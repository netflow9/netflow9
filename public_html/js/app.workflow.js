if (!window.app)
    window.app = {};

//CLD: 2026-10-19T22:00:00+03:00: a class, as app.CodeEditor / app.WfAssistant (was the app.workflow module: mount() and functions):
//
//   const designer = new app.Workflow('canvas-wrap', options);     (the canvas element, or its id)
//
//CLD: 2026-10-24T00:30:00+03:00: + zoomBy(factor)
//methods: fitView(), zoomBy(factor), showNode(id), getNodes(), setNodes(nodes), undo(), redo(), commitNow(), hasPendingChange(), applySaved(sent, nodes), setStepIssues(issues), destroy();
//app.Workflow.current: the designer of the page (e.g. for the Assistant plugin). A new one destroys the previous one: its listeners on the document removed, its editor / picker closed
//CLD: 2026-10-19T22:30:00+03:00: the designer's state and functions are the class's fields and methods (were the variables and functions of a closure run once per designer,
//designer()); the steps / connections (WfNode, WfEdge) reach their designer through `d` (not enumerable: not in their JSON, the saved steps); the helpers without state
//(curves, the picker's markup and placement, the drag preview) stay functions of the module
window.app.Workflow = (function() {

    /* ---------------- Node type definitions (Bootstrap Icons classes) ---------------- */
    const LOOP_HEADER = 40;   // vertical space reserved for the loop's header/label

    //CLD: 2026-10-19T18:05:00+03:00: undo / redo and autosave: each state of the steps and connections (data only: not the selection or the view) is kept as a snapshot; a change is
    //committed once the canvas is still for HISTORY_DELAY ms and no pointer is held (dragging, resizing, connecting): history[historyAt] is the current state, and
    //options.onCommit(snapshot) is told of each new current state (the page saves it); options.onHistoryChange({ canUndo, canRedo }) for the buttons
    const HISTORY_DELAY = 400;
    const HISTORY_MAX = 100;

    //the designer of the page
    let current = null;

    class Geometry {
	constructor(x, y, w, h) {
	    this.x = x || 0;
	    this.y = y || 0;
	    this.w = w || 0;
	    this.h = h || 0;
	}
    }

    let sequence = 1;
    class WfBaseNode {
	wfid = null;

	//CLD: 2026-10-19T22:30:00+03:00: + designer: the app.Workflow it belongs to, as `d` (not enumerable: JSON.stringify, the saved steps and the snapshots, leave it out)
	constructor(designer, wfid, type) {
	    Object.defineProperty(this, 'd', { value: designer });
	    ++sequence;
	    this.wfid = wfid || this.getId(type);
	}

	getId(t) {
	    t = t || 'node';
	    return `${t}-${sequence}-${Math.floor(Math.random()*1000)}`
	}
    }

    class WfNode extends WfBaseNode {
	isNode = true;
	parentId = null;
	config = {};
	geometry = null;
	fqname = null;

	constructor(designer, wfid, fqname, geometry, title, config) {
	    super(designer, wfid, 'node');
	    this.title = title;
	    this.fqname = fqname;

	    if (!geometry.w || !geometry.h) {
		const def = designer.nodeDefs[fqname];
		geometry.w = def.minWidth;
		geometry.h = def.minHeight;
	    }
	    this.geometry = geometry;
	    this.config = config || {};
	}

	getDescendants(){
	    const direct = this.d.workflow.filter(n=>n.parentId===this.wfid);
	    let all = [...direct];
	    direct.forEach(d=>{ all = all.concat(d.getDescendants()); });

	    console.log('getDescendants: all=', all);

	    return all;
	}

	portPos(isInput, index){
	    const def = this.d.nodeDefs[this.fqname];
	    //CLD: 2026-10-11T20:24:33+03:00: the step's own outputs (outPortsOf); more than 2 are spread evenly
	    const count = isInput ? def.inputs : this.d.outPortsOf(this).count;
	    let ty;
	    if(count <= 1)
		ty = 0.5;
	    else if (count === 2)
		ty = index === 0 ? 0.34 : 0.66;
	    else
		ty = (index + 1) / (count + 1);
	    return { x: this.geometry.x + (isInput ? 0 : this.geometry.w), y: this.geometry.y + this.geometry.h*ty };
	}

	getDepth(){
	    let d = 0, cur = this;
	    const seen = new Set();
	    while(cur && cur.parentId && !seen.has(cur.wfid)){
    		seen.add(cur.wfid);
    		cur = this.d.workflow.find(n=>n.wfid===cur.parentId);
    		if(!cur)
    		    break;
    		d++;
	    }
	    return d;
	}

	//CLD: 2026-10-17T10:59:58+03:00: the header classes are shared with the step boxes (were loop-header*)
	renderContainer(def) {
    	    const title = window.app.utils.escapeHtml(this.title);
	    return `
	    <div class="node-box container-box" style="width:${this.geometry.w}px;height:${this.geometry.h}px;">
		<div class="node-header">
		    <span class="node-header-icon"><i class="bi ${def.icon}"></i></span>
		    <span class="node-header-title">${title}</span>
		</div>
	    </div>
	    `;
	}

	renderNote(def) {
    	    const hasText = this.config.text && this.config.text.trim().length > 0;
    	    const bodyText = hasText ? app.utils.escapeHtml(this.config.text) : 'Click to add a description…';
    	    return `
    	    <div class="node-box note-box" style="width:${this.geometry.w}px;height:${this.geometry.h}px;">
		<i class="bi ${def.icon} note-icon"></i>
		<div class="note-body">${bodyText}</div>
	    </div>
	    `;
	}

	render() {
	    //CLD: 2026-10-19T22:30:00+03:00: the designer's state through this.d (were the module's variables)
	    const d = this.d;
    	    const def = d.nodeDefs[this.fqname];
	    //CLD: 2026-10-11T20:24:33+03:00: the step's outputs; tall enough for them (e.g. a Switch with many cases)
	    const outs = d.outPortsOf(this);
	    if (!def.isContainer && !def.isNote && this.geometry.h < d.minHeightFor(this))
		this.geometry.h = d.minHeightFor(this);
    	    const isDropTarget = d.dragState && d.dragState.dropTargetId === this.wfid && d.dragState.node.wfid !== this.wfid;
    	    const el = document.createElement('div');
    	    const isSelected = this.wfid === d.selectedId;

    	    el.className = 'node' + (def.isContainer ? ' container' : '') + (def.isNote ? ' note' : '') + (isSelected ? ' selected' : '') + (isDropTarget ? ' drop-target' : '');
    	    el.style.left = this.geometry.x + 'px';
    	    el.style.top = this.geometry.y + 'px';
    	    el.style.width = this.geometry.w + 'px';
    	    el.style.setProperty('--type-color', def.color);
    	    el.dataset.id = this.wfid;

	    //CLD: 2026-10-07T07:47:40+03:00: problem marker moved below (small icon instead of an outline)

	    switch(true) {
		case def.isContainer:
    		    el.innerHTML = this.renderContainer(def);
    		    break;
    		case def.isNote:
    		    el.innerHTML = this.renderNote(def);
    		    break;
    		//CLD: 2026-10-17T10:59:58+03:00: laid out like the loop: icon and title in a header at the top of the box (were a large icon in the middle, the title
    		//under the box); below it, the variable the step sets (variableOf)
    		default:
    		    const title = window.app.utils.escapeHtml(this.title);
    		    const variable = d.variableOf(this);
    		    const varHtml = variable ? `<span class="node-var" title="Sets the variable ${window.app.utils.escapeHtml(variable)}"><i class="bi bi-box-arrow-right"></i><span>${window.app.utils.escapeHtml(variable)}</span></span>` : '';
    		    //CLD: 2026-10-17T19:43:34+03:00: the step's label (its kind, e.g. Download File) in the body, above the variable
    		    const label = window.app.utils.escapeHtml(def.label || def.name || '');
    		    const labelHtml = label ? `<span class="node-label" title="${label}">${label}</span>` : '';
    		    el.innerHTML = `
        	    <div class="node-box step-box" style="width:${this.geometry.w}px;height:${this.geometry.h}px;">
        		<div class="node-header">
        		    <span class="node-header-icon"><i class="bi ${def.icon}"></i></span>
        		    <span class="node-header-title" title="${title}">${title}</span>
        		</div>
        		<div class="node-body">${labelHtml}${varHtml}</div>
        	    </div>
    		    `;
    		    break;
    	    }

	    //CLD: 2026-10-07T07:47:40+03:00: steps with connection problems get a small icon at the bottom-right (red: error, amber: warning),
	    //messages as tooltip; inside the step's box (already position: relative), else (containers, notes) the node itself
	    const issue = d.nodeIssues[this.wfid];
	    if (issue) {
		const isError = issue.level === 'error';
		const marker = document.createElement('i');
		marker.className = 'bi ' + (isError ? 'bi-exclamation-circle-fill text-danger' : 'bi-exclamation-triangle-fill text-warning');
		marker.title = issue.messages.join('\n');
		Object.assign(marker.style, {
		    position: 'absolute',
		    right: '8px',
		    bottom: '6px',
		    fontSize: '0.9rem',
		    lineHeight: '1',
		    zIndex: '2',
		});
		(el.querySelector('.node-box') || el).appendChild(marker);
	    }

	    //CLD: 2026-10-19T09:02:41+03:00: scheduled steps (nodeDefs[..].scheduled, WfNode::$scheduled) get a small clock at the bottom-right, left of the problem
	    //marker if any: the daemon starts them on their schedule (util\Scheduler)
	    if (def.scheduled) {
		const marker = document.createElement('i');
		marker.className = 'bi bi-alarm text-muted';
		marker.title = 'Runs on its schedule while the workflow is published';
		Object.assign(marker.style, {
		    position: 'absolute',
		    right: issue ? '26px' : '8px',
		    bottom: '6px',
		    fontSize: '0.9rem',
		    lineHeight: '1',
		    zIndex: '2',
		});
		(el.querySelector('.node-box') || el).appendChild(marker);
	    }

    	    if(isSelected && !d.options.readonly) {
    		const toolbar = document.createElement('div');
    		toolbar.className='node-toolbar';
    		//CLD: 2026-10-12T15:21:00+03:00: type="button": the canvas is inside a <form>, so a plain button also submitted it (app.pjax then posted the page and closed the dialogs)
    		toolbar.innerHTML = `
        	<div class="btn-group btn-group-sm shadow-sm bg-white border rounded-2">
        	    <button type="button" class="btn btn-light tool-edit" title="Edit step"><i class="bi bi-pencil"></i></button>
        	    <button type="button" class="btn btn-light text-danger tool-delete" title="Delete step"><i class="bi bi-trash"></i></button>
        	</div>
    		`;
    		//CLD: 2026-10-12T15:21:00+03:00: opens the step's editor (a dialog; was: focus the inspector's name input)
    		toolbar.querySelector('.tool-edit').addEventListener('click', (e)=>{
        	    e.stopPropagation();
        	    d.openEditor(this.wfid);
    		});
    		toolbar.querySelector('.tool-delete').addEventListener('click', (e)=>{
        	    e.stopPropagation();
        	    d.deleteNode(this.wfid);
    		});
    		toolbar.addEventListener('pointerdown', e=>e.stopPropagation());
    		el.appendChild(toolbar);
    	    }

    	    // ports
    	    for(let i=0;i<def.inputs;i++){
    		const p = this.portPos(true, i);
    		const port = document.createElement('div');
    		port.className='port in';
    		port.style.left=(p.x - this.geometry.x - 1.5)+'px';
    		port.style.top=(p.y - this.geometry.y-8)+'px';
    		port.dataset.node = this.wfid;
    		port.dataset.dir='in';
    		port.dataset.index=i;
    		el.querySelector('.node-box').appendChild(port);
    	    }
    	    for(let i=0;i<outs.count;i++){
    		const p = this.portPos(false, i);
    		const port = document.createElement('div');
    		port.className='port out';
    		port.style.left=(p.x - this.geometry.x-5.5)+'px';
    		port.style.top=(p.y - this.geometry.y-5.5)+'px';
    		port.dataset.node = this.wfid;
    		port.dataset.dir='out';
    		port.dataset.index=i;
    		el.querySelector('.node-box').appendChild(port);
    		if(outs.labels){
        	    const lbl = document.createElement('div');
        	    lbl.className='port-label';
        	    lbl.textContent = outs.labels[i];
        	    lbl.style.left = (p.x - this.geometry.x+9)+'px';
        	    lbl.style.top = (p.y - this.geometry.y-6)+'px';
        	    el.querySelector('.node-box').appendChild(lbl);
    		}
    	    }

    	    // resize handle (bottom-right corner of the box)
    	    if (!d.options.readonly) {
    		const handle = document.createElement('div');
    		handle.className = 'resize-handle';
    		handle.addEventListener('pointerdown', (e)=>{
    		    e.stopPropagation();
    		    d.startNodeResize(e, this);
    		});
    		el.querySelector('.node-box').appendChild(handle);

    		el.querySelector('.node-box').addEventListener('pointerdown', (e)=>{
    		    if(e.target.classList.contains('port') || e.target.closest('.resize-handle')) return;
    		    d.startNodeDrag(e, this);
    		});
    	    }

    	    el.addEventListener('click', (e)=>{
    		if(d.dragMoved)
    		    return;
    		//CLD: 2026-10-12T15:21:00+03:00: a second click on the same step within 400 ms (a double click) opens its editor; the first one re-renders the canvas, so the
    		//browser's dblclick never reaches the new element
    		const now = Date.now();
    		const twice = d.lastClick.id === this.wfid && now - d.lastClick.time < 400;
    		d.lastClick = { id: this.wfid, time: now };
    		d.selectedId = this.wfid;
    		d.render();
    		if (twice)
    		    d.openEditor(this.wfid);
    	    });

    	    d.worldEl.appendChild(el);

    	    // add-buttons on unconnected outputs
    	    if (!d.options.readonly) {
    		for(let i=0;i<outs.count;i++){
    		    const connected = d.workflow.some(ed=>ed.fromNode===this.wfid && ed.fromPort===i);
    		    if(connected)
    			continue;
    		    const p = this.portPos(false, i);
    		    const btn = document.createElement('button');
    		    //CLD: 2026-10-12T15:21:00+03:00: not a submit button (the canvas is inside a <form>)
    		    btn.type = 'button';
    		    btn.className='btn btn-sm btn-circle-xs btn-outline-primary add-btn';
    		    btn.style.position = 'absolute';
    		    btn.style.zIndex = 6;
    		    //CLD: 2026-10-11T20:24:33+03:00: after the output's label (was over it)
    		    btn.style.left = (p.x + 14 + (outs.labels ? String(outs.labels[i]).length * 5 + 6 : 0))+'px';
		    btn.style.top = (p.y-10)+'px';
    		    btn.innerHTML = '<i class="bi bi-plus-lg"></i>';
    		    btn.addEventListener('click', (e)=>{
    			e.preventDefault();
        		e.stopPropagation();
        		d.openPicker(btn, this, i);
    		    });
    		    d.worldEl.appendChild(btn);
    		}
    	    }
	}


    }

    class WfEdge extends WfBaseNode {
	isEdge = true;
	fromNode = null;
	fromPort = null;
	toNode = null;
	toPort = null;
	branch = null;

	constructor(designer, wfid, fromNode, fromPort, toNode, toPort, branch) {
	    super(designer, wfid, 'edge');
	    this.fromNode = fromNode;
	    this.fromPort = fromPort;
	    this.toNode = toNode;
	    this.toPort = toPort;
	    this.branch = branch;
	}

	render() {
	    var svg = '';
    	    const fn = this.d.workflow.find(n=>n.wfid===this.fromNode);
    	    const tn = this.d.workflow.find(n=>n.wfid===this.toNode);
    	    if(!fn||!tn)
    		return;
    	    const p1 = fn.portPos(false, this.fromPort);
    	    const p2 = tn.portPos(true, this.toPort);
    	    const d = bezier(p1.x,p1.y,p2.x,p2.y);
    	    const isSelected = this.wfid === this.d.selectedId;
    	    const sel = isSelected ? ' selected' : '';
    	    svg += `<path class="edge-hit" data-edge="${this.wfid}" d="${d}"></path>`;
    	    svg += `<path class="edge-path ${sel}" data-edge="${this.wfid}" d="${d}" marker-end="url(#arrowhead)"></path>`;

    	    if(isSelected && !this.d.options.readonly) {
    		const mid = bezierPoint(p1.x,p1.y,p2.x,p2.y,0.5);
    		svg += `<g class="edge-del" data-edge="${this.wfid}" transform="translate(${mid.x},${mid.y})">
        	    <circle r="10"></circle>
        	    <text x="0" y="4" text-anchor="middle">✕</text>
    		</g>`;
    	    }
    	    return svg;
	}
    }


    /* ---------------- Utilities ---------------- */
    function bezier(x1,y1,x2,y2) {
	const dx = Math.max(Math.abs(x2-x1)*0.55, 46);
	return `M ${x1} ${y1} C ${x1+dx} ${y1}, ${x2-dx} ${y2}, ${x2} ${y2}`;
    }

    function bezierPoint(x1,y1,x2,y2,t) {
	const dx = Math.max(Math.abs(x2-x1)*0.55, 46);
	const cx1=x1+dx, cy1=y1, cx2=x2-dx, cy2=y2;
	const mt=1-t;
	const x = mt*mt*mt*x1 + 3*mt*mt*t*cx1 + 3*mt*t*t*cx2 + t*t*t*x2;
	const y = mt*mt*mt*y1 + 3*mt*mt*t*cy1 + 3*mt*t*t*cy2 + t*t*t*y2;
	return {x,y};
    }

    function buildPicker() {
	//CLD: 2026-10-11T13:34:03+03:00: + "no match" line; the search box is wired by showPicker()
	return $(`
	<div class="picker card bg-white p-2 position-fixed active-picker" style="z-index:20">
	    <h6>Add node</h6>
	    <input type="text" class="form-control form-control-sm picker-search" placeholder="Search..." autocomplete="off">
	    <div class="nodes-container mt-2" style="overflow-y: scroll; max-height: 50vh;"></div>
	    <div class="picker-empty small text-muted px-1 d-none">No matching step</div>
	</div>
	`);
    }

    //CLD: 2026-10-13T20:32:03+03:00: moves a shown picker inside the window (10px from its edges); taller than the window: its list gets shorter and scrolls
    function fitPicker(picker) {
	const el = picker[0];
	const list = el.querySelector('.nodes-container');
	const margin = 10;
	const vw = window.innerWidth, vh = window.innerHeight;
	if (list)
	    list.style.maxHeight = '50vh';
	let r = el.getBoundingClientRect();
	if (list && r.height > vh - 2 * margin) {
	    list.style.maxHeight = Math.max(80, list.getBoundingClientRect().height - (r.height - (vh - 2 * margin))) + 'px';
	    r = el.getBoundingClientRect();
	}
	const left = Math.min(Math.max(margin, r.left), Math.max(margin, vw - r.width - margin));
	const top = Math.min(Math.max(margin, r.top), Math.max(margin, vh - r.height - margin));
	picker.css({ left: left + 'px', top: top + 'px' });
    }

    function buildDragPreview(def) {
	const el = $('<div>', {
	    css: { position: 'fixed', top: '-1000px', left: '-1000px', '--type-color': def.color, },
	    class: 'node',
	});
	//CLD: 2026-10-17T10:59:58+03:00: the step box's header (icon and name), as on the canvas (was the large icon)
	const inner = $('<div>', { class: 'node-box step-box', width: def.defaultWidth + 'px', height: def.defaultHeight + 'px', })
	    .append($(`<div class="node-header"><span class="node-header-icon"><i class="bi ${def.icon}"></i></span><span class="node-header-title"></span></div>`))
	    .appendTo(el);
	inner.find('.node-header-title').text(def.label || '');

	return el;
    }


    //CLD: 2026-10-19T22:30:00+03:00: the designer of one canvas; its state is in its fields, its functions are its methods (were a closure per designer: designer())
    class Workflow {
	//CLD: 2026-10-19T22:30:00+03:00: was mount() (+ the "start clean" of the module shared by the designer pages: a new instance starts clean)
	constructor(root, options) {
	    if (current)
		current.destroy();
	    current = this;

	    this.options = options || {};
	    //node definitions, indexed by fqname
	    this.nodeDefs = this.options.nodeDefs || {};
	    this.options.canvas = this.options.canvas || {};

	    //CLD: 2026-10-19T22:00:00+03:00: the listeners this designer adds outside its own elements (the document) or that must not stay on a reused element; destroy() removes them
	    this.unlisten = [];

	    this.wrapEl = null;
	    this.worldEl = null;
	    this.svgEl = null;

	    this.canvas = {
		pan: {
		    x: 80,
		    y: 120
		},
		zoom: 1,
	    };

	    /* ---------------- Dragging nodes ---------------- */
	    this.dragState = null;
	    this.dragMoved = false;

	    this.workflow = [];
	    this.selectedId = null;
	    //CLD: 2026-10-06T23:25:57+03:00: problems per node from the server (options.issues): { wfid: { level: 'error'|'warning', messages: [...] } }
	    this.nodeIssues = {};
	    //CLD: 2026-10-19T17:10:00+03:00: errors in the steps' settings from the server (options.stepIssues; the draft is saved with them): { wfid: { config: JSON of the
	    //step's config when loaded, issues: [...] } }; shown while the step's config is unchanged (the server checks it again on save)
	    this.serverStepIssues = {};

	    //undo / redo (HISTORY_DELAY above)
	    this.history = [];
	    this.historyAt = -1;
	    this.historyTimer = null;
	    this.pointerHeld = false;

	    //CLD: 2026-10-06T23:33:47+03:00: the problems last told to the page (updateIssues)
	    this.lastIssuesJson = null;

	    //CLD: 2026-10-12T15:21:00+03:00: the step editor (openEditor) and the double click on a step
	    this.editor = null;
	    this.lastClick = { id: null, time: 0 };

	    //CLD: 2026-10-11T13:34:03+03:00: the listener that closes the open picker on a click outside it (showPicker)
	    this.pickerOutside = null;

	    //CLD: 2026-10-12T15:21:00+03:00: an editor left open by a previous page goes away
	    document.querySelectorAll('.modal.wf-editor').forEach((m) => m.remove());

	    //CLD: 2026-10-06T23:33:47+03:00: problems are computed live in render() (validateFlow); options.issues (server) is no longer needed here
	    console.log('nodeDefs=', this.nodeDefs);

	    //CLD: 2026-10-19T22:00:00+03:00: the element, or the element of id `root` (was: #canvas-wrap whatever the string)
	    this.wrapEl = typeof root === 'string' ? document.getElementById(root) : root;
	    this.worldEl = this.wrapEl.getElementsByClassName('canvas-main')[0];
	    this.svgEl = this.wrapEl.getElementsByClassName('canvas-edges')[0];

	    this.worldEl.style.width = '4000px';
	    this.worldEl.style.height = '2600px';
	    this.worldEl.style.transformOrigin = '0 0';

	    this.svgEl.style.width = '4000px';
	    this.svgEl.style.height = '2600px';
	    this.svgEl.style.overflow = 'visible';
	    this.svgEl.style.pointerEvents = 'none';

	    this.loadNodes(this.options.workflow || []);
	    //CLD: 2026-10-19T17:10:00+03:00: the server's errors in the steps' settings, with each step's config as loaded
	    (this.options.stepIssues || []).forEach((i) => {
		const n = this.workflow.find((e) => e.wfid === i.node);
		if (!n)
		    return;
		this.serverStepIssues[n.wfid] = this.serverStepIssues[n.wfid] || { config: JSON.stringify(n.config ?? null), issues: [] };
		this.serverStepIssues[n.wfid].issues.push(i);
	    });

	    this.setupZoom();
	    //CLD: 2026-10-19T23:20:00+03:00: + pinch to zoom (touch screens); before setupPan (its handler runs first)
	    this.setupPinch();
	    this.setupPan();

	    //setup initial canvas settings
	    if (this.options.canvas.zoom)
		this.canvas.zoom = parseFloat(this.options.canvas.zoom) || 1;
	    if (this.options.canvas.pan)
		this.canvas.pan = { x: parseFloat(this.options.canvas.pan.x) || 80, y: parseFloat(this.options.canvas.pan.y) || 120,};

	    if (!this.options.canvas.pan && !this.options.canvas.zoom)
		this.fit();

	    this.setupKeyboard();
	    //CLD: 2026-10-19T18:05:00+03:00: undo / redo; the first state is the steps as loaded
	    this.setupHistory();
	    if (!this.options.readonly) {
		this.history = [ this.snapshot() ];
		this.historyAt = 0;
	    }
	    this.setupPickerButton();

	    this.setupInspectorResize();
	    this.setupDebugResize();


	    this.render();
	}

	//the designer of the page (null when there is none)
	static get current() {
	    return current;
	}

	/* ---------------- Public ---------------- */
	//the view fitted to the steps
	fitView() {
	    this.fit();
	    this.render();
	}

	//CLD: 2026-10-24T00:30:00+03:00: new: zoom in (factor > 1) or out (< 1) about the middle of the canvas (the toolbar's + / - buttons)
	zoomBy(factor) {
	    const r = this.wrapEl.getBoundingClientRect();
	    this.zoomAt(r.width / 2, r.height / 2, this.canvas.zoom * factor);
	}

	//CLD: 2026-10-11T19:57:28+03:00: select step `id` and centre the view on it (same zoom); e.g. a problem clicked in the debug panel
	showNode(id) {
	    const n = this.workflow.find(n => n.wfid === id);
	    if (!n)
		return;
	    const r = this.wrapEl.getBoundingClientRect();
	    this.canvas.pan.x = r.width / 2 - (n.geometry.x + n.geometry.w / 2) * this.canvas.zoom;
	    this.canvas.pan.y = r.height / 2 - (n.geometry.y + n.geometry.h / 2) * this.canvas.zoom;
	    if (this.options.onCanvasChange)
		this.options.onCanvasChange(this.canvas.pan, this.canvas.zoom);
	    this.selectedId = id;
	    this.render();
	}

	//CLD: 2026-10-19T15:26:28+03:00: the current steps and connections as plain data (as saved; e.g. the Assistant sends them to the server)
	getNodes() {
	    return JSON.parse(JSON.stringify(this.workflow));
	}

	//CLD: 2026-10-19T15:26:28+03:00: replaces the steps and connections (e.g. built by the Assistant, or its Undo): nothing selected, open editor / picker closed, the view
	//fitted; not saved (onDataChange marks the changes)
	//CLD: 2026-10-19T18:05:00+03:00: saved by the page's autosave (onCommit), as any change
	//CLD: 2026-10-19T18:05:00+03:00: can be undone (Undo), saved as any change
	setNodes(nodes) {
	    if (this.options.readonly)
		return;
	    this.closePicker();
	    this.closeEditor();
	    this.workflow = [];
	    this.selectedId = null;
	    this.loadNodes(Array.isArray(nodes) ? nodes : Object.values(nodes || {}));
	    this.fit();
	    this.render();
	}

	//CLD: 2026-10-19T18:05:00+03:00: undo / redo (the toolbar buttons)
	undo() {
	    this.restore(this.historyAt - 1);
	}
	redo() {
	    this.restore(this.historyAt + 1);
	}

	//CLD: 2026-10-19T18:05:00+03:00: commit a change still waiting for the canvas to be still (e.g. before leaving the page, Run, Publish); whether one is waiting
	commitNow() {
	    this.commit();
	}
	hasPendingChange() {
	    return this.historyTimer !== null;
	}

	//CLD: 2026-10-19T18:05:00+03:00: after `sent` (a snapshot from onCommit) was saved: the steps' configs as the server saved them (normalized by their config forms: cast values,
	//defaults; e.g. a webhook token) replace those on the canvas, in the current state too (no new undo step), when nothing changed since; whether the canvas changed
	applySaved(sent, nodes) {
	    if (this.options.readonly || this.historyTimer !== null || this.history[this.historyAt] !== sent)
		return false;
	    const saved = {};
	    (Array.isArray(nodes) ? nodes : Object.values(nodes || {})).forEach((n) => { if (n && n.wfid) saved[n.wfid] = n; });
	    let changed = false;
	    this.workflow.forEach((n) => {
		const s = saved[n.wfid];
		if (!n.isNode || !s)
		    return;
		//an empty config comes back as [] (PHP)
		const config = (Array.isArray(s.config) && !s.config.length) || !s.config ? {} : s.config;
		if (JSON.stringify(config) !== JSON.stringify(n.config)) {
		    n.config = config;
		    changed = true;
		}
	    });
	    if (!changed)
		return false;
	    this.history[this.historyAt] = this.snapshot();
	    this.render();
	    clearTimeout(this.historyTimer);
	    this.historyTimer = null;
	    return true;
	}

	//CLD: 2026-10-19T18:05:00+03:00: the server's errors in the steps' settings after a save (as options.stepIssues), with each step's config now
	setStepIssues(issues) {
	    this.serverStepIssues = {};
	    (issues || []).forEach((i) => {
		const n = this.workflow.find((e) => e.wfid === i.node);
		if (!n)
		    return;
		this.serverStepIssues[n.wfid] = this.serverStepIssues[n.wfid] || { config: JSON.stringify(n.config ?? null), issues: [] };
		this.serverStepIssues[n.wfid].issues.push(i);
	    });
	    //not a change of the data: a commit is not started here (one already waiting is kept)
	    const pending = this.historyTimer !== null;
	    this.render();
	    if (!pending) {
		clearTimeout(this.historyTimer);
		this.historyTimer = null;
	    }
	}

	//CLD: 2026-10-19T22:00:00+03:00: a change still waiting is committed (onCommit: the page saves it), the editor / picker closed, the listeners removed, the palette button taken off
	destroy() {
	    if (this.historyTimer !== null && !this.options.readonly)
		this.commit();
	    clearTimeout(this.historyTimer);
	    this.historyTimer = null;
	    this.closePicker();
	    this.closeEditor();
	    this.unlisten.splice(0).forEach((f) => f());
	    if (this.wrapEl)
		$(this.wrapEl).find('.btn-palette, .wf-empty').remove();
	    if (current === this)
		current = null;
	}

	/* ---------------- Internals ---------------- */
	//CLD: 2026-10-19T22:00:00+03:00: a listener removed by destroy()
	listen(target, type, fn, opts) {
	    target.addEventListener(type, fn, opts);
	    this.unlisten.push(() => target.removeEventListener(type, fn, opts));
	}

	//CLD: 2026-10-11T20:24:33+03:00: the outputs of step n: { count, labels } (labels null = numbered). Steps whose outputs follow their config (def.outPorts, e.g. Switch: one per
	//case) read them from its rows (missing: the field's default); keep in sync with WfNode::outLabelsFor
	outPortsOf(n) {
	    const def = this.nodeDefs[n.fqname] || {};
	    const p = def.outPorts;
	    if (!p)
		return { count: def.outputs || 0, labels: def.outLabels || null };
	    //CLD: 2026-10-12T08:26:57+03:00: outputs that depend on a setting ({ field, values: { value: [ labels ] } }; another value = the class's own outputs)
	    if (p.field) {
		let value = n.config ? n.config[p.field] : undefined;
		if (value === undefined || value === null) {
		    const f = (def.configSchema || []).find(f => f.key === p.field);
		    value = f ? f.default : undefined;
		}
		const labels = (p.values || {})[String(value)];
		return labels ? { count: labels.length, labels: labels } : { count: def.outputs || 0, labels: def.outLabels || null };
	    }
	    let rows = n.config ? n.config[p.rows] : undefined;
	    if (!Array.isArray(rows)) {
		const f = (def.configSchema || []).find(f => f.key === p.rows);
		rows = f && Array.isArray(f.default) ? f.default : [];
	    }
	    const labels = rows.map((r, i) => (r && r[p.label] !== undefined && r[p.label] !== null && r[p.label] !== '') ? String(r[p.label]) : String(i + 1)).concat(p.after || []);
	    return { count: labels.length, labels: labels };
	}

	//CLD: 2026-10-17T10:59:58+03:00: the variable a step sets, shown in its box: its first setting marked as a variable name to write (variableName 'write'),
	//or that setting's default; '' when it has none (loops: they show only their header)
	variableOf(n) {
	    const def = this.nodeDefs[n.fqname] || {};
	    if (def.isContainer || def.isNote)
		return '';
	    const f = (def.configSchema || []).find(f => f.variableName === 'write');
	    if (!f)
		return '';
	    const value = (n.config && n.config[f.key] !== undefined && n.config[f.key] !== null) ? n.config[f.key] : f.default;
	    return (value === undefined || value === null) ? '' : String(value).trim();
	}

	//CLD: 2026-10-11T20:24:33+03:00: the height a step needs for its outputs (more than 2: 26px each)
	minHeightFor(n) {
	    const count = this.outPortsOf(n).count;
	    return count > 2 ? (count + 1) * 26 : 0;
	}

	toWorld(clientX, clientY){
	    const r = this.wrapEl.getBoundingClientRect();
	    return { x:(clientX - r.left - this.canvas.pan.x) / this.canvas.zoom, y:(clientY - r.top - this.canvas.pan.y) / this.canvas.zoom };
	}

	//CLD: 2026-10-19T22:30:00+03:00: was the inner fitView() (fitView(), public, also draws)
	fit(){
	    const canvas = this.canvas;
	    if (!this.workflow.length) {
		canvas.pan = { x:80, y:120};
		canvas.zoom=1;

		if (this.options.onCanvasChange)
		    this.options.onCanvasChange(canvas.pan, canvas.zoom);
		return;
	    }
	    const margin = 100;
	    const nodes = this.workflow.filter(n => n.isNode);
	    const xs = nodes.map(n=>n.geometry.x), ys = nodes.map(n=>n.geometry.y);
	    const rights = nodes.map(n=>n.geometry.x + n.geometry.w), bottoms = nodes.map(n=>n.geometry.y + n.geometry.h);
	    const minX=Math.min(...xs) - margin, maxX=Math.max(...rights) + margin;
	    const minY=Math.min(...ys) - margin, maxY=Math.max(...bottoms) + margin;
	    const r = this.wrapEl.getBoundingClientRect();

	    const z = Math.min(r.width/(maxX-minX), r.height/(maxY-minY), 1.2);
	    canvas.zoom = Math.max(0.4, Math.min(1.6, z));
	    canvas.pan.x = (r.width - (maxX-minX) * canvas.zoom)/2 - minX * canvas.zoom;
	    canvas.pan.y = (r.height - (maxY-minY) * canvas.zoom)/2 - minY * canvas.zoom;

	    if (this.options.onCanvasChange)
		this.options.onCanvasChange(canvas.pan, canvas.zoom);
	}

	/* ---------------- Canvas pan & zoom ---------------- */
	setupPan() {
	    //CLD: 2026-10-19T22:00:00+03:00: listen() (was wrapEl.addEventListener)
	    this.listen(this.wrapEl, 'pointerdown', (e) => {
		let panState = null;

		if(e.target !== this.wrapEl && e.target !== this.svgEl && e.target !== this.worldEl) {
    		    if(e.target.tagName !== 'svg' && e.target !== this.svgEl)
    			return;
		}
		//CLD: 2026-10-19T23:20:00+03:00: a second finger on the canvas is a pinch (setupPinch), not a new pan
		if (this.pinchState)
		    return;
		this.selectedId=null;

		//CLD: 2026-10-19T23:20:00+03:00: + pointerId: only this pointer's moves pan (with two fingers down, the other one's moves came here too)
		const pointerId = e.pointerId;
		panState = {startX:e.clientX, startY:e.clientY, ox:this.canvas.pan.x, oy:this.canvas.pan.y};
		this.wrapEl.classList.add('panning');

		const onPan = (e) => {
    		    if(!panState || e.pointerId !== pointerId)
    			return;
    		    this.canvas.pan.x = panState.ox + (e.clientX - panState.startX);
    		    this.canvas.pan.y = panState.oy + (e.clientY - panState.startY);
    		    this.worldEl.style.transform = `translate(${this.canvas.pan.x}px, ${this.canvas.pan.y}px) scale(${this.canvas.zoom})`;

		    if (this.options.onCanvasChange)
			this.options.onCanvasChange(this.canvas.pan, this.canvas.zoom);
		};

		//CLD: 2026-10-19T23:20:00+03:00: + pointercancel; only this pointer's up ends it; also ended by a pinch starting (this.endPan)
		const endPan = (e) => {
		    if (e && e.pointerId !== pointerId)
			return;
    		    panState=null;
		    this.endPan = null;
    		    this.wrapEl.classList.remove('panning');
    		    document.removeEventListener('pointermove', onPan);
    		    document.removeEventListener('pointerup', endPan);
    		    document.removeEventListener('pointercancel', endPan);
		};
		this.endPan = endPan;

		document.addEventListener('pointermove', onPan);
		document.addEventListener('pointerup', endPan);
		document.addEventListener('pointercancel', endPan);
	    });
	}

	//CLD: 2026-10-19T23:20:00+03:00: new: pinch to zoom on touch screens (two fingers on the canvas; the wheel is setupZoom). The point between the fingers stays
	//under them: the canvas zooms by the change of their distance and moves with their midpoint, so it also pans. Started when a second finger touches the canvas
	//while the first one pans it (not during a step drag, resize or a new connection), ending that pan; ends when a finger lifts (the other one does not pan
	//then: a new touch does). #canvas-wrap has touch-action none (theme netflow9/_workflow.scss), so the browser does not zoom or scroll the page instead
	setupPinch() {
	    const touches = new Map(); //pointerId -> { x, y }, the fingers down on the canvas
	    this.pinchState = null;

	    const distMid = () => {
		const [ a, b ] = [ ...touches.values() ];
		const r = this.wrapEl.getBoundingClientRect();
		return { d: Math.hypot(b.x - a.x, b.y - a.y), mx: (a.x + b.x) / 2 - r.left, my: (a.y + b.y) / 2 - r.top };
	    };

	    //capture: before setupPan's handler, which must see pinchState
	    this.listen(this.wrapEl, 'pointerdown', (e) => {
		if (e.pointerType !== 'touch')
		    return;
		//the first finger of a new gesture: fingers whose lift was missed (e.g. lifted outside the window) are dropped, else they would count
		if (e.isPrimary)
		    touches.clear();
		touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
		//only while the first finger pans the canvas (this.endPan set; not when it drags, resizes or connects a step)
		if (touches.size !== 2 || !this.endPan || this.pinchState)
		    return;
		const { d, mx, my } = distMid();
		if (d < 10)
		    return;
		//the second finger, also when on a step, is the pinch's only (no step drag)
		e.stopPropagation();
		this.endPan();
		const canvas = this.canvas;
		this.pinchState = { d0: d, wx: (mx - canvas.pan.x) / canvas.zoom, wy: (my - canvas.pan.y) / canvas.zoom, z0: canvas.zoom };
		this.wrapEl.classList.add('panning');
	    }, true);

	    this.listen(document, 'pointermove', (e) => {
		if (!touches.has(e.pointerId))
		    return;
		touches.set(e.pointerId, { x: e.clientX, y: e.clientY });
		const ps = this.pinchState;
		if (!ps || touches.size < 2)
		    return;
		const canvas = this.canvas;
		const { d, mx, my } = distMid();
		canvas.zoom = Math.min(1.6, Math.max(0.4, ps.z0 * d / ps.d0));
		canvas.pan.x = mx - ps.wx * canvas.zoom;
		canvas.pan.y = my - ps.wy * canvas.zoom;
		this.worldEl.style.transform = `translate(${canvas.pan.x}px, ${canvas.pan.y}px) scale(${canvas.zoom})`;

		if (this.options.onCanvasChange)
		    this.options.onCanvasChange(canvas.pan, canvas.zoom);
	    });

	    const release = (e) => {
		if (!touches.delete(e.pointerId))
		    return;
		if (this.pinchState) {
		    this.pinchState = null;
		    this.wrapEl.classList.remove('panning');
		}
	    };
	    this.listen(document, 'pointerup', release);
	    this.listen(document, 'pointercancel', release);
	}

	setupZoom() {
	    //CLD: 2026-10-19T22:00:00+03:00: listen() (was wrapEl.addEventListener)
	    this.listen(this.wrapEl, 'wheel', (e)=>{
		e.preventDefault();
		const r = this.wrapEl.getBoundingClientRect();
		//CLD: 2026-10-24T00:30:00+03:00: zoomAt (shared with zoomBy; was written here)
		this.zoomAt(e.clientX - r.left, e.clientY - r.top, this.canvas.zoom * (e.deltaY>0 ? 0.9 : 1.1));
	    }, {passive:false});
	}

	//CLD: 2026-10-24T00:30:00+03:00: new (was in the wheel handler): zoom to `zoom` (kept within 0.4 - 1.6) with the canvas point at (cx, cy) staying where it is
	zoomAt(cx, cy, zoom) {
	    const canvas = this.canvas;
	    const worldX = (cx - canvas.pan.x) / canvas.zoom, worldY = (cy - canvas.pan.y) / canvas.zoom;
	    const newZoom = Math.min(1.6, Math.max(0.4, zoom));
	    canvas.pan.x = cx - worldX * newZoom;
	    canvas.pan.y = cy - worldY * newZoom;
	    canvas.zoom = newZoom;

	    this.worldEl.style.transform = `translate(${canvas.pan.x}px, ${canvas.pan.y}px) scale(${canvas.zoom})`;

	    if (this.options.onCanvasChange)
		this.options.onCanvasChange(canvas.pan, canvas.zoom);
	}


	/* ---------------- Resizing nodes ---------------- */
	startNodeResize(e, node){
	    if (this.options.readonly)
		return;

	    let resizeState = null;

	    e.preventDefault();
	    resizeState = { node, startX:e.clientX, startY:e.clientY, ow:node.geometry.w, oh:node.geometry.h };

	    const onNodeResize = (e) => {
		if(!resizeState)
		    return;

		const node = resizeState.node;
    		const def = this.nodeDefs[node.fqname];
    		const dx = (e.clientX - resizeState.startX) / this.canvas.zoom;
    		const dy = (e.clientY - resizeState.startY) / this.canvas.zoom;
    		let newW = Math.max(def.minWidth, resizeState.ow + dx);
    		let newH = Math.max(def.minHeight, resizeState.oh + dy);
    		//CLD: 2026-10-11T13:46:00+03:00: isContainer (`container` does not exist: loops could be shrunk smaller than their steps)
    		if(def.isContainer) {
    		    // never shrink a loop smaller than the children it currently holds
    		    const kids = this.workflow.filter(n=>n.parentId===node.wfid);
    		    if(kids.length){
        		const kidRight = Math.max(...kids.map(k=>k.geometry.x + k.geometry.w)) - node.geometry.x + 20;
			const kidBottom = Math.max(...kids.map(k=>k.geometry.y + k.geometry.h)) - node.geometry.y + 20;
        		newW = Math.max(newW, kidRight);
        		newH = Math.max(newH, kidBottom);
    		    }
    		}
    		node.geometry.w = newW;
    		node.geometry.h = newH;
    		this.render();
	    };

	    const endNodeResize = () => {
    		resizeState = null;
    		document.removeEventListener('pointermove', onNodeResize);
    		document.removeEventListener('pointerup', endNodeResize);
	    };

	    document.addEventListener('pointermove', onNodeResize);
	    document.addEventListener('pointerup', endNodeResize);
	}


	/* ---------------- Keyboard ---------------- */
	//CLD: 2026-10-11T14:09:51+03:00: the document listener is added once (mount() runs on every designer page: it was added again each time); the read-only and
	//"designer still on the page" checks are made per key press (after leaving the designer, Delete must not act on its old selection)
	//CLD: 2026-10-19T22:00:00+03:00: once per designer, removed by destroy() (was once for the module: keyboardReady)
	setupKeyboard() {
	    this.listen(document, 'keydown', (e)=>{
		if (this.options.readonly || !this.wrapEl || !document.body.contains(this.wrapEl))
		    return;
		const tag = (e.target.tagName||'').toLowerCase();
		if(tag==='input'||tag==='textarea'||tag==='select')
		    return;
		//keys pressed while working in the inspector never act on the canvas
		//CLD: 2026-10-12T15:21:00+03:00: nor in a dialog (the step editor)
		if(e.target.closest && (e.target.closest('.inspector') || e.target.closest('.modal')))
		    return;
		if (document.querySelector('.modal.show'))
		    return;
		if((e.key==='Delete'||e.key==='Backspace')) {
    		    if(this.selectedId)
    			this.deleteNode(this.selectedId);
		}
	    });
	}

	/* ---------------- Undo / redo, autosave ---------------- */
	//CLD: 2026-10-19T18:05:00+03:00: the steps and connections as saved (JSON)
	snapshot() {
	    return JSON.stringify(this.workflow);
	}

	//CLD: 2026-10-19T18:05:00+03:00: commit later (called by render(): the data may have changed)
	scheduleCommit() {
	    if (this.options.readonly || this.historyAt < 0)
		return;
	    clearTimeout(this.historyTimer);
	    this.historyTimer = setTimeout(() => this.commit(), HISTORY_DELAY);
	}

	//CLD: 2026-10-19T18:05:00+03:00: a new current state when the data differs from it (later states, the redo ones, are dropped)
	commit() {
	    clearTimeout(this.historyTimer);
	    this.historyTimer = null;
	    if (this.options.readonly || this.historyAt < 0)
		return;
	    if (this.pointerHeld) {
		this.scheduleCommit();
		return;
	    }
	    const s = this.snapshot();
	    if (s === this.history[this.historyAt])
		return;
	    this.history = this.history.slice(0, this.historyAt + 1);
	    this.history.push(s);
	    if (this.history.length > HISTORY_MAX)
		this.history.shift();
	    this.historyAt = this.history.length - 1;
	    this.historyChanged(true);
	}

	historyChanged(save) {
	    if (typeof this.options.onHistoryChange === 'function')
		this.options.onHistoryChange({ canUndo: this.historyAt > 0, canRedo: this.historyAt < this.history.length - 1 });
	    if (save && typeof this.options.onCommit === 'function')
		this.options.onCommit(this.history[this.historyAt]);
	}

	//CLD: 2026-10-19T18:05:00+03:00: go to state `at` (undo / redo): open editor / picker closed, the selection kept when the step is still there, the view not changed
	restore(at) {
	    this.commit();
	    if (this.options.readonly || at < 0 || at >= this.history.length || at === this.historyAt)
		return;
	    this.historyAt = at;
	    this.closePicker();
	    this.closeEditor();
	    this.workflow = [];
	    this.loadNodes(JSON.parse(this.history[at]));
	    if (this.selectedId && !this.workflow.some((n) => n.wfid === this.selectedId))
		this.selectedId = null;
	    this.render();
	    clearTimeout(this.historyTimer);
	    this.historyTimer = null;
	    this.historyChanged(true);
	}

	//CLD: 2026-10-19T18:05:00+03:00: pointers held anywhere (a drag, resize or new connection is not committed halfway); Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y, Ctrl+S (commit now) on the canvas
	//CLD: 2026-10-19T22:00:00+03:00: once per designer, removed by destroy() (was once for the module: historyReady)
	setupHistory() {
	    this.listen(document, 'pointerdown', () => { this.pointerHeld = true; }, true);
	    const release = () => {
		this.pointerHeld = false;
	    };
	    this.listen(document, 'pointerup', release, true);
	    this.listen(document, 'pointercancel', release, true);

	    this.listen(document, 'keydown', (e) => {
		if (this.options.readonly || !this.wrapEl || !document.body.contains(this.wrapEl) || !(e.ctrlKey || e.metaKey))
		    return;
		const key = (e.key || '').toLowerCase();
		if (key === 's') {
		    //saved anyway: the browser's Save page is not wanted here
		    e.preventDefault();
		    this.commit();
		    return;
		}
		//the text fields, the step editor and the Assistant have their own undo
		const tag = (e.target.tagName || '').toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable)
		    return;
		if (e.target.closest && (e.target.closest('.inspector') || e.target.closest('.modal')))
		    return;
		if (document.querySelector('.modal.show'))
		    return;
		if (key === 'z' && !e.shiftKey) {
		    e.preventDefault();
		    this.restore(this.historyAt - 1);
		} else if ((key === 'z' && e.shiftKey) || key === 'y') {
		    e.preventDefault();
		    this.restore(this.historyAt + 1);
		}
	    });
	}

	/* ---------------- Mutations ---------------- */
	deleteNode(id){
	    // deleting a loop container releases its children rather than deleting them
	    this.workflow.filter(n=>n.parentId===id).forEach(n=>n.parentId=null);

	    this.workflow = this.workflow.filter(n=>n.wfid!==id);
	    this.workflow = this.workflow.filter(e=>e.fromNode!==id && e.toNode!==id);
	    this.selectedId = null;
	    this.render();
	}

	/* ---------------- Dragging nodes ---------------- */
	//CLD: 2026-10-11T13:46:00+03:00: module level (was inside startNodeDrag): also places new steps in the loop they are added to
	// find the innermost loop container whose bounds contain a node's center
	findContainerFor(node, excludeIds){
	    const nodeDefs = this.nodeDefs;
	    //CLD: 2026-10-11T13:39:20+03:00: a trigger cannot be placed in a loop (it starts the workflow, not an iteration)
	    if (nodeDefs[node.fqname].type === 'trigger')
		return null;
	    //CLD: 2026-10-14T21:06:38+03:00: nor a loop in another loop
	    if (nodeDefs[node.fqname].isContainer)
		return null;
	    const cx = node.geometry.x + node.geometry.w/2, cy = node.geometry.y + node.geometry.h/2;
	    let best = null;
	    this.workflow.filter(n=>n.isNode).forEach(cand=>{
		if(cand.wfid === node.wfid || excludeIds.has(cand.wfid))
		    return;

		if (!nodeDefs[cand.fqname].isContainer)
		    return;

		const inside = cx > cand.geometry.x + 10 && cx < cand.geometry.x + cand.geometry.w - 10 &&
		    cy > cand.geometry.y + LOOP_HEADER && cy < cand.geometry.y + cand.geometry.h - 10;
		if(inside && (!best || cand.geometry.w*cand.geometry.h < best.area))
		    best = {wfid:cand.wfid, area:cand.geometry.w * cand.geometry.h};
	    });
	    return best ? best.wfid : null;
	}

	//CLD: 2026-10-11T13:46:00+03:00: grows the loops holding `node` (innermost first) so it fits inside, with the same margins as the resize rule
	growContainers(node) {
	    let child = node;
	    let parent = this.workflow.find(n => n.wfid === child.parentId);
	    while (parent && this.nodeDefs[parent.fqname].isContainer) {
		parent.geometry.w = Math.max(parent.geometry.w, child.geometry.x + child.geometry.w - parent.geometry.x + 20);
		parent.geometry.h = Math.max(parent.geometry.h, child.geometry.y + child.geometry.h - parent.geometry.y + 20);
		child = parent;
		parent = this.workflow.find(n => n.wfid === child.parentId);
	    }
	}

	startNodeDrag(e, node){
	    if (this.options.readonly)
		return;

	    e.stopPropagation();
	    this.dragMoved = false;
	    const def = this.nodeDefs[node.fqname];
	    const descendants = def.isContainer ? node.getDescendants() : [];

	    const childOrigins = def.isContainer ? descendants.map(n=>({n, ox:n.geometry.x, oy:n.geometry.y})) : null;
	    const excludeIds = new Set([node.wfid, ...descendants.map(n=>n.wfid)]);
	    this.dragState = { node, startX:e.clientX, startY:e.clientY, ox:node.geometry.x, oy:node.geometry.y, childOrigins, excludeIds, dropTargetId:node.parentId };

	    //CLD: 2026-10-11T13:46:00+03:00: findContainerFor() moved to the module (also used when steps are added)

	    const onNodeDrag = (e) => {
		const dragState = this.dragState;
    		if(!dragState)
    		    return;
    		const dx = (e.clientX - dragState.startX) / this.canvas.zoom;
    		const dy = (e.clientY - dragState.startY) / this.canvas.zoom;
    		if(Math.abs(dx)>2||Math.abs(dy)>2)
    		    this.dragMoved = true;
    		dragState.node.geometry.x = dragState.ox + dx;
    		dragState.node.geometry.y = dragState.oy + dy;
    		if(dragState.childOrigins){
    		    dragState.childOrigins.forEach(c=>{ c.n.geometry.x = c.ox + dx; c.n.geometry.y = c.oy + dy; });
    		}
    		dragState.dropTargetId = this.findContainerFor(dragState.node, dragState.excludeIds);
    		this.render();
	    };

	    const endNodeDrag = () => {
		const dragState = this.dragState;
    		if(dragState) {
    		    //node moved to a different parent (or none)
    		    if (dragState.node.parentId != dragState.dropTargetId) {
    			//remove edges that are connected to this node
        		this.workflow = this.workflow.filter(n=> {
        		    if (n.isNode)
        			return true;
        		    return !(n.toNode === dragState.node.wfid || n.fromNode === dragState.node.wfid)
    			});
    		    }

    		    dragState.node.parentId = dragState.dropTargetId || null;
    		}

    		this.dragState = null;
    		document.removeEventListener('pointermove', onNodeDrag);
    		document.removeEventListener('pointerup', endNodeDrag);
    		// Defer the cleanup re-render (clears drop-target highlight, reorders DOM for
    		// the new parent/child stacking) until *after* the browser's native "click"
    		// event for this interaction has fired. Rebuilding the node DOM synchronously
    		// here would destroy the element the click is about to land on, silently
    		// breaking node selection.
    		setTimeout(() => this.render(), 0);
	    };

	    document.addEventListener('pointermove', onNodeDrag);
	    document.addEventListener('pointerup', endNodeDrag);
	}

	/* ---------------- Connecting ports ---------------- */
	startConnect(e, portEl){
	    if (this.options.readonly)
		return;

	    let connectState = null;

	    const node = this.workflow.find(n=>n.wfid===portEl.dataset.node);
	    const idx = +portEl.dataset.index;
	    const p = node.portPos(false, idx);
	    connectState = { fromNode:node, fromPort:idx, x:p.x, y:p.y };

	    //CLD: 2026-10-19T23:40:00+03:00: the input port the connection goes to: the one under the pointer, else the nearest within 16px (24px for a finger: it covers
	    //the port); used both for the highlight while moving and for the drop (the drop took only the port exactly under the pointer: a highlighted port near a
	    //finger was not connected; the highlight could also show several ports, now the nearest one)
	    const radius = e.pointerType === 'touch' ? 24 : 16;
	    const targetPort = (e) => {
		let best = null, bestDist = radius;
		document.querySelectorAll('.port.in').forEach(p => {
		    const r = p.getBoundingClientRect();
		    const dist = (e.target === p) ? 0 : Math.hypot(e.clientX - (r.left + r.width/2), e.clientY - (r.top + r.height/2));
		    if (dist < bestDist) {
			best = p;
			bestDist = dist;
		    }
		});
		return best;
	    };

	    const onConnectMove = (e) => {
    		if(!connectState)
    		    return;
    		const w = this.toWorld(e.clientX, e.clientY);
    		const d = bezier(connectState.x, connectState.y, w.x, w.y);
    		let temp = document.getElementById('temp-edge');
    		if(!temp){
    		    temp = document.createElementNS('http://www.w3.org/2000/svg','path');
    		    temp.id='temp-edge';
    		    temp.setAttribute('class','edge-path');
    		    temp.setAttribute('stroke-dasharray','5 4');
    		    this.svgEl.appendChild(temp);
    		}
    		temp.setAttribute('d', d);

    		const hit = targetPort(e);
    		document.querySelectorAll('.port.in').forEach(p=>{
    		    p.classList.toggle('compatible', p === hit);
    		});
	    };

	    const onConnectEnd = (e) => {
    		document.removeEventListener('pointermove', onConnectMove);
    		document.removeEventListener('pointerup', onConnectEnd);
    		const temp = document.getElementById('temp-edge');
    		if(temp)
    		    temp.remove();
    		document.querySelectorAll('.port.compatible').forEach(p=>p.classList.remove('compatible'));

    		if(!connectState)
		    return;
    		const els = document.elementsFromPoint(e.clientX, e.clientY);
    		const target = els.find(el=>el.classList && el.classList.contains('port') && el.classList.contains('in')) || targetPort(e);
    		if(target){
    		    const toNode = target.dataset.node;
    		    const toPort = +target.dataset.index;
    		    if(toNode !== connectState.fromNode.wfid){
    			//remove edges that are connected to target node/port
        		this.workflow = this.workflow.filter(n=> {
        		    if (n.isNode)
        			return true;
        		    return !(n.toNode===toNode && n.toPort===toPort)
    			});
        		const edge = new WfEdge(this, null, connectState.fromNode.wfid, connectState.fromPort, toNode, toPort);
        		//CLD: 2026-10-11T20:24:33+03:00: the source step's own output labels
        		const srcLabels = this.outPortsOf(connectState.fromNode).labels;
        		if(srcLabels)
        		    edge.branch = srcLabels[connectState.fromPort];
        		this.workflow.push(edge);
        		this.render();
    		    }
    		}
    		connectState = null;
	    };

	    document.addEventListener('pointermove', onConnectMove);
	    document.addEventListener('pointerup', onConnectEnd);
	}

	/* ---------------- New steps ---------------- */
	//CLD: 2026-10-08T08:10:24+03:00: steps of node types with their own initConfig() (nodeDefs[..].hasInitConfig) get their starting config
	//from the server right after they are added (e.g. a webhook token and URL); the inspector is refreshed if it shows the step
	initNodeConfig(node) {
	    const def = this.nodeDefs[node.fqname];
	    if (!def || !def.hasInitConfig || !this.options.nodeInitUrl)
		return;

	    $.getJSON(this.options.nodeInitUrl, { fqname: node.fqname })
		.done((resp) => {
		    if (!resp || !resp.config || typeof resp.config !== 'object' || !this.workflow.includes(node))
			return;
		    node.config = Object.assign({}, node.config || {}, resp.config);
		    //CLD: 2026-10-12T15:21:00+03:00: the step's editor, if open, shows the new values
		    if (this.editor && this.editor.nodeId === node.wfid)
			this.editor.form.setData(Object.assign({}, node.config, this.nodeDefs[node.fqname].isNote ? {} : { __title: node.title }));
		    this.render();
		})
		.fail(() => console.error('Wf: cannot get the starting config of', node.fqname));
	}

	/* ---------------- Validation ---------------- */
	//CLD: 2026-10-06T23:33:47+03:00: client-side twin of models\Workflow::validateFlow (same rules and messages; the server check stays the
	//reference for saving and running): [ { level: 'error'|'warning', node: wfid|null, message } ]
	validateFlow() {
	    const issues = [];
	    const add = (level, node, message) => issues.push({ level, node, message });

	    const nodes = {};
	    const edges = [];
	    this.workflow.forEach((e) => {
		if (e.isNode)
		    nodes[e.wfid] = e;
		else if (e.isEdge)
		    edges.push(e);
	    });

	    //node types
	    const def = {};
	    Object.keys(nodes).forEach((id) => {
		let d = this.nodeDefs[nodes[id].fqname];
		if (!d) {
		    add('error', id, 'Unknown step type');
		    return;
		}
		//CLD: 2026-10-11T20:24:33+03:00: the step's own outputs when they follow its config (as Workflow::validateFlow)
		if (d.outPorts) {
		    const outs = this.outPortsOf(nodes[id]);
		    d = Object.assign({}, d, { outputs: outs.count, outLabels: outs.labels });
		}
		def[id] = d;
	    });

	    //CLD: 2026-10-11T13:46:00+03:00: the level of a step: the loop it is in, null at the top (keep in sync with Workflow::validateFlow)
	    const levelOf = (id) => {
		const parent = nodes[id].parentId;
		return (parent && def[parent] && def[parent].isContainer) ? parent : null;
	    };

	    //exactly one trigger
	    const triggers = Object.keys(def).filter((id) => def[id].type === 'trigger');
	    if (!triggers.length)
		add('error', null, 'The workflow has no trigger');
	    triggers.slice(1).forEach((id) => add('error', id, 'Only one trigger is allowed'));
	    //CLD: 2026-10-14T21:06:38+03:00: a loop cannot be inside another loop (keep in sync with Workflow::validateFlow)
	    Object.keys(def).filter((id) => def[id].isContainer && levelOf(id) !== null).forEach((id) => add('error', id, 'A loop cannot be inside another loop'));
	    //CLD: 2026-10-11T13:39:20+03:00: a trigger cannot be inside a loop (keep in sync with Workflow::validateFlow)
	    triggers.forEach((id) => {
		const parent = nodes[id].parentId;
		if (parent && def[parent] && def[parent].isContainer)
		    add('error', id, 'A trigger cannot be inside a loop');
	    });

	    //connections
	    const seen = {}, adjacent = {}, inputsUsed = {}, outputsUsed = {};
	    edges.forEach((e) => {
		const from = e.fromNode, to = e.toNode;
		const fd = def[from], td = def[to];
		if (!fd || !td) {
		    add('error', fd ? from : (td ? to : null), 'A connection points to a missing step');
		    return;
		}
		if (fd.isNote || td.isNote) {
		    add('error', fd.isNote ? from : to, 'Notes cannot be connected');
		    return;
		}
		if (td.inputs == 0) {
		    add('error', to, 'This step cannot have incoming connections');
		    return;
		}
		//CLD: 2026-10-11T13:46:00+03:00: connections stay on their level (a loop's own input / output belong to the level around it)
		if (levelOf(from) !== levelOf(to)) {
		    add('error', from, "A connection cannot cross a loop's border");
		    return;
		}
		const fromPort = Number(e.fromPort), toPort = Number(e.toPort);
		if (!Number.isInteger(fromPort) || fromPort < 0 || fromPort >= fd.outputs) {
		    add('error', from, 'A connection leaves from an output this step does not have');
		    return;
		}
		if (!Number.isInteger(toPort) || toPort < 0 || toPort >= td.inputs) {
		    add('error', to, 'A connection arrives at an input this step does not have');
		    return;
		}
		const key = from + '|' + fromPort + '|' + to + '|' + toPort;
		if (seen[key]) {
		    add('error', from, 'Duplicate connection');
		    return;
		}
		seen[key] = true;

		if (from === to)
		    add('warning', from, 'This step is connected to itself');

		(adjacent[from] = adjacent[from] || []).push(to);
		(inputsUsed[to] = inputsUsed[to] || {})[toPort] = true;
		(outputsUsed[from] = outputsUsed[from] || {})[fromPort] = true;
	    });

	    //CLD: 2026-10-11T13:46:00+03:00: computed for every level (was inside the trigger check)
	    const hasIncoming = {};
	    Object.keys(adjacent).forEach((from) => adjacent[from].forEach((to) => { hasIncoming[to] = true; }));

	    //every step must be reachable from the trigger
	    if (triggers.length === 1) {
		const reached = { [triggers[0]]: true };
		const queue = [ triggers[0] ];
		while (queue.length) {
		    const id = queue.shift();
		    (adjacent[id] || []).forEach((next) => {
			if (!reached[next]) {
			    reached[next] = true;
			    queue.push(next);
			}
		    });
		}
		//CLD: 2026-10-07T08:01:20+03:00: flag only where a disconnected chain starts (no incoming connection), not every step after it;
		//steps of a disconnected cycle (all have incoming connections, none starts the chain) are flagged too
		//CLD: 2026-10-11T13:46:00+03:00: top-level steps only (the steps inside a loop are checked from the loop's starting step, below)
		const unreached = Object.keys(def).filter((id) => !def[id].isNote && levelOf(id) === null && !reached[id]);

		const starts = unreached.filter((id) => !hasIncoming[id]);
		const fromStarts = {};
		starts.forEach((id) => { fromStarts[id] = true; });
		const queue2 = starts.slice();
		while (queue2.length) {
		    const id = queue2.shift();
		    (adjacent[id] || []).forEach((next) => {
			if (!fromStarts[next]) {
			    fromStarts[next] = true;
			    queue2.push(next);
			}
		    });
		}
		unreached.forEach((id) => {
		    if (starts.includes(id) || !fromStarts[id])
			add('error', id, 'Not connected to the trigger');
		});
	    }

	    //CLD: 2026-10-11T13:46:00+03:00: the inside of each loop is a flow of its own: each pass starts at its one step without an incoming connection, every step inside
	    //must be reachable from it; notes and triggers (an error of their own) are left out
	    Object.keys(def).filter((id) => def[id].isContainer).forEach((loopId) => {
		const inner = Object.keys(def).filter((id) => !def[id].isNote && def[id].type !== 'trigger' && levelOf(id) === loopId);
		if (!inner.length) {
		    add('warning', loopId, 'The loop is empty');
		    return;
		}
		const starts = inner.filter((id) => !hasIncoming[id]);
		if (!starts.length) {
		    add('error', loopId, 'The loop has no starting step: every step inside has an incoming connection');
		    return;
		}
		if (starts.length > 1) {
		    starts.forEach((id) => add('error', id, 'Only one step inside a loop may have no incoming connection: each pass starts there'));
		    return;
		}
		const reached = { [starts[0]]: true };
		const queue = [ starts[0] ];
		while (queue.length) {
		    const id = queue.shift();
		    (adjacent[id] || []).forEach((next) => {
			if (!reached[next]) {
			    reached[next] = true;
			    queue.push(next);
			}
		    });
		}
		inner.filter((id) => !reached[id]).forEach((id) => add('error', id, "Not connected to the loop's starting step"));
	    });

	    //several outputs (If): every output should lead somewhere; several inputs (Merge): every input should be fed
	    Object.keys(def).forEach((id) => {
		const d = def[id];
		if (d.isNote)
		    return;
		if (d.outputs > 1) {
		    for (let p = 0; p < d.outputs; p++) {
			if (!(outputsUsed[id] || {})[p])
			    add('warning', id, 'Output `' + ((d.outLabels || [])[p] ?? (p + 1)) + '` is not connected');
		    }
		}
		if (d.inputs > 1) {
		    const used = Object.keys(inputsUsed[id] || {}).length;
		    if (used < d.inputs)
			add('warning', id, 'Only ' + used + ' of ' + d.inputs + ' inputs are connected');
		}
	    });

	    return issues;
	}

	//CLD: 2026-10-06T23:33:47+03:00: recompute the problems; index them by node for the outlines (an error wins over a warning) and tell the
	//page when the list changed (options.onIssuesChange, e.g. header badges)
	updateIssues() {
	    const issues = this.validateFlow();
	    //CLD: 2026-10-19T17:10:00+03:00: + the server's errors in the settings of steps not changed since loaded
	    this.workflow.forEach((n) => {
		const s = this.serverStepIssues[n.wfid];
		if (s && s.config === JSON.stringify(n.config ?? null))
		    issues.unshift(...s.issues);
	    });

	    this.nodeIssues = {};
	    issues.forEach((i) => {
		if (!i.node)
		    return;
		const cur = this.nodeIssues[i.node] || { level: 'warning', messages: [] };
		if (i.level === 'error')
		    cur.level = 'error';
		cur.messages.push(i.message);
		this.nodeIssues[i.node] = cur;
	    });

	    const json = JSON.stringify(issues);
	    if (json !== this.lastIssuesJson) {
		this.lastIssuesJson = json;
		if (typeof this.options.onIssuesChange === 'function')
		    this.options.onIssuesChange(issues);
	    }
	}

	/* ---------------- Rendering ---------------- */
	render(){
	    //CLD: 2026-10-06T23:33:47+03:00: live validation of the connections before drawing (outlines + options.onIssuesChange)
	    this.updateIssues();

	    this.worldEl.style.transform = `translate(${this.canvas.pan.x}px, ${this.canvas.pan.y}px) scale(${this.canvas.zoom})`;

	    //remove all nodes
	    this.worldEl.querySelectorAll('.node, .add-btn').forEach(el=>el.remove());

	    // render parents before children so nested nodes visually sit on top of their loop container
	    const ordered = this.workflow.filter(n=>n.isNode).slice().sort((a,b)=>a.getDepth()-b.getDepth());
	    ordered.forEach(node => node.render());

	    // port connect handlers (delegate after nodes drawn)
	    this.worldEl.querySelectorAll('.port').forEach(p=>{
    		p.addEventListener('pointerdown', (e)=>{
    		    e.stopPropagation();
		    if(p.dataset.dir==='out')
			this.startConnect(e, p);
    		});
	    });

	    //render edges
	    let svg = '';
	    this.workflow.filter(edge => edge.isEdge).forEach(edge => {
		svg += edge.render();
	    });

	    //CLD: 2026-10-24T11:20:00+03:00: the edges' color of the theme (--wf-edge: the dark mode; was fill="#b8bdc4")
	    svg += `<defs><marker id="arrowhead" markerWidth="7" markerHeight="7" refX="5.5" refY="3.5" orient="auto"><path d="M0,0 L7,3.5 L0,7 z" style="fill:var(--wf-edge)"></path></marker></defs>`;
	    this.svgEl.innerHTML = svg;


	    this.svgEl.querySelectorAll('[data-edge]').forEach(el=>{
    		el.addEventListener('click', (e)=>{
    		    e.stopPropagation();
    		    const id = el.dataset.edge;
    		    if(el.classList.contains('edge-del')){
			if (!this.options.readonly)
        		    this.deleteNode(id);
    		    } else {
        		this.selectedId = id;
        		this.render();
    		    }
    		});
	    });

	    //CLD: 2026-10-11T13:34:03+03:00: the empty-canvas button (setupPickerButton) while there are no steps
	    const emptyEl = this.wrapEl.querySelector('.wf-empty');
	    if (emptyEl)
		emptyEl.classList.toggle('d-none', this.workflow.some(n => n.isNode));

	    //CLD: %s: removed the inspector refresh: steps are edited in a dialog (openEditor)

	    if (typeof this.options.onDataChange === 'function')
		this.options.onDataChange(this.workflow);
	    //CLD: 2026-10-19T18:05:00+03:00: undo / redo, autosave
	    this.scheduleCommit();

	}




	//CLD: 2026-10-11T20:24:33+03:00: after the outputs of step `node` changed (were labelled `before`): each connection follows its output's label; a renamed output (no
	//label match, the same position holds a new label) keeps its connections; connections of removed outputs are removed
	remapOutputs(node, before) {
	    //CLD: 2026-10-12T08:26:57+03:00: outputs without labels (e.g. HTTP Request back to one output): the connections of the outputs that remain are kept
	    const outs = this.outPortsOf(node);
	    if (!outs.labels) {
		this.workflow = this.workflow.filter((e) => !(e.isEdge && e.fromNode === node.wfid && e.fromPort >= outs.count));
		this.workflow.forEach((e) => { if (e.isEdge && e.fromNode === node.wfid) e.branch = null; });
		return;
	    }
	    const after = outs.labels;
	    const newIndex = (label) => after.indexOf(label);
	    this.workflow = this.workflow.filter((e) => {
		if (!e.isEdge || e.fromNode !== node.wfid)
		    return true;
		const label = before[e.fromPort];
		let to = label === undefined ? -1 : newIndex(label);
		if (to < 0 && e.fromPort < after.length && !before.includes(after[e.fromPort]))
		    to = e.fromPort;
		if (to < 0)
		    return false;
		e.fromPort = to;
		e.branch = after[to];
		return true;
	    });
	}

	/* ---------------- Step editor ---------------- */
	//CLD: 2026-10-12T15:21:00+03:00: steps are edited in a dialog (was the inspector panel, too narrow for steps with many settings; the panel is kept for a later feature):
	//opened by a double click on the step, its toolbar's pencil, or when a step is added. OK applies the values to the step (saved with the workflow),
	//Cancel / Escape leaves it unchanged, Delete removes the step. Read-only (a version): the values only, Close
	closeEditor() {
	    if (!this.editor)
		return;
	    const e = this.editor;
	    this.editor = null;
	    e.modal.hide();
	}

	openEditor(id) {
	    const node = this.workflow.find(n => n.wfid === id);
	    if (!node || !node.isNode)
		return;
	    if (this.editor)
		this.closeEditor();

	    const def = this.nodeDefs[node.fqname];
	    const readonly = !!this.options.readonly;
	    const esc = app.utils.escapeHtml;

	    //the step name is stored in node.title, everything else in node.config
	    const fields = [];
	    if (!def.isNote)
		fields.push({ type: 'text', key: '__title', label: 'Step name', required: true });
	    (def.configSchema || []).forEach(f => fields.push(Object.assign({}, f)));
	    if (readonly)
		fields.forEach(f => f.editable = false);

	    const el = document.createElement('div');
	    el.className = 'modal fade wf-editor';
	    //CLD: 2026-10-13T18:23:00+03:00: a step with a form designer (Form trigger, Human task) gets an extra-large dialog
	    //CLD: 2026-10-13T20:12:00+03:00: full screen (Bootstrap modal-fullscreen; was modal-xl)
	    //CLD: 2026-10-13T20:23:54+03:00: nearly full screen, a margin all around (.modal-near-fullscreen, theme scss/netflow9/_modal.scss; was modal-fullscreen: it did not read as a dialog)
	    //CLD: 2026-10-19T22:19:24+03:00: + modal-fullscreen-sm-down: the whole screen on phones (the near-fullscreen one too: theme netflow9/_modal.scss)
	    el.tabIndex = -1;
	    el.innerHTML = `
		<!-- CLD: 2026-10-16T12:01:59+03:00: + the email designer (Send Email) -->
		<div class="modal-dialog ${(def.configSchema || []).some((f) => [ 'formdesigner', 'emaildesigner' ].includes(f.type)) ? 'modal-near-fullscreen' : 'modal-lg'} modal-fullscreen-sm-down modal-dialog-scrollable">
		    <div class="modal-content">
			<div class="modal-header">
			    <span class="badge d-inline-flex align-items-center gap-1 me-2" style="background:${def.color}"><i class="bi ${def.icon}"></i>${esc(def.label)}</span>
			    <h5 class="modal-title text-truncate">${esc(def.isNote ? 'Note' : (node.title || def.label))}</h5>
			    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body"><div class="wf-editor-form"></div></div>
			<div class="modal-footer">
			    ${readonly ? `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>` : `
			    <button type="button" class="btn btn-outline-danger me-auto btn-editor-delete"><i class="bi bi-trash me-1"></i>Delete ${def.isNote ? 'note' : 'step'}</button>
			    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
			    <button type="button" class="btn btn-primary btn-editor-ok"><i class="bi bi-check me-1"></i>OK</button>`}
			</div>
		    </div>
		</div>`;
	    document.body.appendChild(el);

	    //CLD: 2026-10-11T22:38:36+03:00: expression fields get live help: checked / completed by the server with the variables available at this step (the current, unsaved steps are sent)
	    const expression = (this.options.exprUrl && !readonly) ? { url: this.options.exprUrl, params: () => ({ node: node.wfid, nodes: JSON.stringify(this.workflow) }) } : null;
	    const form = new app.smartform(el.querySelector('.wf-editor-form'), { fields: fields, expression: expression });
	    const formEl = form.render();
	    form.setData(Object.assign({}, node.config || {}, def.isNote ? {} : { __title: node.title }));

	    //CLD: 2026-10-13T20:36:15+03:00: a step with a form (Form trigger, Human task; user's choice): the dialog is the form designer; the step's other settings (name, ...) are moved into
	    //the designer's right panel, shown when no field of the form is selected (the same inputs: data and checks as before); stacked there (the panel is narrow)
	    //CLD: 2026-10-16T12:01:59+03:00: the same for a step with an email (Send Email: the email designer)
	    const designerField = form.fields.find((f) => [ 'formdesigner', 'emaildesigner' ].includes(f.schema.type) && f.designer);
	    let stepPanel = null;
	    if (designerField) {
		const root = el.querySelector('.wf-editor-form form');
		let top = designerField.el;
		while (top && top.parentElement !== root)
		    top = top.parentElement;
		stepPanel = document.createElement('div');
		stepPanel.className = 'fd-step-settings';
		[ ...root.children ].forEach((c) => { if (c !== top) stepPanel.appendChild(c); });
		stepPanel.querySelectorAll('[class*="col-md-"]').forEach((c) => { c.className = c.className.replace(/\bcol-md-\d+\b/g, 'col-md-12'); });
		if (designerField.labelEl)
		    designerField.labelEl.classList.add('d-none');
		//CLD: 2026-10-13T20:45:56+03:00: the designer fills the dialog's body (which no longer scrolls): its columns scroll on their own; the chain from the body down to the
		//designer stretches (flex column, min-height 0)
		const body = el.querySelector('.modal-body');
		body.style.cssText += 'display:flex; flex-direction:column; overflow:hidden';
		[ el.querySelector('.wf-editor-form'), root, top, designerField.designer.container ].forEach((n) => {
		    n.style.cssText += 'display:flex; flex-direction:column; flex:1 1 auto; min-height:0; margin-bottom:0';
		});
		designerField.designer.o.fill = true;
		designerField.designer.setStepPanel(stepPanel);
	    }

	    const modal = bootstrap.Modal.getOrCreateInstance(el);
	    this.editor = { nodeId: id, form: form, modal: modal };
	    el.addEventListener('hidden.bs.modal', () => {
		if (this.editor && this.editor.modal === modal)
		    this.editor = null;
		modal.dispose();
		el.remove();
	    });
	    el.addEventListener('shown.bs.modal', () => {
		const first = el.querySelector('.wf-editor-form input:not([type=hidden]), .wf-editor-form textarea');
		if (first && !readonly)
		    first.focus();
	    });
	    modal.show();

	    if (readonly)
		return;

	    const save = () => {
		if (!form.validate()) {
		    //CLD: 2026-10-13T20:36:15+03:00: a problem in the step's settings while a field of the form is selected: show them
		    if (stepPanel && !stepPanel.isConnected && stepPanel.querySelector('.is-invalid'))
			designerField.designer.select(null);
		    return;
		}

		const data = form.getData();
		if (!def.isNote)
		    node.title = data.__title;
		delete data.__title;
		//CLD: 2026-10-11T20:24:33+03:00: outputs that follow the config (Switch cases): connections stay with their output
		const before = this.outPortsOf(node).labels || [];
		node.config = data;
		if (def.outPorts)
		    this.remapOutputs(node, before);

		this.closeEditor();
		this.render();
	    };

	    //Enter in a text input must not submit the page
	    formEl.addEventListener('submit', (e) => {
		e.preventDefault();
		save();
	    });
	    el.querySelector('.btn-editor-ok').addEventListener('click', save);
	    el.querySelector('.btn-editor-delete').addEventListener('click', () => {
		this.closeEditor();
		this.deleteNode(node.wfid);
	    });
	}


	/* ---------------- Node Picker ---------------- */
	//CLD: 2026-10-11T13:34:03+03:00: the items carry what the search box matches (name, label, type, fqname); groups / items get classes for filtering
	//CLD: 2026-10-14T21:06:38+03:00: insideLoop: the step goes inside a loop, so no loop is offered (a loop cannot be inside another loop)
	buildPickerContent(picker, insideLoop) {
	    const nodeDefs = this.nodeDefs;
	    const main_ul = $('<ul>', { class: "list-unstyled p-0 m-0" });

	    var hasTriggerNode = false;
	    this.workflow.forEach((n) => {
		if (n.isNode && n.fqname.includes('trigger'))
		    hasTriggerNode = true;
	    });

	    //get distinct node typesfrom nodeDefs
	    //CLD: 2026-10-24T09:45:36+03:00: without the steps the account's tier does not have (`unavailable`: views/workflow/index.php)
	    const allNodeTypes = Object.keys(nodeDefs).filter((fqname) => !nodeDefs[fqname].unavailable);

	    const types = allNodeTypes.map((fqname) => nodeDefs[fqname].type ).filter((value, index, self) => self.indexOf(value) === index)

	    types.forEach((typename) => {
		if (hasTriggerNode && typename == 'trigger')
		    return;

		const li = $('<li>', { class: 'mb-1 picker-group' });
		const btn = $('<button>', {
		    type: 'button',
		    class: "btn btn-toggle d-inline-flex align-items-center border-0 text-muted",
		    "data-bs-toggle": "collapse",
		    "data-bs-target": "#" + typename + "-collapse",
		    "aria-expanded": true,
		    "text": typename[0].toUpperCase() + typename.slice(1),
		}).appendTo(li);

		const collapse = $('<div>', { class: 'collapse show', id: typename + "-collapse", }).appendTo(li);
		const collapse_ul = $('<ul>', { class: "btn-toggle-nav list-group list-group-flush" }).appendTo(collapse);
		allNodeTypes.filter((fqname) => nodeDefs[fqname].type === typename && !(insideLoop && nodeDefs[fqname].isContainer)).forEach((fqname) => {
		    const def = nodeDefs[fqname];
		    const search = [ def.name, def.label, def.type, fqname ].join(' ').toLowerCase();
		    //CLD: 2026-10-17T19:41:38+03:00: the step's label (was its name, e.g. `download` for Download File); the name when it has no label
		    $('<li>', { class: 'list-group-item list-group-item-action picker-item d-flex align-items-center gap-2', 'data-fqname': fqname, 'data-search': search, draggable: true })
			.html(`<span class="d-inline-flex align-items-center justify-content-center rounded-2 text-white" style="width:20px;height:20px;background:${def.color};font-size:11px;"><i class="bi ${def.icon}"></i></span>`)
			.append(document.createTextNode(def.label || def.name))
			.appendTo(collapse_ul);

		})
		li.appendTo(main_ul);
	    })
	    main_ul.appendTo($(picker).find('.nodes-container'));
	}

	//CLD: 2026-10-11T13:34:03+03:00: one way to open / close every picker. Typing filters the steps (groups without matches hidden), arrows move the highlight, Enter
	//adds the highlighted (or first) step, Escape closes. Clicks inside the picker keep it open (they closed it, e.g. clicking the search box); any click
	//outside closes it (capture phase: also clicks the canvas stops). Was: no search, a document listener per opening
	closePicker() {
	    $('.active-picker').remove();
	    if (this.pickerOutside) {
		document.removeEventListener('click', this.pickerOutside, true);
		this.pickerOutside = null;
	    }
	}

	//picker: buildPicker() result, already positioned; onPick(fqname) adds the step
	//CLD: 2026-10-14T21:06:38+03:00: + insideLoop (buildPickerContent)
	showPicker(picker, onPick, insideLoop) {
	    this.buildPickerContent(picker, insideLoop);

	    const search = picker.find('.picker-search');
	    const visible = () => picker.find('.picker-item').not('.d-none');
	    const highlight = (el) => {
		picker.find('.picker-item.active').removeClass('active');
		if (el && el.length) {
		    el.addClass('active');
		    el[0].scrollIntoView({ block: 'nearest' });
		}
	    };
	    const pick = (fqname) => {
		if (!this.nodeDefs[fqname])
		    return;
		this.closePicker();
		onPick(fqname);
	    };

	    //currentTarget: the item, also when its icon is clicked (e.target was the icon: no fqname)
	    picker.on('click', '.picker-item', (e) => {
		e.preventDefault();
		pick(e.currentTarget.dataset.fqname);
	    });

	    search.on('input', () => {
		const q = search.val().trim().toLowerCase();
		picker.find('.picker-item').each(function() {
		    this.classList.toggle('d-none', q !== '' && !this.dataset.search.includes(q));
		});
		picker.find('.picker-group').each(function() {
		    this.classList.toggle('d-none', $(this).find('.picker-item').not('.d-none').length == 0);
		    //collapsed groups open while searching
		    if (q !== '')
			$(this).find('.collapse').addClass('show');
		});
		picker.find('.picker-empty').toggleClass('d-none', visible().length > 0);
		highlight(q !== '' ? visible().first() : null);
	    });

	    search.on('keydown', (e) => {
		const list = visible();
		const index = list.index(list.filter('.active'));
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
		    e.preventDefault();
		    highlight(list.eq(e.key === 'ArrowDown' ? Math.min(index + 1, list.length - 1) : Math.max(index - 1, 0)));
		} else if (e.key === 'Enter') {
		    e.preventDefault();
		    const el = index >= 0 ? list.eq(index) : list.first();
		    if (el.length)
			pick(el[0].dataset.fqname);
		} else if (e.key === 'Escape') {
		    e.preventDefault();
		    this.closePicker();
		}
	    });

	    $('body').append(picker);
	    //CLD: 2026-10-13T20:32:03+03:00: kept inside the window, measured as it is (its height was guessed: near the bottom the list went off screen); again when a group opens / closes
	    fitPicker(picker);
	    picker.on('shown.bs.collapse hidden.bs.collapse', () => fitPicker(picker));
	    search.trigger('focus');

	    //registered after the click that opened the picker
	    setTimeout(() => {
		this.pickerOutside = (e) => {
		    if (!picker[0].contains(e.target))
			this.closePicker();
		};
		document.addEventListener('click', this.pickerOutside, true);
	    }, 0);
	}

	/* ---------------- Node picker popover ---------------- */
	openPicker(anchorEl, sourceNode, sourcePortIndex){
	    //CLD: 2026-10-11T13:34:03+03:00: closePicker / showPicker (search, keyboard, closing)
	    this.closePicker();

	    const rect = anchorEl.getBoundingClientRect();
	    const vw = window.innerWidth, vh = window.innerHeight;
	    const pickerW = 180;
	    const pickerH = 210;

	    let left = rect.right + 8;
	    let top = rect.top - 6;
	    if(left + pickerW > vw - 10)
		left = rect.left - pickerW - 8;
	    if(top + pickerH > vh - 10)
		top = vh - pickerH - 10;
	    if(top < 10)
		top = 10;

	    const picker = buildPicker();
	    picker.css({ left: left + 'px', top: top + 'px' });

	    this.showPicker(picker, (fqname) => {
		const def = this.nodeDefs[fqname];
		const geo = new Geometry(
		    //sourceNode.geometry.x + def.defaultWidth * 1.5,
		    sourceNode.geometry.x + 200,
		    sourceNode.geometry.y + (sourcePortIndex - (this.outPortsOf(sourceNode).count - 1)/2)*70
		);
		const n = new WfNode(this, null, fqname, geo, def.label);
		//CLD: 2026-10-11T13:46:00+03:00: in the same loop as the step it follows (a trigger never goes in a loop)
		if (def.type !== 'trigger' && sourceNode.parentId) {
		    n.parentId = sourceNode.parentId;
		    this.growContainers(n);
		}
		this.workflow.push(n)
		//CLD: 2026-10-08T08:10:24+03:00: starting config from the server (e.g. webhook token)
		this.initNodeConfig(n);

		if (def.inputs) {
		    const edge = new WfEdge(this, null, sourceNode.wfid, sourcePortIndex, n.wfid, 0);
		    const srcLabels = this.outPortsOf(sourceNode).labels;
		    if(srcLabels)
			edge.branch = srcLabels[sourcePortIndex];
    		    this.workflow.push(edge);
    		}
    		this.selectedId = n.wfid;
    		this.render();
    		//CLD: 2026-10-12T15:21:00+03:00: a new step opens its editor
    		this.openEditor(n.wfid);
    	    //CLD: 2026-10-14T21:06:38+03:00: from a step inside a loop: no loop offered
    	    }, !!sourceNode.parentId);
	}



	/* ---------------- Palette (click & drag to add) ---------------- */
	//CLD: 2026-10-11T13:34:03+03:00: a new step at world position (x, y) (top-left corner), selected
	addNodeAt(fqname, x, y) {
	    const def = this.nodeDefs[fqname];
	    const n = new WfNode(this, null, fqname, new Geometry(x, y), def.label);
	    //CLD: 2026-10-11T13:46:00+03:00: placed on a loop: inside it (findContainerFor: not for triggers)
	    n.parentId = this.findContainerFor(n, new Set());
	    if (n.parentId)
		this.growContainers(n);
	    this.workflow.push(n)
	    //CLD: 2026-10-08T08:10:24+03:00: starting config from the server (e.g. webhook token)
	    this.initNodeConfig(n);

	    this.selectedId = n.wfid;
	    this.render();
	    //CLD: 2026-10-12T15:21:00+03:00: a new step opens its editor
	    this.openEditor(n.wfid);
	}

	//CLD: 2026-10-11T13:34:03+03:00: the palette below anchorEl (the + button, the empty-canvas button): a click adds the step at the middle of the canvas, or drag it
	//onto the canvas (was inside the + button's click handler, which added another canvas drop handler at each opening: one drop added several steps)
	openPalette(anchorEl) {
	    this.closePicker();

	    const picker = buildPicker();
	    const rect = anchorEl.getBoundingClientRect();
	    picker.css({ left: rect.left + 'px', top: (rect.bottom + 5) + 'px' });

	    this.showPicker(picker, (fqname) => {
		const def = this.nodeDefs[fqname];
		const r = this.wrapEl.getBoundingClientRect();
		const w = this.toWorld(r.left + r.width/2, r.top + r.height/2);
		this.addNodeAt(fqname, w.x - def.defaultWidth/2, w.y - def.defaultHeight/2);
	    });

	    //drag handler
	    picker.on('dragstart', '.picker-item', (e) => {
		const fqname = e.currentTarget.dataset.fqname;
		const def = this.nodeDefs[fqname];

		e.originalEvent.dataTransfer.setData('text/plain', fqname);
		e.originalEvent.dataTransfer.effectAllowed = 'copy';
		const preview = buildDragPreview(def);
		$('body').append(preview);
		//CLD: 2026-10-11T13:34:03+03:00: the preview is held at its middle (def.w / def.h do not exist: NaN)
		e.originalEvent.dataTransfer.setDragImage(preview.get(0), def.defaultWidth/2, def.defaultHeight/2);
		setTimeout(() => preview.remove(), 0);
	    });
	}

	setupPickerButton() {
	    if (this.options.readonly)
		return;

	    const btnPalette = $(`
		<button type="button" class="btn btn-sm btn-success btn-palette position-absolute top-0 start-0 mt-4 ms-4" style="z-index: 20">
		    <i class="bi bi-plus-lg"></i>
		</button>
	    `);
	    btnPalette.on('click', (e) => {
		e.stopPropagation();
		this.openPalette(e.currentTarget);
	    });
	    btnPalette.appendTo(this.wrapEl);

	    //CLD: 2026-10-11T13:34:03+03:00: steps dragged from the palette: one handler, set once (was added at each opening of the palette)
	    //CLD: 2026-10-19T22:00:00+03:00: listen() (was wrapEl.addEventListener)
	    this.listen(this.wrapEl, 'dragover', e=>e.preventDefault());
	    this.listen(this.wrapEl, 'drop', e => {
		e.preventDefault();
		const fqname = e.dataTransfer.getData('text/plain');
		const def = this.nodeDefs[fqname];
		if(!fqname || !def)
		    return;
		this.closePicker();
		const w = this.toWorld(e.clientX, e.clientY);
		this.addNodeAt(fqname, w.x, w.y);
	    });

	    //CLD: 2026-10-11T13:34:03+03:00: empty canvas: a button in the middle opens the palette (shown by render() while there are no steps)
	    const empty = $(`
		<div class="wf-empty position-absolute top-50 start-50 translate-middle text-center d-none" style="z-index: 15">
		    <button type="button" class="btn btn-outline-success btn-lg rounded-circle mb-2" style="width: 64px; height: 64px">
			<i class="bi bi-plus-lg"></i>
		    </button>
		    <div class="text-muted small">Add the first step: a trigger starts the workflow</div>
		</div>
	    `);
	    empty.find('button').on('click', (e) => {
		e.stopPropagation();
		this.openPalette(e.currentTarget);
	    });
	    empty.on('pointerdown', (e) => e.stopPropagation());
	    empty.appendTo(this.wrapEl);
	}


	/* ---------------- Inspector and debug resize ---------------- */
	setupInspectorResize() {
	    const minWidth = 320; //in px, 20rem
	    const maxWidth = 640; //in px, 40rem
	    let lastWidth = minWidth;

	    let el = $('.inspector');
	    if (!el.length)
		return;

	    const handle = el.find('.inspector-resize-handle');
	    if (!handle.length)
		return;

	    //CLD: 2026-10-19T19:05:00+03:00: the width set by the handle is kept in localStorage (this browser), and used again when a designer opens; lastWidth starts
	    //at the panel's own width (a click on the handle without a drag stores that, not minWidth)
	    const WIDTH_KEY = 'netflow9.wf.inspector.width';
	    lastWidth = Math.max(minWidth, Math.min(maxWidth, Math.round(el.outerWidth()) || minWidth));
	    try {
		const stored = parseInt(localStorage.getItem(WIDTH_KEY), 10);
		if (Number.isFinite(stored)) {
		    lastWidth = Math.max(minWidth, Math.min(maxWidth, stored));
		    el.css({ width: lastWidth + 'px', minWidth: lastWidth + 'px' });
		}
	    } catch (e) {}

	    //CLD: 2026-10-19T22:00:00+03:00: namespaced, removed by destroy()
	    this.unlisten.push(() => handle.off('.wfdesigner'));
	    //CLD: 2026-10-19T23:05:00+03:00: pointer events (mouse, touch and pen; were mouse events), as setupPanelResize: the pointer is captured by the handle,
	    //pointercancel ends the drag like pointerup; touch-action none on the handle (theme netflow9/_workflow.scss)
	    handle.on('pointerdown.wfdesigner', (e) => {
		//main button only (a touch or a pen contact is button 0 too)
		if (e.button !== 0)
		    return;
		e.preventDefault();
		const pointerId = e.originalEvent.pointerId;
		try {
		    handle[0].setPointerCapture(pointerId);
		} catch (err) {}

		//CLD: 2026-10-19T20:10:00+03:00: from the panel's width now (it may have been hidden when the designer opened: the Assistant plugin's panel; a click without a drag stores that)
		lastWidth = Math.max(minWidth, Math.min(maxWidth, Math.round(el.outerWidth()) || minWidth));
		handle.addClass('dragging');
		el.removeClass('collapsed');
		$('body').css({ userSelect: 'none', cursor: 'col-resize' });

		handle
		    .on('pointermove.iresize', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
			lastWidth = Math.max(minWidth, Math.min(maxWidth, window.innerWidth - e.originalEvent.clientX));
			el.css({ width: lastWidth + 'px', minWidth: lastWidth + 'px' });
		    })
		    .on('pointerup.iresize pointercancel.iresize', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
    			handle.removeClass('dragging');
			$('body').css({ userSelect: '', cursor: '' });
			handle.off('.iresize');
			try {
			    handle[0].releasePointerCapture(pointerId);
			} catch (err) {}
			//CLD: 2026-10-19T19:05:00+03:00: kept for the next designers
			try {
			    localStorage.setItem(WIDTH_KEY, String(lastWidth));
			} catch (e) {}
		    });
	    });
	}

	//CLD: 2026-10-10T19:08:46+03:00: each bottom panel (debug, executions) resizes on its own (was: all .debug-panel elements together)
	setupDebugResize() {
	    $('.debug-panel').each((i, el) => {
		this.setupPanelResize($(el));
	    });
	}

	setupPanelResize(el) {
	    const minHeight = 40; //px
	    const maxHeight = 520; //px

	    let lastHeight = minHeight;

	    const handle = el.find('.debug-resize-handle');
	    if (!handle.length)
		return;

	    //CLD: 2026-10-19T19:20:00+03:00: the height set by the handle is kept in localStorage (this browser), one per panel, and used again when a designer opens (opening a panel
	    //lower than 120px still makes it 300px: debug.open / exec.open in views/workflow/index.php)
	    const HEIGHT_KEY = el.hasClass('exec-panel') ? 'netflow9.wf.exec.height' : el.hasClass('wf-debug-panel') ? 'netflow9.wf.debug.height' : null;
	    if (HEIGHT_KEY) {
		try {
		    const stored = parseInt(localStorage.getItem(HEIGHT_KEY), 10);
		    if (Number.isFinite(stored)) {
			lastHeight = Math.max(minHeight, Math.min(maxHeight, stored));
			el.css('height', lastHeight + 'px');
		    }
		} catch (e) {}
	    }

	    // Resize (debug panel height) — drag the handle up/down; height is
	    // measured from the bottom of the viewport up to the cursor.

	    //CLD: 2026-10-19T22:00:00+03:00: namespaced, removed by destroy()
	    this.unlisten.push(() => handle.off('.wfdesigner'));
	    //CLD: 2026-10-19T22:55:00+03:00: pointer events (mouse, touch and pen; were mouse events: no resize on touch screens). The pointer is captured by the
	    //handle, so its moves come to the handle even outside it; pointercancel (e.g. the browser takes the gesture) ends the drag like pointerup.
	    //The handle has touch-action none (theme netflow9/_debug.scss), so a touch drag does not scroll the page
	    handle.on('pointerdown.wfdesigner', (e) => {
		//main button only (a touch or a pen contact is button 0 too)
		if (e.button !== 0)
		    return;
		e.preventDefault();
		const pointerId = e.originalEvent.pointerId;
		try {
		    handle[0].setPointerCapture(pointerId);
		} catch (err) {}

		//CLD: 2026-10-19T19:20:00+03:00: from the panel's own height (a click without a drag stores that, not minHeight)
		lastHeight = Math.max(minHeight, Math.min(maxHeight, Math.round(el.outerHeight()) || minHeight));
		handle.addClass('dragging');
		$('body').css({ 'user-select': 'none', 'cursor': 'row-resize' });

		handle
		    .on('pointermove.debug', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
			lastHeight = Math.max(minHeight, Math.min(maxHeight, window.innerHeight - e.originalEvent.clientY));
			el.css('height', lastHeight + 'px');
		    })
		    .on('pointerup.debug pointercancel.debug', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
    			handle.removeClass('dragging');
			$('body').css({ 'user-select': '', 'cursor': '' });
			handle.off('.debug');
			try {
			    handle[0].releasePointerCapture(pointerId);
			} catch (err) {}
			//CLD: 2026-10-19T19:20:00+03:00: kept for the next designers
			if (HEIGHT_KEY) {
			    try {
				localStorage.setItem(HEIGHT_KEY, String(lastHeight));
			    } catch (e) {}
			}
		    });
	    });
	}


	//load nodes
	loadNodes(nodes) {
	    nodes.forEach((n) => {
		var node;
		if (n.title) {
		    node = new WfNode(this, n.wfid, n.fqname, n.geometry, n.title, n.config);
		    node.parentId = n.parentId || null;
		} else {
		    node = new WfEdge(this, n.wfid, n.fromNode, n.fromPort, n.toNode, n.toPort, n.branch);
		}
		this.workflow.push(node);
	    });
	}
    }

    return Workflow;
})();
