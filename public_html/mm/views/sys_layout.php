<html>
    <head>
        <title>Mongo Manager</title>
        <link type="text/css" href="assets/bootstrap.min.css" rel="stylesheet" />
        <script language="javascript" type="text/javascript" src="assets/bootstrap.min.js"></script>
        <script language="javascript" type="text/javascript" src="assets/popper.min.js"></script>
        <script language="javascript" type="text/javascript" src="assets/jquery.min.js"></script>
        <script language="javascript" type="text/javascript" src="assets/split.min.js"></script>

	<script language="javascript" src="assets/jquery.fancytree.ui-deps.js"></script>
	<script language="javascript" src="assets/jquery.fancytree.min.js"></script>
	<script language="javascript" src="assets/jquery.fancytree.glyph.js"></script>
	<script language="javascript" src="assets/jquery.fancytree.filter.js"></script>
	<script language="javascript" src="assets/jquery.typewatch.js"></script>
	<link rel="stylesheet" href="assets/fancytree-skin-awesome/ui.fancytree.css" media="all"/>
	<link rel="stylesheet" href="assets/bootstrap-icons.css" type="text/css" media="all"/>

	<script language="javascript" src="assets/ace/ace.js"></script>

	<!-- local css -->
        <style type="text/css">
	    a {
		text-decoration:none;
		color:#004499;
	    }

	    a:hover {
		color:blue
	    }

    	    /* tweaks */
	    ul.fancytree-container {
		background-color: unset !important;
	    }
	    span.fancytree-node:hover {
		background-color: rgba(0,0,0, .075);
		/*outline: 1px dotted black;*/
	    }
	    .nav a.nav-link.active {
		font-weight: bold;
	    }

	    textarea {
		line-height: 1 !important;
	    }

	    /* panes */
	    #left-pane {
		height: 100%;
		background-color: #eeefff;
		overflow:auto;
	    }
	    #right-pane {
		height:100%;
		overflow:auto;
		/*background-color:#fffeee;*/
	    }
	    /* split.js */
	    .gutter {
		background-color: #eee;
		background-repeat: no-repeat;
		background-position: 50%;
	    }
	    .gutter.gutter-vertical {
		background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAB4AAAAFAQMAAABo7865AAAABlBMVEVHcEzMzMzyAv2sAAAAAXRSTlMAQObYZgAAABBJREFUeF5jOAMEEAIEEFwAn3kMwcB6I2AAAAAASUVORK5CYII=');
	    }
	    .gutter.gutter-horizontal {
		background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAeCAYAAADkftS9AAAAIklEQVQoU2M4c+bMfxAGAgYYmwGrIIiDjrELjpo5aiZeMwF+yNnOs5KSvgAAAABJRU5ErkJggg==');
	    }

	    /* record */
	    .record-row {
		line-height: 1;
		max-height: 150px;
		height: 150px;
		overflow-y: hidden;
	    }
	    .record-row.expanded {
		max-height: unset;
		height: unset;
	    }

        </style>

    </head>
    <body>
	<?php if (!R::app()->user->isGuest()): ?>
	<header style="background-color: #ccc">
	    <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start p-1">

		<ul id="nav-bar" class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
		    <li class="nav-item">
			<a href="<?= R::app()->url('app.home') ?>" class="nav-link active">
			    <i class="bi bi-house"></i><span class="mx-1"><?= R::app()->server->name ?></span>
			</a>
		    </li>

		    <li class="nav-item">
			<a href="<?= R::app()->url('app.server_status') ?>" pjax class="nav-link">
			    <i class="bi bi-info-square"></i><span class="mx-1">Status</span>
			</a>
		    </li>
		    <li class="nav-item">
			<a href="<?= R::app()->url('app.db_command') ?>" pjax class="nav-link">
			    <i class="bi bi-terminal"></i><span class="mx-1">Command</span>
			</a>
		    </li>
		    <li class="nav-item">
			<a href="<?= R::app()->url('app.server_processlist') ?>" pjax class="nav-link">
			    <i class="bi bi-hdd-stack"></i><span class="mx-1">Process list</span>
			</a>
		    </li>

		    <li class="nav-item">
			<a href="<?= R::app()->url('app.user_list') ?>" pjax class="nav-link">
			    <i class="bi bi-people"></i><span class="mx-1">Users</span>
			</a>
		    </li>

		</ul>

		<div class="text-end px-2">
		    <?= R::app()->user->username() ?>
		    <a href="<?= R::app()->url('app.logout') ?>">Logout</a>
		    <!-- loading indicator -->
		    <span id="pjax-active"><i class="bi bi-circle"></i></span>
    		</div>
	    </div>
	</header>
	<div class="d-flex flex-row" style="height: calc(100vh - 3rem)">
	    <div id="left-pane" class="position-relative d-flex flex-column p-2" style="width:30%;">

		<div class="d-flex">
		    <input type="text" class="form-control form-control-sm search mx-1" placeholder="Search">
		    <button class="btn btn-light clear-search px-1" type="button"><i class="bi bi-x"></i></button>
		</div>

    		<!-- left bar -->
    		<div class="nav-tree" data-type="json" style="display:none">
    		    <?php
		    //build databases tree
		    $databases = [];

		    //add collection count
		    foreach (R::app()->server->listDatabases() as $dbName) {
			$databases[] = [
			    'folder' => true,
			    'expanded' => false,
			    'lazy' => true,
			    'lazyUrl' => R::app()->url('app.nav_collections', [ 'db' => $dbName ]),
			    'url' => R::app()->url('app.collection_list', [ 'db' => $dbName ]),
			    'name' => $dbName,
			    'title' => $dbName,
			    'icon' => 'bi bi-database',
			    'key' => $dbName,
			    'type' => 'db',
			];
		    }
		    $tree_data = [
			'title' => 'Databases',
			'expanded' => true,
			'type' => 'root',
			'icon' => 'bi bi-hdd',
			'url' => R::app()->url('app.db_list'),
			//'lazyUrl' => R::app()->url('main.navdbs'),
			'lazy' => true,
			'folder' => true,
			'key' => '--root-databases--',
			'children' => $databases,
		    ];
    		    echo json_encode([ $tree_data ])
    		    ?>
		</div>
	    </div>
	    <div id="right-pane" class="position-relative d-flex flex-column p-2" style="width:70%;">
		<?= $content ?>
	    </div>
	</div>

	<!-- toast container -->
	<div aria-live='polite' aria-atomic='true'>
    	    <div style='position: fixed; top: 4.5rem; right: 0.5rem; z-index: 1050' id='toasts-container'></div>
	</div>

	<?php else: ?>
	<?= $content ?>
	<?php endif; ?>

        <script language="javascript">
	    var config = {
		messagesUrl: '<?= R::app()->url('app.messages') ?>',
		collectionRenameUrl: '<?= R::app()->url('app.collection_rename') ?>',
		collectionDuplicateUrl: '<?= R::app()->url('app.collection_duplicate') ?>',
		collectionInfoUrl: '<?= R::app()->url('app.nav_collection') ?>',
		collectionDropUrl: '<?= R::app()->url('app.collection_drop') ?>',
		dbDropUrl: '<?= R::app()->url('app.db_drop') ?>',
		current: {
		    db: '<?= R::app()->request->getQueryParam('db') ?>',
		    collection: '<?= R::app()->request->getQueryParam('collection') ?>',
		},
	    };
        </script>
	<script language="javascript" src="assets/app.js"></script>
    </body>
</html>