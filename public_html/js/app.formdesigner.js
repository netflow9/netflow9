if (!window.app)
    window.app = {};

/*!
 * a form designer, builds an app.smartform schema
 * { direction, fields } - the forms of the Form trigger and Human task steps (smartform field type `formdesigner`, in the step dialog).
 * Fields: the QSM types (text, textarea, number, date, datetime, select, checkbox, switch, hidden) + design / layout nodes (title, paragraph, divider, columns).
 * Each input has a `key` (its name in the answers; from the label until edited). A condition { key, operator, value } shows a node only when another field
 * matches; its `showIf` is generated from it (the server does the same and drops any other showIf / hideIf / requiredIf).
 * CLD: 2026-10-14T20:17:14+03:00: the condition is the node's `showIf` itself (app.smartform evaluates it; no JS is generated); its operators are those of the field it reads
 * (option `operators`: { type: [ { value, label } ] }, from util\SmartForm::modelFields = the fields' operators, util\Conditions)
 * Build: the palette (click: add at the end, or drag), the canvas (cards rendered by app.smartform; drag to reorder, also into / out of columns), the selected
 * node's settings (an app.smartform). Preview: the form as people will see it, with its conditions and a Check button (validation).
 *
 *   const d = new app.FormDesigner(el, { value: { direction, fields }, readonly: false, onChange: (value) => ... });
 *   d.getValue(); d.setValue(value); d.problem();   // problem(): the first error (a key used twice, ...) or null
 *
 * CLD: 2026-10-13T20:36:03+03:00: d.setStepPanel(el): the settings of the step around the form (user's choice: as the prototype's form settings) - shown in the right panel when no
 * field is selected (clicking the empty canvas, or "Step settings" above a field's settings); the element is moved there, never drawn again; d.select(null)
 * CLD: 2026-10-13T20:45:56+03:00: option `fill`: the designer fills its container's height (the step dialog), the palette, the canvas and the settings each scroll on their own
 * (else each is at most 70% of the window high)
 */
window.app.FormDesigner = (function() {

    const TYPES = {
	text:      { label: 'Text', icon: 'bi-fonts', input: true },
	email:     { label: 'E-mail', icon: 'bi-at', input: true },
	url:       { label: 'Web address', icon: 'bi-link-45deg', input: true },
	textarea:  { label: 'Long text', icon: 'bi-text-paragraph', input: true },
	number:    { label: 'Number', icon: 'bi-123', input: true },
	date:      { label: 'Date', icon: 'bi-calendar3', input: true },
	datetime:  { label: 'Date & time', icon: 'bi-calendar-event', input: true },
	select:    { label: 'Choice', icon: 'bi-ui-radios', input: true },
	checkbox:  { label: 'Checkbox', icon: 'bi-check-square', input: true },
	switch:    { label: 'Switch', icon: 'bi-toggle-on', input: true },
	hidden:    { label: 'Hidden value', icon: 'bi-eye-slash', input: true },
	title:     { label: 'Title', icon: 'bi-type-h1' },
	paragraph: { label: 'Paragraph', icon: 'bi-text-left' },
	divider:   { label: 'Divider', icon: 'bi-hr' },
	columns:   { label: 'Columns', icon: 'bi-layout-split' },
    };
    const WIDTHS = [ { value: '12', label: 'Full' }, { value: '9', label: '3/4' }, { value: '8', label: '2/3' }, { value: '6', label: '1/2' }, { value: '4', label: '1/3' }, { value: '3', label: '1/4' } ];
    const RATIOS = { '6-6': [ 6, 6 ], '4-8': [ 4, 8 ], '8-4': [ 8, 4 ], '3-9': [ 3, 9 ], '9-3': [ 9, 3 ], '4-4-4': [ 4, 4, 4 ] };
    const KEY_RE = /^[A-Za-z_][A-Za-z0-9_]*$/;

    const esc = (s) => window.app.utils.escapeHtml(String(s === undefined || s === null ? '' : s));
    let seq = 0;
    const nid = () => 'fd' + (++seq);

    class FormDesigner {
	constructor(container, options) {
	    this.container = container;
	    //CLD: 2026-10-14T20:17:14+03:00: + operators: { type: [ { value, label } ] }, the operators of each field type (conditions)
	    this.o = Object.assign({ value: null, readonly: false, onChange: null, operators: {} }, options || {});
	    this.mode = 'build';
	    this.selected = null;
	    this.drag = null;
	    this.emitted = null;
	    this.load(this.o.value);
	    this.render();
	}

	// ------------------------------------------------------------------ model

	//a schema in: a copy with designer ids (`_id`); keys taken from the labels until edited (`_autoKey`)
	load(value) {
	    const v = value && typeof value === 'object' ? JSON.parse(JSON.stringify(value)) : {};
	    this.direction = v.direction === 'horizontal' ? 'horizontal' : 'vertical';
	    const prep = (list) => (Array.isArray(list) ? list : []).filter((n) => n && TYPES[n.type]).map((n) => {
		n._id = nid();
		if (n.type === 'columns')
		    n.columns = (Array.isArray(n.columns) ? n.columns : []).slice(0, 3).map((c) => ({ width: c.width || 6, fields: prep(c.fields).filter((f) => f.type !== 'columns') }));
		return n;
	    });
	    this.fields = prep(v.fields);
	    this.emitted = value && typeof value === 'object' ? value : null;
	}

	//the schema out (built once per change): no designer properties
	getValue() {
	    if (!this.emitted)
		this.emitted = this.build();
	    return this.emitted;
	}

	build() {
	    const out = (list) => list.map((n) => {
		const c = {};
		//CLD: 2026-10-14T20:17:14+03:00: the condition stays the node's showIf (an object; was turned into JS here); no hideIf / requiredIf
		Object.keys(n).forEach((k) => { if (!k.startsWith('_') && ![ 'hideIf', 'requiredIf' ].includes(k)) c[k] = n[k]; });
		if (n.type === 'columns')
		    c.columns = n.columns.map((col) => ({ width: col.width, fields: out(col.fields) }));
		return c;
	    });
	    return { direction: this.direction, fields: out(this.fields) };
	}

	setValue(value) {
	    if (value === this.emitted)
		return;
	    this.load(value);
	    this.selected = null;
	    this.render();
	}

	changed() {
	    this.emitted = this.build();
	    this.showProblem();
	    if (this.o.onChange)
		this.o.onChange(this.emitted);
	}

	showProblem() {
	    const el = this.container.querySelector('.fd-problem');
	    if (!el)
		return;
	    const p = this.problem();
	    el.textContent = p || '';
	    el.classList.toggle('d-none', !p);
	}

	//every node: [ node, list it is in, index ]
	all() {
	    const out = [];
	    const walk = (list) => list.forEach((n, i) => {
		out.push([ n, list, i ]);
		if (n.type === 'columns')
		    n.columns.forEach((c) => walk(c.fields));
	    });
	    walk(this.fields);
	    return out;
	}

	find(id) {
	    return this.all().find(([ n ]) => n._id === id) || null;
	}

	//the list a zone stands for: 'root' or '<columns id>:<i>'
	zoneList(zone) {
	    if (zone === 'root')
		return this.fields;
	    const [ id, i ] = zone.split(':');
	    const hit = this.find(id);
	    return hit && hit[0].type === 'columns' && hit[0].columns[+i] ? hit[0].columns[+i].fields : null;
	}

	inputs() {
	    return this.all().map(([ n ]) => n).filter((n) => TYPES[n.type].input);
	}

	//CLD: 2026-10-14T20:17:14+03:00: the operators a condition on field `key` may use: those of its type (option `operators`)
	operatorsOf(key) {
	    const f = this.inputs().find((x) => x.key === key);
	    return (f && this.o.operators[f.type]) || [];
	}

	uniqueKey(base, except) {
	    let key = String(base || 'field').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
	    if (!/^[a-z_]/.test(key))
		key = 'field_' + key;
	    key = key.slice(0, 40) || 'field';
	    const used = new Set(this.inputs().filter((n) => n !== except).map((n) => n.key));
	    let k = key, i = 2;
	    while (used.has(k))
		k = key + '_' + i++;
	    return k;
	}

	create(type) {
	    const n = { _id: nid(), type: type };
	    const t = TYPES[type];
	    if (t.input) {
		n.label = { hidden: 'Hidden value', select: 'Choice', checkbox: 'Checkbox', switch: 'Switch' }[type] || t.label;
		n.key = this.uniqueKey(n.label);
		n._autoKey = true;
	    }
	    if (type === 'select')
		n.choices = [ { value: 'option_1', label: 'Option 1' }, { value: 'option_2', label: 'Option 2' } ];
	    if (type === 'textarea')
		n.rows = 3;
	    if (type === 'hidden')
		n.default = '';
	    if (type === 'title') {
		n.label = 'Section title';
		n.level = 'h5';
	    }
	    if (type === 'paragraph')
		n.text = 'Some text to guide people through the form.';
	    if (type === 'columns')
		n.columns = [ { width: 6, fields: [] }, { width: 6, fields: [] } ];
	    return n;
	}

	add(type, zone, index) {
	    const list = this.zoneList(zone || 'root');
	    if (!list || (type === 'columns' && zone && zone !== 'root'))
		return;
	    const n = this.create(type);
	    list.splice(index === undefined || index === null ? list.length : index, 0, n);
	    this.selected = n._id;
	    this.changed();
	    this.render();
	}

	move(id, zone, index) {
	    const hit = this.find(id);
	    const list = this.zoneList(zone);
	    if (!hit || !list || (hit[0].type === 'columns' && zone !== 'root'))
		return;
	    const [ n, from, i ] = hit;
	    from.splice(i, 1);
	    let at = index === null || index === undefined ? list.length : index;
	    if (from === list && i < at)
		at--;
	    list.splice(at, 0, n);
	    this.changed();
	    this.render();
	}

	remove(id) {
	    const hit = this.find(id);
	    if (!hit)
		return;
	    hit[1].splice(hit[2], 1);
	    //conditions on a removed field go away
	    const keys = new Set(this.inputs().map((n) => n.key));
	    //CLD: 2026-10-14T20:17:14+03:00: the condition is n.showIf (was n.condition)
	    this.all().forEach(([ n ]) => { if (n.showIf && !keys.has(n.showIf.key)) delete n.showIf; });
	    if (this.selected === id || !this.find(this.selected))
		this.selected = null;
	    this.changed();
	    this.render();
	}

	duplicate(id) {
	    const hit = this.find(id);
	    if (!hit)
		return;
	    const copy = JSON.parse(JSON.stringify(hit[0]));
	    const renew = (n) => {
		n._id = nid();
		if (TYPES[n.type].input)
		    n.key = this.uniqueKey(n.key, null);
		if (n.type === 'columns')
		    n.columns.forEach((c) => c.fields.forEach(renew));
	    };
	    hit[1].splice(hit[2] + 1, 0, copy);
	    renew(copy);
	    this.selected = copy._id;
	    this.changed();
	    this.render();
	}

	//the first problem of the form, or null
	problem() {
	    const seen = new Set();
	    for (const n of this.inputs()) {
		if (!n.key || !KEY_RE.test(n.key))
		    return `"${n.label || n.type}": the key must be letters, digits or underscore, not starting with a digit`;
		if (seen.has(n.key))
		    return `The key "${n.key}" is used twice`;
		seen.add(n.key);
		if (n.type === 'select' && !(n.choices || []).some((c) => String(c.value ?? '') !== ''))
		    return `"${n.label || n.key}": add at least one choice`;
	    }
	    for (const [ n ] of this.all()) {
		//CLD: 2026-10-14T20:17:14+03:00: the condition is n.showIf (was n.condition)
		if (n.showIf && !seen.has(n.showIf.key))
		    return `"${n.label || n.type}": its condition uses a field that is not in the form`;
	    }
	    return null;
	}

	// ------------------------------------------------------------------ rendering

	render() {
	    const ro = this.o.readonly;
	    //CLD: 2026-10-13T20:13:36+03:00: focus inside the designer stays inside after drawing it again (else it falls to the page: Escape no longer closed the dialog around)
	    const hadFocus = this.container.contains(document.activeElement);
	    //CLD: 2026-10-13T20:18:33+03:00: the palette is a column on the left (the step dialog is full screen; was a strip of small buttons in the top bar), grouped Fields / Design, buttons
	    //with their label (btn-light + border: the theme's outline-secondary looked disabled)
	    const button = (type) => `<button type="button" class="btn btn-sm btn-light border w-100 text-start mb-1 fd-add" draggable="true" data-type="${type}" title="Click to add at the end, or drag onto the form">
		<i class="bi ${TYPES[type].icon} me-2 text-primary"></i>${esc(TYPES[type].label)}</button>`;
	    const palette = ro ? '' : '<div class="small text-uppercase text-muted fw-semibold mb-2">Fields</div>'
		+ Object.keys(TYPES).filter((t) => TYPES[t].input).map(button).join('')
		+ '<div class="small text-uppercase text-muted fw-semibold mt-3 mb-2">Design</div>'
		+ Object.keys(TYPES).filter((t) => !TYPES[t].input).map(button).join('');
	    //CLD: 2026-10-13T20:45:56+03:00: fill: the columns take the height left, each scrolling (was at most 70vh each)
	    const fill = !!this.o.fill;
	    const scroll = fill ? 'height:100%; overflow:auto' : 'max-height:70vh; overflow:auto';
	    this.container.innerHTML = `
		<div class="fd border rounded ${fill ? 'd-flex flex-column flex-grow-1' : ''}" style="${fill ? 'min-height:0' : ''}">
		    <div class="d-flex flex-wrap gap-1 align-items-center p-2 border-bottom bg-body-tertiary flex-shrink-0">
			<div class="btn-group btn-group-sm me-2" role="group">
			    <button type="button" class="btn ${this.mode === 'build' ? 'btn-primary' : 'btn-outline-primary'} fd-mode" data-mode="build"><i class="bi bi-tools me-1"></i>Build</button>
			    <button type="button" class="btn ${this.mode === 'preview' ? 'btn-primary' : 'btn-outline-primary'} fd-mode" data-mode="preview"><i class="bi bi-play-fill me-1"></i>Preview</button>
			</div>
			<select class="form-select form-select-sm ms-auto fd-direction" style="width:auto" ${ro ? 'disabled' : ''} title="Where the labels are">
			    <option value="vertical" ${this.direction === 'vertical' ? 'selected' : ''}>Labels above</option>
			    <option value="horizontal" ${this.direction === 'horizontal' ? 'selected' : ''}>Labels beside</option>
			</select>
		    </div>
		    <div class="row g-0 ${this.mode === 'build' ? '' : 'd-none'} ${fill ? 'flex-grow-1' : ''}" style="${fill ? 'min-height:0' : ''}">
			<!-- CLD: 2026-10-13T20:12:00+03:00: up to 70% of the window (the step dialog is full screen; was 60%) -->
			${ro ? '' : `<div class="col-md-2 p-2 border-end bg-body-tertiary fd-palette" style="${scroll}">${palette}</div>`}
			<div class="${ro ? 'col-md-8' : 'col-md-6'} p-3 fd-canvas" style="${scroll}; min-height:260px"></div>
			<div class="col-md-4 p-3 border-start fd-props" style="${scroll}"></div>
		    </div>
		    <div class="p-3 fd-preview ${this.mode === 'preview' ? '' : 'd-none'} ${fill ? 'flex-grow-1 overflow-auto' : ''}"></div>
		    <div class="px-3 py-2 border-top small text-danger fd-problem d-none flex-shrink-0"></div>
		</div>`;
	    const root = this.container.firstElementChild;
	    if (hadFocus) {
		root.tabIndex = -1;
		root.focus({ preventScroll: true });
	    }
	    root.querySelectorAll('.fd-mode').forEach((b) => b.addEventListener('click', () => { this.mode = b.dataset.mode; this.render(); }));
	    root.querySelector('.fd-direction').addEventListener('change', (e) => { this.direction = e.target.value; this.changed(); this.render(); });
	    root.querySelectorAll('.fd-add').forEach((b) => {
		b.addEventListener('click', () => this.add(b.dataset.type));
		b.addEventListener('dragstart', (e) => { this.drag = { kind: 'new', type: b.dataset.type }; e.dataTransfer.effectAllowed = 'copy'; e.dataTransfer.setData('text/plain', b.dataset.type); });
		b.addEventListener('dragend', () => this.endDrag());
	    });
	    this.showProblem();
	    if (this.mode === 'preview') {
		this.renderPreview(root.querySelector('.fd-preview'));
		return;
	    }
	    this.canvas = root.querySelector('.fd-canvas');
	    this.canvas.appendChild(this.renderZone(this.fields, 'root'));
	    //CLD: 2026-10-13T20:36:03+03:00: a click on the empty canvas: no field selected (the step's settings)
	    this.canvas.addEventListener('click', (e) => {
		if (!e.target.closest('.fd-item') && this.selected) {
		    this.selected = null;
		    this.render();
		}
	    });
	    this.setupDrop();
	    this.renderProps(root.querySelector('.fd-props'));
	}

	//a list of nodes as cards in a flow row (widths side by side)
	renderZone(list, zone) {
	    const row = document.createElement('div');
	    row.className = 'row g-2 fd-zone';
	    row.dataset.zone = zone;
	    if (!list.length) {
		const empty = document.createElement('div');
		empty.className = 'col-12 fd-empty text-center text-muted small rounded p-4';
		empty.style.border = '2px dashed var(--bs-border-color)';
		//CLD: 2026-10-13T20:26:20+03:00: the palette is on the left now (was a bar above)
		empty.innerHTML = zone === 'root' ? '<i class="bi bi-ui-checks-grid d-block fs-4 mb-1"></i>The form is empty: click or drag a field from the list on the left' : 'Drop fields here';
		row.appendChild(empty);
	    }
	    list.forEach((n) => row.appendChild(this.renderCard(n)));
	    return row;
	}

	renderCard(n) {
	    const t = TYPES[n.type];
	    const col = document.createElement('div');
	    col.className = 'fd-item col-12' + (n.width && n.width < 12 ? ' col-md-' + n.width : '');
	    col.dataset.id = n._id;
	    const selected = n._id === this.selected;
	    const card = document.createElement('div');
	    card.className = 'fd-card rounded p-2 bg-body h-100 ' + (selected ? 'border border-2 border-primary' : 'border');
	    card.draggable = !this.o.readonly;
	    card.style.cursor = this.o.readonly ? 'default' : 'pointer';
	    card.innerHTML = `
		<div class="d-flex align-items-center gap-1 small mb-1">
		    ${this.o.readonly ? '' : '<i class="bi bi-grip-vertical text-muted" style="cursor:grab"></i>'}
		    <span class="badge text-bg-light border"><i class="bi ${t.icon} me-1"></i>${esc(t.label)}</span>
		    ${t.input ? `<code class="small text-truncate">${esc(n.key)}</code>` : ''}
		    ${n.required ? '<span class="badge text-bg-danger-subtle text-danger-emphasis border-0">required</span>' : ''}
		    ${n.showIf ? '<span class="badge text-bg-info-subtle text-info-emphasis" title="Shown only when a condition holds"><i class="bi bi-eye-slash"></i></span>' : ''}
		    ${this.o.readonly ? '' : `<span class="ms-auto text-nowrap">
			<button type="button" class="btn btn-sm btn-link p-0 px-1 text-muted fd-dup" title="Duplicate"><i class="bi bi-copy"></i></button>
			<button type="button" class="btn btn-sm btn-link p-0 px-1 text-danger fd-del" title="Delete"><i class="bi bi-trash"></i></button></span>`}
		</div>`;
	    const body = document.createElement('div');
	    if (n.type === 'columns') {
		const inner = document.createElement('div');
		inner.className = 'row g-2';
		n.columns.forEach((c, i) => {
		    const cc = document.createElement('div');
		    cc.className = 'col-12 col-md-' + (c.width || 6);
		    const zone = this.renderZone(c.fields, n._id + ':' + i);
		    zone.classList.add('rounded', 'p-1', 'bg-body-tertiary');
		    cc.appendChild(zone);
		    inner.appendChild(cc);
		});
		body.appendChild(inner);
	    } else if (n.type === 'hidden') {
		body.className = 'small text-muted';
		body.textContent = 'Not shown; value: ' + (n.default === '' || n.default === undefined ? '(empty)' : n.default);
	    } else {
		//the node as the form will show it (not editable, no condition)
		body.style.pointerEvents = 'none';
		const node = Object.assign({}, n, { editable: false, width: 12 });
		delete node.showIf;
		try {
		    new window.app.smartform(body, { direction: this.direction, size: 'sm', fields: [ node ] }).render();
		} catch (err) {
		    body.textContent = String(err.message || err);
		}
	    }
	    card.appendChild(body);
	    col.appendChild(card);

	    if (!this.o.readonly) {
		card.addEventListener('click', (e) => {
		    if (e.target.closest('.fd-item') !== col)
			return;
		    e.stopPropagation();
		    this.selected = n._id;
		    this.render();
		});
		card.querySelector('.fd-dup').addEventListener('click', (e) => { e.stopPropagation(); this.duplicate(n._id); });
		card.querySelector('.fd-del').addEventListener('click', (e) => { e.stopPropagation(); this.remove(n._id); });
		card.addEventListener('dragstart', (e) => {
		    if (e.target !== card)
			return;
		    e.stopPropagation();
		    this.drag = { kind: 'move', id: n._id, type: n.type };
		    e.dataTransfer.effectAllowed = 'move';
		    e.dataTransfer.setData('text/plain', n._id);
		    setTimeout(() => card.classList.add('opacity-50'), 0);
		});
		card.addEventListener('dragend', () => this.endDrag());
	    }
	    return col;
	}

	// ------------------------------------------------------------------ drag and drop

	setupDrop() {
	    if (this.o.readonly)
		return;
	    const bar = document.createElement('div');
	    bar.className = 'col-12 fd-drop';
	    bar.innerHTML = '<div class="bg-primary rounded" style="height:4px"></div>';
	    this.bar = bar;
	    this.canvas.addEventListener('dragover', (e) => {
		if (!this.drag)
		    return;
		const target = this.dropTarget(e);
		if (!target)
		    return;
		e.preventDefault();
		e.dataTransfer.dropEffect = this.drag.kind === 'new' ? 'copy' : 'move';
		const { zoneEl, before } = target;
		if (before)
		    zoneEl.insertBefore(bar, before);
		else
		    zoneEl.appendChild(bar);
		this.dropAt = target;
	    });
	    this.canvas.addEventListener('drop', (e) => {
		if (!this.drag || !this.dropAt)
		    return;
		e.preventDefault();
		const { zone, index } = this.dropAt;
		const drag = this.drag;
		this.endDrag(true);
		if (drag.kind === 'new')
		    this.add(drag.type, zone, index);
		else
		    this.move(drag.id, zone, index);
	    });
	    this.canvas.addEventListener('dragleave', (e) => {
		if (!this.canvas.contains(e.relatedTarget) && this.bar)
		    this.bar.remove();
	    });
	}

	//where a drop at the pointer goes: the innermost zone (columns: not inside columns, not into itself), before the first card after the pointer
	dropTarget(e) {
	    let zoneEl = e.target.closest('.fd-zone');
	    if (!zoneEl || !this.canvas.contains(zoneEl))
		zoneEl = this.canvas.querySelector('.fd-zone[data-zone="root"]');
	    if (this.drag.type === 'columns' || (this.drag.kind === 'move' && zoneEl.dataset.zone.startsWith(this.drag.id + ':')))
		zoneEl = this.canvas.querySelector('.fd-zone[data-zone="root"]');
	    const items = Array.from(zoneEl.children).filter((c) => c.classList.contains('fd-item') && c.dataset.id !== (this.drag.id || ''));
	    let before = null;
	    for (const item of items) {
		const r = item.getBoundingClientRect();
		if (e.clientY < r.top || (e.clientY <= r.bottom && e.clientX < r.left + r.width / 2)) {
		    before = item;
		    break;
		}
	    }
	    const list = this.zoneList(zoneEl.dataset.zone) || [];
	    const index = before ? list.findIndex((n) => n._id === before.dataset.id) : list.length;
	    return { zoneEl, before, zone: zoneEl.dataset.zone, index: index < 0 ? list.length : index };
	}

	endDrag(keep) {
	    this.drag = null;
	    this.dropAt = null;
	    if (this.bar)
		this.bar.remove();
	    if (!keep && this.canvas)
		this.canvas.querySelectorAll('.fd-card.opacity-50').forEach((c) => c.classList.remove('opacity-50'));
	}

	// ------------------------------------------------------------------ settings of the selected node

	//CLD: 2026-10-13T20:36:03+03:00: the step's settings (an element of the dialog), shown when no field is selected
	setStepPanel(el) {
	    this.stepPanel = el;
	    if (this.mode === 'build')
		this.render();
	}

	select(id) {
	    this.selected = id;
	    this.render();
	}

	renderProps(panel) {
	    const hit = this.selected ? this.find(this.selected) : null;
	    if (!hit && this.stepPanel) {
		panel.innerHTML = '<div class="small text-uppercase text-muted fw-semibold mb-2">Step settings</div>';
		panel.appendChild(this.stepPanel);
		return;
	    }
	    if (!hit) {
		panel.innerHTML = `<div class="text-muted small text-center py-4"><i class="bi bi-hand-index d-block fs-4 mb-1"></i>${this.o.readonly ? 'Read-only' : 'Select a field to change it'}</div>`;
		return;
	    }
	    if (this.stepPanel) {
		const back = document.createElement('button');
		back.type = 'button';
		back.className = 'btn btn-sm btn-link p-0 mb-2 text-decoration-none';
		back.innerHTML = '<i class="bi bi-arrow-left me-1"></i>Step settings';
		back.addEventListener('click', () => this.select(null));
		panel.appendChild(back);
	    }
	    const n = hit[0];
	    const t = TYPES[n.type];
	    const fields = [ { type: 'title', label: t.label, level: 'h6', className: 'mb-0' } ];
	    if (t.input) {
		fields.push({ key: 'key', type: 'text', label: 'Key', required: true, pattern: KEY_RE.source, patternMessage: 'Letters, digits or underscore, not starting with a digit',
		    helpText: 'Its name in the answers' });
		if (n.type !== 'hidden')
		    fields.push({ key: 'label', type: 'text', label: 'Label' });
	    }
	    if (n.type === 'title')
		fields.push({ key: 'label', type: 'text', label: 'Text' }, { key: 'subtitle', type: 'text', label: 'Subtitle' },
		    { key: 'level', type: 'select', label: 'Size', choices: { h4: 'Large', h5: 'Medium', h6: 'Small' } }, { key: 'divider', type: 'switch', label: 'Line under it' });
	    if (n.type === 'paragraph')
		fields.push({ key: 'text', type: 'textarea', label: 'Text', rows: 4 });
	    if (n.type === 'columns')
		fields.push({ key: 'ratio', type: 'select', label: 'Columns', choices: { '6-6': '1/2 + 1/2', '4-8': '1/3 + 2/3', '8-4': '2/3 + 1/3', '3-9': '1/4 + 3/4', '9-3': '3/4 + 1/4', '4-4-4': '1/3 + 1/3 + 1/3' } });
	    //CLD: 2026-10-14T19:54:21+03:00: + url; removed the Kind setting (inputType: text / phone / web address)
	    if ([ 'text', 'email', 'url', 'textarea', 'number' ].includes(n.type))
		fields.push({ key: 'placeholder', type: 'text', label: 'Placeholder' });
	    if (n.type === 'text')
		fields.push({ key: 'maxLength', type: 'number', label: 'At most (characters)', min: 1 },
		    { key: 'pattern', type: 'text', label: 'Pattern (regular expression)' }, { key: 'patternMessage', type: 'text', label: 'Message when it does not match', showIf: '!!data.pattern' });
	    if (n.type === 'textarea')
		fields.push({ key: 'rows', type: 'number', label: 'Rows', min: 1, max: 30 });
	    if (n.type === 'number')
		fields.push({ key: 'min', type: 'number', label: 'Minimum', width: 4 }, { key: 'max', type: 'number', label: 'Maximum', width: 4 }, { key: 'step', type: 'number', label: 'Step', width: 4 });
	    if (n.type === 'select')
		fields.push({ key: 'choices', type: 'editgrid', label: 'Choices', layout: 'rows', addLabel: 'Add choice', emptyText: 'No choices yet', minRows: 1, maxRows: 200,
		    components: [ { key: 'label', type: 'text', placeholder: 'shown', width: 7 }, { key: 'value', type: 'text', placeholder: 'value', width: 5 } ] },
		    { key: 'multiple', type: 'switch', label: 'Several can be chosen' });
	    if (n.type === 'hidden')
		fields.push({ key: 'default', type: 'text', label: 'Value' });
	    if (t.input && n.type !== 'hidden')
		fields.push({ key: 'required', type: 'switch', label: 'Required' }, { key: 'helpText', type: 'text', label: 'Help text' });
	    if (n.type !== 'hidden')
		fields.push({ key: 'width', type: 'select', label: 'Width', choices: WIDTHS });
	    //condition: shown only when another field matches
	    const others = this.inputs().filter((f) => f !== n).map((f) => ({ value: f.key, label: (f.label || f.key) + ' (' + f.key + ')' }));
	    if (n.type !== 'columns' && n.type !== 'hidden' && others.length) {
		fields.push({ type: 'title', label: 'Show only when', level: 'h6', className: 'mt-2 mb-0' },
		    { key: 'condOn', type: 'switch', label: 'A condition decides' },
		    { key: 'condKey', type: 'select', label: 'Field', choices: others, showIf: 'data.condOn' },
		    //CLD: 2026-10-14T20:17:14+03:00: the operators of the field the condition reads (option `operators`, by its type; were one fixed list)
		    { key: 'condOperator', type: 'select', label: 'Operator', choices: this.operatorsOf(n.showIf ? n.showIf.key : (others[0] || {}).value), showIf: 'data.condOn' },
		    //CLD: 2026-10-14T14:08:12+03:00: `in`: the values one per line
		    { key: 'condValue', type: 'text', label: 'Value', showIf: "data.condOn && !['empty', 'notEmpty', 'in'].includes(data.condOperator)" },
		    { key: 'condValues', type: 'textarea', label: 'Values (one per line)', rows: 3, showIf: "data.condOn && data.condOperator === 'in'" });
	    }
	    if (this.o.readonly)
		fields.forEach((f) => { f.editable = false; });

	    const data = Object.assign({}, n, {
		width: String(n.width || 12),
		ratio: n.type === 'columns' ? (Object.keys(RATIOS).find((r) => RATIOS[r].join() === n.columns.map((c) => c.width).join()) || '6-6') : undefined,
		//CLD: 2026-10-14T20:17:14+03:00: from n.showIf (was n.condition)
		condOn: !!n.showIf, condKey: n.showIf ? n.showIf.key : (others[0] || {}).value, condOperator: n.showIf ? n.showIf.operator : 'eq',
		//CLD: 2026-10-14T14:08:12+03:00: a list value (`in`) is edited one per line
		condValue: n.showIf && !Array.isArray(n.showIf.value) ? n.showIf.value : '',
		condValues: n.showIf && Array.isArray(n.showIf.value) ? n.showIf.value.join('\n') : '',
	    });
	    const holder = document.createElement('div');
	    panel.appendChild(holder);
	    const form = new window.app.smartform(holder, { size: 'sm', fields: fields });
	    form.render();
	    form.setData(data);
	    const apply = () => {
		const d = form.getData();
		//CLD: 2026-10-14T20:17:14+03:00: another field for the condition: its operators (the panel again, with an operator of that field)
		const keyChanged = d.condOn && n.showIf && d.condKey !== n.showIf.key;
		this.applyProps(n, d);
		if (keyChanged) {
		    this.render();
		    return;
		}
		//a key that follows the label: shown
		if (TYPES[n.type].input && d.key !== n.key)
		    form.setData({ key: n.key });
	    };
	    panel.addEventListener('input', apply);
	    panel.addEventListener('change', apply);
	    panel.addEventListener('click', (e) => { if (e.target.closest('button')) setTimeout(apply, 0); });
	}

	//the settings form's values back into the node; the card is drawn again
	applyProps(n, d) {
	    const set = (k, v) => { if (v === '' || v === null || v === undefined || v === false) delete n[k]; else n[k] = v; };
	    const t = TYPES[n.type];
	    if (t.input) {
		const key = String(d.key || '').trim();
		if (key !== n.key)
		    n._autoKey = false;
		//the key follows the label until it is edited
		if (n._autoKey && d.label !== n.label && n.type !== 'hidden')
		    n.key = this.uniqueKey(d.label, n);
		else
		    n.key = key;
	    }
	    //CLD: 2026-10-14T19:54:21+03:00: no inputType (removed)
	    [ 'label', 'subtitle', 'level', 'text', 'placeholder', 'pattern', 'patternMessage', 'helpText' ].forEach((k) => { if (k in d) set(k, d[k] === undefined ? '' : String(d[k])); });
	    [ 'maxLength', 'rows', 'min', 'max', 'step' ].forEach((k) => { if (k in d) set(k, d[k] === null || d[k] === '' ? '' : Number(d[k])); });
	    [ 'required', 'multiple', 'divider' ].forEach((k) => { if (k in d) set(k, !!d[k]); });
	    if ('default' in d)
		n.default = String(d.default ?? '');
	    if ('choices' in d && Array.isArray(d.choices))
		n.choices = d.choices.map((c) => ({ value: String(c.value ?? '').trim() || String(c.label ?? '').trim(), label: String(c.label ?? '').trim() || String(c.value ?? '').trim() }));
	    if ('width' in d && n.type !== 'hidden')
		set('width', Number(d.width) < 12 ? Number(d.width) : '');
	    if (n.type === 'columns' && RATIOS[d.ratio]) {
		const widths = RATIOS[d.ratio];
		while (n.columns.length < widths.length)
		    n.columns.push({ width: 4, fields: [] });
		//fewer columns: the last one's fields join the one before
		while (n.columns.length > widths.length) {
		    const last = n.columns.pop();
		    n.columns[n.columns.length - 1].fields.push(...last.fields);
		}
		n.columns.forEach((c, i) => { c.width = widths[i]; });
	    }
	    //CLD: 2026-10-14T20:17:14+03:00: an operator of the field the condition reads (its first one when the chosen one does not apply)
	    const ops = this.operatorsOf(d.condKey).map((o) => o.value);
	    const op = ops.includes(d.condOperator) ? d.condOperator : (ops[0] || 'eq');
	    if (d.condOn && d.condKey)
		//CLD: 2026-10-14T14:08:12+03:00: `in`: the lines as a list (blanks and repeats dropped)
		//CLD: 2026-10-14T20:17:14+03:00: the condition is n.showIf (was n.condition)
		n.showIf = { key: d.condKey, operator: op, value: op === 'in'
		    ? [ ...new Set(String(d.condValues ?? '').split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== '')) ]
		    : ([ 'empty', 'notEmpty' ].includes(op) ? '' : String(d.condValue ?? '')) };
	    else
		delete n.showIf;
	    this.changed();
	    //the card again (the settings panel stays: typing goes on)
	    const old = this.canvas && this.canvas.querySelector('.fd-item[data-id="' + n._id + '"]');
	    if (old)
		old.replaceWith(this.renderCard(n));
	    if (n.type === 'columns' && 'ratio' in d)
		this.render();
	}

	// ------------------------------------------------------------------ preview

	renderPreview(el) {
	    const value = this.getValue();
	    if (!value.fields.length) {
		el.innerHTML = '<div class="text-muted text-center py-4">The form is empty.</div>';
		return;
	    }
	    el.innerHTML = '<div class="fd-preview-form"></div><div class="d-flex gap-2 align-items-center border-top pt-3"><button type="button" class="btn btn-sm btn-primary fd-check"><i class="bi bi-check2-square me-1"></i>Check</button><span class="small fd-check-result"></span></div>';
	    const form = new window.app.smartform(el.querySelector('.fd-preview-form'), value);
	    form.render();
	    el.querySelector('.fd-check').addEventListener('click', () => {
		const ok = form.validate();
		const r = el.querySelector('.fd-check-result');
		r.className = 'small fd-check-result ' + (ok ? 'text-success' : 'text-danger');
		r.textContent = ok ? 'Valid. Answers: ' + JSON.stringify(form.getData()) : 'Some fields need attention.';
	    });
	}
    }

    FormDesigner.TYPES = TYPES;
    return FormDesigner;
})();
