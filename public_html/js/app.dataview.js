if (!window.app)
    window.app = {};

window.app.BaseDataView = (function() {

    class Schema {
	parent = null;
	schema = {};
    
	constructor(parent, options) {
	    this.parent = parent;
	    this.readonly = options.readonly || false;
	    this.updateColumnUrl = options.updateColumnUrl || false;
	    this.createColumnUrl = options.createColumnUrl || false;
	    //CLD: 2026-10-05T21:06:21+03:00: URL template for deleting a column; `__KEY__` is replaced with the column key (as in updateColumnUrl)
	    this.deleteColumnUrl = options.deleteColumnUrl || false;
	    this.schema = options.data || {};
	}

	get() {
	    return this.schema;
	}

	getVisibleColumnNames() {
	    var colNames = []
	    Object.keys(this.schema).forEach((colName) => {
		const col = this.schema[colName];
		if (col.show === false || col.hide === true)
		    return;
		colNames.push(colName);
	    });
	    return colNames;
	}

	getColumnNames() {
	    var colNames = []
	    Object.keys(this.schema).forEach((colName) => { colNames.push(colName) });
	    return colNames;
	}


	column(col) {
	    return this.schema[col];
	}
    }

    class Search {
	search = '';

	renderer = null;
	timer = null;

	constructor(parent, options) {
	    this.parent = parent;

	    //CLD: 2026-10-19T22:19:24+03:00: grows up to 20rem, never under 8rem (was w-25: a quarter of the toolbar, too narrow on phones)
	    const defLayout = `
	    <input type="text" class="form-control form-control-sm flex-grow-1 search-input" style="max-width: 20rem; min-width: 8rem; flex-basis: 12rem" placeholder="Search..." value="{{search}}">
	    `;
	    this.renderer = app.template.compile(options.layout || defLayout);
	}
	render() {
	    return this.renderer({
		search: this.search,
	    });
	}

	processDataUrl(url) {
	    if (!this.search.length)
		return url;

    	    const glue = url.indexOf('?') == -1 ? '?' : '&';
	    return url + glue + '__search=' + encodeURIComponent(this.search);
	}

	postRender() {
	    const el = this.parent.container.querySelector('.search-input');

	    const doSearch = (e) => {
		if (this.timer)
		    clearTimeout(this.timer);
		this.search = el.value;
		this.timer = setTimeout(() => {
		    //reset paging
		    if (this.parent.widgets.pager)
			this.parent.widgets.pager.reset();
		    this.parent.loadData();
		}, 500); // debounce: wait 500ms after typing stops
	    }

	    el.addEventListener('input', doSearch);
	}
    }


    class Sorter {
	sortField = '_id';
	sortDir = 'asc';

	allSorts = {};

	constructor(parent, options) {
	    this.parent = parent;

	    const defLayout = `
	    <div class="dropdown sorter-container">
		<button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
		    Sort {{crtSort}}
		</button>
		<ul class="dropdown-menu dropdown-menu-end bg-white">
		    {{allSorts}}
		</ul>
	    </div>
	    `;
	    this.renderer = app.template.compile(options.layout || defLayout);

	    this.sortField = options.sortField || '_id';
	    this.sortDir = options.sortDir || 'asc';
	}

	render() {
	    var ddOptions = [];
	    for (const key in this.allSorts) {
		var def = this.allSorts[key];
		if (def.f == this.sortField && def.d == this.sortDir)
		    continue;
		ddOptions.push(`<li><a class='dropdown-item' href='#' data-field='${def.f}' data-dir='${def.d}'>${def.l}</a></li>`);
	    }

	    //build a list of possible sorts
	    for (const key in this.parent.schema.get()) {
		var desc = this.parent.schema.column(key);
		this.allSorts[key + '::asc'] = { f: key, d: 'asc', l: desc.label + ': asc' };
		this.allSorts[key + '::desc'] = { f: key, d: 'desc', l: desc.label + ': desc' };
	    }

	    return this.renderer({
		crtSort: this.allSorts[this.sortField + '::' + this.sortDir]?.l,
		allSorts: ddOptions.join(''),
	    });
	}

	processDataUrl(url) {
    	    const glue = url.indexOf('?') == -1 ? '?' : '&';
	    return url + glue + '__sortField=' + this.sortField + '&__sortDir=' + this.sortDir;
	}

	postRender() {
	    //add click handlers
	    const sorterContainer = this.parent.container.querySelector('.sorter-container');
	    for(let el of sorterContainer.getElementsByClassName('dropdown-item')) {
		el.addEventListener('click', (e) => {
		    e.preventDefault();
		    this.sortField = e.target.dataset.field;
		    this.sortDir = e.target.dataset.dir;

		    this.parent.loadData();
		});
	    }
	}
    }


    class Filter {
	crtFilter = [];
	draftFilter = [];

	renderer = null;

	//ui elements
	filterWrap = null;

	filterOps = false;

	//CLD: 2026-10-30T17:18:20+02:00: saved views (a named filter, shared by the users of a data table): options.views = { listUrl, createUrl, deleteUrl (__VIEW__: the view's id) };
	//none without it. crtView: the view applied ({ id, name, filter }), null after a filter changed by hand
	views = null;
	viewList = [];
	crtView = null;
	viewsWrap = null;

	constructor(parent, options) {
	    this.parent = parent;

	    const defLayout = `
	    <div class="dropdown filter-wrap">
    		<button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
    		    Filter <span class="filter-count d-none badge rounded-pill bg-warning">0</span>
    		</button>
    		<!--CLD: 2026-10-19T22:19:24+03:00: no wider than the window (was 30rem)-->
    		<div class="dropdown-menu p-2" style="width:min(30rem, calc(100vw - 2rem)); z-index: 1030">
    		    <div class="filter-rows"></div>
    		    <div class="text-end pb-2">
        		<button class="btn btn-sm btn-success btn-filter-add-row" type="button">
        		    <i class="bi bi-plus"></i>
        		</button>
    		    </div>
    		    <div class="text-end border-top pt-2">
        		<button class="btn btn-sm btn-link text-decoration-none btn-filter-clear">Clear</button>
        		<button class="btn btn-sm btn-primary btn-filter-apply">Apply</button>
    		    </div>
    		</div>
	    </div>
	    `;
	    this.renderer = app.template.compile(options.layout || defLayout);

	    this.filterOps = options.data || false;
	    this.views = options.views || null;
	}

	render() {
	    //CLD: 2026-10-30T17:18:20+02:00: the views dropdown before the filter's, when options.views
	    if (!this.views)
		return this.renderer({});
	    return `
	    <div class="dropdown filter-views">
		<button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Saved views">
		    <i class="bi bi-bookmark"></i> <span class="filter-view-name">View</span>
		</button>
		<div class="dropdown-menu dropdown-menu-end p-0" style="width:min(18rem, calc(100vw - 2rem)); z-index: 1030">
		    <div class="filter-views-list py-1"></div>
		    <div class="border-top p-2 filter-views-save">
			<div class="input-group input-group-sm">
			    <input type="text" class="form-control filter-views-name" maxlength="64" placeholder="Save current filter as…">
			    <button class="btn btn-primary btn-filter-views-save" type="button" title="Save view"><i class="bi bi-floppy"></i></button>
			</div>
		    </div>
		</div>
	    </div>` + this.renderer({});
	}

	cloneRow(row) {
	    return { id: row.id, field: row.field, op: row.op, value: row.value };
	}

	makeRow() {
	    //get first filterable field from schema
	    var field = null;
	    for (const __f of this.parent.schema.getColumnNames()) {
		console.log('Filter::makeRow: check column:', __f);
		if (this.parent.schema.column(__f).filter) {
		    field = __f;
		    break;
		}
	    }
	    if (!field)
		throw Error('no filterable fields');

	    return {
		id: "f" + Date.now() + Math.random().toString(16).slice(2),
		field: field,
		operator: "",
		value: ""
	    };
	}

	buildFilterRow(row) {
	    const wrap = document.createElement("div");
	    wrap.className = "filter-row d-flex align-items-center gap-2 mb-1";

	    // field select
	    const fieldSelect = document.createElement("select");
	    fieldSelect.className = "form-select form-select-sm filter-field-select";
	    fieldSelect.style.width = '8rem';
	    for (const key of this.parent.schema.getColumnNames()) {
		const col = this.parent.schema.column(key);
		if (!col.filter)
		    continue;
		const o = document.createElement("option");
		o.value = key;
		o.textContent = col.label;
		fieldSelect.appendChild(o);
	    }

	    fieldSelect.value = row.field;
	    var fieldType = this.parent.schema.column(fieldSelect.value).type;

	    fieldSelect.addEventListener("change", () => {
		row.field = fieldSelect.value;
		fieldType = this.parent.schema.column(fieldSelect.value).type;
		row.op = this.filterOps[fieldType][0].key;
		row.value = '';
		this.renderFilterRows(); // rebuild so operator + value input match the new field type
	    });
	    wrap.appendChild(fieldSelect);

	    // operator select
	    const opSelect = document.createElement("select");
	    opSelect.className = "form-select form-select-sm filter-op-select";
	    opSelect.style.width = '6rem';
	    opSelect.style.minWidth = '6rem';
	    //get the type of field from schema

	    this.filterOps[fieldType].forEach((op) => {
    		const o = document.createElement("option");
    		o.value = op.key;
    		o.textContent = op.label;
    		opSelect.appendChild(o);
	    });
	    opSelect.value = row.op || this.filterOps[fieldType][0].key;
	    row.op = opSelect.value;
	    opSelect.addEventListener("change", () => {
		row.op = opSelect.value;
	    });
	    wrap.appendChild(opSelect);

	    // value input
	    switch(fieldType) {
		case "select":
	    	    const f_select = document.createElement("select");
		    f_select.className = "form-select form-select-sm filter-value-input";
		    this.parent.schema.column(row.field).choices.forEach((opt) => {
    			const o = document.createElement("option");
    			o.value = opt;
    			o.textContent = opt;
    			f_select.appendChild(o);
    		    });
    		    f_select.value = row.value || this.parent.schema.column(row.field).choices[0];
    		    row.value = f_select.value;
    		    f_select.addEventListener("change", () => {
    			console.log('Filter: select value changed');
    			row.value = f_select.value;
    		    });
    		    wrap.appendChild(f_select);
    		    break;
		case "checkbox":
		case "switch":
    		    const b_select = document.createElement("select");
    		    b_select.className = "form-select form-select-sm filter-value-input";
    		    [["true", "Yes"], ["false", "No"]].forEach(([val, label]) => {
			const o = document.createElement("option");
    			o.value = val;
    			o.textContent = label;
    			b_select.appendChild(o);
    		    });
    		    b_select.value = row.value || "true";
    		    b_select.addEventListener("change", () => { row.value = b_select.value; });
    		    wrap.appendChild(b_select);
    		    break;

		default:
		    const input = document.createElement("input");
		    input.className = "form-control form-control-sm filter-value-input";
		    //input.type = fieldType def.type === "number" ? "number" : def.type === "date" ? "date" : "text";
		    //if (def.type === "text")
		    //    input.placeholder = "Value";
		    input.value = row.value;
		    input.addEventListener("input", () => { row.value = input.value; });
    		    wrap.appendChild(input);
		    break;
	    }

	    // remove button
	    const removeBtn = document.createElement("button");
	    removeBtn.type = "button";
	    removeBtn.className = "filter-row-remove btn btn-sm btn-link";
	    removeBtn.innerHTML = "<i class='bi bi-x-lg'></i>";
	    removeBtn.title = "Remove this filter";
	    removeBtn.addEventListener("click", () => {
    		this.draftFilter = this.draftFilter.filter((r) => r.id !== row.id);
    		this.renderFilterRows();
	    });
	    wrap.appendChild(removeBtn);

	    return wrap;
	}

	renderFilterRows() {
	    this.filterRows.innerHTML = "";
	    if (!this.draftFilter.length) {
    		const hint = document.createElement("div");
    		hint.className = "text-center p-2";
    		hint.textContent = "No filters yet — add one below.";
    		this.filterRows.appendChild(hint);
    		return;
	    }
	    this.draftFilter.forEach((row) => this.filterRows.appendChild(this.buildFilterRow(row)));
	}

	processDataUrl(url) {
	    if (!this.crtFilter.length)
		return url;

    	    const glue = url.indexOf('?') == -1 ? '?' : '&';

	    var i = 0;
	    this.crtFilter.forEach((row) => {
		url += glue + encodeURIComponent('__filter[' + i + '][field]') + '=' + encodeURIComponent(row.field);
		url += '&' + encodeURIComponent('__filter[' + i + '][op]') + '=' + encodeURIComponent(row.op);
		url += '&' + encodeURIComponent('__filter[' + i + '][value]') + '=' + encodeURIComponent(row.value);
		i++;
	    });
	    return url;
	}

	//CLD: 2026-10-30T17:18:20+02:00: the current filter used: back to the first page, data reloaded, the count badge and the view's name updated
	applyCurrent() {
	    if (this.parent.widgets.pager)
		this.parent.widgets.pager.reset();

	    this.parent.loadData();

	    this.filterCountBadge.textContent = this.crtFilter.length;
	    this.filterCountBadge.classList.toggle("d-none", this.crtFilter.length === 0);

	    if (this.viewsWrap)
		this.viewsWrap.querySelector('.filter-view-name').textContent = this.crtView ? this.crtView.name : 'View';
	}

	//CLD: 2026-10-30T17:18:20+02:00: a request to the views' URLs: the answer's JSON, or an Error with its `error`
	async viewsRequest(url, body) {
	    const opts = { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } };
	    if (body) {
		opts.method = 'POST';
		opts.headers['X-Csrf-Token'] = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
		opts.body = body;
	    }
	    const res = await fetch(url, opts);
	    const data = await res.json().catch(() => ({}));
	    if (!res.ok)
		throw new Error(data.error || 'the server answered ' + res.status);
	    return data;
	}

	viewsToast(type, title, body) {
	    app.utils.toast({ type: type, title: app.utils.escapeHtml(title), body: app.utils.escapeHtml(body), delay: 5000 });
	}

	//CLD: 2026-10-30T17:18:20+02:00: the views' list (loaded each time the dropdown opens: views saved by others are seen)
	async loadViews() {
	    const list = this.viewsWrap.querySelector('.filter-views-list');
	    list.innerHTML = `<div class="text-center p-2"><span class="spinner-border spinner-border-sm text-primary"></span></div>`;
	    try {
		this.viewList = (await this.viewsRequest(this.views.listUrl)).views || [];
	    } catch (err) {
		this.viewList = [];
		list.innerHTML = `<div class="text-danger small p-2">${app.utils.escapeHtml(err.message)}</div>`;
		return;
	    }
	    this.renderViews();
	}

	renderViews() {
	    const list = this.viewsWrap.querySelector('.filter-views-list');
	    list.innerHTML = '';
	    if (!this.viewList.length) {
		list.innerHTML = `<div class="text-center small p-2">No saved views yet</div>`;
		return;
	    }
	    this.viewList.forEach((view) => {
		const row = document.createElement('div');
		row.className = 'd-flex align-items-center';

		const a = document.createElement('a');
		a.href = '#';
		a.className = 'dropdown-item flex-fill text-truncate' + (this.crtView && this.crtView.id === view.id ? ' active' : '');
		a.textContent = view.name;
		a.title = view.filter.map((r) => r.field + ' ' + r.op + ' ' + r.value).join(', ');
		a.addEventListener('click', (e) => {
		    e.preventDefault();
		    this.applyView(view);
		});
		row.appendChild(a);

		const del = document.createElement('button');
		del.type = 'button';
		del.className = 'btn btn-sm btn-link px-2';
		del.title = 'Delete this view';
		del.innerHTML = `<i class="bi bi-trash"></i>`;
		del.addEventListener('click', () => this.askDeleteView(row, view));
		row.appendChild(del);

		list.appendChild(row);
	    });
	}

	//a view applied: its conditions on the columns that can still be filtered become the filter
	applyView(view) {
	    const id = () => "f" + Date.now() + Math.random().toString(16).slice(2);
	    this.crtFilter = view.filter
		.filter((r) => this.parent.schema.getColumnNames().includes(r.field) && this.parent.schema.column(r.field).filter)
		.map((r) => ({ id: id(), field: r.field, op: r.op, value: r.value }));
	    this.draftFilter = this.crtFilter.map(this.cloneRow);
	    this.crtView = view;
	    this.applyCurrent();

	    const toggleBtn = this.viewsWrap.querySelector('.dropdown-toggle');
	    bootstrap.Dropdown.getOrCreateInstance(toggleBtn).hide();
	}

	//delete: confirmed in the view's row (Yes / No)
	askDeleteView(row, view) {
	    row.innerHTML = `<span class="small flex-fill text-truncate px-3">Delete "${app.utils.escapeHtml(view.name)}"?</span>
		<button type="button" class="btn btn-sm btn-danger py-0 me-1 filter-view-del-yes">Yes</button>
		<button type="button" class="btn btn-sm btn-outline-secondary py-0 me-2 filter-view-del-no">No</button>`;
	    row.querySelector('.filter-view-del-no').addEventListener('click', () => this.renderViews());
	    row.querySelector('.filter-view-del-yes').addEventListener('click', async () => {
		row.querySelectorAll('button').forEach((b) => { b.disabled = true; });
		try {
		    await this.viewsRequest(this.views.deleteUrl.replace('__VIEW__', encodeURIComponent(view.id)), new URLSearchParams());
		    if (this.crtView && this.crtView.id === view.id) {
			this.crtView = null;
			this.viewsWrap.querySelector('.filter-view-name').textContent = 'View';
		    }
		} catch (err) {
		    this.viewsToast('error', 'View not deleted', err.message);
		}
		this.loadViews();
	    });
	}

	//the current filter saved as a view named from the input
	async saveView() {
	    const input = this.viewsWrap.querySelector('.filter-views-name');
	    const name = input.value.trim();
	    if (!name) {
		input.focus();
		return;
	    }
	    if (!this.crtFilter.length) {
		this.viewsToast('warning', 'Nothing to save', 'Apply a filter first, then save it as a view');
		return;
	    }
	    const body = new URLSearchParams();
	    body.append('name', name);
	    body.append('filter', JSON.stringify(this.crtFilter.map((r) => ({ field: r.field, op: r.op, value: r.value }))));
	    try {
		const data = await this.viewsRequest(this.views.createUrl, body);
		input.value = '';
		this.crtView = data.view;
		this.viewsWrap.querySelector('.filter-view-name').textContent = data.view.name;
		this.viewsToast('success', 'OK', 'View saved!');
	    } catch (err) {
		this.viewsToast('error', 'View not saved', err.message);
	    }
	    this.loadViews();
	}

	postRender() {
	    if (this.filterWrap)
		return;

	    //CLD: 2026-10-30T17:18:20+02:00: the views dropdown
	    if (this.views) {
		this.viewsWrap = this.parent.container.getElementsByClassName('filter-views')[0];
		this.viewsWrap.querySelector('.dropdown-toggle').addEventListener('show.bs.dropdown', () => this.loadViews());
		this.viewsWrap.querySelector('.btn-filter-views-save').addEventListener('click', () => this.saveView());
		this.viewsWrap.querySelector('.filter-views-name').addEventListener('keydown', (e) => {
		    if (e.key !== 'Enter')
			return;
		    e.preventDefault();
		    this.saveView();
		});
	    }

	    this.filterWrap = this.parent.container.getElementsByClassName('filter-wrap')[0];
	    this.filterRows = this.filterWrap.getElementsByClassName('filter-rows')[0];

	    //clear filter
	    this.filterWrap.getElementsByClassName('btn-filter-clear')[0].addEventListener('click', (e) => {
		this.crtFilter = [];
		this.draftFilter = [];
		this.renderFilterRows();
	    });

	    //apply filter
	    this.filterWrap.getElementsByClassName('btn-filter-apply')[0].addEventListener('click', (e) => {
		this.crtFilter = this.draftFilter.map(this.cloneRow);
		//CLD: 2026-10-30T17:18:20+02:00: a filter changed by hand is no longer the view's; paging reset, reload and badge in applyCurrent()
		this.crtView = null;
		this.applyCurrent();

		const toggleBtn = this.filterWrap.getElementsByClassName('dropdown-toggle')[0];
		bootstrap.Dropdown.getOrCreateInstance(toggleBtn).hide();
	    });

	    //add filter row
	    this.filterWrap.getElementsByClassName('btn-filter-add-row')[0].addEventListener('click', (e) => {
		console.log('Filter: addFilterRow called');
		this.draftFilter.push(this.makeRow());
		this.renderFilterRows();
	    });

	    //toggler button
	    const btnFilter = this.filterWrap.getElementsByClassName('dropdown-toggle')[0];
	    btnFilter.addEventListener("shown.bs.dropdown", () => {
		this.draftFilter = this.crtFilter.map(this.cloneRow);
		this.renderFilterRows();
	    });

	    //badge
	    this.filterCountBadge = btnFilter.getElementsByClassName('filter-count')[0];
	}
    }

    class Pager {
	startRecord = 0;
	endRecord = 0;
	nRecords = 0;
	nPages = 0;
	crtPage = 1;
	pageSize = 10;

	renderer = null;

	//CLD: 2026-10-30T17:18:20+02:00: the page size kept in this browser (localStorage) under options.storeKey, when given; the page-number input's debounce timer
	storeKey = null;
	timer = null;

	constructor(parent, options) {
	    this.parent = parent;
	    this.storeKey = options.storeKey || null;
	    const defLayout = `
	    <!--CLD: 2026-10-19T22:19:24+03:00: the range text hidden on phones-->
	    <!--CLD: 2026-10-19T22:41:00+03:00: no flex-wrap (the select, width 100% from .form-select, took a line of its own and pushed the arrows below); w-auto on the select-->
	    <div class="flex-shrink-0 d-flex align-items-center gap-2 pager-container">
		<!--CLD: 2026-10-13T08:04:46+03:00: the range: "1-10", or "0" when there are no records (was "1-0 of 0")-->
		<div class="d-none d-sm-block" style="white-space:pre">Showing {{range}} of {{nRecords}}</div>
    		<select class="form-select form-select-sm w-auto nav-page-size">
    		    <option value="5">5/pg</option>
    		    <option value="10">10/pg</option>
    		    <option value="25">25/pg</option>
    		    <option value="50">50/pg</option>
    		</select>
		<button type="button" class="btn btn-icon btn-sm d-inline nav-page-prev">
		    <i class="bi bi-chevron-left"></i>
		</button>
		<input type="number" class="form-control form-control-sm d-inline nav-page-number" style="width:4rem" value="{{crtPage}}" min="1" max="{{nPages}}">
		<button type="button" class="btn btn-icon btn-sm d-inline nav-page-next">
		    <i class="bi bi-chevron-right"></i>
		</button>
	    </div>
	    `;
	    this.renderer = app.template.compile(options.layout || defLayout);

	    //CLD: 2026-10-30T17:18:20+02:00: the kept page size, if it is one of the select's sizes
	    if (this.storeKey) {
		let kept = null;
		try { kept = parseInt(localStorage.getItem(this.storeKey), 10); } catch (e) { /* not kept */ }
		if ([5, 10, 25, 50].includes(kept))
		    this.pageSize = kept;
	    }
	}

	render() {
	    const el = document.createElement('template');
	    el.innerHTML = this.renderer({
		startRecord: this.startRecord,
		endRecord: this.endRecord,
		//CLD: 2026-10-13T08:04:46+03:00: the records shown, as text: "0" when there are none
		range: this.nRecords > 0 ? this.startRecord + '-' + this.endRecord : '0',
		nRecords: this.nRecords,
		crtPage: this.crtPage,
		nPages: this.nPages,
	    });

	    return el.innerHTML;
	}

	processDataUrl(url) {
    	    const glue = url.indexOf('?') == -1 ? '?' : '&';
	    return url + glue + '__skip=' + ((this.crtPage - 1) * this.pageSize) + '&__limit=' + this.pageSize;
	}

	reset() {
	    this.crtPage = 1;
	}

	//CLD: 2026-10-30T17:18:20+02:00: go to page n (from the page-number input): kept within 1..nPages, nothing for an empty / invalid number or the current page
	goTo(n) {
	    if (this.timer)
		clearTimeout(this.timer);
	    this.timer = null;
	    n = parseInt(n, 10);
	    if (!Number.isFinite(n))
		return;
	    n = Math.max(1, Math.min(Math.max(this.nPages, 1), n));
	    if (n == this.crtPage)
		return;
	    this.crtPage = n;
	    this.parent.loadData();
	}

	onDataLoaded(data) {
	    //CLD: 2026-10-30T17:18:20+02:00: data loaded by another control (arrows, page size, search, filter): a pending page-number change is dropped
	    if (this.timer)
		clearTimeout(this.timer);
	    this.timer = null;

	    this.nRecords = data.nRecords;
	    this.nPages = Math.ceil(this.nRecords / this.pageSize);

    	    this.startRecord = (this.crtPage - 1) * this.pageSize + 1;
    	    this.endRecord = Math.min(this.nRecords, this.crtPage * this.pageSize);

	    //replace pager
	    this.parent.container.getElementsByClassName('pager-container')[0].outerHTML = this.render();


	    let navPrev = this.parent.container.getElementsByClassName('nav-page-prev')[0];
	    let navNext = this.parent.container.getElementsByClassName('nav-page-next')[0];

	    navPrev.disabled = this.crtPage <= 1;
	    navNext.disabled = this.crtPage >= this.nPages;

	    navPrev.addEventListener('click', (e) => {
		this.crtPage--;
		this.parent.loadData();
	    });
	    navNext.addEventListener('click', (e) => {
		this.crtPage++;
		this.parent.loadData();
	    });

	    let pgSizeSel = this.parent.container.getElementsByClassName('nav-page-size')[0];
	    pgSizeSel.value = this.pageSize;
	    pgSizeSel.addEventListener('change', (e) => {
		this.pageSize = parseInt(e.target.value);
		//CLD: 2026-10-30T17:18:20+02:00: kept in this browser (options.storeKey)
		if (this.storeKey) {
		    try { localStorage.setItem(this.storeKey, String(this.pageSize)); } catch (e) { /* not kept */ }
		}
		//CLD: 2026-10-11T21:48:17+03:00: back to the first page (the current page may not exist with the new size: e.g. page 2 of 12 records at 25/pg showed nothing)
		this.crtPage = 1;
		this.parent.loadData();
	    })

	    //CLD: 2026-10-30T17:18:20+02:00: the page-number input (had no handler): its up / down arrows or typing change the page 500 ms after the last change
	    //(debounce, as the search), Enter at once
	    const pgNumber = this.parent.container.getElementsByClassName('nav-page-number')[0];
	    pgNumber.addEventListener('input', (e) => {
		if (this.timer)
		    clearTimeout(this.timer);
		this.timer = setTimeout(() => this.goTo(pgNumber.value), 500);
	    });
	    pgNumber.addEventListener('keydown', (e) => {
		if (e.key !== 'Enter')
		    return;
		e.preventDefault();
		this.goTo(pgNumber.value);
	    });
	}
    }




    class BaseDataView {
	options = {};
	widgets = {};
	container = null;
	renderer = null;
	data = [];
	schema = null;

	timer = null;

	constructor(container, options) {
    	    if (typeof container === 'string')
    		container = document.querySelector(container);
    	    if (container._dataView)
    		return container._dataView;

	    this.options = options;

	    //empty layout
	    const defEmptyLayout = `
	    <div class="p-4 text-center">
		<h5 class="text-info">No data</h5>
	    </div>
	    `;
	    this.emptyLayout = options.emptyLayout || defEmptyLayout;

	    //empty text
	    this.emptyText = options.emptyText || 'No data';


	    //check if schema is provided
	    if (!options.schema || !options.schema.data)
		throw Error('missing `schema` description in options');

	    this.container = container;
	    container._dataView = this;

	    this.schema = new Schema(this, options.schema);
	}

	run() {
	    this.initWidgets();
	    this.render();

	    if (typeof this.options.onInit == 'function')
		this.options.onInit(this);

	    this.loadData();
	}

	initWidgets() {
	    //init sub-widgets
	    const knownClasses = {
		pager: Pager,
		search: Search,
		filter: Filter,
		sorter: Sorter,
	    };
	    const layoutVars = app.template.vars(this.layout);
	    layoutVars.forEach((lv) => {
		if (typeof this.options[lv] == 'undefined')
		    this.options[lv] = {};

		if (!this.options[lv]) {
		    this.widgets[lv] = '';
		    return;
		}
		if (['string','function'].includes(typeof this.options[lv])) {
		    this.widgets[lv] = this.options[lv];
		    return;
		}
		if (typeof this.options[lv] == 'object' && knownClasses[lv]) {
		    var cls = knownClasses[lv];
		    this.widgets[lv] = new cls(this, this.options[lv]);
		    return;
		}
		//last resort
		this.widgets[lv] = '';
	    });

	    this.renderer = app.template.compile(this.layout);
	}

	showSpinner() {
	    this.spinner.classList.remove('d-none');
        }

	hideSpinner() {
	    this.spinner.classList.add('d-none');
	}

	loadData() {
	    //setup a timer to render a spinner if loading takes longer than...
	    this.timer = setTimeout(() => { this.showSpinner(); }, 100);

	    var url = this.options.dataUrl;

	    //let each widget alter url
	    for (const [key, widget] of Object.entries(this.widgets)) {
		if (typeof widget == 'object' && typeof widget.processDataUrl == 'function')
		    url = widget.processDataUrl(url);
	    }

	    fetch(url, { method: 'GET' }).then((response) => {
		response.json().then((data) => {
		    //clear loading timeout and spinner
		    if (this.timer)
			clearTimeout(this.timer);
		    this.hideSpinner();

		    //this.schema = data.schema;
		    this.data = data.data;

		    //install handlers only after some data has loaded
		    for (const [key, widget] of Object.entries(this.widgets)) {
			if (typeof widget != 'object')
			    continue;
			if (typeof widget.onDataLoaded == 'function')
			    widget.onDataLoaded(data);
		    }

		    //now render items
		    this.renderItems(data);
		})
	    }).catch((err) => {
		console.log(`Error: ${err}` )
	    });
	}

	render() {
	    //render into container
	    var rData = {};
	    for (const [key, widget] of Object.entries(this.widgets)) {
		if (typeof widget == 'string') {
		    rData[key] = widget;
		    continue;
		}
		if (typeof widget == 'function') {
		    rData[key] = widget(this);
		    continue;
		}
		if (typeof widget == 'object') {
		    rData[key] = widget.render(this);
		    continue;
		}
	    }
	    this.container.innerHTML = this.renderer(rData);

	    //let each widget alter url
	    for (const [key, widget] of Object.entries(this.widgets)) {
		if (typeof widget == 'object' && typeof widget.postRender == 'function')
		    widget.postRender();
	    }

	    //add spinner, hidden
	    this.spinner = document.createElement('div');
	    this.spinner.className = "spinner-border text-primary d-none";
	    this.spinner.role = "status";
	    this.spinner.innerHTML = `<span class="visually-hidden">Loading...</span>`;

	    const itemsContainer = this.container.getElementsByClassName('items-container')[0];
	    itemsContainer.prepend(this.spinner);
	}

	renderEmpty() {
	    const emptyTpl = app.template.compile(this.emptyLayout);
	    return emptyTpl({ text: this.emptyText });
	}

    }

    return BaseDataView;
})();

window.app.ListView = (function() {
    class ListView extends app.BaseDataView {
	constructor(rootEl, options) {
	    super(rootEl, options);

	    //CLD: 2026-10-19T22:19:24+03:00: the toolbar wraps on narrow screens (flex-wrap)
	    const defLayout = `
	    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
		{{ title }}
		<div class='flex-fill'></div>
		{{ search }}{{sorter}}{{filter}}{{create}}
	    </div>
	    <div class="items-container">
		<ul class='list-group'>
		    {{items}}
		</ul>
	    </div>
	    <div class='d-flex flex-wrap align-items-center w-100 mt-2'>
		<div class='flex-fill'></div>
		{{pager}}
	    </div>
	    `;
	    this.layout = options.layout || defLayout;
	    this.run();
	}

	renderItems(data) {
	    var items = '';
	    if (data.data.length == 0) {
		items = this.renderEmpty();
	    } else {
		if (typeof this.options.itemRender == 'string')
		    this.options.itemRender = app.template.compile(this.options.itemRender);

		data.data.forEach((item) => { items += this.options.itemRender({item: item}) });
	    }

	    const itemsContainer = this.container.querySelectorAll('.items-container ul')[0];
	    itemsContainer.innerHTML = items;
	}
    }

    return ListView;
})();

//CLD: 2026-10-11T22:01:40+03:00: the grid view (a table: columns from the schema, column designer, row actions, add-row button, empty filler rows), moved here from
//data.grid.js (was the global class `gridView`; the views use app.GridView now)
window.app.GridView = (function() {
    class GridView extends app.BaseDataView {

        gridOptions = {};

        constructor(container, opts) {
    	super(container, opts);

    	//CLD: 2026-10-19T22:19:24+03:00: the toolbar wraps on narrow screens (flex-wrap)
    	const defLayout = `
    	<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    	    {{title}}
    	    <div class='flex-fill'></div>
    	    {{search}}{{filter}}{{create}}
    	</div>
    	<div class="datagrid-wrap items-container position-relative overflow-auto" tabindex="0">
    	    <table class="datagrid table table-bordered table-hover mb-0 w-100 small user-select-none">
        		<thead>
        		    <tr class="header-row"></tr>
        		</thead>
        		<tbody id="grid-body" class="grid-body"></tbody>
    	    </table>
    	</div>
    	<div class='d-flex flex-wrap align-items-center w-100 mt-2'>
    	    <div class='flex-fill'></div>
    	    {{pager}}
    	</div>
    	`;
    	this.layout = opts.layout || defLayout;

    	this.gridOptions = opts.grid || {};
    	this.run();
        }

        reload() {
    	//reset paging
    	if (this.widgets.pager)
    	    this.widgets.pager.reset();

    	this.loadData();
        }


        renderItems(data) {
    	this.renderHeader();
    	this.renderBody();
        }

        renderHeader() {
    	const headerRow = this.container.getElementsByClassName('header-row')[0];

    	headerRow.innerHTML = "";


    	this.schema.getVisibleColumnNames().forEach((colName) => {
    	    const col = this.schema.column(colName);
    	    if (col.show === false || col.hide === true)
    		return;

    	    const th = document.createElement("th");
    	    th.className = "col-th sticky-top p-0";
    	    //th.style.width = col.width + "px";
    	    //last column does not have width
    	    th.style.width = "150px";

    	    const wrap = document.createElement("div");
    	    wrap.className = "col-header d-flex align-items-center justify-content-between gap-2 px-2 py-1 text-dark";

    /*
        	    const typeIcon = document.createElement("span");
        	    typeIcon.className = "col-type-icon";
        	    typeIcon.textContent = COLUMN_TYPES[col.type].icon;
        	    typeIcon.title = COLUMN_TYPES[col.type].label;
        	    wrap.appendChild(typeIcon);
    */
    	    const nameSpan = document.createElement("span");
    	    nameSpan.className = "col-name text-truncate flex-fill";
    	    nameSpan.textContent = col.label;
    	    nameSpan.title = colName;
        	    wrap.appendChild(nameSpan);

        	    // kebab menu (dropdown)
        	    if (!this.schema.readonly) {
        		var editLi = null;
        		var delLi = null;
    		//CLD: 2026-10-05T21:06:21+03:00: plain links handled by app.pjax (modal form / confirm + POST) instead of the undefined
    		//openColumnModal() / deleteSelectedColumn() mock functions; URLs come from schema.updateColumnUrl / deleteColumnUrl
    		const columnUrl = (tpl) => tpl.replace('__KEY__', encodeURIComponent(colName));
        		if (col.edit && this.schema.updateColumnUrl) {
        		    editLi = document.createElement("li");
        		    const editA = document.createElement("a");
        		    editA.className = "dropdown-item";
        		    editA.href = columnUrl(this.schema.updateColumnUrl);
        		    editA.textContent = "Edit column";
        		    editLi.appendChild(editA);
    		}
    		if (col.delete && this.schema.deleteColumnUrl) {
        		    delLi = document.createElement("li");
        		    const delA = document.createElement("a");
        		    delA.className = "dropdown-item text-danger";
        		    delA.href = columnUrl(this.schema.deleteColumnUrl);
        		    delA.dataset.confirm = 'Delete column "' + app.utils.escapeHtml(col.label || colName) + '" and its values in all rows?';
        		    delA.dataset.method = 'post';
        		    delA.textContent = "Delete column";
        		    delLi.appendChild(delA);
    		}

    		if (editLi || delLi) {
        		    const menuWrap = document.createElement("div");
        		    menuWrap.className = "dropdown";

        		    const menuBtn = document.createElement("button");
        		    menuBtn.className = "col-menu-btn";
        		    menuBtn.type = "button";
        		    menuBtn.setAttribute("data-bs-toggle", "dropdown");
        		    menuBtn.setAttribute("aria-expanded", "false");
        		    menuBtn.innerHTML = '<i class="bi bi-three-dots-vertical"></i>';
        		    menuWrap.appendChild(menuBtn);

        		    const menuList = document.createElement("ul");
        		    menuList.className = "dropdown-menu dropdown-menu-end";

    		    if (editLi)
        			menuList.appendChild(editLi);

    		    if (delLi)
        			menuList.appendChild(delLi);

        		    menuWrap.appendChild(menuList);
        		    wrap.appendChild(menuWrap);
        		}
    	    }

        	    th.appendChild(wrap);

        	    const handle = document.createElement("div");
        	    handle.className = "col-resize-handle";
        	    //attachColumnResize(handle, col, th);
    	    handle.addEventListener("mousedown", (e) => {
        		e.preventDefault();
        		e.stopPropagation();

    		let startX = e.clientX;
    		let startW = parseInt(th.style.width.replace('px', ''));

    		function onMove(e) {
        		    const dx = e.clientX - startX;
        		    th.style.width = Math.max(60, startW + dx) + "px";
    		}
    		function onUp() {
        		    handle.classList.remove("resizing");
        		    document.removeEventListener("mousemove", onMove);
        		    document.removeEventListener("mouseup", onUp);
    		}

        		handle.classList.add("resizing");
        		document.addEventListener("mousemove", onMove);
        		document.addEventListener("mouseup", onUp);
    	    });

        	    th.appendChild(handle);

        	    headerRow.appendChild(th);
    	});


    	const addTh = document.createElement("th");
    	addTh.className = "col-th sticky-top p-0";
    	addTh.style.width = "100px";

    	//CLD: 2026-10-05T21:06:21+03:00: show the add-column button only when there is a URL for it
        	if (!this.schema.readonly && this.schema.createColumnUrl) {
        	    const btn = document.createElement('button');
        	    btn.className = 'btn btn-sm btn-circle-xs btn-outline-primary mx-2 my-1';
        	    btn.innerHTML = '<i class="bi bi-plus-lg"></i>';
        	    btn.title = 'Add column';
    	    btn.addEventListener("click", () => {
    		app.pjax.fetch(this.schema.createColumnUrl, { push: false});
    	    });

    	    addTh.appendChild(btn);
    	}

    	headerRow.appendChild(addTh);
        }

        renderBody() {
    	const gridBody = this.container.getElementsByClassName('grid-body')[0];
    	gridBody.innerHTML = "";


    	if (this.data.length == 0) {
    	    const emptyTd = document.createElement("td");
    	    emptyTd.className = "p-0";
    	    emptyTd.style.cursor = "pointer";
    	    emptyTd.colSpan = this.schema.getVisibleColumnNames().length + 1;
    	    emptyTd.innerHTML = this.renderEmpty(); //app.template.compile(this.emptyLayout)();
    	    //CLD: 2026-10-13T08:03:04+03:00: the text in the middle of the row (renderFiller makes the row as tall as a full page); no hover highlight on it
    	    emptyTd.style.verticalAlign = 'middle';
    	    emptyTd.style.setProperty('--bs-table-bg-state', 'transparent');

        	    const tr = document.createElement("tr");
    	    tr.className = 'grid-empty';
        	    tr.appendChild(emptyTd);
        	    gridBody.appendChild(tr);
    	} else {
    	    this.data.forEach((row) => {
        		const tr = document.createElement("tr");
        		tr.dataset.row = row._id;

    		this.schema.getVisibleColumnNames().forEach((colName) => {
    		    const col = this.schema.column(colName);
    		    if (col.show === false || col.hide === true)
    			return;


        		    const td = document.createElement("td");
        		    td.dataset.row = row._id;
        		    td.dataset.col = colName;
        		    td.className = 'p-0 position-relative';
        		    //td.style.width = col.width + "px";

        		    //CLD: 2026-10-30T00:00:00Z: boolean columns (checkbox / switch) shown as text, as any other field (were a checkbox)
        		    const content = document.createElement("div");
        		    content.className = "cell-content px-2 py-1 text-truncate w-100 h-100";
        		    content.textContent = row[colName];
        		    td.appendChild(content);

    /*
    		    // Guard: while this exact cell is being edited (e.g. an open <select>
        		    // dropdown), a mousedown bubbling up from the editor itself must not
        		    // re-trigger selectCell()/commitEdit() — that was destroying the
        		    // in-progress <select> editor before its dropdown could register a
        		    // click, which is why editing a select field previously appeared broken.
        		    td.addEventListener("mousedown", (e) => {
            		if (editing && editing.row === ri && editing.col === ci) return;
            		selectCell(ri, ci);
        		    });
        		    td.addEventListener("dblclick", () => startEdit(ri, ci));
    */
        		    tr.appendChild(td);
        		});

        		const opsTd = document.createElement("td");
        		opsTd.className = "tools-wrap px-2 py-1";
        		opsTd.setAttribute('align', 'right');

    		if (this.gridOptions.rowActions && Object.keys(this.gridOptions.rowActions).length) {
    		    const rowActionsTpl = {};
    		    var rowActionsHtml = '';
    		    Object.keys(this.gridOptions.rowActions).forEach((key) => {
    			if (!rowActionsTpl[key])
    			    rowActionsTpl[key] = app.template.compile(this.gridOptions.rowActions[key]);
    			rowActionsHtml += rowActionsTpl[key]({item: row});
    		    });
    		    opsTd.innerHTML = rowActionsHtml;
    		}

        		tr.appendChild(opsTd);

        		gridBody.appendChild(tr);
    	    });
        	}

    	if (this.gridOptions.createRowUrl) {
    	    const addTr = document.createElement("tr");
    	    const addTd = document.createElement("td");
    	    addTd.className = "p-0";
    	    addTd.style.cursor = "pointer";

    	    addTd.colSpan = this.schema.getVisibleColumnNames().length + 1;

        	    const btn = document.createElement('button');
        	    btn.className = 'btn btn-sm btn-circle-xs btn-outline-primary mx-2 my-1';
        	    btn.innerHTML = '<i class="bi bi-plus-lg"></i>';
        	    btn.title = 'Add row';
    	    //CLD: 2026-10-05T21:30:59+03:00: the button had no handler; open the add-row form (modal) like the add-column button
    	    btn.addEventListener("click", () => {
    		app.pjax.fetch(this.gridOptions.createRowUrl, { push: false });
    	    });
        	    addTd.appendChild(btn);

    	    addTr.appendChild(addTd);
    	    gridBody.appendChild(addTr);
    	}

    	this.renderFiller(gridBody);
        }

        //CLD: 2026-10-11T21:48:17+03:00: a page with fewer records than the page size is filled with empty rows (as tall as a record row), so the table keeps its size from page to
        //page; they are not clickable and not highlighted on hover. The "no records" row counts as one row
        renderFiller(gridBody) {
    	const pager = this.widgets.pager;
    	const pageSize = pager && pager.pageSize ? pager.pageSize : 0;
    	const missing = pageSize - Math.max(this.data.length, 1);
    	if (missing <= 0)
    	    return;

    	const first = this.data.length ? gridBody.querySelector('tr[data-row]') : null;
    	//fractional height (offsetHeight is rounded: the rounding added up over the rows)
    	const height = first ? first.getBoundingClientRect().height : 0;
    	const cols = this.schema.getVisibleColumnNames().filter((colName) => {
    	    const col = this.schema.column(colName);
    	    return !(col.show === false || col.hide === true);
    	}).length + 1;

    	for (let i = 0; i < missing; i++) {
    	    const tr = document.createElement('tr');
    	    tr.className = 'grid-filler';
    	    tr.setAttribute('aria-hidden', 'true');
    	    tr.style.pointerEvents = 'none';
    	    if (height)
    		tr.style.height = height.toFixed(3) + 'px';
    	    for (let c = 0; c < cols; c++) {
    		const td = document.createElement('td');
    		td.className = 'p-0';
    		//no hover highlight (Bootstrap's table-hover sets this variable on the cells of the hovered row)
    		td.style.setProperty('--bs-table-bg-state', 'transparent');
    		td.innerHTML = '<div class="cell-content px-2 py-1">&nbsp;</div>';
    		tr.appendChild(td);
    	    }
    	    gridBody.appendChild(tr);
    	}

    	//CLD: 2026-10-13T08:03:04+03:00: no records: one row only, the "No data" row, as tall as a full page of rows (page size x a filler row's height; the "No data"
    	//text is taller than a row), so the table keeps its size; when the table is not laid out (hidden: no heights) the fillers stay
    	if (!this.data.length) {
    	    const emptyTr = gridBody.querySelector('tr.grid-empty');
    	    const fillers = gridBody.querySelectorAll('tr.grid-filler');
    	    const total = Math.max(fillers[0].getBoundingClientRect().height * pageSize, emptyTr ? emptyTr.getBoundingClientRect().height : 0);
    	    if (emptyTr && total > 0) {
    		fillers.forEach((tr) => tr.remove());
    		emptyTr.style.height = total.toFixed(3) + 'px';
    	    }
    	}
        }
    }

    return GridView;
})();
