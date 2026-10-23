/*
 * the project Overview dashboard (views/site/index.php), over the JSON of SiteController::overview.
 *
 *   new app.Overview(container, { url: '/site/overview' });
 *
 * One row of controls (period 24h / 7d / 30d / 90d, Refresh) scopes everything below it: figure tiles (each vs the previous period), executions over time
 * (stacked columns: completed, error), executions by trigger, run time by workflow (median to 95th percentile), the workflows table (sortable, runs per
 * bucket as a sparkline), problems (failing steps, stuck work) and the recent executions. Charts: Chart.js (window.Chart, layouts/_webpage.php). Colors are
 * the CSS custom properties --ov-* of the container (light / dark steps in views/site/index.php). While reloading, the previous render stays at reduced
 * opacity. The chosen period is remembered in this browser (localStorage). Moments (at, since) come as ISO 8601 text (app.formatter.timeAgo takes them).
 */
window.app.Overview = (function() {

    const PERIODS = { '24h': '24 hours', '7d': '7 days', '30d': '30 days', '90d': '90 days' };
    //CLD: 2026-10-15T11:56:59+03:00: + inbound (the Email inbound trigger)
    //CLD: 2026-10-17T15:35:00+03:00: its runs have trigger `email` (was `inbound`)
    //CLD: 2026-10-17T20:40:00+03:00: + file (the File upload trigger)
    const TRIGGERS = { manual: 'Manual', schedule: 'Schedule', webhook: 'Webhook', imap: 'Email (IMAP)', email: 'Email (inbound)', form: 'Form', file: 'File upload' };
    //state -> [ label, icon, text class ]: never color alone
    const STATES = {
	end: [ 'Completed', 'bi-check-circle', 'text-success' ],
	error: [ 'Error', 'bi-x-circle', 'text-danger' ],
	run: [ 'Running', 'bi-play-circle', 'text-primary' ],
	sleep: [ 'Waiting', 'bi-hourglass-split', 'text-warning' ],
	cancel: [ 'Cancelled', 'bi-slash-circle', 'text-secondary' ],
	new: [ 'New', 'bi-circle', 'text-secondary' ],
    };
    const STORE = 'netflow9.overview.period';

    const esc = (s) => window.app.utils.escapeHtml(String(s === undefined || s === null ? '' : s));
    const nf = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

    //a duration in ms, short
    function duration(ms) {
	if (ms === null || ms === undefined)
	    return '–';
	if (ms < 1000)
	    return Math.round(ms) + ' ms';
	const s = ms / 1000;
	if (s < 60)
	    return (s < 10 ? s.toFixed(1) : Math.round(s)) + ' s';
	const m = Math.floor(s / 60);
	if (m < 60)
	    return m + 'm ' + Math.round(s % 60) + 's';
	return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    }

    const percent = (r) => r === null || r === undefined ? '–' : (Math.round(r * 1000) / 10) + '%';

    function stateBadge(state) {
	const [ label, icon, cls ] = STATES[state] || [ state, 'bi-question-circle', 'text-secondary' ];
	return `<span class="${cls} text-nowrap"><i class="bi ${icon} me-1"></i>${esc(label)}</span>`;
    }

    //runs per bucket as a small line (de-emphasis ink, the last point in the accent)
    function sparkline(values) {
	const w = 80, h = 20, max = Math.max(1, ...values);
	const pts = values.map((v, i) => [ values.length > 1 ? i * (w - 4) / (values.length - 1) + 2 : w / 2, h - 2 - v * (h - 4) / max ]);
	const last = pts[pts.length - 1];
	return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" class="ov-spark" aria-hidden="true">`
	    + `<polyline points="${pts.map((p) => p.join(',')).join(' ')}" fill="none" stroke="var(--ov-muted)" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>`
	    + `<circle cx="${last[0]}" cy="${last[1]}" r="2.5" fill="var(--ov-completed)"/></svg>`;
    }

    class Overview {
	constructor(container, options) {
	    this.root = container;
	    this.o = Object.assign({ url: '/site/overview' }, options || {});
	    this.charts = [];
	    this.sort = { key: 'runs', dir: -1 };
	    try {
		this.period = PERIODS[localStorage.getItem(STORE)] ? localStorage.getItem(STORE) : '7d';
	    } catch (e) {
		this.period = '7d';
	    }
	    this.build();
	    this.load();
	}

	build() {
	    this.root.innerHTML = `
		<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
		    <div class="btn-group btn-group-sm ov-periods" role="group" aria-label="Period">
			${Object.keys(PERIODS).map((p) => `<button type="button" class="btn btn-outline-secondary" data-period="${p}">${esc(p)}</button>`).join('')}
		    </div>
		    <button type="button" class="btn btn-sm btn-outline-secondary ov-refresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
		    <span class="small text-muted ov-status"></span>
		</div>
		<div class="ov-body"></div>`;
	    this.root.querySelectorAll('[data-period]').forEach((b) => b.addEventListener('click', () => {
		this.period = b.dataset.period;
		try { localStorage.setItem(STORE, this.period); } catch (e) { /* not kept */ }
		this.load();
	    }));
	    this.root.querySelector('.ov-refresh').addEventListener('click', () => this.load());
	}

	async load() {
	    this.root.querySelectorAll('[data-period]').forEach((b) => b.classList.toggle('active', b.dataset.period === this.period));
	    const body = this.root.querySelector('.ov-body');
	    body.style.opacity = '0.5';
	    try {
		const res = await fetch(this.o.url + '?period=' + encodeURIComponent(this.period), { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
		if (!res.ok)
		    throw new Error('the server answered ' + res.status);
		this.data = await res.json();
		this.render();
		this.root.querySelector('.ov-status').textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	    } catch (err) {
		this.root.querySelector('.ov-status').textContent = 'Could not load the figures (' + err.message + ')';
	    }
	    body.style.opacity = '';
	}

	color(name) {
	    return getComputedStyle(this.root).getPropertyValue('--ov-' + name).trim();
	}

	render() {
	    const d = this.data;
	    this.charts.forEach((c) => c.destroy());
	    this.charts = [];
	    const body = this.root.querySelector('.ov-body');
	    const span = PERIODS[d.period];
	    if (!d.workflowsVisible) {
		body.innerHTML = `<div class="row g-3">${this.tile('Open tasks', nf.format(d.tiles.tasks.open),
		    d.tiles.tasks.overdue ? `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${d.tiles.tasks.overdue} overdue</span>` : 'none overdue', '/task/list')}</div>`;
		return;
	    }
	    const t = d.tiles;
	    const delta = (now, prev) => {
		if (!prev)
		    return `<span class="text-muted">no runs the ${esc(span)} before</span>`;
		const ch = Math.round((now - prev) / prev * 100);
		return `<span class="text-muted"><i class="bi ${ch >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right'} me-1"></i>${ch >= 0 ? '+' : ''}${ch}% vs previous ${esc(span)}</span>`;
	    };
	    const rateDelta = () => {
		if (t.success.rate === null || t.success.previous === null)
		    return `<span class="text-muted">${t.success.errors} errors${t.success.cancelled ? ', ' + t.success.cancelled + ' cancelled' : ''}</span>`;
		const pp = Math.round((t.success.rate - t.success.previous) * 1000) / 10;
		const cls = pp > 0 ? 'text-success' : (pp < 0 ? 'text-danger' : 'text-muted');
		return `<span class="${cls}"><i class="bi ${pp >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right'} me-1"></i>${pp >= 0 ? '+' : ''}${pp} pts</span>`
		    + `<span class="text-muted"> · ${t.success.errors} errors</span>`;
	    };
	    body.innerHTML = `
		<div class="row g-3 mb-3">
		    ${this.tile('Executions', nf.format(t.executions.value), delta(t.executions.value, t.executions.previous))}
		    ${this.tile('Success rate', percent(t.success.rate), rateDelta())}
		    ${this.tile('Median run time', duration(t.duration.p50), `<span class="text-muted">95% within ${duration(t.duration.p95)}</span>`)}
		    ${this.tile('Running now', nf.format(t.running.value), `<span class="text-muted">${t.running.waiting} waiting for a person</span>`)}
		    ${this.tile('Open tasks', nf.format(t.tasks.open), t.tasks.overdue ? `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${t.tasks.overdue} overdue</span>` : '<span class="text-muted">none overdue</span>', '/task/list')}
		</div>
		<div class="card bg-body mb-3"><div class="card-body">
		    <h6 class="mb-0">Executions over time</h6>
		    <div class="small text-muted mb-2">Per ${d.step === 3600 ? 'hour' : 'day'}, last ${esc(span)}</div>
		    <div class="ov-chart" style="height: 240px"><canvas class="ov-timeline" role="img" aria-label="Executions over time, completed and error"></canvas></div>
		</div></div>
		<div class="row g-3 mb-3">
		    <div class="col-12 col-lg-5"><div class="card bg-body h-100"><div class="card-body">
			<h6 class="mb-0">By trigger</h6>
			<div class="small text-muted mb-2">What started the executions</div>
			<div class="ov-chart" style="height: ${Math.max(120, d.triggers.length * 36 + 40)}px"><canvas class="ov-triggers" role="img" aria-label="Executions by trigger"></canvas></div>
		    </div></div></div>
		    <div class="col-12 col-lg-7"><div class="card bg-body h-100"><div class="card-body">
			<h6 class="mb-0">Run time by workflow</h6>
			<div class="small text-muted mb-2">Each bar spans the median to the 95th percentile of the completed runs</div>
			<div class="ov-chart" style="height: ${Math.max(120, d.workflows.filter((w) => w.p50 !== null).length * 36 + 40)}px"><canvas class="ov-runtime" role="img" aria-label="Run time by workflow"></canvas></div>
		    </div></div></div>
		</div>
		<div class="card bg-body mb-3"><div class="card-body">
		    <h6 class="mb-2">Workflows</h6>
		    <div class="table-responsive ov-workflows"></div>
		</div></div>
		<div class="ov-problems"></div>
		<div class="card bg-body mb-3"><div class="card-body">
		    <h6 class="mb-2">Recent executions</h6>
		    <div class="table-responsive ov-recent"></div>
		</div></div>`;
	    this.timeline();
	    this.triggers();
	    this.runtime();
	    this.workflows();
	    this.problems();
	    this.recent();
	}

	tile(label, value, sub, href) {
	    const inner = `<div class="small text-muted">${esc(label)}</div><div class="fs-4 fw-semibold lh-sm my-1">${esc(value)}</div><div class="small">${sub}</div>`;
	    return `<div class="col-12 col-sm-6 col-lg-4 col-xxl"><div class="card bg-body h-100"><div class="card-body py-3">`
		+ (href ? `<a href="${esc(href)}" class="text-decoration-none text-reset d-block">${inner}</a>` : inner) + `</div></div></div>`;
	}

	//common chart options: recessive grid and axes, text in text tokens
	base() {
	    const grid = this.color('grid'), muted = this.color('muted');
	    return {
		responsive: true,
		maintainAspectRatio: false,
		animation: false,
		plugins: { legend: { display: false }, tooltip: { padding: 8, displayColors: true, boxPadding: 4 } },
		scales: {
		    x: { grid: { display: false }, border: { color: grid }, ticks: { color: muted, maxRotation: 0, autoSkipPadding: 12 } },
		    y: { grid: { color: grid, lineWidth: 1 }, border: { display: false }, ticks: { color: muted } },
		},
	    };
	}

	empty(canvas, text) {
	    canvas.parentElement.innerHTML = `<div class="h-100 d-flex align-items-center justify-content-center text-muted small">${esc(text)}</div>`;
	}

	timeline() {
	    const d = this.data;
	    const canvas = this.root.querySelector('.ov-timeline');
	    const total = d.series.completed.reduce((a, b) => a + b, 0) + d.series.error.reduce((a, b) => a + b, 0);
	    if (!total)
		return this.empty(canvas, 'No executions in this period');
	    const labels = d.buckets.map((ms) => d.step === 3600
		? new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		: new Date(ms).toLocaleDateString([], { day: 'numeric', month: 'short' }));
	    const surface = this.color('surface');
	    const opts = this.base();
	    opts.plugins.legend = { display: true, position: 'top', align: 'start', labels: { color: this.color('text'), usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, boxHeight: 10 } };
	    opts.plugins.tooltip.mode = 'index';
	    opts.plugins.tooltip.callbacks = {
		footer: (items) => {
		    const i = items[0].dataIndex;
		    const cancelled = d.series.cancelled[i];
		    return 'Total ' + (d.series.completed[i] + d.series.error[i] + cancelled) + (cancelled ? ' (' + cancelled + ' cancelled)' : '');
		},
	    };
	    opts.scales.x.stacked = true;
	    opts.scales.y.stacked = true;
	    opts.scales.y.beginAtZero = true;
	    opts.scales.y.ticks.precision = 0;
	    //the rounded end on the top segment of each column; a 2px surface gap between the segments
	    const top = (ds) => (ctx) => {
		const i = ctx.dataIndex;
		const above = ds === 'completed' ? d.series.error[i] : 0;
		return above ? 0 : { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 };
	    };
	    this.charts.push(new window.Chart(canvas, {
		type: 'bar',
		data: {
		    labels: labels,
		    datasets: [
			{ label: 'Completed', data: d.series.completed, backgroundColor: this.color('completed'), maxBarThickness: 24, borderSkipped: false, borderRadius: top('completed') },
			{ label: 'Error', data: d.series.error, backgroundColor: this.color('error'), maxBarThickness: 24, borderSkipped: false, borderRadius: top('error'),
			    borderColor: surface, borderWidth: (ctx) => ({ bottom: d.series.completed[ctx.dataIndex] ? 2 : 0, top: 0, left: 0, right: 0 }) },
		    ],
		},
		options: opts,
	    }));
	}

	triggers() {
	    const d = this.data;
	    const canvas = this.root.querySelector('.ov-triggers');
	    if (!d.triggers.length)
		return this.empty(canvas, 'No executions in this period');
	    const opts = this.base();
	    opts.indexAxis = 'y';
	    opts.scales.x = { grid: { color: this.color('grid'), lineWidth: 1 }, border: { display: false }, beginAtZero: true, ticks: { color: this.color('muted'), precision: 0 } };
	    opts.scales.y = { grid: { display: false }, border: { color: this.color('grid') }, ticks: { color: this.color('text') } };
	    opts.plugins.tooltip.displayColors = false;
	    opts.plugins.tooltip.callbacks = { label: (item) => item.raw + ' executions' };
	    this.charts.push(new window.Chart(canvas, {
		type: 'bar',
		data: {
		    labels: d.triggers.map((x) => TRIGGERS[x.trigger] || x.trigger),
		    datasets: [ { label: 'Executions', data: d.triggers.map((x) => x.count), backgroundColor: this.color('completed'), maxBarThickness: 24, borderRadius: 4, borderSkipped: 'start' } ],
		},
		options: opts,
	    }));
	}

	runtime() {
	    const rows = this.data.workflows.filter((w) => w.p50 !== null);
	    const canvas = this.root.querySelector('.ov-runtime');
	    if (!rows.length)
		return this.empty(canvas, 'No completed runs in this period');
	    const opts = this.base();
	    opts.indexAxis = 'y';
	    opts.scales.x = { grid: { color: this.color('grid'), lineWidth: 1 }, border: { display: false }, beginAtZero: true, ticks: { color: this.color('muted'), callback: (v) => duration(v) } };
	    opts.scales.y = { grid: { display: false }, border: { color: this.color('grid') }, ticks: { color: this.color('text') } };
	    opts.plugins.tooltip.displayColors = false;
	    opts.plugins.tooltip.callbacks = { label: (item) => 'median ' + duration(rows[item.dataIndex].p50) + ' · 95% within ' + duration(rows[item.dataIndex].p95) };
	    this.charts.push(new window.Chart(canvas, {
		type: 'bar',
		data: {
		    labels: rows.map((w) => w.name),
		    datasets: [ { label: 'Run time', data: rows.map((w) => [ w.p50, w.p95 ]), backgroundColor: this.color('completed'), maxBarThickness: 16, borderRadius: 4, borderSkipped: false, minBarLength: 6 } ],
		},
		options: opts,
	    }));
	}

	workflows() {
	    const el = this.root.querySelector('.ov-workflows');
	    const cols = [ [ 'name', 'Workflow' ], [ 'runs', 'Runs' ], [ 'success', 'Success' ], [ 'p50', 'Median time' ], [ 'last', 'Last run' ] ];
	    const value = (w, k) => k === 'last' ? (w.last ? w.last.at : '') : (k === 'name' ? w.name.toLowerCase() : (w[k] ?? -1));
	    const rows = this.data.workflows.slice().sort((a, b) => (value(a, this.sort.key) > value(b, this.sort.key) ? 1 : (value(a, this.sort.key) < value(b, this.sort.key) ? -1 : 0)) * this.sort.dir);
	    if (!rows.length) {
		el.innerHTML = '<div class="text-muted small">No workflows</div>';
		return;
	    }
	    el.innerHTML = `<table class="table table-sm table-hover align-middle mb-0">
		<thead><tr>${cols.map(([ k, l ]) => `<th scope="col" class="${k === 'name' ? '' : 'text-end'} text-nowrap" role="button" data-sort="${k}">${esc(l)}`
		    + (this.sort.key === k ? ` <i class="bi ${this.sort.dir > 0 ? 'bi-caret-up-fill' : 'bi-caret-down-fill'} small"></i>` : '') + '</th>').join('')}</tr></thead>
		<tbody>${rows.map((w) => `<tr role="button" data-href="/workflow/index?id=${esc(w.id)}">
		    <td>${esc(w.name)} <span class="badge ${w.status === 'draft' ? 'text-bg-light border' : 'text-bg-success'} ms-1">${esc(w.status === 'draft' ? 'draft' : w.status + ' published')}</span></td>
		    <td class="text-end text-nowrap ov-num">${sparkline(w.spark)} <span class="ms-1">${w.runs}</span></td>
		    <td class="text-end ov-num">${w.success !== null && w.success < 0.9 ? `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${percent(w.success)}</span>` : percent(w.success)}</td>
		    <td class="text-end ov-num">${duration(w.p50)}</td>
		    <td class="text-end text-nowrap">${w.last ? esc(window.app.formatter.timeAgo(w.last.at, false)) + ' · ' + stateBadge(w.last.state) : '<span class="text-muted">never</span>'}</td>
		</tr>`).join('')}</tbody></table>`;
	    el.querySelectorAll('[data-sort]').forEach((th) => th.addEventListener('click', () => {
		const k = th.dataset.sort;
		this.sort = { key: k, dir: this.sort.key === k ? -this.sort.dir : (k === 'name' ? 1 : -1) };
		this.workflows();
	    }));
	    el.querySelectorAll('[data-href]').forEach((tr) => tr.addEventListener('click', () => { window.location.href = tr.dataset.href; }));
	}

	problems() {
	    const d = this.data;
	    const el = this.root.querySelector('.ov-problems');
	    if (!d.failing.length && !d.stuck.length) {
		el.innerHTML = '';
		return;
	    }
	    const stuck = (s) => {
		const since = s.since ? window.app.formatter.timeAgo(s.since, false) : '';
		if (s.kind === 'task')
		    return `<a href="/task/${esc(s.task)}" class="text-reset"><i class="bi bi-person-check me-1 text-warning"></i>Task "${esc(s.title)}" overdue</a> <span class="text-muted">· ${esc(s.workflow)} · due ${esc(since)}</span>`;
		if (s.kind === 'running')
		    return `<i class="bi bi-play-circle me-1 text-warning"></i>Still running, started ${esc(since)} <span class="text-muted">· ${esc(s.workflow)}</span>`;
		return `<i class="bi bi-alarm me-1 text-warning"></i>Should have resumed ${esc(since)} <span class="text-muted">· ${esc(s.workflow)}</span>`;
	    };
	    el.innerHTML = `<div class="row g-3 mb-3">
		${d.failing.length ? `<div class="col-12 col-lg-7"><div class="card bg-body h-100"><div class="card-body">
		    <h6 class="mb-0"><i class="bi bi-x-octagon text-danger me-1"></i>Failing steps</h6>
		    <div class="small text-muted mb-2">The steps where executions failed, most often first</div>
		    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Step</th><th class="text-end">Failures</th><th>Last error</th></tr></thead><tbody>
		    ${d.failing.map((f) => `<tr><td><a href="/workflow/index?id=${esc(f.wf)}" class="text-reset">${esc(f.step)}</a><div class="small text-muted">${esc(f.workflow)}</div></td>
			<td class="text-end ov-num">${f.count}</td><td class="small"><span class="text-danger">${esc(f.error)}</span><div class="text-muted">${esc(window.app.formatter.timeAgo(f.at, false))}</div></td></tr>`).join('')}
		    </tbody></table></div></div></div></div>` : ''}
		${d.stuck.length ? `<div class="col-12 col-lg-${d.failing.length ? 5 : 12}"><div class="card bg-body h-100"><div class="card-body">
		    <h6 class="mb-0"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Needs attention</h6>
		    <div class="small text-muted mb-2">Long runs, late wake-ups and overdue tasks</div>
		    <ul class="list-unstyled mb-0 small">${d.stuck.map((s) => `<li class="py-1 border-bottom">${stuck(s)}</li>`).join('')}</ul>
		</div></div></div>` : ''}
	    </div>`;
	}

	recent() {
	    const el = this.root.querySelector('.ov-recent');
	    const rows = this.data.recent;
	    if (!rows.length) {
		el.innerHTML = '<div class="text-muted small">No executions yet</div>';
		return;
	    }
	    el.innerHTML = `<table class="table table-sm table-hover align-middle mb-0">
		<thead><tr><th>Workflow</th><th>Trigger</th><th>State</th><th class="text-end">Run time</th><th class="text-end">Started</th></tr></thead>
		<tbody>${rows.map((r) => `<tr role="button" data-href="/workflow/index?id=${esc(r.wf)}">
		    <td>${esc(r.workflow)}</td><td>${esc(TRIGGERS[r.trigger] || r.trigger)}</td><td>${stateBadge(r.state)}</td>
		    <td class="text-end ov-num">${duration(r.duration)}</td><td class="text-end text-nowrap">${esc(window.app.formatter.timeAgo(r.at, false))}</td>
		</tr>`).join('')}</tbody></table>`;
	    el.querySelectorAll('[data-href]').forEach((tr) => tr.addEventListener('click', () => { window.location.href = tr.dataset.href; }));
	}
    }

    return Overview;
})();
