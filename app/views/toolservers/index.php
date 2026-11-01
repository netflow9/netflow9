<?php
/*
    CLD: 2026-11-01T11:55:18+02:00: new: the installation's tool servers, the MultiTenant plugin's super-admins' page (ToolserversController::index): $servers, $open (_table.php)
*/
$this->pageTitle = 'Tool servers';
$enabled = null;
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();
    $bc = new \sys\html\Breadcrumbs([ 'items' => [ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Tool servers' ] ] ]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?php require(dirname(__FILE__) . '/_table.php'); ?>
</div>
