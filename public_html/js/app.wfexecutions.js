if (!window.app)
    window.app = {};

//CLD: 2026-10-25T20:30:00+02:00: the designer's Run and Executions panel, a class as app.Workflow / app.WfAssistant (was the `exec` object of the page script, views/workflow/index.php):
//
//   const executions = new app.WfExecutions(panel, options);     (the panel element: .exec-panel, or its selector)
//
//Run starts the saved workflow at its trigger (WfNode::startNow; runs on the server after the answer); the panel lists the latest executions and shows one (summary, log,
//variables), refreshed every 2 s while one is new / running; an execution of the draft that has finished can be deleted, or all of them (asked again in place).
//CLD: 2026-10-26T10:14:16+02:00: one that is new / running / waiting can be killed (asked again in place; urls.kill: WorkflowController::execution_kill)
//options: urls { run, list, detail, del, delAll, kill }, canRun (the user may run the workflow), runTriggers (the triggers Run can start: fqnames), whenSaved(fn) (fn once the
//changes are saved: Run starts the saved draft), onOpen() / onClose() (the page: one bottom panel at a time, the one last open remembered).
//The page tells it of the steps: onDataChange(workflow, serialized) (the Run button's state; the first call is the saved state), markSaved(serialized) (autosave); `dirty`:
//changes not saved yet. Methods: run(), open(), close(), toggle(), refresh(), destroy(). One per designer page: a new one destroys the previous one (its timer stopped, its
//listeners removed).
//CLD: 2026-10-29T21:30:00+02:00: + onShow(run): the selected execution's steps and connections (detail.run, WorkflowController::runStatus) for the canvas, refreshed with it; null when
//none is shown (the panel closed, the execution deleted). Opened with none selected: the latest one still running / waiting is selected (the live one on the canvas)
//CLD: 2026-10-29T21:38:25+02:00: the execution on the canvas (`shown`) stays when the panel is closed, refreshed every 2 s while it runs; showLive(): the latest one running / waiting is shown
//without opening the panel (the page, when the designer opens). A chip at the top of the canvas (options.chip(): the canvas element) says which one: a click opens it in the panel,
//its x takes the marks off (hide())
//CLD: 2026-11-02T14:00:00+02:00: + an AI step's trace in the details (urls.trace: WorkflowController::execution_trace): its visits, opened one loaded and followed while it runs; rounds collapsed, a tool call
//opens on its arguments and result (what is open stays over the refreshes: traceKeys); Run again with the same inputs (urls.again: execution_again, asked again in place); openTrace(execution, trace)
window.app.WfExecutions = (function() {
    //the one of the current designer page
    let current = null;

    const esc = (v) => app.utils.escapeHtml(v === null || v === undefined ? '' : String(v));
    const csrf = () => $('meta[name=csrf-token]').attr('content');
    const toast = (type, title, body) => app.utils.toast({ type: type, title: esc(title), body: esc(body), delay: 5000 });
    const active = (state) => state === 'new' || state === 'run';
    const NS = '.wfexecutions';

    function duration(ms) {
	if (ms === null || ms === undefined)
	    return '';
	if (ms < 1000)
	    return ms + ' ms';
	if (ms < 60000)
	    return (ms / 1000).toFixed(1) + ' s';
	const sec = Math.round(ms / 1000);
	return Math.floor(sec / 60) + 'm ' + String(sec % 60).padStart(2, '0') + 's';
    }

    //the published version an execution ran, or the draft
    const versionLabel = (e) => e.version === null || e.version === undefined ? '(draft)' : '(v' + e.version + ')';

    function badge(state) {
	const colors = { end: 'success', error: 'danger', run: 'primary', new: 'info', sleep: 'warning', cancel: 'secondary' };
	const labels = { end: 'completed', run: 'running', sleep: 'sleeping' };
	return `<span class='badge text-bg-${colors[state] || 'secondary'}'>${esc(labels[state] || state)}</span>`;
    }

    //CLD: 2026-11-02T14:00:00+02:00: the trace: its state; an estimated cost or credits; a text cut; a tool call's approval
    function traceBadge(state) {
	const colors = { running: 'primary', waiting: 'warning', done: 'success', error: 'danger', stopped: 'secondary' };
	const labels = { running: 'running', waiting: 'waiting for approval', done: 'done', error: 'failed', stopped: 'stopped' };
	return `<span class='badge text-bg-${colors[state] || 'secondary'}'>${esc(labels[state] || state)}</span>`;
    }

    function costText(cost) {
	if (cost && cost.usd !== null && cost.usd !== undefined)
	    return 'about $' + Number(cost.usd).toFixed(cost.usd > 0 && cost.usd < 0.01 ? 4 : 2);
	if (cost && cost.credits !== null && cost.credits !== undefined)
	    return cost.credits + ' credit(s)';
	return '';
    }

    const muted = (html) => `<span style='color: var(--rail22-debug-muted)'>${html}</span>`;
    const cutNote = (shown, full) => full ? `<div class='small' style='color: var(--rail22-debug-muted)'>Cut: the first ${esc(Number(String(shown || '').length).toLocaleString())} of ${esc(Number(full).toLocaleString())} characters</div>` : '';
    const block = (text) => `<pre class='mb-1 small' style='color: inherit; white-space: pre-wrap'>${esc(text)}</pre>`;

    function approvalBadge(a, waiting) {
	if (!a)
	    return '';
	const by = a.by ? ' by ' + a.by : '';
	const shown = { asked: waiting ? [ 'warning', 'waiting for approval' ] : [ 'secondary', 'not decided' ], approved: [ 'success', 'approved' + by ], rejected: [ 'danger', 'rejected' + by ],
	    expired: [ 'secondary', 'not approved in time' ], 'not asked': [ 'secondary', 'not asked: a limit stopped the agent' ] }[a.state] || [ 'secondary', a.state ];
	return `<span class='badge text-bg-${shown[0]}'>${esc(shown[1])}</span>`;
    }

    class WfExecutions {
	constructor(panel, options) {
	    if (current)
		current.destroy();
	    current = this;

	    this.el = typeof panel === 'string' ? document.querySelector(panel) : panel;
	    //CLD: 2026-10-29T21:30:00+02:00: + onShow
	    //CLD: 2026-10-29T21:38:25+02:00: + chip
	    this.o = Object.assign({ urls: {}, canRun: false, runTriggers: [], whenSaved: (fn) => fn(), onOpen: () => {}, onClose: () => {}, onShow: () => {}, chip: () => null }, options || {});
	    //baseline: the last saved state (markSaved), current: the latest, dirty: changes not saved yet
	    this.baseline = null;
	    this.current = null;
	    this.dirty = false;
	    this.hasTrigger = false;
	    this.list = [];
	    this.selected = null;
	    this.detail = null;
	    this.timer = null;
	    //CLD: 2026-10-29T21:38:25+02:00: the execution on the canvas (its id, its details), whether the panel is open or not; the chip saying which
	    this.shown = null;
	    this.shownDetail = null;
	    this.chipEl = null;
	    //CLD: 2026-11-02T14:00:00+02:00: the traces: those loaded (id => with its rounds), being loaded, whether opened by the user (id => yes / no; not told: the only one of a run is open), the rounds and calls open, one to show
	    this.traces = {};
	    this.traceLoading = new Set();
	    this.traceOpen = new Map();
	    this.traceKeys = new Set();
	    this.traceToShow = null;
	    //the elements its listeners are on (removed by destroy)
	    this.bound = [];

	    this.on($('.btn-run-wf'), 'click', (e) => {
		e.preventDefault();
		this.run();
	    });
	    this.on($('.btn-exec-toggle'), 'click', (e) => {
		e.preventDefault();
		this.toggle();
	    });
	    if (!this.el)
		return;
	    const $panel = $(this.el);
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.refresh();
	    }, '.btn-exec-refresh');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.close();
	    }, '.btn-exec-close');
	    this.on($panel, 'click', (e) => this.select(e.currentTarget.dataset.id), '.exec-row');
	    //delete an execution of the draft (asked again in place)
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.confirmDelete();
	    }, '.btn-exec-delete');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.remove();
	    }, '.btn-exec-delete-yes');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.renderDetail();
	    }, '.btn-exec-delete-no');
	    //delete all executions of the draft (asked again in place)
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.confirmDeleteAll();
	    }, '.btn-exec-delete-all');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.removeAll();
	    }, '.btn-exec-delete-all-yes');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.restoreDeleteAll();
	    }, '.btn-exec-delete-all-no');
	    //CLD: 2026-10-26T10:14:16+02:00: kill a new / running / waiting execution (asked again in place)
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.askKill = this.selected;
		this.renderDetail();
		const yes = this.find('.btn-exec-kill-yes');
		if (yes)
		    yes.focus();
	    }, '.btn-exec-kill');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.kill();
	    }, '.btn-exec-kill-yes');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.askKill = null;
		this.renderDetail();
	    }, '.btn-exec-kill-no');
	    //CLD: 2026-11-02T14:00:00+02:00: Run again (asked again in place; kept over the 2 s refresh while asked: askAgain)
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.askAgain = this.selected;
		this.renderDetail();
		const yes = this.find('.btn-exec-again-yes');
		if (yes)
		    yes.focus();
	    }, '.btn-exec-again');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.runAgain();
	    }, '.btn-exec-again-yes');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.askAgain = null;
		this.renderDetail();
	    }, '.btn-exec-again-no');
	    this.on($panel, 'click', (e) => {
		e.preventDefault();
		this.select(e.currentTarget.dataset.id);
	    }, '.exec-rerun-of');
	    //CLD: 2026-11-02T14:00:00+02:00: a trace, a round, a tool call opened or closed (the click comes before the element toggles)
	    this.on($panel, 'click', (e) => {
		const d = e.currentTarget.parentElement;
		const open = !d.open;
		if (d.dataset.trace) {
		    this.traceOpen.set(d.dataset.trace, open);
		    if (open && this.detail)
			this.loadTraces(this.detail);
		} else if (open) {
		    this.traceKeys.add(d.dataset.key);
		} else {
		    this.traceKeys.delete(d.dataset.key);
		}
	    }, 'details[data-key] > summary');
	}

	//a listener removed by destroy() (selector: delegated)
	on($el, type, fn, selector) {
	    if (selector)
		$el.on(type + NS, selector, fn);
	    else
		$el.on(type + NS, fn);
	    this.bound.push($el);
	}

	destroy() {
	    clearInterval(this.timer);
	    this.timer = null;
	    this.bound.splice(0).forEach(($el) => $el.off(NS));
	    //CLD: 2026-10-29T21:38:25+02:00: the chip goes with it
	    if (this.chipEl)
		this.chipEl.remove();
	    this.chipEl = null;
	    if (current === this)
		current = null;
	}

	/* ---------------- the steps (told by the page) ---------------- */
	onDataChange(workflow, serialized) {
	    if (this.baseline === null)
		this.baseline = serialized;
	    this.current = serialized;
	    this.dirty = serialized !== this.baseline;
	    this.hasTrigger = workflow.some((n) => n.isNode && this.o.runTriggers.includes(n.fqname));
	    let tip = 'Run (from the trigger; an Email trigger checks the mailbox now)';
	    if (!this.o.canRun)
		tip = 'You may not run this workflow';
	    else if (!this.hasTrigger)
		tip = 'This trigger cannot be run from here (it starts when its event happens)';
	    else if (this.dirty)
		tip = 'Run (after the changes are saved)';
	    $('.btn-run-wf').attr('title', tip).toggleClass('opacity-50', !this.o.canRun || !this.hasTrigger);
	}

	//the state `serialized` is saved
	markSaved(serialized) {
	    this.baseline = serialized;
	    this.dirty = this.current !== null && this.current !== this.baseline;
	}

	/* ---------------- Run ---------------- */
	//started once the changes are saved (options.whenSaved)
	run() {
	    if (!this.o.canRun || !this.hasTrigger)
		return;
	    this.o.whenSaved(() => this.start());
	}

	start() {
	    $.ajax({ url: this.o.urls.run, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() } })
		.done((resp) => {
		    //nothing started (e.g. no new mail) -> the reason; several executions (one per message) -> the first is shown
		    if (!resp.execution) {
			toast('info', 'Nothing started', resp.message || 'Nothing was started');
			return;
		    }
		    const n = (resp.executions || []).length;
		    toast('success', 'Started', n > 1 ? n + ' executions started (one per message)' : 'Execution started');
		    this.open();
		    this.select(resp.execution);
		})
		.fail((xhr) => toast('error', 'Not started', (xhr.responseJSON && xhr.responseJSON.error) || 'The workflow could not be started'));
	}

	/* ---------------- the panel ---------------- */
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
	    //one bottom panel at a time (they share the same place), remembered
	    this.o.onOpen();
	    if (this.el.classList.contains('d-none')) {
		this.el.classList.remove('d-none');
		if (this.el.offsetHeight < 120)
		    this.el.style.height = '300px';
	    }
	    this.refresh();
	    if (!this.timer)
		this.timer = setInterval(() => this.tick(), 2000);
	}

	//CLD: 2026-10-29T21:38:25+02:00: the execution on the canvas stays (its timer too, while it runs: tick)
	close() {
	    if (this.el)
		this.el.classList.add('d-none');
	    this.o.onClose();
	}

	//CLD: 2026-10-29T21:38:25+02:00: + the execution on the canvas while it runs, the panel open or not
	tick() {
	    //the page was left (pjax)
	    const here = !this.el || document.body.contains(this.el);
	    const open = !!this.el && here && this.isOpen();
	    const shownLive = here && !!this.shownDetail && active(this.shownDetail.state);
	    if (!open && !shownLive) {
		clearInterval(this.timer);
		this.timer = null;
		return;
	    }
	    if (open && (this.list.some((e) => active(e.state)) || (this.detail && active(this.detail.state))))
		this.refresh();
	    //refresh() loads it when it is the one selected
	    if (shownLive && !(open && this.shown === this.selected))
		this.loadDetail(this.shown);
	}

	ensureTimer() {
	    if (!this.timer)
		this.timer = setInterval(() => this.tick(), 2000);
	}

	refresh() {
	    $.getJSON(this.o.urls.list).done((resp) => {
		this.list = resp.executions || [];
		this.renderList();
		//CLD: 2026-10-29T21:30:00+02:00: none selected: the latest one running / waiting (shown on the canvas)
		//CLD: 2026-10-29T21:38:25+02:00: or the one on the canvas
		const live = this.list.find((e) => active(e.state) || e.state === 'sleep');
		const pick = this.selected ? null : (this.shown || (live && live.id));
		if (pick && this.isOpen())
		    this.select(pick);
	    });
	    if (this.selected)
		this.loadDetail(this.selected);
	}

	//CLD: 2026-10-29T21:38:25+02:00: + shown on the canvas
	select(id) {
	    this.selected = id;
	    this.shown = id;
	    this.renderList();
	    this.loadDetail(id);
	}

	//CLD: 2026-10-29T21:38:25+02:00: the details of the one selected (the panel) and / or of the one on the canvas (its marks, the chip)
	loadDetail(id) {
	    $.getJSON(this.o.urls.detail, { execution: id }).done((resp) => {
		const e = resp.execution;
		if (this.selected === id) {
		    this.detail = e;
		    this.renderDetail();
		}
		if (this.shown === id) {
		    this.shownDetail = e;
		    this.o.onShow(e.run || null);
		    this.renderChip();
		    if (active(e.state))
			this.ensureTimer();
		}
	    });
	}

	/* ---------------- the execution on the canvas ---------------- */
	//CLD: 2026-10-29T21:38:25+02:00: show execution `id` on the canvas (the panel not opened)
	show(id) {
	    this.shown = id;
	    this.loadDetail(id);
	}

	//CLD: 2026-10-29T21:38:25+02:00: the marks taken off the canvas (the chip's x; the execution deleted); selecting one in the panel shows it again
	hide() {
	    this.shown = null;
	    this.shownDetail = null;
	    this.o.onShow(null);
	    this.renderChip();
	}

	//CLD: 2026-10-29T21:38:25+02:00: the latest execution running / waiting, if any, on the canvas (the designer opened with the panel closed)
	showLive() {
	    $.getJSON(this.o.urls.list).done((resp) => {
		if (this.shown || this.isOpen() || current !== this)
		    return;
		const live = (resp.executions || []).find((e) => active(e.state) || e.state === 'sleep');
		if (live)
		    this.show(live.id);
	    });
	}

	//CLD: 2026-10-29T21:38:25+02:00: the chip at the top of the canvas: the state and start of the execution shown, x to hide it; none when none is shown
	renderChip() {
	    const e = this.shown ? this.shownDetail : null;
	    if (!e) {
		if (this.chipEl)
		    this.chipEl.remove();
		this.chipEl = null;
		return;
	    }
	    if (!this.chipEl || !document.body.contains(this.chipEl)) {
		const wrap = this.o.chip();
		if (!wrap)
		    return;
		const el = this.chipEl = document.createElement('div');
		el.className = 'wf-run-chip position-absolute top-0 start-50 translate-middle-x mt-3 d-flex align-items-center gap-2 px-2 py-1 small bg-body border rounded-pill shadow-sm text-nowrap';
		el.style.zIndex = 20;
		//not a pan of the canvas
		el.addEventListener('pointerdown', (ev) => ev.stopPropagation());
		el.addEventListener('click', (ev) => {
		    ev.preventDefault();
		    ev.stopPropagation();
		    if (ev.target.closest('.btn-run-chip-hide')) {
			this.hide();
			return;
		    }
		    const id = this.shown;
		    this.open();
		    if (id)
			this.select(id);
		});
		wrap.appendChild(el);
	    }
	    this.chipEl.innerHTML = `${badge(e.state)}<span role='button' title='Show it in the Executions panel'>Run of ${esc(e.started || e.created || '-')} <span class='text-body-secondary'>${esc(versionLabel(e))}</span></span>`
		+ `<button type='button' class='btn btn-sm btn-link text-body-secondary p-0 lh-1 btn-run-chip-hide' title='Hide the marks of this run' aria-label='Hide'><i class='bi bi-x-lg'></i></button>`;
	}

	find(selector) {
	    return this.el ? this.el.querySelector(selector) : null;
	}

	renderList() {
	    const box = this.find('.exec-list');
	    if (!box)
		return;
	    if (!this.list.length) {
		box.innerHTML = `<div style='color: var(--rail22-debug-muted)'>No executions yet${this.hasTrigger ? ': use Run' : ''}</div>`;
		return;
	    }
	    box.innerHTML = this.list.map((e) => `
		<div class='exec-row d-flex align-items-center gap-2 px-1 rounded${e.id === this.selected ? ' exec-selected' : ''}' data-id='${esc(e.id)}' title='${esc(e.error || '')}' style='cursor: pointer'>
		    ${badge(e.state)}${e.rerun_of ? ` <i class='bi bi-arrow-repeat' title='Run again with the inputs of another execution'></i>` : ''}
		    <span class='exec-time'>${esc(e.started || e.created)}</span>
		    <span class='exec-what' style='color: var(--rail22-debug-muted)' title='${esc(e.trigger + ' ' + versionLabel(e))}'>${esc(e.trigger)} ${esc(versionLabel(e))}</span>
		    <span class='exec-duration ms-auto' style='color: var(--rail22-debug-muted)'>${duration(e.duration)}</span>
		</div>`).join('');
	}

	renderDetail() {
	    const box = this.find('.exec-detail');
	    const e = this.detail;
	    if (!box || !e)
		return;
	    const vars = JSON.stringify(e.variables || {}, null, 2);
	    const log = (e.log || []).map((l) => `<div class='debug-log-line'><span style='color: var(--rail22-debug-muted)'>${esc((l.time || '').slice(11))}</span> ${l.title ? `<span class='tag'>[${esc(l.title)}]</span>` : ''}${esc(l.message)}</div>`).join('');
	    //Delete: an execution of the draft that has finished (asked again in place: Yes / No)
	    const del = this.deletable(e) ? `<span class='exec-delete ms-auto flex-shrink-0'><button type='button' class='debug-icon-btn btn-exec-delete' title='Delete this execution'><i class='bi bi-trash'></i></button></span>` : '';
	    //CLD: 2026-10-26T10:14:16+02:00: Kill: one that is new / running / waiting (Kill? Yes / No; kept over the 2 s refresh while asked: askKill)
	    const kill = !this.killable(e) ? '' : `<span class='exec-kill ms-auto flex-shrink-0'>` + (this.askKill === e.id
		? `<span class='me-1'>Kill?</span><button type='button' class='debug-icon-btn btn-exec-kill-yes text-danger' title='Yes, stop it'><i class='bi bi-check-lg'></i></button><button type='button' class='debug-icon-btn btn-exec-kill-no' title='No'><i class='bi bi-x-lg'></i></button>`
		: `<button type='button' class='debug-icon-btn btn-exec-kill text-danger' title='Kill this execution'><i class='bi bi-stop-circle'></i></button>`) + `</span>`;
	    //CLD: 2026-11-02T14:00:00+02:00: Run again: one that has finished, by a user who may run the workflow; the one it ran again; its position kept over a refresh
	    const again = !this.againable(e) ? '' : `<span class='exec-again d-inline-flex align-items-center'>` + (this.askAgain === e.id
		? `<span class='me-1'>Run again on the current draft? Its tools run again too.</span><button type='button' class='debug-icon-btn btn-exec-again-yes' title='Yes, run it again'><i class='bi bi-check-lg'></i></button><button type='button' class='debug-icon-btn btn-exec-again-no' title='No'><i class='bi bi-x-lg'></i></button>`
		: `<button type='button' class='debug-icon-btn btn-exec-again' title='Run again with the same inputs, on the current draft'><i class='bi bi-arrow-repeat'></i></button>`) + `</span>`;
	    const from = e.rerun_of ? `, <a href='#' class='exec-rerun-of' data-id='${esc(e.rerun_of)}'>run again of another execution</a>` : '';
	    const top = box.scrollTop;
	    box.innerHTML = `
		<div class='mb-1 d-flex flex-wrap align-items-center gap-1'><span>${badge(e.state)} <span style='color: var(--rail22-debug-muted)'>${esc(e.trigger)} ${esc(versionLabel(e))}, started ${esc(e.started || e.created || '-')}${e.duration !== null ? ', ' + duration(e.duration) : ''}${e.wake_at ? ', wakes at ' + esc(e.wake_at) : ''}${from}</span></span><span class='ms-auto d-inline-flex align-items-center gap-1'>${again}${del}${kill}</span></div>
		${e.error ? `<div class='text-danger mb-1'>${esc(e.error)}</div>` : ''}
		${this.tracesHtml(e)}
		<div class='debug-panel-title mt-2 mb-1'>Log</div>
		${log || `<div style='color: var(--rail22-debug-muted)'>(empty)</div>`}
		<div class='debug-panel-title mt-2 mb-1'>Variables</div>
		<pre class='mb-0' style='color: inherit; white-space: pre-wrap'>${esc(vars)}</pre>`;
	    box.scrollTop = top;
	    this.loadTraces(e);
	    if (this.traceToShow) {
		const el = box.querySelector(`details[data-trace='${CSS.escape(this.traceToShow)}']`);
		if (el) {
		    el.scrollIntoView({ block: 'nearest' });
		    this.traceToShow = null;
		}
	    }
	}

	/* ---------------- an AI step's trace ---------------- */
	//CLD: 2026-11-02T14:00:00+02:00: new: whether trace `t` of execution `e` is open
	traceIsOpen(t, e) {
	    return this.traceOpen.has(t.id) ? this.traceOpen.get(t.id) : (e.traces || []).length === 1;
	}

	//the open traces not loaded, or changed since (one that runs: at each refresh); shown again when they arrive
	loadTraces(e) {
	    (e.traces || []).filter((t) => this.traceIsOpen(t, e)).forEach((t) => {
		const have = this.traces[t.id];
		if ((have && have.updated === t.updated) || this.traceLoading.has(t.id) || !this.o.urls.trace)
		    return;
		this.traceLoading.add(t.id);
		$.getJSON(this.o.urls.trace, { execution: e.id, trace: t.id })
		    .done((resp) => {
			this.traces[t.id] = resp.trace;
		    })
		    .fail(() => {
			this.traces[t.id] = Object.assign({}, t, { rounds: [], missing: true });
		    })
		    .always(() => {
			this.traceLoading.delete(t.id);
			if (this.detail && this.detail.id === e.id)
			    this.renderDetail();
		    });
	    });
	}

	//the visits of the AI steps; none: nothing
	tracesHtml(e) {
	    if (!(e.traces || []).length)
		return '';
	    const more = e.traces_total > e.traces.length ? `<div class='small' style='color: var(--rail22-debug-muted)'>The first ${esc(e.traces.length)} of ${esc(e.traces_total)} visits</div>` : '';
	    return `<div class='debug-panel-title mt-2 mb-1'>Agent trace</div>${e.traces.map((t) => this.traceHtml(t, e)).join('')}${more}`;
	}

	traceHtml(t, e) {
	    const totals = t.totals || {};
	    const n = (v) => Number(v || 0).toLocaleString();
	    const money = costText(totals.cost);
	    const parts = [ esc(t.provider + (t.model ? ' ' + t.model : '')), `${n(totals.rounds)} round(s)`, `${n(totals.tool_calls)} tool call(s)${totals.write_calls ? ', ' + n(totals.write_calls) + ' write' : ''}`,
		`${n(totals.input_tokens)} + ${n(totals.output_tokens)} tokens` ];
	    parts.push(money ? esc(money) + (t.system ? '' : ' (estimated)') : `<span title='Set the prices of this model: on the step, or in Account settings &gt; AI'>cost not known</span>`);
	    if (t.duration !== null)
		parts.push(esc(duration(t.duration)));
	    const open = this.traceIsOpen(t, e);
	    const data = this.traces[t.id];
	    let body = '';
	    if (open && data)
		body = data.missing ? muted('This trace could not be loaded') : (data.rounds || []).map((r) => this.roundHtml(t, r)).join('') || muted('No call to the model yet');
	    else if (open)
		body = muted('Loading…');
	    return `<details class='mb-1' data-key='${esc(t.id)}' data-trace='${esc(t.id)}'${open ? ' open' : ''}>
		<summary style='cursor: pointer'>${traceBadge(t.state)} <span class='tag'>[${esc(t.step)}${t.visit > 1 ? ' #' + esc(t.visit) : ''}]</span> ${muted(parts.join(', '))}</summary>
		<div class='ps-3 pt-1'>${t.error ? `<div class='text-danger mb-1'>${esc(t.error)}</div>` : ''}${body}</div>
	    </details>`;
	}

	roundHtml(t, r) {
	    const key = `${t.id}:r${r.n}`;
	    const tokens = r.tokens || {};
	    const calls = r.calls || [];
	    const money = costText(r.cost);
	    const parts = [ esc(r.model), `${Number(tokens.input || 0).toLocaleString()} + ${Number(tokens.output || 0).toLocaleString()} tokens` ];
	    if (money)
		parts.push(esc(money));
	    parts.push(esc(duration(r.duration_ms)));
	    if (calls.length)
		parts.push(calls.length + ' tool call(s)');
	    const asked = calls.filter((c) => c.approval).length;
	    if (asked)
		parts.push(asked + ' asked for approval');
	    return `<details class='mb-1' data-key='${esc(key)}'${this.traceKeys.has(key) ? ' open' : ''}>
		<summary style='cursor: pointer'>Round ${esc(r.n)} ${muted(parts.join(', '))}${calls.some((c) => c.access === 'write') ? ` <span class='badge text-bg-warning'>write</span>` : ''}</summary>
		<div class='ps-3 pt-1'>
		    ${r.text ? block(r.text) + cutNote(r.text, r.text_length) : ''}
		    ${calls.map((c, i) => this.callHtml(t, key, c, i)).join('')}
		</div>
	    </details>`;
	}

	callHtml(t, roundKey, c, i) {
	    const key = `${roundKey}:c${i}`;
	    const waiting = t.state === 'waiting';
	    const a = c.approval;
	    const decided = a && (a.by || a.at || a.comment) ? `<div class='small mb-1'>${muted('Decision:')} ${esc(a.state)}${a.by ? ' by ' + esc(a.by) : ''}${a.at ? ', ' + esc(a.at) : ''}${a.comment ? ': ' + esc(a.comment) : ''}</div>` : '';
	    const result = c.result !== undefined ? block(c.result || '(empty)') + cutNote(c.result, c.result_length) : muted(a && a.state === 'asked' && waiting ? 'Not run: waiting for approval' : 'Not run');
	    return `<details class='mb-1' data-key='${esc(key)}'${this.traceKeys.has(key) ? ' open' : ''}>
		<summary style='cursor: pointer'><i class='bi bi-wrench'></i> <code>${esc(c.tool)}</code> ${c.access === 'write' ? `<span class='badge text-bg-warning'>write</span>` : muted(esc(c.access))}
		    ${approvalBadge(a, waiting)}${c.refused ? ` <span class='badge text-bg-danger'>stopped by a limit</span>` : ''}${c.error && !c.refused ? ` <span class='text-danger'>error</span>` : ''}
		    ${c.duration_ms !== undefined && c.result !== undefined && !(a && a.state !== 'approved') ? muted(esc(duration(c.duration_ms))) : ''}${c.images ? ' ' + muted(esc(c.images) + ' image(s)') : ''}</summary>
		<div class='ps-3 pt-1'>
		    ${decided}
		    <div class='small' style='color: var(--rail22-debug-muted)'>Arguments</div>
		    ${block(c.arguments || '{}')}${cutNote(c.arguments, c.arguments_length)}
		    <div class='small' style='color: var(--rail22-debug-muted)'>Result</div>
		    ${result}
		</div>
	    </details>`;
	}

	//CLD: 2026-11-02T14:00:00+02:00: new: the panel on execution `id`, its trace `trace` open (a link to a trace: WorkflowController::trace)
	openTrace(id, trace) {
	    if (trace) {
		this.traceOpen.set(trace, true);
		this.traceToShow = trace;
	    }
	    this.open();
	    this.select(id);
	}

	/* ---------------- run again ---------------- */
	//CLD: 2026-11-02T14:00:00+02:00: new: a finished execution, by a user who may run the workflow
	againable(e) {
	    return this.o.canRun && !!this.o.urls.again && ![ 'new', 'run', 'sleep' ].includes(e.state);
	}

	//the selected execution's inputs, as a new execution, once the changes are saved (the change to compare); it is shown
	runAgain() {
	    const id = this.selected;
	    this.askAgain = null;
	    this.renderDetail();
	    if (!id)
		return;
	    const url = this.o.urls.again + (this.o.urls.again.includes('?') ? '&' : '?') + 'execution=' + encodeURIComponent(id);
	    this.o.whenSaved(() => {
		$.ajax({ url: url, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() } })
		    .done((resp) => {
			toast('success', 'Started', 'Running again with the same inputs');
			this.select(resp.execution);
			this.refresh();
			this.ensureTimer();
		    })
		    .fail((xhr) => toast('error', 'Not started', (xhr.responseJSON && xhr.responseJSON.error) || 'The execution could not be run again'));
	    });
	}

	//CLD: 2026-10-29T21:38:25+02:00: the canvas: hide() (was here)
	clearDetail() {
	    this.selected = null;
	    this.detail = null;
	    const box = this.find('.exec-detail');
	    if (box)
		box.innerHTML = `<span style='color: var(--rail22-debug-muted)'>Select an execution</span>`;
	}

	/* ---------------- kill ---------------- */
	//CLD: 2026-10-26T10:14:16+02:00: an execution that is new / running / waiting, by a user who may run the workflow
	killable(e) {
	    return this.o.canRun && !!this.o.urls.kill && [ 'new', 'run', 'sleep' ].includes(e.state);
	}

	//kills the selected execution; the list and the details are reloaded
	kill() {
	    const id = this.selected;
	    this.askKill = null;
	    if (!id)
		return;
	    const url = this.o.urls.kill + (this.o.urls.kill.includes('?') ? '&' : '?') + 'execution=' + encodeURIComponent(id);
	    $.ajax({ url: url, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() } })
		.done(() => toast('success', 'Killed', 'The execution was stopped'))
		.fail((xhr) => toast('error', 'Not killed', (xhr.responseJSON && xhr.responseJSON.error) || 'The execution could not be killed'))
		.always(() => this.refresh());
	}

	/* ---------------- delete ---------------- */
	//an execution of the draft (no version) that is not running or sleeping, by a user who may run the workflow
	deletable(e) {
	    return this.o.canRun && (e.version === null || e.version === undefined) && ![ 'new', 'run', 'sleep' ].includes(e.state);
	}

	//the trash button becomes `Delete? Yes / No`
	confirmDelete() {
	    const box = this.find('.exec-detail .exec-delete');
	    if (!box)
		return;
	    box.innerHTML = `<span class='me-1'>Delete?</span><button type='button' class='debug-icon-btn btn-exec-delete-yes text-danger' title='Yes, delete it'><i class='bi bi-check-lg'></i></button><button type='button' class='debug-icon-btn btn-exec-delete-no' title='No'><i class='bi bi-x-lg'></i></button>`;
	    box.querySelector('.btn-exec-delete-yes').focus();
	}

	//deletes the selected execution; the list and the details are updated
	remove() {
	    const id = this.selected;
	    if (!id)
		return;
	    const url = this.o.urls.del + (this.o.urls.del.includes('?') ? '&' : '?') + 'execution=' + encodeURIComponent(id);
	    $.ajax({ url: url, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() } })
		.done(() => {
		    this.list = this.list.filter((e) => e.id !== id);
		    //CLD: 2026-10-29T21:38:25+02:00: off the canvas
		    if (this.shown === id)
			this.hide();
		    if (this.selected === id)
			this.clearDetail();
		    this.renderList();
		    toast('success', 'Deleted', 'Execution deleted');
		})
		.fail((xhr) => {
		    toast('error', 'Not deleted', (xhr.responseJSON && xhr.responseJSON.error) || 'The execution could not be deleted');
		    this.renderDetail();
		});
	}

	//the header's trash button becomes `Delete all? Yes / No`; restoreDeleteAll() puts it back
	confirmDeleteAll() {
	    const box = this.find('.exec-delete-all');
	    if (!box)
		return;
	    box.innerHTML = `<span class='me-1'>Delete all?</span><button type='button' class='debug-icon-btn btn-exec-delete-all-yes text-danger' title='Yes, delete all executions of the draft'><i class='bi bi-check-lg'></i></button><button type='button' class='debug-icon-btn btn-exec-delete-all-no' title='No'><i class='bi bi-x-lg'></i></button>`;
	    box.querySelector('.btn-exec-delete-all-yes').focus();
	}

	restoreDeleteAll() {
	    const box = this.find('.exec-delete-all');
	    if (box)
		box.innerHTML = `<button type='button' class='debug-icon-btn btn-exec-delete-all' title='Delete all executions of the draft (running / sleeping ones and those of published versions are kept)'><i class='bi bi-trash'></i></button>`;
	}

	//deletes all finished executions of the draft; the list is reloaded, the details cleared when the selected one is gone
	removeAll() {
	    $.ajax({ url: this.o.urls.delAll, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': csrf() } })
		.done((resp) => {
		    this.restoreDeleteAll();
		    if (this.detail && this.deletable(this.detail))
			this.clearDetail();
		    //CLD: 2026-10-29T21:38:25+02:00: off the canvas
		    if (this.shownDetail && this.deletable(this.shownDetail))
			this.hide();
		    this.refresh();
		    const n = resp.deleted || 0;
		    toast('success', 'Deleted', (n === 1 ? '1 execution deleted' : n + ' executions deleted') + (resp.kept ? ', ' + resp.kept + ' kept (running / sleeping or of a published version)' : ''));
		})
		.fail((xhr) => {
		    this.restoreDeleteAll();
		    toast('error', 'Not deleted', (xhr.responseJSON && xhr.responseJSON.error) || 'The executions could not be deleted');
		});
	}
    }

    return WfExecutions;
})();
