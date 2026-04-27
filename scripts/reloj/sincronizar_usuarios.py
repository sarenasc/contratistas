"""
sincronizar_usuarios.py
Lee todos los usuarios de cada reloj activo, construye la union total
y registra en cada dispositivo los que le falten.
El identificador clave es user_id (= RUT numerico).
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _conexion import get_conn
from zk import ZK

def get_conn_zk(ip, puerto):
    zk = ZK(ip, port=puerto, timeout=6)
    return zk.connect()

def main():
    conn = get_conn()
    cur  = conn.cursor()
    cur.execute("SELECT id, nombre, ip, puerto FROM dbo.reloj_dispositivo WHERE activo=1")
    dispositivos = cur.fetchall()
    conn.close()

    if not dispositivos:
        print("Sin dispositivos activos.", flush=True)
        return

    # ── Paso 1: recopilar todos los usuarios de todos los relojes ──
    print("=== Paso 1: leyendo usuarios de cada reloj ===", flush=True)
    usuarios_por_reloj = {}   # disp_id -> {user_id_str: User}
    union_usuarios     = {}   # user_id_str -> User (el primero que lo tenga)

    for disp_id, nombre, ip, puerto in dispositivos:
        print(f"  [{nombre}] conectando...", flush=True)
        zk_conn = None
        try:
            zk_conn = get_conn_zk(ip, puerto)
            users   = zk_conn.get_users()
            mapa    = {}
            for u in users:
                uid_str = str(u.user_id).strip()
                if uid_str == '' or uid_str == '0':
                    continue
                mapa[uid_str] = u
                if uid_str not in union_usuarios:
                    union_usuarios[uid_str] = u
            usuarios_por_reloj[disp_id] = mapa
            print(f"  [{nombre}] {len(mapa)} usuarios leidos.", flush=True)
        except Exception as e:
            print(f"  [{nombre}] ERROR: {e}", flush=True)
            usuarios_por_reloj[disp_id] = None
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    total_union = len(union_usuarios)
    print(f"\nUnion total: {total_union} usuarios unicos.", flush=True)

    # ── Paso 2: sincronizar cada reloj con los que le faltan ───────
    print("\n=== Paso 2: sincronizando usuarios faltantes ===", flush=True)

    for disp_id, nombre, ip, puerto in dispositivos:
        mapa = usuarios_por_reloj.get(disp_id)
        if mapa is None:
            print(f"  [{nombre}] saltado (no se pudo leer).", flush=True)
            continue

        faltantes = {uid: u for uid, u in union_usuarios.items() if uid not in mapa}

        if not faltantes:
            print(f"  [{nombre}] completo, nada que agregar.", flush=True)
            continue

        print(f"  [{nombre}] faltan {len(faltantes)} usuarios, registrando...", flush=True)
        zk_conn = None
        try:
            zk_conn = get_conn_zk(ip, puerto)

            # Calcular el max uid interno actual del dispositivo
            max_uid = max((int(u.uid) for u in mapa.values()), default=0)

            agregados = 0
            for uid_str, u_src in faltantes.items():
                max_uid += 1
                nombre_u = (u_src.name or '').strip() or f'Usuario {uid_str}'
                try:
                    zk_conn.set_user(
                        uid       = max_uid,
                        name      = nombre_u[:24],
                        privilege = int(u_src.privilege),
                        password  = u_src.password or '',
                        group_id  = u_src.group_id  or '',
                        user_id   = uid_str
                    )
                    agregados += 1
                    print(f"    + {uid_str} ({nombre_u})", flush=True)
                except Exception as e:
                    print(f"    ERROR {uid_str}: {e}", flush=True)

            print(f"  [{nombre}] {agregados} usuarios agregados.", flush=True)
        except Exception as e:
            print(f"  [{nombre}] ERROR al conectar: {e}", flush=True)
        finally:
            if zk_conn:
                try: zk_conn.disconnect()
                except: pass

    print("\nSincronizacion completada.", flush=True)

if __name__ == '__main__':
    main()
