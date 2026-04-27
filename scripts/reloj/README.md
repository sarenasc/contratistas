# Módulo Reloj Biométrico — Scripts Python

## Dependencias Python

Instalar con pip en el servidor destino:

```
pip install pyzk pyodbc
```

---

## Configuración al desplegar en un nuevo servidor

### 1. `PYTHON_BIN` en `config/.env`

Actualiza la ruta al ejecutable de Python según el sistema operativo:

| Sistema | Ejemplo |
|---|---|
| Windows (instalación usuario) | `C:\Users\<usuario>\AppData\Local\Programs\Python\Python313\python.exe` |
| Windows (instalación global) | `C:\Python311\python.exe` |
| Linux / Mac | `/usr/bin/python3` |

Para encontrar la ruta correcta ejecuta en terminal:
- **Windows**: `where python` o `where python3`
- **Linux/Mac**: `which python3`

### 2. ODBC Driver

Los scripts Python requieren **ODBC Driver 17 for SQL Server**.

- **Windows**: Descargar desde Microsoft e instalar.
- **Linux (Debian/Ubuntu)**:
  ```
  curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add -
  # (seguir instrucciones oficiales de Microsoft para la distro)
  apt-get install msodbcsql17 unixodbc-dev
  ```

### 3. Task Scheduler (sync automático)

Para que `sync_relojes.py` corra cada 15 minutos en Windows:

1. Abrir **Programador de tareas**
2. Nueva tarea → Desencadenador: cada 15 minutos
3. Acción: `C:\ruta\a\python.exe  C:\xampp\htdocs\contratista\scripts\reloj\sync_relojes.py`

En Linux usar `cron`:
```
*/15 * * * * /usr/bin/python3 /var/www/contratista/scripts/reloj/sync_relojes.py
```

---

## Archivos del módulo

| Archivo | Descripción |
|---|---|
| `_conexion.py` | Módulo compartido: lee `config/.env` y abre conexión SQL Server |
| `_rutas.php` | Constantes PHP con rutas a los scripts (usado por las páginas web) |
| `sync_relojes.py` | Descarga marcaciones de todos los relojes activos |
| `registrar_usuario.py` | Registra/actualiza un trabajador en los relojes físicos |
| `limpiar_reloj.py` | Borra historial del reloj físico (SQL Server no se toca) |
| `importar_usuarios.py` | Importa usuarios del reloj a la tabla `reloj_trabajador` |
| `info_reloj.py` | Verifica conectividad y obtiene info del dispositivo |
