if (!window.app)
    window.app = {};

window.app.pjax = (function() {
    const debug = true;

    //the current xhr
    var c_xhr = null;

    var rqno = 1;

    // Return the `href` component of given URL object with the hash portion removed.
    function stripHash(loca) {
	return loca.href.replace(/#.*/, '')
    }

    // Internal: Hard replace current state with url.
    function locationReplace(url) {
	window.history.replaceState(null, "", url)
	window.location.replace(url)
    }

    function disableElement(el, add_spinner) {
	el = $(el);
	el.attr('disabled', true).css('pointer-events', 'none')

	if (add_spinner && el.prop('tagName') != 'INPUT') {
	    el.attr('data-orig-label', el.html());
	    el.html('<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span>' + el.html())
	}
    }

    function enableElement(el) {
	el = $(el);
	el.attr('disabled', false).css('pointer-events', '')
	if (el.data('orig-label') && el.prop('tagName') != 'INPUT') {
	    el.html(el.data('orig-label'));
	}
    }

    function fetch(href, options) {
	//console.log(rqno + ': fetch: enter');

	const ts = Date.now();

	var defaults = {
	    //push in history
	    push: false,
	    //state
	    state: {},
	    //method
	    method: 'GET',
	    //data to GET or POST
	    data: {},
	    //where to put the result
	    destination: '.main-area',
	    //source element
	    source: null,
	    //mode: replace or append
	    mode: 'replace',
	}

	rqno++;
	options = $.extend({}, defaults, options)

	if (c_xhr)
	    c_xhr.abort();

	var redirectUrl = null;
	var response = null;

	var ajax_options = {
	    url: href,
	    type: options.method,
	    data: options.data,

	    beforeSend: function(xhr, settings) {
		// No timeout for non-GET requests
		// Its not safe to request the resource again with a fallback method.
		if (settings.type !== 'GET') {
    		    settings.timeout = 0;
    		} else {
    		    settings.timeout = 60 * 1000; //60 seconds
    		}
		xhr.setRequestHeader('X-PJAX', 'true')
        	xhr.setRequestHeader('X-Csrf-Token', $('meta[name=csrf-token]').attr('content'));

		//disable source element
		if (options.source)
		    disableElement(options.source, settings.type != 'GET');
	    },
	    error: function(xhr, textStatus, errorThrown) {
		if (debug)
		    console.log(rqno + ': fetch::error: textStatus:', textStatus);

		const resp = $(xhr.responseText)
		$('main').replaceWith($('<div/>').append(resp.filter('main')));
		document.title = resp.filter('title').text();
	    },
	    success: function(__response, status, xhr) {
		if (debug)
		    console.log(rqno + ': fetch::success: status:', status);
		
		redirectUrl = xhr.getResponseHeader('X-Pjax-Redirect');
		response = __response;
	    },
	    complete: function(xhr, textStatus) {
		//if (debug)
		//    console.log(rqno + ': fetch::complete: enter...');

		c_xhr = null;

		if (options.source)
		    enableElement(options.source);

		if (textStatus != 'success') {
		    if (debug)
			console.log(rqno + ': fetch::complete: textStatus=', textStatus);

		    return;
		}

		//close and dispose all previous modals
		$('body').find('.modal').each(function(idx, el) {
		    bootstrap.Modal.getOrCreateInstance(el).dispose();
		    $(el).remove();
		});

		//process the response
		//a pjax response in json
		if (typeof response == 'object') {
		    if (debug)
			console.log(rqno + ': fetch::complete: received a JSON response:', response);

		    //do we have meta in response ?
		    if (response.meta.length) {
			response.meta.forEach((ev) => {
			    if (debug)
				console.log(rqno + ': fetch::complete: processing meta event:', ev);

			    //handle redirection here; the rest are dispatched by app.events.dispatch
			    if (ev.type == 'pjax::redirect') {
				redirectUrl = ev.data.url;
				return;
			    }

			    app.events.dispatch(ev);
			});
		    }

		    if (typeof response.content !== 'undefined') {
			var content = $(response.content);
			if ((content.find('.modal-dialog')).length) {
			    if (debug)
				console.log(rqno + ': fetch::complete: received a modal in JSON response');

			    options.push = false;
			    $('body').append(content).append($(response.scripts));
			    (new bootstrap.Modal('#' + $('body').find('.modal').attr('id'))).show();
			} else {
			    if (debug)
				console.log(rqno + ': fetch::complete: received a non-modal in JSON response');
			    //if we received just 'OK', do nothing
			    if (response.content == 'OK') {
				options.push = false;
			    } else {
				document.title = response.title;
				$(options.destination).empty().append(content);
			    }
			    ////re-init events
			    if (debug)
		    		console.log(rqno + ': fetch::complete: re-init event listeners');
			    app.events.init();
			}
		    }
		    if (typeof response.script !== 'undefined') {
			$('body').append($(response.script));
		    }
		} else {
		    if (debug)
		    	console.log(rqno + ': fetch::complete: received a text response:', response);

		    if (response && response.length) {
			//re-init events
			if (debug)
			    console.log(rqno + ': fetch::complete: re-init event listeners');
			app.events.init();

			$(options.destination).empty().append($(response));
		    }
		}

		if (options.push)
    		    history.pushState(options.state, document.title, href);

		if (options.onComplete)
		    options.onComplete(xhr.responseText);

		//do redirection
		if (redirectUrl) {
		    queue = [];
		    fetch(redirectUrl, { push: true});
		    return;
		}
	    }
	}
	
	if (options.data instanceof FormData) {
	    ajax_options.processData = false;
	    ajax_options.contentType = false;
	}

	c_xhr = $.ajax(ajax_options);
    }

    //----------------------------
    return {
	// load content from href
	// options:
	//   destination: the destination where to put the received content
	// if a modal is received as content, ignore selector and append to body
	// if a redirect is received, do redirect
	// if an error is received, replace <main>
	fetch: function(href, options) {
	    fetch(href, options);
	},
	isActive: function() {
	    return c_xhr != null;
	},
	init: function() {


	    //CLD: 2026-10-16T13:33:48+03:00: confirm in a small popover next to the clicked element (below it, above when there is no room; Popper), was a modal. Closed by the
	    //buttons, Escape, a click elsewhere or scrolling; one open at a time. An element in a dropdown menu (hidden once clicked) anchors to the menu's toggle
	    let confirmBox = null;
	    const closeConfirm = () => {
		if (!confirmBox)
		    return;
		confirmBox.popper.destroy();
		confirmBox.node.remove();
		$(document).off('.pjaxconfirm');
		$(window).off('.pjaxconfirm');
		confirmBox.anchor.focus?.();
		confirmBox = null;
	    };

	    $(document).on('click', '[data-confirm]', function(e) {
		e.preventDefault();

		const el = $(this);
		const url = el.data('remote') || el.attr('href');
		const message = el.data('confirm');
		const method = el.data('method') || 'get';
		const reopen = confirmBox && confirmBox.el === this;
		closeConfirm();
		if (reopen)
		    return false;

		const menu = this.closest('.dropdown-menu');
		const anchor = (menu && menu.parentElement.querySelector('[data-bs-toggle=dropdown]')) || this;

		const node = app.utils.el('div', 'popover bs-popover-auto shadow-sm', { role: 'alertdialog' });
		node.style.maxWidth = '22rem';
		const arrow = app.utils.el('div', 'popover-arrow', { 'data-popper-arrow': '' });
		const body = app.utils.el('div', 'popover-body d-flex align-items-center gap-2 py-2 px-3');
		const text = app.utils.el('div', 'flex-grow-1 small');
		text.innerHTML = message;
		const ok = app.utils.el('button', 'btn btn-sm btn-success py-0 px-2', { type: 'button', title: 'Yes' });
		ok.innerHTML = "<i class='bi bi-check-lg'></i>";
		const cancel = app.utils.el('button', 'btn btn-sm btn-danger py-0 px-2', { type: 'button', title: 'No' });
		cancel.innerHTML = "<i class='bi bi-x-lg'></i>";
		body.append(text, ok, cancel);
		node.append(arrow, body);
		document.body.append(node);

		const popper = Popper.createPopper(anchor, node, {
		    placement: 'bottom',
		    modifiers: [
			{ name: 'offset', options: { offset: [ 0, 8 ] } },
			{ name: 'flip', options: { fallbackPlacements: [ 'top', 'left', 'right' ], boundary: document.body, altAxis: false } },
			{ name: 'preventOverflow', options: { padding: 8, boundary: document.body } },
			{ name: 'arrow', options: { element: arrow, padding: 6 } },
		    ],
		});
		confirmBox = { el: this, anchor: anchor, node: node, popper: popper };

		ok.addEventListener('click', () => {
		    closeConfirm();
		    fetch(url, { method: method, source: el });
		});
		cancel.addEventListener('click', closeConfirm);
		//after this click has finished bubbling
		setTimeout(() => {
		    $(document).on('mousedown.pjaxconfirm', (ev) => {
			if (confirmBox && !confirmBox.node.contains(ev.target) && !confirmBox.el.contains(ev.target))
			    closeConfirm();
		    });
		    $(document).on('keydown.pjaxconfirm', (ev) => {
			if (ev.key === 'Escape')
			    closeConfirm();
		    });
		    $(window).one('scroll.pjaxconfirm resize.pjaxconfirm', closeConfirm);
		});
		ok.focus();
		return false;
	    });


	    //submits
	    //CLD: 2026-10-11T21:35:50+03:00: forms only (was ':not([no-pjax])': it also matched the form's ancestors, so a form with no-pjax was still taken over, with
	    //currentTarget = an ancestor, and failed on its missing method)
	    $(document).on('submit', 'form:not([no-pjax])', function(e) {
		e.preventDefault();
		
		console.log('app.pjax::submit: a form was submitted, e=', e);

		const form = $(e.currentTarget);
		var formAction = form.attr('action');
		if (!formAction || !formAction.match(/(^\/|:\/\/)/))
		    formAction = window.location.href;

		var formMethod = form.attr('method');
		if (!formMethod.match(/(get|post)/i)) {
		    form.append($('<input/>', { name: '_method', value: formMethod, type: 'hidden' }));
		    formMethod = 'POST';
		}

		//console.log('app.pjax::submit: action=', formAction);
		//console.log('app.pjax::submit: method=', formMethod);

		var formData = new FormData(form[0]);

		if (formMethod.match(/post/i)) {
		    const csrfParam = $('meta[name=csrf-param]').attr('content');
		    const csrfToken = $('meta[name=csrf-token]').attr('content');

		    if (csrfParam.length && csrfToken.length)
			formData.append(csrfParam, csrfToken);
		}

		//fetch
		const options = {
		    method: formMethod,
		    data: formData,
		    push: false,
		    source: e.originalEvent.submitter,
		};

		fetch(formAction, options);

		return false;
	    });
	
	    //clicks
	    $(document).on('click', 'a:not([no-pjax])', function(e) {
		var link = e.currentTarget;

		// click with modifiers should open links in a new tab as normal.
		if ( e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
		    if (debug)
			console.log('app.pjax::clickHandler: click with modifiers, ignore');
		    return;
		}

		// Ignore event with default prevented
		if (e.isDefaultPrevented()) {
		    if (debug)
			console.log('app.pjax::clickHandler: default prevented, ignore');
		    return;
		}

		// Ignore cross origin links
		if (location.protocol !== link.protocol || location.hostname !== link.hostname) {
		    if (debug)
			console.log('app.pjax::clickHandler: cross origin click, ignore');
		    return;
		}

		// Ignore case when a hash is being tacked on the current URL
		if (link.href.indexOf('#') > -1 && stripHash(link) == stripHash(location))
		    return

		if (link.target === '_blank' || link.target === '_top') {
		    e.preventDefault();
		    window.open(link.href);
		    return false;
		}

		e.preventDefault()

		// extend some defaults with data from clicked element
		var options = $.extend({}, { push: true, source: $(link) }, $(link).data());

		//CLD: 2026-10-11T21:32:01+03:00: data-method (e.g. data-method="post": a link that changes something) is the request's method (the CSRF token goes in the
		//X-Csrf-Token header); such a request is not pushed to the history (back / reload must not repeat it). Was only picked up implicitly through data()
		const method = String($(link).data('method') || 'get').toUpperCase();
		options.method = method;
		if (method !== 'GET')
		    options.push = false;

		if (debug)
		    console.log('app.pjax::clickHandler: call fetch...');
		fetch(link.href, options);

		return false;
	    });

	    //initialization - back navigation
	    window.addEventListener('popstate', function(e) {
		console.log('app.pjax: popstate event fired, e:', e);
		fetch(location.href, { push: false })
	    });
	}
    };
})();
