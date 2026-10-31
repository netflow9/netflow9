<?php
/*
    the installation (AdminController::index): $app (AdminController::appInfo), the plugins' sections (hook admin:installation-panels)
    CLD: 2026-10-31T11:05:39+02:00: the services: the core's page (views/services/index.php)
*/
$this->pageTitle = 'Administration';
//CLD: 2026-10-24T16:30:00+03:00: util\Format::duration / bytes called directly (were the $ago / $gb helpers: min / h / days, GB); the numbers: util\Format::number (was number_format)
$tab = 'installation';
require __DIR__ . '/_header.php';
?>

<div class="main-body p-4">
    <h6 class="mb-2">Installation</h6>
    <div class="row g-3 mb-4 small">
	<?php foreach([
	    'Accounts' => \util\Format::number($app['accounts']) . ($app['suspended'] ? ' (' . $app['suspended'] . ' suspended)' : ''),
	    'Users' => \util\Format::number($app['users']),
	    'Workflows' => \util\Format::number($app['workflows']),
	    'Runs, last 24 h' => \util\Format::number($app['runs_24h']),
	    'Jobs waiting' => \util\Format::number($app['jobs_waiting']),
	    'Version' => $app['commit'] ?? '?',
	    'PHP / MongoDB' => $app['php'] . ' / ' . ($app['mongo'] ?? '?'),
	    'Server' => \V::app()->html->escape($app['host']) . ($app['load'] ? ' &middot; load ' . implode(' ', array_map(fn($l) => \util\Format::number($l, 2), $app['load'])) : ''),
	    'Disk free' => ($app['disk_free'] === false ? '?' : \util\Format::bytes($app['disk_free'])) . ' of ' . ($app['disk_total'] === false ? '?' : \util\Format::bytes($app['disk_total'])),
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
