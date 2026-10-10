<?php
/**
 * Placeholder for modules delivered in a later build phase.
 * Set $pageTitle, $activeNav, $phaseNumber, $phaseSummary, $phaseFeatures before including.
 */
include APP_ROOT . '/templates/header.php'; ?>
<div class="card">
  <div class="card-body py-5 text-center">
    <i class="bi bi-cone-striped display-5 text-warning"></i>
    <h2 class="h4 mt-3"><?= h($pageTitle) ?></h2>
    <p class="text-muted mb-4">
      Scheduled for <strong>build phase <?= (int) ($phaseNumber ?? 0) ?></strong>.
      <?= h($phaseSummary ?? '') ?>
    </p>
    <?php if (!empty($phaseFeatures)): ?>
      <ul class="list-group list-group-flush mx-auto text-start" style="max-width:34rem">
        <?php foreach ($phaseFeatures as $f): ?>
          <li class="list-group-item small"><i class="bi bi-dot"></i> <?= h($f) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <a class="btn btn-outline-primary mt-4" href="<?= h(url('dashboard.php')) ?>">Back to dashboard</a>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
