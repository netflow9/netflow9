<?php

    $rowtpl = "
    <tr>
	<td><a href='#' class='nav-url' data-nav-id='{:key}'>{:name}</a></td>
	<td align='right'>{:storageSize}</td>
	<td align='right'>{:totalSize}</td>
	<td align='right'>{:dataSize}</td>
	<td align='right'>{:indexSize}</td>
	<td align='right'>{:collections}</td>
	<td align='right'>{:objects}</td>
    </tr>
    ";
?>
<div class="row m-1">
    <div class="col">
	<h5>Databases</h5>
    </div>
</div>


<table class="table table-striped table-hover">
    <tr>
	<th width="30%">Name</th>
	<th nowrap style="text-align:right">Storage size</th>
	<th nowrap style="text-align:right">Total size</th>
	<th nowrap style="text-align:right">Data size</th>
	<th nowrap style="text-align:right">Index size</th>
	<th style="text-align:right">Collections</th>
	<th style="text-align:right">Objects</th>
    </tr>
    <?php
    $dbs = R::app()->server->listDatabases();
    foreach ($dbs as $dbName) {
	$dbInfo = current(R::app()->server->manager->executeCommand($dbName, new \MongoDB\Driver\Command(['dbStats'=>1]))->toArray());
	echo Utils::pluck($rowtpl, [
	    'name' => $dbName,
	    'key' => $dbName,
	    'totalSize' => Utils::getSizeHuman($dbInfo->totalSize),
	    'storageSize' => Utils::getSizeHuman($dbInfo->storageSize),
	    'dataSize' => Utils::getSizeHuman($dbInfo->dataSize),
	    'indexSize' => Utils::getSizeHuman($dbInfo->indexSize),
	    'collections' => $dbInfo->collections,
	    'objects' => number_format($dbInfo->objects),
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
