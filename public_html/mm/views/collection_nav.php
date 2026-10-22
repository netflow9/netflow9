<?php
    $command = new \MongoDB\Driver\Command([ 'count' => $collection ]);
    $cursor = R::app()->server->manager->executeCommand($db, $command);
    $res = current($cursor->toArray());
?>
<!-- Navigation & Menu -->
<div class="row mx-1">
    <div class="col">
	<h5>
	    <?= $db . '.' . $collection ?> (<?= $res->n ?> records)
	</h5>
    </div>
    <div class="col-auto">
	<?php
	switch($tab) {
	    case 'browse':
		$is_file = preg_match("/\\.files$/", $collection);
		$route = ($is_file ? 'app.file_upload' : 'app.document_edit');
		$title = ($is_file ? 'Upload file' : 'Insert document');
		$url = R::app()->url($route, [ 'db' => $db, 'collection' => $collection ]);
		echo R::app()->html->link($title, $url, [ 'class' => 'btn btn-sm btn-primary', 'pjax' => 1 ]);
		break;
	    case 'indexes':
		$url = R::app()->url('app.index_create', [ 'db' => $db, 'collection' => $collection ]);
		echo R::app()->html->link('Create index', $url, [ 'class' => 'btn btn-sm btn-primary mx-1', 'pjax' => 1 ]);
		//$url = R::app()->url('collection.indexCreate2d', [ 'db' => $db, 'collection' => $collection ]);
		//echo R::app()->html->link('Create 2D index', $url, [ 'class' => 'btn btn-sm btn-primary mx-1', 'pjax' => 1 ]);
		break;
	}
	?>
    </div>
</div>

<div class="row m-1">
    <div class="col">
	<ul class="nav nav-underline">
	    <li class="nav-item">
		<a class="nav-link p-0 <?= $tab == 'browse' ? 'active' : '' ?>" href="<?= R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $collection ]) ?>" pjax>Find/modify</a>
	    </li>
	    <li class="nav-item">
		<a class="nav-link p-0 <?= $tab == 'aggregate' ? 'active' : '' ?>" href="<?= R::app()->url('app.collection_aggregate', [ 'db' => $db, 'collection' => $collection ]) ?>" pjax>Aggregate</a>
	    </li>
	    <li class="nav-item">
		<a class="nav-link p-0 <?= $tab == 'indexes' ? 'active' : '' ?>" href="<?= R::app()->url('app.index_list', [ 'db' => $db, 'collection' => $collection ]) ?>" pjax>Indexes</a>
	    </li>
	</ul>
    </div>
</div>
