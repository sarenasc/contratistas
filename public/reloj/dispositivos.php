<?php
require_once __DIR__ . '/../_bootstrap.php';
if (!es_admin()) { header('Location: ' . BASE_URL . '/Inicio.php'); exit; }

$flash_ok = $flash_error = null;

// ── Guardar nuevo ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $accion    = $_POST['accion'];
    if ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        $r  = sqlsrv_query($conn,
            "DELETE FROM dbo.reloj_dispositivo WHERE id=?", [$id]);
        if ($r) $flash_ok = "Reloj eliminado.";
        else    $flash_error = "Error al eliminar.";
    } else {
    $nombre    = trim($_POST['nombre']    ?? '');
    $ip        = trim($_POST['ip']        ?? '');
    $puerto    = (int)($_POST['puerto']   ?? 4370);
    $ubicacion = trim($_POST['ubicacion'] ?? '');
    $modelo    = trim($_POST['modelo']    ?? 'ZKTeco');
    $activo    = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '' || $ip === '') {
        $flash_error = "Nombre e IP son obligatorios.";
    } elseif ($accion === 'nuevo') {
        // Validar que el dispositivo responde antes de guardar
        $socket = @fsockopen($ip, $puerto, $errno, $errstr, 3);
        if (!$socket) {
            $flash_error = "No se pudo conectar a $ip:$puerto — $errstr. Verifica la IP y el puerto antes de guardar.";
        } else {
            fclose($socket);
            $r = sqlsrv_query($conn,
                "INSERT INTO dbo.reloj_dispositivo (nombre,ip,puerto,ubicacion,modelo,activo)
                 VALUES (?,?,?,?,?,?)",
                [$nombre,$ip,$puerto,$ubicacion,$modelo,$activo]);
            if ($r) $flash_ok = "Reloj registrado correctamente.";
            else    $flash_error = "Error al guardar.";
        }
    } elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $r  = sqlsrv_query($conn,
            "UPDATE dbo.reloj_dispositivo
             SET nombre=?,ip=?,puerto=?,ubicacion=?,modelo=?,activo=?
             WHERE id=?",
            [$nombre,$ip,$puerto,$ubicacion,$modelo,$activo,$id]);
        if ($r) $flash_ok = "Reloj actualizado.";
        else    $flash_error = "Error al actualizar.";
    }
    } // cierre else (no-eliminar)
}

$rows = sqlsrv_query($conn,
    "SELECT id,nombre,ip,puerto,ubicacion,modelo,activo
     FROM dbo.reloj_dispositivo ORDER BY nombre");

$title = "Relojes — Dispositivos";
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/navbar_wrapper.php';
?>
<main class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Relojes Biométricos</h4>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-warning btn-sm" onclick="sincronizarUsuarios()">
        &#8644; Igualar usuarios entre relojes
      </button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevo">
        + Agregar reloj
      </button>
    </div>
  </div>

  <?php if ($flash_ok):    ?><div class="alert alert-success"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_error): ?><div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

  <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle">
      <thead class="table-dark">
        <tr>
          <th>Nombre</th><th>IP</th><th>Puerto</th>
          <th>Ubicación</th><th>Modelo</th><th>Estado BD</th>
          <th>Conectividad</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php while ($d = sqlsrv_fetch_array($rows, SQLSRV_FETCH_ASSOC)): ?>
        <tr>
          <td><?= htmlspecialchars($d['nombre']) ?></td>
          <td><code><?= htmlspecialchars($d['ip']) ?></code></td>
          <td><?= $d['puerto'] ?></td>
          <td><?= htmlspecialchars($d['ubicacion'] ?? '') ?></td>
          <td><?= htmlspecialchars($d['modelo']) ?></td>
          <td><?= $d['activo']
                ? '<span class="badge bg-success">Activo</span>'
                : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
          <td>
            <span class="badge bg-secondary status-badge"
                  data-ip="<?= htmlspecialchars($d['ip']) ?>"
                  data-puerto="<?= $d['puerto'] ?>">
              Verificando...
            </span>
          </td>
          <td class="text-center">
            <button class="btn btn-sm btn-outline-secondary btn-editar"
              data-id="<?= $d['id'] ?>"
              data-nombre="<?= htmlspecialchars($d['nombre'],ENT_QUOTES) ?>"
              data-ip="<?= htmlspecialchars($d['ip'],ENT_QUOTES) ?>"
              data-puerto="<?= $d['puerto'] ?>"
              data-ubicacion="<?= htmlspecialchars($d['ubicacion']??'',ENT_QUOTES) ?>"
              data-modelo="<?= htmlspecialchars($d['modelo'],ENT_QUOTES) ?>"
              data-activo="<?= $d['activo'] ?>"
              data-bs-toggle="modal" data-bs-target="#modalEditar">&#9998;</button>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('¿Eliminar este reloj?')">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= $d['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">&#128465;</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- Modal Nuevo -->
<div class="modal fade" id="modalNuevo" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" id="formNuevo">
      <input type="hidden" name="accion" value="nuevo">
      <div class="modal-header">
        <h5 class="modal-title">Nuevo Reloj</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php include __DIR__ . '/partials/form_dispositivo.php'; ?>
        <div id="testResultNuevo" class="mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-info btn-sm"
                onclick="probarConexion('formNuevo','testResultNuevo')">
          &#128246; Probar conexión
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="btnGuardarNuevo">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" id="formEditar">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header">
        <h5 class="modal-title">Editar Reloj</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php include __DIR__ . '/partials/form_dispositivo.php'; ?>
        <div id="testResultEditar" class="mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-info btn-sm"
                onclick="probarConexion('formEditar','testResultEditar')">
          &#128246; Probar conexión
        </button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Actualizar</button>
      </div>
    </form>
  </div></div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<!-- Modal sincronizar usuarios -->
<div class="modal fade" id="modalSincUsers" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Igualando usuarios entre relojes...</h5>
    </div>
    <div class="modal-body">
      <p class="text-muted small mb-2">
        Lee todos los usuarios de cada reloj, determina cuáles faltan en cada uno y los registra.
      </p>
      <div class="progress mb-3" style="height:22px;">
        <div id="sincBar" class="progress-bar progress-bar-striped progress-bar-animated
             bg-warning" style="width:5%">5%</div>
      </div>
      <pre id="sincLog" class="bg-dark text-light p-3 rounded"
           style="max-height:350px;overflow-y:auto;font-size:.8rem;"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary d-none" id="btnCerrarSinc"
              data-bs-dismiss="modal">Cerrar</button>
    </div>
  </div></div>
</div>

<script>
// ── Igualar usuarios entre relojes ───────────────────────────────
function sincronizarUsuarios() {
    if (!confirm(
        'Esta acción leerá los usuarios de todos los relojes y registrará\n' +
        'los faltantes en cada dispositivo para dejarlos iguales.\n\n¿Continuar?'
    )) return;

    document.getElementById('sincLog').textContent = '';
    const bar = document.getElementById('sincBar');
    bar.style.width = '5%'; bar.textContent = '5%';
    bar.classList.add('progress-bar-animated');
    document.getElementById('btnCerrarSinc').classList.add('d-none');

    new bootstrap.Modal(document.getElementById('modalSincUsers')).show();

    let pct = 5;
    const source = new EventSource('sync_stream.php?accion=sinc_users');

    source.onmessage = function(e) {
        const msg = JSON.parse(e.data);
        if (msg === '__DONE__') {
            source.close();
            bar.style.width = '100%'; bar.textContent = '100%';
            bar.classList.remove('progress-bar-animated');
            document.getElementById('btnCerrarSinc').classList.remove('d-none');
        } else {
            document.getElementById('sincLog').textContent += msg + '\n';
            document.getElementById('sincLog').scrollTop = 9999;
            pct = Math.min(pct + 8, 90);
            bar.style.width = pct + '%'; bar.textContent = pct + '%';
        }
    };

    source.onerror = function() {
        source.close();
        document.getElementById('sincLog').textContent += '\nError de conexion.';
        document.getElementById('btnCerrarSinc').classList.remove('d-none');
    };
}

// ── Cargar estado online/offline de cada reloj ────────────────────
document.querySelectorAll('.status-badge').forEach(badge => {
    const ip     = badge.dataset.ip;
    const puerto = badge.dataset.puerto;
    fetch(`check_dispositivo.php?ip=${encodeURIComponent(ip)}&puerto=${puerto}`)
        .then(r => r.json())
        .then(data => {
            if (data.online) {
                badge.className = 'badge bg-success status-badge';
                let txt = 'Online';
                if (data.firmware) txt += ' · ' + data.firmware;
                if (data.usuarios) txt += ' · ' + data.usuarios + ' usuarios';
                badge.textContent = txt;
            } else {
                badge.className = 'badge bg-danger status-badge';
                badge.textContent = 'Offline' + (data.error ? ' — ' + data.error : '');
            }
        })
        .catch(() => {
            badge.className = 'badge bg-warning text-dark status-badge';
            badge.textContent = 'Sin respuesta';
        });
});

// ── Poblar modal editar ───────────────────────────────────────────
document.querySelectorAll('.btn-editar').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('edit_id').value = btn.dataset.id;
        document.querySelector('#modalEditar [name=nombre]').value    = btn.dataset.nombre;
        document.querySelector('#modalEditar [name=ip]').value        = btn.dataset.ip;
        document.querySelector('#modalEditar [name=puerto]').value    = btn.dataset.puerto;
        document.querySelector('#modalEditar [name=ubicacion]').value = btn.dataset.ubicacion;
        document.querySelector('#modalEditar [name=modelo]').value    = btn.dataset.modelo;
        document.querySelector('#modalEditar [name=activo]').checked  = btn.dataset.activo === '1';
        document.getElementById('testResultEditar').innerHTML = '';
    });
});

// ── Probar conexión desde modal ───────────────────────────────────
function probarConexion(formId, resultId) {
    const form   = document.getElementById(formId);
    const ip     = form.querySelector('[name=ip]').value.trim();
    const puerto = form.querySelector('[name=puerto]').value || 4370;
    const result = document.getElementById(resultId);

    if (!ip) { result.innerHTML = '<div class="alert alert-warning py-1 mb-0">Ingresa una IP primero.</div>'; return; }

    result.innerHTML = '<div class="alert alert-secondary py-1 mb-0">Probando conexión a ' + ip + ':' + puerto + '...</div>';

    fetch(`check_dispositivo.php?ip=${encodeURIComponent(ip)}&puerto=${puerto}`)
        .then(r => r.json())
        .then(data => {
            if (data.online) {
                let info = 'Firmware: ' + (data.firmware || '?');
                if (data.serial)   info += ' · Serial: '   + data.serial;
                if (data.usuarios) info += ' · Usuarios: ' + data.usuarios;
                result.innerHTML = `<div class="alert alert-success py-1 mb-0">
                    &#10003; Dispositivo online — ${info}</div>`;
            } else {
                result.innerHTML = `<div class="alert alert-danger py-1 mb-0">
                    &#10007; Sin respuesta — ${data.error || 'Host inalcanzable'}</div>`;
            }
        })
        .catch(() => {
            result.innerHTML = '<div class="alert alert-danger py-1 mb-0">Error al verificar.</div>';
        });
}
</script>
