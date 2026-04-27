/* ============================================
   MULTISELECT.JS
   Plugin vanilla JS para multiselect con tags
   ============================================
   Uso:
     initMultiselect('#mi-select')
     initMultiselect('.mi-clase', { placeholder: 'Elegir...', max: 5 })
   ============================================ */

(function() {
  'use strict';

  /**
   * Convierte un <select multiple> en un multiselect con tags.
   * @param {string|Element} selector - CSS selector o elemento <select>
   * @param {object} opts - Opciones opcionales
   */
  function initMultiselect(selector, opts) {
    const els = typeof selector === 'string'
      ? Array.from(document.querySelectorAll(selector))
      : [selector];

    els.forEach(function(select) {
      if (!select || select.dataset.msInit) return;
      select.dataset.msInit = '1';
      select.style.display = 'none';

      const options = Array.from(select.options);
      const cfg = Object.assign({
        placeholder : select.dataset.placeholder || 'Seleccionar opciones…',
        searchPlaceholder: select.dataset.search   || 'Buscar…',
        max         : parseInt(select.dataset.max) || 0,  // 0 = sin límite
        searchable  : select.dataset.searchable !== 'false',
      }, opts || {});

      /* ── Construir el DOM ── */
      const wrapper = document.createElement('div');
      wrapper.className = 'multiselect-wrapper';

      const box = document.createElement('div');
      box.className = 'multiselect-box';
      box.setAttribute('tabindex', '0');
      box.setAttribute('role', 'combobox');
      box.setAttribute('aria-haspopup', 'listbox');
      box.setAttribute('aria-expanded', 'false');

      const msInput = document.createElement('input');
      msInput.className = 'ms-input';
      msInput.placeholder = cfg.placeholder;
      msInput.setAttribute('autocomplete', 'off');
      if (!cfg.searchable) msInput.readOnly = true;

      const dropdown = document.createElement('div');
      dropdown.className = 'ms-dropdown';
      dropdown.setAttribute('role', 'listbox');

      box.appendChild(msInput);
      wrapper.appendChild(box);
      wrapper.appendChild(dropdown);
      select.parentNode.insertBefore(wrapper, select.nextSibling);

      let selected = new Set(); // valores seleccionados

      /* ── Render opciones en el dropdown ── */
      function renderDropdown(filter) {
        dropdown.innerHTML = '';
        filter = (filter || '').toLowerCase();

        const visible = options.filter(function(opt) {
          return !filter || opt.text.toLowerCase().includes(filter);
        });

        if (!visible.length) {
          const empty = document.createElement('div');
          empty.className = 'ms-empty';
          empty.textContent = 'Sin resultados';
          dropdown.appendChild(empty);
          return;
        }

        visible.forEach(function(opt) {
          const item = document.createElement('div');
          item.className = 'ms-option' + (selected.has(opt.value) ? ' selected' : '');
          item.setAttribute('role', 'option');
          item.setAttribute('aria-selected', selected.has(opt.value) ? 'true' : 'false');
          item.dataset.value = opt.value;

          const check = document.createElement('div');
          check.className = 'ms-option-check';
          check.innerHTML = selected.has(opt.value) ? '✓' : '';

          const label = document.createElement('span');
          label.textContent = opt.text;

          item.appendChild(check);
          item.appendChild(label);

          item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            toggleOption(opt.value, opt.text);
          });

          dropdown.appendChild(item);
        });
      }

      /* ── Render tags en el box ── */
      function renderTags() {
        /* quitar tags existentes */
        box.querySelectorAll('.ms-tag, .ms-count').forEach(function(el) { el.remove(); });
        msInput.placeholder = selected.size ? '' : cfg.placeholder;

        const maxVisible = 3;
        let i = 0;
        selected.forEach(function(val) {
          if (i >= maxVisible) return;
          const opt = options.find(function(o) { return o.value === val; });
          if (!opt) return;
          const tag = document.createElement('span');
          tag.className = 'ms-tag';
          tag.innerHTML = opt.text +
            '<button class="ms-tag-remove" data-val="' + val + '" type="button" aria-label="Quitar">×</button>';
          box.insertBefore(tag, msInput);
          i++;
        });

        if (selected.size > maxVisible) {
          const count = document.createElement('span');
          count.className = 'ms-count';
          count.style.display = 'inline-flex';
          count.textContent = '+' + (selected.size - maxVisible);
          box.insertBefore(count, msInput);
        }

        /* Sincronizar el <select> original */
        options.forEach(function(opt) {
          opt.selected = selected.has(opt.value);
        });

        /* Disparar evento change en el select original */
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }

      /* ── Toggle selección ── */
      function toggleOption(val, text) {
        if (cfg.max && selected.size >= cfg.max && !selected.has(val)) {
          /* límite alcanzado — visual feedback */
          box.style.borderColor = '#dc2626';
          setTimeout(function() { box.style.borderColor = ''; }, 600);
          return;
        }
        if (selected.has(val)) {
          selected.delete(val);
        } else {
          selected.add(val);
        }
        renderTags();
        renderDropdown(msInput.value);
      }

      /* ── Abrir / cerrar dropdown ── */
      function openDropdown() {
        box.classList.add('open', 'focused');
        box.setAttribute('aria-expanded', 'true');
        dropdown.classList.add('open');
        renderDropdown();
        msInput.focus();
      }

      function closeDropdown() {
        box.classList.remove('open', 'focused');
        box.setAttribute('aria-expanded', 'false');
        dropdown.classList.remove('open');
        msInput.value = '';
      }

      /* ── Eventos ── */
      box.addEventListener('click', function(e) {
        /* Click en quitar tag */
        if (e.target.classList.contains('ms-tag-remove')) {
          selected.delete(e.target.dataset.val);
          renderTags();
          renderDropdown(msInput.value);
          return;
        }
        dropdown.classList.contains('open') ? closeDropdown() : openDropdown();
      });

      box.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDropdown();
        if (e.key === 'Enter')  { e.preventDefault(); openDropdown(); }
        if (e.key === 'Backspace' && !msInput.value && selected.size) {
          const last = Array.from(selected).pop();
          selected.delete(last);
          renderTags();
          renderDropdown();
        }
      });

      msInput.addEventListener('input', function() {
        if (!dropdown.classList.contains('open')) openDropdown();
        renderDropdown(this.value);
      });

      document.addEventListener('click', function(e) {
        if (!wrapper.contains(e.target)) closeDropdown();
      });

      /* ── Inicializar con opciones pre-seleccionadas ── */
      options.forEach(function(opt) {
        if (opt.selected) selected.add(opt.value);
      });

      renderTags();

      /* ── API pública en el elemento ── */
      select.msGetValues   = function()  { return Array.from(selected); };
      select.msClearAll    = function()  { selected.clear(); renderTags(); renderDropdown(); };
      select.msSelectAll   = function()  {
        options.forEach(function(o) { selected.add(o.value); });
        renderTags(); renderDropdown();
      };
      select.msSetValues   = function(vals) {
        selected = new Set(vals);
        renderTags(); renderDropdown();
      };
    });
  }

  /* ── Auto-inicializar selects con data-multiselect ── */
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('select[data-multiselect]').forEach(function(el) {
      initMultiselect(el);
    });
  });

  /* Exportar globalmente */
  window.initMultiselect = initMultiselect;

})();
