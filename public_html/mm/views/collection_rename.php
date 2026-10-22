<h5>Rename <?= $db . '.' . $collection ?></h5>

<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";
?>

			
<div class="row mx-0">
    <div class="col-4">
	<form method="post" class="validate" novalidate>
	    <?php
		echo R::app()->html->field('newname', [
		    'type' => 'text',
		    'label' => 'To:',
		    'value' => $newname ?? '',
		    'input' => [ 'class' => 'form-control', 'autofocus' => 1 ]
		]);

		echo '<div class="my-2">Options</div>';

		echo R::app()->html->field('remove_target', [
		    'type' => 'checkbox',
		    'label' => 'Remove target if exists',
		    'value' => $remove_target ?? 1,
		]);
		echo R::app()->html->submit('Rename', [ 'class' => 'btn btn-sm btn-success', 'pjax-submit' => 1 ]);
		echo R::app()->html->link('Back', 'javascript:history.back()', [ 'class' => 'btn btn-sm btn-outline-secondary mx-1' ]);
	    ?>

	</form>
    </div>
</div>
