<div class="mx-1 mb-1 d-flex justify-content-between">
    <span>
	<a pjax class="pager-nav mx-1" href="<?= R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $collection, 'page' => 1 ])?>">
	    <i class="bi bi-chevron-double-left"></i>
	</a>
	<a pjax class="pager-nav mx-1" href="<?= R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $collection, 'page' => $pager->prev() ])?>">
	    <i class="bi bi-chevron-left"></i>
	</a>
    </span>
    <span>
	<?=$pager->size()*($pager->page()-1)+1;?>-<?=min($pager->size()*$pager->page(),$pager->total());?>/<?=$pager->total();?>
    </span>
    <span>
	<a pjax class="pager-nav mx-1" href="<?= R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $collection, 'page' => $pager->next() ])?>">
	    <i class="bi bi-chevron-right"></i>
	</a>

	<a pjax class="pager-nav mx-1" href="<?= R::app()->url('app.collection_browse', [ 'db' => $db, 'collection' => $collection, 'page' => $pager->last() ])?>">
	    <i class="bi bi-chevron-double-right"></i>
	</a>
    </span>
</div>


<!-- list all records -->
<?php
    $row_render = function($index, $row, $is_file, $pager, $db, $collection, $criteria) {
	$actions = [];
	if(!empty($row['r']['_id'])) {
	    $url_params = [
		'db' => $db,
		'collection' => $collection,
		'id' => (string)$row['r']['_id'],
		'criteria' => $criteria,
	    ];

	    //update
	    $url = R::app()->url('app.document_edit', $url_params);
	    $title = '<i class="bi bi-pencil"></i>';
	    $actions[] = R::app()->html->link($title, $url, [ 'class' => 'mx-2', 'pjax' => 1 ]);

	    //delete
	    $url = R::app()->url('app.document_delete', $url_params);
	    $title = '<i class="bi bi-trash"></i>';
	    $actions[] = R::app()->html->link($title, $url, [ 'data-confirm' => 'Are you sure ?', 'data-method' => 'post', 'class' => 'mx-2' ]);

	    //refresh
	    $url = R::app()->url('app.document_get', $url_params);
	    $title = '<i class="bi bi-arrow-clockwise"></i>';
	    $actions[] = R::app()->html->link($title, $url, [ 'data-id' => str_replace('.','-',$row['r']['_id']), 'class' => 'refresh-record mx-2' ]);

	    //to clipboard
	    $url = '#';
	    $title = '<i class="bi bi-clipboard"></i>';
	    $actions[] = R::app()->html->link($title, $url, [ 'data-id' => str_replace('.','-',$row['r']['_id']), 'class' => 'copy-record mx-2', 'title' => 'Copy to clipboard', ]);

	    //expand/collapse
	    $url = '#';
	    $title = '<i class="bi bi-arrows-expand"></i>';
	    $actions[] = R::app()->html->link($title, $url, [ 'data-id' => str_replace('.','-',$row['r']['_id']), 'class' => 'expand-record mx-2', 'title' => 'Expand/Collapse' ]);

	    // for gridfs - download
	    if ($is_file) {
		$url = R::app()->url('app.file_download', $url_params);
		$title = '<i class="bi bi-download"></i>';
		$actions[] = R::app()->html->link($title, $url);
	    }
	}
	$actions = implode('', $actions);

	$record_no = $pager->total() - $pager->offset() - $index;
	$id = str_replace('.','-',$row['r']['_id']) ?? null;

	return "
	<div class='card record mb-1'>
	    <div class='card-header p-2'>
		#{$record_no}
		{$actions}
	    </div>
	    <div class='card-body p-2'>
		<!-- display record -->
		<div data-id='$id' class='record-row'>
		    {$row['disp']}
		</div>
		<!-- switch to text so we can copy it easieer -->
		<textarea class='record-text d-none' data-id='$id'>{$row['text']}</textarea>
	    </div>
	</div>
	";
    };


    echo '<div>';
    $is_file = preg_match("/\\.files$/", $collection);
    foreach ($rows as $index => $row)
	echo $row_render($index, $row, $is_file, $pager, $db, $collection, $criteria);
    echo '</div>';

