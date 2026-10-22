<!-- header -->
<div class="d-flex align-items-center w-100 align-items-top">
    <h5 class="mb-0"><?= $project->schema['icon']->display() . ' ' . $project->schema['name']->display() ?></h5>
    <div class="flex-fill"></div>
</div>

<?php
$navCfg = [
    'items' => [
	'general' => [
	    'label' => 'Settings',
	    'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'general' ]),
	],
	'members' => [
	    'label' => 'Members',
	    'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'members' ]),
	],
    ],
    'active' => $tab,
];

echo (new \sys\html\Nav($navCfg))->render();
