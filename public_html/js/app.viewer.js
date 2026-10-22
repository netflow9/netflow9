if (!window.app)
    window.app = {};

/*!
 * DocxViewer — renders a .docx file into a scrollable, Bootstrap-styled viewer using docx-preview.
 *
 *   new app.DocxViewer(containerEl | '#selector', { url: '/file/content/<id>' })
 *
 * - JSZip and docx-preview (UMD builds, globals `JSZip` and `docx`) are loaded on first use by adding <script> tags
 *   CLD: 2026-10-13T16:48:57+03:00: now loaded by app/layouts/_webpage.php; `jszipSrc` / `libSrc` are gone
 * - the document is rendered inside a shadow root: its styles cannot leak into the app and the app's CSS does not
 *   distort it; links other than http(s) / mailto / #anchors are neutralized after rendering
 * - the file is fetched by the viewer (fetch + ArrayBuffer), so the server keeps sending it as an attachment
 * - toolbar: zoom out / in, 100%
 */
window.app.DocxViewer = (function() {

    const DEFAULTS = {
	url: null,                                                  // .docx url (same origin; cookies are sent)
	height: '75vh',                                             // height of the scrolling area
	zoom: 1,
	minZoom: 0.25,
	maxZoom: 3,
	zoomStep: 1.25,
	texts: {
	    loading: 'Loading document…',
	    error: 'Could not display this document.',
	    libMissing: 'The document previewer is not available.',
	},
    };

    //CLD: 2026-10-13T16:48:57+03:00: JSZip (window.JSZip) and docx-preview (window.docx) are loaded by app/layouts/_webpage.php (were <script> tags added here on first use)
    async function loadLib() {
	if (!window.JSZip || !window.docx || typeof window.docx.renderAsync !== 'function')
	    throw new Error('docx-preview (window.docx) or JSZip (window.JSZip) is not loaded');
	return window.docx;
    }

    //CLD: 2026-10-14T08:35:56+03:00: removed the local el() helper: DOM elements are made with the framework's app.utils.el()

    class DocxViewer {
	constructor(container, options = {}) {
	    if (typeof container === 'string')
		container = document.querySelector(container);
	    if (!container)
		throw new Error('DocxViewer: a container element is required');
	    if (container._docxViewer)
		return container._docxViewer;

	    this.container = container;
	    this.o = Object.assign({}, DEFAULTS, options, { texts: Object.assign({}, DEFAULTS.texts, options.texts || {}) });
	    this.zoom = this.o.zoom;
	    this.destroyed = false;

	    this._build();
	    container._docxViewer = this;
	    this._load();
	}

	/* ---------- public API ---------- */

	setZoom(zoom) {
	    zoom = Math.min(Math.max(this.o.minZoom, zoom), this.o.maxZoom);
	    this.zoom = zoom;
	    this.zoomLabel.textContent = Math.round(zoom * 100) + '%';
	    //CSS zoom keeps the layout (and the scrollbars) in sync with the visual size
	    this.docEl.style.zoom = String(zoom);
	}

	zoomIn() { this.setZoom(this.zoom * this.o.zoomStep); }
	zoomOut() { this.setZoom(this.zoom / this.o.zoomStep); }

	destroy() {
	    this.destroyed = true;
	    if (this.abort)
		this.abort.abort();
	    this.container.innerHTML = '';
	    delete this.container._docxViewer;
	}

	/* ---------- building ---------- */

	_build() {
	    this.container.innerHTML = '';

	    //toolbar
	    const bar = app.utils.el('div', 'd-flex align-items-center gap-1 mb-2');
	    this.btnZoomOut = app.utils.el('button', 'btn btn-sm btn-icon ms-auto', { type: 'button', title: 'Zoom out' });
	    this.btnZoomOut.innerHTML = '<i class="bi bi-zoom-out"></i>';
	    this.zoomLabel = app.utils.el('span', 'small text-muted text-center');
	    this.zoomLabel.style.minWidth = '3.5rem';
	    this.btnZoomIn = app.utils.el('button', 'btn btn-sm btn-icon', { type: 'button', title: 'Zoom in' });
	    this.btnZoomIn.innerHTML = '<i class="bi bi-zoom-in"></i>';
	    this.btnReset = app.utils.el('button', 'btn btn-sm btn-icon', { type: 'button', title: 'Actual size' });
	    this.btnReset.innerHTML = '<i class="bi bi-aspect-ratio"></i>';
	    bar.append(this.btnZoomOut, this.zoomLabel, this.btnZoomIn, this.btnReset);
	    this.toolbar = bar;
	    this._setToolbarEnabled(false);

	    //scrolling area; the document lives in a shadow root inside it
	    this.scroller = app.utils.el('div', 'border rounded bg-body-tertiary overflow-auto position-relative');
	    this.scroller.style.height = this.o.height;
	    this.status = app.utils.el('div', 'text-center text-muted p-5');
	    this.status.textContent = this.o.texts.loading;
	    this.host = app.utils.el('div');
	    this.scroller.append(this.status, this.host);

	    const root = this.host.attachShadow({ mode: 'open' });
	    this.styleEl = app.utils.el('div');               // docx-preview puts the document's <style> here
	    this.docEl = app.utils.el('div');                 // ... and the pages here
	    root.append(this.styleEl, this.docEl);
	    this.shadow = root;

	    this.container.append(bar, this.scroller);

	    this.btnZoomOut.addEventListener('click', () => this.zoomOut());
	    this.btnZoomIn.addEventListener('click', () => this.zoomIn());
	    this.btnReset.addEventListener('click', () => this.setZoom(1));
	}

	_setToolbarEnabled(enabled) {
	    this.toolbar.querySelectorAll('button').forEach((e) => { e.disabled = !enabled; });
	}

	_showError(message) {
	    this.status.classList.remove('d-none');
	    this.status.classList.add('text-danger');
	    this.status.textContent = message;
	}

	/* ---------- loading & rendering ---------- */

	async _load() {
	    const t = this.o.texts;

	    let lib;
	    try {
		lib = await loadLib(this.o);
	    } catch (err) {
		console.error('DocxViewer: cannot load the docx-preview library', err);
		this._showError(t.libMissing);
		return;
	    }

	    try {
		this.abort = new AbortController();
		const res = await fetch(this.o.url, { credentials: 'same-origin', signal: this.abort.signal });
		if (!res.ok)
		    throw new Error('HTTP ' + res.status);
		const data = await res.arrayBuffer();
		if (this.destroyed)
		    return;

		await lib.renderAsync(data, this.docEl, this.styleEl, {
		    className: 'docx',
		    inWrapper: true,         // grey background with page shadows
		    breakPages: true,
		    ignoreLastRenderedPageBreak: true,
		    renderHeaders: true,
		    renderFooters: true,
		    renderFootnotes: true,
		    renderEndnotes: true,
		    useBase64URL: true,      // images as data: URLs (no blob: URLs to revoke)
		    experimental: false,
		});
	    } catch (err) {
		if (this.destroyed)
		    return;
		console.error('DocxViewer: cannot render', this.o.url, err);
		this._showError(t.error);
		return;
	    }

	    this._sanitizeLinks();
	    this.status.classList.add('d-none');
	    this._setToolbarEnabled(true);
	    this.setZoom(this.zoom);
	}

	//keep http(s), mailto and in-document links; neutralize the rest (javascript:, file:, data:, ...)
	_sanitizeLinks() {
	    this.shadow.querySelectorAll('a[href]').forEach((a) => {
		const href = a.getAttribute('href') || '';
		if (href.startsWith('#'))
		    return;

		let url = null;
		try {
		    url = new URL(href, window.location.href);
		} catch (err) {
		    url = null;
		}

		if (url && [ 'http:', 'https:', 'mailto:' ].includes(url.protocol)) {
		    a.setAttribute('target', '_blank');
		    a.setAttribute('rel', 'noopener noreferrer');
		} else {
		    a.removeAttribute('href');
		}
	    });
	}
    }

    return DocxViewer;
})();

/*!
 * PdfViewer — renders a PDF into a scrollable, Bootstrap-styled viewer using pdf.js.
 *
 *   new app.PdfViewer(containerEl | '#selector', { url: '/file/content/<id>' })
 *
 * - pages are rendered lazily, when they scroll into view; toolbar: previous / next / page number, zoom out / in, fit width
 * - the PDF is fetched by pdf.js itself, so the server can (and does) send it as an attachment: the browser's own PDF
 *   viewer is never involved, and PDF scripts are not run (scripting is not enabled, XFA off)
 *
 */
window.app.PdfViewer = (function() {

    const DEFAULTS = {
	url: null,                                      // PDF url (same origin; cookies are sent)
	//CLD: 2026-10-06T21:46:14+03:00: file names of the local build (were the .min.mjs names)
	workerSrc: '/js/vendor/pdfjs/pdf.worker.mjs',   // pdf.js worker module
	zoom: 'fit',                                    // 'fit' (page width) or a number (1 = 100%)
	minZoom: 0.25,
	maxZoom: 4,
	zoomStep: 1.25,
	height: '75vh',                                 // height of the scrolling area
	texts: {
	    loading: 'Loading PDF…',
	    error: 'Could not display this PDF.',
	    libMissing: 'The PDF previewer is not available.',
	    page: 'Page',
	    of: 'of',
	},
    };

    function loadLib(o) {
	const ready = (lib) => {
	    lib.GlobalWorkerOptions.workerSrc = o.workerSrc;
	    return lib;
	};
	if (window.pdfjsLib)
	    return Promise.resolve(ready(window.pdfjsLib));
	if (document.readyState === 'complete')
	    return Promise.reject(new Error('pdf.js is not loaded'));
	return new Promise((resolve, reject) => window.addEventListener('load', () => window.pdfjsLib ? resolve(ready(window.pdfjsLib)) : reject(new Error('pdf.js is not loaded')), { once: true }));
    }

    //CLD: 2026-10-14T08:35:56+03:00: removed the local el() helper: DOM elements are made with the framework's app.utils.el()

    class PdfViewer {
	constructor(container, options = {}) {
	    if (typeof container === 'string')
		container = document.querySelector(container);
	    if (!container)
		throw new Error('PdfViewer: a container element is required');
	    if (container._pdfViewer)
		return container._pdfViewer;

	    this.container = container;
	    this.o = Object.assign({}, DEFAULTS, options, { texts: Object.assign({}, DEFAULTS.texts, options.texts || {}) });

	    this.doc = null;          // pdf.js document
	    this.pages = [];          // [{ wrap, canvas, rendered, task }]
	    this.baseSize = null;     // page 1 size at zoom 1: { width, height }
	    this.zoom = 1;
	    this.current = 1;
	    this.destroyed = false;

	    this._build();
	    container._pdfViewer = this;
	    this._load();
	}

	/* ---------- public API ---------- */

	goTo(n) {
	    if (!this.doc)
		return;
	    n = Math.min(Math.max(1, parseInt(n, 10) || 1), this.doc.numPages);
	    this.pages[n - 1].wrap.scrollIntoView({ block: 'start' });
	    this._setCurrent(n);
	}

	setZoom(zoom) {
	    if (!this.doc)
		return;
	    if (zoom === 'fit')
		zoom = this._fitZoom();
	    zoom = Math.min(Math.max(this.o.minZoom, zoom), this.o.maxZoom);

	    const page = this.current;
	    this.zoom = zoom;
	    this.zoomLabel.textContent = Math.round(zoom * 100) + '%';
	    this._layoutPages();
	    this.goTo(page);
	}

	zoomIn() { this.setZoom(this.zoom * this.o.zoomStep); }
	zoomOut() { this.setZoom(this.zoom / this.o.zoomStep); }

	destroy() {
	    this.destroyed = true;
	    if (this.observer)
		this.observer.disconnect();
	    this.pages.forEach((p) => { if (p.task) p.task.cancel(); });
	    if (this.doc)
		this.doc.destroy();
	    this.container.innerHTML = '';
	    delete this.container._pdfViewer;
	}

	/* ---------- building ---------- */

	_build() {
	    const t = this.o.texts;
	    this.container.innerHTML = '';

	    //CLD: 2026-10-06T21:49:39+03:00: toolbar buttons are btn-icon

	    //toolbar
	    const bar = app.utils.el('div', 'd-flex align-items-center gap-1 mb-2 flex-wrap');
	    this.btnPrev = app.utils.el('button', 'btn btn-sm btn-icon', { type: 'button', title: 'Previous page' });
	    this.btnPrev.innerHTML = '<i class="bi bi-chevron-up"></i>';
	    this.btnNext = app.utils.el('button', 'btn btn-sm btn-icon', { type: 'button', title: 'Next page' });
	    this.btnNext.innerHTML = '<i class="bi bi-chevron-down"></i>';
	    this.pageInput = app.utils.el('input', 'form-control form-control-sm text-center', { type: 'number', min: '1', value: '1', 'aria-label': t.page });
	    this.pageInput.style.width = '4.5rem';
	    this.pageCount = app.utils.el('span', 'small text-muted text-nowrap');

	    this.btnZoomOut = app.utils.el('button', 'btn btn-sm btn-icon ms-auto', { type: 'button', title: 'Zoom out' });
	    this.btnZoomOut.innerHTML = '<i class="bi bi-zoom-out"></i>';
	    this.zoomLabel = app.utils.el('span', 'small text-muted text-center');
	    this.zoomLabel.style.minWidth = '3.5rem';
	    this.btnZoomIn = app.utils.el('button', 'btn btn-sm btn-icon', { type: 'button', title: 'Zoom in' });
	    this.btnZoomIn.innerHTML = '<i class="bi bi-zoom-in"></i>';
	    this.btnFit = app.utils.el('button', 'btn btn-sm btn-icon', { type: 'button', title: 'Fit width' });
	    this.btnFit.innerHTML = '<i class="bi bi-arrows-expand-vertical"></i>';

	    bar.append(this.btnPrev, this.btnNext, this.pageInput, this.pageCount, this.btnZoomOut, this.zoomLabel, this.btnZoomIn, this.btnFit);
	    this.toolbar = bar;
	    this._setToolbarEnabled(false);

	    //scrolling area with the pages
	    this.scroller = app.utils.el('div', 'border rounded bg-body-tertiary overflow-auto position-relative');
	    this.scroller.style.height = this.o.height;
	    this.pagesEl = app.utils.el('div', 'd-flex flex-column align-items-center gap-3 p-3');
	    this.status = app.utils.el('div', 'text-center text-muted p-5');
	    this.status.textContent = t.loading;
	    this.scroller.append(this.status, this.pagesEl);

	    this.container.append(bar, this.scroller);

	    //events
	    this.btnPrev.addEventListener('click', () => this.goTo(this.current - 1));
	    this.btnNext.addEventListener('click', () => this.goTo(this.current + 1));
	    this.pageInput.addEventListener('change', () => this.goTo(this.pageInput.value));
	    this.btnZoomOut.addEventListener('click', () => this.zoomOut());
	    this.btnZoomIn.addEventListener('click', () => this.zoomIn());
	    this.btnFit.addEventListener('click', () => this.setZoom('fit'));
	    this.scroller.addEventListener('scroll', () => this._trackCurrent(), { passive: true });
	}

	_setToolbarEnabled(enabled) {
	    this.toolbar.querySelectorAll('button, input').forEach((e) => { e.disabled = !enabled; });
	}

	_showError(message) {
	    this.status.classList.remove('d-none');
	    this.status.classList.add('text-danger');
	    this.status.textContent = message;
	}

	/* ---------- loading & rendering ---------- */

	async _load() {
	    const t = this.o.texts;

	    let lib;
	    try {
		lib = await loadLib(this.o);
	    } catch (err) {
		console.error('PdfViewer: pdf.js is not available (window.pdfjsLib)', err);
		this._showError(t.libMissing);
		return;
	    }

	    try {
		//CLD: 2026-10-06T21:46:14+03:00: pdf.js 6 has no `isEvalSupported` (no eval path left); same-origin requests send the session cookie
		//by default, so no `withCredentials`
		this.doc = await lib.getDocument({
		    url: this.o.url,
		    enableXfa: false,
		}).promise;
	    } catch (err) {
		console.error('PdfViewer: cannot open', this.o.url, err);
		this._showError(t.error);
		return;
	    }
	    if (this.destroyed)
		return;

	    //size of page 1 at 100%: used for all placeholders until each page is rendered
	    const first = await this.doc.getPage(1);
	    const vp = first.getViewport({ scale: 1 });
	    this.baseSize = { width: vp.width, height: vp.height };

	    //one placeholder per page
	    for (let n = 1; n <= this.doc.numPages; n++) {
		const wrap = app.utils.el('div', 'bg-white shadow-sm position-relative');
		wrap.dataset.page = n;
		const canvas = app.utils.el('canvas', 'd-block');
		wrap.appendChild(canvas);
		this.pagesEl.appendChild(wrap);
		this.pages.push({ wrap, canvas, rendered: false, task: null, size: null });
	    }

	    this.status.classList.add('d-none');
	    this.pageCount.textContent = t.of + ' ' + this.doc.numPages;
	    this.pageInput.max = String(this.doc.numPages);
	    this._setToolbarEnabled(true);

	    //render pages as they come into view (and a little before)
	    this.observer = new IntersectionObserver((entries) => {
		entries.forEach((e) => {
		    if (e.isIntersecting)
			this._renderPage(parseInt(e.target.dataset.page, 10));
		});
	    }, { root: this.scroller, rootMargin: '200px 0px' });
	    this.pages.forEach((p) => this.observer.observe(p.wrap));

	    this.setZoom(this.o.zoom);
	}

	_fitZoom() {
	    //scroller width minus the page area's padding (p-3 = 1rem each side) and a scrollbar allowance
	    const available = this.scroller.clientWidth - 48;
	    return this.baseSize ? Math.max(this.o.minZoom, available / this.baseSize.width) : 1;
	}

	//size every page box for the current zoom; rendered pages are redrawn when visible again
	_layoutPages() {
	    this.pages.forEach((p) => {
		if (p.task) {
		    p.task.cancel();
		    p.task = null;
		}
		p.rendered = false;
		const size = p.size || this.baseSize;
		p.wrap.style.width = Math.floor(size.width * this.zoom) + 'px';
		p.wrap.style.height = Math.floor(size.height * this.zoom) + 'px';
	    });
	    //re-trigger the observer for pages already in view
	    if (this.observer) {
		this.pages.forEach((p) => { this.observer.unobserve(p.wrap); this.observer.observe(p.wrap); });
	    }
	}

	async _renderPage(n) {
	    const p = this.pages[n - 1];
	    if (!p || p.rendered || p.task || !this.doc)
		return;

	    const zoom = this.zoom;
	    try {
		const page = await this.doc.getPage(n);
		const vp1 = page.getViewport({ scale: 1 });
		p.size = { width: vp1.width, height: vp1.height };

		const viewport = page.getViewport({ scale: zoom });
		const dpr = window.devicePixelRatio || 1;
		const canvas = p.canvas;
		canvas.width = Math.floor(viewport.width * dpr);
		canvas.height = Math.floor(viewport.height * dpr);
		canvas.style.width = Math.floor(viewport.width) + 'px';
		canvas.style.height = Math.floor(viewport.height) + 'px';
		p.wrap.style.width = canvas.style.width;
		p.wrap.style.height = canvas.style.height;

		p.task = page.render({
		    canvasContext: canvas.getContext('2d'),
		    viewport,
		    transform: dpr !== 1 ? [ dpr, 0, 0, dpr, 0, 0 ] : null,
		});
		await p.task.promise;
		p.task = null;
		//a zoom change during rendering invalidates it
		p.rendered = (zoom === this.zoom);
	    } catch (err) {
		p.task = null;
		if (err && err.name === 'RenderingCancelledException')
		    return;
		console.error('PdfViewer: cannot render page', n, err);
	    }
	}

	//the current page = the last page whose top is above the upper third of the scroller
	_trackCurrent() {
	    if (!this.doc)
		return;
	    const limit = this.scroller.getBoundingClientRect().top + this.scroller.clientHeight / 3;
	    let current = 1;
	    for (const p of this.pages) {
		if (p.wrap.getBoundingClientRect().top <= limit)
		    current = parseInt(p.wrap.dataset.page, 10);
		else
		    break;
	    }
	    this._setCurrent(current);
	}

	_setCurrent(n) {
	    this.current = n;
	    if (document.activeElement !== this.pageInput)
		this.pageInput.value = String(n);
	    this.btnPrev.disabled = (n <= 1);
	    this.btnNext.disabled = (n >= this.doc.numPages);
	}
    }

    return PdfViewer;
})();

/*!
 * XlsxViewer — shows the sheets of a spreadsheet as Bootstrap tables, one tab per sheet, using SheetJS.
 *
 *   new app.XlsxViewer(containerEl | '#selector', { url: '/file/content/<id>' })
 *
 * - SheetJS (UMD build, global `XLSX`) is loaded on first use by adding a <script> tag
 *   CLD: 2026-10-13T16:48:57+03:00: now loaded by app/layouts/_webpage.php; `libSrc` is gone
 * - cells show their formatted value (as in the spreadsheet); every value is set with textContent (never as HTML)
 * - large sheets are cut to `maxRows` x `maxCols` (a note says so); formulas, styles, merged cells and charts are not shown
 * - the file is fetched by the viewer (fetch + ArrayBuffer), so the server keeps sending it as an attachment
 */
window.app.XlsxViewer = (function() {

    const DEFAULTS = {
	url: null,                                         // spreadsheet url (same origin; cookies are sent)
	height: '75vh',                                    // height of the scrolling area
	maxRows: 1000,                                     // rows parsed and shown per sheet
	maxCols: 100,                                      // columns shown per sheet
	texts: {
	    loading: 'Loading spreadsheet…',
	    error: 'Could not display this spreadsheet.',
	    libMissing: 'The spreadsheet previewer is not available.',
	    empty: 'This sheet is empty.',
	    truncated: 'Showing the first {rows} rows and {cols} columns.',
	},
    };

    //CLD: 2026-10-13T16:48:57+03:00: SheetJS is window.XLSX, loaded by app/layouts/_webpage.php (was a <script> added here on first use)
    function loadLib() {
	return window.XLSX && typeof window.XLSX.read === 'function' ? Promise.resolve(window.XLSX) : Promise.reject(new Error('SheetJS (window.XLSX) is not loaded'));
    }

    //CLD: 2026-10-14T08:35:56+03:00: removed the local el() helper: DOM elements are made with the framework's app.utils.el()

    class XlsxViewer {
	constructor(container, options = {}) {
	    if (typeof container === 'string')
		container = document.querySelector(container);
	    if (!container)
		throw new Error('XlsxViewer: a container element is required');
	    if (container._xlsxViewer)
		return container._xlsxViewer;

	    this.container = container;
	    this.o = Object.assign({}, DEFAULTS, options, { texts: Object.assign({}, DEFAULTS.texts, options.texts || {}) });
	    this.lib = null;
	    this.workbook = null;
	    this.current = null;      // name of the sheet shown
	    this.destroyed = false;

	    this._build();
	    container._xlsxViewer = this;
	    this._load();
	}

	/* ---------- public API ---------- */

	//show the sheet `name` (or index)
	showSheet(name) {
	    if (!this.workbook)
		return;
	    if (typeof name === 'number')
		name = this.workbook.SheetNames[name];
	    if (!this.workbook.Sheets[name])
		return;

	    this.current = name;
	    this.tabs.querySelectorAll('.nav-link').forEach((a) => a.classList.toggle('active', a.dataset.sheet === name));
	    this._renderSheet(this.workbook.Sheets[name]);
	    this.scroller.scrollTop = 0;
	    this.scroller.scrollLeft = 0;
	}

	destroy() {
	    this.destroyed = true;
	    if (this.abort)
		this.abort.abort();
	    this.container.innerHTML = '';
	    delete this.container._xlsxViewer;
	}

	/* ---------- building ---------- */

	_build() {
	    this.container.innerHTML = '';

	    //sheet tabs (filled after loading)
	    this.tabs = app.utils.el('ul', 'nav nav-tabs mb-2 flex-nowrap overflow-auto');

	    //note shown when a sheet is cut to maxRows x maxCols
	    this.note = app.utils.el('div', 'small text-muted mb-1 d-none');

	    this.scroller = app.utils.el('div', 'border rounded overflow-auto position-relative');
	    this.scroller.style.height = this.o.height;
	    this.status = app.utils.el('div', 'text-center text-muted p-5');
	    this.status.textContent = this.o.texts.loading;
	    this.tableHost = app.utils.el('div');
	    this.scroller.append(this.status, this.tableHost);

	    this.container.append(this.tabs, this.note, this.scroller);

	    this.tabs.addEventListener('click', (e) => {
		const a = e.target.closest('[data-sheet]');
		if (!a)
		    return;
		e.preventDefault();
		this.showSheet(a.dataset.sheet);
	    });
	}

	_showError(message) {
	    this.status.classList.remove('d-none');
	    this.status.classList.add('text-danger');
	    this.status.textContent = message;
	}

	/* ---------- loading & rendering ---------- */

	async _load() {
	    const t = this.o.texts;

	    try {
		this.lib = await loadLib(this.o);
	    } catch (err) {
		console.error('XlsxViewer: cannot load SheetJS', err);
		this._showError(t.libMissing);
		return;
	    }

	    try {
		this.abort = new AbortController();
		const res = await fetch(this.o.url, { credentials: 'same-origin', signal: this.abort.signal });
		if (!res.ok)
		    throw new Error('HTTP ' + res.status);
		const data = await res.arrayBuffer();
		if (this.destroyed)
		    return;

		this.workbook = this.lib.read(data, {
		    type: 'array',
		    sheetRows: this.o.maxRows + 1,  // parse no more than needed (+1 to know that rows were cut)
		    cellFormula: false,
		    cellHTML: false,
		    cellStyles: false,
		    cellDates: true,
		});
	    } catch (err) {
		if (this.destroyed)
		    return;
		console.error('XlsxViewer: cannot read', this.o.url, err);
		this._showError(t.error);
		return;
	    }

	    //one tab per sheet
	    this.workbook.SheetNames.forEach((name) => {
		const li = app.utils.el('li', 'nav-item');
		const a = app.utils.el('a', 'nav-link text-nowrap', { href: '#' });
		a.dataset.sheet = name;
		a.textContent = name;
		li.appendChild(a);
		this.tabs.appendChild(li);
	    });

	    this.status.classList.add('d-none');
	    if (this.workbook.SheetNames.length)
		this.showSheet(0);
	    else
		this._showError(t.error);
	}

	_renderSheet(ws) {
	    const t = this.o.texts;
	    const XLSX = this.lib;
	    this.tableHost.innerHTML = '';
	    this.note.classList.add('d-none');

	    if (!ws['!ref']) {
		const empty = app.utils.el('div', 'text-center text-muted p-5');
		empty.textContent = t.empty;
		this.tableHost.appendChild(empty);
		return;
	    }

	    //full range (columns) as declared by the sheet; rows: what was parsed
	    const range = XLSX.utils.decode_range(ws['!ref']);
	    const nCols = Math.min(range.e.c - range.s.c + 1, this.o.maxCols);
	    const rows = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '', blankrows: true });
	    const cutRows = rows.length > this.o.maxRows;
	    const cutCols = (range.e.c - range.s.c + 1) > this.o.maxCols;
	    const nRows = Math.min(rows.length, this.o.maxRows);

	    if (cutRows || cutCols) {
		this.note.textContent = t.truncated.replace('{rows}', nRows).replace('{cols}', nCols);
		this.note.classList.remove('d-none');
	    }

	    const table = app.utils.el('table', 'table table-sm table-bordered table-hover mb-0 small');
	    table.style.width = 'auto';

	    //header: column letters, sticky while scrolling
	    const thead = app.utils.el('thead');
	    const hr = app.utils.el('tr');
	    const corner = app.utils.el('th', 'bg-body-secondary');
	    corner.style.position = 'sticky';
	    corner.style.top = '0';
	    corner.style.left = '0';
	    corner.style.zIndex = '3';
	    hr.appendChild(corner);
	    for (let c = 0; c < nCols; c++) {
		const th = app.utils.el('th', 'bg-body-secondary text-center fw-semibold');
		th.style.position = 'sticky';
		th.style.top = '0';
		th.style.zIndex = '2';
		th.textContent = XLSX.utils.encode_col(range.s.c + c);
		hr.appendChild(th);
	    }
	    thead.appendChild(hr);

	    //body: row numbers (sticky on the left) + cells
	    const tbody = app.utils.el('tbody');
	    for (let r = 0; r < nRows; r++) {
		const tr = app.utils.el('tr');
		const rh = app.utils.el('th', 'bg-body-secondary text-end fw-semibold');
		rh.style.position = 'sticky';
		rh.style.left = '0';
		rh.style.zIndex = '1';
		rh.textContent = String(range.s.r + r + 1);
		tr.appendChild(rh);

		const row = rows[r] || [];
		for (let c = 0; c < nCols; c++) {
		    const td = app.utils.el('td', 'text-nowrap');
		    const v = row[c];
		    td.textContent = (v === undefined || v === null) ? '' : String(v);
		    tr.appendChild(td);
		}
		tbody.appendChild(tr);
	    }

	    table.append(thead, tbody);
	    this.tableHost.appendChild(table);
	}
    }

    return XlsxViewer;
})();
