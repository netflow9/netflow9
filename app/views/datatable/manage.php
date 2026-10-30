<?php
//CLD: 2026-10-11T22:01:40+03:00: app.GridView (was the global gridView of data.grid.js, now in app.dataview.js)

$this->pageTitle = 'Manage Data Table: ' . $dt->name;

$createBtn = '';
if (\V::app()->user->can('DataTable::create')) {
    $createBtn = \V::app()->html->link(
	'Create',
	\V::app()->createUrl('/datatable/create'),
	//CLD: 2026-10-11T21:32:01+03:00: data-method post: Create makes a table, so it is a POST (app.pjax sends the link's data-method; was a GET)
	[ 'class' => 'btn btn-primary btn-sm', 'escape' => false, 'data-method' => 'post' ]
    );
}

//schema and filtering
$sf = \util\WidgetsUtil::getSchema($dt->iname);
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$schema = \util\JSON::encode($sf['schema']);
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$filter = \util\JSON::encode($sf['filter']);

$dataUrl = \V::app()->createUrl('', [ 'id' => $dt->_id, 'data' => 1 ]);

//CLD: 2026-10-11T22:10:29+03:00: what this user may do here: the column designer needs DataTable::update, row editing DataTable::writeData (viewing: DataTable::readData)
$canUpdate = \V::app()->user->can('DataTable::update', [ 'object' => $dt ]);
$canWrite = \V::app()->user->can('DataTable::writeData', [ 'object' => $dt ]);

//CLD: 2026-10-05T21:06:21+03:00: column designer URLs; the grid replaces __KEY__ with the column key
$createColumnUrl = $canUpdate ? \V::app()->createUrl('/datatable/create_column', [ 'id' => $dt->_id ]) : '';
$updateColumnUrl = $canUpdate ? \V::app()->createUrl('/datatable/update_column', [ 'id' => $dt->_id, 'key' => '__KEY__' ]) : '';
$deleteColumnUrl = $canUpdate ? \V::app()->createUrl('/datatable/delete_column', [ 'id' => $dt->_id, 'key' => '__KEY__' ]) : '';
$readonly = $canUpdate ? 'false' : 'true';

//CLD: 2026-10-30T17:18:20+02:00: saved views (named filters, shared by the users who can open the table): list / save / delete URLs; the grid replaces __VIEW__ with the view id
$viewsUrl = \V::app()->createUrl('/datatable/views', [ 'id' => $dt->_id ]);
$createViewUrl = \V::app()->createUrl('/datatable/create_view', [ 'id' => $dt->_id ]);
$deleteViewUrl = \V::app()->createUrl('/datatable/delete_view', [ 'id' => $dt->_id, 'view' => '__VIEW__' ]);

//CLD: 2026-10-05T21:30:59+03:00: row editing URLs; rowActions templates get the row id from {{item._id}}
$createRowUrl = \V::app()->createUrl('/datatable/create_row', [ 'id' => $dt->_id ]);
$updateRowUrl = str_replace('__ROW__', '{{item._id}}', \V::app()->createUrl('/datatable/update_row', [ 'id' => $dt->_id, 'row' => '__ROW__' ]));
$deleteRowUrl = str_replace('__ROW__', '{{item._id}}', \V::app()->createUrl('/datatable/delete_row', [ 'id' => $dt->_id, 'row' => '__ROW__' ]));
//CLD: 2026-10-11T22:10:29+03:00: row editing (add-row button, edit / delete actions) only with DataTable::writeData
$createRowJs = $canWrite ? "'{$createRowUrl}'" : 'false';
$rowActionsJs = $canWrite ? "{
		    edit: `<a href='{$updateRowUrl}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Edit row'><i class='bi bi-pencil'></i></a>`,
		    delete: `<a href='{$deleteRowUrl}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Delete row' data-confirm='Delete this row?' data-method='post'><i class='bi bi-trash'></i></a>`,
		}" : '{}';

$script = "
+function() {
    const dtName = '{$dt->name}';
    //CLD: 2026-10-05T21:30:59+03:00: container kept for the reload on row events (onInit)
    const container = document.getElementById('datatables-list');
    new app.GridView(
	container, {
	    create: '{$createBtn}',
	    title: `<h5 class='h5 mb-0'>Manage Data Table: \${dtName}</h5>`,
	    dataUrl: '{$dataUrl}',
	    schema: {
		//CLD: 2026-10-11T22:10:29+03:00: read-only without DataTable::update (was always false)
		readonly: {$readonly},
		//CLD: 2026-10-05T21:06:21+03:00: real column designer URLs (was a non-existent action with a literal {{item._id}})
		updateColumnUrl: '{$updateColumnUrl}',
		createColumnUrl: '{$createColumnUrl}',
		deleteColumnUrl: '{$deleteColumnUrl}',
		data: {$schema},
	    },
	    filter: {
		data: {$filter},
		//CLD: 2026-10-30T17:18:20+02:00: saved views
		views: {
		    listUrl: '{$viewsUrl}',
		    createUrl: '{$createViewUrl}',
		    deleteUrl: '{$deleteViewUrl}',
		},
	    },
	    //CLD: 2026-10-30T17:18:20+02:00: the page size kept in this browser, the same for every data table
	    pager: {
		storeKey: 'netflow9.datatable.pageSize',
	    },
	    //CLD: 2026-10-05T21:30:59+03:00: was under `data:`, which the grid does not read (it reads `grid:`); real row URLs and actions
	    grid: {
		//CLD: 2026-10-11T22:10:29+03:00: add-row button and row actions only with DataTable::writeData (set in PHP above)
		createRowUrl: {$createRowJs},
		rowActions: {$rowActionsJs}
	    },
	    //CLD: 2026-10-05T21:30:59+03:00: reload rows after add/edit/delete (events sent by the row actions)
	    onInit: function(e) {
		app.events.on('object::deleted object::created object::updated', (e) => {
		    container._dataView.reload();
		});
	    }
	}
    );
}();
";
\V::app()->cs->addPageScript($script);

?>
<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Data Tables', 'href' => '/datatable/list' ],
	//CLD: 2026-10-05T20:42:44+03:00: Breadcrumbs prints labels as HTML; escape the user-editable name
	[ 'label' => \V::app()->html->escape((string)$dt->name) ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div id="datatables-list" class="main-body p-4"></div>
