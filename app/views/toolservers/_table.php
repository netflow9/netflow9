<?php
/*
    the tool servers' table (util\ToolServers: mcp.json): the super-admins' page (index.php, with the MultiTenant plugin) and the account settings' tab
    (views/account/_tools.php, the owners): $servers, $open (models\ToolServerSlot::open: [ server => [ account => n ] ]), $enabled (the account's server ids: a switch each, the owners'
    tab; null: none, the super-admins switch them per account on the MultiTenant plugin's account page); Add, Edit, Remove, Test (ToolserversController)
*/
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . \V::app()->html->escape(\V::app()->csrf->getToken(false)) . '">';
$fileError = \util\ToolServers::fileError();
?>
    <div class="d-flex align-items-start gap-3 mb-3">
	<p class="text-muted small mb-0 flex-fill">MCP servers this installation runs (or reaches) for the AI steps of its accounts: each run of an AI step that uses one opens a session, within the limits below. They are kept in <code>mcp.json</code>, next to <code>app.env</code>. A web browser (Playwright) is added with <code>sudo scripts/playwright-mcp.sh install</code>.<?= $enabled === null ? ' Switch them on for an account on its page (Accounts).' : '' ?></p>
	<a href="<?= \V::app()->createUrl('/toolservers/edit') ?>" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i>Add server</a>
    </div>
    <?php if ($fileError !== null): ?>
	<div class="alert alert-danger small py-2"><?= \V::app()->html->escape($fileError) ?>: no server is offered until it is fixed by hand.</div>
    <?php endif; ?>
    <div class="table-responsive mb-4">
	<table class="table table-sm align-middle small">
	    <thead><tr><th>Server</th><th>Endpoint</th><th>Tools</th><th>Sessions open</th><?php if ($enabled === null): ?><th>Accounts</th><?php endif; ?><?php if ($enabled !== null): ?><th class="text-nowrap">This account</th><?php endif; ?><th class="text-end">Actions</th></tr></thead>
	    <tbody>
	    <?php foreach($servers as $id => $s):
		$managed = (string)($s['managed_by'] ?? '');
	    ?>
		<tr>
		    <td>
			<div class="fw-semibold"><?= \V::app()->html->escape($s['label']) ?> <span class="text-muted fw-normal">(<?= \V::app()->html->escape($id) ?>)</span><?php if ($managed !== ''): ?> <span class="badge text-bg-light border" title="Written by <?= \V::app()->html->escape($managed) ?>: changed and removed there">installed</span><?php endif; ?></div>
			<div class="text-muted"><?= \V::app()->html->escape($s['description'] ?? '') ?></div>
		    </td>
		    <td><code><?= \V::app()->html->escape($s['url']) ?></code></td>
		    <td><?= $s['tools'] === null ? 'all' : count($s['tools']) ?></td>
		    <td class="text-nowrap"><?= array_sum($open[$id] ?? []) ?> of <?= (int)$s['max_sessions'] ?> <span class="text-muted">(<?= (int)$s['max_sessions_per_account'] ?> per account)</span></td>
		    <?php if ($enabled === null): ?>
		    <td><?= count(\models\ToolServerAccess::accountsOf($id)) ?></td>
		    <?php else:
			$on = in_array($id, $enabled, true); ?>
		    <td class="text-nowrap">
			<form method="post" action="<?= \V::app()->createUrl('/toolservers/toggle') ?>" class="d-inline">
			    <?= $csrf ?><input type="hidden" name="server" value="<?= \V::app()->html->escape($id) ?>"><input type="hidden" name="on" value="<?= $on ? 0 : 1 ?>">
			    <button type="submit" class="btn btn-sm <?= $on ? 'btn-success' : 'btn-outline-secondary' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><i class="bi <?= $on ? 'bi-toggle-on' : 'bi-toggle-off' ?> me-1"></i><?= $on ? 'Enabled' : 'Disabled' ?></button>
			</form>
		    </td>
		    <?php endif; ?>
		    <td class="text-end text-nowrap">
			<form method="post" action="<?= \V::app()->createUrl('/toolservers/test') ?>" class="d-inline">
			    <?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($id) ?>">
			    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plug me-1"></i>Test</button>
			</form>
			<?php if ($managed === ''): ?>
			<a href="<?= \V::app()->createUrl('/toolservers/edit', [ 'id' => $id ]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
			<form method="post" action="<?= \V::app()->createUrl('/toolservers/remove') ?>" class="d-inline" onsubmit="return confirm('Remove <?= \V::app()->html->escape($s['label']) ?>? The AI steps that use it fail until it is removed from them.')">
			    <?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($id) ?>">
			    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Remove</button>
			</form>
			<?php endif; ?>
		    </td>
		</tr>
	    <?php endforeach; ?>
	    <?php if (!$servers): ?>
		<tr><td colspan="7" class="text-muted">No tool servers yet.</td></tr>
	    <?php endif; ?>
	    </tbody>
	</table>
    </div>
