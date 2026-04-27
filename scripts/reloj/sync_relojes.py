"""
sync_relojes.py
Descarga marcaciones de todos los relojes activos y las guarda en SQL Server.
Programar en Task Scheduler cada 15 minutos.
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn

from zk import ZK
from datetime import datetime

def sync():
    conn = get_conn()
    cur  = conn.cursor()

    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo = 1")
    dispositivos = cur.fetchall()

    total_nuevas = 0
    for disp_id, nombre, ip, puerto in dispositivos:
        print(f"[{datetime.now():%H:%M:%S}] Conectando a {nombre} ({ip})...", flush=True)
        zk = ZK(ip, port=puerto, timeout=6)
        zk_conn = None
        try:
            zk_conn = zk.connect()
            marcaciones = zk_conn.get_attendance()
            nuevas = 0
            for m in marcaciones:
                try:
                    uid = int(m.user_id) if m.user_id else m.uid
                    if uid == 0:
                        continue
                    ts_str = m.timestamp.strftime('%Y-%m-%d %H:%M:%S')
                    cur.execute("""
                        MERGE dbo.reloj_marcacion AS tgt
                        USING (SELECT ? AS id_dispositivo,
                                      ? AS id_numero,
                                      CONVERT(datetime,?,120) AS fecha_hora,
                                      ? AS tipo, ? AS estado) AS src
                        ON  tgt.id_dispositivo = src.id_dispositivo
                        AND tgt.id_numero      = src.id_numero
                        AND tgt.fecha_hora     = src.fecha_hora
                        WHEN NOT MATCHED THEN
                            INSERT (id_dispositivo, id_numero, fecha_hora, tipo, estado)
                            VALUES (src.id_dispositivo, src.id_numero,
                                    src.fecha_hora, src.tipo, src.estado);
                    """, disp_id, uid, ts_str, m.punch, m.status)
                    nuevas += cur.rowcount
                except Exception:
                    pass
            conn.commit()
            total_nuevas += nuevas
            print(f"  OK {len(marcaciones)} registros leidos, {nuevas} nuevos insertados.", flush=True)
        except Exception as e:
            print(f"  ERROR en {nombre}: {e}", flush=True)
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    conn.close()
    print(f"Sync completado -- {total_nuevas} marcaciones nuevas en total.", flush=True)

if __name__ == '__main__':
    sync()
