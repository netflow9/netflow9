<?php
//CLD: 2026-10-31T01:00:00+02:00: new: the project's knowledge bases (KnowledgeController::list; app.GridView, as the data tables' list); a note when the account has no embedding model

$this->pageTitle = 'Knowledge bases';

$proto = \V::app()->om->create('KnowledgeBase', [ 'aid' => \V::app()->var('rt.account')->_id, 'project' => \V::app()->om->to_oid(\V::app()->user->crtProject) ]);
$createBtn = '';
if (\V::app()->user->can('KnowledgeBase::create', [ 'object' => $proto ]) && $settings->ready())
    $createBtn = \V::app()->html->link('Create', \V::app()->createUrl('/knowledge/create'), [ 'class' => 'btn btn-primary btn-sm', 'escape' => false ]);

$dataUrl = \V::app()->createUrl('', [ 'data' => 1 ]);

$script = "
+function(){
    const container = document.getElementById('knowledge-list');
    new app.GridView(container, {
	filter: false,
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Knowledge bases</h5>`,
	dataUrl: '{$dataUrl}',

	schema: {
	    readonly: true,
	    updateColumnUrl: '',
	    createColumnUrl: '',
	    data: {
		name: { label: 'Name', type: 'text', filter: true },
		documents: { label: 'Documents', type: 'number', filter: false },
		chunks: { label: 'Chunks', type: 'number', filter: false },
		model: { label: 'Embedding model', type: 'text', filter: false },
		state: { label: 'State', type: 'text', filter: false },
		created_at: { label: 'Created', type: 'datetime', filter: true },
	    },
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		manage: `<a href='/knowledge/manage/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Documents and search'><i class='bi bi-journal-bookmark'></i></a>`,
		update: `<a href='/knowledge/update/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='Settings'><i class='bi bi-pencil'></i></a>`,
		delete: `<a href='/knowledge/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' data-confirm='Delete the knowledge base and its documents?' data-method='post' title='Delete'><i class='bi bi-trash'></i></a>`,
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
?>
<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Knowledge bases' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<?php if (!$settings->ready()): ?>
<div class="px-4 pt-3">
    <div class="alert alert-warning mb-0 small">
	<i class="bi bi-exclamation-triangle me-1"></i>No embedding model is set for the account: knowledge bases cannot be created or searched yet.
	<?php if (\V::app()->user->can('Account::update')): ?>
	<a href="<?= \V::app()->createUrl('/knowledge/settings') ?>">Set it in Settings: Knowledge bases</a>.
	<?php else: ?>
	An owner of the account sets it (Settings: Knowledge bases).
	<?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div id="knowledge-list" class="main-body p-4"></div>
