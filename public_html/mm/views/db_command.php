<h5>Execute command</h5>

<form method="post">
    <div class="row mx-1" style="background-color: #eeefff">
	<div class="col">
	    <?php
	    echo R::app()->html->field('command', [
		'type' => 'textarea',
		'label' => 'Command',
		'value' => $command,
		'rows' => 5,
		'id' => 'command-' . uniqid(),
	    ]);
	    $dbs = R::app()->server->listDatabases();
	    echo R::app()->html->field('db', [
		'type' => 'select',
		'label' => 'DB',
		'value' => $db,
		'input' => [ 'class' => 'form-control' ],
		'list' => array_combine($dbs, $dbs),
	    ]);
	    ?>
	</div>
	<div class="col-auto">
	    <input type="submit" class="btn btn-sm btn-success mt-4" value="Go" pjax-submit>
	</div>
    </div>
</form>
<script>
    $('textarea[name=command]').ace();
</script>

<?php 
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";

    if(!empty($ret))
	echo Utils::highlight($ret);
