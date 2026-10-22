<?php
$this->pageTitle = 'Project: ' . $project->name;
?>


<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	//CLD: 2026-10-05T20:42:44+03:00: Breadcrumbs prints labels as HTML; escape the user-editable name
	[ 'label' => $project->schema['icon']->display() . ' ' . \V::app()->html->escape((string)$project->name) ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">

    <?php
    //CLD: 2026-10-05T20:42:44+03:00: only known tabs (the value is used to build a require() path)
    $tab = \V::app()->request->query['__tab'] ?? 'general';
    if (!in_array($tab, [ 'general', 'members' ], true))
	$tab = 'general';
    require(dirname(__FILE__) . '/_header.php');
    require(dirname(__FILE__) . '/_' . $tab . '.php');
    ?>
</div>
