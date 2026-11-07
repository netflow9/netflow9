<?php
/*
    the installation's services (ServicesController::index):
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
    <?php require(dirname(__FILE__) . '/_table.php'); ?>
</div>
