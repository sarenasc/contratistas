"""
eliminar_usuario.py <id_numero>
Elimina un trabajador de TODOS los relojes activos por user_id.
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK

def main():
    if len(sys.argv) < 2:
        print("Uso: eliminar_usuario.py <id_numero>")
        sys.exit(1)

    id_numero = int(sys.argv[1])

    conn = get_conn()
    cur  = conn.cursor()
    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo = 1")
    dispositivos = cur.fetchall()
    conn.close()

    errores = []
    for _, disp_nombre, ip, puerto in dispositivos:
        zk     = ZK(ip, port=int(puerto), timeout=6)
        zk_con = None
        try:
            zk_con   = zk.connect()
            usuarios = zk_con.get_users()
            uid = None
            for u in usuarios:
                if str(u.user_id).strip() == str(id_numero):
                    uid = int(u.uid)
                    break
            if uid is not None:
                zk_con.delete_user(uid=uid)
                print(f"OK:{disp_nombre}:eliminado")
            else:
                print(f"OK:{disp_nombre}:no_encontrado")
        except Exception as e:
            errores.append(str(e))
            print(f"ERROR:{disp_nombre}:{e}")
        finally:
            if zk_con:
                try: zk_con.disconnect()
                except: pass

    sys.exit(1 if errores else 0)

if __name__ == '__main__':
    main()
