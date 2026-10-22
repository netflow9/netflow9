<?php
$this->pageTitle = 'User list';

$createBtn = '';
if (\V::app()->user->can('Role::create')) {
    $createBtn = \V::app()->html->link(
	'Create',
	\V::app()->createUrl('/user/create'),
	[ 'class' => 'btn btn-primary btn-sm', 'escape' => false ]
    );
}

//schema and filtering
//CLD: 2026-10-22T00:28:13+03:00: the users' schema (was Variable's, a copy-paste; the Variable object is removed)
$sf = \util\WidgetsUtil::getSchema('User');
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$schema = \util\JSON::encode($sf['schema']);
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$filter = \util\JSON::encode($sf['filter']);

//CLD: 2026-10-19T12:05:32+03:00: app.formatter.timeAgo (was app.timeAgo.inWords)
$script = "
+function(){
    new app.ListView(document.getElementById('users-list'), {
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Users</h5>`,
	itemRender: `
	<li class='list-group-item d-flex justify-content-between align-items-center bg-white'>
	    <div class='col-5'>
		<a href='/user/update/{{item._id}}' class='text-decoration-none'>
    		    <h6 class='mb-0'>{{item.firstname}} {{item.lastname}}</h6>
    		    <small class='text-muted'>{{app.formatter.timeAgo(item.updated_at.length == 0 ? item.created_at : item.updated_at)}}</small>
		</a>
	    </div>
	    <div class='col-2'>
		<h6>{{item.role}}</h6>
	    </div>
	    <div class='col'>
		<div class='dropdown float-end'>
		    <button class='btn btn-icon' data-bs-toggle='dropdown' aria-expanded='false'>
			<i class='bi bi-three-dots-vertical'></i>
		    </button>
		    <ul class='dropdown-menu dropdown-menu-end'>
			<li><a class='dropdown-item' href='#'>Mark as read</a></li>
			<li><a class='dropdown-item' href='#'>Archive</a></li>
			<li><a class='dropdown-item text-danger' href='#'>Delete</a></li>
		    </ul>
		</div>
	    </div>
	</li>
	`,
	dataUrl: '/user/list?data=1',
	schema: {
	    readonly: true,
	    //updateColumnUrl: '',
	    //createColumnUrl: '',
	    data: {$schema},
	},

	filter: {
	    data: {$filter},
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
	[ 'label' => 'Users' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div id="users-list" class="main-body p-4"></div>
