"""
limpiar_reloj.py [id_dispositivo]
Borra el historial de asistencia del reloj fisico (SQL Server NO se toca).
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK

def main():
    id_disp = int(sys.argv[1]) if len(sys.argv) > 1 else None
    conn = get_conn()
    cur  = conn.cursor()

    if id_disp:
        cur.execute(
            "SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo=1 AND id=?",
            id_disp)
    else:
        cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo=1")

    dispositivos = cur.fetchall()
    conn.close()

    for _, nombre, ip, puerto in dispositivos:
        zk = ZK(ip, port=puerto, timeout=6)
        zk_conn = None
        try:
            zk_conn = zk.connect()
            zk_conn.clear_attendance()
            print(f"OK:{nombre} -- historial borrado del reloj fisico", flush=True)
        except Exception as e:
            print(f"ERROR:{nombre}:{e}", flush=True)
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

if __name__ == '__main__':
    main()
