<h5><?= $db . '.' . $collection ?>: <?= !empty($id) ? 'Edit record' : 'Create record' ?></h5>

<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";

    if (!empty($message))
	echo "<pre>$message</pre>";

    $id = 'editor-' . uniqid();
?>

<div class="row mx-0">
    <div class="col-12">
	<form method="post" class="validate" novalidate>
	    <?php
		echo R::app()->html->field('document', [
		    'id' => $id,
		    'type' => 'textarea',
		    'label' => false,
		    'value' => $document,
		    'rows' => 20,
		]);
		echo R::app()->html->submit('Save', [ 'class' => 'btn btn-sm btn-success', 'pjax-submit' => 1 ]);
		echo R::app()->html->link('Back', 'javascript:history.back()', [ 'class' => 'btn btn-sm btn-outline-secondary mx-1' ]);
	    ?>
	</form>
    </div>
</div>

<script>
    $('#<?= $id ?>').ace();
</script>
