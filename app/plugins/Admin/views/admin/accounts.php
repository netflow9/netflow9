<?php
/*
    the accounts (AdminController::accounts): $accounts, $counts (users, projects, workflows, runs of the last 30 days: [ aid => n ]), $q (the search);
    the plugins' short texts per account (hook admin:account-summary)
*/
$this->pageTitle = 'Administration::Accounts';
$tab = 'accounts';
$crumbs = [ [ 'label' => 'Accounts' ] ];
require __DIR__ . '/_header.php';
$states = [ 'active' => 'text-bg-success', 'disabled' => 'text-bg-danger', 'incomplete' => 'text-bg-secondary' ];
?>

<div class="main-body p-4">
    <form method="get" action="<?= \V::app()->createUrl('/Admin/admin/accounts') ?>" class="d-flex gap-2 mb-3" style="max-width: 480px">
	<input type="search" name="q" value="<?= \V::app()->html->escape($q) ?>" class="form-control form-control-sm" placeholder="Name, hostname, or a user's email" aria-label="Search accounts">
	<button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>

    <div class="table-responsive">
	<table class="table table-sm table-hover align-middle small">
	    <thead><tr><th>Account</th><th>State</th><th>Tier</th><th>Created</th><th class="text-end">Users</th><th class="text-end">Projects</th><th class="text-end">Workflows</th><th class="text-end">Runs, 30 d</th><th>Also</th></tr></thead>
	    <tbody>
	    <?php foreach($accounts as $a):
		$aid = (string)$a->_id;
		$also = new \ArrayObject();
		\V::app()->hooks->applyHook('admin:account-summary', [ $a, $also ]);
	    ?>
		<tr>
		    <td>
			<a href="<?= \V::app()->createUrl('/Admin/admin/account', [ 'id' => $aid ]) ?>" class="fw-semibold"><?= \V::app()->html->escape((string)$a->name !== '' ? $a->name : '(no name)') ?></a>
			<div class="text-muted"><?= \V::app()->html->escape($a->hostname) ?></div>
		    </td>
		    <td><span class="badge <?= $states[(string)$a->state] ?? 'text-bg-secondary' ?>"><?= \V::app()->html->escape($a->schema['state']->choices[(string)$a->state] ?? $a->state) ?></span></td>
		    <td class="<?= (string)$a->tier === '' ? 'text-muted' : '' ?>"><?= \V::app()->html->escape(\util\Tiers::labelOf($a)) ?></td>
		    <td class="text-nowrap"><?= \V::app()->html->escape(\util\Format::date($a->_id)) ?></td>
		    <td class="text-end"><?= \util\Format::number($counts['users'][$aid] ?? 0) ?></td>
		    <td class="text-end"><?= \util\Format::number($counts['projects'][$aid] ?? 0) ?></td>
		    <td class="text-end"><?= \util\Format::number($counts['workflows'][$aid] ?? 0) ?></td>
		    <td class="text-end"><?= \util\Format::number($counts['runs'][$aid] ?? 0) ?></td>
		    <td class="text-muted"><?= implode(' &middot; ', \V::app()->html->escape($also->getArrayCopy())) ?></td>
		</tr>
	    <?php endforeach; ?>
	    <?php if (!$accounts): ?>
		<tr><td colspan="9" class="text-muted">No account<?= $q !== '' ? ' matches' : '' ?>.</td></tr>
	    <?php endif; ?>
	    </tbody>
	</table>
    </div>
    <?php if (count($accounts) >= 500): ?><div class="text-muted small">The newest 500: search to find others.</div><?php endif; ?>
</div>
