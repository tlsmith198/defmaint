/* ADMI Maintenance -- small progressive-enhancement helpers. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // Whole-row navigation on list tables.
    document.querySelectorAll('tr[data-href]').forEach(function (tr) {
      tr.classList.add('row-link');
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a, button, input, label, select')) return;
        window.location = tr.dataset.href;
      });
    });

    // Confirm destructive / irreversible actions.
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (!window.confirm(el.dataset.confirm)) e.preventDefault();
      });
    });

    // Auto-submit filter controls.
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
      el.addEventListener('change', function () { el.form.submit(); });
    });

    // Client-side table filter box.
    document.querySelectorAll('[data-filter-table]').forEach(function (input) {
      var table = document.querySelector(input.dataset.filterTable);
      if (!table) return;
      input.addEventListener('input', function () {
        var needle = input.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (row) {
          row.style.display = row.textContent.toLowerCase().indexOf(needle) > -1 ? '' : 'none';
        });
      });
    });

    // Image preview before upload.
    document.querySelectorAll('[data-preview-target]').forEach(function (input) {
      input.addEventListener('change', function () {
        var img = document.querySelector(input.dataset.previewTarget);
        if (img && input.files && input.files[0]) {
          img.src = URL.createObjectURL(input.files[0]);
          img.classList.remove('d-none');
        }
      });
    });

    // Guard against double submits on slow shop-floor tablets.
    document.querySelectorAll('form[data-guard]').forEach(function (form) {
      form.addEventListener('submit', function () {
        form.querySelectorAll('button[type=submit]').forEach(function (b) {
          b.disabled = true;
          b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';
        });
      });
    });

    // Dismiss flash messages after a while.
    document.querySelectorAll('.alert-success.alert-dismissible').forEach(function (a) {
      setTimeout(function () {
        if (window.bootstrap && bootstrap.Alert) bootstrap.Alert.getOrCreateInstance(a).close();
      }, 6000);
    });
  });
})();
