if (!window.app)
    window.app = {};

//CLD: 2026-10-25T20:30:00+02:00: the designer's Debug panel, a class as app.Workflow / app.WfAssistant (was the `debug` object of the page script, views/workflow/index.php):
//
//   const debug = new app.WfDebug(panel, options);     (the panel element: .wf-debug-panel, or its selector)
//
//the workflow's errors and warnings (setIssues: the designer's, updated while editing); a problem of a step shows that step on the canvas. The top-bar button
//(.btn-debug-toggle) toggles it, the header badges (#wf-issues .btn-debug-open) open it. options: steps() (the current steps: their titles; the designer's getNodes()),
//onShowNode(wfid), onOpen() / onClose() (the page: one bottom panel at a time, the one last open remembered). Methods: setIssues(issues), open(), close(), toggle(),
//destroy(). One per designer page: a new one destroys the previous one (its listeners removed).
window.app.WfDebug = (function() {
    //the one of the current designer page
    let current = null;

    const esc = (v) => app.utils.escapeHtml(v === null || v === undefined ? '' : String(v));
    const NS = '.wfdebug';

    class WfDebug {
	constructor(panel, options) {
	    if (current)
		current.destroy();
	    current = this;

	    this.el = typeof panel === 'string' ? document.querySelector(panel) : panel;
	    this.o = Object.assign({ steps: () => [], onShowNode: () => {}, onOpen: () => {}, onClose: () => {} }, options || {});
	    this.issues = [];
	    //the elements its listeners are on (removed by destroy)
	    this.bound = [];

	    this.on($('.btn-debug-toggle'), 'click', (e) => {
		e.preventDefault();
		this.toggle();
	    });
	    this.on($('#wf-issues'), 'click', (e) => {
		e.preventDefault();
		this.open();
	    }, '.btn-debug-open');
	    if (!this.el)
		return;
	    this.on($(this.el), 'click', (e) => {
		e.preventDefault();
		this.close();
	    }, '.btn-debug-close');
	    this.on($(this.el), 'click', (e) => this.o.onShowNode(e.currentTarget.dataset.node), '.debug-issue[data-node]');
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
	    this.bound.splice(0).forEach(($el) => $el.off(NS));
	    if (current === this)
		current = null;
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
	    //one bottom panel at a time (they share the same place), remembered
	    this.o.onOpen();
	    if (this.el.classList.contains('d-none')) {
		this.el.classList.remove('d-none');
		if (this.el.offsetHeight < 120)
		    this.el.style.height = '300px';
	    }
	    this.render();
	}

	close() {
	    if (this.el)
		this.el.classList.add('d-none');
	    this.o.onClose();
	}

	setIssues(issues) {
	    this.issues = issues || [];
	    this.render();
	}

	//step titles from the current steps
	titles() {
	    const titles = {};
	    (this.o.steps() || []).forEach((n) => {
		if (n && n.wfid)
		    titles[n.wfid] = n.title || n.wfid;
	    });
	    return titles;
	}

	render() {
	    const box = this.el ? this.el.querySelector('.debug-issues') : null;
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
		const node = i.node ? `data-node='${esc(i.node)}' role='button' title='Show the step'` : '';
		const step = i.node ? `<span class='tag'>[${esc(titles[i.node] || i.node)}]</span> ` : '';
		return `<div class='debug-log-line debug-issue' ${node}><i class='bi ${icon} me-1'></i>${step}${esc(i.message)}</div>`;
	    });
	    box.innerHTML = rows.join('');
	}
    }

    return WfDebug;
})();
