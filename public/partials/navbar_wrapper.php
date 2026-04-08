<?php
// Asumimos que la página ya hizo: require_once public/_bootstrap.php
$username = $_SESSION['nom_usu'] ?? '';
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid px-3">
    <a class="navbar-brand" href="<?= BASE_URL ?>/Inicio.php">
      Sistema Contratista Condor de Apalta (Ex Almahue)
      <?php if ($username): ?>
        <small class="d-block text-muted">Usuario: <?= htmlspecialchars($username) ?></small>
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
