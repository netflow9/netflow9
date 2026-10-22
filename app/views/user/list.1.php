<?php
$this->pageTitle = 'User list';

$account = \V::app()->config('rt.account');
$wDef = [
    'objectName' => 'User',
    'dataProvider' => \V::app()->om->find('User', [ 'aid' => $account->_id ]),

    'layout' => "
    <div class='p-4'>
	<div class='d-flex gap-2'>
	    <h5>User list</h5>
	    <div class='flex-fill'></div>
	    {search}{sorter}{filter}{create}
	</div>
	<ul class='mt-2 list-group'>{items}</ul>
	<div class='d-flex align-items-center w-100 mt-2 flex-shrink-0'>
	    <div class='flex-fill'></div>
	    {pager}
	</div>
    </div>
    ",

    'create' => function() {
	if (\V::app()->user->can('User::create')) {
	    $url = \V::app()->createUrl('/user/create');
	    return "<a href='{$url}' class='btn btn-primary btn-sm'>Create</a>";
	}
	return '';
    },

    'itemRender' => function($key, $item, $widget) {
	$tpl = '
	<li class="list-group-item row bg-white d-flex">
	    <div class="col-5">
		<a href="{:url}" class="text-decoration-none">
    		    <h6 class="mb-0">{:firstname} {:lastname}</h6>
    		    <small class="text-muted">{:times}</small>
		</a>
	    </div>
	    <div class="col-2">
		<h6>{:role}</h6>
	    </div>
	    <div class="col">
		<div class="dropdown float-end">
		    <button class="btn btn-icon" data-bs-toggle="dropdown" aria-expanded="false">
			<i class="bi bi-three-dots-vertical"></i>
		    </button>
		    <ul class="dropdown-menu dropdown-menu-end">
			<li><a class="dropdown-item" href="#">Mark as read</a></li>
			<li><a class="dropdown-item" href="#">Archive</a></li>
			<li><a class="dropdown-item text-danger" href="#">Delete</a></li>
		    </ul>
		</div>
	    </div>
	</li>
	';

	return \util\VString::insert($tpl, [
	    'url' => \V::app()->createUrl('/user/update', [ 'id' => $item->_id ]),
	    'firstname' => $item->firstname,
	    'lastname' => $item->lastname,
	    //CLD: 2026-10-19T12:05:32+03:00: the dates (util\TimeAgo is removed: times in words are app.formatter.timeAgo in the browser)
	    'times' => implode(', ', array_map(fn($t) => $t ? $t->toDateTime()->setTimezone(new \DateTimeZone(\V::app()->config('app.timezone')))->format('Y-m-d H:i') : '',
		[ $item->created_at, $item->updated_at ])),
	    'role' => (string)$item->deref('role'),
	]);
    },
];

echo (new \sys\widgets\ListView($wDef))->render();
