<?php
/*
    CLD: 2026-10-31T01:00:00+02:00: new: one knowledge base (KnowledgeController::manage): its documents (status: indexed, pending - the daemon indexes it -, error), adding them
    (uploaded files, project files, a web page, a text: KnowledgeController::upload / add), removing them, a test search (KnowledgeController::search), re-index (when the account's
    embedding model is another), its settings and delete. The documents come from KnowledgeController::documents, polled every 3 s while some are pending or it is re-indexing
*/
$this->pageTitle = 'Knowledge base - ' . $kb->name;
$id = (string)$kb->_id;

$opts = \util\JSON::encode([
    'documents' => \V::app()->createUrl('/knowledge/documents', [ 'id' => $id ]),
    'add' => \V::app()->createUrl('/knowledge/add', [ 'id' => $id ]),
    'upload' => \V::app()->createUrl('/knowledge/upload', [ 'id' => $id ]),
    'search' => \V::app()->createUrl('/knowledge/search', [ 'id' => $id ]),
    'remove' => \V::app()->createUrl('/knowledge/remove', [ 'id' => $id ]),
    'fileView' => \V::app()->createUrl('/file/view'),
    'canUpdate' => $canUpdate,
]);

\V::app()->cs->addPageScript("+function(o) {" . <<<'JS'
    const root = document.getElementById('knowledge-manage');
    if (!root)
	return;
    const esc = (v) => app.utils.escapeHtml(String(v ?? ''));
    const csrf = () => $('meta[name=csrf-token]').attr('content');
    const tbody = root.querySelector('.kb-docs tbody');
    const stats = root.querySelector('.kb-stats');
    const notice = root.querySelector('.kb-notice');
    let timer = null;

    const sourceIcon = { file: 'bi-file-earmark-text', url: 'bi-globe', text: 'bi-card-text' };
    const status = (d) => {
	if (d.status === 'pending')
	    return '<span class="badge text-bg-secondary"><span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span>Indexing</span>';
	if (d.status === 'error')
	    return `<span class="badge text-bg-danger" title="${esc(d.error)}">Error</span><div class="small text-danger text-wrap">${esc(d.error)}</div>`;
	return '<span class="badge text-bg-success">Indexed</span>';
    };
    const titleOf = (d) => {
	const t = esc(d.title || d.key);
	if (d.source === 'file' && d.file)
	    return `<a href="${esc(o.fileView + '/' + d.file)}">${t}</a>`;
	if (d.source === 'url' && d.url)
	    return `<a href="${esc(d.url)}" target="_blank" rel="noopener noreferrer">${t}</a>`;
	return t;
    };

    const render = (r) => {
	const docs = r.documents || [];
	tbody.innerHTML = docs.length ? docs.map((d) => `<tr>
	    <td class="ps-3"><i class="bi ${sourceIcon[d.source] || 'bi-file-earmark'} me-1 text-muted"></i>${titleOf(d)}${d.source === 'text' && d.key !== d.title ? `<div class="small text-muted">${esc(d.key)}</div>` : ''}</td>
	    <td class="text-end">${d.chunks}</td>
	    <td>${status(d)}</td>
	    <td class="text-nowrap small">${d.updated_at ? app.formatter.timeAgoHtml(d.updated_at, false) : ''}</td>
	    <td class="text-end pe-3">${o.canUpdate ? `<a href="${esc(o.remove + (o.remove.includes('?') ? '&' : '?') + 'doc=' + encodeURIComponent(d.id))}" class="btn btn-sm btn-icon px-1 py-0" data-confirm="Remove this document from the knowledge base?" data-method="post" title="Remove"><i class="bi bi-trash"></i></a>` : ''}</td>
	</tr>`).join('') : '<tr><td colspan="5" class="text-muted small ps-3">No documents yet' + (o.canUpdate ? ': add files, a web page or a text above.' : '.') + '</td></tr>';

	const kb = r.kb || {};
	stats.innerHTML = `${kb.documents} document(s) &middot; ${kb.chunks} chunk(s)` + (kb.model ? ` &middot; <code>${esc(kb.model)}</code>` : '') + (kb.dims ? ` (${kb.dims} dimensions)` : '');
	let n = '';
	if (kb.state === 'reindexing')
	    n = '<div class="alert alert-info small py-2"><span class="spinner-border spinner-border-sm me-1"></span>Re-indexing: the chunks are embedded again with the account\'s model. Searches wait until it is done.</div>';
	else if (kb.needsReindex)
	    n = '<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>Indexed with another model than the account\'s: re-index it before it is searched or added to again.</div>';
	if (kb.state_message)
	    n += `<div class="alert alert-danger small py-2">${esc(kb.state_message)}</div>`;
	notice.innerHTML = n;
	root.querySelectorAll('.kb-reindex').forEach((b) => b.classList.toggle('d-none', !(kb.needsReindex || (kb.state !== 'reindexing' && kb.chunks > 0))));

	clearTimeout(timer);
	if (kb.state === 'reindexing' || docs.some((d) => d.status === 'pending'))
	    timer = setTimeout(load, 3000);
    };
    const load = () => $.getJSON(o.documents).done(render);

    //adding
    const say = (form, cls, html) => {
	const el = form.querySelector('.kb-add-status');
	el.className = 'kb-add-status small ' + cls;
	el.innerHTML = html;
    };
    const failed = (x) => esc((x.responseJSON && x.responseJSON.error) || 'Failed (' + (x.status || 'no answer') + ')');
    const post = (form, data) => {
	const btn = form.querySelector('[type=submit]');
	btn.disabled = true;
	say(form, 'text-muted', '<span class="spinner-border spinner-border-sm me-1"></span>Adding&hellip;');
	$.ajax({ url: o.add, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() }, data: data })
	    .done((r) => { say(form, 'text-success', `<i class="bi bi-check-circle me-1"></i>${(r.queued || []).length} queued: indexed in the background`); form.reset(); load(); })
	    .fail((x) => say(form, 'text-danger', failed(x)))
	    .always(() => { btn.disabled = false; });
    };
    root.querySelectorAll('form.kb-add').forEach((form) => form.addEventListener('submit', (e) => {
	e.preventDefault();
	const kind = form.dataset.kind;
	if (kind === 'files')
	    return post(form, { files: Array.from(form.querySelector('select').selectedOptions).map((op) => op.value) });
	if (kind === 'url')
	    return post(form, { url: form.querySelector('[name=url]').value });
	if (kind === 'text')
	    return post(form, { title: form.querySelector('[name=title]').value, key: form.querySelector('[name=key]').value, text: form.querySelector('[name=text]').value });
	//uploads, one at a time
	const input = form.querySelector('input[type=file]');
	const files = Array.from(input.files || []);
	if (!files.length)
	    return say(form, 'text-danger', 'Choose one or more files');
	const btn = form.querySelector('[type=submit]');
	btn.disabled = true;
	let done = 0;
	const errors = [];
	const next = () => {
	    if (!files.length) {
		btn.disabled = false;
		form.reset();
		say(form, errors.length ? 'text-danger' : 'text-success', (done ? `<i class="bi bi-check-circle me-1"></i>${done} uploaded and queued. ` : '') + errors.map(esc).join('<br>'));
		load();
		return;
	    }
	    const f = files.shift();
	    say(form, 'text-muted', `<span class="spinner-border spinner-border-sm me-1"></span>Uploading ${esc(f.name)}&hellip;`);
	    const fd = new FormData();
	    fd.append('file', f);
	    $.ajax({ url: o.upload, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', headers: { 'X-Csrf-Token': csrf() } })
		.done(() => { done++; })
		.fail((x) => errors.push(f.name + ': ' + ((x.responseJSON && x.responseJSON.error) || 'failed (' + x.status + ')')))
		.always(next);
	};
	next();
    }));

    //the test search
    const sform = root.querySelector('form.kb-search');
    const results = root.querySelector('.kb-results');
    sform.addEventListener('submit', (e) => {
	e.preventDefault();
	const btn = sform.querySelector('[type=submit]');
	btn.disabled = true;
	results.innerHTML = '<div class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Searching&hellip;</div>';
	$.ajax({ url: o.search, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() },
	    data: { query: sform.querySelector('[name=query]').value, results: sform.querySelector('[name=results]').value } })
	    .done((r) => {
		const list = r.results || [];
		results.innerHTML = `<div class="small text-muted mb-2">${list.length} result(s), ${r.ms} ms</div>` + list.map((x, i) => `<div class="border rounded p-2 mb-2">
		    <div class="d-flex align-items-center gap-2 small mb-1">
			<span class="badge text-bg-primary" title="Similarity (-1 to 1)">${Number(x.score).toFixed(3)}</span>
			<span class="badge text-bg-light border" title="Found by meaning, by words, or both">${esc(x.match)}</span>
			<span class="fw-semibold text-truncate">${esc(x.title || x.document)}${x.page ? ` &middot; page ${x.page}` : ''}</span>
		    </div>
		    <div class="small" style="white-space: pre-wrap; max-height: 12rem; overflow-y: auto">${esc(x.text)}</div>
		</div>`).join('');
	    })
	    .fail((x) => { results.innerHTML = `<div class="small text-danger">${failed(x)}</div>`; })
	    .always(() => { btn.disabled = false; });
    });

    app.events.on('object::deleted object::updated', () => load());
    load();
JS . "}({$opts});");
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Knowledge bases', 'href' => '/knowledge/list' ],
	[ 'label' => (string)$kb->name ],
    ]]);
    echo $bc->render();
    ?>
    <div class="spacer"></div>
    <?php if ($canUpdate): ?>
    <a href="<?= \V::app()->createUrl('/knowledge/reindex', [ 'id' => $id ]) ?>" class="btn btn-sm btn-outline-warning py-0 me-1 kb-reindex d-none" data-confirm="Embed every chunk again with the account's model?" data-method="post" title="Embed every chunk again with the account's embedding model"><i class="bi bi-arrow-repeat me-1"></i><span class="btn-label">Re-index</span></a>
    <a href="<?= \V::app()->createUrl('/knowledge/update', [ 'id' => $id ]) ?>" class="btn btn-sm btn-outline-primary py-0 me-1" title="Name, description, chunk size"><i class="bi bi-pencil me-1"></i><span class="btn-label">Settings</span></a>
    <?php endif; ?>
    <?php if ($canDelete): ?>
    <a href="<?= \V::app()->createUrl('/knowledge/delete', [ 'id' => $id, 'ret' => 1 ]) ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Delete the knowledge base and its documents?" data-method="post" title="Delete"><i class="bi bi-trash me-1"></i><span class="btn-label">Delete</span></a>
    <?php endif; ?>
</div>

<div id="knowledge-manage" class="main-body p-4">
    <div class="mb-3">
	<h5 class="mb-1"><i class="bi bi-journal-bookmark me-1"></i><?= \V::app()->html->escape($kb->name) ?></h5>
	<?php if (trim((string)$kb->description) !== ''): ?>
	<div class="text-muted small mb-1" style="white-space: pre-wrap"><?= \V::app()->html->escape($kb->description) ?></div>
	<?php endif; ?>
	<div class="small text-muted kb-stats"></div>
    </div>

    <?php if (!$settings->ready()): ?>
    <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>No embedding model is set for the account: documents cannot be added or searched.
	<?php if (\V::app()->user->can('Account::update')): ?><a href="<?= \V::app()->createUrl('/knowledge/settings') ?>">Settings: Knowledge bases</a><?php endif; ?></div>
    <?php endif; ?>
    <div class="kb-notice"></div>

    <div class="row g-4">
	<div class="col-lg-8">
	    <?php if ($canUpdate): ?>
	    <div class="card mb-3">
		<?php //CLD: 2026-10-31T01:30:00+02:00: nav-underline in a header without vertical padding, the underline on its border (as the Administration tabs; were card-header-tabs in a header without bottom padding and border: hanging below it, misaligned) ?>
		<div class="card-header py-0">
		    <ul class="nav nav-underline" role="tablist">
			<?php if ($canUpload): ?>
			<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#kb-add-upload" type="button"><i class="bi bi-upload me-1"></i>Upload files</button></li>
			<?php endif; ?>
			<li class="nav-item"><button class="nav-link<?= $canUpload ? '' : ' active' ?>" data-bs-toggle="tab" data-bs-target="#kb-add-files" type="button"><i class="bi bi-files me-1"></i>Project files</button></li>
			<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#kb-add-url" type="button"><i class="bi bi-globe me-1"></i>Web page</button></li>
			<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#kb-add-text" type="button"><i class="bi bi-card-text me-1"></i>Text</button></li>
		    </ul>
		</div>
		<div class="card-body tab-content">
		    <?php if ($canUpload): ?>
		    <form class="kb-add tab-pane fade show active" id="kb-add-upload" data-kind="upload" no-pjax>
			<div class="d-flex gap-2 align-items-start">
			    <input type="file" class="form-control form-control-sm" multiple accept=".pdf,.docx,.pptx,.xlsx,.odt,.ods,.odp,.txt,.md,.csv,.tsv,.html,.htm,.json,.xml,.yaml,.yml,.log">
			    <button type="submit" class="btn btn-sm btn-primary text-nowrap">Upload and add</button>
			</div>
			<div class="form-text">PDF, Office or text files; they are saved with the project's files too.</div>
			<div class="kb-add-status small"></div>
		    </form>
		    <?php endif; ?>
		    <form class="kb-add tab-pane fade<?= $canUpload ? '' : ' show active' ?>" id="kb-add-files" data-kind="files" no-pjax>
			<div class="d-flex gap-2 align-items-start">
			    <select class="form-select form-select-sm" multiple size="5">
				<?php foreach($files as $fid => $fname): ?>
				<option value="<?= \V::app()->html->escape($fid) ?>"><?= \V::app()->html->escape($fname) ?></option>
				<?php endforeach; ?>
			    </select>
			    <button type="submit" class="btn btn-sm btn-primary text-nowrap">Add</button>
			</div>
			<div class="form-text"><?= $files ? 'The project\'s PDF, Office and text files; Ctrl / Cmd + click chooses several.' : 'The project has no PDF, Office or text files.' ?></div>
			<div class="kb-add-status small"></div>
		    </form>
		    <form class="kb-add tab-pane fade" id="kb-add-url" data-kind="url" no-pjax>
			<div class="d-flex gap-2">
			    <input type="url" name="url" class="form-control form-control-sm" placeholder="https://www.example.com/help" required>
			    <button type="submit" class="btn btn-sm btn-primary text-nowrap">Add</button>
			</div>
			<div class="form-text">The page's text; added again later, it is replaced.</div>
			<div class="kb-add-status small"></div>
		    </form>
		    <form class="kb-add tab-pane fade" id="kb-add-text" data-kind="text" no-pjax>
			<div class="row g-2 mb-2">
			    <div class="col-8"><input type="text" name="title" class="form-control form-control-sm" placeholder="Title" required></div>
			    <div class="col-4"><input type="text" name="key" class="form-control form-control-sm" placeholder="Key (optional: the title)"></div>
			</div>
			<textarea name="text" class="form-control form-control-sm mb-2" rows="5" placeholder="The text" required></textarea>
			<button type="submit" class="btn btn-sm btn-primary">Add</button>
			<div class="kb-add-status small"></div>
		    </form>
		</div>
	    </div>
	    <?php endif; ?>

	    <div class="card">
		<div class="card-header"><b>Documents</b></div>
		<div class="table-responsive">
		    <?php //CLD: 2026-10-31T01:45:00+02:00: the first and last columns indented as the card's header (ps-3 / pe-3; were at the card's edges) ?>
		    <table class="table table-sm table-hover mb-0 align-middle kb-docs">
			<thead><tr><th class="ps-3">Document</th><th class="text-end">Chunks</th><th>Status</th><th>Updated</th><th class="pe-3"></th></tr></thead>
			<tbody><tr><td colspan="5" class="small text-muted ps-3"><span class="spinner-border spinner-border-sm me-1"></span>Loading&hellip;</td></tr></tbody>
		    </table>
		</div>
	    </div>
	</div>

	<div class="col-lg-4">
	    <div class="card">
		<div class="card-header"><b>Try a search</b></div>
		<div class="card-body">
		    <form class="kb-search mb-3" no-pjax>
			<div class="input-group input-group-sm">
			    <input type="text" name="query" class="form-control" placeholder="A question or keywords" required>
			    <select name="results" class="form-select" style="max-width: 5rem" title="Results">
				<option value="3">3</option><option value="5" selected>5</option><option value="10">10</option>
			    </select>
			    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
			</div>
			<div class="form-text">What the workflows and the AI steps find: by meaning and by words.</div>
		    </form>
		    <div class="kb-results"></div>
		</div>
	    </div>
	</div>
    </div>
</div>
