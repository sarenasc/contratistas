
<!-- Bootstrap Bundle (incluye Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
  $(document).ready(function() {
    if ($('#cargo').length) {
      $('#cargo').select2({
        placeholder: 'Buscar y seleccionar cargos...',
        allowClear: true,
        width: '100%'
      });
    }
  });

  $(document).ready(function() {
    $('.select2-cargo').select2({
        width: '100%',
        placeholder: "Buscar cargo...",
        allowClear: true
    });
});

</script>


</body>
</html>
