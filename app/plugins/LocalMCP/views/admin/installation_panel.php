<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the tool servers in the administration's installation page (hook admin:installation-panels): $servers (Plugin::servers), $open
    (LocalMcpSlot::open), the accounts enabled
*/
$e = fn($v) => \V::app()->html->escape((string)$v);
?>
<h6 class="mb-2">Tool servers <span class="text-muted fw-normal">(AI steps)</span></h6>
<div class="table-responsive mb-4">
    <table class="table table-sm align-middle small">
	<thead><tr><th>Server</th><th>Endpoint</th><th>Tools offered</th><th>Accounts</th><th>Sessions open</th></tr></thead>
	<tbody>
	<?php foreach($servers as $id => $s): ?>
	    <tr>
		<td class="fw-semibold"><?= $e($s['label']) ?> <span class="text-muted fw-normal">(<?= $e($id) ?>)</span></td>
		<td><code><?= $e($s['url']) ?></code></td>
		<td><?= isset($s['tools']) ? count((array)$s['tools']) : 'all' ?></td>
		<td><?= count(\plugins\LocalMCP\models\LocalMcpAccess::accountsOf($id)) ?></td>
		<td><?= array_sum($open[$id] ?? []) ?> of <?= (int)($s['max_sessions'] ?? 1) ?></td>
	    </tr>
	<?php endforeach; ?>
	</tbody>
    </table>
</div>
