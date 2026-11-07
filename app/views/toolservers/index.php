<?php
/*
    the installation's tool servers, the MultiTenant plugin's super-admins' page (ToolserversController::index): $servers, $open (_table.php)
*/
$this->pageTitle = 'Tool servers';
$enabled = null;
?>

<div class="main-header py-2 px-3">
    <?= (new \sys\html\BtnExpandSidebar())->render() ?>
    <?= (new \sys\html\Breadcrumbs([ 'items' => [ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Tool servers' ] ] ]))->render() ?>
</div>

<div class="main-body p-4">
    <?php require(dirname(__FILE__) . '/_table.php'); ?>
</div>
