"""
importar_usuarios.py
Importa usuarios de los relojes activos a dbo.reloj_trabajador.
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK

def main():
    conn = get_conn()
    cur  = conn.cursor()
    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo=1")
    dispositivos = cur.fetchall()

    importados = 0
    vistos = set()

    for _, disp_nombre, ip, puerto in dispositivos:
        zk = ZK(ip, port=puerto, timeout=6)
        zk_conn = None
        try:
            zk_conn = zk.connect()
            users = zk_conn.get_users()
            print(f"[{disp_nombre}] {len(users)} usuarios en reloj", flush=True)
            for u in users:
                try:
                    uid = int(u.user_id) if u.user_id else u.uid
                except Exception:
                    uid = u.uid
                if uid == 0 or uid in vistos:
                    continue
                vistos.add(uid)
                nombre_u = (u.name or '').strip() or f'Usuario {uid}'
                cur.execute("""
                    IF NOT EXISTS (SELECT 1 FROM dbo.reloj_trabajador WHERE id_numero=?)
                    INSERT INTO dbo.reloj_trabajador (id_numero, rut, nombre)
                    VALUES (?, ?, ?)
                """, uid, uid, str(uid), nombre_u)
                if cur.rowcount > 0:
                    importados += 1
                    print(f"  Importado: {uid} -- {nombre_u}", flush=True)
        except Exception as e:
            print(f"ERROR {disp_nombre}: {e}", flush=True)
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    conn.commit()
    conn.close()
    print(f"Total importados: {importados} nuevos trabajadores.", flush=True)

if __name__ == '__main__':
    main()
