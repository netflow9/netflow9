<h5>Change password for user: <?= $user ?> on <?= $db ?></h5>

<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";
?>

<div class="row mx-0">
    <div class="col-4">
	<form method="post" class="validate" novalidate>
	    <?php
		echo R::app()->html->field('password', [
		    'type' => 'text',
		    'label' => 'Password',
		    'value' => $password ?? '',
		]);

		echo R::app()->html->field('password2', [
		    'type' => 'text',
		    'label' => 'Confirm Password',
		    'value' => $password2 ?? '',
		]);
		echo R::app()->html->submit('Create', [ 'class' => 'btn btn-sm btn-success', 'pjax-submit' => 1 ]);
	    ?>

	</form>
    </div>
</div>
