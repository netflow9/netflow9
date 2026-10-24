<?php
$this->pageTitle = 'Account::Settings';
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Account settings' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?= \V::app()->form->create($account) ?>
	<!-- header -->
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<h5>Account settings</h5>
		<span class="text-muted">Modify per-account settings</span>
	    </div>
	    <div class="flex-fill"></div>
	</div>

	<div class="pt-4 w-50">
	    <h6>MFA</h6>
	    <?= $account->schema['enforce_mfa']->input([ 'cols' => '0,12' ]) ?>
	</div>

	<?php //CLD: 2026-10-21T23:00:00+03:00: the session limits (sys\Boot::after_user_login) ?>
	<div class="pt-4 w-50">
	    <h6>Sessions</h6>
	    <?= $account->schema['session_idle']->input([ 'cols' => '6,6' ]) ?>
	    <?= $account->schema['session_max']->input([ 'cols' => '6,6' ]) ?>
	    <div class="form-text">Apply to "Remember me" sign-ins too, which only stay signed in after the browser is closed.</div>
	</div>

	<div class="pt-4 w-50">
	    <h6>Logs</h6>
	    <?= $account->schema['audit_log_retention']->input([ 'cols' => '6,6' ]) ?>
	    <?= $account->schema['execution_log_retention']->input([ 'cols' => '6,6' ]) ?>
	    <?php //CLD: 2026-10-22T00:00:00+03:00: the audit log is kept 1 year at least (models\AuditLog::MIN_RETENTION_DAYS) ?>
	    <div class="form-text">A shorter time also removes the older entries already written. Audit log entries are kept at least 1 year.</div>
	</div>

	<?php //CLD: 2026-10-19T21:10:00+03:00: the inbound mail prefix moved into the InboundMail plugin (Settings menu: Inbound mail) ?>




	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
</div>

