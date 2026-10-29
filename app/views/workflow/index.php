<?php

$this->pageTitle = 'Workflow: ' . (string)$wf;

//we need to build a list of available nodes for the builder
//CLD: 2026-10-25T20:00:00+02:00: only their version here (WfNode::designerDefs, which builds them): the designer asks them when this tab does not have that version (nodeDefs below,
//WorkflowController::node_defs); were in each page (~85 KB, ~250 KB in the pjax answer)
$nodeDefsVersion = \models\WfNode::designerDefs(\V::app()->config('rt.account'))['version'];
$nodeDefsUrl = \V::app()->createUrl('/workflow/node_defs');
//the canvas of this page (mount: not one of a page loaded since, while the definitions were asked); hex only, safe in the script
$pageToken = bin2hex(random_bytes(6));

//canvas state comes from the query string; accept only numbers, as it is injected in JS
$__canvas_num = function($key) {
    $value = \V::app()->request->query[$key] ?? '';
    return is_numeric($value) ? (string)(float)$value : '';
};
$__canvas_pan_x = $__canvas_num('__canvas_pan_x');
$__canvas_pan_y = $__canvas_num('__canvas_pan_y');
$__canvas_zoom = $__canvas_num('__canvas_zoom');

$nodeEditUrl = \V::app()->createUrl('/workflow/node_edit');
$nodeInitUrl = \V::app()->createUrl('/workflow/node_init', [ 'id' => (string)$wf->_id ]);
$exprUrl = \V::app()->createUrl('/workflow/expression', [ 'id' => (string)$wf->_id ]);
$runUrl = \V::app()->createUrl('/workflow/start', [ 'id' => (string)$wf->_id ]);
$runTriggers = \util\JSON::encode(array_keys(array_filter(\models\WfNode::getAll(), fn($d) => $d['type'] === 'trigger' && !empty($d['canRun']))));
$executionsUrl = \V::app()->createUrl('/workflow/executions', [ 'id' => (string)$wf->_id ]);
$saveUrl = \V::app()->createUrl('/workflow/save', [ 'id' => (string)$wf->_id ]);
$wfIdJs = (string)$wf->_id;
$executionUrl = \V::app()->createUrl('/workflow/execution', [ 'id' => (string)$wf->_id ]);
$executionDeleteUrl = \V::app()->createUrl('/workflow/execution_delete', [ 'id' => (string)$wf->_id ]);
$executionsDeleteUrl = \V::app()->createUrl('/workflow/executions_delete', [ 'id' => (string)$wf->_id ]);
//CLD: 2026-10-26T10:14:16+02:00: Kill in the Executions panel
$executionKillUrl = \V::app()->createUrl('/workflow/execution_kill', [ 'id' => (string)$wf->_id ]);
$canRun = \V::app()->user->can('Workflow::update', [ 'object' => $wf ]) ? 'true' : 'false';
//the version being viewed (?version, read-only: its steps on the canvas)
$activeVersion = $wf->publishedVersion();
$isPublished = $wf->status === \models\Workflow::PUBLISHED && $activeVersion !== null;
$draftChanged = $activeVersion !== null && !$wf->draftMatches($activeVersion);
$shown = $viewVersion ? $wf->forVersion($viewVersion) : $wf;
$readonly = $viewVersion ? 'true' : 'false';
$canUpdate = \V::app()->user->can('Workflow::update', [ 'object' => $wf ]);
$canPublish = \V::app()->user->can('Workflow::publish', [ 'object' => $wf ]);
$canDelete = \V::app()->user->can('Workflow::delete', [ 'object' => $wf ]);
$canDuplicate = \V::app()->user->can('Workflow::create', [ 'object' => \V::app()->om->create('Workflow', [ 'aid' => $wf->aid, 'project' => $wf->project ]) ]);

//CLD: 2026-10-06T23:25:57+03:00: connection problems of the saved workflow (Workflow::validateFlow): marked on the nodes, counted in the header
//CLD: 2026-10-11T13:51:42+03:00: of the version being viewed, if any
//CLD: 2026-10-19T17:10:00+03:00: + errors in the steps' settings (a draft is saved with them); the designer marks them until the step is changed (options.stepIssues)
//CLD: 2026-10-25T19:30:00+02:00: the errors in the steps' settings are asked once the designer is shown (WorkflowController::step_issues, stepCheck below): validateSteps
//was the slowest part of this page; the header badges start with the connection problems
$issues = $shown->validateFlow();
$issuesJs = \util\JSON::encode($issues);
$stepIssuesUrl = \V::app()->createUrl('/workflow/step_issues', array_filter([ 'id' => (string)$wf->_id, 'version' => $viewVersion ? (string)$viewVersion->_id : null ]));


\V::app()->cs->addPageScript("
+function() {
    let designer = null;

    //CLD: 2026-10-10T19:08:46+03:00: manual run + Executions panel: Run starts the saved workflow at its Manual trigger step(s) (runs on the server after the answer);
    //the panel lists the latest executions and shows one (summary, log, variables), refreshed every 2 s while one is new / running
    //CLD: 2026-10-19T19:35:00+03:00: the bottom panel last open (exec | debug; none when closed) is kept in localStorage (this browser) and opened again when a designer opens
    const bottomPanel = {
	KEY: 'netflow9.wf.bottom.panel',
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

    //CLD: 2026-10-25T20:30:00+02:00: a toast with plain texts (was exec.toast)
    const toast = (type, title, body) => app.utils.toast({ type: type, title: app.utils.escapeHtml(title), body: app.utils.escapeHtml(body), delay: 5000 });
    const csrf = () => $('meta[name=csrf-token]').attr('content');

    //CLD: 2026-10-25T20:30:00+02:00: Run and the Executions panel: app.WfExecutions (app.wfexecutions.js; was the `exec` object here), the Debug panel: app.WfDebug (app.wfdebug.js;
    //was `debug`); one bottom panel at a time, the one last open remembered (bottomPanel)
    const exec = new app.WfExecutions(document.querySelector('.exec-panel'), {
	urls: { run: '{$runUrl}', list: '{$executionsUrl}', detail: '{$executionUrl}', del: '{$executionDeleteUrl}', delAll: '{$executionsDeleteUrl}', kill: '{$executionKillUrl}' },
	canRun: {$canRun},
	//CLD: 2026-10-12T20:33:47+03:00: the triggers Run can start
	runTriggers: {$runTriggers},
	//CLD: 2026-10-19T18:05:00+03:00: started once the changes are saved (autosave; was refused until saved)
	whenSaved: (fn) => autosave.whenSaved(fn),
	onOpen: () => {
	    debug.close();
	    bottomPanel.set('exec');
	},
	onClose: () => bottomPanel.closed('exec'),
	//CLD: 2026-10-29T21:30:00+02:00: the selected execution's steps and connections on the canvas (done, running, waiting, failed; null: none)
	onShow: (run) => designer && designer.setRun(run),
    });
    const debug = new app.WfDebug(document.querySelector('.wf-debug-panel'), {
	//step titles from the current steps (the designer keeps [name=nodes] up to date)
	steps: () => {
	    try {
		return Object.values(JSON.parse($('[name=nodes]').val() || '{}'));
	    } catch (err) {
		return [];
	    }
	},
	onShowNode: (id) => designer && designer.showNode(id),
	onOpen: () => {
	    exec.close();
	    bottomPanel.set('debug');
	},
	onClose: () => bottomPanel.closed('debug'),
    });

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

    //CLD: 2026-10-25T20:30:00+02:00: the Run and Executions handlers: in app.WfExecutions

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
	//CLD: 2026-10-25T19:30:00+02:00: saves sent since the page was shown
	sent: 0,

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
	    //CLD: 2026-10-25T19:30:00+02:00: a save was sent: its answer has the step errors (stepCheck's are not used then)
	    this.sent++;
	    this.status();
	    $.ajax({ url: this.url, method: 'POST', dataType: 'json', data: { nodes: JSON.stringify(this.nodesOf(snapshot)) }, headers: { 'X-Csrf-Token': csrf() } })
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
			toast('error', 'Not saved', this.error + '. Trying again.');
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
	KEY: 'netflow9.wf.canvas',
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
	readonly: {$readonly},
	workflow: JSON.parse($('[name=nodes]').val()),
	nodeDefs: null,
	stepIssues: [],
	canvas: {},
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
	onCommit: (snapshot) => autosave.onCommit(snapshot),
	onHistoryChange: (h) => {
	    $('.btn-undo-wf').toggleClass('disabled opacity-50', !h.canUndo).attr('aria-disabled', h.canUndo ? 'false' : 'true');
	    $('.btn-redo-wf').toggleClass('disabled opacity-50', !h.canRedo).attr('aria-disabled', h.canRedo ? 'false' : 'true');
	},
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

    //CLD: 2026-10-25T20:00:00+02:00: the step definitions of version {$nodeDefsVersion}: kept in this tab (app.wfNodeDefs: pages loaded by app.pjax share it), asked when missing or
    //of another version (a deploy, the account's plan changed); the designer is made once they are here
    const nodeDefs = {
	url: '{$nodeDefsUrl}',
	version: '{$nodeDefsVersion}',
	get(fn) {
	    const kept = app.wfNodeDefs;
	    if (kept && kept.version === this.version) {
		fn(kept.nodeDefs);
		return;
	    }
	    //the designer of the previous page (still current until this one is made) goes now: its listeners would act on a canvas no longer shown
	    app.Workflow.current?.destroy();
	    $.ajax({ url: this.url, method: 'GET', dataType: 'json' })
		.done((resp) => {
		    app.wfNodeDefs = { version: resp.version, nodeDefs: resp.nodeDefs || {} };
		    fn(app.wfNodeDefs.nodeDefs);
		})
		.fail((xhr) => toast('error', 'Designer', 'The step types could not be loaded (' + (xhr.status || 'no connection') + '): reload the page'));
	},
    };

    const mount = (defs) => {
	//the page was left while they were asked
	const wrap = document.getElementById('canvas-wrap');
	if (!wrap || wrap.dataset.page !== '{$pageToken}')
	    return;
	options.nodeDefs = defs;

	designer = new app.Workflow('canvas-wrap', options);

	//CLD: 2026-10-25T19:30:00+02:00: the errors in the steps' settings, asked once the designer is shown (were computed in the page, delaying it); not used when
	//the page was left (another designer is the current one) or the steps were changed since (a save answers with its own; a change not committed yet would
	//take them as about its new config)
	const stepCheck = {
	    url: '{$stepIssuesUrl}',
	    load() {
		const shown = designer;
		$.ajax({ url: this.url, method: 'GET', dataType: 'json' })
		    .done((resp) => {
			if (app.Workflow.current !== shown || autosave.sent || shown.hasPendingChange())
			    return;
			shown.setStepIssues(resp.stepIssues || []);
		    });
	    },
	};
	stepCheck.load();

	//CLD: 2026-10-19T19:35:00+03:00: the bottom panel open last time (after mount: the problems are known); Executions only where its button is (the draft)
	if (bottomPanel.get() === 'exec' && document.querySelector('.btn-exec-toggle'))
	    exec.open();
	else if (bottomPanel.get() === 'debug')
	    debug.open();
    };
    nodeDefs.get(mount);

    $('.btn-zoom-fit').on('click', (e) => {
	e.preventDefault();
	designer.fitView();
    });
    $('.btn-zoom-in, .btn-zoom-out').on('click', function(e) {
	e.preventDefault();
	designer.zoomBy(this.classList.contains('btn-zoom-in') ? 1.2 : 1 / 1.2);
    });

    const NOTE_KEY = 'netflow9.wf.smallScreenNote';
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
	$versionsUrl = \V::app()->createUrl('/workflow/versions', [ 'id' => $id ]);
	if ($viewVersion) {
	    $isActive = $isPublished && (string)$activeVersion->_id === (string)$viewVersion->_id;
	    echo "<span class='badge text-bg-warning me-2'><i class='bi bi-eye me-1'></i>Viewing " . \V::app()->html->escape($viewVersion) . ($isActive ? ' (published)' : '') . ", read-only</span>";
	    echo "<a href='" . \V::app()->createUrl('/workflow/index', [ 'id' => $id ]) . "' class='btn btn-sm btn-outline-secondary py-0 me-1'>Back to the draft</a>";
	    //CLD: 2026-10-11T19:47:51+03:00: Publish again: $canPublish, Copy into the draft: $canUpdate
	    if ($canPublish && !$isActive)
		echo "<a href='" . \V::app()->createUrl('/workflow/publish', [ 'id' => $id, 'version' => (string)$viewVersion->_id ]) . "' class='btn btn-sm btn-outline-success py-0 me-1'>Publish again</a>";
	    if ($canUpdate)
		echo "<a href='" . \V::app()->createUrl('/workflow/restore', [ 'id' => $id, 'version' => (string)$viewVersion->_id ]) . "' class='btn btn-sm btn-outline-primary py-0 me-1' data-method='post' data-confirm='Replace the draft with the steps of " . \V::app()->html->escape($viewVersion) . "?'>Copy into the draft</a>";
	    if ($canDuplicate)
		echo "<a href='" . \V::app()->createUrl('/workflow/duplicate', [ 'id' => $id, 'version' => (string)$viewVersion->_id ]) . "' class='btn btn-sm btn-outline-secondary py-0 me-1' data-method='post' data-confirm='Create a new workflow from " . \V::app()->html->escape($viewVersion) . "?'>Duplicate</a>";
	    echo "<a href='{$versionsUrl}' class='btn btn-sm btn-outline-secondary py-0 me-1'>Versions</a>";
	} else {
	    if ($isPublished)
		[ $btnClass, $label, $tip ] = [ 'btn-success', '<i class="bi bi-broadcast me-1"></i>' . \V::app()->html->escape($activeVersion) . ' published', 'Its trigger starts runs of ' . \V::app()->html->escape($activeVersion) ];
	    elseif ($activeVersion)
		[ $btnClass, $label, $tip ] = [ 'btn-outline-secondary', '<i class="bi bi-pause-circle me-1"></i>' . \V::app()->html->escape($activeVersion) . ' unpublished', 'Its trigger does not start runs' ];
	    else
		[ $btnClass, $label, $tip ] = [ 'btn-outline-secondary', '<i class="bi bi-pencil-square me-1"></i>Draft', 'Never published: its trigger does not start runs (Run does)' ];
	    $baseTip = $tip;
	    if ($activeVersion)
		$label .= ' <span class="wf-draft-changed' . ($draftChanged ? '' : ' d-none') . '"><i class="bi bi-dot"></i><span class="btn-label">draft changed</span></span>';
	    if ($draftChanged) {
		$tip .= '; the draft has changes that are not published';
	    }

	    $items = '';
	    if ($canPublish) {
		//CLD: 2026-10-19T18:05:00+03:00: both items: the draft differs from the version (wf-if-changed) or not (wf-if-same); the one not shown is hidden, the autosave switches them (versions.update)
		$publishUrl = \V::app()->createUrl('/workflow/publish', [ 'id' => $id ]);
		$differs = !$activeVersion || $draftChanged;
		$items .= "<li class='wf-if-changed" . ($differs ? '' : ' d-none') . "'><a class='dropdown-item btn-publish-draft' href='{$publishUrl}'><i class='bi bi-broadcast me-2'></i>Publish the draft as v" . ((int)$wf->version_count + 1) . "&hellip;</a></li>";
		if ($activeVersion) {
		    if ($isPublished)
			$items .= "<li class='wf-if-same" . ($differs ? ' d-none' : '') . "'><span class='dropdown-item-text text-muted small'>The draft is published</span></li>";
		    else
			$items .= "<li class='wf-if-same" . ($differs ? ' d-none' : '') . "'><a class='dropdown-item btn-publish-draft' href='{$publishUrl}'><i class='bi bi-broadcast me-2'></i>Publish " . \V::app()->html->escape($activeVersion) . " again</a></li>";
		}
		if ($isPublished)
		    $items .= "<li><a class='dropdown-item' href='" . \V::app()->createUrl('/workflow/unpublish', [ 'id' => $id ]) . "' data-method='post' data-confirm='Unpublish: its trigger stops starting runs?'><i class='bi bi-pause-circle me-2'></i>Unpublish</a></li>";
		$items .= "<li><hr class='dropdown-divider'></li>";
	    }
	    $items .= "<li><a class='dropdown-item' href='{$versionsUrl}'><i class='bi bi-clock me-2'></i>Versions</a></li>";
	    if ($canDuplicate)
		$items .= "<li><a class='dropdown-item btn-duplicate-wf' href='" . \V::app()->createUrl('/workflow/duplicate', [ 'id' => $id ]) . "' data-method='post' data-confirm='Create a new workflow from the saved draft?'><i class='bi bi-copy me-2'></i>Duplicate</a></li>";
	    if ($canDelete)
		$items .= "<li><hr class='dropdown-divider'></li><li><a class='dropdown-item text-danger' href='" . \V::app()->createUrl('/workflow/delete', [ 'id' => $id ]) . "' data-method='post' data-confirm='Delete this workflow with all its versions and executions? This cannot be undone.'><i class='bi bi-trash me-2'></i>Delete</a></li>";

	    echo "
	    <div class='dropdown d-inline-block me-1'>
		<button type='button' class='btn btn-sm py-0 px-2 dropdown-toggle wf-versions-btn {$btnClass}' data-bs-toggle='dropdown' aria-expanded='false' title='{$tip}' data-tip='{$baseTip}' data-has-version='" . ($activeVersion ? '1' : '0') . "'>{$label}</button>
		<ul class='dropdown-menu dropdown-menu-end'>{$items}</ul>
	    </div>";
	}
	?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-zoom-out d-inline" title="Zoom out"><i class="bi bi-zoom-out"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-zoom-fit d-inline" title="Fit the workflow to the view"><i class="bi bi-aspect-ratio"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-zoom-in d-inline" title="Zoom in"><i class="bi bi-zoom-in"></i></a>
	<?php if (!$viewVersion):  ?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-run-wf text-success d-inline" title="Run"><i class="bi bi-play-fill"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-exec-toggle d-inline" title="Executions"><i class="bi bi-clock-history"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-debug-toggle d-inline" title="Debug: errors and warnings"><i class="bi bi-bug"></i></a>
	<?php /* <a href="<?= \V::app()->createUrl('/workflow/variables', [ 'id' => $wf->_id ]) ?>" class="px-1 btn btn-sm btn-icon d-inline"><i class="bi bi-code-slash"></i></a> */ ?>
	<a href="<?= \V::app()->createUrl('/workflow/update_name', [ 'id' => $wf->_id ]) ?>" class="px-1 btn btn-sm btn-icon d-inline"><i class="bi bi-gear"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-undo-wf d-inline disabled opacity-50" title="Undo (Ctrl+Z)" aria-disabled="true"><i class="bi bi-arrow-counterclockwise"></i></a>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-redo-wf d-inline disabled opacity-50" title="Redo (Ctrl+Shift+Z)" aria-disabled="true"><i class="bi bi-arrow-clockwise"></i></a>
	<a href="#" class="wf-save-status small px-1 text-nowrap text-muted text-decoration-none" title="All changes are saved"><i class="bi bi-cloud-check me-1"></i><span class="btn-label">Saved</span></a>
	<?php endif; ?>
	<?php //CLD: 2026-10-26T23:49:50+02:00: the Assistant's button: opens / closes its panel (app.WfAssistant; was the Assistant plugin's, hook workflow:designer-toolbar) ?>
	<a href="#" class="px-1 btn btn-sm btn-icon btn-assistant-toggle d-inline" title="Assistant: describe the workflow or a change" aria-pressed="false"><i class="bi bi-stars"></i></a>
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
	<textarea name="nodes" class="d-none"><?= \V::app()->html->escape(\util\JSON::encode(array_values(is_array($shown->nodes) ? $shown->nodes : []))) ?></textarea>

	<?php //CLD: 2026-10-25T20:00:00+02:00: + data-page (mount() in the script) ?>
	<div id="canvas-wrap" class="position-relative overflow-hidden flex-grow-1" data-page="<?= $pageToken ?>">
	    <div class="position-absolute top-0 start-0 canvas-main">
		<svg class="position-absolute top-0 start-0 canvas-edges"></svg>
	    </div>
	</div>

	<?php //CLD: 2026-10-26T23:49:50+02:00: the Assistant's panel (was the Assistant plugin's, hook workflow:designer-panels) ?>
	<?= $this->renderFile('/views/assistant/_designer_panel', [ 'wf' => $wf, 'viewVersion' => $viewVersion ]) ?>
	<?php \V::app()->hooks->applyHook('workflow:designer-panels', [ $wf, $viewVersion ]); ?>
    <?= \V::app()->form->end(); ?>

</div>
<!-- DEBUG PANEL: drag the handle above it to resize vertically -->
<div class="debug-panel wf-debug-panel d-none" style="background: var(--netflow9-debug-bg)">
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
<div class="debug-panel exec-panel d-none" style="background: var(--netflow9-debug-bg)">
    <div class="debug-resize-handle"></div>
    <div class="debug-panel-header">
	<div class="debug-panel-title"><i class="bi bi-clock-history"></i> Executions</div>
	<div class="debug-panel-actions">
	    <?php if (!$viewVersion && \V::app()->user->can('Workflow::update', [ 'object' => $wf ])): ?>
	    <span class="exec-delete-all d-inline-flex align-items-center"><button type="button" class="debug-icon-btn btn-exec-delete-all" title="Delete all executions of the draft (running / sleeping ones and those of published versions are kept)"><i class="bi bi-trash"></i></button></span>
	    <?php endif; ?>
	    <button class="debug-icon-btn btn-exec-refresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
	    <button class="debug-icon-btn btn-exec-close" title="Close panel"><i class="bi bi-x-lg"></i></button>
	</div>
    </div>
    <?php //CLD: 2026-10-19T22:19:24+03:00: the list's width is in the theme (netflow9/_workflow.scss: .exec-panel .exec-list; was style width 380px): the list goes above the details on phones ?>
    <div class="debug-panel-body d-flex gap-3 p-0">
	<div class="exec-list overflow-auto py-2 ps-3 pe-2 border-end border-secondary"></div>
	<div class="exec-detail overflow-auto flex-grow-1 py-2 pe-3"><span style="color: var(--netflow9-debug-muted)">Select an execution</span></div>
    </div>
</div>
