<?= R::app()->view->renderFile('collection_nav', [ 'tab' => 'indexes', 'db' => $db, 'collection' => $collection ]) ?>

<table class="table table-striped table-hover">
    <tr>
	<td width="20%">Name</td>
	<td>Key</td>
	<td>Properties</td>
	<td>Usage</td>
	<td>Ops</td>
    </tr>
    <?php foreach ($indexes as $index): ?>
	<tr>
	    <td valign="top" width="20%" style="word-break:break-all;"><?= $index['name'] ?></td>
	    <td><?= $index['key_text'] ?></td>
	    <td valign="top">
	    <?php
		if (!empty($index['unique']))
		    echo '<span class="badge bg-info">Unique</span>';
		if (!empty($index['2dsphere']))
		    echo '<span class="badge bg-info">2D sphere</span>';
		if (!empty($index['geo']))
		    echo '<span class="badge bg-info">Geo Haystack</span>';
		if (!empty($index['sparse']))
		    echo '<span class="badge bg-info">Sparse</span>';
		if (!empty($index['text']))
		    echo '<span class="badge bg-info">Text</span>';
		if (!empty($index['ttl']))
		    echo '<span class="badge bg-info">TTL</span>';
	    ?>
	    </td>
	    <td valign="top">
		<h5><?= $index['accesses']['ops'] ?></h5>
		<?php
		    $period = time() - $index['accesses']['since']->toDateTime()->format('u');
		    echo (int)($index['accesses']['ops'] * 60 / $period);
		    echo ' ops/min';
		?>
	    </td>
	    <td>
		<?php
		if (!(count($index['key']) == 1 && isset($index['key']['_id']))) {
		    $url = R::app()->url('app.index_drop', [ 'db' => $db, 'collection' => $collection, 'index' =>  $index['name'] ]);
		    $title = '<i class="bi bi-trash"></i>';
		    echo R::app()->html->link($title, $url, [
			'data-confirm' => 'Are you sure to drop this index ?',
			'data-method' => 'post',
		    ]);
		}
		?>
	    </td>
	</tr>
    <?php endforeach; ?>
</table>
