/*
 * CLD: 2026-10-16T12:00:26+03:00: new component: the email designer (user's design: simple, block based, one column) - edits an email made of blocks, as models\EmailBlocks
 * stores it ({ preheader, blocks: [ { type: html, html } | { type: image, file, alt, width, align, href } ] }); util\EmailHtml turns it into what is sent.
 *
 *   const d = new app.EmailDesigner(el, { value, readonly, onChange: (value) => ..., expression: { url, params } });
 *
 * The canvas shows the email as it will look (a white column of 600 px on gray, the preview text above): text blocks are edited in place (a rich text toolbar:
 * bold / italic / underline / strike, paragraph / headings, lists, alignment, color, link, clear; "Insert variable" lists the variables of the step through the
 * expression help of the step's settings - option `expression`, as app.ExprInput); pasted text comes in as plain text. Image blocks show their image; the right panel
 * holds the selected block's settings: the image (one of the project's images, or a new upload: app.FileUpload, /file/upload), alt text, width, alignment, link.
 * Blocks are added (end, or between two: the + line), moved up / down, removed. As app.FormDesigner: option `fill` (the designer fills its container's height),
 * setStepPanel(el) (the step's other settings in the right panel when no block is selected), select(id), getValue(), setValue(v), problem().
 */
window.app.EmailDesigner = (function() {

    const WIDTH = 600;
    //email-safe image types (Outlook shows no WebP)
    const IMAGE = /\.(png|jpe?g|gif)$/i;
    const FONT = 'font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5; color: #222222;';
    const esc = (s) => window.app.utils.escapeHtml(String(s === undefined || s === null ? '' : s));
    let seq = 0;
    const nid = () => 'ed' + (++seq);

    class EmailDesigner {
	constructor(container, options) {
	    this.container = container;
	    this.o = Object.assign({ value: null, readonly: false, onChange: null, expression: null, fill: false }, options || {});
	    this.selected = null;
	    this.stepPanel = null;
	    this.images = null;
	    this.setValue(this.o.value);
	}

	setValue(value) {
	    const v = value && typeof value === 'object' ? value : {};
	    this.preheader = String(v.preheader || '');
	    this.blocks = (Array.isArray(v.blocks) ? v.blocks : []).map((b) => Object.assign({}, b, { _id: nid() }));
	    this.emitted = null;
	    this.render();
	}

	getValue() {
	    if (!this.emitted)
		this.emitted = { preheader: this.preheader, blocks: this.blocks.map((b) => { const c = Object.assign({}, b); delete c._id; return c; }) };
	    return this.emitted;
	}

	changed() {
	    this.emitted = null;
	    if (this.o.onChange)
		this.o.onChange(this.getValue());
	}

	problem() {
	    if (!this.blocks.length)
		return 'Add at least one block to the email.';
	    const i = this.blocks.findIndex((b) => b.type === 'image' && !b.file);
	    return i >= 0 ? `Block ${i + 1}: choose an image.` : '';
	}

	setStepPanel(el) {
	    this.stepPanel = el;
	    this.render();
	}

	select(id) {
	    this.selected = id;
	    this.render();
	}

	find(id) {
	    return this.blocks.findIndex((b) => b._id === id);
	}

	add(type, at) {
	    const b = type === 'image' ? { type: 'image', file: '', alt: '', width: WIDTH, align: 'center', href: '', _id: nid() } : { type: 'html', html: '<p>Text</p>', _id: nid() };
	    this.blocks.splice(at === undefined ? this.blocks.length : at, 0, b);
	    this.selected = b._id;
	    this.changed();
	    this.render();
	}

	move(id, by) {
	    const i = this.find(id), j = i + by;
	    if (i < 0 || j < 0 || j >= this.blocks.length)
		return;
	    [ this.blocks[i], this.blocks[j] ] = [ this.blocks[j], this.blocks[i] ];
	    this.changed();
	    this.render();
	}

	remove(id) {
	    this.blocks.splice(this.find(id), 1);
	    if (this.selected === id)
		this.selected = null;
	    this.changed();
	    this.render();
	}

	render() {
	    const ro = !!this.o.readonly;
	    const fill = !!this.o.fill;
	    const scroll = fill ? 'height:100%; overflow:auto' : 'max-height:70vh; overflow:auto';
	    this.container.innerHTML = `
		<div class="ed border rounded ${fill ? 'd-flex flex-column flex-grow-1' : ''}" style="${fill ? 'min-height:0' : ''}">
		    <div class="d-flex flex-wrap gap-1 align-items-center p-2 border-bottom bg-body-tertiary flex-shrink-0">
			${ro ? '' : `<span class="small text-muted me-1">Add</span>
			<button type="button" class="btn btn-sm btn-outline-primary ed-add" data-type="html"><i class="bi bi-text-paragraph me-1"></i>Text</button>
			<button type="button" class="btn btn-sm btn-outline-primary ed-add" data-type="image"><i class="bi bi-image me-1"></i>Image</button>`}
			<div class="ms-auto ed-rte d-none"></div>
		    </div>
		    <div class="row g-0 ${fill ? 'flex-grow-1' : ''}" style="${fill ? 'min-height:0' : ''}">
			<div class="col-md-8 ed-canvas" style="${scroll}; background:#f2f3f5; min-height:260px"></div>
			<div class="col-md-4 p-3 border-start ed-props" style="${scroll}"></div>
		    </div>
		    <div class="px-3 py-2 border-top small text-danger ed-problem d-none flex-shrink-0"></div>
		</div>`;
	    const root = this.container.firstElementChild;
	    root.querySelectorAll('.ed-add').forEach((b) => b.addEventListener('click', () => this.add(b.dataset.type)));
	    this.renderCanvas(root.querySelector('.ed-canvas'));
	    this.renderProps(root.querySelector('.ed-props'));
	    this.showProblem();
	}

	showProblem() {
	    const el = this.container.querySelector('.ed-problem');
	    const p = this.problem();
	    el.textContent = p;
	    el.classList.toggle('d-none', !p);
	}

	renderCanvas(canvas) {
	    const ro = !!this.o.readonly;
	    const col = document.createElement('div');
	    col.style.cssText = `max-width:${WIDTH}px; margin:16px auto; background:#ffffff; box-shadow:0 1px 3px rgba(0,0,0,.08)`;
	    //the preview text (the line after the subject in the inbox)
	    const pre = document.createElement('div');
	    pre.className = 'px-3 pt-2';
	    pre.innerHTML = `<input type="text" class="form-control form-control-sm border-0 border-bottom rounded-0 text-muted ed-preheader" placeholder="Preview text (shown after the subject in the inbox)" ${ro ? 'disabled' : ''}>`;
	    const preInput = pre.querySelector('input');
	    preInput.value = this.preheader;
	    preInput.addEventListener('input', () => { this.preheader = preInput.value; this.changed(); });
	    col.appendChild(pre);

	    if (!this.blocks.length)
		col.insertAdjacentHTML('beforeend', `<div class="text-muted small text-center py-5">${ro ? 'No blocks' : 'Add a text or an image block'}</div>`);
	    this.blocks.forEach((b, i) => {
		if (!ro)
		    col.appendChild(this.insertLine(i));
		col.appendChild(this.renderBlock(b, i));
	    });
	    if (!ro && this.blocks.length)
		col.appendChild(this.insertLine(this.blocks.length));
	    canvas.appendChild(col);
	    canvas.addEventListener('click', (e) => {
		if (!e.target.closest('.ed-block') && !e.target.closest('.ed-insert') && this.selected) {
		    this.selected = null;
		    this.render();
		}
	    });
	}

	//a thin line with a + between blocks: Text / Image inserted there
	insertLine(at) {
	    const el = document.createElement('div');
	    el.className = 'ed-insert d-flex align-items-center justify-content-center';
	    el.style.cssText = 'height:14px; opacity:.35';
	    el.innerHTML = `<div class="dropdown"><button type="button" class="btn btn-sm btn-light border rounded-circle p-0 lh-1" style="width:20px;height:20px" data-bs-toggle="dropdown" title="Insert a block here"><i class="bi bi-plus"></i></button>
		<ul class="dropdown-menu dropdown-menu-sm"><li><button type="button" class="dropdown-item small" data-type="html"><i class="bi bi-text-paragraph me-1"></i>Text</button></li>
		<li><button type="button" class="dropdown-item small" data-type="image"><i class="bi bi-image me-1"></i>Image</button></li></ul></div>`;
	    el.addEventListener('mouseenter', () => { el.style.opacity = '1'; });
	    el.addEventListener('mouseleave', () => { el.style.opacity = '.35'; });
	    el.querySelectorAll('[data-type]').forEach((b) => b.addEventListener('click', () => this.add(b.dataset.type, at)));
	    return el;
	}

	renderBlock(b, i) {
	    const ro = !!this.o.readonly;
	    const sel = this.selected === b._id;
	    const el = document.createElement('div');
	    el.className = 'ed-block position-relative';
	    el.style.cssText = `outline:${sel ? '2px solid var(--bs-primary)' : '1px dashed transparent'}; outline-offset:-1px`;
	    el.addEventListener('mouseenter', () => { if (!sel) el.style.outline = '1px dashed var(--bs-secondary-border-subtle)'; });
	    el.addEventListener('mouseleave', () => { if (!sel) el.style.outline = '1px dashed transparent'; });
	    if (b.type === 'html') {
		const body = document.createElement('div');
		body.style.cssText = `${FONT} padding:16px 32px; min-height:40px; outline:none`;
		body.innerHTML = b.html;
		if (!ro) {
		    body.contentEditable = 'true';
		    body.addEventListener('focus', () => { if (this.selected !== b._id) { this.selected = b._id; this.markSelected(el); this.renderProps(this.container.querySelector('.ed-props')); } this.showToolbar(body, b); });
		    body.addEventListener('input', () => { b.html = body.innerHTML; this.changed(); this.showProblem(); });
		    //pasted text comes in plain (no styles from other documents)
		    body.addEventListener('paste', (e) => {
			e.preventDefault();
			document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
		    });
		}
		el.appendChild(body);
	    } else {
		const pad = b.width >= WIDTH ? '0' : '8px 32px';
		const align = b.align === 'left' ? 'left' : (b.align === 'right' ? 'right' : 'center');
		el.innerHTML = b.file
		    ? `<div style="padding:${pad}; text-align:${align}"><img src="/file/content/${esc(b.file)}" alt="${esc(b.alt)}" style="display:inline-block; width:100%; max-width:${Math.min(b.width, WIDTH)}px; height:auto; border:0"></div>`
		    : `<div style="padding:${pad}"><div class="border rounded text-muted small text-center py-4 bg-body-tertiary"><i class="bi bi-image fs-3 d-block"></i>Choose an image (on the right)</div></div>`;
		el.addEventListener('click', () => { if (this.selected !== b._id) this.select(b._id); });
	    }
	    if (!ro) {
		const tools = document.createElement('div');
		tools.className = 'position-absolute top-0 end-0 m-1 btn-group btn-group-sm shadow-sm ed-tools';
		tools.style.cssText = `z-index:2; ${sel ? '' : 'display:none'}`;
		tools.innerHTML = `<button type="button" class="btn btn-light border" data-act="up" title="Move up" ${i === 0 ? 'disabled' : ''}><i class="bi bi-arrow-up"></i></button>
		    <button type="button" class="btn btn-light border" data-act="down" title="Move down" ${i === this.blocks.length - 1 ? 'disabled' : ''}><i class="bi bi-arrow-down"></i></button>
		    <button type="button" class="btn btn-light border text-danger" data-act="remove" title="Remove the block"><i class="bi bi-trash"></i></button>`;
		tools.querySelectorAll('[data-act]').forEach((t) => t.addEventListener('mousedown', (e) => {
		    e.preventDefault();
		    e.stopPropagation();
		    if (t.dataset.act === 'remove')
			this.remove(b._id);
		    else
			this.move(b._id, t.dataset.act === 'up' ? -1 : 1);
		}));
		el.appendChild(tools);
	    }
	    return el;
	}

	//the selection outline and tools follow the block being edited (without drawing the canvas again: the text keeps its cursor)
	markSelected(el) {
	    this.container.querySelectorAll('.ed-block').forEach((x) => {
		const on = x === el;
		x.style.outline = on ? '2px solid var(--bs-primary)' : '1px dashed transparent';
		const t = x.querySelector('.ed-tools');
		if (t)
		    t.style.display = on ? '' : 'none';
	    });
	}

	//the rich text toolbar (in the designer's top bar) for text block `body`; commands keep the text's selection (mousedown: default prevented)
	showToolbar(body, b) {
	    const bar = this.container.querySelector('.ed-rte');
	    const btn = (cmd, icon, title, arg) => `<button type="button" class="btn btn-sm btn-light border" data-cmd="${cmd}" ${arg ? `data-arg="${arg}"` : ''} title="${title}"><i class="bi ${icon}"></i></button>`;
	    bar.innerHTML = `<div class="d-flex flex-wrap gap-1 align-items-center">
		<select class="form-select form-select-sm ed-format" style="width:auto" title="Paragraph / heading"><option value="p">Paragraph</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option></select>
		<div class="btn-group">${btn('bold', 'bi-type-bold', 'Bold')}${btn('italic', 'bi-type-italic', 'Italic')}${btn('underline', 'bi-type-underline', 'Underline')}${btn('strikeThrough', 'bi-type-strikethrough', 'Strike through')}</div>
		<div class="btn-group">${btn('insertUnorderedList', 'bi-list-ul', 'Bulleted list')}${btn('insertOrderedList', 'bi-list-ol', 'Numbered list')}</div>
		<div class="btn-group">${btn('justifyLeft', 'bi-text-left', 'Align left')}${btn('justifyCenter', 'bi-text-center', 'Center')}${btn('justifyRight', 'bi-text-right', 'Align right')}</div>
		<label class="btn btn-sm btn-light border mb-0" title="Text color"><i class="bi bi-palette"></i><input type="color" class="ed-color" value="#222222" style="width:0;height:0;opacity:0;position:absolute"></label>
		<div class="btn-group">${btn('createLink', 'bi-link-45deg', 'Link')}${btn('unlink', 'bi-link', 'Remove the link')}${btn('removeFormat', 'bi-eraser', 'Clear formatting')}</div>
		${this.o.expression ? `<div class="dropdown"><button type="button" class="btn btn-sm btn-light border dropdown-toggle ed-vars" data-bs-toggle="dropdown" title="Insert a variable">{{ }}</button><ul class="dropdown-menu dropdown-menu-end small" style="max-height:50vh; overflow:auto"><li class="dropdown-item-text text-muted">Loading…</li></ul></div>` : ''}
	    </div>`;
	    bar.classList.remove('d-none');
	    const exec = (cmd, arg) => {
		body.focus();
		document.execCommand('styleWithCSS', false, true);
		document.execCommand(cmd, false, arg);
		b.html = body.innerHTML;
		this.changed();
	    };
	    bar.querySelectorAll('[data-cmd]').forEach((t) => t.addEventListener('mousedown', (e) => {
		e.preventDefault();
		if (t.dataset.cmd === 'createLink') {
		    const url = prompt('Link address (https://…, mailto:…, or {{ variable }})', 'https://');
		    if (url)
			exec('createLink', url);
		    return;
		}
		exec(t.dataset.cmd, t.dataset.arg);
	    }));
	    bar.querySelector('.ed-format').addEventListener('change', (e) => exec('formatBlock', e.target.value));
	    bar.querySelector('.ed-color').addEventListener('input', (e) => exec('foreColor', e.target.value));
	    const vars = bar.querySelector('.ed-vars');
	    if (vars) {
		vars.addEventListener('mousedown', (e) => e.preventDefault());
		vars.addEventListener('show.bs.dropdown', () => this.loadVariables(vars.nextElementSibling, (name) => exec('insertText', '{{ ' + name + ' }}')));
	    }
	}

	//the variables of the step (the expression help's names), as menu items inserting {{ name }}
	async loadVariables(menu, insert) {
	    const e = this.o.expression;
	    const r = await window.app.ExprInput.request(e.url, Object.assign({ text: '', before: '', mode: 'name', usage: '' }, e.params ? e.params() : {}));
	    const items = (r && r.completion && r.completion.items) || [];
	    menu.innerHTML = items.length ? '' : '<li class="dropdown-item-text text-muted">No variables before this step</li>';
	    items.forEach((it) => {
		const li = document.createElement('li');
		li.innerHTML = `<button type="button" class="dropdown-item small"><code>${esc(it.label)}</code> <span class="text-muted">${esc(it.detail || '')}</span></button>`;
		li.firstElementChild.addEventListener('mousedown', (ev) => { ev.preventDefault(); insert(it.label); });
		menu.appendChild(li);
	    });
	}

	//the right panel: the selected image block's settings; else the step's settings (setStepPanel) or a hint
	renderProps(panel) {
	    panel.innerHTML = '';
	    const i = this.find(this.selected);
	    const b = i >= 0 ? this.blocks[i] : null;
	    if (!b || b.type !== 'image') {
		if (this.stepPanel && !b) {
		    panel.innerHTML = '<div class="small text-uppercase text-muted fw-semibold mb-2">Step settings</div>';
		    panel.appendChild(this.stepPanel);
		} else {
		    panel.innerHTML = `<div class="text-muted small text-center py-4"><i class="bi bi-hand-index d-block fs-4 mb-1"></i>${b ? 'Type in the text block; the toolbar above formats it' : 'Select a block to change it'}</div>`;
		    if (b && this.stepPanel) {
			const back = document.createElement('button');
			back.type = 'button';
			back.className = 'btn btn-sm btn-link p-0 text-decoration-none';
			back.innerHTML = '<i class="bi bi-arrow-left me-1"></i>Step settings';
			back.addEventListener('click', () => this.select(null));
			panel.appendChild(back);
		    }
		}
		return;
	    }
	    const ro = !!this.o.readonly;
	    panel.innerHTML = `
		${this.stepPanel ? '<button type="button" class="btn btn-sm btn-link p-0 mb-2 text-decoration-none ed-back"><i class="bi bi-arrow-left me-1"></i>Step settings</button>' : ''}
		<h6 class="mb-2">Image</h6>
		<div class="mb-2 d-flex gap-2">
		    <button type="button" class="btn btn-sm btn-outline-primary ed-upload" ${ro ? 'disabled' : ''}><i class="bi bi-upload me-1"></i>Upload</button>
		    <span class="small text-muted align-self-center ed-upload-msg"></span>
		    <input type="file" class="d-none ed-file" accept=".png,.jpg,.jpeg,.gif">
		</div>
		<div class="d-flex flex-wrap gap-2 mb-3 ed-gallery"><span class="small text-muted">Loading the project's images…</span></div>
		<label class="form-label small mb-1">Alt text</label>
		<input type="text" class="form-control form-control-sm mb-2" data-k="alt" placeholder="Shown when images are off" ${ro ? 'disabled' : ''}>
		<label class="form-label small mb-1">Width: <span class="ed-w"></span> px</label>
		<input type="range" class="form-range mb-2" data-k="width" min="40" max="${WIDTH}" step="10" ${ro ? 'disabled' : ''}>
		<label class="form-label small mb-1">Alignment</label>
		<select class="form-select form-select-sm mb-2" data-k="align" ${ro ? 'disabled' : ''}><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
		<label class="form-label small mb-1">Link</label>
		<input type="text" class="form-control form-control-sm" data-k="href" placeholder="https://… or {{ variable }} (optional)" ${ro ? 'disabled' : ''}>`;
	    const back = panel.querySelector('.ed-back');
	    if (back)
		back.addEventListener('click', () => this.select(null));
	    panel.querySelectorAll('[data-k]').forEach((input) => {
		const k = input.dataset.k;
		input.value = b[k] ?? '';
		const apply = () => {
		    b[k] = k === 'width' ? Number(input.value) : input.value;
		    if (k === 'width')
			panel.querySelector('.ed-w').textContent = b.width;
		    this.changed();
		    this.refreshBlock(b);
		};
		input.addEventListener('input', apply);
		input.addEventListener('change', apply);
	    });
	    panel.querySelector('.ed-w').textContent = b.width;
	    this.renderGallery(panel, b);
	    if (!ro) {
		const msg = panel.querySelector('.ed-upload-msg');
		const up = new window.app.FileUpload(panel.querySelector('.ed-file'), {
		    url: '/file/upload',
		    accept: '.png,.jpg,.jpeg,.gif',
		    formData: () => {
			const p = document.querySelector('meta[name=csrf-param]')?.content, t = document.querySelector('meta[name=csrf-token]')?.content;
			return p && t ? { [p]: t } : {};
		    },
		    onUploadStart: () => { msg.textContent = 'Uploading…'; },
		    onFileUploadError: (item) => { msg.textContent = (item.response && item.response.error) || 'The upload failed'; },
		    onFileUploadComplete: (item) => {
			msg.textContent = '';
			if (item.response && item.response.id) {
			    b.file = item.response.id;
			    this.images = null;
			    this.changed();
			    this.render();
			}
		    },
		});
		panel.querySelector('.ed-upload').addEventListener('click', () => up.browse());
	    }
	}

	//the project's images (newest first), as thumbnails to pick
	async renderGallery(panel, b) {
	    const box = panel.querySelector('.ed-gallery');
	    if (!this.images) {
		try {
		    const res = await fetch('/file/list?data=1&__limit=200&__sortField=_id&__sortDir=desc', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
		    const data = res.ok ? await res.json() : null;
		    this.images = ((data && data.data) || []).filter((r) => IMAGE.test(String(r.filename || ''))).map((r) => ({ id: r._id, name: String(r.filename) }));
		} catch (err) {
		    this.images = [];
		}
	    }
	    if (!box.isConnected)
		return;
	    box.innerHTML = this.images.length ? '' : '<span class="small text-muted">No PNG, JPEG or GIF images in the project\'s Files: upload one</span>';
	    this.images.forEach((im) => {
		const t = document.createElement('button');
		t.type = 'button';
		t.className = `btn p-0 border ${im.id === b.file ? 'border-primary border-2' : ''}`;
		t.title = im.name;
		t.disabled = !!this.o.readonly;
		t.innerHTML = `<img src="/file/content/${esc(im.id)}" alt="" style="width:64px; height:64px; object-fit:cover; display:block">`;
		t.addEventListener('click', () => { b.file = im.id; if (!b.alt) b.alt = im.name.replace(/\.[^.]+$/, ''); this.changed(); this.render(); });
		box.appendChild(t);
	    });
	}

	//an image block drawn again after a setting changed (the panel keeps its focus)
	refreshBlock(b) {
	    const i = this.find(b._id);
	    const old = this.container.querySelectorAll('.ed-block')[i];
	    if (old)
		old.replaceWith(this.renderBlock(b, i));
	    this.showProblem();
	}
    }

    return EmailDesigner;
})();
