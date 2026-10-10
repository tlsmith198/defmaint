</main>
<footer class="admi-footer">
  <div class="container-fluid d-flex flex-wrap justify-content-between small">
    <span><?= h(setting('company_name', 'Aerospace & Defense Manufacturing Inc.')) ?>
      &middot; CAGE <?= h(setting('cage_code', '97WZ5')) ?></span>
    <span>Equipment, Asset &amp; Maintenance Management
      &middot; build <?= h(defined('APP_BUILD') ? APP_BUILD : '—') ?></span>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(url('assets/js/app.js')) ?>"></script>
</body>
</html>
