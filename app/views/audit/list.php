<?php
$label = ($system ? 'System ' : '') . ($log == 'ExecutionLog' ? 'execution log' : 'audit log');
$label = ucfirst($label);
$this->pageTitle = $label;

//schema and filtering
$sf = \util\WidgetsUtil::getSchema($log);
$schema = $sf['schema'];
unset($schema['updated_at']);
//a project's log: every entry is of that project, no Project column
if (!$system)
    unset($schema['project']);
if (isset($schema['created_at']))
    $schema['created_at']['label'] = 'When';
$schema = \util\JSON::encode($schema);
$filter = \util\JSON::encode($sf['filter']);

//the grid's data: list?data=1 with this log's query string ($qData: with the agents' filter)
$dataUrl = '/audit/list' . ($qData === '' ? '?data=1' : "{$qData}&data=1");

$title = $system ? $label : $label . ': ' . \V::app()->html->escape((string)\V::app()->om->findById('Project', \V::app()->user->crtProject));

//the audit log: all of it, or only the agents' tool calls
$agentLink = '';
if ($log == 'AuditLog')
    $agentLink = \util\VString::insert('<a class=\'btn btn-sm {:cls} ms-3\' href=\'{:href}\'><i class=\'bi bi-robot me-1\'></i>Agent actions</a>', [
	'cls' => $agent ? 'btn-primary' : 'btn-outline-primary',
	'href' => '/audit/list' . ($agent ? $q : ($q === '' ? '?agent=1' : "{$q}&agent=1")),
    ]);

$script = "
+function() {
    const container = document.getElementById('obj-list');
    new app.GridView(container, {
	title: `<h5 class='h5 mb-0'>{$title}</h5>{$agentLink}`,
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
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?= (new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => $label ],
    ]]))->render() ?>
</div>

<div id="obj-list" class="main-body p-4"></div>
