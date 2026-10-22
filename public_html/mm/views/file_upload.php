<!-- Navigation & Menu -->
<div class="row mx-1">
    <div class="col">
	<h5><?= $db . '.' . $collection ?> - upload file</h5>
    </div>
    <div class="col-auto">
    </div>
</div>

<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";
?>

<div class="row mx-0">
    <div class="col-4">
	<form method="post" enctype="multipart/form-data">
	    <input class="mb-2" type="file" name="file"/>
	    <?= R::app()->html->submit('Upload', [ 'class' => 'btn btn-sm btn-success', 'pjax-submit' => 1 ]); ?>
	    <?= R::app()->html->link('Back', 'javascript:history.back()', [ 'class' => 'btn btn-sm btn-outline-secondary mx-1' ]); ?>
	</form>
    </div>
</div>


