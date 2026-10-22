<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the installation (AdminController::index): $services (each with its `status`: util\Services::status), $app (AdminController::appInfo),
    the plugins' sections (hook admin:installation-panels)
*/
$this->pageTitle = 'Administration';
//CLD: 2026-10-23T01:30:00+03:00: \V::app()->html->escape() called directly (was a $e wrapper)
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . \V::app()->html->escape(\V::app()->csrf->getToken(false)) . '">';
$ago = function($ts) {
    if (!$ts)
	return '';
    $d = time() - $ts;
    return $d < 3600 ? round($d / 60) . ' min' : ($d < 86400 ? round($d / 3600, 1) . ' h' : round($d / 86400, 1) . ' days');
};
$gb = fn($b) => $b === false || $b === null ? '?' : number_format($b / 1073741824, 1) . ' GB';
$tab = 'installation';
require __DIR__ . '/_header.php';
?>

<div class="main-body p-4">
    <h6 class="mb-2">Services</h6>
    <div class="table-responsive mb-4">
	<table class="table table-sm align-middle small">
	    <thead><tr><th>Service</th><th>State</th><th>Check</th><th class="text-end">Actions</th></tr></thead>
	    <tbody>
	    <?php foreach($services as $id => $s):
		$st = $s['status'];
		$badge = $st['running'] === true ? 'text-bg-success' : ($st['running'] === false ? 'text-bg-danger' : 'text-bg-secondary');
		[ $hOk, $hText ] = $st['health'] ?? [ null, '' ];
	    ?>
		<tr>
		    <td>
			<div class="fw-semibold"><?= \V::app()->html->escape($s['label']) ?></div>
			<div class="text-muted"><?= \V::app()->html->escape($s['description'] ?? '') ?></div>
			<div class="text-muted"><code><?= \V::app()->html->escape($s['unit'] ?? '') ?></code><?= $st['unit'] ? '' : ' &middot; <span class="text-warning-emphasis">not a system service yet</span>' ?></div>
		    </td>
		    <td class="text-nowrap">
			<span class="badge <?= $badge ?>"><?= \V::app()->html->escape($st['running'] === true ? 'running' : ($st['running'] === false ? 'stopped' : 'unknown')) ?></span>
			<div class="text-muted"><?= \V::app()->html->escape($st['state']) ?><?= $st['pid'] ? ' &middot; pid ' . (int)$st['pid'] : '' ?><?= $st['since'] ? ' &middot; up ' . \V::app()->html->escape($ago($st['since'])) : '' ?></div>
		    </td>
		    <td>
			<?php if ($hOk !== null): ?>
			<span class="<?= $hOk ? 'text-success' : 'text-danger' ?>"><i class="bi <?= $hOk ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i><?= \V::app()->html->escape($hText) ?></span>
			<?php endif; ?>
		    </td>
		    <td class="text-end text-nowrap">
			<?php if ($st['unit']): ?>
			<?php foreach([ 'start' => 'bi-play-fill', 'restart' => 'bi-arrow-repeat', 'stop' => 'bi-stop-fill' ] as $verb => $icon):
			    if (($verb === 'start' && $st['running']) || ($verb !== 'start' && !$st['running']))
				continue; ?>
			<form method="post" action="<?= \V::app()->createUrl('/Admin/admin/service') ?>" class="d-inline" onsubmit="return <?= $verb === 'start' ? 'true' : "confirm('" . ucfirst($verb) . ' ' . \V::app()->html->escape($s['label']) . "?')" ?>">
			    <?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($id) ?>"><input type="hidden" name="verb" value="<?= $verb ?>">
			    <button type="submit" class="btn btn-sm btn-outline-<?= $verb === 'stop' ? 'danger' : 'secondary' ?>"><i class="bi <?= $icon ?> me-1"></i><?= ucfirst($verb) ?></button>
			</form>
			<?php endforeach; ?>
			<?php else: ?>
			<span class="text-muted" title="Run scripts/services-install.sh as root to manage it here">scripts/services-install.sh</span>
			<?php endif; ?>
		    </td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>

    <h6 class="mb-2">Installation</h6>
    <div class="row g-3 mb-4 small">
	<?php foreach([
	    'Accounts' => number_format($app['accounts']) . ($app['suspended'] ? ' (' . $app['suspended'] . ' suspended)' : ''),
	    'Users' => number_format($app['users']),
	    'Workflows' => number_format($app['workflows']),
	    'Runs, last 24 h' => number_format($app['runs_24h']),
	    'Jobs waiting' => number_format($app['jobs_waiting']),
	    'Version' => $app['commit'] ?? '?',
	    'PHP / MongoDB' => $app['php'] . ' / ' . ($app['mongo'] ?? '?'),
	    'Server' => \V::app()->html->escape($app['host']) . ($app['load'] ? ' &middot; load ' . implode(' ', array_map(fn($l) => number_format($l, 2), $app['load'])) : ''),
	    'Disk free' => $gb($app['disk_free']) . ' of ' . $gb($app['disk_total']),
	] as $label => $value): ?>
	<div class="col-6 col-md-4 col-xl-3">
	    <div class="border rounded-3 p-2 h-100">
		<div class="text-muted"><?= \V::app()->html->escape($label) ?></div>
		<div class="fw-semibold"><?= $label === 'Server' ? $value : \V::app()->html->escape($value) ?></div>
	    </div>
	</div>
	<?php endforeach; ?>
    </div>

    <?php \V::app()->hooks->applyHook('admin:installation-panels'); ?>
</div>
