<?php
//CLD: 2026-10-19T14:09:20+03:00: new partial: the steps of the initial setup (SetupController::STEPS) above each step's page; $crt: the current step (an action), or 'done'
$keys = array_keys(\controllers\SetupController::STEPS);
$pos = $crt === 'done' ? count($keys) : array_search($crt, $keys, true);
?>
<div class="d-flex align-items-center gap-2 mb-4 small">
<?php foreach(\controllers\SetupController::STEPS as $action => $title):
    $i = array_search($action, $keys, true); ?>
    <?php if ($i > 0): ?><div class="flex-fill border-top <?= $i <= $pos ? 'border-success' : '' ?>"></div><?php endif; ?>
    <div class="d-flex align-items-center gap-1 text-nowrap <?= $i === $pos ? 'fw-semibold' : ($i > $pos ? 'text-muted' : '') ?>">
	<?php if ($i < $pos): ?>
	    <i class="bi bi-check-circle-fill text-success"></i>
	<?php else: ?>
	    <span class="badge rounded-pill <?= $i === $pos ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= $i + 1 ?></span>
	<?php endif; ?>
	<?= \V::t($title) ?>
    </div>
<?php endforeach; ?>
</div>
