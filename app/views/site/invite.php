<?php
//CLD: 2026-10-17T22:00:00+03:00: new view: the page of a project invitation link (SiteController::invite): the user's name and password; an expired / used link says so; an address
//that is already a user of the account was added to the project and signs in as usual
$this->pageTitle = \V::t('Join a project');
$esc = fn($s) => \V::app()->html->escape((string)$s);
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:460px">
	<div class="card-body p-4">
	<?php if (!$invite): ?>
	    <h5 class="mb-3 text-center"><?= \V::t('This invitation does not work any more') ?></h5>
	    <p class="mb-3"><?= \V::t('Invitations work once, for 7 days. If you already joined, sign in; else ask the person who invited you to send it again.') ?></p>
	    <div class="d-flex gap-2 justify-content-center">
		<a class="btn btn-primary" href="<?= \V::app()->user->getLoginUrl() ?>"><?= \V::t('Sign in') ?></a>
	    </div>
	<?php elseif ($existing): ?>
	    <h5 class="mb-3 text-center"><?= \V::t('You joined') ?> <?= $esc($project->name) ?></h5>
	    <p class="mb-3"><?= $esc($invite->email) ?> <?= \V::t('already has a user in') ?> <b><?= $esc($account->name ?: $account->hostname) ?></b>: <?= \V::t('sign in with it and choose the project in the sidebar.') ?></p>
	    <div class="d-flex gap-2 justify-content-center">
		<a class="btn btn-primary" href="<?= \V::app()->user->getLoginUrl() ?>"><?= \V::t('Sign in') ?></a>
		<a class="btn btn-outline-secondary" href="/site/forgot"><?= \V::t('Forgot the password?') ?></a>
	    </div>
	<?php else: ?>
	    <?=\V::app()->form->create($form, [ 'action' => '/site/invite?t=' . rawurlencode($token) ])?>
	    <h5 class="mb-1 text-center"><?= \V::t('Join') ?> <?= $esc($project->name) ?></h5>
	    <p class="text-center text-muted small mb-3"><?= $esc($account->name ?: $account->hostname) ?> &middot; <?= $esc($invite->email) ?></p>
	    <?php
	    echo '<div class="row g-2"><div class="col-sm-6">';
	    echo \V::app()->form->field('firstname', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('First name'), 'input' => [ 'autofocus' => 1, 'autocomplete' => 'given-name' ] ]);
	    echo '</div><div class="col-sm-6">';
	    echo \V::app()->form->field('lastname', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Last name'), 'input' => [ 'autocomplete' => 'family-name' ] ]);
	    echo '</div></div>';
	    echo \V::app()->form->field('new_password', [ 'dir' => 'v', 'type' => 'password', 'label' => \V::t('Password'), 'input' => [ 'autocomplete' => 'new-password' ],
		'help' => \V::t('At least 8 characters, with lower and upper case letters, a digit and a symbol') ]);
	    echo \V::app()->form->field('cfm_password', [ 'dir' => 'v', 'type' => 'password', 'label' => \V::t('Password again'), 'input' => [ 'autocomplete' => 'new-password' ] ]);
	    ?>
	    <div class="form-group mb-2 text-center pt-3">
		<button class="btn btn-primary" type="submit"><?= \V::t('Join the project') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	<?php endif; ?>
	</div>
    </div>
</main>
