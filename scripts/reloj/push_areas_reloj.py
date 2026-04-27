"""
push_areas_reloj.py
Lee los trabajadores con area asignada en SQL Server y actualiza
el campo group_id de cada usuario en todos los relojes activos.
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK

def main():
    conn = get_conn()
    cur  = conn.cursor()

    # Trabajadores con area asignada
    cur.execute("""
        SELECT t.id_numero, t.nombre, ISNULL(CAST(t.id_area AS NVARCHAR), '') AS group_id
        FROM dbo.reloj_trabajador t
        WHERE t.activo = 1
    """)
    trabajadores = {str(r[0]): {'nombre': r[1], 'group_id': r[2]}
                    for r in cur.fetchall()}

    # Relojes activos
    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo=1")
    dispositivos = cur.fetchall()
    conn.close()

    total_act = sum(1 for t in trabajadores.values() if t['group_id'])
    print(f"Trabajadores con area asignada: {total_act} de {len(trabajadores)}", flush=True)

    for _, disp_nombre, ip, puerto in dispositivos:
        print(f"\n[{disp_nombre}] conectando...", flush=True)
        zk = ZK(ip, port=puerto, timeout=6)
        zk_conn = None
        try:
            zk_conn = zk.connect()
            users   = zk_conn.get_users()
            actualizados = 0

            for u in users:
                uid_str = str(u.user_id).strip()
                if uid_str not in trabajadores:
                    continue
                nuevo_group = trabajadores[uid_str]['group_id']
                if str(u.group_id).strip() == nuevo_group:
                    continue   # ya correcto
                try:
                    zk_conn.set_user(
                        uid       = int(u.uid),
                        name      = u.name or '',
                        privilege = int(u.privilege),
                        password  = u.password or '',
                        group_id  = nuevo_group,
                        user_id   = uid_str
                    )
                    actualizados += 1
                except Exception as e:
                    print(f"  ERROR {uid_str}: {e}", flush=True)

            print(f"  {actualizados} usuarios actualizados con su area.", flush=True)
        except Exception as e:
            print(f"  ERROR: {e}", flush=True)
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    print("\nPush de areas completado.", flush=True)

if __name__ == '__main__':
    main()
