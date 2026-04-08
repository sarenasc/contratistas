# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Environment

- **Stack**: PHP + SQL Server (via `sqlsrv` extension), Bootstrap 5, PhpOffice/PhpSpreadsheet, Composer
- **Server**: XAMPP on Windows — served at `http://localhost/contratista/public/`
- **BASE_URL**: `/contratista/public` (defined in `config/app.php`)
- **Dependencies**: `composer install` in project root (uses `vendor/autoload.php`)

## First-run setup

On a fresh installation, visit `/contratista/public/setup.php`. This wizard:
1. Tests the SQL Server connection
2. Creates the first admin user (hashed with `password_hash(PASSWORD_BCRYPT)`)
3. Writes credentials to `config/.env`
4. Creates `config/setup.lock` — once this file exists, `setup.php` is blocked

The `.env` file (never committed) holds: `DB_SERVER`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `DB_NAME2`, `DB_NAME_RELOJ`.

## Architecture

### Bootstrap chain (every protected page)
```
public/some_page.php
  → require_once '../_bootstrap.php'
      → session_start(), config/app.php (BASE_URL), auth_guard, config/conexion.php
  → require_once '../../app/lib/db.php'
  → include partials/header.php + partials/navbar_wrapper.php
  → ... page logic ...
  → include partials/footer.php
```

`public/_bootstrap.php` is the single entry point that wires together session, auth, and DB for all protected pages. Pages outside `public/` (root-level `.php` files) are legacy and do NOT use this pattern.

### Database connections
`config/conexion.php` opens two connections available globally:
- `$conn` → `DB_NAME` (SistGestion) — main application database
- `$conn2` → `DB_NAME2` (Facturador externo) — used only to import billing marks

### DB helper (`app/lib/db.php`)
Always use `db_query($conn, $sql, $params)` instead of `sqlsrv_query()` directly — it throws a `RuntimeException` on failure with a formatted error message. Never use `die(print_r(sqlsrv_errors(), true))`.

### Auth
`app/Middlewares/auth_guard.php` — checks `$_SESSION['id']`; redirects to `BASE_URL/Index.php` if not set. It requires `BASE_URL` to already be defined, so `config/app.php` must be loaded first.

### Key DB tables (SistGestion)
- `Area` — cost centers / areas
- `TRA_usuario` — system users (passwords bcrypt-hashed)
- `Dota_Cargo` — job roles/labores
- `dota_tipo_mo` — type of labor (MO: Mano de Obra)
- `dota_contratista` — contractor companies (employers)
- `dota_turno` — work shifts
- `dota_jefe_area` — area supervisors with default shift
- `Dota_tipo_tarifa` — tariff types (with active/date range)
- `Dota_Valor_Dotacion` — cargo ↔ tarifa assignments
- `dota_Registro_Marcacion` — attendance records

### Carga de Asistencia (multi-step AJAX flow)
`public/procesos/carga_asistencia.php` orchestrates a 3-phase upload:
1. **`carga_asistencia_ajax_start.php`** — receives the file upload (XHR with progress), saves to `storage/asistencia/`, reads headers and detects unique values (Area, Empleador, Cargo, Turno) using `ChunkReadFilter` for memory efficiency
2. **`carga_asistencia_ajax_chunk.php`** — processes the Excel in 2000-row chunks, updates `$_SESSION['asistencia_upload']`
3. **`carga_asistencia_paso2.php`** — mapping form POST; receives user-mapped IDs and inserts into the DB

The Excel must have a sheet named **"Matriz"** with headers: `FECHA, SEMANA, RESPONSABLE, AREA, EMPLEADOR, CARGO, RUT, NOMBRE, SEXO, TURNO, %JORNADA, HE`.

## Page structure pattern

New pages follow this template:
```php
<?php
require_once __DIR__ . '/../_bootstrap.php';   // or '../../' from subdirs
require_once __DIR__ . '/../../app/lib/db.php';

$title = "Page Title";
$flash_error = null;
$flash_ok    = null;

// POST handling with db_query()...

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container py-4">
  <!-- content -->
</main>
<?php include __DIR__ . '/../partials/footer.php'; ?>
```

`BASE_URL` is available in all partials via `base_url.php` (included by `navbar.php`).

## Known pending issues (`ruta de mejoras.txt`)

Security items not yet implemented — be aware when working in these areas:
- **CSRF tokens** [ALTA] — no cross-site request forgery protection on any form
- **Session timeout** [ALTA] — sessions never expire
- **SQL errors exposed to screen** [ALTA] — some legacy files still use `die(print_r(...))`
- **Tarifa overlap validation** [ALTA] — `tarifas/tipo_tarifa.php` validation is incomplete
- **Bootstrap version mix** [MEDIA] — some files still use Bootstrap 4.5.2; target is 5.3.3
- **Temp file cleanup** [MEDIA] — `storage/asistencia/` files are never deleted after upload

## Navigation sections (navbar)
- **Procesos**: Carga Asistencia, Descuento, Pre Factura
- **Contratistas**: Contratista, Solicitud Contratista
- **Tarifas**: Cargos y Tarifas, Tipo Tarifas, Tarifas Especiales
- **Configuraciones**: Tipo MO, Labores (Cargos), Area, Turnos, Jefes de Área, Registro Usuario
