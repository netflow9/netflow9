//CLD: 2026-10-19T16:05:12+03:00: the model may also be an OpenAI-compatible one (the chosen credential's; texts no longer name Claude)
//CLD: 2026-10-19T15:26:28+03:00: new: the Assistant of the workflow designer (the right-side inspector): the person describes the workflow or a change, Claude builds it on the
//canvas (WorkflowController::assistant, util\WorkflowAssistant). The designer's current steps go with each request (unsaved changes included); the answer's steps replace
//the canvas (app.workflow.setNodes), not saved: the person reviews and saves them. Each change can be undone (the steps before it, kept while the page lives).
//CLD: 2026-10-19T18:05:00+03:00: the canvas is saved automatically now (each change, the Assistant's too) and the designer's Undo (Ctrl+Z) puts it back
//The conversation is kept per workflow in sessionStorage (saving reloads the designer)
//CLD: 2026-10-19T20:10:00+03:00: moved into the Assistant plugin (app/plugins/Assistant/js; was public_html/js): served by AssistantController::script, the requests go to
//AssistantController::ask; + panel(): the panel is opened / closed by the designer's top-bar button, open or closed as last time
//CLD: 2026-10-19T21:40:00+03:00: a class, as app.CodeEditor / app.ExprInput (was the app.wfassistant module with panel() and mount()):
//
//   new app.WfAssistant(panel, { wfid, url })
//CLD: 2026-10-19T22:00:00+03:00: + options.designer: the app.Workflow it works on (default: app.Workflow.current, the page's; was the app.workflow module)
//CLD: 2026-10-20T10:00:00+03:00: no credentials: the server and model are those of Assistant settings (options.credentials, .wfa-credential and the stored credential are gone)
//
//panel: the Assistant's panel of the designer (#wf-assistant-panel, plugins/Assistant/views/designer_panel.php). It is open or closed as last time, the top-bar buttons
//(.btn-assistant-toggle) toggle it, .wfa-close closes it; the conversation is set up when the panel has one (.wfa-messages: the draft, who may change it, Assistant settings set up).
//One per designer page: a new one destroys the previous one (its request is dropped); destroy() also stops listening to the page.
if (!window.app)
    window.app = {};

window.app.WfAssistant = (function() {
    //the steps before each change: { '<wfid>:<message id>': nodes } (kept while the page lives: shared by the designer pages app.pjax loads)
    const undo = {};
    const MAX_KEPT = 40;
    //CLD: 2026-10-19T20:10:00+03:00: whether the panel is open, kept in localStorage (this browser; closed the first time)
    const OPEN_KEY = 'qsm.wf.assistant.open';

    //the one of the current designer page
    let current = null;

    const esc = (v) => app.utils.escapeHtml(v === null || v === undefined ? '' : String(v));

    //plain text with `code`, **bold** and line breaks
    function format(text) {
	return esc(text)
	    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
	    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
	    .replace(/\n/g, '<br>');
    }

    const newId = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

    class WfAssistant {
	constructor(panel, options) {
	    if (current)
		current.destroy();
	    current = this;

	    this.panel = typeof panel === 'string' ? document.querySelector(panel) : panel;
	    this.o = Object.assign({ wfid: '', url: '', designer: null }, options || {});
	    this.state = { messages: [] };
	    this.xhr = null;
	    this.destroyed = false;
	    //the listeners on elements outside the panel (removed by destroy)
	    this.off = [];
	    if (!this.panel)
		return;

	    this.setupPanel();
	    //the conversation: only when the panel has it (designer_panel.php)
	    this.root = this.panel.querySelector('.wf-assistant');
	    if (!this.root || !this.root.querySelector('.wfa-messages'))
		return;
	    this.load();
	    this.bind();
	    this.render();
	}

	//its request dropped, its listeners outside the panel removed (the panel goes away with the page)
	destroy() {
	    this.destroyed = true;
	    if (this.xhr) {
		const old = this.xhr;
		this.xhr = null;
		old.abort();
	    }
	    this.off.forEach((f) => f());
	    this.off = [];
	    if (current === this)
		current = null;
	}

	/* ---------------- the panel ---------------- */
	//CLD: 2026-10-19T20:10:00+03:00: as last time; the top-bar button toggles it, .wfa-close closes it (was panel())
	setupPanel() {
	    let open = false;
	    try {
		open = localStorage.getItem(OPEN_KEY) === '1';
	    } catch (e) {}
	    this.showPanel(open, false);
	    const toggle = (e) => {
		e.preventDefault();
		this.showPanel(this.panel.classList.contains('d-none'), true);
	    };
	    document.querySelectorAll('.btn-assistant-toggle').forEach((b) => {
		b.addEventListener('click', toggle);
		this.off.push(() => b.removeEventListener('click', toggle));
	    });
	    this.panel.querySelectorAll('.wfa-close').forEach((b) => b.addEventListener('click', (e) => {
		e.preventDefault();
		this.showPanel(false, true);
	    }));
	}

	//the panel open or closed; the top-bar buttons show it; remember: kept for the next designers (a person's click)
	showPanel(open, remember) {
	    this.panel.classList.toggle('d-none', !open);
	    document.querySelectorAll('.btn-assistant-toggle').forEach((b) => {
		b.classList.toggle('active', open);
		b.setAttribute('aria-pressed', open ? 'true' : 'false');
	    });
	    if (!remember)
		return;
	    try {
		localStorage.setItem(OPEN_KEY, open ? '1' : '0');
	    } catch (e) {}
	    const input = this.panel.querySelector('.wfa-input');
	    if (open && input && !input.disabled)
		input.focus();
	}

	/* ---------------- the conversation ---------------- */
	key() {
	    return 'wf-assistant:' + this.o.wfid;
	}

	//CLD: 2026-10-19T22:00:00+03:00: the designer it works on (options.designer, else the page's)
	designer() {
	    return this.o.designer || app.Workflow.current;
	}

	load() {
	    this.state = { messages: [] };
	    try {
		const saved = JSON.parse(sessionStorage.getItem(this.key()) || 'null');
		if (saved && Array.isArray(saved.messages))
		    this.state = { messages: saved.messages };
	    } catch (e) {}
	}

	store() {
	    try {
		sessionStorage.setItem(this.key(), JSON.stringify({ messages: this.state.messages.slice(-MAX_KEPT) }));
	    } catch (e) {}
	}

	messageHtml(m) {
	    if (m.role === 'user')
		return `<div class="d-flex justify-content-end mb-2"><div class="wfa-msg wfa-user rounded-3 px-2 py-1 small">${format(m.text)}</div></div>`;
	    if (m.error)
		return `<div class="mb-2"><div class="wfa-msg rounded-3 px-2 py-1 small text-danger-emphasis bg-danger-subtle"><i class="bi bi-exclamation-triangle me-1"></i>${format(m.text)}</div></div>`;
	    let change = '';
	    if (m.change) {
		const c = m.change;
		const issues = [];
		if (c.errors)
		    issues.push(`<span class="text-danger">${c.errors} error${c.errors > 1 ? 's' : ''}</span>`);
		if (c.warnings)
		    issues.push(`<span class="text-warning-emphasis">${c.warnings} warning${c.warnings > 1 ? 's' : ''}</span>`);
		const canUndo = !!undo[this.o.wfid + ':' + m.id];
		change = `
		    <div class="d-flex align-items-center gap-2 mt-1 pt-1 border-top small">
			<span class="text-success"><i class="bi bi-diagram-3 me-1"></i>Canvas updated: ${c.steps} step${c.steps === 1 ? '' : 's'}</span>
			${issues.length ? `<span>(${issues.join(', ')})</span>` : ''}
			${canUndo ? `<button type="button" class="btn btn-link btn-sm p-0 ms-auto wfa-undo" data-id="${esc(m.id)}" title="Put back the steps as they were before this change"><i class="bi bi-arrow-counterclockwise me-1"></i>Undo</button>` : ''}
		    </div>`;
		//CLD: 2026-10-19T17:02:48+03:00: the settings still to fill in (the steps are applied anyway; saving needs them)
		if (c.toFix && c.toFix.length)
		    change += `
		    <div class="mt-1 small text-warning-emphasis">
			<div><i class="bi bi-pencil-square me-1"></i>To fill in before saving (double-click the step):</div>
			<ul class="ps-3 mb-0">${c.toFix.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>
		    </div>`;
	    } else if (m.unchanged) {
		//CLD: 2026-10-19T17:02:48+03:00: said plainly: the answer alone may read as if something was built
		change = `<div class="mt-1 pt-1 border-top small text-muted"><i class="bi bi-dash-circle me-1"></i>The canvas was not changed</div>`;
	    }
	    //CLD: 2026-10-22T15:00:00+03:00: System: the credits the answer used, and those left
	    let credits = '';
	    if (m.credits)
		credits = `<div class="mt-1 text-muted" style="font-size: .75rem"><i class="bi bi-coin me-1"></i>&minus;${esc(m.credits)} credit${m.credits === 1 ? '' : 's'}${m.creditsLeft != null ? ` &middot; ${esc(m.creditsLeft)} left` : ''}</div>`;
	    return `<div class="mb-2"><div class="wfa-msg wfa-bot rounded-3 px-2 py-1 small"><div>${format(m.text)}</div>${change}${credits}</div></div>`;
	}

	render() {
	    const list = this.root.querySelector('.wfa-messages');
	    let html = '';
	    if (!this.state.messages.length) {
		html = `
		    <div class="text-muted small">
			<p class="mb-2">Describe the workflow you need in your own words, and the Assistant builds its steps and connections on the canvas. You can also ask for changes to what is there.</p>
			<p class="mb-2">For example:</p>
			<ul class="ps-3 mb-2">
			    <li><a href="#" class="wfa-example">When an invoice email arrives, extract the amount with Claude and, above 10000, send it to finance</a></li>
			    <li><a href="#" class="wfa-example">Every morning at 8, download the exchange rates and store them in a data table</a></li>
			    <li><a href="#" class="wfa-example">Add error handling to the HTTP request: on error, send me an email</a></li>
			</ul>
			<p class="mb-0"><i class="bi bi-info-circle me-1"></i>Changes are saved automatically: Undo (Ctrl+Z) puts the canvas back. To change a step yourself, double-click it on the canvas.</p>
		    </div>`;
	    } else {
		html = this.state.messages.map((m) => this.messageHtml(m)).join('');
	    }
	    if (this.xhr)
		html += `<div class="mb-2 small text-muted wfa-pending"><span class="spinner-border spinner-border-sm me-2"></span>The assistant is working on it&hellip; this can take a minute</div>`;
	    list.innerHTML = html;
	    list.scrollTop = list.scrollHeight;

	    const busy = !!this.xhr;
	    this.root.querySelector('.wfa-input').disabled = busy;
	    this.root.querySelector('.wfa-send').classList.toggle('d-none', busy);
	    this.root.querySelector('.wfa-stop').classList.toggle('d-none', !busy);
	    this.root.querySelector('.wfa-clear').disabled = busy || !this.state.messages.length;
	}

	add(m) {
	    m.id = m.id || newId();
	    this.state.messages.push(m);
	    this.store();
	    this.render();
	    return m;
	}

	//the earlier turns for the server: the person's requests and the answers (not the failed ones)
	history() {
	    const turns = [];
	    this.state.messages.forEach((m, i) => {
		if (m.error)
		    return;
		//a request whose answer failed is left out
		const next = this.state.messages[i + 1];
		if (m.role === 'user' && next && next.error)
		    return;
		turns.push({ role: m.role, text: m.text + (m.change ? '\n(The canvas was updated.)' : '') });
	    });
	    return turns;
	}

	send(text) {
	    text = (text || '').trim();
	    if (!text || this.xhr || this.destroyed)
		return;
	    const turns = this.history();
	    const before = this.designer().getNodes();
	    const sent = JSON.stringify(before);
	    this.add({ role: 'user', text: text });
	    this.root.querySelector('.wfa-input').value = '';

	    const req = this.xhr = $.ajax({
		url: this.o.url,
		method: 'POST',
		dataType: 'json',
		headers: { 'X-Csrf-Token': $('meta[name=csrf-token]').attr('content') },
		data: { message: text, history: JSON.stringify(turns), nodes: sent },
	    });
	    this.render();
	    req.done((resp) => {
		//dropped (another page was opened: destroy)
		if (this.xhr !== req)
		    return;
		const m = { role: 'assistant', text: resp.reply || '' };
		//CLD: 2026-10-22T15:00:00+03:00: System: the credits used (AssistantController::ask)
		if (resp.credits) {
		    m.credits = resp.credits;
		    m.creditsLeft = resp.credits_left;
		}
		if (Array.isArray(resp.nodes)) {
		    //the canvas was changed meanwhile: the person decides
		    if (JSON.stringify(this.designer().getNodes()) !== sent && !confirm('You changed the canvas while the assistant was working. Replace it with the assistant\'s version?')) {
			m.text += (m.text ? '\n\n' : '') + '(Not applied: you kept your changes.)';
		    } else {
			m.id = newId();
			undo[this.o.wfid + ':' + m.id] = before;
			this.designer().setNodes(resp.nodes);
			const problems = resp.problems || [];
			m.change = {
			    steps: resp.nodes.filter((n) => n && n.isNode).length,
			    errors: problems.filter((p) => p.level === 'error').length,
			    warnings: problems.filter((p) => p.level === 'warning').length,
			    //CLD: 2026-10-19T17:02:48+03:00: settings still wrong (WorkflowAssistant: to_fix)
			    toFix: Array.isArray(resp.to_fix) ? resp.to_fix : [],
			};
		    }
		}
		//CLD: 2026-10-19T17:02:48+03:00: no steps came back: marked (messageHtml says so)
		if (!m.change)
		    m.unchanged = true;
		if (!m.text)
		    m.text = 'No change.';
		this.xhr = null;
		this.add(m);
	    }).fail((x, status) => {
		if (this.xhr !== req)
		    return;
		this.xhr = null;
		if (status === 'abort')
		    this.add({ role: 'assistant', error: true, text: 'Stopped.' });
		else
		    this.add({ role: 'assistant', error: true, text: (x.responseJSON && x.responseJSON.error) || 'The assistant could not be reached (' + (x.status || status) + ')' });
	    });
	}

	bind() {
	    const root = this.root;
	    const input = root.querySelector('.wfa-input');
	    root.querySelector('.wfa-send').addEventListener('click', () => this.send(input.value));
	    root.querySelector('.wfa-stop').addEventListener('click', () => { if (this.xhr) this.xhr.abort(); });
	    //Enter sends, Shift+Enter is a new line (the panel is inside the canvas form: never submit it)
	    input.addEventListener('keydown', (e) => {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
		    e.preventDefault();
		    this.send(input.value);
		}
	    });
	    root.querySelector('.wfa-clear').addEventListener('click', () => {
		if (!this.state.messages.length || !confirm('Start a new conversation? The canvas is not changed.'))
		    return;
		this.state.messages = [];
		this.store();
		this.render();
	    });
	    root.querySelector('.wfa-messages').addEventListener('click', (e) => {
		const ex = e.target.closest('.wfa-example');
		if (ex) {
		    e.preventDefault();
		    input.value = ex.textContent;
		    input.focus();
		    return;
		}
		const btn = e.target.closest('.wfa-undo');
		if (btn) {
		    const k = this.o.wfid + ':' + btn.dataset.id;
		    if (!undo[k] || !confirm('Put back the steps as they were before this change? Later changes are undone too.'))
			return;
		    this.designer().setNodes(undo[k]);
		    //this change and the later ones can no longer be undone
		    const at = this.state.messages.findIndex((m) => m.id === btn.dataset.id);
		    this.state.messages.slice(at).forEach((m) => { delete undo[this.o.wfid + ':' + m.id]; });
		    this.add({ role: 'assistant', text: 'Undone: the steps are back as they were before that change.' });
		}
	    });
	}
    }

    return WfAssistant;
})();
