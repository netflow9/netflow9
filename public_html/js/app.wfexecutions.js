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

    class WfExecutions {
	constructor(panel, options) {
	    if (current)
		current.destroy();
	    current = this;

	    this.el = typeof panel === 'string' ? document.querySelector(panel) : panel;
	    this.o = Object.assign({ urls: {}, canRun: false, runTriggers: [], whenSaved: (fn) => fn(), onOpen: () => {}, onClose: () => {} }, options || {});
	    //baseline: the last saved state (markSaved), current: the latest, dirty: changes not saved yet
	    this.baseline = null;
	    this.current = null;
	    this.dirty = false;
	    this.hasTrigger = false;
	    this.list = [];
	    this.selected = null;
	    this.detail = null;
	    this.timer = null;
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

	close() {
	    if (this.el)
		this.el.classList.add('d-none');
	    clearInterval(this.timer);
	    this.timer = null;
	    this.o.onClose();
	}

	tick() {
	    //the page was left (pjax) or the panel closed
	    if (!this.el || !document.body.contains(this.el) || this.el.classList.contains('d-none')) {
		clearInterval(this.timer);
		this.timer = null;
		return;
	    }
	    if (this.list.some((e) => active(e.state)) || (this.detail && active(this.detail.state)))
		this.refresh();
	}

	refresh() {
	    $.getJSON(this.o.urls.list).done((resp) => {
		this.list = resp.executions || [];
		this.renderList();
	    });
	    if (this.selected)
		this.loadDetail(this.selected);
	}

	select(id) {
	    this.selected = id;
	    this.renderList();
	    this.loadDetail(id);
	}

	loadDetail(id) {
	    $.getJSON(this.o.urls.detail, { execution: id }).done((resp) => {
		if (this.selected !== id)
		    return;
		this.detail = resp.execution;
		this.renderDetail();
	    });
	}

	find(selector) {
	    return this.el ? this.el.querySelector(selector) : null;
	}

	renderList() {
	    const box = this.find('.exec-list');
	    if (!box)
		return;
	    if (!this.list.length) {
		box.innerHTML = `<div style='color: var(--netflow9-debug-muted)'>No executions yet${this.hasTrigger ? ': use Run' : ''}</div>`;
		return;
	    }
	    box.innerHTML = this.list.map((e) => `
		<div class='exec-row d-flex align-items-center gap-2 px-1 rounded${e.id === this.selected ? ' exec-selected' : ''}' data-id='${esc(e.id)}' title='${esc(e.error || '')}' style='cursor: pointer'>
		    ${badge(e.state)}
		    <span class='exec-time'>${esc(e.started || e.created)}</span>
		    <span class='exec-what' style='color: var(--netflow9-debug-muted)' title='${esc(e.trigger + ' ' + versionLabel(e))}'>${esc(e.trigger)} ${esc(versionLabel(e))}</span>
		    <span class='exec-duration ms-auto' style='color: var(--netflow9-debug-muted)'>${duration(e.duration)}</span>
		</div>`).join('');
	}

	renderDetail() {
	    const box = this.find('.exec-detail');
	    const e = this.detail;
	    if (!box || !e)
		return;
	    const vars = JSON.stringify(e.variables || {}, null, 2);
	    const log = (e.log || []).map((l) => `<div class='debug-log-line'><span style='color: var(--netflow9-debug-muted)'>${esc((l.time || '').slice(11))}</span> ${l.title ? `<span class='tag'>[${esc(l.title)}]</span>` : ''}${esc(l.message)}</div>`).join('');
	    //Delete: an execution of the draft that has finished (asked again in place: Yes / No)
	    const del = this.deletable(e) ? `<span class='exec-delete ms-auto flex-shrink-0'><button type='button' class='debug-icon-btn btn-exec-delete' title='Delete this execution'><i class='bi bi-trash'></i></button></span>` : '';
	    //CLD: 2026-10-26T10:14:16+02:00: Kill: one that is new / running / waiting (Kill? Yes / No; kept over the 2 s refresh while asked: askKill)
	    const kill = !this.killable(e) ? '' : `<span class='exec-kill ms-auto flex-shrink-0'>` + (this.askKill === e.id
		? `<span class='me-1'>Kill?</span><button type='button' class='debug-icon-btn btn-exec-kill-yes text-danger' title='Yes, stop it'><i class='bi bi-check-lg'></i></button><button type='button' class='debug-icon-btn btn-exec-kill-no' title='No'><i class='bi bi-x-lg'></i></button>`
		: `<button type='button' class='debug-icon-btn btn-exec-kill text-danger' title='Kill this execution'><i class='bi bi-stop-circle'></i></button>`) + `</span>`;
	    box.innerHTML = `
		<div class='mb-1 d-flex align-items-center gap-1'><span>${badge(e.state)} <span style='color: var(--netflow9-debug-muted)'>${esc(e.trigger)} ${esc(versionLabel(e))}, started ${esc(e.started || e.created || '-')}${e.duration !== null ? ', ' + duration(e.duration) : ''}${e.wake_at ? ', wakes at ' + esc(e.wake_at) : ''}</span></span>${del}${kill}</div>
		${e.error ? `<div class='text-danger mb-1'>${esc(e.error)}</div>` : ''}
		<div class='debug-panel-title mt-2 mb-1'>Log</div>
		${log || `<div style='color: var(--netflow9-debug-muted)'>(empty)</div>`}
		<div class='debug-panel-title mt-2 mb-1'>Variables</div>
		<pre class='mb-0' style='color: inherit; white-space: pre-wrap'>${esc(vars)}</pre>`;
	}

	clearDetail() {
	    this.selected = null;
	    this.detail = null;
	    const box = this.find('.exec-detail');
	    if (box)
		box.innerHTML = `<span style='color: var(--netflow9-debug-muted)'>Select an execution</span>`;
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
