# Docker

## Requisitos

- Docker Desktop
- Puerto `8080` libre para Apache
- Puerto `1433` libre si se usara el SQL Server incluido

## Configuracion

1. Copiar `config/.env.example` a `config/.env`.
2. Para usar SQL Server dentro de Docker, dejar:

```ini
DB_SERVER=sqlserver
DB_USER=sa
DB_PASSWORD=CambiaEstaPassword@2024
DB_NAME=Fact_contratista
DB_NAME2=
DB_NAME_RELOJ=
```

3. Si cambias `DB_PASSWORD`, crea tambien un `.env` en la raiz con:

```ini
MSSQL_SA_PASSWORD=TuPasswordSegura
```

El valor de `DB_PASSWORD` en `config/.env` debe coincidir con `MSSQL_SA_PASSWORD`.

Si `config/.env` ya existe, revisalo antes de levantar Docker: la aplicacion siempre usa ese archivo para conectarse a la base de datos.

## Levantar

```powershell
docker compose up --build
```

La aplicacion queda en:

```text
http://localhost:8080/contratista/public/login.php
```

El servicio `sqlserver-init` ejecuta `setup.sql` una vez contra la base `master`; el script crea la base `Fact_contratista` si no existe y luego crea el esquema.

## Servidor SQL externo

Si usaras un SQL Server existente:

1. En `config/.env`, cambia `DB_SERVER` por la IP o nombre del servidor.
2. En `docker-compose.yml`, comenta `sqlserver`, `sqlserver-init` y los `depends_on` asociados.
