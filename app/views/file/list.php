<?php

$this->pageTitle = 'Files';

$createBtn = '';
if (\V::app()->user->can('File::create')) {
    $createBtn = \V::app()->html->link(
	'Upload',
	\V::app()->createUrl('/file/upload'),
	[ 'id' => 'btn-upload', 'class' => 'btn btn-primary btn-sm', 'escape' => false ]
    );
    //CLD: 2026-10-06T21:33:37+03:00: allowed file types (was images only); also filters dropped files (app.FileUpload checks `accept`)
    $createBtn .= '<input id="file-input" type="file" hidden multiple accept="' . \models\File::acceptAttribute() . '">';
}

//schema and filtering
$sf = \util\WidgetsUtil::getSchema('File');
$schema = \util\JSON::encode($sf['schema']);
$filter = \util\JSON::encode($sf['filter']);


$script = "
+function(){
    const fileList = document.getElementById('files-list');

    new app.GridView(fileList, {
	create: '{$createBtn}',
	title: `<h5 class='h5 mb-0'>Files</h5>`,
	dataUrl: '/file/list?data=1',
	schema: {
	    readonly: true,
	    data: {$schema},
	},

	filter: {
	    data: {$filter},
	},
	grid: {
	    createRowUrl: false,
	    rowActions: {
		//CLD: 2026-10-06T21:29:27+03:00: view page (image preview, download) instead of the non-existing /file/update
		view: `<a href='/file/view/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' title='View'><i class='bi bi-eye'></i></a>`,
		delete: `<a href='/file/delete/{{item._id}}' class='btn btn-sm btn-icon d-inline px-1 py-0 mx-1 row-action-btn' data-confirm='Are you sure?' data-method='post'><i class='bi bi-trash'></i></a>`,
	    }
	},
	onInit: () => {
	    const fileInput = document.getElementById('file-input');
	    const btnUpload = document.getElementById('btn-upload');
	    var saveLabel = null;

	    const dropZoneMessage = document.getElementById('dropzone-message');

	    btnUpload.addEventListener('click', (e) => {
		e.preventDefault();
		fileInput._fileUpload.browse();
	    });

	    //refresh gv on file delete
	    app.events.on('object::deleted', (e) => {
		console.log('File manager received event:', e);
		fileList._dataView.reload();
	    })

	    new app.FileUpload(fileInput, {
		url: '/file/upload',
		dropzone: document.getElementById('dropzone'),
		formData: () => {
		    const csrfParam = $('meta[name=csrf-param]').attr('content');
		    const csrfToken = $('meta[name=csrf-token]').attr('content');

		    if (!csrfParam.length || !csrfToken.length)
			return {};

		    var resp = {};
		    resp[csrfParam] = csrfToken;
		    return resp;
		},

		onDragEnter: (e) => {
		    fileList.classList.add('d-none');
		    dropZoneMessage.classList.remove('d-none');
		},
		onDragLeave: (e) => {
		    fileList.classList.remove('d-none');
		    dropZoneMessage.classList.add('d-none');
		},
		onDrop: (e) => {
		    fileList.classList.remove('d-none');
		    dropZoneMessage.classList.add('d-none');
		},
		onUploadProgress: (data) => {
		    btnUpload.textContent = data.nLoaded + '/' + data.nFiles + ' ' + Math.floor(data.pctCurrent) + '%';
		},
		onFileUploadError: (item) => {
    		    app.utils.toast({
    			type: 'error',
    			title: item.name,
    			body: item.error.message,
    		    });
		},
		onUploadComplete: () => {
		    btnUpload.disabled = false;
		    btnUpload.textContent = saveLabel;
		    btnUpload.style.width = null;
		    btnUpload.style.textAlign = null;
		    fileList._dataView.reload();
		},
		onUploadStart: () => {
		    saveLabel = btnUpload.textContent;
		    btnUpload.disabled = true;
		    btnUpload.style.width = '6rem'; //btnUpload.getBoundingClientRect().width + 'px';
		    btnUpload.style.textAlign = 'left';
		},
	    });
	}
    });
}();

";
\V::app()->cs->addPageScript($script);

?>
<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Files' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div id="dropzone" class="main-body p-4">
    <div id="files-list"></div>
    <div id="dropzone-message" class="text-center border-info border-2 rounded h-100 d-none" style="border-style: dashed">
	<div class="position-relative" style="top:50%;left:50%;transform:translate(-50%);">Drop files to upload</div>
    </div>
</div>

