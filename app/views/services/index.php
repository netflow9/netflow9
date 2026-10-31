<?php
/*
    CLD: 2026-10-31T11:05:39+02:00: new (was the Services section of the Admin plugin's installation page): the installation's services (ServicesController::index):
    $services, each with its `status` (util\Services::status); start / stop / restart (ServicesController::control)
*/
$this->pageTitle = 'Services';
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . \V::app()->html->escape(\V::app()->csrf->getToken(false)) . '">';
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();
    $bc = new \sys\html\Breadcrumbs([ 'items' => [ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Services' ] ] ]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <p class="text-muted small mb-3">The background services of this installation. Start, stop and restart need them installed as system services (<code>scripts/4_services-install.sh</code>, as root).</p>
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
			<div class="text-muted"><?= \V::app()->html->escape($st['state']) ?><?= $st['pid'] ? ' &middot; pid ' . (int)$st['pid'] : '' ?><?= $st['since'] ? ' &middot; up ' . \V::app()->html->escape(\util\Format::duration((time() - $st['since']) * 1000)) : '' ?></div>
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
			<form method="post" action="<?= \V::app()->createUrl('/services/control') ?>" class="d-inline" onsubmit="return <?= $verb === 'start' ? 'true' : "confirm('" . ucfirst($verb) . ' ' . \V::app()->html->escape($s['label']) . "?')" ?>">
			    <?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($id) ?>"><input type="hidden" name="verb" value="<?= $verb ?>">
			    <button type="submit" class="btn btn-sm btn-outline-<?= $verb === 'stop' ? 'danger' : 'secondary' ?>"><i class="bi <?= $icon ?> me-1"></i><?= ucfirst($verb) ?></button>
			</form>
			<?php endforeach; ?>
			<?php else: ?>
			<span class="text-muted" title="Run scripts/4_services-install.sh as root to manage it here">scripts/4_services-install.sh</span>
			<?php endif; ?>
		    </td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>
</div>
