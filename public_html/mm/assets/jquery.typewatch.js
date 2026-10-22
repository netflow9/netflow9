/*
*	TypeWatch 3
*
*	Examples/Docs: github.com/dennyferra/TypeWatch
*  
*  Dual licensed under the MIT and GPL licenses:
*  http://www.opensource.org/licenses/mit-license.php
*  http://www.gnu.org/licenses/gpl.html
*/

'use strict';
$.fn.typeWatch = function(o) {
    // The default input types that are supported
    var _supportedInputTypes =
	    ['TEXT', 'TEXTAREA', 'PASSWORD', 'TEL', 'SEARCH', 'URL', 'EMAIL', 'DATETIME', 'DATE', 'MONTH', 'WEEK', 'TIME', 'DATETIME-LOCAL', 'NUMBER', 'RANGE', 'DIV'];

    var defaults = {
	wait: 750,
	callback: function() { },
	highlight: true,
	captureLength: 2,
	allowSubmit: false,
	inputTypes: _supportedInputTypes
    };

    // Options
    var options = $.extend(defaults, o);

    function checkElement(timer, override) {
	var value = timer.type === 'DIV' ? $(timer.el).html() : $(timer.el).val();

	// If has capture length and has changed value
	// Or override and has capture length or allowSubmit option is true
	// Or capture length is zero and changed value
	if ((value.length >= options.captureLength && value != timer.text)
		|| (override && (value.length >= options.captureLength || options.allowSubmit))
		|| (value.length == 0 && timer.text)) {
	    timer.text = value;
	    timer.cb.call(timer.el, value);
	}
    };

    function watchElement(elem) {
	var elementType = (elem.type || elem.nodeName).toUpperCase();
	if ($.inArray(elementType, options.inputTypes) == -1)
	    return;

	// Allocate timer element
	var timer = {
	    timer: null,
	    text: (elementType === 'DIV') ? $(elem).html() : $(elem).val(),
	    cb: options.callback,
	    el: elem,
	    type: elementType,
	    wait: options.wait
	};

	// Set focus action (highlight)
	if (options.highlight && elementType !== 'DIV')
	    $(elem).focus(function() { this.select(); });

	// Key watcher / clear and reset the timer
	var startWatch = function(evt) {
	    var timerWait = timer.wait;
	    var overrideBool = false;
	    var evtElementType = elementType;

	    // If enter key is pressed and not a TEXTAREA or DIV
	    if (typeof evt.keyCode != 'undefined' && evt.keyCode == 13 && evtElementType !== 'TEXTAREA' && elementType !== 'DIV') {
		timerWait = 1;
		overrideBool = true;
	    }

	    var timerCallbackFx = function() {
		checkElement(timer, overrideBool)
	    }

	    // Clear timer
	    clearTimeout(timer.timer);
	    timer.timer = setTimeout(timerCallbackFx, timerWait);
	};

	$(elem).on('keydown paste cut input', startWatch);
    };

    // Watch each element
    return this.each(function() {
	watchElement(this);
    });
};
