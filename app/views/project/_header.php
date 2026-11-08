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
    //CLD: 2026-11-08T12:00:00+02:00: the tabs on the header's line, after the project's icon and name (no top padding, a gap from the name)
    'navWrapOptions' => [ 'class' => 'nav nav-underline ms-4' ],
];
//CLD: 2026-10-28T10:00:00+02:00: + Export / import (Project::transfer)
if (\V::app()->user->can('Project::transfer', [ 'object' => $project ]))
    $navCfg['items']['transfer'] = [
	'label' => 'Export / import',
	'href' => \V::app()->createUrl('/project/settings', [ 'id' => $project->_id, '__tab' => 'transfer' ]),
    ];
?>
<!-- header: the project's icon and name, then the tabs -->
<div class="d-flex align-items-center w-100">
    <h5 class="mb-0"><?= $project->schema['icon']->display() . ' ' . $project->schema['name']->display() ?></h5>
    <?= (new \sys\html\Nav($navCfg))->render() ?>
    <div class="flex-fill"></div>
</div>
