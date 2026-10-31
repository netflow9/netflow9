<?php
//load current project
$crtProject = \V::app()->om->findById('Project', \V::app()->user->crtProject);

//get other projects
$query = [
    'aid' => \V::app()->var('rt.account')->_id,
    '$or' => [
	[ 'owner' => \V::app()->user->_id ],
	[ 'members.' . \V::app()->user->_id => [ '$ne' => null ] ],
    ],
];
$projects = \V::app()->om->find('Project', $query);
$dd_items = [];
foreach($projects as $p) {
    if ($p->_id == $crtProject->_id)
	continue;
    $dd_items[] = \V::app()->html->link(
	$p->schema['icon']->display() . ' ' . $p->schema['name']->display(),
	\V::app()->createUrl('/site/switch_project', [ 'id' => $p->_id ]),
	[ 'no-pjax' => true, 'class' => 'dropdown-item', 'escape' => false ]
    );
}
$dd_items = implode('', $dd_items);

\V::app()->cs->addPageScript("app.sidebar.highlight();");

?>

<!-- header -->
<div class="sidebar-header p-2">
    <div>
	<a href="<?= \V::app()->createUrl('/' . \V::app()->config('default.controller')) ?>" no-pjax class="d-inline text-decoration-none py-1 pe-2">
	    <img src="/img/app-logo.svg" style="height:1rem">
	</a>
	<?php if (empty($dd_items)): ?>
	    <span class="py-1">
		<?= $crtProject->schema['icon']->display() . ' ' . $crtProject->schema['name']->display() ?>
	    </span>
	<?php else: ?>
	<div class="dropdown d-inline">
	    <a href="#" class="py-1 dropdown-toggle text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false">
		<?= $crtProject->schema['icon']->display() . ' ' . $crtProject->schema['name']->display() ?>
	    </a>
	    <div class="dropdown-menu">
		<?= $dd_items ?>
	    </div>
	</div>
	<?php endif; ?>
    </div>
    <div class="sidebar-header-actions">
	<!--
	<a href="#" class="btn-sidebar-search p-1 text-decoration-none" id="sidebarSearchBtn" data-bs-toggle="modal" data-bs-target="#searchModal" title="Search">
	    <i class="bi bi-search"></i>
	</a>
	-->

	<?php
	//current project
	if (\V::app()->user->can('Project::update', [ 'object' => $crtProject ])) {
	    echo \V::app()->html->link(
		'<i class="bi bi-gear"></i>',
		\V::app()->createUrl('/project/settings'),
		[ 'class' => 'p-1', 'escape' => false ]
	    );
	}
	?>

	<div class="dropdown">
	    <a href="#" class="p-1" id="newMenuBtn" data-bs-toggle="dropdown" aria-expanded="false" title="New">
		<i class="bi bi-plus-lg"></i>
	    </a>
	    <div class="dropdown-menu py-0 bg-white" aria-labelledby="newMenuBtn">
		<a class="dropdown-item px-2" href="/chat/create"><i class="bi bi-chat-dots"></i> New chat</a>
		<?php if (\V::app()->user->can('Project::create')): ?>
		<a class="dropdown-item px-2" href="/project/create" data-method="post"><i class="bi bi-folder2"></i> New project</a>
		<?php endif; ?>
	    </div>
	</div>

	<a href="#" class="btn-collapse-sidebar p-1" title="Collapse sidebar">
	    <i class="bi bi-layout-sidebar-inset"></i>
	</a>
    </div>
</div>

<!-- body -->
<div class="sidebar-body">
    <!-- PRIMARY VIEW -->
    <div class="sidebar-view sidebar-view-primary">

	<!-- top menu -->
	<div class="flex-shrink-0">
	    <nav class="nav flex-column d-flex min-height-0 overflow-hidden flex-fill">
		<?php
		echo \V::app()->html->link(
		    '<i class="bi bi-speedometer"></i> ' . \V::t('Overview'),
		    \V::app()->createUrl('/site/index'),
		    [ 'class' => 'nav-link ps-2', 'escape' => false ]
		);
		{
		    echo \V::app()->html->link(
			'<i class="bi bi-ui-checks"></i> Forms',
			'/form/list',
			[ 'class' => 'nav-link ps-2', 'escape' => false ]
		    );
		    $openTasks = count(\models\WfTask::openFor(\V::app()->user, $crtProject->aid, null));
		    echo \V::app()->html->link(
			\util\VString::insert('<i class="bi bi-person-check"></i> My tasks{:badge}', [
			    'badge' => $openTasks ? \util\VString::insert(' <span class="badge rounded-pill text-bg-primary ms-1">{:n}</span>', [ 'n' => $openTasks ]) : '',
			]),
			'/task/list',
			[ 'class' => 'nav-link ps-2', 'escape' => false ]
		    );
		}
		?>
	    </nav>
	</div>

	<!-- scrollable menu -->
	<div class="d-flex flex-column flex-fill min-height-0">
	    <ul class="nav overflow-y-auto min-height-0">
		<div class="nav-item w-100 px-2 py-1">
		    Project workspace
		</div>

		<?php
		//CLD: 2026-10-08T14:36:10+03:00: credentials enabled; checked on an unsaved credential of the current project (project scope = membership)
		if (\V::app()->user->can('Credential::list', [ 'object' => \V::app()->om->create('Credential', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ])) {
		    echo \V::app()->html->link(
			//CLD: 2026-10-22T00:28:13+03:00: + variables (credentials of type Variable)
			'<i class="bi bi-key"></i> Credentials & variables',
			\V::app()->createUrl('/credential/list'),
			[ 'class' => 'nav-link py-1 w-100', 'escape' => false ]
		    );
		}

		if (\V::app()->user->can('DataTable::list', [ 'owner' => $crtProject ])) {
			echo \V::app()->html->link(
			'<i class="bi bi-table"></i> Data Tables',
			\V::app()->createUrl('/datatable/list'),
			[ 'class' => 'nav-link py-1 w-100', 'escape' => false ]
		    );
		}

		//CLD: 2026-10-31T01:00:00+02:00: the knowledge bases (KnowledgeController)
		if (\V::app()->user->can('KnowledgeBase::list', [ 'object' => \V::app()->om->create('KnowledgeBase', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ])) {
		    echo \V::app()->html->link(
			'<i class="bi bi-journal-bookmark"></i> Knowledge bases',
			\V::app()->createUrl('/knowledge/list'),
			[ 'class' => 'nav-link py-1 w-100', 'escape' => false ]
		    );
		}

		if (\V::app()->user->can('File::list', [ 'object' => \V::app()->om->create('File', [ 'aid' => $crtProject->aid, 'metadata' => [ 'project' => $crtProject->_id ] ]) ])) {
		    echo \V::app()->html->link(
			'<i class="bi bi-files"></i> Files',
			\V::app()->createUrl('/file/list'),
			[ 'class' => 'nav-link py-1 w-100', 'escape' => false ]
		    );
		}

		//CLD: 2026-10-26T23:56:07+02:00: the MCP server link (its tokens; was the SysMCPTools plugin's), hidden for the moment (McpController::SHOW_MENU); /mcp and /token/list still work
		if (\controllers\McpController::SHOW_MENU && \V::app()->user->can('McpToken::list', [ 'object' => \V::app()->om->create('McpToken', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ])) {
		    echo \V::app()->html->link(
			'<i class="bi bi-plug"></i> MCP server',
			\V::app()->createUrl('/token/list'),
			[ 'class' => 'nav-link py-1 w-100', 'escape' => false ]
		    );
		}

		\V::app()->hooks->applyHook('layout:aside-project-menu', [ $crtProject ]);

		$wfCreateUrl = '';
		if (\V::app()->user->can('Workflow::create', [ 'object' => \V::app()->om->create('Workflow', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ])) {
		    $wfCreateUrl = \V::app()->html->link(
			'<i class="bi bi-plus-lg"></i>',
			\V::app()->createUrl('/workflow/create'),
			[ 'escape' => false, 'data-method' => 'post' ]
		    );
		}
		?>
		<?php
		$canReadWf = \V::app()->user->can('Workflow::read', [ 'object' => \V::app()->om->create('Workflow', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ]);
		if ($canReadWf || $wfCreateUrl !== ''): ?>
		<div class="nav-item w-100 ps-2 py-1 pe-2">
		    <span>Workflows</span>
		    <span class="float-end"><?= $wfCreateUrl ?></span>
		</div>
		<?php endif; ?>

		<?php
		$wfs = $canReadWf ? \V::app()->om->find('Workflow', [ 'project' => $crtProject->_id]) : [];
		foreach($wfs as $wf) {
		    $url = \V::app()->createUrl('/workflow/index', [ 'id' => $wf->_id ]);
		    $name = $wf->schema['icon']->display() . ' ' . $wf->schema['name']->display();
		    //published workflows (started by their triggers) get a green dot
		    $published = $wf->status === \models\Workflow::PUBLISHED;
		    if ($published)
			$name .= " <i class='bi bi-circle-fill text-success ms-1' style='font-size: .45rem; vertical-align: middle' title='Published'></i>";

		    //CLD: 2026-10-17T21:30:00+03:00: a menu (three dots) per workflow: Publish (the designer's Publish dialog: shown when not published, or the draft changed since), Unpublish
		    //(published), Delete; each with its permission (as the designer's status menu), with the designer's confirmations; none allowed: no menu
		    $items = '';
		    if (\V::app()->user->can('Workflow::publish', [ 'object' => $wf ])) {
			$active = $published ? $wf->publishedVersion() : null;
			if (!$published || !$active || !$wf->draftMatches($active))
			    $items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}"><i class="bi bi-broadcast me-2"></i>Publish&hellip;</a>', [
				'href' => \V::app()->createUrl('/workflow/publish', [ 'id' => (string)$wf->_id ]),
			    ]);
			if ($published)
			    $items .= \util\VString::insert('<a class="dropdown-item px-2" href="{:href}" data-method="post" data-confirm="Unpublish: its trigger stops starting runs?"><i class="bi bi-pause-circle me-2"></i>Unpublish</a>', [
				'href' => \V::app()->createUrl('/workflow/unpublish', [ 'id' => (string)$wf->_id ]),
			    ]);
		    }

		    if (\V::app()->user->can('Workflow::delete', [ 'object' => $wf ]))
			$items .= \util\VString::insert('<a class="dropdown-item text-danger px-2" href="{:href}" data-method="post" data-confirm="Delete this workflow with all its versions and executions? This cannot be undone."><i class="bi bi-trash me-2"></i>Delete</a>', [
			    'href' => \V::app()->createUrl('/workflow/delete', [ 'id' => (string)$wf->_id ]),
			]);
		    //the menu opens fixed (popper strategy), so the scrolling list does not clip it
		    $menu = $items === '' ? '' : "
			<div class='dropdown flex-shrink-0 sidebar-item-menu'>
			    <a href='#' class='px-2 py-1 d-block text-body-secondary' data-bs-toggle='dropdown' data-bs-popper-config='{\"strategy\":\"fixed\"}' aria-expanded='false' title='Actions'><i class='bi bi-three-dots-vertical small'></i></a>
			    <ul class='dropdown-menu dropdown-menu-end py-0 bg-white'>{$items}</ul>
			</div>";
		    echo "
		    <li class='nav-item w-100 d-flex align-items-center'>
			<a href='{$url}' class='nav-link py-1 flex-fill text-truncate'>{$name}</a>{$menu}
		    </li>
		    ";
		}
		?>
	    </ul>
	</div>

	<!-- two-level menu, pinned to the bottom of the sidebar -->
	<div class="sidebar-bottom-menu">
	    <div class="d-flex align-items-center justify-content-end px-2 pt-2 pb-1">
		<div class="btn-group btn-group-sm" role="group" aria-label="Theme">
		    <button type="button" class="btn btn-outline-secondary py-0" data-theme-value="light" title="Light" aria-pressed="false"><i class="bi bi-sun" aria-hidden="true"></i><span class="visually-hidden">Light</span></button>
		    <button type="button" class="btn btn-outline-secondary py-0" data-theme-value="dark" title="Dark" aria-pressed="false"><i class="bi bi-moon-stars" aria-hidden="true"></i><span class="visually-hidden">Dark</span></button>
		    <button type="button" class="btn btn-outline-secondary py-0" data-theme-value="auto" title="Auto (the system's)" aria-pressed="false"><i class="bi bi-circle-half" aria-hidden="true"></i><span class="visually-hidden">Auto</span></button>
		</div>
	    </div>
	    <nav class="nav flex-column">
		<?php //CLD: 2026-10-28T23:55:00+02:00: the documentation inside the app (DocsController; was https://www.netflow9.io, in a new tab) ?>
		<a href="<?= \V::app()->createUrl('/docs/index') ?>" class="nav-link px-2">
		    <i class="bi bi-book"></i> Documentation
		</a>
	    </nav>
	</div>

    </div> <!-- sidebar-primary-view -->

    <!-- SECONDARY VIEW -->
    <div class="sidebar-view sidebar-view-secondary d-none">
	<nav class="nav flex-column">
	    <a href="#" class="nav-link close-secondary-menu" style="border-bottom: var(--bs-border-width) solid var(--bs-border-color)">
		<i class="bi bi-arrow-left"></i> <b>Settings</b>
	    </a>
	    <a href="<?= \V::app()->createUrl('/user/profile') ?>" class="nav-link">
		<i class="bi bi-person-circle"></i> Personal
	    </a>
	    <?php if (\V::app()->auth->getUserRole()->getPermissionScope('User::read') === 'global'): ?>
	    <a href="<?= \V::app()->createUrl('/user/list') ?>" class="nav-link">
		<i class="bi bi-person-vcard"></i> Users
	    </a>
	    <?php endif; ?>
	    <?php if (\V::app()->user->can('Role::list')): ?>
	    <a href="<?= \V::app()->createUrl('/role/list') ?>" class="nav-link">
		<i class="bi bi-person-lines-fill"></i> Roles
	    </a>
	    <?php endif; ?>
	    <?php if (\V::app()->user->can('Account::update')): ?>
	    <a href="<?= \V::app()->createUrl('/account/settings') ?>" class="nav-link">
		<i class="bi bi-house-gear"></i> Account settings
	    </a>
	    <?php endif; ?>
	    <?php if (\V::app()->user->can('Account::usage')): ?>
	    <a href="<?= \V::app()->createUrl('/account/usage') ?>" class="nav-link">
		<i class="bi bi-speedometer2"></i> Usage
	    </a>
	    <?php endif; ?>
	    <?php //CLD: 2026-10-26T23:49:50+02:00: Assistant settings (was the Assistant plugin's, hook layout:settings-menu) ?>
	    <?php if (\V::app()->user->can('Account::update')): ?>
	    <a href="<?= \V::app()->createUrl('/assistant/settings') ?>" class="nav-link">
		<i class="bi bi-stars"></i> Assistant
	    </a>
	    <?php endif; ?>
	    <?php //CLD: 2026-10-31T01:00:00+02:00: the account's embedding model (the knowledge bases') ?>
	    <?php if (\V::app()->user->can('Account::update')): ?>
	    <a href="<?= \V::app()->createUrl('/knowledge/settings') ?>" class="nav-link">
		<i class="bi bi-journal-bookmark"></i> Knowledge bases
	    </a>
	    <?php endif; ?>
	    <?php \V::app()->hooks->applyHook('layout:settings-menu'); ?>
	    <?php
	    $aid = \V::app()->config('rt.account')->_id ?? null;
	    if (\V::app()->user->can('AuditLog::list', [ 'object' => \V::app()->om->create('AuditLog', [ 'aid' => $aid, 'project' => null ]) ]))
		echo "<a href='/audit/list?scope=system' class='nav-link'><i class='bi bi-journal-text'></i> System audit log</a>";
	    if (\V::app()->user->can('ExecutionLog::list', [ 'object' => \V::app()->om->create('ExecutionLog', [ 'aid' => $aid, 'project' => null ]) ]))
		echo "<a href='/audit/list?log=execution&scope=system' class='nav-link'><i class='bi bi-activity'></i> System execution log</a>";
	    ?>
	    <form method="post" action="<?= \V::app()->createUrl('/site/logout') ?>" no-pjax class="m-0">
		<input type="hidden" name="<?= \V::app()->csrf->csrf_key ?>" value="<?= \V::app()->html->escape(\V::app()->csrf->getToken(false)) ?>">
		<button type="submit" class="nav-link w-100 text-start border-0 bg-transparent">
		    <i class="bi bi-box-arrow-right"></i> Logout
		</button>
	    </form>
	</nav>
    </div>
</div>

<!-- footer -->
<a href="#" class="sidebar-footer open-secondary-menu text-decoration-none p-2">
    <div class="avatar avatar-md">
	<?= \V::app()->user->avatar('md') ?>
    </div>
    <div class="flex-grow-1 min-width-0">
	<div class="text-truncate"><?= \V::app()->html->escape((string)\V::app()->user->name) ?></div>
	<div class="text-muted small"><?= \V::app()->html->escape((string)\V::app()->om->findById('Role', \V::app()->user->role)->name) ?></div>
    </div>

    <div class="float-end mb-3">
	<i class="bi bi-chevron-up float-end"></i>
    </div>
</a>

<div class="sidebar-resize-handle"></div>

