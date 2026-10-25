<?php
//CLD: 2026-10-19T14:09:20+03:00: new view: the initial setup, step 2 (SetupController::server): the server's settings (models\setup\ServerForm; were on views/setup/index.php)
$this->pageTitle = \V::t('Setup');
//the field's options first: they override the defaults (`type` => password / select)
$v = fn($name, $label, $opts = []) => \V::app()->form->field($name, $opts + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t($label) ]);
?>

<main class="position-relative d-flex flex-column align-items-center h-100 pb-5 overflow-auto">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?= \V::app()->html->escape(\V::app()->config('app.name')) ?></h1>
    </header>

    <div class="card w-100" style="max-width:720px">
	<div class="card-body p-4">
	    <?= $this->renderFile('setup/_steps', [ 'crt' => 'server' ]) ?>
	    <?=\V::app()->form->create($form, [ 'action' => '/setup/server' ])?>
	    <h5 class="mb-3"><?= \V::t('Server') ?></h5>
	    <?= $v('base_url', 'Public address', [ 'help' => \V::t('The address users open; links in emails start with it') ]) ?>
	    <div class="row g-2">
		<div class="col-sm-8"><?= $v('mongo_uri', 'MongoDB connection string') ?></div>
		<div class="col-sm-4"><?= $v('mongo_db', 'Database name', [ 'help' => \V::t('Must not have accounts yet') ]) ?></div>
	    </div>

	    <h6 class="mt-4"><?= \V::t('Outgoing email') ?></h6>
	    <div class="row g-2">
		<div class="col-sm-6"><?= $v('smtp_host', 'SMTP server') ?></div>
		<div class="col-sm-2"><?= $v('smtp_port', 'Port') ?></div>
		<div class="col-sm-4"><?= $v('smtp_secure', 'Encryption', [ 'type' => 'select', 'list' => [ '' => \V::t('None (STARTTLS)'), 'tls' => 'TLS', 'ssl' => 'SSL' ] ]) ?></div>
	    </div>
	    <div class="row g-2">
		<div class="col-sm-6"><?= $v('smtp_username', 'Username', [ 'help' => \V::t('Empty: no authentication'), 'input' => [ 'autocomplete' => 'off' ] ]) ?></div>
		<div class="col-sm-6"><?= $v('smtp_password', 'Password', [ 'type' => 'password', 'input' => [ 'autocomplete' => 'new-password' ],
		    'help' => $passwordKept ? \V::t('Empty: keep the one entered before') : false ]) ?></div>
	    </div>
	    <?php //CLD: 2026-10-19T14:18:52+03:00: no inbound email domain (the Email inbound trigger is set up later in app.env: MAIL_INBOUND_DOMAIN) ?>
	    <?php //CLD: 2026-10-19T14:20:35+03:00: the default is filled in (noreply@<domain>, SetupController::server); empty still means noreply@ the public address's domain ?>
	    <?= $v('mail_from', 'Emails come from', [ 'help' => \V::t('The sender address of the emails the app sends') ]) ?>

	    <div class="d-flex justify-content-between pt-3">
		<a class="btn btn-outline-secondary" href="/setup"><i class="bi bi-arrow-left"></i> <?= \V::t('Back') ?></a>
		<button class="btn btn-primary" type="submit"><?= \V::t('Continue') ?> <i class="bi bi-arrow-right"></i></button>
	    </div>
	    <?=\V::app()->form->end()?>
	</div>
    </div>
</main>
