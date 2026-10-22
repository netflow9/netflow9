<?php
//CLD: 2026-10-11T22:01:40+03:00: app.GridView (was the global gridView of data.grid.js, now in app.dataview.js)
//CLD: 2026-10-11T17:35:22+03:00: new view: the audit log (AuditController::list), newest first; the eye opens an entry's details (AuditController::view)
//CLD: 2026-10-11T17:45:25+03:00: the audit log or the execution log ($log: AuditLog | ExecutionLog; ?log=execution on its URLs)
//CLD: 2026-10-11T20:09:30+03:00: the system log ($system: every entry of the account) or the current project's log; $q comes from the controller (log, scope)
$label = ($system ? 'System ' : '') . ($log == 'ExecutionLog' ? 'execution log' : 'audit log');
$label = ucfirst($label);
$this->pageTitle = $label;

//schema and filtering
$sf = \util\WidgetsUtil::getSchema($log);
$schema = $sf['schema'];
unset($schema['updated_at']);
//CLD: 2026-10-11T20:21:05+03:00: a project's log: every entry is of that project, no Project column
if (!$system)
    unset($schema['project']);
if (isset($schema['created_at']))
    $schema['created_at']['label'] = 'When';
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$schema = \util\JSON::encode($schema);
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$filter = \util\JSON::encode($sf['filter']);

//CLD: 2026-10-21T00:55:00+03:00: the grid's data: list?data=1 with this log's query string (was /audit/data{$q})
$dataUrl = '/audit/list' . ($q === '' ? '?data=1' : "{$q}&data=1");

$title = $system ? $label : $label . ': ' . \V::app()->html->escape((string)\V::app()->om->findById('Project', \V::app()->user->crtProject));

$script = "
+function() {
    const container = document.getElementById('obj-list');
    new app.GridView(container, {
	title: `<h5 class='h5 mb-0'>{$title}</h5>`,
	dataUrl: '{$dataUrl}',

	schema: {
	    readonly: true,
	    data: {$schema},
	},

	filter: {
		data: {$filter},
	},

	sorter: { sortField: 'created_at', sortDir: 'desc' },

	grid: {
		createRowUrl: false,
		rowActions: {
		    view: `<a href='/audit/view/{{item._id}}{$q}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Details'><i class='bi bi-eye'></i></a>`,
		}
	},
    });
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
	[ 'label' => $label ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div id="obj-list" class="main-body p-4"></div>
