//get all object keys and (if object) call `init`
if (!window.app)
    window.app = {};

window.app.sidebar = (function() {
    const minWidth = 250; //px
    const maxWidth = 400; //px

    let sidebar;
    let lastWidth = 0;

    //CLD: 2026-10-19T19:05:00+03:00: the width set by the resize handle is kept in localStorage (this browser), and used again on load
    const WIDTH_KEY = 'netflow9.sidebar.width';
    const storedWidth = () => {
	try {
	    const w = parseInt(localStorage.getItem(WIDTH_KEY), 10);
	    return Number.isFinite(w) ? Math.max(minWidth, Math.min(maxWidth, w)) : null;
	} catch (e) {
	    return null;
	}
    };
    const storeWidth = (w) => {
	try {
	    localStorage.setItem(WIDTH_KEY, String(w));
	} catch (e) {}
    };

    //CLD: 2026-10-19T22:19:24+03:00: the width goes in the --sidebar-w custom property (was inline width/min-width), so the theme's media queries can replace it
    const setWidth = (w) => {
	if (sidebar && sidebar[0])
	    sidebar[0].style.setProperty('--sidebar-w', w + 'px');
    };

    //CLD: 2026-10-19T22:19:24+03:00: below lg (theme netflow9/_app.scss: media-breakpoint-down(lg)) the sidebar is a drawer: .sidebar-open on .app-shell shows it;
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

	    if (!sidebar)
		return;

	    //CLD: 2026-10-19T22:19:24+03:00: on a small screen the drawer's width is not the desktop one: 16rem, the theme's default
	    lastWidth = isMobile() ? 16 * parseFloat(getComputedStyle(document.documentElement).fontSize) : sidebar.width();
	    //CLD: 2026-10-19T19:05:00+03:00: the stored width, if any
	    const stored = storedWidth();
	    if (stored !== null) {
		lastWidth = stored;
		setWidth(lastWidth);
	    }

	    // Collapse / expand
	    _document.on('click', '.btn-collapse-sidebar', (e) => {
		    console.log('sidebar: click on collapsed');
		    e.preventDefault();
		    //CLD: 2026-10-19T22:19:24+03:00: small screen: closes the drawer
		    if (isMobile())
			return closeDrawer();
		    isCollapsed = true;
		    sidebar.addClass('collapsed');
		    $('.btn-expand-sidebar').removeClass('d-none');
	    })
	    _document.on('click', '.btn-expand-sidebar', (e) => {
		    //CLD: 2026-10-19T22:19:24+03:00: preventDefault (an <a href="#">); small screen: opens the drawer
		    e.preventDefault();
		    if (isMobile())
			return openDrawer();
		    isCollapsed = false;
		    sidebar.removeClass('collapsed');
		    setWidth(lastWidth);
		    $('.btn-expand-sidebar').addClass('d-none');
	    });

	    //CLD: 2026-10-19T22:19:24+03:00: the drawer closes on its backdrop, Escape, a link that navigates, going back, or when the window gets wide
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

	    //CLD: 2026-10-19T23:05:00+03:00: pointer events (mouse, touch and pen; were mouse events: no resize on touch screens), as the designer's panels
	    //(app.workflow.js setupPanelResize): the pointer is captured by the handle, pointercancel ends the drag like pointerup; touch-action none on the
	    //handle (theme netflow9/_app.scss). Delegated on the sidebar (was bound to the handle): the handle is new after the aside is rendered again (sidebar::refresh)
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
			//CLD: 2026-10-19T19:05:00+03:00: kept for the next page loads
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


//CLD: 2026-10-19T12:05:32+03:00: timeAgo is app.formatter.timeAgo(past, short) (was the app.timeAgo module, app.timeAgo.inWords; the PHP util\TimeAgo is removed):
//past is a date or ISO 8601 text; short: 1d, ~1h, <1m... instead of `1 day ago`; empty past -> ''
app.formatter = (function() {
    const __texts = {
	normal: {
	    aboutOneDay: '1 day ago',
	    aboutOneHour: 'about 1 hour ago',
	    aboutOneMonth: 'about 1 month ago',
	    aboutOneYear: 'about 1 year ago',
	    days: '{0} days ago',
	    hours: '{0} hours ago',
	    lessThanAMinute: 'less than a minute ago',
	    lessThanOneHour: '{0} minutes ago',
	    months: '{0} months ago',
	    oneMinute: '1 minute ago',
	    years: 'over {0} years ago',
	    never: 'Never',
	},
	short: {
	    aboutOneDay: '1d',
	    aboutOneHour: '~1h',
	    aboutOneMonth: '~1M',
	    aboutOneYear : '~y',
	    days: '{0}d',
	    hours: '{0}h',
	    lessThanAMinute: '<1m',
	    lessThanOneHour: '{0}m',
	    months: '{0}M',
	    oneMinute: '1m',
	    years: '>{0}y',
	}
    };

    function format(format) {
	var args = Array.prototype.slice.call(arguments, 1);
	return format.replace(/{(\d+)}/g, function(match, number) { 
    	    return typeof args[number] != 'undefined' ? args[number] : match;
	});
    }

    function isLessThan29Seconds(s) {
	return s < 30;
    }

    function isLessThan1Min29Seconds(s) {
	return s < 90;
    }

    function isLessThan44Min29Secs(s) {
	return s < 44 * 60 + 30;
    }

    function isLessThan1Hour29Mins59Seconds(s) {
	return s < 1 * 3600 + 29 * 60 + 59;
    }

    function isLessThan23Hours59Mins29Seconds(s) {
	return s < 23 * 3600 + 59 * 60 + 29;
    }

    function isLessThan47Hours59Mins29Seconds(s) {
	return s < 47 * 3600 + 59 * 60 + 29;
    }

    function isLessThan29Days23Hours59Mins29Seconds(s) {
	return s < 29 * 24 * 3600 + 23 * 3600 + 59 * 60 + 29;
    }

    function isLessThan59Days23Hours59Mins29Secs(s) {
	return s < 59 * 24 * 3600 + 23 * 3600 + 59 * 60 + 29;
    }

    function isLessThan1Year(s) {
	return s < 363 * 24 * 3600;
    }

    function isLessThan2Years(s) {
	return s < 363 * 24 * 3600 + 365 * 24 * 3600;
    }

    function isMoreThan2Years(s) {
        return s > 2 * 365 * 24 * 3600;
    }

    //CLD: 2026-10-24T17:30:00+03:00: d (Date / ISO 8601 text) in a PHP date() format (app.DatePicker.formatDate; without it: ISO 8601)
    function formatAs(d, fmt) {
	if (!d)
	    return '';
	d = d instanceof Date ? d : new Date(d);
	if (isNaN(d))
	    return '';
	return window.app.DatePicker ? window.app.DatePicker.formatDate(d, fmt) : d.toISOString();
    }

    return {
	//CLD: 2026-10-24T18:00:00+03:00: as util\Format::bytes: 1536 -> "1.5 KB", 0 -> "0 B"; binary (1024) or decimal (1000) units, KB / MB / GB / TB / PB;
	//trailing zeros dropped, rounding up to the next unit (1023.96 KB -> "1 MB"); empty or not a number -> '' (was "0 B"; always 1 decimal, up to TB)
	bytes: function(bytes, decimals = 1, binary = true) {
	    if (bytes === null || bytes === undefined || bytes === '' || isNaN(bytes))
		return '';
	    bytes = Number(bytes);
	    const base = binary ? 1024 : 1000;
	    const units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
	    let i = 0;
	    while (Math.abs(bytes) >= base && i < units.length - 1) {
		bytes /= base;
		i++;
	    }
	    if (i > 0 && i < units.length - 1 && Math.abs(Number(bytes.toFixed(decimals))) >= base) {
		bytes /= base;
		i++;
	    }
	    return (i === 0 ? String(Math.trunc(bytes)) : String(Number(bytes.toFixed(decimals)))) + ' ' + units[i];
	},

	//CLD: 2026-10-24T17:30:00+03:00: a date / datetime in the account's format (<html> data-date-format / data-datetime-format: util\Format, PHP date() tokens,
	//app.DatePicker.formatDate), in the browser's time zone; d: a Date or ISO 8601 text; empty or not a date -> ''
	date: function(d) {
	    return formatAs(d, document.documentElement.dataset.dateFormat || 'Y-m-d');
	},

	datetime: function(d) {
	    return formatAs(d, document.documentElement.dataset.datetimeFormat || 'Y-m-d H:i:s');
	},

	//CLD: 2026-10-24T17:30:00+03:00: timeAgo as <time datetime="ISO 8601" title="the datetime, in the account's format">words</time> (escaped; as util\Format::timeagoHtml)
	timeAgoHtml: function(past, short) {
	    const words = this.timeAgo(past, short);
	    if (!words)
		return '';
	    const d = new Date(past);
	    const esc = (s) => window.app.utils.escapeHtml(String(s));
	    return isNaN(d) ? esc(words) : `<time datetime="${esc(d.toISOString())}" title="${esc(this.datetime(d))}">${esc(words)}</time>`;
	},

	timeAgo: function(past, short) {
	    if (!past)
		return '';
	    if (!past.length)
		return '';

	    const texts = short ? __texts.short : __texts.normal;

	    past = new Date(past);
	    const now = new Date;
    	    const diff = Math.floor((now - past) / 1000);

    	    // rule 1
    	    // less than 29secs
    	    if (isLessThan29Seconds(diff))
        	return texts.lessThanAMinute;

    	    // rule 2
    	    // more than 29secs and less than 1min29secss
    	    if (isLessThan1Min29Seconds(diff))
        	return texts.oneMinute;

    	    // rule 3
    	    // between 1min30secs and 44mins29secs
    	    if (isLessThan44Min29Secs(diff))
        	return format(texts.lessThanOneHour, Math.floor(diff / 60));

    	    // rule 4
    	    // between 44mins30secs and 1hour29mins59secs
    	    if (isLessThan1Hour29Mins59Seconds(diff))
        	return texts.aboutOneHour;

    	    // rule 5
    	    // between 1hour29mins59secs and 23hours59mins29secs
    	    if (isLessThan23Hours59Mins29Seconds(diff))
        	return format(texts.hours, Math.floor(diff / 3600));

    	    // rule 6
    	    // between 23hours59mins30secs and 47hours59mins29secs
    	    if (isLessThan47Hours59Mins29Seconds(diff))
        	return texts.aboutOneDay;

    	    // rule 7
    	    // between 47hours59mins30secs and 29days23hours59mins29secs
    	    if (isLessThan29Days23Hours59Mins29Seconds(diff))
        	return format(texts.days, Math.floor(diff / (24 * 3600)));

    	    // rule 8
    	    // between 29days23hours59mins30secs and 59days23hours59mins29secs
    	    if (isLessThan59Days23Hours59Mins29Secs(diff))
        	return texts.aboutOneMonth;

    	    // rule 9
    	    // between 59days23hours59mins30secs and 1year (minus 1sec)
    	    if (isLessThan1Year(diff)) {
		const months = Math.max((now.getFullYear() - past.getFullYear()) * 12 + now.getMonth() - past.getMonth(), 0);
        	return format(texts.months, months);
    	    }

    	    // rule 10
    	    // between 1year and 2years (minus 1sec)
    	    if (isLessThan2Years(diff))
        	return texts.aboutOneYear;

    	    // rule 11
    	    // 2years or more
	    const years = Math.max(now.getFullYear() - past.getFullYear(), 0);
	    return format(texts.years, years);
	},
    };
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

	escapeHtml: function(s) {
	    return (s || '').replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
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
    		    source += `'+((_t=(${escape}))==null?'':_escape(_t))+'`;
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
		const _escape = (s) => {
		    return (s || '').replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
		};
		return (new Function('obj', source))(data);
	    };
	},
    };
})();

//CLD: 2026-10-24T11:20:00+03:00: new: the theme, light, dark or auto (the system's), chosen per browser (localStorage netflow9.theme; read first by the <head> script of
//layouts/_webpage.php, which sets data-bs-theme / data-theme-choice on <html> before the styles load). The switcher: buttons [data-theme-value] (the sidebar's); auto follows
//the system's changes, another tab's choice is followed too; when the theme shown changes: `app:themechange` on document ({ detail: { theme } }), e.g. the overview's charts
app.theme = (function() {
    const KEY = 'netflow9.theme';
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

