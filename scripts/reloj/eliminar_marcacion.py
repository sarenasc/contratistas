"""
eliminar_marcacion.py <id_numero> <fecha YYYY-MM-DD>
Elimina marcaciones de la BD y del reloj fisico para un trabajador en una fecha.
Salida: JSON {ok, db, relojes:[...]}
"""
import sys, os, json
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK
from datetime import datetime

def main():
    if len(sys.argv) < 3:
        print(json.dumps({'ok': False, 'error': 'Uso: eliminar_marcacion.py <id_numero> <fecha YYYY-MM-DD>'}))
        sys.exit(1)

    try:
        id_numero = int(sys.argv[1])
    except ValueError:
        print(json.dumps({'ok': False, 'error': 'id_numero debe ser entero'}))
        sys.exit(1)

    fecha_str = sys.argv[2]
    try:
        target_date = datetime.strptime(fecha_str, '%Y-%m-%d').date()
    except ValueError:
        print(json.dumps({'ok': False, 'error': f'Fecha invalida: {fecha_str}'}))
        sys.exit(1)

    conn = get_conn()
    cur  = conn.cursor()

    # ── 1. Borrar de la BD ────────────────────────────────────────────────────
    cur.execute(
        "DELETE FROM dbo.reloj_marcacion WHERE id_numero = ? AND CAST(fecha_hora AS DATE) = ?",
        id_numero, fecha_str
    )
    n_db = cur.rowcount
    conn.commit()

    # ── 2. Obtener dispositivos activos ───────────────────────────────────────
    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo = 1")
    dispositivos = cur.fetchall()
    conn.close()

    resultados = []

    for _disp_id, nombre, ip, puerto in dispositivos:
        zk     = ZK(ip, port=puerto, timeout=6)
        zk_conn = None
        try:
            zk_conn = zk.connect()
            all_att = zk_conn.get_attendance()

            # Separar los que se eliminan de los que se conservan
            to_delete = [
                a for a in all_att
                if str(a.user_id) == str(id_numero)
                and hasattr(a.timestamp, 'date')
                and a.timestamp.date() == target_date
            ]
            to_keep = [a for a in all_att if a not in to_delete]

            if not to_delete:
                resultados.append(f'OK:{nombre}:sin marcaciones de ese trabajador en ese dia')
                continue

            # Limpiar el dispositivo completo
            zk_conn.clear_attendance()

            # Intentar restaurar las marcaciones que se deben conservar
            n_back   = 0
            soportado = True
            for a in to_keep:
                try:
                    zk_conn.new_attendance(
                        uid       = int(a.user_id),
                        timestamp = a.timestamp,
                        status    = a.status,
                        punch     = a.punch
                    )
                    n_back += 1
                except AttributeError:
                    soportado = False
                    break
                except Exception:
                    pass

            if not soportado:
                # Firmware no soporta reescritura: las demas marcaciones quedan
                # eliminadas del dispositivo, pero siguen en la BD y se re-sincronizan
                # en el proximo ciclo automatico.
                resultados.append(
                    f'OK:{nombre}:{len(to_delete)} eliminada(s) del reloj. '
                    f'Las restantes ({len(to_keep)}) se resincronizan en el proximo ciclo.'
                )
            else:
                resultados.append(
                    f'OK:{nombre}:{len(to_delete)} eliminada(s), {n_back} de {len(to_keep)} restauradas'
                )

        except Exception as e:
            resultados.append(f'ERROR:{nombre}:{e}')
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    print(json.dumps({
        'ok'     : True,
        'db'     : n_db,
        'relojes': resultados
    }, ensure_ascii=False))


if __name__ == '__main__':
    main()
