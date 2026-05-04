# Docker

Este Docker levanta solo la aplicacion PHP/Apache. No incluye SQL Server.

## Configuracion

Edita `config/.env` para apuntar a tu SQL Server local o externo:

```ini
DB_SERVER=host.docker.internal
DB_USER=sa
DB_PASSWORD=TuPassword
DB_NAME=Fact_contratista
DB_NAME2=
DB_NAME_RELOJ=
```

Usa `host.docker.internal` cuando SQL Server esta instalado en el mismo Windows donde corre Docker Desktop. Si la base esta en otro servidor, usa su IP o nombre DNS.

## Levantar

```powershell
docker compose up --build
```

La aplicacion queda en:

```text
http://localhost:8080/contratista/public/login.php
```

Si necesitas crear el esquema, ejecuta `setup.sql` directamente en tu SQL Server antes de usar el sistema.
