<?php

//the app's scripts are registered by layouts/_scripts.php (shared with layouts/form.php)
require(dirname(__FILE__) . '/_scripts.php');

ob_start();
?>

<?php //the plugins' notices over the page (outside the content pjax replaces; e.g. the MultiTenant plugin's "viewing as" bar) ?>
<?php \V::app()->hooks->applyHook('layout:page-top'); ?>
<main class="app-shell">
    <!-- SIDEBAR -->
    <?php //the width kept for the user (User prefs sidebar_width, set by the resize handle: app.sidebar) ?>
    <?php $sidebarW = (int)(\V::app()->var('rt.prefs')['sidebar_width'] ?? 0); ?>
    <?php //collapsed: User prefs sidebar_collapsed; the width limits (data-min-width / data-max-width: data\Constants) for app.sidebar ?>
    <aside class="sidebar<?= empty(\V::app()->var('rt.prefs')['sidebar_collapsed']) ? '' : ' collapsed' ?>" data-min-width="<?= \data\Constants::SIDEBAR_MIN_W ?>" data-max-width="<?= \data\Constants::SIDEBAR_MAX_W ?>"<?= $sidebarW ? ' style="--sidebar-w: ' . min(max($sidebarW, \data\Constants::SIDEBAR_MIN_W), \data\Constants::SIDEBAR_MAX_W) . 'px" data-width="' . $sidebarW . '"' : '' ?>>
	<?php require(dirname(__FILE__) . '/main_aside.php') ?>
    </aside>
    <?php //behind the sidebar when it is a drawer (small screens, theme local/_app.scss); a click closes it (app.sidebar) ?>
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
