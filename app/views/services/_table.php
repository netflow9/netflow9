<?php
/*
    the services' table: the Services page (index.php, with the MultiTenant plugin) and the account settings' tab (views/account/_services.php, without it):
    $services, each with its `status` (util\Services::withStatus); start / stop / restart (ServicesController::control)
*/
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . \V::app()->html->escape(\V::app()->csrf->getToken(false)) . '">';
?>
<?php //in Docker (util\Services::docker): read-only, managed with docker compose on the host ?>
<?php if (\util\Services::docker()): ?>
    <p class="text-muted small mb-3">The background services of this installation run in Docker: their state is their check's. Start, stop and restart them on the host, with <code>docker compose</code> (e.g. <code>docker compose restart daemon</code>).</p>
<?php else: ?>
    <p class="text-muted small mb-3">The background services of this installation. Start, stop and restart need them installed as system services (as root: <code>scripts/daemon.sh install</code>, <code>haraka.sh install</code>, <code>playwright-mcp.sh install</code>).</p>
<?php endif; ?>
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
			<?php if (empty($st['docker'])): ?>
			<div class="text-muted"><code><?= \V::app()->html->escape($s['unit'] ?? '') ?></code><?= $st['unit'] ? '' : ' &middot; <span class="text-warning-emphasis">not a system service yet</span>' ?></div>
			<?php endif; ?>
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
			<?php elseif (!empty($st['docker'])): ?>
			<code class="text-muted"><?= \V::app()->html->escape(\util\VString::insert('docker compose restart {:id}', [ 'id' => $id ])) ?></code>
			<?php else: ?>
			<span class="text-muted" title="Install it as a system service (its script in scripts/, as root) to manage it here">not a system service</span>
			<?php endif; ?>
		    </td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>
