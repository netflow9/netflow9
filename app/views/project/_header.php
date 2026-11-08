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
//CLD: 2026-10-28T10:00:00+02:00: + Export / import (Project::transfer)
if (\V::app()->user->can('Project::transfer', [ 'object' => $project ]))
    $navCfg['items']['transfer'] = [
	'label' => 'Export / import',
	'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'transfer' ]),
    ];

echo (new \sys\html\Nav($navCfg))->render();
