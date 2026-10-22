<?php
//the project's MCP server tokens (app.GridView, as views/variable/list.php) and the server's URL
$this->pageTitle = 'MCP server';

$createBtn = '';
$__newToken = \V::app()->om->create('McpToken', [ 'aid' => \V::app()->config('rt.account')->_id, 'project' => $project->_id ]);
if (\V::app()->user->can('McpToken::create', [ 'object' => $__newToken ])) {
    $createBtn = \V::app()->html->link(
	'Create token',
	\V::app()->createUrl('/SysMCPTools/token/create'),
	[ 'class' => 'btn btn-primary btn-sm', 'escape' => false ]
    );
}
$deleteAction = '';
if (\V::app()->user->can('McpToken::delete', [ 'object' => $__newToken ]))
    $deleteAction = "<a href='/SysMCPTools/token/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' data-confirm='Revoke this token? Clients using it stop working.' data-method='post' title='Revoke'><i class='bi bi-trash'></i></a>";

//schema and filtering
$sf = \util\WidgetsUtil::getSchema('McpToken');
$schema = $sf['schema'];
unset($schema['updated_at'], $schema['project'], $schema['token_hash']);
$schema = \util\JSON::encode($schema);
$filter = \util\JSON::encode($sf['filter']);
$url = \V::app()->html->escape(\V::app()->createAbsoluteUrl('/mcp'));

$script = "
+function() {
    const container = document.getElementById('obj-list');
    new app.GridView(container, {
	create: `{$createBtn}`,
	title: `<h5 class='h5 mb-0'>MCP server tokens</h5>`,
	dataUrl: '/SysMCPTools/token/list?data=1', /* CLD: 2026-10-21T00:45:00+03:00: (was /SysMCPTools/token/data) */

	schema: {
	    readonly: true,
	    data: {$schema},
	},

	filter: {
		data: {$filter},
	},

	grid: {
		createRowUrl: false,
		rowActions: {
		    delete: `{$deleteAction}`,
		}
	},
	onInit: () => {
	    app.events.on('object::deleted object::created object::updated', (e) => {
		container._dataView.reload();
	    });
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
	[ 'label' => 'MCP server' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <div class="text-muted small mb-3">
	MCP clients (Claude Code, Claude Desktop, AI steps, ...) reach this project's data tables, workflows, files and variables at
	<code><?= $url ?></code> with a token (<code>Authorization: Bearer &lt;token&gt;</code>). A token acts as the user who made it, within that user's permissions.
    </div>
    <div id="obj-list"></div>
</div>
