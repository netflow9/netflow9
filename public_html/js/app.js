//get all object keys and (if object) call `init`
if (!window.app)
    window.app = {};

window.app.sidebar = (function() {
    //the width limits (px) are the layout's (aside data-min-width / data-max-width: data\Constants), read by init
    let minWidth = 250;
    let maxWidth = 400;

    let sidebar;
    let lastWidth = 0;

    //the width set by the resize handle is kept for the user (User prefs sidebar_width: every browser):
    //rendered by the layout (aside data-width), saved by /site/prefs
    const storedWidth = () => {
	const w = parseInt(sidebar.attr('data-width'), 10);
	return Number.isFinite(w) ? Math.max(minWidth, Math.min(maxWidth, w)) : null;
    };
    //the collapsed state (User prefs sidebar_collapsed; rendered by the layout: aside.collapsed); storeWidth saves both (a drag expands the sidebar)
    const store = (data) => {
	$.ajax({ url: '/site/prefs', method: 'POST', data: data, dataType: 'json',
	    headers: { 'X-Csrf-Token': app.utils.csrf(), 'X-Requested-With': 'XMLHttpRequest' } })
	    .fail((xhr) => console.warn('app.sidebar: the state was not saved', xhr.status));
    };
    const storeWidth = (w) => {
	w = Math.round(w);
	sidebar.attr('data-width', w);
	store({ sidebar_width: w, sidebar_collapsed: 0 });
    };

    //the width goes in the --sidebar-w custom property, so the theme's media queries can replace it
    const setWidth = (w) => {
	if (sidebar && sidebar[0])
	    sidebar[0].style.setProperty('--sidebar-w', w + 'px');
    };

    //below lg (theme rail22/_app.scss: media-breakpoint-down(lg)) the sidebar is a drawer: .sidebar-open on .app-shell shows it;
    //the desktop width and collapsed state are left as they are
    const mobileQuery = window.matchMedia('(max-width: 991.98px)');
    const isMobile = () => mobileQuery.matches;
    const openDrawer = () => $('.app-shell').addClass('sidebar-open');
    const closeDrawer = () => $('.app-shell').removeClass('sidebar-open');

    // Resize (sidebar width)
    let isCollapsed = false;
    let _document = $(document);


    const api = {

	//routing highlight on location.href change
	highlight: function (e) {
	    console.log('app.sidebar.highlight: current location.href=', location.href);
	    //locate an `a` with location href in it and add class active
	    const url = URL.parse(location.href);

	    //if an `a` with current url is not found, leave active the latest
	    const crtLink = sidebar.find('a[href="' + url.pathname + '"]');
	    if (crtLink.length) {
		sidebar.find('a.nav-link').removeClass('active');
		crtLink.addClass('active');
		//if closest parent with .sidebar-view has .sidebar-view-secondary, activate secondary menu
		const sidebarViewParent = crtLink.closest('.sidebar-view');
		if (sidebarViewParent.hasClass('sidebar-view-secondary')) {
		    $('.sidebar-view-primary').addClass('d-none');
		    $('.sidebar-view-secondary').removeClass('d-none');
		}
	    }
	},

	init: function() {
	    sidebar = $('.sidebar');
	    minWidth = parseInt(sidebar.attr('data-min-width'), 10) || minWidth;
	    maxWidth = parseInt(sidebar.attr('data-max-width'), 10) || maxWidth;

	    if (!sidebar)
		return;

	    //rendered collapsed (the state kept for the user)
	    isCollapsed = sidebar.hasClass('collapsed');
	    //on a small screen (and when collapsed: its width is 0) the drawer's width is not the desktop one: 16rem, the theme's default
	    lastWidth = isMobile() || isCollapsed ? 16 * parseFloat(getComputedStyle(document.documentElement).fontSize) : sidebar.width();
	    //the stored width, if any
	    const stored = storedWidth();
	    if (stored !== null) {
		lastWidth = stored;
		setWidth(lastWidth);
	    }

	    // Collapse / expand
	    _document.on('click', '.btn-collapse-sidebar', (e) => {
		    console.log('sidebar: click on collapsed');
		    e.preventDefault();
		    //small screen: closes the drawer
		    if (isMobile())
			return closeDrawer();
		    isCollapsed = true;
		    sidebar.addClass('collapsed');
		    $('.btn-expand-sidebar').removeClass('d-none');
		    store({ sidebar_collapsed: 1 });
	    })
	    _document.on('click', '.btn-expand-sidebar', (e) => {
		    //preventDefault (an <a href="#">); small screen: opens the drawer
		    e.preventDefault();
		    if (isMobile())
			return openDrawer();
		    isCollapsed = false;
		    sidebar.removeClass('collapsed');
		    setWidth(lastWidth);
		    $('.btn-expand-sidebar').addClass('d-none');
		    store({ sidebar_collapsed: 0 });
	    });

	    //the drawer closes on its backdrop, Escape, a link that navigates, going back, or when the window gets wide
	    _document.on('click', '.sidebar-backdrop', closeDrawer);
	    _document.on('keydown', (e) => {
		if (e.key === 'Escape' && $('.app-shell').hasClass('sidebar-open'))
		    closeDrawer();
	    });
	    sidebar.on('click', 'a[href]', function(e) {
		const href = $(this).attr('href');
		if (isMobile() && href && href !== '#' && !$(this).is('[data-bs-toggle]'))
		    closeDrawer();
	    });
	    window.addEventListener('popstate', closeDrawer);
	    mobileQuery.addEventListener('change', closeDrawer);

	    //pointer events (mouse, touch and pen), as the designer's panels
	    //(app.workflow.js setupPanelResize): the pointer is captured by the handle, pointercancel ends the drag like pointerup; touch-action none on the
	    //handle (theme rail22/_app.scss). Delegated on the sidebar: the handle is new after the aside is rendered again (sidebar::refresh)
	    sidebar.on('pointerdown', '.sidebar-resize-handle', (e) => {
		//main button only (a touch or a pen contact is button 0 too)
		if (e.button !== 0)
		    return;
		e.preventDefault();
		const handle = $(e.currentTarget);
		const pointerId = e.originalEvent.pointerId;
		try {
		    e.currentTarget.setPointerCapture(pointerId);
		} catch (err) {}

		handle.addClass('dragging');
		sidebar.removeClass('collapsed');
		//expanded by the drag, as by the expand button (saved with the width: storeWidth)
		isCollapsed = false;
		$('.btn-expand-sidebar').addClass('d-none');
		$('body').css({ userSelect: 'none', cursor: 'col-resize' });

		handle
		    .on('pointermove.sbresize', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
			lastWidth = Math.max(minWidth, Math.min(maxWidth, e.originalEvent.clientX));
			setWidth(lastWidth);
		    })
		    .on('pointerup.sbresize pointercancel.sbresize', (e) => {
			if (e.originalEvent.pointerId !== pointerId)
			    return;
    			handle.removeClass('dragging');
			$('body').css({ userSelect: '', cursor: '' });
			handle.off('.sbresize');
			try {
			    handle[0].releasePointerCapture(pointerId);
			} catch (err) {}
			//kept for the next page loads
			storeWidth(lastWidth);
		    });
	    });

	    //clicks
	    sidebar.on('click', 'a.nav-link', function(e) {
		sidebar.find('a.nav-link').removeClass('active');
		$(this).addClass('active');
	    });

	    //click on secondary-menu toggle
	    sidebar.on('click', 'a.open-secondary-menu', (e) => {
		e.preventDefault();
		$('.sidebar-view-primary').addClass('d-none');
		$('.sidebar-view-secondary').removeClass('d-none');
	    });
	    sidebar.on('click', 'a.close-secondary-menu', (e) => {
		e.preventDefault();
		$('.sidebar-view-primary').removeClass('d-none');
		$('.sidebar-view-secondary').addClass('d-none');
	    });

	    //initialization
	    window.removeEventListener('popstate', () => { api.highlight() });
	    window.addEventListener('popstate', () => { api.highlight() });

	    api.highlight();
	},
    };

    return api;
})();


//event handler
app.events = (function() {
    let _listeners = {};

    const api = {
	//register an event handler
	on: function(eventNames, fn) {
	    console.log('app.events.on: registering events:', eventNames);
    	    for (const eventName of eventNames.split(' ').filter(Boolean)) {
		console.log('app.events.on: registering handler for event:', eventName);
    		(_listeners[eventName] ||= []).push(fn);
    	    }
	},
	off: function(eventNames, fn) {
    	    for (const eventName of eventNames.split(' ').filter(Boolean)) {
    		if (!fn)
    		    delete _listeners[eventName];
    		else
    		    _listeners[eventName] = (_listeners[eventName] || []).filter(f => f !== fn);
    	    }
        },

	dispatch: function(e) {
	    if (!_listeners[e.type]) {
		console.log('app.events.dispatch: no handlers for event:', e.type);
		console.log('app.events.dispatch: listeners:', _listeners);
	    }

    	    for (const fn of (_listeners[e.type] || []).slice()) {
    		fn.call(this, e.data);
    	    }
	},

	init: function() {
	    _listeners = {};
	    //pre-register some system handlers
	    api.on('toast::add', app.utils.toast);
	    api.on('sidebar::refresh', (data) => {
		var timer = setInterval(() => {
		    if (!app.pjax.isActive()) {
			clearInterval(timer);
			app.pjax.fetch('/site/render_aside', { push: false, destination: $('aside.sidebar') });
		    }
		}, 100);
	    });
	},
    };


    return api;
})();

app.utils = (function() {
    var uidCounter = 0;

    return {
	uid: function(prefix) {
	    uidCounter++;
	    return (prefix || 'fld') + '-' + uidCounter;
	},

	//toast display utility
	toast: function(data) {
	    const defaults = {
		type: 'info',
		delay: 3000,
		title: '',
		body: '',
	    }

    	    data = $.extend({}, defaults, data);

	    //color is one of: 'error', 'warning', 'success', 'info'
	    var color = 'text-' + data.type;

	    var toast = $('' +
	    '<div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="' + data.delay + '">' +
		'<div class="toast-header">' +
		    '<i class="bi me-2 fs-5 bi-circle-fill ' + color + '"></i>' +
		    '<strong class="me-auto">' + data.title + '</strong>' +
		    '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' +
		'</div>' +
		'<div class="toast-body">' +
		    data.body +
		'</div>' +
	    '</div>');

	    $(toast).appendTo($('.toast-container'))
	    bootstrap.Toast.getOrCreateInstance(toast[0]).show();
	},

	//a toast of plain texts (escaped), shown 5 s: type error, warning, success, info
	toastText: function(type, title, body) {
	    this.toast({ type: type, title: this.escapeHtml(title), body: this.escapeHtml(body), delay: 5000 });
	},

	//the page's CSRF token (<meta name=csrf-token>), for the X-Csrf-Token header of a POST
	csrf: function() {
	    return document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
	},

	//a Bootstrap badge: <span class='badge text-bg-COLOR'>label</span>, the label escaped; color: success, danger, primary, info, warning, secondary
	badge: function(color, label) {
	    return `<span class='badge text-bg-${color}'>${this.escapeHtml(label)}</span>`;
	},

	//create a DOM element with given className and attributes
	el: function (tag, className, attrs) {
	    const n = document.createElement(tag);
	    if (className)
		n.className = className;
	    if (attrs) {
		for (const k in attrs)
		    n.setAttribute(k, attrs[k]);
	    }
	    return n;
	},

	//any value (null/undefined -> '', numbers -> text) and single quotes too, so the files' own esc() helpers could go
	escapeHtml: function(s) {
	    return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
	},
    };
})();

// JavaScript micro-templating
// Adapted from Underscore templating
// handlebars-style, preserves whitespace, and correctly escapes quotes within interpolated code.
app.template = (function() {
    function escapeChars(text) {
	const escapeRegExp = /\\|'|\r|\n/g;
	return text.replace(escapeRegExp, (match) => {
		    const escapes = { "'": "'", '\\': '\\', '\r': 'r', '\n': 'n', };
		    return '\\' + escapes[match];
	});
    }

    // Combine delimiters into one regular expression via alternation.
    const delimiters = {
	print: /{{([\s\S]+?)}}/g,
	exec: /{%([\s\S]+?)%}/g,
	escape: /{{-([\s\S]+?)}}/g,
    };


    return {
	vars: function(text) {
	    return text.match(delimiters.print).map(el => el.replace('{{','').replace('}}','').trim());
	},
	compile: function(text) {
	    const matcher = RegExp([
		delimiters.escape.source, //escape: {{- .... }}
    		delimiters.print.source, //print: {{ .... }}
    		delimiters.exec.source, //execute: {% ... %}
	    ].join('|') + '|$', 'g');

	    // Compile the template source, escaping string literals appropriately.
	    var index = 0;
	    var source = '';
	    text.replace(matcher, function(match, escape, print, execute, offset) {
    		source += escapeChars(text.slice(index, offset));
    		index = offset + match.length;

    		if (escape) {
    		    //app.utils.escapeHtml; the compiled function (new Function) sees only globals, never the local _escape it called
    		    source += `'+((_t=(${escape}))==null?'':app.utils.escapeHtml(_t))+'`;
    		    return;
    		}
    		if (print) {
    		    source += `'+((_t=(${print}))==null?'':_t)+'`;
    		    return;
    		}
    		if (execute) {
    		    source += `';${execute} _p+='`;
    		    return;
    		}
	    });

	    source = `var _t,_p;with(obj||{}){_p='${source}';}return _p;`;

	    //validate source code
	    try {
    		const render = new Function('obj', source);
	    } catch (e) {
    		e.source = source;
    		throw e;
	    }

	    return function(data) {
		return (new Function('obj', source))(data);
	    };
	},
    };
})();

//the theme, light, dark or auto (the system's), chosen per browser (localStorage rail22.theme; read first by the <head> script of
//layouts/_webpage.php, which sets data-bs-theme / data-theme-choice on <html> before the styles load). The switcher: buttons [data-theme-value] (the sidebar's); auto follows
//the system's changes, another tab's choice is followed too; when the theme shown changes: `app:themechange` on document ({ detail: { theme } }), e.g. the overview's charts
app.theme = (function() {
    const KEY = 'rail22.theme';
    const CHOICES = [ 'light', 'dark', 'auto' ];
    const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    const choice = () => {
	const c = document.documentElement.getAttribute('data-theme-choice');
	return CHOICES.includes(c) ? c : 'auto';
    };
    const resolved = (c) => (c === 'dark' || (c === 'auto' && media && media.matches)) ? 'dark' : 'light';

    //shows choice $c: the theme on <html>, the switchers' buttons
    const apply = (c) => {
	const html = document.documentElement;
	const before = html.getAttribute('data-bs-theme');
	const theme = resolved(c);
	html.setAttribute('data-theme-choice', c);
	html.setAttribute('data-bs-theme', theme);
	document.querySelectorAll('[data-theme-value]').forEach((b) => {
	    const on = b.getAttribute('data-theme-value') === c;
	    b.classList.toggle('active', on);
	    b.setAttribute('aria-pressed', on ? 'true' : 'false');
	});
	if (before !== theme)
	    document.dispatchEvent(new CustomEvent('app:themechange', { detail: { theme: theme } }));
    };

    return {
	init() {
	    apply(choice());
	    document.addEventListener('click', (e) => {
		const b = e.target.closest('[data-theme-value]');
		if (!b)
		    return;
		e.preventDefault();
		this.set(b.getAttribute('data-theme-value'));
	    });
	    if (media)
		media.addEventListener('change', () => {
		    if (choice() === 'auto')
			apply('auto');
		});
	    window.addEventListener('storage', (e) => {
		if (e.key === KEY && CHOICES.includes(e.newValue))
		    apply(e.newValue);
	    });
	},

	//chooses $c (light, dark, auto) for this browser
	set(c) {
	    if (!CHOICES.includes(c))
		return;
	    try {
		localStorage.setItem(KEY, c);
	    } catch (e) {}
	    apply(c);
	},

	//the choice (light, dark, auto) and the theme shown (light, dark)
	choice: choice,
	current() {
	    return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
	},
    };
})();

+function() {
    console.log('app: initializing');
    const app_modules = Object.keys(window.app);
    app_modules.forEach((el) => {
	if (typeof window.app[el].init === "function") {
	    window.app[el].init();
	}
    });
}();

