<?php

//CLD: 2026-10-13T18:25:21+03:00: the app's scripts are registered by layouts/_scripts.php (shared with layouts/form.php; were listed here)
require(dirname(__FILE__) . '/_scripts.php');

ob_start();
?>

<main class="app-shell">
    <!-- SIDEBAR -->
    <aside class="sidebar">
	<?php require(dirname(__FILE__) . '/main_aside.php') ?>
    </aside>
    <?php //CLD: 2026-10-19T22:19:24+03:00: behind the sidebar when it is a drawer (small screens, theme qsm/_app.scss); a click closes it (app.sidebar) ?>
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
