<h5>Authentication &raquo; Add user</h5>

<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";
?>

<div class="row mx-0">
    <div class="col-4">
	<form method="post" class="validate" novalidate>
	    <?php
		echo R::app()->html->field('username', [
		    'type' => 'text',
		    'label' => 'Username',
		    'value' => $username ?? '',
		]);
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

		$dbs = R::app()->server->listDatabases();
		echo R::app()->html->field('db', [
		    'type' => 'select',
		    'label' => 'DB',
		    'value' => $db ?? '',
		    'input' => [ 'class' => 'form-control' ],
		    'list' => array_combine($dbs, $dbs),
		]);


		echo R::app()->html->field('role', [
		    'type' => 'select',
		    'label' => 'Role',
		    'value' => $role ?? '',
		    'list' => $roles,
		]);

		echo R::app()->html->submit('Create', [ 'class' => 'btn btn-sm btn-success', 'pjax-submit' => 1 ]);
	    ?>

	</form>
    </div>
</div>
