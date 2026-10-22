<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the administration pages' header: breadcrumbs, the tabs ($tab: installation | accounts), the message of the last action (AdminController::flash)
*/
?>
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
    <?php if (!empty($_SESSION['admin_flash'])):
	$f = $_SESSION['admin_flash'];
	unset($_SESSION['admin_flash']); ?>
    <div class="alert alert-<?= \V::app()->html->escape($f['type']) ?> mt-3 mb-0 py-2 small" role="alert"><?= \V::app()->html->escape($f['message']) ?></div>
    <?php endif; ?>
</div>
