if (!window.app)
    window.app = {};

/**
 * DatePicker — a small, dependency-free date / datetime picker.
 * Replaces a text input with a Bootstrap-styled popup calendar,
 * and keeps a hidden input in sync for form submission.
 *
 * Usage:
 *   new DatePicker(document.getElementById('myInput'), {
 *     mode: 'date' | 'datetime',
 *     min: Date | null,
 *     max: Date | null,
 *     format: 'M j, Y',            // display format, PHP date() tokens (see formatDate); the hidden input
 *                                  // always carries 'Y-m-d' (date) or 'Y-m-dTH:i' (datetime)
 *     onChange: (date) => {}
 *   });
 *   picker.setValue('2026-10-06' | '2026-10-06T14:30' | Date | '');   // set from code, no onChange
 */
window.app.DatePicker = (function() {
    const DOW = [ 'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    const MONTHS = [
	    'January', 'February', 'March', 'April', 'May', 'June',
	    'July', 'August', 'September', 'October', 'November', 'December'
	];

    class DatePicker {

	constructor(input, options = {}) {
    	    if (typeof input === 'string')
    		input = document.querySelector(input);
    	    if (input._datePicker)
    		return input._datePicker;


	    this.input = input;
	    this.mode = options.mode || 'date'; // 'date' | 'datetime'
	    this.min = options.min || null;
	    this.max = options.max || null;
	    this.onChange = options.onChange || null;
	    // Display format using PHP date()-style tokens — see DatePicker.formatDate()
	    // for the full token list. Defaults to a sensible format per mode.
	    this.format = options.format || (this.mode === 'datetime' ? 'M j, Y H:i' : 'M j, Y');

	    this.value = null;          // currently selected Date (or null)
	    this.viewDate = new Date(); // month/year currently displayed
	    this.viewMode = 'days';     // 'days' | 'months' | 'years'
	    this.hour = 12;
	    this.minute = 0;

	    this._buildDom();
	    this._bindEvents();
	    
	    this.input._datePicker = this;
	}

	_buildDom() {
	    // Hidden input carries the real value for form submission
	    this.hiddenInput = document.createElement('input');
	    this.hiddenInput.type = 'hidden';
	    //this.hiddenInput.name = this.input.dataset.name || this.input.id + '_value';
	    this.hiddenInput.name = this.input.name;
	    this.input.name = '';
	    this.input.insertAdjacentElement('afterend', this.hiddenInput);

	    this.popup = document.createElement('div');
	    this.popup.className = 'dp-popup card p-2';
	    //CLD: 2026-10-05T22:37:58+03:00: kept next to the input while closed; while open it is moved out of any clipping container (see open)
	    this.input.parentElement.appendChild(this.popup);
	    this._onReposition = () => this._place();

	    this._render();
	}

	_bindEvents() {
	    // Prevent clicks inside the popup (nav buttons, day cells, etc.) from
	    // bubbling to the document listener below — otherwise re-rendering the
	    // popup mid-click detaches the clicked element and the outside-click
	    // check fires immediately, closing the popup right after navigating.
	    this.popup.addEventListener('click', (e) => {
		e.stopPropagation();
		//CLD: 2026-10-05T22:37:58+03:00: navigating changes the popup height: keep it placed
		if (this.popup.classList.contains('show'))
		    this._place();
	    });

	    this.input.addEventListener('click', () => this.open());
	    this.input.addEventListener('keydown', (e) => {
    		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.open(); }
    		if (e.key === 'Escape') this.close();
	    });

	    document.addEventListener('click', (e) => {
    		if (!this.popup.contains(e.target) && e.target !== this.input) {
    		    this.close();
    		}
	    });
	}

	//CLD: 2026-10-05T22:37:58+03:00: the popup was absolutely positioned inside the input's parent, so scrollable/overflow containers
	//(e.g. a scrollable modal body) clipped it. While open it now lives in the closest .modal (stays inside Bootstrap's
	//focus trap, so the time selects keep working) or in <body>, positioned `fixed` next to the input
	open() {
	    this.viewDate = this.value ? new Date(this.value) : new Date();
	    this.viewMode = 'days';
	    this._render();

	    const host = this.input.closest('.modal') || document.body;
	    if (this.popup.parentElement !== host)
		host.appendChild(this.popup);
	    //CLD: 2026-10-05T22:43:18+03:00: `position: fixed` now comes from the theme (.dp-popup, _date_picker.scss); inline style removed

	    this.popup.classList.add('show');
	    this._place();

	    //follow scrolling (any scroll container: capture) and resizing while open
	    window.addEventListener('scroll', this._onReposition, true);
	    window.addEventListener('resize', this._onReposition);
	}

	close() {
	    if (!this.popup.classList.contains('show'))
		return;

	    this.popup.classList.remove('show');
	    window.removeEventListener('scroll', this._onReposition, true);
	    window.removeEventListener('resize', this._onReposition);

	    //back next to the input, so it goes away with it
	    if (this.input.isConnected && this.input.parentElement)
		this.input.parentElement.appendChild(this.popup);
	}

	//CLD: 2026-10-05T22:37:58+03:00: place the open popup under the input, or above it when there is no room below; kept inside the viewport
	_place() {
	    const margin = 4;
	    const r = this.input.getBoundingClientRect();
	    const pw = this.popup.offsetWidth;
	    const ph = this.popup.offsetHeight;

	    let top = r.bottom + 2;
	    if (top + ph > window.innerHeight - margin && r.top - ph - 2 >= margin)
		top = r.top - ph - 2;

	    let left = Math.min(r.left, window.innerWidth - pw - margin);
	    left = Math.max(left, margin);

	    this.popup.style.top = top + 'px';
	    this.popup.style.left = left + 'px';
	}

	_isSameDay(a, b) {
	    return a && b &&
    		a.getFullYear() === b.getFullYear() &&
    		a.getMonth() === b.getMonth() &&
    		a.getDate() === b.getDate();
	}

	_isDisabled(date) {
	    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
	    if (this.min) {
    		const min = new Date(this.min.getFullYear(), this.min.getMonth(), this.min.getDate());
    		if (d < min)
    		    return true;
	    }
	    if (this.max) {
    		const max = new Date(this.max.getFullYear(), this.max.getMonth(), this.max.getDate());
    		if (d > max)
    		    return true;
	    }
	    return false;
	}

	_pad(n) {
	    return String(n).padStart(2, '0');
	}

	_formatDisplay(date) {
	    const d = new Date(date);
	    if (this.mode === 'datetime')
		d.setHours(this.hour, this.minute, 0, 0);
	    return DatePicker.formatDate(d, this.format);
	}

	/**
	 * Format a Date using PHP date()-style single-character tokens.
	 * Any character can be escaped with a backslash to output it literally
	 * (as in PHP), e.g. 'Y-m-d\\TH:i:s' → "2026-09-12T14:30:00".
	 *
	 * Supported tokens (subset of PHP's date(), the ones meaningful for a
	 * date/time picker — no timezone/epoch tokens since this is local-only):
	 *
	 *   d  Day, 2 digits, leading zeros      (01–31)
	 *   j  Day, no leading zeros             (1–31)
	 *   D  Day name, 3 letters               (Mon)
	 *   l  Day name, full                    (Monday)   ["l" = lowercase L]
	 *   N  ISO-8601 day of week              (1 = Mon ... 7 = Sun)
	 *   w  Day of week                       (0 = Sun ... 6 = Sat)
	 *   S  Ordinal suffix for the day        (st, nd, rd, th)
	 *   z  Day of the year                   (0–365)
	 *   F  Month name, full                  (January)
	 *   M  Month name, 3 letters             (Jan)
	 *   m  Month, 2 digits, leading zeros    (01–12)
	 *   n  Month, no leading zeros           (1–12)
	 *   t  Days in the month                 (28–31)
	 *   Y  Year, 4 digits                    (2026)
	 *   y  Year, 2 digits                    (26)
	 *   L  Leap year                         (1 or 0)
	 *   a  am/pm, lowercase
	 *   A  AM/PM, uppercase
	 *   g  Hour, 12-hour, no leading zeros   (1–12)
	 *   G  Hour, 24-hour, no leading zeros   (0–23)
	 *   h  Hour, 12-hour, leading zeros      (01–12)
	 *   H  Hour, 24-hour, leading zeros      (00–23)
	 *   i  Minutes, leading zeros            (00–59)
	 *   s  Seconds, leading zeros            (00–59)
	 *
	 * Example: DatePicker.formatDate(new Date(), 'd/m/Y H:i')
	 */
	static formatDate(date, format) {
	    const pad = (n) => String(n).padStart(2, '0');
	    const dow = date.getDay();               // 0 (Sun) – 6 (Sat)
	    const dom = date.getDate();
	    const month = date.getMonth();
	    const year = date.getFullYear();
	    const h24 = date.getHours();
	    const h12raw = h24 % 12;
	    const h12 = h12raw === 0 ? 12 : h12raw;

	    const isLeap = (y) => (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
	    const daysInMonth = new Date(year, month + 1, 0).getDate();
	    const startOfYear = new Date(year, 0, 1);
	    const dayOfYear = Math.floor((date - startOfYear) / 86400000);

	    const ordinalSuffix = (n) => {
    		const v = n % 100;
    		if (v >= 11 && v <= 13)
    		    return 'th';
    		switch (n % 10) {
    		    case 1: return 'st';
    		    case 2: return 'nd';
    		    case 3: return 'rd';
    		    default: return 'th';
    		}
	    };

	    const tokenMap = {
		d: () => pad(dom),
		j: () => String(dom),
		D: () => DOW[dow],
		l: () => ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][dow],
		N: () => String(dow === 0 ? 7 : dow),
		w: () => String(dow),
		S: () => ordinalSuffix(dom),
		z: () => String(dayOfYear),
		F: () => MONTHS[month],
		M: () => MONTHS[month].slice(0, 3),
		m: () => pad(month + 1),
		n: () => String(month + 1),
		t: () => String(daysInMonth),
		Y: () => String(year),
		y: () => String(year).slice(-2),
		L: () => (isLeap(year) ? '1' : '0'),
		a: () => (h24 < 12 ? 'am' : 'pm'),
		A: () => (h24 < 12 ? 'AM' : 'PM'),
		g: () => String(h12),
		G: () => String(h24),
		h: () => pad(h12),
		H: () => pad(h24),
		i: () => pad(date.getMinutes()),
		s: () => pad(date.getSeconds()),
	    };

	    let result = '';
	    for (let idx = 0; idx < format.length; idx++) {
    		const ch = format[idx];
    		if (ch === '\\') {
    		    // PHP escape: output the following character literally.
    		    idx++;
    		    result += idx < format.length ? format[idx] : '';
    		} else if (tokenMap[ch]) {
    		    result += tokenMap[ch]();
    		} else {
    		    result += ch;
    		}
	    }
	    return result;
	}

	_isoValue(date) {
	    const d = new Date(date);
	    if (this.mode === 'datetime')
		d.setHours(this.hour, this.minute, 0, 0);
	    else
		d.setHours(0, 0, 0, 0);
	    // Local ISO-like string (no timezone shift)
	    const y = d.getFullYear(), m = this._pad(d.getMonth()+1), day = this._pad(d.getDate());
	    if (this.mode === 'datetime') {
    		return `${y}-${m}-${day}T${this._pad(this.hour)}:${this._pad(this.minute)}`;
	    }
	    return `${y}-${m}-${day}`;
	}

	//CLD: 2026-10-05T22:32:08+03:00: set the value from code (no onChange): a Date, a local 'Y-m-d' / 'Y-m-dTH:i' string
	//(the format of the hidden input), or empty to clear
	setValue(value) {
	    let d = null;
	    if (value instanceof Date) {
		d = new Date(value);
	    } else if (typeof value === 'string' && value !== '') {
		const m = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
		if (m)
		    d = new Date(+m[1], +m[2] - 1, +m[3], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0);
	    }

	    if (!d || isNaN(d.getTime())) {
		this.value = null;
		this.input.value = '';
		this.hiddenInput.value = '';
		return;
	    }

	    this.value = d;
	    this.viewDate = new Date(d);
	    this.hour = d.getHours();
	    this.minute = d.getMinutes();
	    this.input.value = this._formatDisplay(d);
	    this.hiddenInput.value = this._isoValue(d);
	    this._render();
	}

	_select(date) {
	    this.value = new Date(date);
	    this.input.value = this._formatDisplay(this.value);
	    this.hiddenInput.value = this._isoValue(this.value);
	    if (this.onChange)
		this.onChange(new Date(this.value));
	    this._render();
	}

	_render() {
	    this.popup.innerHTML = '';
	    if (this.viewMode === 'days')
		this._renderDays();
	    else if (this.viewMode === 'months')
		this._renderMonths();
	    else this._renderYears();

	    if (this.mode === 'datetime')
		this._renderTime();
	    this._renderFooter();
	}

	_renderDays() {
	    const header = document.createElement('div');
	    header.className = 'dp-header card-header d-flex align-items-center mb-2 p-0 border-0';

	    const prev = document.createElement('button');
	    prev.type = 'button';
	    prev.className = 'btn btn-sm btn-light';
	    prev.innerHTML = '<i class="bi bi-chevron-left"></i>';
	    prev.addEventListener('click', () => {
    		this.viewDate.setMonth(this.viewDate.getMonth() - 1);
    		this._render();
	    });

	    const title = document.createElement('button');
	    title.type = 'button';
	    title.className = 'dp-title btn btn-sm btn-light';
	    title.textContent = `${MONTHS[this.viewDate.getMonth()]} ${this.viewDate.getFullYear()}`;
	    title.addEventListener('click', () => { this.viewMode = 'months'; this._render(); });

	    const next = document.createElement('button');
	    next.type = 'button';
	    next.className = 'btn btn-sm btn-light';
	    next.innerHTML = '<i class="bi bi-chevron-right"></i>';
	    next.addEventListener('click', () => {
    		this.viewDate.setMonth(this.viewDate.getMonth() + 1);
    		this._render();
	    });

	    header.append(prev, title, next);
	    this.popup.appendChild(header);

	    const grid = document.createElement('div');
	    grid.className = 'dp-grid';

	    DOW.forEach(d => {
    		const el = document.createElement('div');
    		el.className = 'dp-dow';
    		el.textContent = d;
    		grid.appendChild(el);
	    });

	    const year = this.viewDate.getFullYear();
	    const month = this.viewDate.getMonth();
	    const firstOfMonth = new Date(year, month, 1);
	    const startOffset = firstOfMonth.getDay();
	    const gridStart = new Date(year, month, 1 - startOffset);
	    const today = new Date();

	    for (let i = 0; i < 42; i++) {
    		const cellDate = new Date(gridStart);
    		cellDate.setDate(gridStart.getDate() + i);

    		const btn = document.createElement('button');
    		btn.type = 'button';
    		btn.className = 'dp-day btn btn-sm btn-light';
    		btn.textContent = cellDate.getDate();

    		if (cellDate.getMonth() !== month) {
    		    //btn.classList.add('is-muted-x text-muted');
    		    btn.classList.add('text-body-tertiary');
    		}
    		if (this._isSameDay(cellDate, today)) {
    		    btn.classList.add('is-today');
    		}
    		if (this.value && this._isSameDay(cellDate, this.value)) {
    		    btn.classList.add('active');
    		}

    		const disabled = this._isDisabled(cellDate);
    		if (disabled)
    		    btn.disabled = true;
    		else
    		    btn.addEventListener('click', () => this._select(cellDate));

    		grid.appendChild(btn);
	    }

	    this.popup.appendChild(grid);
	}

	_renderMonths() {
	    const header = document.createElement('div');
	    header.className = 'dp-header card-header d-flex align-items-center gap-2 mb-2 p-0 bg-white border-0';

	    const prev = document.createElement('button');
	    prev.type = 'button';
	    prev.className = 'btn btn-sm btn-light';
	    prev.innerHTML = '<i class="bi bi-chevron-left"></i>';
	    prev.addEventListener('click', () => {
    		this.viewDate.setFullYear(this.viewDate.getFullYear() - 1);
    		this._render();
	    });

	    const title = document.createElement('button');
	    title.type = 'button';
	    title.className = 'dp-title btn btn-sm btn-light';
	    title.textContent = this.viewDate.getFullYear();
	    title.addEventListener('click', () => { this.viewMode = 'years'; this._render(); });

	    const next = document.createElement('button');
	    next.type = 'button';
	    next.className = 'btn btn-sm btn-light';
	    next.innerHTML = '<i class="bi bi-chevron-right"></i>';
	    next.addEventListener('click', () => {
    		this.viewDate.setFullYear(this.viewDate.getFullYear() + 1);
    		this._render();
	    });

	    header.append(prev, title, next);
	    this.popup.appendChild(header);

	    const grid = document.createElement('div');
	    grid.className = 'dp-months';

	    MONTHS.forEach((m, idx) => {
    		const btn = document.createElement('button');
    		btn.type = 'button';
    		btn.textContent = m.slice(0, 3);
    		btn.className = 'btn btn-sm btn-light';
    		if (idx === this.viewDate.getMonth()) {
    		    //btn.classList.add('is-selected');
    		    btn.classList.add('active');
    		}
    		btn.addEventListener('click', () => {
    		    this.viewDate.setMonth(idx);
    		    this.viewMode = 'days';
    		    this._render();
    		});
    		grid.appendChild(btn);
	    });

	    this.popup.appendChild(grid);
	}

	_renderYears() {
	    const startYear = Math.floor(this.viewDate.getFullYear() / 12) * 12;

	    const header = document.createElement('div');
	    header.className = 'dp-header card-header d-flex align-items-center gap-2 mb-2 p-0 bg-white border-0';

	    const prev = document.createElement('button');
	    prev.type = 'button';
	    prev.className = 'btn btn-sm btn-light';
	    prev.innerHTML = '<i class="bi bi-chevron-left"></i>';
	    prev.addEventListener('click', () => {
    		this.viewDate.setFullYear(this.viewDate.getFullYear() - 12);
    		this._render();
	    });

	    const title = document.createElement('span');
	    title.className = 'dp-title btn btn-sm btn-light';
	    title.textContent = `${startYear} – ${startYear + 11}`;

	    const next = document.createElement('button');
	    next.type = 'button';
	    next.className = 'btn btn-sm btn-light';
	    next.innerHTML = '<i class="bi bi-chevron-right"></i>';
	    next.addEventListener('click', () => {
    		this.viewDate.setFullYear(this.viewDate.getFullYear() + 12);
    		this._render();
	    });

	    header.append(prev, title, next);
	    this.popup.appendChild(header);

	    const grid = document.createElement('div');
	    grid.className = 'dp-years';

	    for (let i = 0; i < 12; i++) {
    		const y = startYear + i;
    		const btn = document.createElement('button');
    		btn.type = 'button';
    		btn.className = 'btn btn-sm btn-light';
    		btn.textContent = y;
    		if (y === this.viewDate.getFullYear()) {
    		    //btn.classList.add('is-selected');
    		    btn.classList.add('active');
    		}
    		btn.addEventListener('click', () => {
    		    this.viewDate.setFullYear(y);
    		    this.viewMode = 'months';
    		    this._render();
    		});
    		grid.appendChild(btn);
	    }

	    this.popup.appendChild(grid);
	}

	_renderTime() {
	    const wrap = document.createElement('div');
	    wrap.className = 'dp-time d-flex align-items-center justify-content-center gap-2 mt-2 pt-2';

	    const hourSel = document.createElement('select');
	    hourSel.className = 'form-select form-select-sm';
	    for (let h = 0; h < 24; h++) {
    		const opt = document.createElement('option');
    		opt.value = h;
    		opt.textContent = this._pad(h);
    		if (h === this.hour)
    		    opt.selected = true;
    		hourSel.appendChild(opt);
	    }
	    hourSel.addEventListener('change', () => {
    		this.hour = parseInt(hourSel.value, 10);
    		if (this.value)
    		    this._select(this.value);
	    });

	    const colon = document.createElement('span');
	    colon.className = 'dp-colon';
	    colon.textContent = ':';

	    const minSel = document.createElement('select');
	    minSel.className = 'form-select form-select-sm';
	    [0, 15, 30, 45].forEach(m => {
    		const opt = document.createElement('option');
    		opt.value = m;
    		opt.textContent = this._pad(m);
    		if (m === this.minute)
    		    opt.selected = true;
    		minSel.appendChild(opt);
	    });
	    minSel.addEventListener('change', () => {
    		this.minute = parseInt(minSel.value, 10);
    		if (this.value)
    		    this._select(this.value);
	    });

	    wrap.append(hourSel, colon, minSel);
	    this.popup.appendChild(wrap);
	}

	_renderFooter() {
	    const footer = document.createElement('div');
	    footer.className = 'card-footer d-flex justify-content-between align-items-center mt-2';

	    const todayBtn = document.createElement('button');
	    todayBtn.type = 'button';
	    todayBtn.className = 'btn btn-sm btn-outline-info';
	    todayBtn.textContent = 'Today';
	    todayBtn.addEventListener('click', () => {
    		const now = new Date();
    		if (this.mode === 'datetime') {
    		    this.hour = now.getHours();
    		    this.minute = Math.round(now.getMinutes() / 15) * 15 % 60;
    		}
    		if (!this._isDisabled(now)) {
    		    this.viewDate = now;
    		    this._select(now);
    		}
	    });

	    const clearBtn = document.createElement('button');
	    clearBtn.type = 'button';
	    clearBtn.textContent = 'Clear';
	    clearBtn.className = 'btn btn-sm btn-link text-info';
	    clearBtn.addEventListener('click', () => {
    		this.value = null;
    		this.input.value = '';
    		this.hiddenInput.value = '';
    		if (this.onChange)
    		    this.onChange(null);
    		this.close();
	    });

	    const okBtn = document.createElement('button');
	    okBtn.type = 'button';
	    okBtn.className = 'dp-ok-btn btn btn-sm btn-primary';
	    okBtn.textContent = 'OK';
	    okBtn.addEventListener('click', () => this.close());

	    footer.append(todayBtn, clearBtn, okBtn);
	    this.popup.appendChild(footer);
	}
    }

    return DatePicker;
})();

/*!
 * SelectPlus — a small select2-style enhancer for Bootstrap 5. No dependencies.
 *
 *   new SelectPlus(selectEl, options)
 *   SelectPlus.init('.js-select', options)   // many at once, returns instances
 *
 * The native <select> stays in the DOM (hidden) and is always kept in sync,
 * so normal form submission, FormData, and `change` listeners keep working.
 */
window.app.SelectPlus = (function() {

    const DEFAULTS = {
	placeholder: null,          // falls back to data-placeholder or the text of an <option value="">
	allowClear: false,          // show an × that clears the value
	closeOnSelect: null,        // default: true for single, false for multiple
	searchPlaceholder: 'Search…',
	minimumInputLength: 0,
	ajax: null,                 // see AJAX_DEFAULTS
	create: false,              // true, or async (term, instance) => ({ id, text }) | null
	createLabel: (term) => `Add "${term}"`,
	templateResult: null,       // (item) => HTML string, for dropdown rows (you escape it)
	templateSelection: null,    // (item) => HTML string, for the selected value / tags
	texts: {
    	    noResults: 'No results found',
    	    loading: 'Loading…',
    	    loadingMore: 'Loading more…',
    	    error: 'Could not load results. Try again.',
    	    creating: 'Adding…',
    	    createError: 'Could not add this item. Try again.',
    	    inputTooShort: (n) => `Type ${n} more character${n === 1 ? '' : 's'}`,
	},
    };

    const AJAX_DEFAULTS = {
	url: null,                  // string, or (term, page) => string
	method: 'GET',              // GET sends params as query string, anything else as JSON body
	headers: {},
	delay: 250,                 // debounce in ms
	data: (term, page) => ({ q: term, page }),
	// Must return { results: [{ id, text, ...anything }], more: boolean }
	processResults: (json) => ({
    	    results: Array.isArray(json) ? json : (json.results || []),
    	    more: !!(json.pagination && json.pagination.more),
	}),
	transport: null,            // optional: async ({ term, page }, abortSignal) => json — replaces fetch entirely
    };

    let uid = 0;
    const norm = (s) => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const toItem = (r) => ({
	...r,
	id: String(r.id ?? r.value ?? r.text),
	text: String(r.text ?? r.label ?? r.name ?? r.id),
    });

    class SelectPlus {
	constructor(select, options = {}) {
    	    if (typeof select === 'string')
    		select = document.querySelector(select);
    	    if (!select || select.tagName !== 'SELECT')
    		throw new Error('SelectPlus: a <select> element is required');
    	    if (select._selectPlus)
    		return select._selectPlus;

    	    this.select = select;
    	    this.multiple = select.multiple;
    	    this.id = 'sp-' + (++uid);

    	    // data-* attributes, overridden by JS options
    	    const ds = select.dataset, fromData = {};
    	    if (ds.placeholder)
    		fromData.placeholder = ds.placeholder;
    	    if (ds.allowClear)
    		fromData.allowClear = ds.allowClear === 'true';
    	    if (ds.create)
    		fromData.create = ds.create === 'true';
    	    if (ds.minimumInputLength)
    		fromData.minimumInputLength = +ds.minimumInputLength;

    	    this.o = { ...DEFAULTS, ...fromData, ...options, texts: { ...DEFAULTS.texts, ...(options.texts || {}) } };
    	    const ajax = options.ajax || (ds.ajaxUrl ? { url: ds.ajaxUrl } : null);
    	    this.o.ajax = ajax ? { ...AJAX_DEFAULTS, ...ajax } : null;
    	    if (this.o.closeOnSelect == null)
    		this.o.closeOnSelect = !this.multiple;
    	    if (this.o.placeholder == null) {
    		const empty = [...select.options].find((o) => o.value === '');
    		this.o.placeholder = empty ? empty.textContent : '';
    	    }

    	    this.items = [];
    	    this.rows = [];
    	    this.active = -1;
    	    this.term = '';
    	    this.page = 1;
    	    this.more = false;
    	    this.loading = false;
    	    this.isOpen = false;

    	    this._build();
    	    this._bind();
    	    this._syncState();
	    this.refresh();
    	    select._selectPlus = this;
	}

	/* ---------- public API ---------- */
/*
	static init(target, options) {
    	    const els = typeof target === 'string' ? document.querySelectorAll(target) : target instanceof Element ? [target] : target;
    	    return [...els].map((s) => new SelectPlus(s, options));
	}
*/
	getValue() {
    	    return this.multiple ? [...this.select.selectedOptions].map((o) => o.value) : this.select.value;
	}

	/** Programmatically select an item ({id, text}) — adds the <option> if missing. */
	setItem(item) {
	    this._selectItem(toItem(item));
	}

	clear() {
    	    [...this.select.options].forEach((o) => { o.selected = false; });
    	    const empty = [...this.select.options].find((o) => o.value === '');
    	    if (empty && !this.multiple)
    		empty.selected = true;
    	    else
    		this.select.selectedIndex = -1;
    	    this._changed();
	}

	/** Re-render the visible value from the native select (call after changing it in code). */
	refresh() {
	    this._renderSelection();
        }

	open() {
    	    if (this.isOpen || this.select.disabled)
    		return;
    	    this.isOpen = true;
    	    this.root.classList.add('open');
    	    this.menu.classList.add('show');
    	    this.box.setAttribute('aria-expanded', 'true');
    	    this.search.value = '';
    	    this._query('');
    	    this._place();
    	    this.search.focus();
    	    document.addEventListener('mousedown', this._onOutside);
	}

	close(focusBox = false) {
    	    if (!this.isOpen)
    		return;
    	    this.isOpen = false;
    	    clearTimeout(this._timer);
    	    if (this._abort)
    		this._abort.abort();
    	    this.root.classList.remove('open');
    	    this.menu.classList.remove('show', 'sp-up');
    	    this.box.setAttribute('aria-expanded', 'false');
    	    this.search.removeAttribute('aria-activedescendant');
    	    document.removeEventListener('mousedown', this._onOutside);
    	    if (focusBox)
    		this.box.focus();
	}

	destroy() {
    	    this.close();
    	    this._observer.disconnect();
    	    this.select.removeEventListener('change', this._onNativeChange);
    	    if (this._form)
    		this._form.removeEventListener('reset', this._onReset);
    	    this._labels.forEach((l) => l.removeEventListener('click', this._onLabel));
    	    this.root.remove();
    	    this.select.classList.remove('visually-hidden');
    	    this.select.removeAttribute('tabindex');
    	    this.select.removeAttribute('aria-hidden');
    	    delete this.select._selectPlus;
	}

	/* ---------- building & events ---------- */
	_build() {
    	    const s = this.select;
    	    this.root = app.utils.el('div', 'sp' + (this.multiple ? ' sp-multiple' : ''));
    	    this.box = app.utils.el('div', 'form-select sp-selection', {
    		tabindex: '0', role: 'combobox', 'aria-haspopup': 'listbox',
    		'aria-expanded': 'false', 'aria-controls': this.id + '-list',
    	    });
    	    if (s.classList.contains('form-select-sm'))
    		this.box.classList.add('form-select-sm');
    	    if (s.classList.contains('form-select-lg'))
    		this.box.classList.add('form-select-lg');
    	    const label = s.id && document.querySelector(`label[for="${CSS.escape(s.id)}"]`);
    	    if (label) {
    		label.id ||= this.id + '-label';
    		this.box.setAttribute('aria-labelledby', label.id);
    	    }

    	    this.menu = app.utils.el('div', 'dropdown-menu sp-dropdown');
    	    const sw = app.utils.el('div', 'p-2 border-bottom');
    	    this.search = app.utils.el('input', 'form-control form-control-sm', {
    		type: 'search', autocomplete: 'off', spellcheck: 'false',
    		placeholder: this.o.searchPlaceholder, 'aria-autocomplete': 'list',
    		    'aria-controls': this.id + '-list',
    	    });
    	    sw.appendChild(this.search);
    	    this.list = app.utils.el('ul', 'sp-results', { role: 'listbox', id: this.id + '-list' });
    	    if (this.multiple)
    		this.list.setAttribute('aria-multiselectable', 'true');
    	    this.menu.append(sw, this.list);
    	    this.root.append(this.box, this.menu);

    	    s.classList.add('visually-hidden');
    	    s.tabIndex = -1;
    	    s.setAttribute('aria-hidden', 'true');
    	    s.after(this.root);
	}

	_bind() {
    	    this.box.addEventListener('click', () => (this.isOpen ? this.close(true) : this.open()));
    	    this.box.addEventListener('keydown', (e) => {
    		if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(e.key)) {
        	    e.preventDefault(); this.open();
    		} else if (e.key === 'Backspace' && this.multiple) {
        	    this._removeLast();
    		} else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        	    e.preventDefault();          // type-to-search: open and carry the key over
        	    this.open();
        	    this.search.value = e.key;
        	    this._query(e.key);
    		}
    	    });

    	    this.search.addEventListener('input', () => this._query(this.search.value));
    	    this.search.addEventListener('keydown', (e) => {
    		switch (e.key) {
        	    case 'ArrowDown':
        		e.preventDefault();
        		this._move(1);
        		break;
        	    case 'ArrowUp':
        		e.preventDefault();
        		this._move(-1);
        		break;
        	    case 'Enter':
        		e.preventDefault();
        		this._choose(this.active);
        		break;
        	    case 'Escape':
        		e.preventDefault();
        		this.close(true);
        		break;
        	    case 'Tab':
        		this.close();
        		break;
        	    case 'Backspace':
        		if (this.multiple && !this.search.value)
        		    this._removeLast();
        		break;
    		}
    	    });

    	    // Keep focus in the search input while clicking rows
    	    this.list.addEventListener('mousedown', (e) => e.preventDefault());
    	    this.list.addEventListener('click', (e) => {
    		const node = e.target.closest('[data-index]');
    		if (node)
    		    this._choose(+node.dataset.index);
    	    });
    	    this.list.addEventListener('mousemove', (e) => {
    		const node = e.target.closest('[data-index]');
    		if (node && +node.dataset.index !== this.active)
    		    this._highlight(+node.dataset.index, false);
    	    });
    	    this.list.addEventListener('scroll', () => {
    		const l = this.list;
    		if (this.o.ajax && this.more && !this.loading && l.scrollTop + l.clientHeight >= l.scrollHeight - 40) {
        	    this._fetch(this.term, this.page + 1);
    		}
    	    });

    	    this._onOutside = (e) => { if (!this.root.contains(e.target)) this.close(); };
    	    this._onNativeChange = () => { if (!this._silent) this._renderSelection(); };
    	    this.select.addEventListener('change', this._onNativeChange);

    	    this._form = this.select.form;
    	    this._onReset = () => setTimeout(() => this._renderSelection());
    	    if (this._form)
    		this._form.addEventListener('reset', this._onReset);

    	    this._onLabel = (e) => { e.preventDefault(); this.box.focus(); };
    	    this._labels = this.select.id ? [...document.querySelectorAll(`label[for="${CSS.escape(this.select.id)}"]`)] : [];
    	    this._labels.forEach((l) => l.addEventListener('click', this._onLabel));

    	    // Mirror disabled / validation classes set on the native select
    	    this._observer = new MutationObserver(() => this._syncState());
    	    this._observer.observe(this.select, { attributes: true, attributeFilter: ['class', 'disabled'] });
	}

	_syncState() {
    	    const s = this.select;
    	    this.box.classList.toggle('is-invalid', s.classList.contains('is-invalid'));
    	    this.box.classList.toggle('is-valid', s.classList.contains('is-valid'));
    	    this.root.classList.toggle('sp-disabled', s.disabled);
    	    this.box.tabIndex = s.disabled ? -1 : 0;
    	    this.box.setAttribute('aria-disabled', String(s.disabled));
    	    if (s.disabled)
    		this.close();
	}

	_place() {
    	    this.menu.classList.remove('sp-up');
    	    const r = this.box.getBoundingClientRect();
    	    const h = this.menu.offsetHeight;
    	    if (window.innerHeight - r.bottom < h + 8 && r.top > h + 8)
    		this.menu.classList.add('sp-up');
	}

	/* ---------- selection display ---------- */
	_itemFromOption(opt) {
    	    return opt._spItem || { id: opt.value, text: opt.textContent, disabled: opt.disabled };
	}

	_fill(node, item, template) {
    	    if (template)
    		node.innerHTML = template(item);
    	    else
    		node.textContent = item.text;
	}

	_renderSelection() {
    	    const selected = [...this.select.selectedOptions].filter((o) => o.value !== '');
    	    this.box.replaceChildren();

    	    if (!selected.length) {
    		const ph = app.utils.el('span', 'sp-value sp-placeholder');
    		ph.textContent = this.o.placeholder || '\u00a0';
    		this.box.appendChild(ph);
    	    } else if (this.multiple) {
    		selected.forEach((opt) => {
        	    const item = this._itemFromOption(opt);
        	    const tag = app.utils.el('span', 'badge text-bg-light border sp-tag');
        	    const txt = app.utils.el('span');
        	    this._fill(txt, item, this.o.templateSelection);
        	    const x = app.utils.el('button', 'btn-close', { type: 'button', tabindex: '-1', 'aria-label': 'Remove ' + item.text });
        	    x.addEventListener('click', (e) => { e.stopPropagation(); this._deselect(opt.value); });
        	    tag.append(txt, x);
        	    this.box.appendChild(tag);
    		});
    	    } else {
    		const v = app.utils.el('span', 'sp-value');
    		this._fill(v, this._itemFromOption(selected[0]), this.o.templateSelection);
    		this.box.appendChild(v);
    	    }

    	    if (this.o.allowClear && selected.length && !this.select.disabled) {
    		const x = app.utils.el('button', 'btn-close sp-clear', { type: 'button', tabindex: '-1', 'aria-label': 'Clear' });
    		x.addEventListener('click', (e) => { e.stopPropagation(); this.clear(); this.close(); });
    		this.box.appendChild(x);
    	    }
	}

	/* ---------- searching ---------- */
	_query(term) {
    	    this.term = term;
    	    this.page = 1;
    	    this.more = false;
    	    clearTimeout(this._timer);
    	    const min = this.o.minimumInputLength;

    	    if (term.trim().length < min) {
    		if (this._abort)
    		    this._abort.abort();
    		this.items = [];
    		return this._renderResults({ message: this.o.texts.inputTooShort(min - term.trim().length) });
    	    }
    	    if (!this.o.ajax) {
    		this.items = this._localResults(term);
    		return this._renderResults();
    	    }
    	    this._renderResults({ message: this.o.texts.loading });
    	    this._timer = setTimeout(() => this._fetch(term, 1), term ? this.o.ajax.delay : 0);
	}

	_localResults(term) {
    	    const t = norm(term.trim());
    	    return [...this.select.options]
    		.filter((o) => o.value !== '')
    		.map((o) => this._itemFromOption(o))
    		.filter((i) => !t || norm(i.text).includes(t));
	}

	async _fetch(term, page) {
    	    if (this._abort)
    		this._abort.abort();
    	    const ctrl = (this._abort = new AbortController());
    	    this.loading = true;
    	    try {
    		const json = await this._request(term, page, ctrl.signal);
    		if (ctrl.signal.aborted)
    		    return;
    		const res = this.o.ajax.processResults(json, { term, page }) || {};
    		const items = (res.results || []).map(toItem);
    		this.items = page === 1 ? items : this.items.concat(items);
    		this.page = page;
    		this.more = !!res.more;
    		this._renderResults({ keepScroll: page > 1 });
    		if (page === 1)
    		    this._place();
    	    } catch (err) {
    		if (err.name === 'AbortError')
    		    return;
    		console.error('SelectPlus:', err);
    		this.more = false;
    		this._renderResults({ message: this.o.texts.error, keepScroll: page > 1 });
    	    } finally {
    		if (this._abort === ctrl) {
    		    this._abort = null;
    		    this.loading = false;
    		}
    	    }
	}

	async _request(term, page, signal) {
    	    const a = this.o.ajax;
    	    if (a.transport)
    		return a.transport({ term, page }, signal);

    	    const params = a.data(term, page) || {};
    	    let url = typeof a.url === 'function' ? a.url(term, page) : a.url;
    	    const init = {
    		method: a.method.toUpperCase(),
    		headers: { Accept: 'application/json', ...a.headers },
    		credentials: 'same-origin',
    		signal,
    	    };
    	    if (init.method === 'GET') {
    		const u = new URL(url, location.href);
    		for (const [k, v] of Object.entries(params)) if (v != null) u.searchParams.set(k, v);
    		url = u;
    	    } else {
    		init.headers['Content-Type'] = 'application/json';
    		init.body = JSON.stringify(params);
    	    }
    	    const res = await fetch(url, init);
    	    if (!res.ok)
    		throw new Error(`HTTP ${res.status}`);
    	    return res.json();
	}

	/* ---------- dropdown rows ---------- */
	_isSelected(id) {
    	    return [...this.select.selectedOptions].some((o) => o.value === id);
	}

	_renderResults(state = {}) {
    	    const prevActive = this.active;
    	    const scrollTop = this.list.scrollTop;
    	    this.list.replaceChildren();
    	    this.rows = [];

    	    const term = this.term.trim();
    	    const isMessageOnly = state.message && !state.keepScroll;

    	    if (!isMessageOnly) {
    		this.items.forEach((item) => {
        	    const sel = this._isSelected(item.id);
        	    const node = app.utils.el('li', 'dropdown-item' + (sel ? ' sp-selected' : '') + (item.disabled ? ' disabled' : ''), {
        		role: 'option', 'aria-selected': String(sel), id: `${this.id}-opt-${this.rows.length}`,
        		'data-index': this.rows.length,
        	    });
        	    this._fill(node, item, this.o.templateResult);
        	    //CLD: 2026-10-13T08:19:08+03:00: the tick of a selected item: a Bootstrap icon (was the ✓ character, \2713, of the .sp-selected::after CSS rule)
        	    if (sel)
        		node.appendChild(app.utils.el('i', 'bi bi-check-lg float-end ms-2 text-primary', { 'aria-hidden': 'true' }));
        	    this.list.appendChild(node);
        	    this.rows.push({ item, node });
    		});

    		const canCreate = this.o.create && term && term.length >= this.o.minimumInputLength &&
        	    !this.items.some((i) => norm(i.text) === norm(term));
    		if (canCreate) {
        	    const node = app.utils.el('li', 'dropdown-item sp-create', {
        		role: 'option', id: `${this.id}-opt-${this.rows.length}`, 'data-index': this.rows.length,
        	    });
        	    node.textContent = this.o.createLabel(term);
        	    this.list.appendChild(node);
        	    this.rows.push({ create: true, term, node });
    		}
    	    }

    	    const msg = state.message ||
    		(this.more ? this.o.texts.loadingMore : null) ||
    		(!this.rows.length ? this.o.texts.noResults : null);
    	    if (msg) {
    		const li = app.utils.el('li', 'sp-msg', { role: 'presentation' });
    		li.textContent = msg;
    		this.list.appendChild(li);
    	    }

    	    if (state.keepScroll || state.keepActive)
    		this.list.scrollTop = scrollTop;
    	    const start = state.keepActive && prevActive >= 0 && prevActive < this.rows.length
    		? prevActive
    		: this.rows.findIndex((r) => !(r.item && r.item.disabled));
    	    this._highlight(start, false);
	}

	_highlight(i, scroll = true) {
    	    this.rows.forEach((r, k) => r.node.classList.toggle('sp-hl', k === i));
    	    this.active = i;
    	    if (i >= 0 && this.rows[i]) {
    		this.search.setAttribute('aria-activedescendant', this.rows[i].node.id);
    		if (scroll)
    		    this.rows[i].node.scrollIntoView({ block: 'nearest' });
    	    } else {
    		this.search.removeAttribute('aria-activedescendant');
    	    }
	}

	_move(delta) {
    	    const n = this.rows.length;
    	    if (!n)
    		return;
    	    let i = this.active;
    	    for (let step = 0; step < n; step++) {
    		i = (i + delta + n) % n;
    		if (!(this.rows[i].item && this.rows[i].item.disabled))
    		    return this._highlight(i);
    	    }
	}

	_choose(i) {
    	    const row = this.rows[i];
    	    if (!row || (row.item && row.item.disabled))
    		return;
    	    if (row.create)
    		return this._create(row.term);
    	    if (this.multiple && this._isSelected(row.item.id))
    		this._deselect(row.item.id);
    	    else
    		this._selectItem(row.item);
	}

	/* ---------- value changes ---------- */
	_selectItem(item) {
    	    let opt = [...this.select.options].find((o) => o.value === item.id);
    	    if (!opt) {
    		opt = new Option(item.text, item.id);
    		this.select.add(opt);
    	    }
    	    opt._spItem = item;
    	    if (this.multiple)
    		opt.selected = true;
    	    else
    		this.select.value = item.id;
    	    this._changed();

    	    if (this.o.closeOnSelect)
    		this.close(true);
    	    else
    		if (this.isOpen)
    		    this._renderResults({ keepActive: true });
	}

	_deselect(id) {
    	    const opt = [...this.select.options].find((o) => o.value === id);
    	    if (!opt)
    		return;
    	    opt.selected = false;
    	    this._changed();
    	    if (this.isOpen)
    		this._renderResults({ keepActive: true });
	}

	_removeLast() {
    	    const sel = [...this.select.selectedOptions].filter((o) => o.value !== '');
    	    if (sel.length)
    		this._deselect(sel[sel.length - 1].value);
	}

	async _create(term) {
    	    let item = { id: term, text: term, isNew: true };
    	    if (typeof this.o.create === 'function') {
    		this._renderResults({ message: this.o.texts.creating });
    		try {
        	    const r = await this.o.create(term, this);
        	    if (!r)
        		return this._renderResults();       // callback declined
        	    item = { ...toItem(r), isNew: true };
    		} catch (err) {
        	    console.error('SelectPlus:', err);
        	    return this._renderResults({ message: this.o.texts.createError });
    		}
    	    }
    	    this._selectItem(item);
    	    this.select.dispatchEvent(new CustomEvent('selectplus:create', { bubbles: true, detail: item }));
    	    if (this.isOpen) {                                // multiple mode stays open: reset the search
    		this.search.value = '';
    		this._query('');
    	    }
	}

	_changed() {
    	    this._renderSelection();
    	    this._silent = true;
    	    this.select.dispatchEvent(new Event('input', { bubbles: true }));
    	    this.select.dispatchEvent(new Event('change', { bubbles: true }));
    	    this._silent = false;
	}
    }

    return SelectPlus;
})();



window.app.IconPicker = (function() {
    let icons = null;
    let iconUrl = "/js/bootstrap-icons.json";

    const loadIcons = async () => {
	if (icons)
	    return;
	try {
	    const response = await fetch(iconUrl);
	    const data = await response.json();
	    icons = Object.keys(data); // Adjust if needed
	} catch (error) {
	    console.error("Error loading icons:", error);
	    return;
	}
    };

    class IconPicker {
	constructor(el, options) {
    	    if (typeof el === 'string')
    		el = document.querySelector(el);
    	    if (el._iconPicker)
    		return el._iconPicker;

	    //switch to JQuery
	    el = $(el);
	    if (!el.length)
		return;

	    const container = el.find('.icons-container');

	    (async () => {
		if (!icons)
		    await loadIcons();
		container.html(
		    icons.map(function(icon) {
			const lcIcon = icon.toLowerCase();
			return `<button type="button" class="btn" data-icon="${lcIcon}"><i class="bi bi-${icon}"></i></button>`;
		    }).join('')
		);
	    })();

	    //install handlers
	    el.find('input.icon-search').on('input', function(e) {
		const filter = $(this).val().toLowerCase();
		container.find('button').each((idx, item) => {
		    item.style.display = item.getAttribute("data-icon").includes(filter) ? "" : "none";
		});
	    })

	    container.on('click', 'button[data-icon]', function(e) {
		const input = el.find('input[type=hidden]')
		//get the input value, explode by '::' then replace first element
		var parts = input.val().split('::');
		parts[0] = $(this).data('icon');
		input.val(parts.join('::'));
		el.find('.dropdown-toggle i').removeClass().addClass('text-' + parts[1] + ' bi bi-' + $(this).data('icon'));
	    });

	    el.get(0)._iconPicker = this;
	}
    }

    return IconPicker;
})();


/*!
 * fileUpload v1.0 — tiny dependency-free file uploader with dropzone.
 * Modern browsers only (ES2020). MIT License.
 *
 *   const up = new fileUpload({ url: '/upload', dropzone: '#drop' });
 *   up.on('add', item => ...).on('progress', item => ...);
 */
window.app.FileUpload = (function() {
    const DEFAULTS = {
	url: '',                 // string or (item) => string
	method: 'POST',
	fieldName: 'file',       // form field name for the file
	dropzone: null,          // element or selector
	input: null,             // <input type=file> element or selector (auto-created if omitted)
	accept: '',              // e.g. "image/*,.pdf,application/zip"
	maxFileSize: 0,          // bytes, 0 = no limit
	maxFiles: 0,             // 0 = no limit
	formData: {},            // extra fields: object or (item) => object
	timeout: 0,              // ms, 0 = none

	//events
	onDragEnter: (e) => {},
	onDragLeave: (e) => {},
	onDragOver: (e) => {},
	onDrop: (e) => {},
	onFileAdd: (item) => {},
	onFileUploadProgress: (item) => {},
	onFileUploadComplete: (item) => {},
	onFileUploadError: (item) => {},
	onUploadStart: () => {},
	onUploadProgress: (data) => {},
	onUploadComplete: () => {},
    };

    class FileUpload {
	constructor(input, options = {}) {
    	    if (typeof input === 'string')
    		input = document.querySelector(input);
    	    if (input._fileUpload)
    		return input._fileUpload;

    	    this.opts = { ...DEFAULTS, ...options };
    	    this.files = [];
    	    this._active = null;
    	    this._dragDepth = 0;

    	    this.dropzone = this.opts.dropzone;
    	    if (!input)
    		throw new Error('Input not provided');

	    this.input = input;

	    //bind to file-input
    	    if (this.opts.accept && !this.input.accept)
    		this.input.accept = this.opts.accept;

    	    this.input.addEventListener('change', () => {
    		const files = Array.from(this.input.files || []);
    		this.input.value = ''; // allow selecting the same file again
    		if (files.length)
    		    this.addFiles(files);
    	    });

    	    if (this.dropzone) {
    		const dz = this.dropzone;
    		dz.addEventListener('dragenter', (e) => {
    		    if (!this.hasFiles(e))
    			return;
    		    e.preventDefault();
    		    if (this._dragDepth++ === 0)
        		this.opts.onDragEnter(e);
    		});

    		dz.addEventListener('dragover', (e) => {
    		    if (!this.hasFiles(e))
    			return;
    		    e.preventDefault();
    		    e.dataTransfer.dropEffect = 'copy';
    		    this.opts.onDragOver(e);
    		});

    		dz.addEventListener('dragleave', (e) => {
    		    if (!this.hasFiles(e))
    			return;
    		    if (--this._dragDepth <= 0) {
        		this._dragDepth = 0;
        		this.opts.onDragLeave(e);
    		    }
    		});

    		dz.addEventListener('drop', (e) => {
    		    if (!this.hasFiles(e))
    			return;

    		    e.preventDefault();
    		    this._dragDepth = 0;
    		    const files = Array.from(e.dataTransfer.files || []);
    		    this.opts.onDrop(e);
    		    this.addFiles(files);
    		});
    	    }
    	    this.input._fileUpload = this;
	}

	/* ---------- utilities ---------- */
	hasFiles(e) {
	    return !!e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
	}

	/* ---------- public API ---------- */
	browse() {
    	    this.input.click();
    	    return this;
	}

	addFiles(fileList) {
    	    let files = Array.from(fileList || []);
    	    if (!this.input.multiple)
    		files = files.slice(0, 1);

	    const validate = (file) => {
    		const o = this.opts;
    		if (o.maxFileSize && file.size > o.maxFileSize)
    		    return { code: 'size', message: `File is larger than ${app.formatter.bytes(o.maxFileSize)}` };

    		if (o.maxFiles && this.files.length >= o.maxFiles)
    		    return { code: 'count', message: `You can add up to ${o.maxFiles} files` };

		//validate file accept
		if (this.input.accept) {
		    const name = file.name.toLowerCase();
		    const type = (file.type || '').toLowerCase();

		    const accepted = this.input.accept
    			.split(',')
    			.map(s => s.trim().toLowerCase())
    			.filter(Boolean)
    			.some(rule => {
    			    if (rule.startsWith('.'))
    				return name.endsWith(rule);
    			    if (rule.endsWith('/*'))
    				return type.startsWith(rule.slice(0, -1));
    			    return type === rule;
    			});
		    if (!accepted) {
    			return { code: 'type', message: 'File type not allowed' };
    		    }
		}
    		return null;
	    };

    	    for (const file of files) {
    		const item = {
    		    file,
    		    name: file.name,
    		    size: file.size,
    		    type: file.type,
    		    status: 'pending', // pending | uploading | done | error
    		    progress: 0,      // 0..100
    		    loaded: 0,
    		    response: null,
    		    error: null,
    		    xhr: null,
    		    data: {},         // free slot for your own stuff (DOM refs, etc.)
    		};

    		const error = validate(file);
    		if (error) {
    		    item.status = 'error';
    		    item.error = error;
    		    this.opts.onFileUploadError(item);
        	    continue;
    		}

    		this.files.push(item);
    		this.opts.onFileAdd(item);
    	    }

    	    if (this.files.length) {
    		this.opts.onUploadStart();
    		this.upload();
    	    }
	}

	// Upload one item, or every pending item when called without arguments.
	upload() {
    	    if (this._active)
    		return;

    	    const item = this.files.find(f => f.status === 'pending');
    	    if (item) {
    		this._send(item);
    		return;
    	    }
	
    	    if (!this.files.some(f => f.status === 'pending')) {
    		this.opts.onUploadComplete();
    		this.files = [];
    	    }
	}

	sendTotalProgress() {
	    if (this.files.length == 0)
		return;

    	    const counted = this.files.filter(f => ['pending', 'uploading', 'done'].includes(f.status));
	    const sizeTotal = counted.reduce((s, f) => s + f.size, 0);
	    const sizeLoaded = counted.reduce((s, f) => s + (f.status === 'done' ? f.size : f.loaded), 0);

    	    this.opts.onUploadProgress({
		nFiles: this.files.length,
		nLoaded: this.files.filter((f) => f.status == 'done').length,
		sizeTotal: sizeTotal,
		sizeLoaded: sizeLoaded,
		pctTotal: sizeTotal ? (sizeLoaded / sizeTotal) * 100 : 0,
		pctCurrent: this._active ? this._active.progress : 0,
    	    });
	}

	/* ---------- internals ---------- */
	_send(item) {
    	    const o = this.opts;

    	    const fd = new FormData();
    	    var _formData = o.formData;
    	    if (typeof _formData === 'function')
    		_formData = o.formData(item);

    	    for (const [k, v] of Object.entries(_formData))
    		fd.append(k, v);
    	    fd.append(o.fieldName, item.file, item.name);

    	    const xhr = new XMLHttpRequest();
    	    item.xhr = xhr;
    	    item.status = 'uploading';
    	    this._active = item;

	    var _url = o.url;
    	    if (typeof _url === 'function')
    		_url = o.url(item);

    	    xhr.open(o.method, _url, true);
    	    xhr.timeout = o.timeout || 0;

    	    xhr.upload.onprogress = e => {
    		if (!e.lengthComputable)
    		    return;
    		item.loaded = e.loaded * (item.size / e.total); // scale out multipart overhead
    		item.progress = (e.loaded / e.total) * 100;
    		o.onFileUploadProgress(item);
    		this.sendTotalProgress();
    	    };

    	    const finish = (item) => {
    		this._active = null;
    		item.xhr = null;
    		if (item.status === 'done') {
    		    o.onFileUploadComplete(item);
    		} else {
    		    o.onFileUploadError(item);
    		}
    		this.sendTotalProgress();
    		this.upload();
    	    };

    	    xhr.onload = () => {
    		try {
    		    item.response = JSON.parse(xhr.responseText);
		} catch {
    		    item.response = xhr.responseText;
		}

    		if (xhr.status >= 200 && xhr.status < 300) {
        	    item.status = 'done';
        	    item.progress = 100;
        	    item.loaded = item.size;
    		} else {
        	    item.status = 'error';
		    //CLD: 2026-10-06T21:33:37+03:00: show the server's message when it sends { error }
        	    const serverMessage = (item.response && typeof item.response === 'object' && item.response.error) ? item.response.error : null;
        	    item.error = { code: 'http', status: xhr.status, message: serverMessage || `Server responded with ${xhr.status}` };
    		}
    		finish(item);
    	    };
    	    xhr.onerror = () => {
    		item.status = 'error';
    		item.error = { code: 'network', message: 'Network error' };
    		finish(item);
    	    };
    	    xhr.ontimeout = () => {
    		item.status = 'error';
    		item.error = { code: 'timeout', message: 'Upload timed out' };
    		finish(item);
    	    };
    	    xhr.onabort = () => {
    		item.status = 'error';
    		item.error = { code: 'aborted', message: 'Upload aborted' };
    		finish(item);
    	    };

    	    xhr.send(fd);
	}
    }

    return FileUpload;
})();
