<?php
//CLD: 2026-10-06T21:29:27+03:00: new view: file page - details, image preview (images only), download

$this->pageTitle = 'File: ' . $file->filename;

$name = \V::app()->html->escape($file->filename);
$contentUrl = \V::app()->createUrl('/file/content', [ 'id' => $file->_id ]);
$downloadUrl = \V::app()->createUrl('/file/content', [ 'id' => $file->_id, 'download' => 1 ]);

$extension = $file->extension();

switch($extension) {
    case 'pdf':
	\V::app()->cs->addPageScript("new app.PdfViewer('#preview', { url: '{$contentUrl}' });");
	break;
    case 'docx':
	\V::app()->cs->addPageScript("new app.DocxViewer('#preview', { url: '{$contentUrl}' });");
	break;
    case 'xlsx';
	\V::app()->cs->addPageScript("new app.XlsxViewer('#preview', { url: '{$contentUrl}' });");
	break;
}
?>
<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Files', 'href' => '/file/list' ],
	[ 'label' => $name ],
    ]]);
    echo $bc->render();
    ?>
    <div class="spacer"></div>
    <div>
	<!-- no-pjax: a download is not a page -->
	<?php //CLD: 2026-10-19T22:19:24+03:00: the text in .btn-label (only the icon on phones) ?>
	<a href="<?= $downloadUrl ?>" class="btn btn-sm btn-primary" no-pjax><i class="bi bi-download me-1"></i><span class="btn-label">Download</span></a>
    </div>
</div>

<div class="main-body p-4">
    <div class="row">
	<div class="col-lg-9">
	    <?php
		switch($extension) {
		    case 'png': case 'jpg': case 'jpeg': case 'gif': case 'webp':
			echo "
			<div class='border rounded p-2 text-center bg-body-tertiary'>
			    <img src='{$contentUrl}' alt='{$name}' class='img-fluid' style='max-height: 75vh'>
			</div>
			";
			break;
		    case 'pdf': case 'docx': case 'xlsx':
			echo "<div id='preview'></div>";
			break;
		    default:
			echo "
			<div class='border rounded p-5 text-center text-muted'>
			    <i class='bi bi-file-earmark fs-1 d-block mb-2'></i>
			    No preview for this file type.
			</div>
			";
		}
	    ?>
	</div>

	<div class="col-lg-3">
	    <dl class="mb-0">
		<dt>Name</dt>
		<dd class="text-break"><?= $name ?></dd>
		<dt>Size</dt>
		<dd><?= $file->schema['length']->display() ?></dd>
		<dt>Type</dt>
		<dd><?= \V::app()->html->escape((string)$file->type) ?></dd>
		<dt>Owner</dt>
		<dd><?= $file->schema['owner']->display() ?></dd>
		<dt>Uploaded</dt>
		<dd class="mb-0"><?= $file->schema['created_at']->display() ?></dd>
	    </dl>
	</div>
    </div>
</div>
