"""
Modulo compartido: conexion a SQL Server leyendo config/.env
relativo a la ubicacion de este archivo (scripts/reloj/).
"""
import os
import pyodbc

def _env_path():
    base = os.path.dirname(os.path.abspath(__file__))          # scripts/reloj
    return os.path.join(base, '..', '..', 'config', '.env')   # config/.env

def load_env():
    env = {}
    with open(_env_path(), encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if '=' in line and not line.startswith('#'):
                k, v = line.split('=', 1)
                env[k.strip()] = v.strip()
    return env

def get_conn():
    env = load_env()
    return pyodbc.connect(
        f"DRIVER={{ODBC Driver 17 for SQL Server}};"
        f"SERVER={env['DB_SERVER']};DATABASE={env['DB_NAME']};"
        f"UID={env['DB_USER']};PWD={env['DB_PASSWORD']}"
    )
