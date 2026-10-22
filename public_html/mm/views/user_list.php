<!-- Navigation & Menu -->
<div class="row mx-1">
    <div class="col">
	<h5>
	    Users and Authentication
	</h5>
    </div>
    <div class="col-auto">
	<?php
	    $url = R::app()->url('app.user_add');
	    echo R::app()->html->link('Add User', $url, [ 'class' => 'btn btn-sm btn-primary mx-1', 'pjax' => 1 ]);
	?>
    </div>
</div>

<?php
    $auth_message = null;
    try {
	$command = new \MongoDB\Driver\Command([ 'getCmdLineOpts' => 1 ]);
	$cursor = R::app()->server->manager->executeCommand('admin', $command);
	$cursor->setTypeMap([ 'root' => 'array', 'document' => 'array' ]);
	$ret = current($cursor->toArray());

	$auth_enabled = $ret['parsed']['security']['authorization'] ?? '';
	if (empty($auth_enabled) || $auth_enabled != 'enabled')
	    $auth_message = 'User authentication not enabled';
    } catch (Exception $e) {
	$auth_message = 'error:' . $e->getMessage();
    }

    if (!empty($auth_message))
	echo "<div class='alert alert-warning'>$auth_message</div>";
?>

<table class="table table-striped table-hover">
    <tr>
	<th>DB</th>
	<th>User</th>
	<th>Roles</th>
	<th>Operation</th>
    </tr>
    <?php foreach ($users as $user): ?>
	<tr>
	    <td><?= $user['db'] ?></td>
	    <td><?= $user['user'] ?></td>
	    <td>
		<?php
		foreach($user['roles'] as $rline)
		    echo "<p class='mb-0'> on: {$rline['db']}: role: {$rline['role']}</p>";
		?>
	    </td>
	    <td>
		<?php
		    $url = R::app()->url('app.user_drop', [ 'user' => $user['user'], 'db' => $user['db'] ]);
		    $title = '<i class="bi bi-trash"></i>';
		    echo R::app()->html->link($title, $url, [
			'data-confirm' => 'Please confirm',
			'data-method' => 'post',
			'title' => 'Delete',
			'class' => 'mx-1',
		    ]);

		    $url = R::app()->url('app.user_password', [ 'user' => $user['user'], 'db' => $user['db'] ]);
		    $title = '<i class="bi bi-key"></i>';
		    echo R::app()->html->link($title, $url, [
			'title' => 'Change password',
			'class' => 'mx-1',
			'pjax' => 1,
		    ]);
		?>
	    </td>
    </tr>
    <?php endforeach; ?>
</table>
