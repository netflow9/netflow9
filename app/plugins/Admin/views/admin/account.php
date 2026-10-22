<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: one account (AdminController::account): $account, $users, $projects, $roleNames ([ role id => name ]), $workflows ([ project id => n ]),
    $owner (whom "view as" signs in as; null: none); suspend / reactivate, view as; the plugins' sections (hook admin:account-panels)
*/
$name = (string)$account->name !== '' ? (string)$account->name : '(no name)';
$this->pageTitle = 'Administration::' . $name;
//CLD: 2026-10-23T01:30:00+03:00: \V::app()->html->escape() called directly (was a $e wrapper)
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . \V::app()->html->escape(\V::app()->csrf->getToken(false)) . '">';
$tab = 'accounts';
$crumbs = [ [ 'label' => 'Accounts', 'href' => '/Admin/admin/accounts' ], [ 'label' => $name ] ];
require __DIR__ . '/_header.php';
$suspended = (string)$account->state === 'disabled';
$own = (string)$account->_id === (string)\V::app()->user->aid;
?>

<div class="main-body p-4">
    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
	<div class="me-auto">
	    <h5 class="mb-1"><?= \V::app()->html->escape($name) ?> <span class="badge <?= $suspended ? 'text-bg-danger' : 'text-bg-success' ?> align-middle fs-6"><?= \V::app()->html->escape($account->schema['state']->choices[(string)$account->state] ?? $account->state) ?></span></h5>
	    <div class="text-muted small"><?= \V::app()->html->escape($account->hostname) ?> &middot; created <?= \V::app()->html->escape(gmdate('Y-m-d', $account->_id->getTimestamp())) ?> &middot; id <code><?= \V::app()->html->escape($account->_id) ?></code></div>
	</div>
	<?php if (!$own): ?>
	<form method="post" action="<?= \V::app()->createUrl('/Admin/admin/view_as') ?>" no-pjax class="m-0">
	    <?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($account->_id) ?>">
	    <button type="submit" class="btn btn-sm btn-outline-primary" <?= $owner ? '' : 'disabled' ?> title="<?= $owner ? 'Sign in as ' . \V::app()->html->escape($owner) . ', read-only, for at most ' . (\plugins\Admin\Plugin::VIEW_MAX / 60) . ' minutes' : 'No active owner' ?>">
		<i class="bi bi-eye me-1"></i>View as <?= $owner ? \V::app()->html->escape($owner) : 'owner' ?>
	    </button>
	</form>
	<?php endif; ?>
    </div>

    <?php if (!$own): ?>
    <div class="border rounded-3 p-3 mb-4 <?= $suspended ? 'border-danger-subtle' : '' ?>" style="max-width: 720px">
	<form method="post" action="<?= \V::app()->createUrl('/Admin/admin/account_state') ?>" class="m-0" onsubmit="return confirm('<?= $suspended ? 'Reactivate' : 'Suspend' ?> <?= \V::app()->html->escape(addslashes($name)) ?>?')">
	    <?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($account->_id) ?>"><input type="hidden" name="state" value="<?= $suspended ? 'active' : 'disabled' ?>">
	    <?php if ($suspended): ?>
	    <div class="small mb-2">Suspended: its users cannot sign in, nothing new runs (schedules, webhooks, forms, mail), its queued jobs and sleeping runs wait.</div>
	    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-play-circle me-1"></i>Reactivate</button>
	    <?php else: ?>
	    <label class="form-label small mb-1" for="suspend-reason">Suspend: its users are signed out at once, nothing new runs, its queued jobs wait until it is reactivated</label>
	    <div class="d-flex gap-2">
		<input id="suspend-reason" type="text" name="reason" class="form-control form-control-sm" maxlength="300" placeholder="Why (kept in the audit log)" required>
		<button type="submit" class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-pause-circle me-1"></i>Suspend</button>
	    </div>
	    <?php endif; ?>
	</form>
    </div>
    <?php endif; ?>

    <h6>Users <span class="text-muted fw-normal">(<?= count($users) ?>)</span></h6>
    <div class="table-responsive mb-4">
	<table class="table table-sm align-middle small">
	    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>State</th><th>2FA</th></tr></thead>
	    <tbody>
	    <?php foreach($users as $u): ?>
		<tr>
		    <td><?= \V::app()->html->escape($u) ?><?= !empty($u->superadmin) ? ' <span class="badge text-bg-dark">super-admin</span>' : '' ?></td>
		    <td><?= \V::app()->html->escape($u->email) ?></td>
		    <td><?= \V::app()->html->escape($roleNames[(string)$u->role] ?? '') ?></td>
		    <td><?= \V::app()->html->escape($u->state) ?></td>
		    <td><?= !empty($u->tfa_secret) ? '<i class="bi bi-check-lg text-success" aria-label="yes"></i>' : '<span class="text-muted">no</span>' ?></td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>

    <h6>Projects <span class="text-muted fw-normal">(<?= count($projects) ?>)</span></h6>
    <div class="table-responsive mb-4">
	<table class="table table-sm align-middle small">
	    <thead><tr><th>Project</th><th class="text-end">Members</th><th class="text-end">Workflows</th></tr></thead>
	    <tbody>
	    <?php foreach($projects as $p): ?>
		<tr>
		    <td><?= \V::app()->html->escape($p->name) ?><?= !empty($p->personal) ? ' <span class="text-muted">(personal)</span>' : '' ?></td>
		    <td class="text-end"><?= count((array)($p->members ?? [])) ?></td>
		    <td class="text-end"><?= number_format($workflows[(string)$p->_id] ?? 0) ?></td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>

    <?php \V::app()->hooks->applyHook('admin:account-panels', [ $account ]); ?>
</div>
