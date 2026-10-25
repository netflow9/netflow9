<?php
$this->pageTitle = \V::t('Create an account');
?>

<main class="position-relative d-flex flex-column align-items-center h-100">
    <header class="pt-3">
        <h1><img src="/img/app-logo.svg" height="48" class="pe-2"><?=\V::app()->config('app.name')?></h1>
    </header>

    <div class="card w-100" style="max-width:420px">
	<div class="card-body p-4">
	<?php if ($sent): ?>
	    <h5 class="mb-3 text-center"><i class="bi bi-envelope-check text-success me-1"></i><?= \V::t('Check your inbox') ?></h5>
	    <p class="mb-2"><?= \V::t('We sent an email to') ?> <strong><?= \V::app()->html->escape((string)$form->email) ?></strong>.</p>
	    <p class="text-muted small mb-0"><?= \V::t('Open the link in it (it works for 24 hours) to finish creating your account. No email? Look in the spam folder, or try again.') ?></p>
	<?php else: ?>
	    <?=\V::app()->form->create($form)?>
	    <h5 class="mb-3 text-center"><?= \V::t('Create an account') ?></h5>
	    <?php
	    echo \V::app()->form->field('email', [
		'type' => 'text',
		'label' => false,
		'input' => [
		    'placeholder' => \V::t('your email'), 'class' => 'form-control form-control-lg mt-2', 'autofocus' => 1, 'autocomplete' => 'email'
		],
		'bare' => true,
	    ]);
	    ?>
	    <p class="small text-muted mt-3 mb-0"><?= \V::t('We will send you a link to confirm the address. By continuing, you agree with our Terms and conditions.') ?></p>
	    <div class="form-group mb-2 text-center pt-4">
		<button class="btn btn-primary" type="submit"><?= \V::t('Continue') ?></button>
	    </div>
	    <?=\V::app()->form->end()?>
	<?php endif; ?>
	</div>
    </div>

    <?=\V::app()->html->link(\V::t('Already have an account? Sign in'), \V::app()->user->getLoginUrl(), [ 'class' => 'pt-2' ])?>
</main>
