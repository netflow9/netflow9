<?php
/*
    CLD: 2026-10-31T11:05:39+02:00: new (was the Services section of the Admin plugin's installation page): the installation's services (ServicesController::index):
    $services, each with its `status` (util\Services::status); start / stop / restart (ServicesController::control)
*/
$this->pageTitle = 'Services';
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();
    $bc = new \sys\html\Breadcrumbs([ 'items' => [ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Services' ] ] ]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?php //CLD: 2026-10-31T19:30:00+02:00: the table: _table.php (the account settings' tab too) ?>
    <?php require(dirname(__FILE__) . '/_table.php'); ?>
</div>
