<?php
$this->pageTitle = 'Inbound mail settings';
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Inbound mail settings' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?= \V::app()->form->create($settings) ?>
	<!-- header -->
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<h5>Inbound mail settings</h5>
		<span class="text-muted">The addresses of the Email (inbound) trigger: mail sent to them starts a workflow</span>
	    </div>
	    <div class="flex-fill"></div>
	</div>

	<div class="pt-4 w-50">
	    <?= $settings->schema['prefix']->input([ 'cols' => '3,9' ]) ?>
	    <?php if ($domain === ''): ?>
	    <div class="alert alert-warning small mt-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>The inbound domain is not set: set MAIL_INBOUND_DOMAIN in app.env (the domain Haraka receives), then the steps get their addresses.</div>
	    <?php else: ?>
	    <div class="form-text">Each Email (inbound) step has its own address: <code><?= \V::app()->html->escape($settings->prefix) ?>-xxxxxxxx@<?= \V::app()->html->escape($domain) ?></code>.</div>
	    <?php endif; ?>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
</div>
