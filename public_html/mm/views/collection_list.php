<?php

    $command = new \MongoDB\Driver\Command([ 'dbStats' => 1 ]);
    $cursor = R::app()->server->manager->executeCommand($db, $command);
    $dbInfo = current($cursor->toArray());

    //get db info
    $rowtpl = "
    <tr>
	<td><a href='#' class='nav-url' data-nav-id='{:key}'>{:name}</a></td>
	<td align='right'>{:documents}</td>
	<td align='right'>{:storageSize}</td>
	<td align='right'>{:totalSize}</td>
	<td align='right'>{:dataSize}</td>
	<td align='right'>{:indexSize}</td>
	<td align='right'>{:nindexes}</td>
    </tr>
    ";
?>
<div class="row m-1">
    <div class="col">
	<h5>Database: <?= $db ?>: <?= $dbInfo->collections ?> collections</h5>
    </div>
    <div class="col-auto">
	<a class="btn btn-sm btn-primary" href="<?= R::app()->url('app.collection_create', [ 'db' => $db ]) ?>" pjax>
	    Create collection
	</a>
    </div>
</div>

<table class="table table-striped table-hover">
    <tr>
	<th width="30%">Name</th>
	<th style="text-align:right">Documents</th>
	<th nowrap style="text-align:right">Storage size</th>
	<th nowrap style="text-align:right">Total size</th>
	<th nowrap style="text-align:right">Data size</th>
	<th nowrap style="text-align:right">Index size</th>
	<th nowrap style="text-align:right">Indexes</th>
    </tr>
    <?php
    $collections = R::app()->server->listCollections($db);
    foreach ($collections as $cName => $cCount) {
	$cInfo = current(R::app()->server->manager->executeCommand($db, new \MongoDB\Driver\Command(['collstats'=>$cName]))->toArray());
	echo Utils::pluck($rowtpl, [
	    'key' => "$db.$cName",
	    'name' => $cName,
	    'documents' => number_format($cInfo->count),
	    'storageSize' => Utils::getSizeHuman($cInfo->storageSize),
	    'totalSize' => Utils::getSizeHuman($cInfo->totalSize),
	    'dataSize' => Utils::getSizeHuman($cInfo->size),
	    'indexSize' => Utils::getSizeHuman($cInfo->totalIndexSize),
	    'nindexes' => $cInfo->nindexes,
	]);
    }
    ?>
</table>
<script>
$('a.nav-url').off('click').on('click', function(e) {
    e.preventDefault();
    $(document).trigger({ type: 'navigate', key: $(this).data('nav-id') });
})

</script>
