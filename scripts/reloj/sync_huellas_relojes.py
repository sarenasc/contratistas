"""
sync_huellas_relojes.py
Lee templates de huella de relojes activos y actualiza dbo.reloj_huella_cache.
"""
import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from _conexion import get_conn
from zk import ZK


def ensure_table(cur):
    cur.execute(
        """
IF OBJECT_ID('dbo.reloj_huella_cache', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.reloj_huella_cache (
        id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        id_numero NVARCHAR(50) NOT NULL,
        reloj_id INT NOT NULL,
        reloj_nombre NVARCHAR(150) NOT NULL,
        ip NVARCHAR(50) NOT NULL,
        uid INT NOT NULL,
        fid INT NOT NULL,
        template_bytes INT NOT NULL,
        fecha_sync DATETIME NOT NULL DEFAULT GETDATE()
    );
    CREATE INDEX IX_reloj_huella_cache_id_numero ON dbo.reloj_huella_cache(id_numero);
    CREATE INDEX IX_reloj_huella_cache_reloj ON dbo.reloj_huella_cache(reloj_id, id_numero);
END
"""
    )


def main():
    conn = get_conn()
    cur = conn.cursor()
    ensure_table(cur)
    conn.commit()

    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo = 1 ORDER BY nombre")
    dispositivos = cur.fetchall()

    total_templates = 0
    errores = 0

    for reloj_id, nombre, ip, puerto in dispositivos:
        print(f"Conectando {nombre} ({ip})...", flush=True)
        zk = ZK(ip, port=int(puerto), timeout=12)
        zk_con = None
        try:
            zk_con = zk.connect()
            users = zk_con.get_users()
            uid_to_user_id = {int(u.uid): str(u.user_id).strip() for u in users}
            templates = zk_con.get_templates()

            rows = []
            for tpl in templates:
                id_numero = uid_to_user_id.get(int(tpl.uid))
                if not id_numero:
                    continue
                rows.append((id_numero, int(reloj_id), str(nombre), str(ip), int(tpl.uid), int(tpl.fid), len(tpl.template)))

            cur.execute("DELETE FROM dbo.reloj_huella_cache WHERE reloj_id = ?", int(reloj_id))
            if rows:
                cur.fast_executemany = True
                cur.executemany(
                    """
                    INSERT INTO dbo.reloj_huella_cache
                        (id_numero, reloj_id, reloj_nombre, ip, uid, fid, template_bytes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    """,
                    rows,
                )
            conn.commit()
            total_templates += len(rows)
            print(f"OK:{nombre}: {len(rows)} huellas indexadas", flush=True)
        except Exception as exc:
            errores += 1
            conn.rollback()
            print(f"ERROR:{nombre}:{exc}", flush=True)
        finally:
            if zk_con:
                try:
                    zk_con.disconnect()
                except Exception:
                    pass

    print(f"Resumen: {total_templates} huellas indexadas; errores={errores}", flush=True)
    conn.close()
    return 1 if errores else 0


if __name__ == "__main__":
    raise SystemExit(main())
