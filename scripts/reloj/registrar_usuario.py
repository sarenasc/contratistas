"""
registrar_usuario.py  <id_numero> <nombre>
Registra o actualiza un trabajador en TODOS los relojes activos.
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK

def main():
    if len(sys.argv) < 3:
        print("Uso: registrar_usuario.py <id_numero> <nombre> [id_area]")
        sys.exit(1)

    id_numero = int(sys.argv[1])
    nombre    = sys.argv[2][:24]
    group_id  = sys.argv[3] if len(sys.argv) > 3 else ''

    conn = get_conn()
    cur  = conn.cursor()
    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo = 1")
    dispositivos = cur.fetchall()
    conn.close()

    errores = []
    for _, disp_nombre, ip, puerto in dispositivos:
        zk = ZK(ip, port=puerto, timeout=6)
        zk_conn = None
        try:
            zk_conn = zk.connect()
            usuarios  = zk_conn.get_users()
            ya_existe = None
            max_uid   = 0
            for u in usuarios:
                try:
                    max_uid = max(max_uid, int(u.uid))
                except Exception:
                    pass
                if str(u.user_id).strip() == str(id_numero):
                    ya_existe = u
                    break

            if ya_existe:
                zk_conn.set_user(
                    uid=int(ya_existe.uid), name=nombre,
                    privilege=int(ya_existe.privilege),
                    password=ya_existe.password or '',
                    group_id=group_id or ya_existe.group_id or '',
                    user_id=str(id_numero))
                print(f"OK:{disp_nombre}:actualizado")
            else:
                zk_conn.set_user(
                    uid=max_uid + 1, name=nombre, privilege=0,
                    password='', group_id=group_id, user_id=str(id_numero))
                print(f"OK:{disp_nombre}:registrado")
        except Exception as e:
            errores.append(str(e))
            print(f"ERROR:{disp_nombre}:{e}")
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    sys.exit(1 if errores else 0)

if __name__ == '__main__':
    main()
