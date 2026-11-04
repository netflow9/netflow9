<?php
//CLD: 2026-10-11T22:01:40+03:00: app.GridView (was the global gridView of data.grid.js, now in app.dataview.js)

$this->pageTitle = 'Data Tables - list';

$createBtn = '';
if (\V::app()->user->can('DataTable::create')) {
    $createBtn = \V::app()->html->link(
	'Create',
	\V::app()->createUrl('/datatable/create'),
	//CLD: 2026-10-11T21:32:01+03:00: data-method post: Create makes a table, so it is a POST (app.pjax sends the link's data-method; was a GET)
	[ 'class' => 'btn btn-primary btn-sm', 'escape' => false, 'data-method' => 'post' ]
    );
}

$dataUrl = \V::app()->createUrl('', [ 'data' => 1 ]);
//CLD: 2026-11-03T14:00:00+02:00: Share, with DataTable::share (not in a personal project); the second list: the tables shared with this project (open only: the rest is their project's)
$shareUrl = '/share/manage?object=DataTableDef&id={{item._id}}';
$shareAction = $canShare ? "share: `<a href='{$shareUrl}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Share'><i class='bi bi-share'></i></a>`,
		" : '';
$sharedDataUrl = \V::app()->createUrl('', [ 'data' => 1, 'shared' => 1 ]);
//CLD: 2026-11-04T12:00:00+02:00: Move, as Delete: it leaves this project
$moveAction = \V::app()->user->can('DataTable::delete') ? "move: `<a href='/move/manage?object=DataTableDef&id={{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Move to another project'><i class='bi bi-folder-symlink'></i></a>`,
		" : '';

$script = "
+function(){
    const container = document.getElementById('datatables-list');
    new app.GridView(container, {
	filter: false,
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Data Tables</h5>`,
	dataUrl: '{$dataUrl}',

	schema: {
	    readonly: true,
	    updateColumnUrl: '',
	    createColumnUrl: '',
	    data: {
		//_id: { label: 'ID', type: 'object', filter: false, choices: [] },
		name: { label: 'Name', type: 'text', filter: true },
		rows: { label: 'Rows', type: 'number', filter: false },
		cols: { label: 'Cols', type: 'number', filter: false },
		//CLD: 2026-11-03T14:00:00+02:00: how many projects it is shared with
		shares: { label: 'Shared with', type: 'number', filter: false },
		created_at: { label: 'Created', type: 'datetime', filter: true },
	    },
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		manage: `<a href='/datatable/manage/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn'><i class='bi bi-table'></i></a>`,
		{$shareAction}{$moveAction}update: `<a href='/datatable/update/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn'><i class='bi bi-pencil'></i></a>`,
		delete: `<a href='/datatable/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' data-confirm='Are you sure?' data-method='post'><i class='bi bi-trash'></i></a>`,
	    }
	},
	onInit: function(e) {
	    app.events.on('object::deleted object::created object::updated', (e) => {
		container._dataView.reload();
	    });
	}
    });
}();
";
\V::app()->cs->addPageScript($script);

if ($sharedCount > 0) {
    \V::app()->cs->addPageScript("
+function(){
    const container = document.getElementById('datatables-shared');
    new app.GridView(container, {
	filter: false,
	create: '',
	title: `<h5 class='h5 mb-0'>Shared with this project</h5>`,
	dataUrl: '{$sharedDataUrl}',
	schema: {
	    readonly: true,
	    updateColumnUrl: '',
	    createColumnUrl: '',
	    data: {
		name: { label: 'Name', type: 'text', filter: false },
		project: { label: 'From project', type: 'text', filter: false },
		access: { label: 'This project may', type: 'text', filter: false },
		rows: { label: 'Rows', type: 'number', filter: false },
	    },
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		manage: `<a href='/datatable/manage/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Open'><i class='bi bi-table'></i></a>`,
	    }
	},
    });
}();
");
}

?>
<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Data Tables' ],
    ]]);
    echo $bc->render();
    ?>
    <?php //CLD: 2026-10-11T20:51:55+03:00: Import (a new table from a CSV / Excel file; DatatableController::import opens as a dialog) ?>
    <?php //CLD: 2026-10-19T22:19:24+03:00: the text in .btn-label (only the icon on phones) ?>
    <?php if (\V::app()->user->can('DataTable::create')): ?>
    <div class="spacer"></div>
    <a href="<?= \V::app()->createUrl('/datatable/import') ?>" class="btn btn-sm btn-outline-primary py-0" title="Create a data table from a CSV or Excel file"><i class="bi bi-upload me-1"></i><span class="btn-label">Import</span></a>
    <?php endif; ?>
</div>

<div class="main-body p-4">
    <div id="datatables-list"></div>
    <?php if ($sharedCount > 0): ?>
    <div id="datatables-shared" class="mt-4"></div>
    <?php endif; ?>
</div>
