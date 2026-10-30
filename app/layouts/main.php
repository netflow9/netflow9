<?php

//CLD: 2026-10-13T18:25:21+03:00: the app's scripts are registered by layouts/_scripts.php (shared with layouts/form.php; were listed here)
require(dirname(__FILE__) . '/_scripts.php');

ob_start();
?>

<?php //CLD: 2026-10-22T23:00:00+03:00: the plugins' notices over the page (outside the content pjax replaces; e.g. the Admin plugin's "viewing as" bar) ?>
<?php \V::app()->hooks->applyHook('layout:page-top'); ?>
<main class="app-shell">
    <!-- SIDEBAR -->
    <?php //CLD: 2026-10-30T12:00:00+02:00: the width kept for the user (User sidebar_width, set by the resize handle: app.sidebar) ?>
    <?php $sidebarW = (int)(\V::app()->var('rt.user')->sidebar_width ?? 0); ?>
    <aside class="sidebar"<?= $sidebarW ? ' style="--sidebar-w: ' . min(max($sidebarW, \controllers\SiteController::SIDEBAR_MIN_W), \controllers\SiteController::SIDEBAR_MAX_W) . 'px" data-width="' . $sidebarW . '"' : '' ?>>
	<?php require(dirname(__FILE__) . '/main_aside.php') ?>
    </aside>
    <?php //CLD: 2026-10-19T22:19:24+03:00: behind the sidebar when it is a drawer (small screens, theme netflow9/_app.scss); a click closes it (app.sidebar) ?>
    <div class="sidebar-backdrop"></div>

    <!-- MAIN -->
    <div class="main-area">
	<?= $content ?>
    </div>
</main>

<!-- toasts -->
<div aria-live="polite" aria-atomic="true" class="position-relative">
    <div class="toast-container position-absolute bottom-0 end-0 p-3">
    </div>
</div>


<?php
$content = ob_get_clean();
echo $this->renderFile('/layouts/_webpage', compact('content'));
