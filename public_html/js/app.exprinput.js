if (!window.app)
    window.app = {};

/*!
 * CLD: 2026-10-11T22:38:36+03:00: new component: live help for a text input / textarea that may hold expressions between {{ and }} (the J language).
 * The server does the work: each change posts the text (debounced) and gets its problems back; typing a name, a `.` or Ctrl+Space inside {{ }} also
 * posts the text before the cursor and gets suggestions, shown in a menu at the cursor (arrows, Enter / Tab, Escape).
 *
 *   new app.ExprInput(inputEl, { url, params: () => ({ ... }) })
 *
 * POST url: text, before (only when suggestions are wanted), ...params()  ->  { problems: [ { severity, message, start, end, line, column } ],
 *   completion: { replace, items: [ { label, kind, detail } ] } | null }
 * The problems are listed below the input (line:column, message); nothing blocks the form.
 *
 * CLD: 2026-10-11T23:02:07+03:00: options.mode `name`: a field holding a variable name (no {{ }}): suggestions of the available variables as the user types or focuses it,
 * options.usage `read` also warns about a name no step before sets (POST adds mode, usage)
 *
 * CLD: 2026-10-13T12:17:47+03:00: options.syntax (POST `syntax`): text (default: text with any {{ }} parts) | reference (only {{ variable }} / {{ variable.field }}): the server checks
 * and suggests in it; a reference field is checked whatever it holds (plain text is an error there)
 */
window.app.ExprInput = (function() {

    const KIND_ICONS = { variable: 'bi-braces', global: 'bi-box', property: 'bi-dot', method: 'bi-lightning', literal: 'bi-type' };

    class ExprInput {
	constructor(input, options) {
	    this.input = input;
	    this.url = options.url;
	    this.params = options.params || (() => ({}));
	    this.mode = options.mode || 'expression';
	    this.usage = options.usage || '';
	    this.syntax = options.syntax || 'text';
	    this.panel = null;
	    this.menu = null;
	    this.items = [];
	    this.active = 0;
	    this.replace = 0;
	    //per kind of request (check, complete): the latest one only
	    this.seq = { check: 0, complete: 0 };
	    this.controllers = { check: null, complete: null };
	    this.checkTimer = null;
	    this.completeTimer = null;
	    //where the `)` added after an accepted method is (typing it there steps over it)
	    this.autoParen = null;

	    input.setAttribute('spellcheck', 'false');
	    input.setAttribute('autocomplete', 'off');
	    input.addEventListener('input', (e) => this._onInput(e));
	    input.addEventListener('keydown', (e) => this._onKeydown(e));
	    input.addEventListener('blur', () => setTimeout(() => this.closeMenu(), 150));
	    input.addEventListener('click', () => this.closeMenu());
	    //a name field offers the variables as soon as it is entered
	    if (this.mode === 'name')
		input.addEventListener('focus', () => setTimeout(() => { if (document.activeElement === input) this.complete(); }, 100));

	    //a value with expressions (a name that is read) is checked once the input is on the page
	    if (this._wantsCheck())
		setTimeout(() => this.check(), 50);
	}

	/* ---------- events ---------- */

	_onInput(e) {
	    clearTimeout(this.checkTimer);
	    this.checkTimer = setTimeout(() => this.check(), 350);

	    //typing a name character or `.` inside {{ }} (in a name field: any name character): suggestions; else the menu goes away
	    const ch = e.data || '';
	    if (this.mode === 'name' ? /^\w$/.test(ch) : (this._inExpression() && /^[\w$.]$/.test(ch))) {
		clearTimeout(this.completeTimer);
		this.completeTimer = setTimeout(() => this.complete(), 150);
	    } else if (e.inputType && e.inputType.startsWith('delete') && this.menu) {
		clearTimeout(this.completeTimer);
		this.completeTimer = setTimeout(() => this.complete(), 150);
	    } else {
		this.closeMenu();
	    }
	}

	_onKeydown(e) {
	    //typing `)` right before the `)` an accepted method added: step over it
	    if (e.key === ')' && this.autoParen !== null && this.input.selectionStart === this.autoParen && this.input.selectionEnd === this.autoParen && this.input.value[this.autoParen] === ')') {
		e.preventDefault();
		this.input.setSelectionRange(this.autoParen + 1, this.autoParen + 1);
		this.autoParen = null;
		return;
	    }
	    if ((e.ctrlKey || e.metaKey) && e.key === ' ') {
		e.preventDefault();
		this.complete();
		return;
	    }
	    if (!this.menu)
		return;
	    switch (e.key) {
		case 'ArrowDown':
		    e.preventDefault();
		    this._setActive(this.active + 1);
		    break;
		case 'ArrowUp':
		    e.preventDefault();
		    this._setActive(this.active - 1);
		    break;
		case 'Enter':
		case 'Tab':
		    e.preventDefault();
		    e.stopPropagation();
		    this.accept(this.items[this.active]);
		    break;
		case 'Escape':
		    e.preventDefault();
		    e.stopPropagation();
		    this.closeMenu();
		    break;
	    }
	}

	//CLD: 2026-10-13T12:17:47+03:00: whether the value is checked on the server: a name read (non-empty); a reference (non-empty: plain text is an error); text with {{
	_wantsCheck() {
	    const v = this.input.value;
	    if (this.mode === 'name')
		return this.usage === 'read' && v !== '';
	    //CLD: 2026-10-13T12:43:20+03:00: + syntax value (one {{ }}): checked whatever it holds, as a reference
	    return this.syntax !== 'text' ? v.trim() !== '' : v.includes('{{');
	}

	//the cursor is after an unclosed {{ (a rough test; the server decides)
	//CLD: 2026-10-13T15:32:41+03:00: a script (syntax script) is code everywhere
	_inExpression() {
	    if (this.syntax === 'script')
		return true;
	    const before = this.input.value.slice(0, this.input.selectionStart);
	    return before.lastIndexOf('{{') > before.lastIndexOf('}}');
	}

	/* ---------- server ---------- */

	async _post(withBefore) {
	    if (!this.url)
		return null;
	    const kind = withBefore ? 'complete' : 'check';
	    if (this.controllers[kind])
		this.controllers[kind].abort();
	    const controller = this.controllers[kind] = new AbortController();
	    const seq = ++this.seq[kind];

	    const fields = { text: this.input.value };
	    if (this.mode === 'name') {
		fields.mode = 'name';
		fields.usage = this.usage;
	    } else {
		fields.syntax = this.syntax;
	    }
	    if (withBefore)
		fields.before = this.input.value.slice(0, this.input.selectionStart);
	    const resp = await ExprInput.request(this.url, Object.assign(fields, this.params() || {}), controller.signal);
	    return seq === this.seq[kind] ? resp : null;
	}

	//CLD: 2026-10-13T15:32:41+03:00: one request to the live-help server (also used by app.CodeEditor): POST fields -> the JSON answer, or null (failed, aborted)
	static async request(url, fields, signal) {
	    const body = new URLSearchParams();
	    Object.keys(fields).forEach((k) => body.set(k, fields[k]));
	    try {
		const res = await fetch(url, {
		    method: 'POST',
		    body: body,
		    credentials: 'same-origin',
		    signal: signal,
		    //X-Requested-With: the server reads the CSRF token from the header only for AJAX / pjax requests
		    headers: { 'X-Csrf-Token': document.querySelector('meta[name=csrf-token]')?.content || '', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
		});
		if (!res.ok)
		    return null;
		return await res.json();
	    } catch (err) {
		return null;
	    }
	}

	async check() {
	    if (!document.body.contains(this.input))
		return;
	    if (!this._wantsCheck()) {
		this.showProblems([]);
		return;
	    }
	    const resp = await this._post(false);
	    if (resp)
		this.showProblems(resp.problems || []);
	}

	async complete() {
	    if (!document.body.contains(this.input))
		return;
	    //the answer is for this text and cursor only (typing on meanwhile makes it stale)
	    const value = this.input.value, pos = this.input.selectionStart;
	    const resp = await this._post(true);
	    if (!resp || this.input.value !== value || this.input.selectionStart !== pos)
		return;
	    this.showProblems(resp.problems || []);
	    const c = resp.completion;
	    if (c && c.items && c.items.length && document.activeElement === this.input)
		this.openMenu(c);
	    else
		this.closeMenu();
	}

	/* ---------- problems ---------- */

	showProblems(problems) {
	    if (!problems.length) {
		if (this.panel)
		    this.panel.innerHTML = '';
		return;
	    }
	    if (!this.panel || !this.panel.isConnected) {
		this.panel = document.createElement('div');
		this.panel.className = 'expr-problems small mt-1';
		this.input.insertAdjacentElement('afterend', this.panel);
	    }
	    const esc = app.utils.escapeHtml;
	    //in a table cell (e.g. an edit grid row) only the icons, the message as their tooltip
	    //CLD: 2026-10-13T09:52:24+03:00: also in a row of an edit grid with layout `rows` (.edit-grid-row)
	    const compact = !!this.input.closest('td, .edit-grid-row');
	    this.panel.innerHTML = problems.map((p) => {
		const icon = p.severity === 'error' ? 'bi-x-circle text-danger' : 'bi-exclamation-triangle text-warning';
		//a name field: no position (the whole value)
		const where = this.mode === 'name' ? '' : (this.input.tagName === 'TEXTAREA' ? `${p.line}:${p.column}` : `col ${p.column}`);
		if (compact)
		    return `<i class='bi ${icon} me-1' role='button' title="${esc(where + ': ' + p.message)}" data-start='${p.start}' data-end='${p.end}'></i>`;
		return `<div class='d-flex gap-1 align-items-baseline' role='button' data-start='${p.start}' data-end='${p.end}'><i class='bi ${icon}'></i><span class='text-muted text-nowrap'>${esc(where)}</span><span>${esc(p.message)}</span></div>`;
	    }).join('');
	    //a click selects the place of the problem
	    this.panel.querySelectorAll('[data-start]').forEach((el) => el.addEventListener('mousedown', (e) => {
		e.preventDefault();
		this.input.focus();
		this.input.setSelectionRange(+el.dataset.start, Math.max(+el.dataset.end, +el.dataset.start));
	    }));
	}

	/* ---------- suggestions ---------- */

	openMenu(completion) {
	    this.items = completion.items;
	    this.replace = completion.replace || 0;
	    this.active = 0;
	    if (!this.menu) {
		this.menu = document.createElement('ul');
		this.menu.className = 'dropdown-menu show shadow-sm py-1 small expr-menu';
		this.menu.style.position = 'fixed';
		this.menu.style.zIndex = 2000;
		this.menu.style.maxHeight = '16rem';
		this.menu.style.overflowY = 'auto';
		this.menu.style.minWidth = '14rem';
		this.menu.addEventListener('mousedown', (e) => e.preventDefault());
		document.body.appendChild(this.menu);
	    }
	    const esc = app.utils.escapeHtml;
	    this.menu.innerHTML = this.items.map((it, i) => `
		<li><a href='#' class='dropdown-item d-flex gap-2 align-items-baseline py-1' data-index='${i}'>
		    <i class='bi ${KIND_ICONS[it.kind] || 'bi-dot'} text-muted'></i>
		    <span class='font-monospace'>${esc(it.label)}</span>
		    <span class='ms-auto ps-3 text-muted text-truncate' style='max-width: 18rem'>${esc(it.detail || '')}</span>
		</a></li>`).join('');
	    this.menu.querySelectorAll('[data-index]').forEach((a) => a.addEventListener('click', (e) => {
		e.preventDefault();
		this.accept(this.items[+a.dataset.index]);
	    }));
	    this._setActive(0);
	    this._position();
	}

	closeMenu() {
	    if (this.menu) {
		this.menu.remove();
		this.menu = null;
	    }
	    this.items = [];
	}

	_setActive(i) {
	    if (!this.menu || !this.items.length)
		return;
	    this.active = (i + this.items.length) % this.items.length;
	    this.menu.querySelectorAll('.dropdown-item').forEach((a, k) => a.classList.toggle('active', k === this.active));
	    const el = this.menu.querySelector('.dropdown-item.active');
	    if (el)
		el.scrollIntoView({ block: 'nearest' });
	}

	//the chosen item replaces the name being typed; a method gets "()" with the cursor inside
	accept(item) {
	    if (!item)
		return;
	    const input = this.input;
	    const pos = input.selectionStart;
	    const start = Math.max(0, pos - this.replace);
	    const insert = item.label + (item.kind === 'method' ? '()' : '');
	    input.setRangeText(insert, start, pos, 'end');
	    this.autoParen = null;
	    if (item.kind === 'method') {
		input.setSelectionRange(start + insert.length - 1, start + insert.length - 1);
		this.autoParen = start + insert.length - 1;
	    }
	    input.dispatchEvent(new Event('input', { bubbles: true }));
	    this.closeMenu();
	    input.focus();
	}

	//below the cursor (a hidden copy of the input measures where the cursor is)
	_position() {
	    const input = this.input;
	    const rect = input.getBoundingClientRect();
	    const caret = caretOffset(input, input.selectionStart);
	    let left = rect.left + caret.left - input.scrollLeft;
	    let top = rect.top + caret.top - input.scrollTop + caret.height + 2;
	    const w = this.menu.offsetWidth, h = this.menu.offsetHeight;
	    if (left + w > window.innerWidth - 8)
		left = Math.max(8, window.innerWidth - 8 - w);
	    if (top + h > window.innerHeight - 8)
		top = Math.max(8, rect.top + caret.top - input.scrollTop - h - 2);
	    this.menu.style.left = left + 'px';
	    this.menu.style.top = top + 'px';
	}
    }

    //the cursor's offset in the input's box: a hidden div with the same styles holds the text before it, and a marker
    function caretOffset(input, pos) {
	const style = window.getComputedStyle(input);
	const div = document.createElement('div');
	[ 'boxSizing', 'width', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth',
	  'borderLeftWidth', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'lineHeight', 'textTransform', 'wordSpacing', 'tabSize' ]
	    .forEach((p) => { div.style[p] = style[p]; });
	div.style.position = 'absolute';
	div.style.visibility = 'hidden';
	div.style.top = '0';
	div.style.left = '-9999px';
	div.style.whiteSpace = input.tagName === 'TEXTAREA' ? 'pre-wrap' : 'pre';
	div.style.wordWrap = 'break-word';
	div.style.overflow = 'hidden';
	div.textContent = input.value.slice(0, pos);
	const marker = document.createElement('span');
	marker.textContent = '​';
	div.appendChild(marker);
	document.body.appendChild(div);
	const result = { left: marker.offsetLeft, top: marker.offsetTop, height: marker.offsetHeight || parseFloat(style.lineHeight) || 16 };
	div.remove();
	return result;
    }

    return ExprInput;
})();
