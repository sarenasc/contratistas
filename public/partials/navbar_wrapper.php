<?php
// Asumimos que la página ya hizo: require_once public/_bootstrap.php
$display_name = nombre_usuario();
?>

<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
  <div class="container-fluid px-3">
    <a class="navbar-brand" href="<?= BASE_URL ?>/Inicio.php">
      <span class="brand-title">Sistema Contratista</span>
      <span class="brand-subtitle">Condor de Apalta</span>
      <?php if ($display_name): ?>
        <small class="d-block app-user-name">
          <?= htmlspecialchars($display_name) ?>
        </small>
      <?php endif; ?>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <?php require __DIR__ . '/navbar.php'; ?>

        <li class="nav-item">
          <a class="nav-link text-danger" href="<?= BASE_URL ?>/logout.php">Cerrar sesión</a>
        </li>
      </ul>
    </div>
  </div>
</nav>
