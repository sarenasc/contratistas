
<!-- Bootstrap Bundle (incluye Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Inyectar CSRF token automáticamente en fetch() y XMLHttpRequest
(function () {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  if (!csrfToken) return;

  // Interceptar fetch()
  const _fetch = window.fetch;
  window.fetch = function (input, init) {
    init = init || {};
    if (!init.method || init.method.toUpperCase() === 'GET') return _fetch(input, init);
    init.headers = new Headers(init.headers || {});
    if (!init.headers.has('X-CSRF-TOKEN')) {
      init.headers.set('X-CSRF-TOKEN', csrfToken);
    }
    return _fetch(input, init);
  };

  // Interceptar XMLHttpRequest.send()
  const _open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method) {
    this._csrfMethod = method;
    return _open.apply(this, arguments);
  };
  const _setRequestHeader = XMLHttpRequest.prototype.setRequestHeader;
  const _send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (body) {
    if (this._csrfMethod && this._csrfMethod.toUpperCase() !== 'GET') {
      _setRequestHeader.call(this, 'X-CSRF-TOKEN', csrfToken);
    }
    return _send.apply(this, arguments);
  };
})();
</script>

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
