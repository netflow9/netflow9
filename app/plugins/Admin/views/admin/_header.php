<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();
    $bc = new \sys\html\Breadcrumbs([ 'items' => array_merge([ [ 'label' => 'Home', 'href' => '/site/index' ], [ 'label' => 'Administration', 'href' => '/Admin/admin/index' ] ], $crumbs ?? []) ]);
    echo $bc->render();
    ?>
</div>
<div class="px-4 pt-3">
    <ul class="nav nav-underline">
	<li class="nav-item"><a class="nav-link <?= $tab === 'installation' ? 'active' : '' ?>" href="<?= \V::app()->createUrl('/Admin/admin/index') ?>"><i class="bi bi-hdd-rack me-1"></i>Installation</a></li>
	<li class="nav-item"><a class="nav-link <?= $tab === 'accounts' ? 'active' : '' ?>" href="<?= \V::app()->createUrl('/Admin/admin/accounts') ?>"><i class="bi bi-buildings me-1"></i>Accounts</a></li>
    </ul>
    <?php //CLD: 2026-10-27T21:47:05+02:00: no flash alert here: the controllers' messages are toasts (addToast) ?>
</div>
