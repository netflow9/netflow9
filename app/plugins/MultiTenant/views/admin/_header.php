<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();
    $bc = new \sys\html\Breadcrumbs([ 'items' => array_merge([ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Administration', 'href' => '/MultiTenant/admin/index' ] ], $crumbs ?? []) ]);
    echo $bc->render();
    ?>
</div>
<div class="px-4 pt-3">
    <ul class="nav nav-underline">
	<li class="nav-item"><a class="nav-link <?= $tab === 'installation' ? 'active' : '' ?>" href="<?= \V::app()->createUrl('/MultiTenant/admin/index') ?>"><i class="bi bi-hdd-rack me-1"></i>Installation</a></li>
	<li class="nav-item"><a class="nav-link <?= $tab === 'accounts' ? 'active' : '' ?>" href="<?= \V::app()->createUrl('/MultiTenant/admin/accounts') ?>"><i class="bi bi-buildings me-1"></i>Accounts</a></li>
	<?php //CLD: 2026-10-31T11:05:39+02:00: the services: the core's page (controllers\ServicesController) ?>
	<li class="nav-item"><a class="nav-link" href="<?= \V::app()->createUrl('/services/index') ?>"><i class="bi bi-gear-wide-connected me-1"></i>Services</a></li>
    </ul>
    <?php //CLD: 2026-10-27T21:47:05+02:00: no flash alert here: the controllers' messages are toasts (addToast) ?>
</div>
