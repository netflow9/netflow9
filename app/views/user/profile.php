<?php
$this->pageTitle = 'Settings::Personal';
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Profile' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">

    <?= \V::app()->form->create($user) ?>
	<!-- header -->
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<h5>Personal settings</h5>
		<span class="text-muted">Modify your personal settings and setup MFA</span>
	    </div>
	    <div class="flex-fill"></div>
	    <div class="flex-shrink-0 d-flex align-items-center gap-2">
		<div class="avatar avatar-lg">
		    <?= \V::app()->user->avatar('lg') ?>
		</div>
		<div class="flex-grow-1" style="min-width:0;">
		    <?php //CLD: 2026-10-05T20:30:32+03:00: escape user-editable user and role names ?>
		    <div class="text-truncate"><?= \V::app()->html->escape((string)\V::app()->user->name) ?></div>
		    <div class="text-muted small"><?= \V::app()->html->escape((string)\V::app()->om->findById('Role', \V::app()->user->role)->name) ?></div>
		</div>
	    </div>
	</div>

	<div class="pt-4 w-50">
	    <h6>Basic information</h6>
	    <?= $user->schema['firstname']->input([ 'cols' => '3,9']) ?>
	    <?= $user->schema['lastname']->input([ 'cols' => '3,9']) ?>
	    <?= $user->schema['email']->input([ 'cols' => '3,9']) ?>
	</div>

	<div class="pt-4 w-50">
	    <h6>Security</h6>

	    <?php //CLD: 2026-10-21T16:30:00+03:00: the account enforces MFA (sys\Boot::enforce_mfa sends the user here until 2FA is set up) ?>
	    <?php $mfaEnforced = !empty(\V::app()->config('rt.account')->enforce_mfa); ?>
	    <?php if ($mfaEnforced && empty($user->tfa_secret)): ?>
	    <div class="alert alert-warning"><i class="bi bi-shield-lock me-1"></i>Your account requires two-factor authentication: activate it below to continue.</div>
	    <?php endif; ?>

	    <div class="form-group row mb-2">
		<label for="field-password" class="col-sm-3 col-form-label">Password</label>
		<div class="col-sm-9">
		    <?php //CLD: 2026-10-21T10:00:00+03:00: a password kept elsewhere (e.g. an LDAP user's) is changed there ?>
		    <?php if (($pwSource = \sys\Identity::passwordSource($user)) !== null): ?>
		    <div class="form-control-plaintext text-muted"><i class="bi bi-diagram-3 me-1"></i>Your <?= \V::app()->html->escape($pwSource) ?> password: change it in your directory</div>
		    <?php else: ?>
		    <a class="btn btn-outline-info" href="<?= \V::app()->createUrl('/user/self_change_password') ?>">Change</a>
		    <?php endif; ?>
		</div>
	    </div>

	    <?php
	    if (empty($user->tfa_secret)) {
		$url = \V::app()->createUrl('/user/tfa_activate');
		$label = 'Activate';
	    } else {
		$url = \V::app()->createUrl('/user/tfa_deactivate');
		$label = 'Deactivate';
	    }
	    ?>
	    <div class="form-group row mb-2">
		<label for="field-2fa" class="col-sm-3 col-form-label">Two factor Auth</label>
		<div class="col-sm-9">
		    <?php //CLD: 2026-10-21T16:30:00+03:00: no Deactivate while the account enforces MFA ?>
		    <?php if ($mfaEnforced && !empty($user->tfa_secret)): ?>
		    <div class="form-control-plaintext text-muted"><i class="bi bi-shield-check me-1"></i>Active (required by your account)</div>
		    <?php else: ?>
		    <a class="btn btn-outline-info" href="<?= $url ?>"><?= $label ?></a>
		    <?php endif; ?>
		</div>
	    </div>

	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>

    <?= \V::app()->form->end() ?>
</div>