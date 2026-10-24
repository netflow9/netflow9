<?php

$this->pageTitle = 'Role::list';

$createBtn = '';
if (\V::app()->user->can('Role::create')) {
    $createBtn = \V::app()->html->link(
	'Create',
	\V::app()->createUrl('/role/create'),
	//CLD: 2026-10-13T21:12:34+03:00: a POST link (RoleController::create creates the role; was a GET to an action that did not exist)
	[ 'class' => 'btn btn-primary btn-sm', 'escape' => false, 'data-method' => 'post' ]
    );
}

//schema and filtering
$sf = \util\WidgetsUtil::getSchema('Role');
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$filter = \util\JSON::encode($sf['filter']);
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$schema = \util\JSON::encode($sf['schema']);

//CLD: 2026-10-19T12:05:32+03:00: app.formatter.timeAgo (was app.timeAgo.inWords)
//CLD: 2026-10-24T17:30:00+03:00: app.formatter.timeAgoHtml: the date and time in the account's format as a tooltip; from _iso (DataTrait: ISO 8601; the shown texts are in the account's format)
$script = "
+function(){
    new app.ListView(document.getElementById('roles-list'), {
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Roles</h5>`,
	itemRender: `
	    <li class='list-group-item d-flex justify-content-between align-items-center bg-white'>
		<a href='/role/update/{{item._id}}' class='text-decoration-none'>
    		    <h6 class='mb-0'>{{item.name}}</h6>
    		    <small class='text-muted'>{{app.formatter.timeAgoHtml((item._iso || {}).updated_at || (item._iso || {}).created_at)}}</small>
		</a>
	    </li>
	`,
	dataUrl: '/role/list?data=1',
	filter: {
	    data: {$filter},
	},
	schema: {
	    readonly: true,
	    data: {$schema},
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
	[ 'label' => 'Roles' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div id="roles-list" class="main-body p-4"></div>
