<h5><?php if (!empty($db)) echo "$db &raquo; "; ?>Create new collection</h5>

<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";
?>

<div class="row mx-0">
    <div class="col-4">
	<form method="post" class="validate" novalidate>
	    <?php
		echo R::app()->html->field('db', [
		    'type' => 'text',
		    'label' => 'Database',
		    'value' => $db ?? '',
		]);
		echo R::app()->html->field('name', [
		    'type' => 'text',
		    'label' => 'Collection',
		    'value' => $name ?? '',
		    'input' => [ 'class' => 'form-control', 'autofocus' => 1 ]
		]);

		echo '<div class="my-2">Options</div>';

		echo R::app()->html->field('is_capped', [
		    'type' => 'checkbox',
		    'label' => 'Is Capped',
		    'value' => $is_capped ?? 0,
		]);

		echo R::app()->html->field('size', [
		    'type' => 'text',
		    'label' => 'Size (bytes)',
		    'value' => $size ?? 0,
		]);

		echo R::app()->html->field('max', [
		    'type' => 'text',
		    'label' => 'Size (documents)',
		    'value' => $max ?? 0,
		]);

		echo R::app()->html->submit('Create', [ 'class' => 'btn btn-sm btn-success', 'pjax-submit' => 1 ]);
		echo R::app()->html->link('Back', 'javascript:history.back()', [ 'class' => 'btn btn-sm btn-outline-secondary mx-1' ]);
	    ?>

	</form>
    </div>
</div>
