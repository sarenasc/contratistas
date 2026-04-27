"""
info_reloj.py <ip> <puerto>
Devuelve info basica del dispositivo ZKTeco.
"""
import sys
from zk import ZK

def main():
    if len(sys.argv) < 2:
        print("error:sin parametros")
        sys.exit(1)

    ip     = sys.argv[1]
    puerto = int(sys.argv[2]) if len(sys.argv) > 2 else 4370

    zk = ZK(ip, port=puerto, timeout=5)
    zk_conn = None
    try:
        zk_conn = zk.connect()
        print(f"firmware:{zk_conn.get_firmware_version()}", flush=True)
        print(f"serial:{zk_conn.get_serialnumber()}", flush=True)
        print(f"usuarios:{len(zk_conn.get_users())}", flush=True)
        sys.exit(0)
    except Exception as e:
        print(f"error:{e}", flush=True)
        sys.exit(1)
    finally:
        if zk_conn:
            try: zk_conn.disconnect()
            except: pass

if __name__ == '__main__':
    main()
