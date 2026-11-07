<?php
//CLD: 2026-10-19T14:09:20+03:00: new view: the initial setup, step 2 (SetupController::server): the server's settings (models\setup\ServerForm; were on views/setup/index.php)
$this->pageTitle = \V::t('Setup');
//the field's options first: they override the defaults (`type` => password / select)
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
	    <?= \V::app()->form->field('base_url', [ 'help' => \V::t('The address users open; links in emails start with it') ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Public address') ]) ?>
	    <div class="row g-2">
		<div class="col-sm-8"><?= \V::app()->form->field('mongo_uri', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('MongoDB connection string') ]) ?></div>
		<div class="col-sm-4"><?= \V::app()->form->field('mongo_db', [ 'help' => \V::t('Must not have accounts yet') ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Database name') ]) ?></div>
	    </div>

	    <h6 class="mt-4"><?= \V::t('Outgoing email') ?></h6>
	    <div class="row g-2">
		<div class="col-sm-6"><?= \V::app()->form->field('smtp_host', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('SMTP server') ]) ?></div>
		<div class="col-sm-2"><?= \V::app()->form->field('smtp_port', [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Port') ]) ?></div>
		<div class="col-sm-4"><?= \V::app()->form->field('smtp_secure', [ 'type' => 'select', 'list' => [ '' => \V::t('None (STARTTLS)'), 'tls' => 'TLS', 'ssl' => 'SSL' ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Encryption') ]) ?></div>
	    </div>
	    <div class="row g-2">
		<div class="col-sm-6"><?= \V::app()->form->field('smtp_username', [ 'help' => \V::t('Empty: no authentication'), 'input' => [ 'autocomplete' => 'off' ] ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Username') ]) ?></div>
		<div class="col-sm-6"><?= \V::app()->form->field('smtp_password', [ 'type' => 'password', 'input' => [ 'autocomplete' => 'new-password' ],
		    'help' => $passwordKept ? \V::t('Empty: keep the one entered before') : false ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Password') ]) ?></div>
	    </div>
	    <?php //CLD: 2026-10-19T14:18:52+03:00: no inbound email domain (the Email inbound trigger is set up later in app.env: MAIL_INBOUND_DOMAIN) ?>
	    <?php //CLD: 2026-10-19T14:20:35+03:00: the default is filled in (noreply@<domain>, SetupController::server); empty still means noreply@ the public address's domain ?>
	    <?= \V::app()->form->field('mail_from', [ 'help' => \V::t('The sender address of the emails the app sends') ] + [ 'dir' => 'v', 'type' => 'text', 'label' => \V::t('Emails come from') ]) ?>

	    <div class="d-flex justify-content-between pt-3">
		<a class="btn btn-outline-secondary" href="/setup"><i class="bi bi-arrow-left"></i> <?= \V::t('Back') ?></a>
		<button class="btn btn-primary" type="submit"><?= \V::t('Continue') ?> <i class="bi bi-arrow-right"></i></button>
	    </div>
	    <?=\V::app()->form->end()?>
	</div>
    </div>
</main>
