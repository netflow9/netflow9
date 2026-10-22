<?php
//CLD: 2026-10-13T18:25:21+03:00: new layout: a page without the app's sidebar (a public form of a Form trigger: plugins\Forms\controllers\FormController), with the app's scripts
require(dirname(__FILE__) . '/_scripts.php');

ob_start();
?>
<main class="container py-4" style="max-width: 860px">
    <?= $content ?>
</main>
<?php
$content = ob_get_clean();
echo $this->renderFile('/layouts/_webpage', compact('content'));
