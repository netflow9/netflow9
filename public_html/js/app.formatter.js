if (!window.app)
    window.app = {};

//texts for the pages: dates (formatDate: PHP date() tokens, the account's formats), time ago, durations, numbers, sizes, AI tokens and costs, plain text as HTML.
//Shared lists and helpers: DAYS, MONTHS, pad (app.DatePicker uses them too)
window.app.formatter = (function() {
    //time ago: the words for each rule of timeAgo (normal / short: 1d, ~1h, <1m...)
    const AGO = {
	normal: {
	    lessThanAMinute: 'less than a minute ago',
	    oneMinute: '1 minute ago',
	    minutes: '{0} minutes ago',
	    aboutOneHour: 'about 1 hour ago',
	    hours: '{0} hours ago',
	    aboutOneDay: '1 day ago',
	    days: '{0} days ago',
	    aboutOneMonth: 'about 1 month ago',
	    months: '{0} months ago',
	    aboutOneYear: 'about 1 year ago',
	    years: 'over {0} years ago',
	},
	short: {
	    lessThanAMinute: '<1m',
	    oneMinute: '1m',
	    minutes: '{0}m',
	    aboutOneHour: '~1h',
	    hours: '{0}h',
	    aboutOneDay: '1d',
	    days: '{0}d',
	    aboutOneMonth: '~1M',
	    months: '{0}M',
	    aboutOneYear: '~y',
	    years: '>{0}y',
	},
    };

    const MINUTE = 60, HOUR = 3600, DAY = 24 * 3600;
    //time ago: [ below this many seconds, the words: a text or (diff, past, now) => [ text, number ] ], in order; beyond the last: years
    const AGO_RULES = [
	[ 30, 'lessThanAMinute' ],
	[ 90, 'oneMinute' ],
	//CLD: 2026-11-11T22:00:00+02:00: rounded, as util\Format::timeago: from 90 s it is 2 minutes (floor gave "1 minutes ago")
	[ 44 * MINUTE + 30, (diff) => [ 'minutes', Math.round(diff / MINUTE) ] ],
	[ HOUR + 29 * MINUTE + 59, 'aboutOneHour' ],
	[ 23 * HOUR + 59 * MINUTE + 29, (diff) => [ 'hours', Math.floor(diff / HOUR) ] ],
	[ 47 * HOUR + 59 * MINUTE + 29, 'aboutOneDay' ],
	[ 29 * DAY + 23 * HOUR + 59 * MINUTE + 29, (diff) => [ 'days', Math.floor(diff / DAY) ] ],
	[ 59 * DAY + 23 * HOUR + 59 * MINUTE + 29, 'aboutOneMonth' ],
	[ 363 * DAY, (diff, past, now) => [ 'months', Math.max((now.getFullYear() - past.getFullYear()) * 12 + now.getMonth() - past.getMonth(), 0) ] ],
	[ 363 * DAY + 365 * DAY, 'aboutOneYear' ],
    ];

    //text with {0}, {1}... filled from the arguments after it
    function fill(text, ...args) {
	return text.replace(/{(\d+)}/g, (match, i) => args[i] !== undefined ? args[i] : match);
    }

    //a number with 2 digits at least: 5 -> 05
    const pad = (n) => String(n).padStart(2, '0');

    //d (a Date, ISO 8601 text or ms) as a Date; empty or not a date -> null
    function toDate(d) {
	if (d === null || d === undefined || d === '')
	    return null;
	d = d instanceof Date ? d : new Date(d);
	return isNaN(d) ? null : d;
    }

    const DAYS = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
    const MONTHS = [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ];

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
     * Example: app.formatter.formatDate(new Date(), 'd/m/Y H:i')
     */
    function formatDate(date, format) {
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
	    D: () => DAYS[dow].slice(0, 3),
	    l: () => DAYS[dow],
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

    //d (Date / ISO 8601 text / ms) in a PHP date() format (formatDate); empty or not a date -> ''
    function formatAs(d, fmt) {
	d = toDate(d);
	return d ? formatDate(d, fmt) : '';
    }

    const compactFormat = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

    return {
	//as util\Format::bytes: 1536 -> "1.5 KB", 0 -> "0 B"; binary (1024) or decimal (1000) units, KB / MB / GB / TB / PB;
	//trailing zeros dropped, rounding up to the next unit (1023.96 KB -> "1 MB"); empty or not a number -> ''
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

	//a date / datetime in the account's format (<html> data-date-format / data-datetime-format: util\Format, PHP date() tokens,
	//formatDate), in the browser's time zone; d: a Date or ISO 8601 text; empty or not a date -> ''
	date: function(d) {
	    return formatAs(d, document.documentElement.dataset.dateFormat || 'Y-m-d');
	},

	datetime: function(d) {
	    return formatAs(d, document.documentElement.dataset.datetimeFormat || 'Y-m-d H:i:s');
	},

	DAYS: DAYS,
	MONTHS: MONTHS,
	pad: pad,

	//d (a Date) in a PHP date() format: formatDate above
	formatDate: formatDate,

	//a time / a day, short, in the browser's language: 14:05, 11 Nov (the Overview's chart axis, its Updated time); d: a Date, ISO 8601 text or ms; not a date -> ''
	shortTime: function(d) {
	    d = toDate(d);
	    return d ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
	},

	shortDate: function(d) {
	    d = toDate(d);
	    return d ? d.toLocaleDateString([], { day: 'numeric', month: 'short' }) : '';
	},

	//a number with the browser's thousands separators (1,234); signed: a + before a positive one; empty -> 0
	number: function(n, signed = false) {
	    n = Number(n || 0);
	    return (signed && n > 0 ? '+' : '') + n.toLocaleString();
	},

	//a number, compact: 1.2K, 3.4M
	compact: function(n) {
	    return compactFormat.format(Number(n || 0));
	},

	//a duration in ms, short: 850 ms, 4.2 s, 12 s, 3m 05s, 1h 05m; null / undefined -> empty
	duration: function(ms, empty = '') {
	    if (ms === null || ms === undefined)
		return empty;
	    if (ms < 1000)
		return Math.round(ms) + ' ms';
	    const s = ms / 1000;
	    if (s < 60)
		return (s < 10 ? s.toFixed(1) : Math.round(s)) + ' s';
	    const sec = Math.round(s);
	    if (sec < 3600)
		return Math.floor(sec / 60) + 'm ' + pad(sec % 60) + 's';
	    const min = Math.round(sec / 60);
	    return Math.floor(min / 60) + 'h ' + pad(min % 60) + 'm';
	},

	//the tokens of an AI call { input, output }: 1,200 + 340
	tokens: function(t) {
	    return this.number(t && t.input) + ' + ' + this.number(t && t.output);
	},

	//an AI cost { usd, credits }: $0.0042 under a cent, else $1.25; N credit(s); both when both; about: "about $1.25" (an estimate); none -> ''
	cost: function(cost, about = false) {
	    const parts = [];
	    if (cost && cost.usd !== null && cost.usd !== undefined)
		parts.push((about ? 'about $' : '$') + Number(cost.usd).toFixed(cost.usd > 0 && cost.usd < 0.01 ? 4 : 2));
	    if (cost && cost.credits !== null && cost.credits !== undefined)
		parts.push(cost.credits + ' credit(s)');
	    return parts.join(', ');
	},

	//plain text as HTML (escaped) with `code`, **bold** and line breaks (the designer's Assistant messages)
	richText: function(text) {
	    return app.utils.escapeHtml(text)
		.replace(/`([^`\n]+)`/g, '<code>$1</code>')
		.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
		.replace(/\n/g, '<br>');
	},

	//timeAgo as <time datetime="ISO 8601" title="the datetime, in the account's format">words</time> (escaped; as util\Format::timeagoHtml)
	timeAgoHtml: function(past, short) {
	    const d = toDate(past);
	    if (!d)
		return '';
	    return `<time datetime="${app.utils.escapeHtml(d.toISOString())}" title="${app.utils.escapeHtml(this.datetime(d))}">${app.utils.escapeHtml(this.timeAgo(d, short))}</time>`;
	},

	//past (a Date, ISO 8601 text or ms) in words: 5 minutes ago (short: 5m), about 1 hour ago, 3 days ago...; empty or not a date -> ''
	timeAgo: function(past, short) {
	    past = toDate(past);
	    if (!past)
		return '';
	    const texts = short ? AGO.short : AGO.normal;
	    const now = new Date();
	    const diff = Math.floor((now - past) / 1000);
	    const rule = AGO_RULES.find(([ below ]) => diff < below);
	    if (!rule)
		return fill(texts.years, Math.max(now.getFullYear() - past.getFullYear(), 0));
	    if (typeof rule[1] === 'string')
		return texts[rule[1]];
	    const [ key, n ] = rule[1](diff, past, now);
	    return fill(texts[key], n);
	},
    };
})();
