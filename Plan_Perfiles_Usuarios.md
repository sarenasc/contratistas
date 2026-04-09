# Plan de Implementación: Perfiles, Usuarios y Aprobación de Asistencia

Fecha: 2026-04-09  
Rama de desarrollo: `desarrollo`  
Rama estable: `master`

**Base de datos nueva:** `Fact_contratista`  
**Base de datos anterior (no tocar):** `proforma_contratista` → respaldo en `C:\xampp\htdocs\contratista2`

---

## FASE 1 — Base de Datos ✅ COMPLETADA EN setup.sql

### Cambios respecto al sistema anterior

- `TRA_usuario` → renombrada y actualizada a `dota_usuarios`
  - Campos nuevos: `id_perfil`, `activo`, `email`
  - `nom_usu` → `usuario`, `pass_usu` → `password_hash`
  - Eliminado: `sistema1` (campo legacy sin uso)

- `dota_jefe_area` → agregado `id_usuario` (FK a `dota_usuarios`)
  - Un área puede tener más de un jefe (uno por turno)
  - Si un jefe cubre ambos turnos → `id_turno NULL` o dos registros

- `dota_solicitud_contratista` → incluye `id_turno` desde el inicio

- `vw_proformas_detalle` → ahora hace JOIN con `dota_asistencia_lote`
  y solo incluye registros con `estado = 'listo_factura'`

### Tablas nuevas en setup.sql

| Tabla | Propósito |
|---|---|
| `dota_perfiles` | 4 perfiles: Administrador, Edicion, Aprobador-Edicion, Visualizacion |
| `dota_usuarios` | Usuarios del sistema (reemplaza TRA_usuario) |
| `dota_usuario_modulos` | Qué módulos puede acceder cada usuario |
| `dota_usuario_areas` | Qué áreas puede aprobar un usuario aprobador |
| `dota_usuario_cargos` | Cargos específicos fuera del área propia (caso Orlando) |
| `dota_asistencia_lote` | Estado del lote cargado: pendiente → listo_factura |
| `dota_asistencia_aprobacion` | Log de aprobaciones/rechazos/ediciones por usuario |

---

## FASE 2 — Autenticación

### Paso 2.1 — `public/auth/login.php`
- Formulario usuario + contraseña
- Valida contra `dota_usuarios` con `password_verify()`
- Guarda en sesión: `id_usuario`, `nombre`, `id_perfil`, `modulos[]`, `areas[]`, `cargos[]`
- Redirige a `index.php`

### Paso 2.2 — `public/auth/logout.php`
- `session_destroy()` y redirige a login

### Paso 2.3 — Modificar `public/_bootstrap.php`
- Si no hay sesión activa → redirigir a `auth/login.php`
- Cargar módulos, áreas y cargos permitidos del usuario en sesión
- Exponer funciones helper:
  - `puede_modulo($modulo)` → bool
  - `puede_aprobar_area($id_area)` → bool
  - `puede_aprobar_cargo($id_cargo)` → bool
  - `es_admin()` → bool
  - `es_jefe_operaciones()` → bool (nivel_aprobacion = 2)

### Paso 2.4 — Modificar `app/Middlewares/auth_guard.php`
- Actualizar para verificar `$_SESSION['id_usuario']` (antes era `$_SESSION['id']`)

---

## FASE 3 — Gestión de Usuarios

### Paso 3.1 — `public/configuraciones/usuarios.php`
- Lista de usuarios con perfil y estado activo/inactivo
- Formulario agregar/editar:
  - nombre, apellido, usuario, email, contraseña, perfil, área propia
  - Checkboxes de módulos accesibles
  - Multiselect de áreas que puede aprobar (solo para perfil Aprobador-Edicion)
  - Multiselect de cargos específicos adicionales (caso Orlando)
- Toggle activo/inactivo
- Contraseña se guarda con `password_hash(..., PASSWORD_DEFAULT)`
- Solo accesible por Administrador

### Paso 3.2 — Agregar restricciones de acceso en páginas existentes
Agregar al inicio de cada página (después de `_bootstrap.php`):
```php
if (!puede_modulo('configuraciones')) {
    header('Location: /index.php'); exit;
}
```
Páginas afectadas: Cargos.php, tipo_tarifa.php, tarifasEspecial.php, Tarifas_Cargo.php,
carga_asistencia.php, Solicitud_Contra.php, Pre-Factura.php, proformas.php, etc.

---

## FASE 4 — Flujo de Aprobación de Asistencia

### Decisiones de diseño confirmadas

- **Un jefe puede aprobar por área + turno:** si hay 2 jefes en Packing (uno por turno), cada uno aprueba su turno. Si uno falta, el otro puede cubrir ambos (asignarle los 2 turnos en `dota_usuario_areas` o dejar `id_turno NULL`).
- **Jefe de Operaciones:** ve y puede aprobar/rechazar TODO sin restricciones de área/cargo.
- **RRHH siempre puede editar:** no solo cuando fue rechazada la asistencia.
- **Notificaciones:**
  - Al entrar al sistema: alerta en pantalla principal si hay asistencias pendientes o rechazadas.
  - Al subir y guardar una asistencia: envío de email a los aprobadores correspondientes.

### Paso 4.1 — Modificar carga de asistencia (paso 2)
En `carga_asistencia_paso2_chunk.php`, al finalizar el lote:
- Insertar registro en `dota_asistencia_lote` con `estado = 'pendiente'`
- Guardar `id_usuario_carga` desde sesión
- Enviar email a los jefes de área correspondientes

### Paso 4.2 — Bandeja del Jefe de Área (`public/aprobacion/bandeja_jefe.php`)
- Lista lotes con `estado = 'pendiente'` o `'rechazado_ops'`
  filtrando por áreas + cargos que puede aprobar el usuario logueado
- Por lote: botón Ver Detalle, Aprobar (su parte), Rechazar (con observación obligatoria)
- Al aprobar:
  - Insertar en `dota_asistencia_aprobacion` (accion='aprobado', id_area, id_turno)
  - Verificar si TODOS los jefes relevantes ya aprobaron el lote
  - Si todos aprobaron → actualizar `dota_asistencia_lote.estado = 'aprobado_area'`
- Al rechazar:
  - Insertar en `dota_asistencia_aprobacion` (accion='rechazado', observacion obligatoria)
  - Actualizar `dota_asistencia_lote.estado = 'rechazado_area'`

### Paso 4.3 — Bandeja del Jefe de Operaciones (`public/aprobacion/bandeja_operaciones.php`)
- Lista lotes con `estado = 'aprobado_area'`
- Ve el historial de aprobaciones por área del lote
- Al aprobar → `estado = 'listo_factura'`
- Al rechazar → `estado = 'rechazado_ops'` + indicar área/cargo con problema
  → el lote vuelve a aparecer en bandeja del jefe de área correspondiente

### Paso 4.4 — Detalle de asistencia (`public/aprobacion/detalle_asistencia.php`)
- Vista de registros del lote: trabajador, cargo, área, turno, horas
- Solo lectura para aprobadores
- Muestra historial del lote (quién aprobó/rechazó, cuándo, observación)

### Paso 4.5 — Edición de asistencia (`public/procesos/editar_asistencia.php`)
- Accesible para perfil RRHH (módulo 'procesos') en cualquier momento
- Filtra por `registro` (lote) y muestra registros editables: horas, cargo, área
- Guarda cambios con UPDATE en `dota_asistencia_carga`
- Registra acción `'editado'` en `dota_asistencia_aprobacion`
- Si el lote estaba rechazado → vuelve a `estado = 'pendiente'` para que el jefe que rechazó apruebe de nuevo

### Paso 4.6 — Notificación en pantalla principal (`public/index.php`)
- Consulta al cargar si hay lotes pendientes o rechazados asignados al usuario
- Muestra alerta visible: "Tienes X asistencias pendientes de aprobación" / "X rechazadas"
- Solo se muestra a usuarios con perfil Aprobador-Edicion o Administrador

---

## FASE 5 — Ajuste a Pre-Factura

### Paso 5.1 — Bloquear Pre-Factura si asistencia no está aprobada
En `public/procesos/Pre-Factura.php`:
- Al seleccionar semana/año, verificar que todos los lotes de ese período tengan `estado = 'listo_factura'`
- Si hay lotes sin aprobar → mostrar tabla con qué lotes faltan y en qué estado están
- La vista `vw_proformas_detalle` ya aplica el filtro automáticamente (JOIN con dota_asistencia_lote)

---

## Orden de implementación

```
Fase 1 (BD setup.sql) ✅
     ↓
Fase 2 (Auth + _bootstrap)
     ↓
Fase 3 (Usuarios)  ←→  Fase 4 (Aprobación)   ← en paralelo
     ↓
Fase 5 (Pre-Factura)
```

---

## Estado de ramas Git

| Rama | Propósito |
|---|---|
| `master` | Código estable — no tocar hasta que algo esté probado |
| `desarrollo` | Rama activa de desarrollo |
| `main` | Rama original (respaldo) |
