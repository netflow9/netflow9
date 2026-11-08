if (!window.app)
    window.app = {};

//CLD: 2026-11-02T15:46:06+02:00: new: the designer's Tests panel, a class as app.WfExecutions / app.WfDebug
//
//   const tests = new app.WfTests(panel, options);     (the panel element: .tests-panel, or its selector)
//
//the workflow's test cases (left) and, on the right, the results of a test run (pass / fail per case and per check, tokens, cost, the difference from the run before it, the last runs in a list) or the case being edited.
//Run tests runs the saved draft in the daemon (EvalController), refreshed every 2 s while one runs. The top-bar button (.btn-tests-toggle) toggles it. options: urls (data, detail, save, del, start, stop, required), whenSaved(fn),
//onOpen() / onClose() (the page: one bottom panel at a time), onShowExecution(execution, trace). Methods: open(), close(), toggle(), isOpen(), refresh(), editCase(case), destroy()
window.app.WfTests = (function() {
    let current = null;

    const csrf = () => $('meta[name=csrf-token]').attr('content');
    const toast = (type, title, body) => app.utils.toast({ type: type, title: app.utils.escapeHtml(title), body: app.utils.escapeHtml(body), delay: 5000 });
    const NS = '.wftests';
    const active = (state) => state === 'queued' || state === 'running';
    const muted = (html) => `<span style='color: var(--rail22-debug-muted)'>${html}</span>`;

    function runBadge(state) {
	const colors = { passed: 'success', failed: 'danger', error: 'danger', running: 'primary', queued: 'info', stopped: 'secondary' };
	return `<span class='badge text-bg-${colors[state] || 'secondary'}'>${app.utils.escapeHtml(state)}</span>`;
    }

    function caseBadge(state) {
	const shown = { pass: [ 'success', 'pass' ], fail: [ 'danger', 'fail' ], error: [ 'danger', 'could not run' ], stopped: [ 'secondary', 'stopped' ] }[state] || [ 'secondary', state ];
	return `<span class='badge text-bg-${shown[0]}'>${app.utils.escapeHtml(shown[1])}</span>`;
    }

    function costText(cost) {
	const parts = [];
	if (cost && cost.usd !== null && cost.usd !== undefined)
	    parts.push('$' + Number(cost.usd).toFixed(cost.usd > 0 && cost.usd < 0.01 ? 4 : 2));
	if (cost && cost.credits !== null && cost.credits !== undefined)
	    parts.push(cost.credits + ' credit(s)');
	return parts.join(', ');
    }

    const tokensText = (t) => Number((t && t.input) || 0).toLocaleString() + ' + ' + Number((t && t.output) || 0).toLocaleString();
    const signed = (n) => (n > 0 ? '+' : '') + Number(n).toLocaleString();

    function changeText(d) {
	if (!d)
	    return '';
	const shown = { new: [ 'info', 'new' ], fixed: [ 'success', 'fixed' ], broken: [ 'danger', 'broken' ] }[d.change];
	const parts = [];
	if (shown)
	    parts.push(`<span class='badge text-bg-${shown[0]}'>${shown[1]}</span>`);
	if (d.tokens)
	    parts.push(app.utils.escapeHtml(signed(d.tokens) + ' tokens'));
	if (d.cost_usd)
	    parts.push(app.utils.escapeHtml((d.cost_usd > 0 ? '+$' : '-$') + Math.abs(d.cost_usd).toFixed(4)));
	if (d.answer_changed)
	    parts.push('answer changed');
	return parts.join(', ') || 'no change';
    }

    class WfTests {
	constructor(panel, options) {
	    if (current)
		current.destroy();
	    current = this;

	    this.el = typeof panel === 'string' ? document.querySelector(panel) : panel;
	    this.o = Object.assign({ urls: {}, whenSaved: (fn) => fn(), onOpen: () => {}, onClose: () => {}, onShowExecution: () => {} }, options || {});
	    this.data = null;
	    this.runId = null;
	    this.detail = null;
	    this.editing = null;
	    this.openRows = new Set();
	    this.timer = null;
	    this.askDelete = false;
	    this.bound = [];

	    this.on($('.btn-tests-toggle'), 'click', (e) => {
		e.preventDefault();
		this.toggle();
	    });
	    if (!this.el)
		return;
	    const $p = $(this.el);
	    const click = (selector, fn) => this.on($p, 'click', (e) => {
		e.preventDefault();
		fn(e.currentTarget, e);
	    }, selector);
	    click('.btn-tests-close', () => this.close());
	    click('.btn-tests-refresh', () => this.refresh());
	    click('.btn-tests-run', () => this.start());
	    click('.btn-tests-stop', () => this.stop());
	    click('.btn-tests-new', () => this.editCase({ name: '', vars: {}, tools: 'mock', mocks: [], checks: [], steps: [], notes: [] }));
	    click('.tests-case', (t) => {
		const c = (this.data.cases || []).find((x) => x.id === t.dataset.id);
		if (c)
		    this.editCase(c);
	    });
	    click('.tests-row', (t) => {
		const id = t.dataset.case;
		if (this.openRows.has(id))
		    this.openRows.delete(id);
		else
		    this.openRows.add(id);
		this.renderRight();
	    });
	    click('.tests-trace', (t, e) => {
		e.stopPropagation();
		this.o.onShowExecution(t.dataset.execution, t.dataset.trace || '');
	    });
	    click('.btn-case-save', () => this.saveCase());
	    click('.btn-case-cancel', () => {
		this.editing = null;
		this.renderRight();
	    });
	    click('.btn-case-delete', () => {
		this.askDelete = true;
		this.renderRight();
	    });
	    click('.btn-case-delete-no', () => {
		this.askDelete = false;
		this.renderRight();
	    });
	    click('.btn-case-delete-yes', () => this.deleteCase());
	    click('.btn-check-remove', (t) => {
		this.readForm();
		this.editing.checks.splice(Number(t.dataset.i), 1);
		this.renderRight();
	    });
	    click('.btn-check-arg', (t) => {
		this.readForm();
		const c = this.editing.checks[Number(t.dataset.i)];
		c.args = (c.args || []).concat([ { path: '', op: 'equals', value: '' } ]);
		this.renderRight();
	    });
	    click('.btn-arg-remove', (t) => {
		this.readForm();
		this.editing.checks[Number(t.dataset.i)].args.splice(Number(t.dataset.a), 1);
		this.renderRight();
	    });
	    this.on($p, 'change', (e) => {
		this.readForm();
		const type = e.currentTarget.value;
		e.currentTarget.value = '';
		if (type) {
		    this.editing.checks.push({ type: type, path: '', op: 'equals', value: '', tool: '', args: [], text: '', rubric: '' });
		    this.renderRight();
		}
	    }, '.tests-add-check');
	    this.on($p, 'change', (e) => {
		this.runId = e.currentTarget.value;
		this.detail = null;
		this.editing = null;
		this.loadDetail();
	    }, '.tests-run-select');
	    this.on($p, 'change', (e) => this.setRequired(e.currentTarget.checked), '.tests-required');
	}

	on($el, type, fn, selector) {
	    if (selector)
		$el.on(type + NS, selector, fn);
	    else
		$el.on(type + NS, fn);
	    this.bound.push($el);
	}

	destroy() {
	    clearInterval(this.timer);
	    this.bound.splice(0).forEach(($el) => $el.off(NS));
	    if (current === this)
		current = null;
	}

	find(selector) {
	    return this.el.querySelector(selector);
	}

	isOpen() {
	    return !!this.el && !this.el.classList.contains('d-none');
	}

	toggle() {
	    if (this.isOpen())
		this.close();
	    else
		this.open();
	}

	open() {
	    if (!this.el)
		return;
	    this.o.onOpen();
	    if (this.el.classList.contains('d-none')) {
		this.el.classList.remove('d-none');
		if (this.el.offsetHeight < 120)
		    this.el.style.height = '360px';
	    }
	    this.refresh();
	    if (!this.timer)
		this.timer = setInterval(() => this.tick(), 2000);
	}

	close() {
	    if (this.el)
		this.el.classList.add('d-none');
	    clearInterval(this.timer);
	    this.timer = null;
	    this.o.onClose();
	}

	//while a run is on
	tick() {
	    const latest = this.data && (this.data.runs || [])[0];
	    if (this.isOpen() && latest && active(latest.state))
		this.refresh();
	}

	refresh() {
	    return $.getJSON(this.o.urls.data).done((data) => {
		this.data = data;
		const runs = data.runs || [];
		//the newest while one runs, or when none is chosen; a run chosen that is gone: the newest
		if (!runs.some((r) => r.id === this.runId) || (runs[0] && active(runs[0].state)))
		    this.runId = runs[0] ? runs[0].id : null;
		this.renderTop();
		this.renderCases();
		this.loadDetail();
	    });
	}

	loadDetail() {
	    if (!this.runId) {
		this.detail = null;
		this.renderRight();
		return;
	    }
	    const url = this.o.urls.detail + (this.o.urls.detail.includes('?') ? '&' : '?') + 'run=' + encodeURIComponent(this.runId);
	    $.getJSON(url).done((resp) => {
		this.detail = resp;
		this.renderRight();
	    });
	}

	renderTop() {
	    const d = this.data;
	    const latest = (d.runs || [])[0];
	    const running = latest && active(latest.state);
	    const box = this.find('.tests-actions');
	    box.innerHTML = `
		<div class='form-check form-switch mb-0 me-2 small' title='${app.utils.escapeHtml(d.gate.message)}'>
		    <input class='form-check-input tests-required' type='checkbox' id='tests-required' ${d.required ? 'checked' : ''} ${d.canPublish ? '' : 'disabled'}>
		    <label class='form-check-label' for='tests-required'>Tests must pass to publish</label>
		</div>
		${d.canEdit && running ? `<button type='button' class='btn btn-sm btn-outline-danger py-0 btn-tests-stop'><i class='bi bi-stop-circle me-1'></i>Stop</button>` : ''}
		${d.canEdit && !running ? `<button type='button' class='btn btn-sm btn-success py-0 btn-tests-run' ${(d.cases || []).length ? '' : 'disabled'}><i class='bi bi-play-fill me-1'></i>Run tests</button>` : ''}`;
	}

	renderCases() {
	    const d = this.data;
	    const box = this.find('.tests-cases');
	    const results = {};
	    ((this.detail && this.detail.run && this.detail.run.cases) || []).forEach((c) => results[c.case] = c.state);
	    const rows = (d.cases || []).map((c) => `
		<div class='tests-case exec-row d-flex align-items-center gap-2 px-1 rounded${this.editing && this.editing.id === c.id ? ' exec-selected' : ''}' data-id='${app.utils.escapeHtml(c.id)}' style='cursor: pointer'>
		    ${results[c.id] ? caseBadge(results[c.id]) : ''}<span class='text-truncate' title='${app.utils.escapeHtml(c.name)}'>${app.utils.escapeHtml(c.name)}</span>
		    <span class='ms-auto flex-shrink-0'>${muted(app.utils.escapeHtml((c.checks || []).length + ' check(s)'))}</span>
		</div>`).join('');
	    box.innerHTML = `<div class='debug-panel-title mb-1 d-flex align-items-center'>Test cases${d.canEdit ? `<button type='button' class='debug-icon-btn btn-tests-new ms-auto' title='Write a test case by hand'><i class='bi bi-plus-lg'></i></button>` : ''}</div>`
		+ (rows || `<div>${muted('None yet: open an execution and use Save as test case, or write one with +')}</div>`);
	}

	renderRight() {
	    const box = this.find('.tests-detail');
	    if (!box || !this.data)
		return;
	    this.renderCases();
	    if (this.editing) {
		//what is being typed stays: the 2 s refresh does not come here while editing
		box.innerHTML = this.editorHtml();
		return;
	    }
	    const top = box.scrollTop;
	    box.innerHTML = this.resultsHtml();
	    box.scrollTop = top;
	}

	/* ---------------- results ---------------- */
	resultsHtml() {
	    const runs = this.data.runs || [];
	    if (!runs.length)
		return muted('No test runs yet');
	    const options = runs.map((r) => `<option value='${app.utils.escapeHtml(r.id)}' ${r.id === this.runId ? 'selected' : ''}>${app.utils.escapeHtml(r.created || '')} - ${app.utils.escapeHtml(r.state)}${r.totals ? ` (${r.totals.passed}/${r.totals.cases})` : ''}</option>`).join('');
	    const select = `<select class='form-select form-select-sm w-auto tests-run-select' aria-label='Test run'>${options}</select>`;
	    if (!this.detail)
		return select;
	    const run = this.detail.run;
	    const diff = this.detail.diff || {};
	    const changes = diff.cases || {};
	    const rows = (run.cases || []).map((c) => {
		const failed = (c.checks || []).filter((k) => !k.pass).length;
		const open = this.openRows.has(c.case);
		const checks = (c.checks || []).map((k) => {
		    const moved = ((changes[c.case] || {}).checks || []).find((x) => x.label === k.label);
		    return `<div class='d-flex gap-2'><i class='bi ${k.pass ? 'bi-check-lg text-success' : 'bi-x-lg text-danger'}'></i><span>${app.utils.escapeHtml(k.label)}${k.detail ? ' ' + muted('- ' + app.utils.escapeHtml(k.detail)) : ''}${moved ? ` <span class='badge text-bg-${moved.now ? 'success' : 'danger'}'>${moved.now ? 'fixed' : 'broken'}</span>` : ''}</span></div>`;
		}).join('');
		const more = !open ? '' : `<tr><td colspan='6' class='small'>
		    ${c.error ? `<div class='text-danger mb-1'>${app.utils.escapeHtml(c.error)}</div>` : ''}
		    ${checks || muted('No checks: the run only has to finish')}
		    ${(c.notes || []).map((n) => `<div class='mt-1'>${muted('<i class="bi bi-info-circle me-1"></i>' + app.utils.escapeHtml(n))}</div>`).join('')}
		    ${c.answer ? `<div class='debug-panel-title mt-2 mb-1'>Answer</div><pre class='mb-1 small' style='color: inherit; white-space: pre-wrap'>${app.utils.escapeHtml(c.answer)}</pre>` : ''}
		    ${c.execution ? `<a href='#' class='tests-trace small' data-execution='${app.utils.escapeHtml(c.execution)}' data-trace='${app.utils.escapeHtml(c.trace || '')}'>Open its execution</a>` : ''}
		</td></tr>`;
		return `<tr class='tests-row' data-case='${app.utils.escapeHtml(c.case)}' style='cursor: pointer'>
		    <td><i class='bi bi-chevron-${open ? 'down' : 'right'} me-1'></i>${app.utils.escapeHtml(c.name)}</td>
		    <td>${caseBadge(c.state)}</td>
		    <td class='text-nowrap'>${(c.checks || []).length - failed} / ${(c.checks || []).length}</td>
		    <td class='text-nowrap'>${app.utils.escapeHtml(tokensText(c.tokens))}</td>
		    <td class='text-nowrap'>${app.utils.escapeHtml(costText(c.cost))}</td>
		    <td>${changeText(changes[c.case])}</td>
		</tr>${more}`;
	    }).join('');
	    const t = run.totals || {};
	    const removed = (diff.removed || []).length ? `<div class='small mt-1'>${muted('Not in this run: ' + app.utils.escapeHtml(diff.removed.join(', ')))}</div>` : '';
	    return `<div class='d-flex flex-wrap align-items-center gap-2 mb-2'>${select}${runBadge(run.state)}
		    <span class='small'>${muted(app.utils.escapeHtml(`${t.passed || 0} of ${t.cases || 0} passed, ${tokensText(t.tokens)} tokens${costText(t.cost) ? ', ' + costText(t.cost) : ''}${run.by ? ', by ' + run.by : ''}`))}</span></div>
		${run.error ? `<div class='text-danger small mb-1'>${app.utils.escapeHtml(run.error)}</div>` : ''}
		${rows ? `<table class='table table-sm table-dark table-hover align-middle small mb-0' style='--bs-table-bg: transparent'>
		    <thead><tr><th>Case</th><th>Result</th><th>Checks</th><th>Tokens</th><th>Cost</th><th>${diff.previous ? 'Since the last run' : 'First run'}</th></tr></thead><tbody>${rows}</tbody></table>` : muted(active(run.state) ? 'Running the first case...' : 'No results')}
		${removed}`;
	}

	/* ---------------- a case ---------------- */
	editCase(c) {
	    this.editing = JSON.parse(JSON.stringify(c));
	    this.askDelete = false;
	    if (!this.isOpen())
		this.open();
	    this.renderRight();
	}

	stepSelect(c, i) {
	    const steps = this.data.steps || [];
	    if (steps.length < 2)
		return '';
	    return `<select class='form-select form-select-sm w-auto' data-field='step' data-i='${i}' aria-label='AI step'><option value=''>Any AI step</option>${steps.map((s) => `<option value='${app.utils.escapeHtml(s.id)}' ${c.step === s.id ? 'selected' : ''}>${app.utils.escapeHtml(s.title)}</option>`).join('')}</select>`;
	}

	opSelect(op, attrs) {
	    return `<select class='form-select form-select-sm w-auto' ${attrs} aria-label='Comparison'>${Object.entries(this.data.operators).map(([ k, v ]) => `<option value='${app.utils.escapeHtml(k)}' ${op === k ? 'selected' : ''}>${app.utils.escapeHtml(v)}</option>`).join('')}</select>`;
	}

	checkHtml(c, i) {
	    const input = (field, value, placeholder, cls) => `<input type='text' class='form-control form-control-sm ${cls || ''}' data-field='${field}' data-i='${i}' value='${app.utils.escapeHtml(value)}' placeholder='${app.utils.escapeHtml(placeholder)}' aria-label='${app.utils.escapeHtml(placeholder)}'>`;
	    let body = '';
	    if (c.type === 'json_field')
		body = input('path', c.path, 'Field, e.g. category or items.0.id') + this.opSelect(c.op, `data-field='op' data-i='${i}'`) + (c.op === 'exists' || c.op === 'absent' ? '' : input('value', c.value, 'Value or pattern'));
	    else if (c.type === 'tool_called' || c.type === 'tool_not_called')
		body = input('tool', c.tool, 'Tool name; * for any part') + `<button type='button' class='btn btn-sm btn-outline-secondary py-0 btn-check-arg text-nowrap' data-i='${i}'>+ argument</button>`;
	    else if (c.type === 'contains' || c.type === 'not_contains')
		body = input('text', c.text, 'Text') + `<div class='form-check mb-0 text-nowrap'><input class='form-check-input' type='checkbox' id='case-sens-${i}' data-field='case_sensitive' data-i='${i}' ${c.case_sensitive ? 'checked' : ''}><label class='form-check-label small' for='case-sens-${i}'>Exact case</label></div>`;
	    else if (c.type === 'judged')
		body = `<textarea class='form-control form-control-sm' rows='2' data-field='rubric' data-i='${i}' placeholder='What a good answer does, in a sentence or two' aria-label='Rubric'>${app.utils.escapeHtml(c.rubric)}</textarea>`;
	    const args = (c.type === 'tool_called' || c.type === 'tool_not_called') ? (c.args || []).map((a, n) => `<div class='d-flex gap-1 align-items-center ms-4 mt-1'>
		<input type='text' class='form-control form-control-sm' data-arg='path' data-i='${i}' data-a='${n}' value='${app.utils.escapeHtml(a.path)}' placeholder='Argument' aria-label='Argument'>
		${this.opSelect(a.op, `data-arg='op' data-i='${i}' data-a='${n}'`)}
		${a.op === 'exists' || a.op === 'absent' ? '' : `<input type='text' class='form-control form-control-sm' data-arg='value' data-i='${i}' data-a='${n}' value='${app.utils.escapeHtml(a.value)}' placeholder='Value or pattern' aria-label='Value or pattern'>`}
		<button type='button' class='debug-icon-btn btn-arg-remove' data-i='${i}' data-a='${n}' title='Remove'><i class='bi bi-x-lg'></i></button></div>`).join('') : '';
	    return `<div class='mb-1'><div class='d-flex gap-1 align-items-center'><span class='small text-nowrap'>${app.utils.escapeHtml(this.data.checkTypes[c.type] || c.type)}</span>${this.stepSelect(c, i)}${body}
		<button type='button' class='debug-icon-btn btn-check-remove ms-auto' data-i='${i}' title='Remove this check'><i class='bi bi-x-lg'></i></button></div>${args}</div>`;
	}

	editorHtml() {
	    const c = this.editing;
	    const canEdit = this.data.canEdit;
	    const types = Object.entries(this.data.checkTypes).map(([ k, v ]) => `<option value='${app.utils.escapeHtml(k)}'>${app.utils.escapeHtml(v)}</option>`).join('');
	    const modes = Object.entries(this.data.modes).map(([ k, v ]) => `<option value='${app.utils.escapeHtml(k)}' ${c.tools === k ? 'selected' : ''}>${app.utils.escapeHtml(v)}</option>`).join('');
	    const del = !c.id || !canEdit ? '' : this.askDelete
		? `<span class='small'>Delete this test case?</span><button type='button' class='btn btn-sm btn-danger py-0 btn-case-delete-yes'>Yes</button><button type='button' class='btn btn-sm btn-outline-secondary py-0 btn-case-delete-no'>No</button>`
		: `<button type='button' class='btn btn-sm btn-outline-danger py-0 btn-case-delete'>Delete</button>`;
	    return `<fieldset ${canEdit ? '' : 'disabled'}>
		<div class='row g-2 mb-2'>
		    <div class='col-md-7'><label class='form-label small mb-0' for='case-name'>Name</label><input type='text' id='case-name' class='form-control form-control-sm' value='${app.utils.escapeHtml(c.name)}' maxlength='200'></div>
		    <div class='col-md-5'><label class='form-label small mb-0' for='case-tools'>Tool calls</label><select id='case-tools' class='form-select form-select-sm'>${modes}</select></div>
		</div>
		<div class='row g-2 mb-2'>
		    <div class='col-md-6'><label class='form-label small mb-0' for='case-vars'>Inputs: the first variables, as JSON</label><textarea id='case-vars' class='form-control form-control-sm font-monospace' rows='4'>${app.utils.escapeHtml(JSON.stringify(c.vars || {}, null, 2))}</textarea></div>
		    <div class='col-md-6'><label class='form-label small mb-0' for='case-mocks'>Recorded tool results (${(c.mocks || []).length}), as JSON</label><textarea id='case-mocks' class='form-control form-control-sm font-monospace' rows='4'>${app.utils.escapeHtml(JSON.stringify(c.mocks || [], null, 2))}</textarea></div>
		</div>
		${(c.steps || []).length ? `<div class='small mb-1'>${muted('Recorded, not run in a test: ' + app.utils.escapeHtml(c.steps.join(', ')))}</div>` : ''}
		${(c.notes || []).map((n) => `<div class='small mb-1'>${muted('<i class="bi bi-info-circle me-1"></i>' + app.utils.escapeHtml(n))}</div>`).join('')}
		<div class='debug-panel-title mb-1'>Checks</div>
		${(c.checks || []).map((k, i) => this.checkHtml(k, i)).join('')}
		<select class='form-select form-select-sm w-auto tests-add-check mb-2' aria-label='Add a check'><option value=''>Add a check...</option>${types}</select>
		</fieldset>
		<div class='d-flex align-items-center gap-1'>
		    ${canEdit ? `<button type='button' class='btn btn-sm btn-primary py-0 btn-case-save'>Save</button>` : ''}
		    <button type='button' class='btn btn-sm btn-outline-secondary py-0 btn-case-cancel'>${canEdit ? 'Cancel' : 'Close'}</button>
		    <span class='ms-auto d-inline-flex align-items-center gap-1'>${del}</span>
		</div>`;
	}

	//what the form says now, into this.editing (the JSON texts kept as text until saved: raw*)
	readForm() {
	    const c = this.editing;
	    if (!c || !this.find('#case-name'))
		return;
	    c.name = this.find('#case-name').value;
	    c.tools = this.find('#case-tools').value;
	    try {
		c.vars = JSON.parse(this.find('#case-vars').value || '{}');
	    } catch (e) {}
	    try {
		c.mocks = JSON.parse(this.find('#case-mocks').value || '[]');
	    } catch (e) {}
	    this.el.querySelectorAll('.tests-detail [data-field]').forEach((f) => {
		const k = c.checks[Number(f.dataset.i)];
		if (k)
		    k[f.dataset.field] = f.type === 'checkbox' ? f.checked : f.value;
	    });
	    this.el.querySelectorAll('.tests-detail [data-arg]').forEach((f) => {
		const k = c.checks[Number(f.dataset.i)];
		if (k && k.args && k.args[Number(f.dataset.a)])
		    k.args[Number(f.dataset.a)][f.dataset.arg] = f.value;
	    });
	}

	post(url, data) {
	    return $.ajax({ url: url, method: 'POST', dataType: 'json', data: data || {}, headers: { 'X-Csrf-Token': csrf() } });
	}

	saveCase() {
	    this.readForm();
	    const c = this.editing;
	    let vars;
	    let mocks;
	    try {
		vars = JSON.parse(this.find('#case-vars').value || '{}');
		mocks = JSON.parse(this.find('#case-mocks').value || '[]');
	    } catch (e) {
		toast('error', 'Not saved', 'Inputs and Recorded tool results must be valid JSON: ' + e.message);
		return;
	    }
	    this.post(this.o.urls.save, { case_id: c.id || '', case: JSON.stringify({ name: c.name, vars: vars, tools: c.tools, mocks: mocks, checks: c.checks }) })
		.done(() => {
		    toast('success', 'Saved', 'The test case is saved');
		    this.editing = null;
		    this.refresh();
		})
		.fail((xhr) => toast('error', 'Not saved', (xhr.responseJSON && xhr.responseJSON.error) || 'The test case could not be saved'));
	}

	deleteCase() {
	    const url = this.o.urls.del + (this.o.urls.del.includes('?') ? '&' : '?') + 'case=' + encodeURIComponent(this.editing.id);
	    this.post(url).done(() => {
		this.editing = null;
		this.refresh();
	    }).fail((xhr) => toast('error', 'Not deleted', (xhr.responseJSON && xhr.responseJSON.error) || 'The test case could not be deleted'));
	}

	/* ---------------- a run ---------------- */
	//once the changes are saved: what runs is the saved draft
	start() {
	    this.o.whenSaved(() => {
		this.post(this.o.urls.start).done((resp) => {
		    this.runId = resp.run.id;
		    this.editing = null;
		    this.detail = null;
		    this.refresh();
		}).fail((xhr) => toast('error', 'Not started', (xhr.responseJSON && xhr.responseJSON.error) || 'The tests could not be started'));
	    });
	}

	stop() {
	    const url = this.o.urls.stop + (this.o.urls.stop.includes('?') ? '&' : '?') + 'run=' + encodeURIComponent((this.data.runs[0] || {}).id || '');
	    this.post(url).always(() => this.refresh());
	}

	setRequired(on) {
	    this.post(this.o.urls.required, { required: on ? 1 : 0 }).fail((xhr) => toast('error', 'Not changed', (xhr.responseJSON && xhr.responseJSON.error) || 'The setting could not be changed')).always(() => this.refresh());
	}
    }

    return WfTests;
})();
