<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the account's tool servers in the administration (hook admin:account-panels): $account, $servers (Plugin::servers), $enabled (ids), $open
    (LocalMcpSlot::open: [ server => [ account => n ] ]); a switch each (controllers/AdminController::toggle)
*/
//CLD: 2026-10-23T01:30:00+03:00: \V::app()->html->escape() called directly (was a $e wrapper)
$aid = (string)$account->_id;
?>
<h6>Tool servers <span class="text-muted fw-normal">(AI steps)</span></h6>
<div class="table-responsive mb-4">
    <table class="table table-sm align-middle small">
	<thead><tr><th>Server</th><th>Sessions open</th><th class="text-end">For this account</th></tr></thead>
	<tbody>
	<?php foreach($servers as $id => $s):
	    $on = in_array($id, $enabled, true); ?>
	    <tr>
		<td><div class="fw-semibold"><?= \V::app()->html->escape($s['label']) ?></div><div class="text-muted"><?= \V::app()->html->escape($s['description'] ?? '') ?></div></td>
		<td><?= (int)($open[$id][$aid] ?? 0) ?> of <?= (int)($s['max_sessions_per_account'] ?? 1) ?></td>
		<td class="text-end">
		    <form method="post" action="<?= \V::app()->createUrl('/LocalMCP/admin/toggle') ?>" class="d-inline">
			<input type="hidden" name="<?= \V::app()->csrf->csrf_key ?>" value="<?= \V::app()->html->escape(\V::app()->csrf->getToken(false)) ?>">
			<input type="hidden" name="id" value="<?= \V::app()->html->escape($aid) ?>"><input type="hidden" name="server" value="<?= \V::app()->html->escape($id) ?>"><input type="hidden" name="on" value="<?= $on ? 0 : 1 ?>">
			<button type="submit" class="btn btn-sm <?= $on ? 'btn-success' : 'btn-outline-secondary' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><i class="bi <?= $on ? 'bi-toggle-on' : 'bi-toggle-off' ?> me-1"></i><?= $on ? 'Enabled' : 'Disabled' ?></button>
		    </form>
		</td>
	    </tr>
	<?php endforeach; ?>
	<?php if (!$servers): ?><tr><td colspan="3" class="text-muted">No tool servers (plugins/LocalMCP/servers.php)</td></tr><?php endif; ?>
	</tbody>
    </table>
</div>
