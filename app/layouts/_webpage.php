<?php
    $icon = base64_encode(file_get_contents(BASE . '/public_html/img/app-logo-512.png'));
?>

<!DOCTYPE html>
<?php //CLD: 2026-10-24T17:30:00+03:00: the account's date formats (util\Format::dateFormat / datetimeFormat), for app.formatter.date / datetime / timeAgoHtml ?>
<html lang="<?= \V::app()->config('app.language') ?>" data-date-format="<?= \V::app()->html->escape(\util\Format::dateFormat()) ?>" data-datetime-format="<?= \V::app()->html->escape(\util\Format::datetimeFormat()) ?>">
    <head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

	<title><?= \V::app()->html->escape((string)$this->pageTitle) ?></title>
	<meta property="og:title" content="<?= \V::app()->html->escape((string)$this->pageTitle) ?>">
	<meta name="author" content="<?= \V::app()->config('app.author') ?>">
	<meta property="og:locale" content="<?= \V::app()->config('app.language') ?>">

	<meta name="csrf-param" content="<?= \V::app()->csrf->getKey() ?>">
	<meta name="csrf-token" content="<?= \V::app()->csrf->getToken() ?>">
	
	<?php //CLD: 2026-10-24T11:51:46+03:00: light / dark / auto (the system's), chosen per browser (localStorage netflow9.theme: app.theme in app.js; default app.color-scheme), set on <html> before
	//the styles load (no flash of the other theme) ?>
	<meta name="color-scheme" content="light dark">
	<script>
	(function() {
	    var choice = <?= \util\JSON::encode(in_array(\V::app()->config('app.color-scheme'), [ 'light', 'dark', 'auto' ], true) ? \V::app()->config('app.color-scheme') : 'auto') ?>;
	    try {
		var stored = localStorage.getItem('netflow9.theme');
		if (stored === 'light' || stored === 'dark' || stored === 'auto')
		    choice = stored;
	    } catch (e) {}
	    var dark = choice === 'dark' || (choice === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
	    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
	    document.documentElement.setAttribute('data-theme-choice', choice);
	})();
	</script>

	<link href="data:image/png;base64,<?= $icon ?>" rel="icon" type="image/png" />

	<?php //CLD: 2026-10-24T11:51:46+03:00: + its modification time (a new build is loaded at once) ?>
	<link rel="stylesheet" href="/css/theme.css?<?= @filemtime(BASE . '/public_html/css/theme.css') ?>">
	<link rel="stylesheet" href="/css/bootstrap-icons.css">

	<?= \V::app()->cs->renderPageCssFiles(); ?>
	<?= \V::app()->cs->renderPageCss(); ?>
    </head>

    <body>
	<?= $content ?>

	<script src="/js/vendor/popper-2.11.8/popper-2.11.8.min.js"></script>
	<script src="/js/vendor/jquery-4.0.0/jquery-4.0.0.min.js"></script>
	<?php //CLD: 2026-10-09T12:21:48+03:00: Bootstrap from js/vendor (like popper and jquery); /js/bootstrap-5.3.8.min.js was moved there and returned 404, so dropdowns etc. did not work ?>
	<script src="/js/vendor/bootstrap-5.3.8/bootstrap-5.3.8.min.js"></script>
	<?php //CLD: 2026-10-13T16:48:57+03:00: the previewers' libraries (were added by app.xlsxviewer.js / app.docxviewer.js when first used): SheetJS -> window.XLSX; JSZip -> window.JSZip, before docx-preview -> window.docx (it looks for JSZip) ?>
	<script src="/js/vendor/sheetjs/xlsx.full.min.js"></script>
	<script src="/js/vendor/jszip/jszip.min.js"></script>
	<script src="/js/vendor/docx-preview/docx-preview.min.js"></script>
	<?php //CLD: 2026-10-14T21:41:02+03:00: Chart.js 4.5.1 (UMD) -> window.Chart: the charts of the project overview ?>
	<script src="/js/vendor/chartjs-4.5.1/chart.umd.min.js"></script>
	<?php //CLD: 2026-10-13T16:34:15+03:00: the ES module libraries (were imported by the components when first used): pdf.js -> window.pdfjsLib (app.pdfviewer.js), CodeMirror 6 -> window.CodeMirror6 (app.codeeditor.js; built by frontend/standalone-packages/CodeMirror6/netflow9_build.sh) ?>
	<script type="module" src="/js/vendor/pdfjs/pdf.mjs"></script>
	<script type="module" src="/js/vendor/codemirror-6/codemirror.mjs"></script>

	<?= \V::app()->cs->renderPageScriptFiles() ?>
	<?= \V::app()->cs->renderPageScripts() ?>
    </body>
</html>