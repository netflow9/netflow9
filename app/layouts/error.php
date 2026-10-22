<?php
$this->pageTitle = 'Error: ' . $status;
ob_start();
?>
<main class="position-relative d-flex flex-column align-items-center h-100">
    <div class="card w-100" style="max-width:600px;transform: translateY(10vh)">
	<div class="card-body p-4">

	    <h1 class="text-center text-danger pb-4">
		<i class="bi bi-exclamation-triangle"></i>
		Error <?=$status?>
	    </h1>

	    <?= $message ?>

	    <div class="form-group mb-2 text-center pt-4">
		<a class="btn btn-primary" no-pjax href="<?=\V::app()->createUrl('/site/index')?>"><?=\V::t('Go home')?></a>
	    </div>
	</div>
    </div>
</main>
<?php
$content = ob_get_clean();
echo $this->renderFile('/layouts/_webpage', compact('content'));

