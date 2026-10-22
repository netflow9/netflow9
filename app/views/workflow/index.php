<?php

$this->pageTitle = 'Workflow: ' . (string)$wf;

//we need to build a list of available nodes for the builder
//CLD: 2026-10-05T21:46:57+03:00: util\JSON::encode (same flags, shared)
$nodeDefs = \util\JSON::encode(\models\WfNode::getAll());

//canvas state comes from the query string; accept only numbers, as it is injected in JS
$__canvas_num = function($key) {
    $value = \V::app()->request->query[$key] ?? '';
    return is_numeric($value) ? (string)(float)$value : '';
};
$__canvas_pan_x = $__canvas_num('__canvas_pan_x');
$__canvas_pan_y = $__canvas_num('__canvas_pan_y');
$__canvas_zoom = $__canvas_num('__canvas_zoom');

$nodeEditUrl = \V::app()->createUrl('/workflow/node_edit');
//CLD: 2026-10-08T08:10:24+03:00: starting config of new steps (WorkflowController::node_init)
$nodeInitUrl = \V::app()->createUrl('/workflow/node_init', [ 'id' => (string)$wf->_id ]);
//CLD: 2026-10-11T22:38:36+03:00: live help of expression fields in the inspector (WorkflowController::expression)
$exprUrl = \V::app()->createUrl('/workflow/expression', [ 'id' => (string)$wf->_id ]);
//CLD: 2026-10-10T19:08:46+03:00: manual run + Executions panel (WorkflowController::start / executions / execution)
$runUrl = \V::app()->createUrl('/workflow/start', [ 'id' => (string)$wf->_id ]);
//CLD: 2026-10-12T20:33:47+03:00: the triggers Run can start (WfNode::startNow; was the Manual trigger only)
$runTriggers = \util\JSON::encode(array_keys(array_filter(\models\WfNode::getAll(), fn($d) => $d['type'] === 'trigger' && !empty($d['canRun']))));
$executionsUrl = \V::app()->createUrl('/workflow/executions', [ 'id' => (string)$wf->_id ]);
//CLD: 2026-10-19T18:05:00+03:00: autosave (WorkflowController::save)
$saveUrl = \V::app()->createUrl('/workflow/save', [ 'id' => (string)$wf->_id ]);
//CLD: 2026-10-19T19:50:00+03:00: the key of the remembered canvas pan / zoom (canvasView); an ObjectId: hex only, safe in the script
$wfIdJs = (string)$wf->_id;
$executionUrl = \V::app()->createUrl('/workflow/execution', [ 'id' => (string)$wf->_id ]);
$canRun = \V::app()->user->can('Workflow::update', [ 'object' => $wf ]) ? 'true' : 'false';
//CLD: 2026-10-11T13:51:42+03:00: versions (was a draft / published switch, 2026-10-11T13:29:36+03:00): the active version, whether the draft differs from it,
//the version being viewed (?version, read-only: its steps on the canvas)
$activeVersion = $wf->publishedVersion();
$isPublished = $wf->status === \models\Workflow::PUBLISHED && $activeVersion !== null;
$draftChanged = $activeVersion !== null && !$wf->draftMatches($activeVersion);
$shown = $viewVersion ? $wf->forVersion($viewVersion) : $wf;
$readonly = $viewVersion ? 'true' : 'false';
$canUpdate = \V::app()->user->can('Workflow::update', [ 'object' => $wf ]);
//CLD: 2026-10-11T19:47:51+03:00: Publish / Unpublish / Publish again (was $canUpdate)
$canPublish = \V::app()->user->can('Workflow::publish', [ 'object' => $wf ]);
//CLD: 2026-10-11T14:05:19+03:00: Delete
$canDelete = \V::app()->user->can('Workflow::delete', [ 'object' => $wf ]);
//CLD: 2026-10-11T14:01:52+03:00: Duplicate: creating a workflow in this one's project
$canDuplicate = \V::app()->user->can('Workflow::create', [ 'object' => \V::app()->om->create('Workflow', [ 'aid' => $wf->aid, 'project' => $wf->project ]) ]);

//CLD: 2026-10-06T23:25:57+03:00: connection problems of the saved workflow (Workflow::validateFlow): marked on the nodes, counted in the header
//CLD: 2026-10-11T13:51:42+03:00: of the version being viewed, if any
//CLD: 2026-10-19T17:10:00+03:00: + errors in the steps' settings (a draft is saved with them); the designer marks them until the step is changed (options.stepIssues)
$stepIssues = $shown->validateSteps();
$issues = array_merge($stepIssues, $shown->validateFlow());
$issuesJs = \util\JSON::encode($issues);
$stepIssuesJs = \util\JSON::encode($stepIssues);

//CLD: 2026-10-19T20:10:00+03:00: the Assistant's variables moved into the Assistant plugin (plugins/Assistant/views/designer_panel.php)


//CLD: 2026-10-10T19:08:46+03:00: Executions panel rows
\V::app()->cs->addPageCss("
.exec-row { line-height: 1.9; }
.exec-row:hover { background: rgba(255, 255, 255, 0.06); }
.exec-row.exec-selected { background: rgba(217, 119, 87, 0.25); }
/* CLD: 2026-10-23T02:00:00+03:00: the list wider (theme: 380px; on phones the theme's layout), a row on one line: the time and the duration never wrap, the trigger / version is cut */
@media (min-width: 768px) { .exec-panel .exec-list { width: 460px; } }
.exec-row { white-space: nowrap; }
.exec-row .exec-what { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.exec-row .exec-time, .exec-row .exec-duration { flex-shrink: 0; }
");

\V::app()->cs->addPageScript("
+function() {
    console.log('WfScript: nodeDefs=', {$nodeDefs});
    //CLD: 2026-10-19T22:00:00+03:00: this page's designer (app.Workflow, made below once the handlers exist; was the app.workflow module)
    let designer = null;

    //CLD: 2026-10-10T19:08:46+03:00: manual run + Executions panel: Run starts the saved workflow at its Manual trigger step(s) (runs on the server after the answer);
    //the panel lists the latest executions and shows one (summary, log, variables), refreshed every 2 s while one is new / running
    //CLD: 2026-10-19T19:35:00+03:00: the bottom panel last open (exec | debug; none when closed) is kept in localStorage (this browser) and opened again when a designer opens
    const bottomPanel = {
	KEY: 'qsm.wf.bottom.panel',
	get() {
	    try {
		return localStorage.getItem(this.KEY) || '';
	    } catch (e) {
		return '';
	    }
	},
	set(name) {
	    try {
		if (name)
		    localStorage.setItem(this.KEY, name);
		else
		    localStorage.removeItem(this.KEY);
	    } catch (e) {}
	},
	//a panel closed: forgotten only when it is the one kept (the other one's open() closes this one first)
	closed(name) {
	    if (this.get() === name)
		this.set('');
	},
    };

    const exec = {
	urls: { run: '{$runUrl}', list: '{$executionsUrl}', detail: '{$executionUrl}' },
	canRun: {$canRun},
	baseline: null,
	dirty: false,
	//CLD: 2026-10-12T20:33:47+03:00: hasTrigger (was hasManual): the workflow has a trigger Run can start
	runTriggers: {$runTriggers},
	hasTrigger: false,
	list: [],
	selected: null,
	detail: null,
	timer: null,

	esc: (v) => app.utils.escapeHtml(v === null || v === undefined ? '' : String(v)),
	panel: () => document.querySelector('.exec-panel'),
	csrf: () => $('meta[name=csrf-token]').attr('content'),

	//CLD: 2026-10-19T18:05:00+03:00: + current; baseline is the last saved state (markSaved: autosave), dirty = changes not saved yet
	current: null,
	onDataChange(workflow, serialized) {
	    if (this.baseline === null)
		this.baseline = serialized;
	    this.current = serialized;
	    this.dirty = serialized !== this.baseline;
	    this.hasTrigger = workflow.some((n) => n.isNode && this.runTriggers.includes(n.fqname));
	    let tip = 'Run (from the trigger; an Email trigger checks the mailbox now)';
	    if (!this.canRun)
		tip = 'You may not run this workflow';
	    else if (!this.hasTrigger)
		tip = 'This trigger cannot be run from here (it starts when its event happens)';
	    else if (this.dirty)
		tip = 'Run (after the changes are saved)';
	    $('.btn-run-wf').attr('title', tip).toggleClass('opacity-50', !this.canRun || !this.hasTrigger);
	},

	//CLD: 2026-10-19T18:05:00+03:00: the state `serialized` is saved
	markSaved(serialized) {
	    this.baseline = serialized;
	    this.dirty = this.current !== null && this.current !== this.baseline;
	},

	toast(type, title, body) {
	    app.utils.toast({ type: type, title: this.esc(title), body: this.esc(body), delay: 5000 });
	},

	run() {
	    if (!this.canRun || !this.hasTrigger)
		return;
	    //CLD: 2026-10-19T18:05:00+03:00: started once the changes are saved (autosave; was refused until saved)
	    if (this.dirty || designer.hasPendingChange()) {
		autosave.whenSaved(() => this.run());
		return;
	    }
	    $.ajax({ url: this.urls.run, method: 'POST', dataType: 'json', headers: { 'X-Csrf-Token': this.csrf() } })
		.done((resp) => {
		    //CLD: 2026-10-12T20:33:47+03:00: nothing started (e.g. no new mail) -> the reason; several executions (one per message) -> the first is shown
		    if (!resp.execution) {
			this.toast('info', 'Nothing started', resp.message || 'Nothing was started');
			return;
		    }
		    const n = (resp.executions || []).length;
		    this.toast('success', 'Started', n > 1 ? n + ' executions started (one per message)' : 'Execution started');
		    this.open();
		    this.select(resp.execution);
		})
		.fail((xhr) => this.toast('error', 'Not started', (xhr.responseJSON && xhr.responseJSON.error) || 'The workflow could not be started'));
	},

	open() {
	    const el = this.panel();
	    if (!el)
		return;
	    //CLD: 2026-10-11T19:57:28+03:00: one bottom panel at a time (they share the same place)
	    debug.close();
	    if (el.classList.contains('d-none')) {
		el.classList.remove('d-none');
		if (el.offsetHeight < 120)
		    el.style.height = '300px';
	    }
	    //CLD: 2026-10-19T19:35:00+03:00: remembered
	    bottomPanel.set('exec');
	    this.refresh();
	    if (!this.timer)
		this.timer = setInterval(() => this.tick(), 2000);
	},

	close() {
	    const el = this.panel();
	    if (el)
		el.classList.add('d-none');
	    clearInterval(this.timer);
	    this.timer = null;
	    //CLD: 2026-10-19T19:35:00+03:00: remembered
	    bottomPanel.closed('exec');
	},

	active: (state) => state === 'new' || state === 'run',

	tick() {
	    const el = this.panel();
	    //the page was left (pjax) or the panel closed
	    if (!el || !document.body.contains(el) || el.classList.contains('d-none')) {
		clearInterval(this.timer);
		this.timer = null;
		return;
	    }
	    if (this.list.some((e) => this.active(e.state)) || (this.detail && this.active(this.detail.state)))
		this.refresh();
	},

	refresh() {
	    $.getJSON(this.urls.list).done((resp) => {
		this.list = resp.executions || [];
		this.renderList();
	    });
	    if (this.selected)
		this.loadDetail(this.selected);
	},

	select(id) {
	    this.selected = id;
	    this.renderList();
	    this.loadDetail(id);
	},

	loadDetail(id) {
	    $.getJSON(this.urls.detail, { execution: id }).done((resp) => {
		if (this.selected !== id)
		    return;
		this.detail = resp.execution;
		this.renderDetail();
	    });
	},

	duration(ms) {
	    if (ms === null || ms === undefined)
		return '';
	    if (ms < 1000)
		return ms + ' ms';
	    if (ms < 60000)
		return (ms / 1000).toFixed(1) + ' s';
	    const sec = Math.round(ms / 1000);
	    return Math.floor(sec / 60) + 'm ' + String(sec % 60).padStart(2, '0') + 's';
	},

	//CLD: 2026-10-11T13:51:42+03:00: the published version an execution ran, or the draft
	versionLabel: (e) => e.version === null || e.version === undefined ? '(draft)' : '(v' + e.version + ')',

	badge(state) {
	    const colors = { end: 'success', error: 'danger', run: 'primary', new: 'info', sleep: 'warning', cancel: 'secondary' };
	    const labels = { end: 'completed', run: 'running', sleep: 'sleeping' };
	    return `<span class='badge text-bg-\${colors[state] || 'secondary'}'>\${this.esc(labels[state] || state)}</span>`;
	},

	renderList() {
	    const box = document.querySelector('.exec-list');
	    if (!box)
		return;
	    if (!this.list.length) {
		box.innerHTML = `<div style='color: var(--qsm-debug-muted)'>No executions yet\${this.hasTrigger ? ': use Run' : ''}</div>`;
		return;
	    }
	    box.innerHTML = this.list.map((e) => `
		<div class='exec-row d-flex align-items-center gap-2 px-1 rounded\${e.id === this.selected ? ' exec-selected' : ''}' data-id='\${this.esc(e.id)}' title='\${this.esc(e.error || '')}' style='cursor: pointer'>
		    \${this.badge(e.state)}
		    <span class='exec-time'>\${this.esc(e.started || e.created)}</span>
		    <span class='exec-what' style='color: var(--qsm-debug-muted)' title='\${this.esc(e.trigger + ' ' + this.versionLabel(e))}'>\${this.esc(e.trigger)} \${this.esc(this.versionLabel(e))}</span>
		    <span class='exec-duration ms-auto' style='color: var(--qsm-debug-muted)'>\${this.duration(e.duration)}</span>
		</div>`).join('');
	},

	renderDetail() {
	    const box = document.querySelector('.exec-detail');
	    const e = this.detail;
	    if (!box || !e)
		return;
	    const vars = JSON.stringify(e.variables || {}, null, 2);
	    const log = (e.log || []).map((l) => `<div class='debug-log-line'><span style='color: var(--qsm-debug-muted)'>\${this.esc((l.time || '').slice(11))}</span> \${l.title ? `<span class='tag'>[\${this.esc(l.title)}]</span>` : ''}\${this.esc(l.message)}</div>`).join('');
	    box.innerHTML = `
		<div class='mb-1'>\${this.badge(e.state)} <span style='color: var(--qsm-debug-muted)'>\${this.esc(e.trigger)} \${this.esc(this.versionLabel(e))}, started \${this.esc(e.started || e.created || '-')}\${e.duration !== null ? ', ' + this.duration(e.duration) : ''}\${e.wake_at ? ', wakes at ' + this.esc(e.wake_at) : ''}</span></div>
		\${e.error ? `<div class='text-danger mb-1'>\${this.esc(e.error)}</div>` : ''}
		<div class='debug-panel-title mt-2 mb-1'>Log</div>
		\${log || `<div style='color: var(--qsm-debug-muted)'>(empty)</div>`}
		<div class='debug-panel-title mt-2 mb-1'>Variables</div>
		<pre class='mb-0' style='color: inherit; white-space: pre-wrap'>\${this.esc(vars)}</pre>`;
	},
    };

    //CLD: 2026-10-11T19:57:28+03:00: the debug panel (opened / closed like the Executions panel; one bottom panel at a time): the workflow's errors and warnings, updated
    //while editing (onIssuesChange); a problem of a step selects that step on the canvas
    const debug = {
	issues: [],

	panel: () => document.querySelector('.wf-debug-panel'),

	open() {
	    const el = this.panel();
	    if (!el)
		return;
	    exec.close();
	    if (el.classList.contains('d-none')) {
		el.classList.remove('d-none');
		if (el.offsetHeight < 120)
		    el.style.height = '300px';
	    }
	    //CLD: 2026-10-19T19:35:00+03:00: remembered
	    bottomPanel.set('debug');
	    this.render();
	},

	close() {
	    const el = this.panel();
	    if (el)
		el.classList.add('d-none');
	    //CLD: 2026-10-19T19:35:00+03:00: remembered
	    bottomPanel.closed('debug');
	},

	setIssues(issues) {
	    this.issues = issues || [];
	    this.render();
	},

	//step titles from the current steps (the designer keeps [name=nodes] up to date)
	titles() {
	    const titles = {};
	    try {
		Object.values(JSON.parse($('[name=nodes]').val() || '{}')).forEach((n) => { if (n && n.wfid) titles[n.wfid] = n.title || n.wfid; });
	    } catch (err) {}
	    return titles;
	},

	render() {
	    const box = document.querySelector('.wf-debug-panel .debug-issues');
	    if (!box)
		return;
	    if (!this.issues.length) {
		box.innerHTML = `<div><i class='bi bi-check-circle text-success me-1'></i>No errors or warnings</div>`;
		return;
	    }
	    const titles = this.titles();
	    const order = { error: 0, warning: 1 };
	    const rows = [ ...this.issues ].sort((a, b) => (order[a.level] ?? 2) - (order[b.level] ?? 2)).map((i) => {
		const icon = i.level === 'error' ? 'bi-x-circle-fill text-danger' : 'bi-exclamation-triangle-fill text-warning';
		const node = i.node ? `data-node='\${exec.esc(i.node)}' role='button' title='Show the step'` : '';
		const step = i.node ? `<span class='tag'>[\${exec.esc(titles[i.node] || i.node)}]</span> ` : '';
		return `<div class='debug-log-line debug-issue' \${node}><i class='bi \${icon} me-1'></i>\${step}\${exec.esc(i.message)}</div>`;
	    });
	    box.innerHTML = rows.join('');
	},
    };

    //CLD: 2026-10-11T13:51:42+03:00: Publish needs the draft saved (it publishes the saved draft); was the `publish` switch object
    //CLD: 2026-10-11T14:01:52+03:00: + Duplicate (copies the saved draft); stopImmediatePropagation: the data-confirm handler is on the document too
    //CLD: 2026-10-19T18:05:00+03:00: with changes not saved yet: done once they are saved (autosave; was refused until saved)
    $('.btn-duplicate-wf, .btn-publish-draft').on('click', (e) => {
	if (exec.dirty || designer.hasPendingChange()) {
	    e.preventDefault();
	    e.stopImmediatePropagation();
	    e.stopPropagation();
	    const el = e.currentTarget;
	    autosave.whenSaved(() => el.click());
	}
    });

    $('.btn-run-wf').on('click', (e) => {
	e.preventDefault();
	exec.run();
    });
    $('.btn-exec-toggle').on('click', (e) => {
	e.preventDefault();
	const el = exec.panel();
	if (el && el.classList.contains('d-none'))
	    exec.open();
	else
	    exec.close();
    });
    $('.btn-exec-refresh').on('click', (e) => {
	e.preventDefault();
	exec.refresh();
    });
    $('.btn-exec-close').on('click', (e) => {
	e.preventDefault();
	exec.close();
    });
    $('.exec-panel').on('click', '.exec-row', function() {
	exec.select(this.dataset.id);
    });


    //CLD: 2026-10-19T18:05:00+03:00: autosave: each new state of the canvas (app.workflow.js onCommit: every change, undo / redo too) is saved (WorkflowController::save), one request at a
    //time, the latest state waiting; the server's configs come back on the canvas (applySaved), its problems in the badges; a failed save is tried again (RETRY ms, or a
    //click on the status). whenSaved(fn): fn once nothing is waiting (Run, Publish, Duplicate use the saved draft)
    const autosave = {
	url: '{$saveUrl}',
	RETRY: 10000,
	sending: null,
	queued: null,
	error: null,
	retryTimer: null,
	waiting: [],

	nodesOf: (snapshot) => {
	    const nodes = {};
	    JSON.parse(snapshot).forEach((n) => nodes[n.wfid] = n);
	    return nodes;
	},
	busy() {
	    return this.sending !== null || this.queued !== null || designer.hasPendingChange();
	},

	onCommit(snapshot) {
	    this.queued = snapshot;
	    this.send();
	},

	send() {
	    clearTimeout(this.retryTimer);
	    this.retryTimer = null;
	    if (this.sending !== null || this.queued === null) {
		this.status();
		return;
	    }
	    const snapshot = this.sending = this.queued;
	    this.queued = null;
	    this.status();
	    $.ajax({ url: this.url, method: 'POST', dataType: 'json', data: { nodes: JSON.stringify(this.nodesOf(snapshot)) }, headers: { 'X-Csrf-Token': exec.csrf() } })
		.done((resp) => {
		    this.sending = null;
		    this.error = null;
		    //the server's configs on the canvas (when nothing changed since), then its errors in the steps' settings
		    const applied = designer.applySaved(snapshot, resp.nodes || []);
		    designer.setStepIssues(resp.stepIssues || []);
		    exec.markSaved(JSON.stringify(applied ? this.nodesOf(JSON.stringify(designer.getNodes())) : this.nodesOf(snapshot)));
		    versions.update(!!resp.draftChanged);
		    this.send();
		    this.done();
		})
		.fail((xhr) => {
		    this.sending = null;
		    const first = this.error === null;
		    this.error = (xhr.responseJSON && xhr.responseJSON.error) || 'The changes could not be saved (' + (xhr.status || 'no connection') + ')';
		    if (first)
			exec.toast('error', 'Not saved', this.error + '. Trying again.');
		    //the latest state is saved next
		    if (this.queued === null)
			this.queued = snapshot;
		    this.status();
		    this.retryTimer = setTimeout(() => this.send(), this.RETRY);
		});
	},

	whenSaved(fn) {
	    designer.commitNow();
	    if (!this.busy() && !exec.dirty) {
		fn();
		return;
	    }
	    this.waiting = [ fn ];
	    this.status();
	},
	done() {
	    if (this.busy() || this.error !== null || !this.waiting.length)
		return;
	    const list = this.waiting;
	    this.waiting = [];
	    list.forEach((fn) => fn());
	},

	status() {
	    const el = document.querySelector('.wf-save-status');
	    if (!el)
		return;
	    //CLD: 2026-10-19T22:19:24+03:00: the texts in .btn-label (only the icon on phones; the title says it)
	    let html = '<i class=\"bi bi-cloud-check me-1\"></i><span class=\"btn-label\">Saved</span>', cls = 'text-muted', tip = 'All changes are saved';
	    if (this.error !== null && this.sending === null) {
		[ html, cls, tip ] = [ '<i class=\"bi bi-cloud-slash me-1\"></i><span class=\"btn-label\">Not saved</span>', 'text-danger', this.error + ' (click to try again now)' ];
	    } else if (this.sending !== null || this.queued !== null) {
		[ html, cls, tip ] = [ '<span class=\"spinner-border spinner-border-sm me-1\" style=\"width: .7rem; height: .7rem\"></span><span class=\"btn-label\">Saving&hellip;</span>', 'text-muted', 'Saving the changes' ];
	    }
	    el.innerHTML = html;
	    el.className = 'wf-save-status small px-1 text-nowrap ' + cls;
	    el.title = tip;
	},
    };
    $('.wf-save-status').on('click', (e) => {
	e.preventDefault();
	if (autosave.error !== null)
	    autosave.send();
    });
    //CLD: 2026-10-19T18:05:00+03:00: Undo / Redo
    $('.btn-undo-wf').on('click', (e) => {
	e.preventDefault();
	designer.undo();
    });
    $('.btn-redo-wf').on('click', (e) => {
	e.preventDefault();
	designer.redo();
    });

    //CLD: 2026-10-19T18:05:00+03:00: the version menu follows the saved draft: `draft changed` and which Publish item (wf-if-changed / wf-if-same) is shown
    const versions = {
	update(changed) {
	    const btn = document.querySelector('.wf-versions-btn');
	    if (!btn)
		return;
	    const hasVersion = btn.dataset.hasVersion === '1';
	    btn.querySelector('.wf-draft-changed')?.classList.toggle('d-none', !changed);
	    btn.title = btn.dataset.tip + (changed ? '; the draft has changes that are not published' : '');
	    const differs = changed || !hasVersion;
	    document.querySelectorAll('.wf-if-changed').forEach((el) => el.classList.toggle('d-none', !differs));
	    document.querySelectorAll('.wf-if-same').forEach((el) => el.classList.toggle('d-none', differs));
	},
    };

    //CLD: 2026-10-19T18:05:00+03:00: leaving with changes not saved: a change still waiting is committed when a link is clicked (in-app pages load without leaving: its save goes on);
    //the browser asks before closing / reloading while something is not saved. Once per page load (the designer page is loaded again by app.pjax): app.wfAutosave is the current one
    app.wfAutosave = autosave;
    if (!window.__wfAutosaveGuard) {
	window.__wfAutosaveGuard = true;
	document.addEventListener('click', (e) => {
	    if (app.wfAutosave && document.getElementById('canvas-wrap') && e.target.closest && e.target.closest('a[href]'))
		app.Workflow.current?.commitNow();
	}, true);
	window.addEventListener('beforeunload', (e) => {
	    if (!app.wfAutosave || !document.getElementById('canvas-wrap'))
		return;
	    app.Workflow.current?.commitNow();
	    if (app.wfAutosave.busy() || app.wfAutosave.error !== null) {
		e.preventDefault();
		e.returnValue = '';
	    }
	});
    }

    //CLD: 2026-10-19T19:50:00+03:00: the canvas pan / zoom per workflow, kept in localStorage (this browser): { <workflow id>: { x, y, zoom, t } } in one key, the MAX most recent
    //workflows; written PAUSE ms after the view stops moving (panning / zooming call onCanvasChange on every move)
    const canvasView = {
	KEY: 'qsm.wf.canvas',
	MAX: 50,
	PAUSE: 300,
	wfid: '{$wfIdJs}',
	timer: null,
	read() {
	    try {
		const all = JSON.parse(localStorage.getItem(this.KEY) || '{}');
		return all && typeof all === 'object' && !Array.isArray(all) ? all : {};
	    } catch (e) {
		return {};
	    }
	},
	stored() {
	    const v = this.read()[this.wfid];
	    if (!v || ![ v.x, v.y, v.zoom ].every((n) => typeof n === 'number' && Number.isFinite(n)) || v.zoom <= 0)
		return null;
	    return v;
	},
	store(pan, zoom) {
	    clearTimeout(this.timer);
	    this.timer = setTimeout(() => {
		const x = Number(pan.x), y = Number(pan.y), z = Number(zoom);
		if (![ x, y, z ].every(Number.isFinite) || z <= 0)
		    return;
		const all = this.read();
		all[this.wfid] = { x: x, y: y, zoom: z, t: Date.now() };
		const ids = Object.keys(all).sort((a, b) => (all[b].t || 0) - (all[a].t || 0));
		ids.slice(this.MAX).forEach((id) => delete all[id]);
		try {
		    localStorage.setItem(this.KEY, JSON.stringify(all));
		} catch (e) {}
	    }, this.PAUSE);
	},
    };

    const canvas_pan_x = '{$__canvas_pan_x}';
    const canvas_pan_y = '{$__canvas_pan_y}';
    const canvas_zoom = '{$__canvas_zoom}';

    const options = {
	//CLD: 2026-10-11T13:51:42+03:00: a published version is shown read-only
	readonly: {$readonly},
	workflow: JSON.parse($('[name=nodes]').val()),
	nodeDefs: {$nodeDefs},
	//CLD: 2026-10-19T17:10:00+03:00: errors in the steps' settings found by the server
	stepIssues: {$stepIssuesJs},
	canvas: {},
	//CLD: 2026-10-06T23:33:47+03:00: live problem badges in the header (the designer validates while editing)
	onIssuesChange: (issues) => {
	    const box = document.getElementById('wf-issues');
	    if (!box)
		return;
	    let html = '';
	    [ [ 'error', 'danger' ], [ 'warning', 'warning' ] ].forEach(([ level, color ]) => {
		const list = issues.filter((i) => i.level === level);
		if (!list.length)
		    return;
		//escapeHtml escapes double quotes (not single ones): title is double-quoted
		const tip = app.utils.escapeHtml(list.map((i) => '- ' + i.message).join('\\n'));
		//CLD: 2026-10-11T19:57:28+03:00: + btn-debug-open: a click opens the debug panel (the list)
		html += `<span class='badge text-bg-\${color} me-1 btn-debug-open' role='button' title=\"\${tip}\"><i class='bi bi-exclamation-triangle me-1'></i>\${list.length} \${level}\${list.length > 1 ? 's' : ''}</span>`;
	    });
	    box.innerHTML = html;
	    //CLD: 2026-10-11T19:57:28+03:00: the debug panel lists them too
	    debug.setIssues(issues);
	},
	onDataChange: (workflow) => {
	    //console.log('index.php: data changed:', workflow);
	    const nodes = {};
	    workflow.forEach((n) => nodes[n.wfid] = n);
	    $('[name=nodes]').text(JSON.stringify(nodes));
	    //CLD: 2026-10-10T19:08:46+03:00: unsaved changes (the first call is the saved state) and whether there is a Manual trigger: what Run can do
	    exec.onDataChange(workflow, JSON.stringify(nodes));
	    //console.log('onDataChange: nodes=', JSON.stringify(nodes, null, 2));
	},
	//CLD: 2026-10-19T18:05:00+03:00: autosave of each new state; the Undo / Redo buttons
	onCommit: (snapshot) => autosave.onCommit(snapshot),
	onHistoryChange: (h) => {
	    $('.btn-undo-wf').toggleClass('disabled opacity-50', !h.canUndo).attr('aria-disabled', h.canUndo ? 'false' : 'true');
	    $('.btn-redo-wf').toggleClass('disabled opacity-50', !h.canRedo).attr('aria-disabled', h.canRedo ? 'false' : 'true');
	},
	//CLD: 2026-10-19T18:40:00+03:00: removed onCanvasChange: it kept pan / zoom in the form's action for the reload after the form-post save (gone: autosave)
	//CLD: 2026-10-19T19:50:00+03:00: onCanvasChange again: pan / zoom remembered for this workflow (canvasView)
	onCanvasChange: (pan, zoom) => canvasView.store(pan, zoom),
	nodeEditUrl: '{$nodeEditUrl}',
	nodeInitUrl: '{$nodeInitUrl}',
	exprUrl: '{$exprUrl}',
    };

    if (canvas_zoom.length)
	options.canvas.zoom = canvas_zoom;
    if (canvas_pan_x.length && canvas_pan_y.length)
	options.canvas.pan = { x: canvas_pan_x, y: canvas_pan_y };
    //CLD: 2026-10-19T19:50:00+03:00: else the pan / zoom this workflow had last time (none: the view is fitted, as before)
    if (!options.canvas.zoom && !options.canvas.pan) {
	const view = canvasView.stored();
	if (view) {
	    options.canvas.zoom = view.zoom;
	    options.canvas.pan = { x: view.x, y: view.y };
	}
    }

    //CLD: 2026-10-19T22:00:00+03:00: new app.Workflow (was app.workflow.mount): `designer` is this page's (it destroys the one of a previous designer page)
    designer = new app.Workflow('canvas-wrap', options);
    //CLD: 2026-10-19T20:10:00+03:00: the Assistant is mounted by its plugin (designer_panel.php, after this script)

    $('#canvas-form .btn-zoom-fit').on('click', (e) => {
	e.preventDefault();
	designer.fitView();
    });

    //---------------------debug panel
    //CLD: 2026-10-11T19:57:28+03:00: toggles the debug panel only, as the Executions button does (was: every .debug-panel, the Executions panel too); the badges open it
    $('.btn-debug-toggle').on('click', (e) => {
	e.preventDefault();
	const el = debug.panel();
	if (el && el.classList.contains('d-none'))
	    debug.open();
	else
	    debug.close();
    });
    $('.btn-debug-close').on('click', (e) => {
	e.preventDefault();
	debug.close();
    });
    $('#wf-issues').on('click', '.btn-debug-open', (e) => {
	e.preventDefault();
	debug.open();
    });
    $('.wf-debug-panel').on('click', '.debug-issue[data-node]', function() {
	designer.showNode(this.dataset.node);
    });

    //CLD: 2026-10-19T19:35:00+03:00: the bottom panel open last time (after mount: the problems are known); Executions only where its button is (the draft)
    if (bottomPanel.get() === 'exec' && document.querySelector('.btn-exec-toggle'))
	exec.open();
    else if (bottomPanel.get() === 'debug')
	debug.open();

    //CLD: 2026-10-19T18:05:00+03:00: removed the Save button's handler: changes are saved automatically (autosave)

    //CLD: 2026-10-19T22:19:24+03:00: the small-screen note: closed once, not shown again in this browser (localStorage may be blocked: then it shows again)
    const NOTE_KEY = 'qsm.wf.smallScreenNote';
    const note = document.querySelector('.wf-small-screen-note');
    if (note) {
	try {
	    if (localStorage.getItem(NOTE_KEY))
		note.remove();
	} catch (e) {}
	note.addEventListener('closed.bs.alert', () => {
	    try {
		localStorage.setItem(NOTE_KEY, '1');
	    } catch (e) {}
	});
    }

}();
");

$formOptions = [
    'id' => 'canvas-form',
    'style' => 'height:100%',
    'class' => 'd-flex',
    'action' => \V::app()->createUrl('', [ 'id' => $wf->_id ]),
];
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	//CLD: 2026-10-05T20:42:44+03:00: Breadcrumbs prints labels as HTML; escape the user-editable name
	[ 'label' => \V::app()->html->escape((string)$wf->name) ],
    ]]);
    echo $bc->render();
    ?>

    <div class="spacer"></div>
    <?php
    //CLD: 2026-10-06T23:25:57+03:00: problem counts, the messages as tooltip
    $issueBadges = '';
    foreach([ 'error' => 'danger', 'warning' => 'warning' ] as $level => $color) {
	$list = array_values(array_filter($issues, fn($i) => $i['level'] == $level));
	if (empty($list))
	    continue;
	$label = count($list) . ' ' . $level . (count($list) > 1 ? 's' : '');
	$tip = \V::app()->html->escape(implode("\n", array_map(fn($i) => '- ' . $i['message'], $list)));
	$issueBadges .= "<span class='badge text-bg-{$color} me-1' title='{$tip}'><i class='bi bi-exclamation-triangle me-1'></i>{$label}</span>";
    }
    //CLD: 2026-10-06T23:33:47+03:00: container updated live by the designer (onIssuesChange); the server-side badges are the first state
    echo "<span id='wf-issues'>{$issueBadges}</span>";
    ?>
    <div>
	<?php
	//CLD: 2026-10-11T13:51:42+03:00: versions (was the draft / published switch): viewing a version shows what it is and its actions; else a menu with the
	//published version (if any), whether the draft differs, Publish, Unpublish and the Versions dialog
	$id = (string)$wf->_id;
	$esc = fn($v) => \V::app()->html->escape((string)$v);
	$versionsUrl = \V::app()->createUrl('/workflow/versions', [ 'id' => $id ]);
	if ($viewVersion) {
	    $isActive = $isPublished && (string)$activeVersion->_id === (string)$viewVersion->_id;
	    echo "<span class='badge text-bg-warning me-2'><i class='bi bi-eye me-1'></i>Viewing " . $esc($viewVersion) . ($isActive ? ' (published)' : '') . ", read-only</span>";
	    echo "<a href='" . \V::app()->createUrl('/workflow/index', [ 'id' => $id ]) . "' class='btn btn-sm btn-outline-secondary py-0 me-1'>Back to the draft</a>";
	    //CLD: 2026-10-11T19:47:51+03:00: Publish again: $canPublish, Copy into the draft: $canUpdate
	    if ($canPublish && !$isActive)
		echo "<a href='" . \V::app()->createUrl('/workflow/publish', [ 'id' => $id, 'version' => (string)$viewVersion->_id ]) . "' class='btn btn-sm btn-outline-success py-0 me-1'>Publish again</a>";
	    if ($canUpdate)
		echo "<a href='" . \V::app()->createUrl('/workflow/restore', [ 'id' => $id, 'version' => (string)$viewVersion->_id ]) . "' class='btn btn-sm btn-outline-primary py-0 me-1' data-method='post' data-confirm='Replace the draft with the steps of " . $esc($viewVersion) . "?'>Copy into the draft</a>";
	    //CLD: 2026-10-11T14:01:52+03:00: + Duplicate this version (a new draft workflow)
	    if ($canDuplicate)
		echo "<a href='" . \V::app()->createUrl('/workflow/duplicate', [ 'id' => $id, 'version' => (string)$viewVersion->_id ]) . "' class='btn btn-sm btn-outline-secondary py-0 me-1' data-method='post' data-confirm='Create a new workflow from " . $esc($viewVersion) . "?'>Duplicate</a>";
	    echo "<a href='{$versionsUrl}' class='btn btn-sm btn-outline-secondary py-0 me-1'>Versions</a>";
	} else {
	    if ($isPublished)
		[ $btnClass, $label, $tip ] = [ 'btn-success', '<i class="bi bi-broadcast me-1"></i>' . $esc($activeVersion) . ' published', 'Its trigger starts runs of ' . $esc($activeVersion) ];
	    elseif ($activeVersion)
		[ $btnClass, $label, $tip ] = [ 'btn-outline-secondary', '<i class="bi bi-pause-circle me-1"></i>' . $esc($activeVersion) . ' unpublished', 'Its trigger does not start runs' ];
	    else
		[ $btnClass, $label, $tip ] = [ 'btn-outline-secondary', '<i class="bi bi-pencil-square me-1"></i>Draft', 'Never published: its trigger does not start runs (Run does)' ];
	    //CLD: 2026-10-19T18:05:00+03:00: `draft changed` is always there when there is a version, hidden when not changed: the autosave updates it (versions.update); the button's own tip in data-tip
	    $baseTip = $tip;
	    if ($activeVersion)
		//CLD: 2026-10-19T22:19:24+03:00: the text in .btn-label (only the dot on phones; the button's title says it)
		$label .= ' <span class="wf-draft-changed' . ($draftChanged ? '' : ' d-none') . '"><i class="bi bi-dot"></i><span class="btn-label">draft changed</span></span>';
	    if ($draftChanged) {
		$tip .= '; the draft has changes that are not published';
	    }

	    $items = '';
	    //CLD: 2026-10-11T19:47:51+03:00: $canPublish (was $canUpdate)
	    if ($canPublish) {
		//CLD: 2026-10-19T18:05:00+03:00: both items: the draft differs from the version (wf-if-changed) or not (wf-if-same); the one not shown is hidden, the autosave switches them (versions.update)
		$publishUrl = \V::app()->createUrl('/workflow/publish', [ 'id' => $id ]);
		$differs = !$activeVersion || $draftChanged;
		$items .= "<li class='wf-if-changed" . ($differs ? '' : ' d-none') . "'><a class='dropdown-item btn-publish-draft' href='{$publishUrl}'><i class='bi bi-broadcast me-2'></i>Publish the draft as v" . ((int)$wf->version_count + 1) . "&hellip;</a></li>";
		if ($activeVersion) {
		    if ($isPublished)
			$items .= "<li class='wf-if-same" . ($differs ? ' d-none' : '') . "'><span class='dropdown-item-text text-muted small'>The draft is published</span></li>";
		    else
			$items .= "<li class='wf-if-same" . ($differs ? ' d-none' : '') . "'><a class='dropdown-item btn-publish-draft' href='{$publishUrl}'><i class='bi bi-broadcast me-2'></i>Publish " . $esc($activeVersion) . " again</a></li>";
		}
		if ($isPublished)
		    $items .= "<li><a class='dropdown-item' href='" . \V::app()->createUrl('/workflow/unpublish', [ 'id' => $id ]) . "' data-method='post' data-confirm='Unpublish: its trigger stops starting runs?'><i class='bi bi-pause-circle me-2'></i>Unpublish</a></li>";
		$items .= "<li><hr class='dropdown-divider'></li>";
	    }
	    $items .= "<li><a class='dropdown-item' href='{$versionsUrl}'><i class='bi bi-clock me-2'></i>Versions</a></li>";
	    //CLD: 2026-10-11T14:01:52+03:00: + Duplicate (the saved draft, as a new draft workflow)
	    if ($canDuplicate)
		$items .= "<li><a class='dropdown-item btn-duplicate-wf' href='" . \V::app()->createUrl('/workflow/duplicate', [ 'id' => $id ]) . "' data-method='post' data-confirm='Create a new workflow from the saved draft?'><i class='bi bi-copy me-2'></i>Duplicate</a></li>";
	    //CLD: 2026-10-11T14:05:19+03:00: + Delete (the workflow, its executions and versions)
	    if ($canDelete)
		$items .= "<li><hr class='dropdown-divider'></li><li><a class='dropdown-item text-danger' href='" . \V::app()->createUrl('/workflow/delete', [ 'id' => $id ]) . "' data-method='post' data-confirm='Delete this workflow with all its versions and executions? This cannot be undone.'><i class='bi bi-trash me-2'></i>Delete</a></li>";

	    echo "
	    <div class='dropdown d-inline-block me-1'>
		<button type='button' class='btn btn-sm py-0 px-2 dropdown-toggle wf-versions-btn {$btnClass}' data-bs-toggle='dropdown' aria-expanded='false' title='{$tip}' data-tip='{$baseTip}' data-has-version='" . ($activeVersion ? '1' : '0') . "'>{$label}</button>
		<ul class='dropdown-menu dropdown-menu-end'>{$items}</ul>
	    </div>";
	}
	?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-zoom-fit d-inline"><i class="bi bi-aspect-ratio"></i></a>
	<?php if (!$viewVersion): //CLD: 2026-10-11T13:51:42+03:00: the draft's tools only (not when viewing a version) ?>
	<?php //CLD: 2026-10-10T19:08:46+03:00: Run (from the Manual trigger) and the Executions panel ?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-run-wf text-success d-inline" title="Run"><i class="bi bi-play-fill"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-exec-toggle d-inline" title="Executions"><i class="bi bi-clock-history"></i></a>
	<?php //CLD: 2026-10-11T19:57:28+03:00: + title ?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-debug-toggle d-inline" title="Debug: errors and warnings"><i class="bi bi-bug"></i></a>
	<?php //CLD: 2026-10-19T18:55:00+03:00: the Variables button hidden for now (WorkflowController::variables is kept); put back: ?>
	<?php /* <a href="<?= \V::app()->createUrl('/workflow/variables', [ 'id' => $wf->_id ]) ?>" class="px-1 btn btn-sm btn-icon d-inline"><i class="bi bi-code-slash"></i></a> */ ?>
	<a href="<?= \V::app()->createUrl('/workflow/update_name', [ 'id' => $wf->_id ]) ?>" class="px-1 btn btn-sm btn-icon d-inline"><i class="bi bi-gear"></i></a>
	<?php //CLD: 2026-10-19T18:05:00+03:00: Undo / Redo and the autosave status (was the Save button: each change is saved) ?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-undo-wf d-inline disabled opacity-50" title="Undo (Ctrl+Z)" aria-disabled="true"><i class="bi bi-arrow-counterclockwise"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-redo-wf d-inline disabled opacity-50" title="Redo (Ctrl+Shift+Z)" aria-disabled="true"><i class="bi bi-arrow-clockwise"></i></a>
	<?php //CLD: 2026-10-19T22:19:24+03:00: the text in .btn-label (only the icon on phones) ?>
	<a href="#" class="wf-save-status small px-1 text-nowrap text-muted text-decoration-none" title="All changes are saved"><i class="bi bi-cloud-check me-1"></i><span class="btn-label">Saved</span></a>
	<?php endif; ?>
	<?php //CLD: 2026-10-19T20:10:00+03:00: the plugins' buttons (e.g. the Assistant's, which opens its panel); also when viewing a version ?>
	<?php \V::app()->hooks->applyHook('workflow:designer-toolbar', [ $wf, $viewVersion ]); ?>
    </div>
</div>
<?php //CLD: 2026-10-19T22:19:24+03:00: on phones (below md), a note that the designer is made for larger screens; once closed, not shown again in this browser ?>
<div class="alert alert-info alert-dismissible small d-md-none m-2 mb-0 py-2 wf-small-screen-note">
    <i class="bi bi-display me-1"></i>The designer works best on a larger screen. You can still view and run this workflow here.
    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<div class="main-body">
    <!-- ---------- Canvas ---------- -->
    <?= \V::app()->form->create($wf, $formOptions); ?>
	<?php //CLD: 2026-10-11T13:51:42+03:00: the steps shown: the draft, or the version being viewed ?>
	<?php //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing) ?>
	<textarea name="nodes" class="d-none"><?= \V::app()->html->escape(\util\JSON::encode(array_values(is_array($shown->nodes) ? $shown->nodes : []))) ?></textarea>

	<div id="canvas-wrap" class="position-relative overflow-hidden flex-grow-1">
	    <div class="position-absolute top-0 start-0 canvas-main">
		<svg class="position-absolute top-0 start-0 canvas-edges"></svg>
	    </div>
	</div>

	<?php //CLD: 2026-10-19T20:10:00+03:00: the panels on the right of the canvas come from the plugins (e.g. the Assistant: plugins/Assistant/views/designer_panel.php; was the inspector
	//holding the Assistant here); inside the canvas form, so their inputs have no `name` ?>
	<?php \V::app()->hooks->applyHook('workflow:designer-panels', [ $wf, $viewVersion ]); ?>
    <?= \V::app()->form->end(); ?>

</div>
<!-- DEBUG PANEL: drag the handle above it to resize vertically -->
<?php //CLD: 2026-10-11T19:57:28+03:00: wf-debug-panel (its own toggle, as the Executions panel), the workflow's errors and warnings (debug.render); removed the Clear button (nothing to clear) ?>
<div class="debug-panel wf-debug-panel d-none" style="background: var(--qsm-debug-bg)">
    <div class="debug-resize-handle"></div>
    <div class="debug-panel-header">
	<div class="debug-panel-title"><i class="bi bi-bug"></i> Debug</div>
	<div class="debug-panel-actions">
	    <button class="debug-icon-btn btn-debug-close" title="Close panel"><i class="bi bi-x-lg"></i></button>
	</div>
    </div>
    <div class="debug-panel-body overflow-auto py-2 px-3">
	<div class="debug-panel-title mb-1">Errors and warnings</div>
	<div class="debug-issues"></div>
    </div>
</div>
<?php //CLD: 2026-10-10T19:08:46+03:00: Executions panel (same look as the debug panel): the list on the left, the selected execution on the right ?>
<div class="debug-panel exec-panel d-none" style="background: var(--qsm-debug-bg)">
    <div class="debug-resize-handle"></div>
    <div class="debug-panel-header">
	<div class="debug-panel-title"><i class="bi bi-clock-history"></i> Executions</div>
	<div class="debug-panel-actions">
	    <button class="debug-icon-btn btn-exec-refresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
	    <button class="debug-icon-btn btn-exec-close" title="Close panel"><i class="bi bi-x-lg"></i></button>
	</div>
    </div>
    <?php //CLD: 2026-10-19T22:19:24+03:00: the list's width is in the theme (qsm/_workflow.scss: .exec-panel .exec-list; was style width 380px): the list goes above the details on phones ?>
    <div class="debug-panel-body d-flex gap-3 p-0">
	<div class="exec-list overflow-auto py-2 ps-3 pe-2 border-end border-secondary"></div>
	<div class="exec-detail overflow-auto flex-grow-1 py-2 pe-3"><span style="color: var(--qsm-debug-muted)">Select an execution</span></div>
    </div>
</div>
