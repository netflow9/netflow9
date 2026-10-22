if (!window.app) {
    window.app = {
	init: false,
	debug: true,
    };
}

//ace
$.fn.ace = function(options) {
    return this.each(function () {
        var el = $(this)
    	var hidden = $('<input/>').attr('type', 'hidden').insertBefore(el).attr('name', el.attr('name'))

	const editor = ace.edit(el.attr('id'), {
	    theme: 'ace/theme/chrome',
	    maxLines: el.attr('rows'),
	    minLines: el.attr('rows'),
	    fontSize: '1rem',
	})
	editor.session.setMode({path: 'ace/mode/php', inline: true });
	editor.on('change', function(e) {
	    hidden.val(editor.getValue())
	})
    	hidden.attr('value', editor.getValue())
    });
};



//toasts
window.app.toast = function(data) {
    const defaults = {
	type: 'info',
	delay: 5000,
	title: '',
	body: '',
    }

    data = $.extend({}, defaults, data);

    //badge
    //badge is one of: 'error', 'warning', 'success', 'info'
    var badge_class, badge_label
    switch(data.type) {
	case 'error':
	    badge_class = 'bg-danger';
	    badge_label = 'Error';
	    break;
	case 'warning':
	    badge_class = 'bg-warning';
	    badge_label = 'Warning';
	    break;
	case 'success':
	    badge_class = 'bg-success';
	    badge_label = 'Success';
	    break;
	case 'info':
	default:
	    badge_class = 'bg-info';
	    badge_label = 'Info';
	    break;
    }
    var badge = '<span class="badge ' + badge_class + '">' + badge_label + '</span>';

    var __toast = '' +
	'<div class="toast fade hide" style="min-width: 350px; background-color: white;" role="alert" aria-live="assertive" aria-atomic="true" data-delay="' + data.delay + '">' +
	    '<div class="toast-header justify-content-between align-items-center">' +
		badge +
    		data.title +
		'<a href="#" class="m-2 float-right" data-bs-dismiss="toast" aria-label="Close" onclick="return false;">' +
		    '<i class="bi bi-x-lg"></i>' +
		'</a>' +
	    '</div>' +
	    '<div class="toast-body">' +
    		data.body +
	    '</div>' +
	'</div>'
    ;
    $(__toast).appendTo($('#toasts-container')).toast('show');
}

//pjax handling
window.app.pjax = (function($) {

    //the current xhr
    var c_xhr = null;

    // Return the `href` component of given URL object with the hash portion removed.
    function stripHash(loca) {
	return loca.href.replace(/#.*/, '')
    }

    // Internal: Hard replace current state with url.
    function locationReplace(url) {
	window.history.replaceState(null, '', url)
	window.location.replace(url)
    }

    var api = {
	navigate: function(href, options) {
	    if (window.app.debug)
		console.log('pjax::navigate: href=', href)
	    if (c_xhr) {
		if (window.app.debug)
		    console.log('pjax::navigate: there is an already active request: ', c_xhr);
		c_xhr.abort();
	    }

	    var defaults = {
		push: false,
		state: {},
		method: 'GET',
		data: {},
		selector: '#right-pane',
		mode: 'replace',
		processData: true,
		contentType: true,
	    }

	    options = $.extend({}, defaults, options)
	    //console.log('pjax::navigate: options=', options);

	    //destination
	    var dst_el = $(options.selector);

	    if (dst_el.length == 0) {
		if (window.app.debug)
		    console.log('pjax:navigate: output destination not found, fallback to standard nav');
    		locationReplace(href);
		return;
	    }

	    var content = '';

	    var ajax_options = {
		url: href,
		type: options.method,
		data: options.data,
		beforeSend: function(xhr, settings) {
		    // No timeout for non-GET requests
		    // Its not safe to request the resource again with a fallback method.
		    //SC: 2023-01-17: added timeout
		    if (settings.type !== 'GET') {
    			settings.timeout = 0;
    		    } else {
    			settings.timeout = 60 * 1000; //60 seconds
    		    }
		    xhr.setRequestHeader('X-PJAX', 'true')
		},
		error: function(xhr, textStatus, errorThrown) {
		    c_xhr = null;
		    content = xhr.responseText;
		},
		success: function(response, status, xhr) {
		    content = response;
		},
		complete: function(xhr, textStatus) {
		    if (window.app.debug)
			console.log('pjax::navigate: complete')
		    c_xhr = null;

		    var url = xhr.getResponseHeader('X-Pjax-Redirect');
		    if (url) {
			if (window.app.debug)
			    console.log('pjax:navigate: complete: redirecting to url:', url);
			api.navigate(url, { push: true});
			return;
		    }

		    //save as file
		    const cdisp = xhr.getResponseHeader('Content-Disposition');
		    const ctype = xhr.getResponseHeader('Content-Type');
		    if (cdisp && cdisp.includes('attachment')) {

			// check for a filename
        		const matches = cdisp.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
        		var filename = ''
        		if (matches && matches[1])
        		    filename = matches[1].replace(/['"]/g, '');

			if (filename.length) {
			    // use HTML5 a[download] attribute to specify filename
            		    const a = document.createElement('a');
                	    a.href = window.URL.createObjectURL(new File([content], filename, { type: ctype }));
                	    a.download = filename;
                	    document.body.appendChild(a);
                	    a.click();
        		    setTimeout(function () { URL.revokeObjectURL(a.href); }, 100); // cleanup
            		}
		    } else {
			//extract scripts, append to body after DOM has been inserted
			content = $('<div/>').append(content);
			var js = content.find('script').remove();

			dst_el.html(content.html());

			//execute js after content was bound to DOM
			$('body').append(js);

			if (options.push)
    			    history.pushState(options.state, document.title, href)
		    }
		    $(document).trigger('pjax:complete');

		    if (options.onComplete)
			options.onComplete();
		}
	    }

	    if (options.processData == false)
		ajax_options.processData = false;
	    if (options.contentType == false)
		ajax_options.contentType = false;

	    $(document).trigger('pjax:before');
	    c_xhr = $.ajax(ajax_options);
	},
    }

    function popStateHandler(e) {
	if (window.app.debug)
	    console.log('pjax::popstatehandler: enter with e=', e);
	if (typeof(e) != 'undefined')
	    $(document).trigger({ type: 'pjax:popstate', state: e.state });

	api.navigate(location.href, { push: false })
    }

    function clickHandler(e) {
	if (window.app.debug)
	    console.log('pjax: clickHandler: enter, e=', e)

	// click with modifiers should open links in a new tab as normal.
	if ( e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
	    if (window.app.debug)
		console.log('pjax: click with modifiers, ignore');
	    return
	}

	// Ignore event with default prevented
	if (e.isDefaultPrevented()) {
	    if (window.app.debug)
		console.log('pjax: clickHandler: default prevented, ignore');
	    return
	}

	var link = e.currentTarget
	if (link.tagName.toUpperCase() !== 'A') {
	    if (window.app.debug)
		console.log('pjax: clicked element is not A, ignore');
	    return
	}

	// Ignore cross origin links
	if (location.protocol !== link.protocol || location.hostname !== link.hostname) {
	    if (window.app.debug)
		console.log('pjax: cross origin click, ignore');
	    return
	}

	// Ignore case when a hash is being tacked on the current URL
	if (link.href.indexOf('#') > -1 && stripHash(link) == stripHash(location))
	    return

	// Ignore links intended to affect other tabs or windows.
	if (link.target === '_blank' || link.target === '_top')
	    return

	e.preventDefault()

	//whether a modal is open, close it
	var modal = $('.modal:visible')
	if (modal.length && $('body').hasClass('modal-open'))
	    modal.modal('hide');

	//SC: 2023-01-17: added
	// Load clicked link.
	if (0) {
	    api.navigate(link.href, { push: true });
	} else {
	    const el_data = $(link).data();
	    const defaults = { push: true };
	    const options = $.extend({}, defaults, el_data)
	    api.navigate(link.href, options);
	}
    }

    function submitHandler(e) {
	e.preventDefault()

	if (window.app.debug)
	    console.log('pjax::submitHandler: enter, e=', e)
	var el = $(this)
	var form = el.closest('form')

	if (window.app.debug)
	    console.log('pjax::submitHandler: clicked=', el);

	if(form.length == 0) {
	    if (window.app.debug)
		console.log('pjax::submitHandler: no form found')
	    return;
	}

	//disable button and add spinner
	var btn_label = el.html();
	el.attr('disabled', true)
	el.css('pointer-events', 'none')

	var action = form.attr('action')
        if (!action || !action.match(/(^\/|:\/\/)/))
    	    action = window.location.href;

	method = form.attr('method')
        if (!method.match(/(get|post)/i)) {
            form.append($('<input/>', { name: '_method', value: method, type: 'hidden' }));
            method = 'POST';
        }
        if (method.match(/post/i) && app.csrf) {
            var csrfParam = app.csrf.getCsrfParam();
            if (csrfParam)
        	form.append($('<input/>', { name: csrfParam, value: app.csrf.getCsrfToken(), type: 'hidden'}));
        }

	
	var form_data = new FormData(form[0]);

	//navigate to
	if (window.app.debug) {
	    console.log('pjax::submitHandler: navigate....');
	    console.log('pjax::submitHandler: formdata=', form_data);
	    console.log('pjax::submitHandler: form enctype=', form.attr('enctype'));
	}

	var options = {
	    method: method,
//	    data: form.serializeArray(),
	    data: form_data,
	    push: false,

	    processData: false,
	    contentType: false
	};
	if (el.data('selector'))
	    options.selector = el.data('selector');

	options.onComplete = function() {
	    el.attr('disabled', false);
	    el.css('pointer-events', '');
	    el.html(btn_label);
	};

	api.navigate(action, options);
	return false;
    }

    //initialize click/popstate handlers
    if (1) {
	$(document).on('click', '[pjax]', clickHandler);
	$(document).on('click', '[pjax-submit]', submitHandler);
	//$(window).on('popstate', popStateHandler);

	window.addEventListener('load', function() {
	    setTimeout(function() {
    		window.addEventListener('popstate', function() {
        	    popStateHandler();
        	});
            }, 0);
        });
    }

    return api;
})(jQuery);

$(document).ready(function(e) {
    //install split
    if ($('#left-pane').length) {
	//split
	Split([ '#left-pane', '#right-pane' ], {
	    sizes: [30, 70],
	    minSize: 200,
	    gutter: function(index, direction) {
		const gutter = document.createElement('div');
		gutter.className = 'gutter gutter-horizontal';
		return gutter;
	    }
	});
    }

    //install navigation
    const tree = $('.nav-tree');
    if ($('.nav-tree').length) {

	//event: navigate
	$(document).on('navigate', function(e) {
	    if (window.app.debug)
		console.log('tree: navigate: data=', e);
	    //const ft = tree.fancytree('getTree');
	    const ft = $.ui.fancytree.getTree('.nav-tree');
	    const ns = e.key.split('.');

	    //load/expand db-type node
	    const dbnode = ft.getNodeByKey(ns[0]);
	    if (dbnode) {
		dbnode.resetLazy();
		dbnode.setExpanded(true).then(function() {
		    dbnode.setActive(true);
		    //search coll node
		    const collnode = ft.getNodeByKey(e.key);
		    if (collnode) {
			collnode.setActive();
			if (collnode.data.url.length)
			    app.pjax.navigate(collnode.data.url);
		    } else {
			if (dbnode.data.url.length)
			    app.pjax.navigate(dbnode.data.url);
		    }
		});
		return;
	    } else {
		if (window.app.debug)
		    console.log('navigate: a node was not found by key:', e.key);
	    }
	});

	//event: search
	$(document).on('search', function(e) {
	    const ft = $.ui.fancytree.getTree('.nav-tree');
	    const query = e.query;
	    if (query.length == 0) {
		ft.clearFilter();
		return;
	    }
	    ft.filterNodes.call(ft, query);
	});

	tree.fancytree({
	    extensions: [ 'glyph', 'filter' ],
	    glyph: {
		preset: 'bi',
		map: {}
	    },
	    filter: {
		autoApply: true,   // Re-apply last filter if lazy data is loaded
		autoExpand: false, // Expand all branches that contain matches while filtered
		counter: true,     // Show a badge with number of matching child nodes near parent icons
		fuzzy: false,      // Match single characters in order, e.g. 'fb' will match 'FooBar'
		hideExpandedCounter: true,  // Hide counter badge if parent is expanded
		hideExpanders: false,       // Hide expanders if all child nodes are hidden by filter
    		highlight: true,   // Highlight matches by wrapping inside <mark> tags
		leavesOnly: true, // Match end nodes only
    		nodata: true,      // Display a 'no data' status node if result is empty
    		mode: 'hide',       // Grayout unmatched nodes (pass "hide" to remove unmatched node instead)
    	    },
	    init: function(event, data, flag) {
		if (window.app.debug) {
		    console.log('FT: init: data=', data);
		    console.log('FT: init: config=', config);
		}

		//if there is a database/collection in config, activate and expand
		if (config.current.db.length || config.current.collection.length) {
		    if (window.app.debug)
			console.log('FT: init: navigate to active db/collection');
		    $(document).trigger({type:'navigate',key:config.current.db + '.' + config.current.collection});
		}

		tree.show();
	    },

	    click: function(event, data) {
		const node = data.node;
		if (window.app.debug)
		    console.log('FT: click: node=', node);

		//refresh title on click
		if (node.type == 'collection') {
		    const dotpos = node.key.indexOf('.');
		    const db = node.key.substring(0, dotpos);
		    const collection = node.key.substring(dotpos+1)
		    $.ajax({
			type: 'get',
			dataType: 'json',
			url: config.collectionInfoUrl,
			data: { db: db, collection: collection },
			success: function(response) {
			    $(node.span).find('span.fancytree-title').text(response[collection].title);
			}
		    });
		}

		if (node.data.url.length) {
		    if (!node.isActive() || node.type == 'collection')
			app.pjax.navigate(node.data.url, { push: true });
		}

		if (node.key == 'dbs')
		    return;

		if(!node.isExpanded() && node.type == 'db')
		    node.resetLazy();
	    },

	    lazyLoad: function(event, data) {
		const node = data.node;
		if (window.app.debug)
		    console.log('FT: lazyLoad: node=', node);
		data.result = {
		    url: node.data.lazyUrl,
		};
	    },

	    renderNode: function (event, data) {
		var node = data.node;
		var nodeSpan = $(node.span);

		const ns = node.key.split('.');

		// check if span of node already rendered
		if (nodeSpan.data('rendered'))
		    return;

		//drop database
		if (node.type == 'db') {
		    const drop_db_url = config.dbDropUrl + '&' + $.param({ db: node.key });

    		    const drop_db_btn = $('<a/>').attr('href', drop_db_url).addClass('float-end p-1').attr('title', 'Drop database')
    			.append($('<i/>').addClass('bi bi-trash'))
    			.attr('data-confirm', 'Please confirm').attr('data-method', 'post').hide();
		    nodeSpan.append(drop_db_btn);
    		    nodeSpan.hover( () => drop_db_btn.show(), () => drop_db_btn.hide() )
		}

		//drop collection
		if (node.type == 'collection') {
		    const drop_coll_url = config.collectionDropUrl + '&' + $.param({ db: ns[0], collection: ns[1] });

    		    const drop_coll_btn = $('<a/>').attr('href', drop_coll_url).addClass('float-end p-1').attr('title', 'Drop collection')
    			.append($('<i/>').addClass('bi bi-trash'))
    			.attr('data-confirm', 'Please confirm').attr('data-method', 'post').hide();
		    nodeSpan.append(drop_coll_btn);
    		    nodeSpan.hover( () => drop_coll_btn.show(), () => drop_coll_btn.hide() )
		}

		if (node.type == 'collection') {
		    //duplicate collection
		    const duplicate_url = config.collectionDuplicateUrl + '&' + $.param({ db: ns[0], collection: ns[1] });
    		    const duplicate_btn = $('<a/>').attr('href', duplicate_url).addClass('float-end p-1').attr('title', 'Duplicate ...')
    			.attr('pjax', 1)
    			.append($('<i/>').addClass('bi bi-copy')).hide();
		    nodeSpan.append(duplicate_btn);
    		    nodeSpan.hover( () => duplicate_btn.show(), () => duplicate_btn.hide() )

		    //rename
		    const rename_url = config.collectionRenameUrl + '&' + $.param({ db: ns[0], collection: ns[1] });
    		    const rename_btn = $('<a/>').attr('href', rename_url).addClass('float-end p-1').attr('title', 'Rename ...')
    			.attr('pjax', 1)
    			.append($('<i/>').addClass('bi bi-keyboard')).hide();
		    nodeSpan.append(rename_btn);
    		    nodeSpan.hover( () => rename_btn.show(), () => rename_btn.hide() )
		}

		if (node.type == 'root') {
		    //refresh page
    		    const refresh_btn = $('<a/>').attr('href', window.location.href).addClass('float-end p-1').attr('title', 'Refresh ...')
    			.append($('<i/>').addClass('bi bi-arrow-clockwise')).hide();
		    nodeSpan.append(refresh_btn);
    		    nodeSpan.hover( () => refresh_btn.show(), () => refresh_btn.hide() )
		}

    		// span rendered
    		nodeSpan.data('rendered', true);
	    }
	});
    }

    //loading indicator
    $(document).off('pjax:before').on('pjax:before', function(e) {
	$('#pjax-active').addClass('spinner-border spinner-border-sm').find('i').hide();
    });
    $(document).off('pjax:complete').on('pjax:complete', function(e) {
	$('#pjax-active').removeClass('spinner-border spinner-border-sm').find('i').show();
    });


    //install nav navigation
    $('#nav-bar a.nav-link').on('click', function(e) {
	$('#nav-bar a.nav-link').removeClass('active');
	$(this).addClass('active');
    });

    //data-confirm
    $(document).on('click', '[data-confirm]', function(e) {
	e.preventDefault();
        var el = $(this)
        const message = el.data('confirm')

	//disable element
	el.attr('disabled', 'disabled')

	var confirmed = false;
	if (message === true || message == '1')
    	    confirmed = true;

	if (!confirmed)
    	    confirmed = confirm(message);

	if (!confirmed)
	    return false;

	//confirmed is true, do ajax call
	$(document).on('pjax:complete', function() {
	    el.attr('disabled', '');
	});
	app.pjax.navigate(el.attr('href'), { method: el.data('method') || 'GET', push: false })
    	return false;
    });

    function getMessages() {
	$.ajax({
	    type: 'get',
	    dataType: 'json',
	    url: config.messagesUrl,
	    success: function(result) {
		if (window.app.debug)
		    console.log('get toasts:', result);
		$.each(result, function(k, el) {
		    window.app.toast(el);
		});
	    },
	    global: false,
	});
    }

    //handle Ajax redirection globally
    $(document).ajaxComplete(function (event, xhr, settings) {
        const url = xhr.getResponseHeader('X-Redirect');
        if (url) {
	    window.app.pjax.navigate(url, { push: true});
	    return;
	}
	getMessages();
    });


    //collection browse utilities
    $(document).on('click', '.copy-record', function(e) {
	e.preventDefault();

	const btn = $(this);
	const id = btn.data('id');
	const record = $('.record-text[data-id=' + id + ']');

	navigator.clipboard.writeText(record.text()).then(() => {
	    console.log('Content copied to clipboard');
	    window.app.toast({
		type: 'success',
		body: 'Record copied to clipboard',
	    });
	},() => {
	    console.error('Failed to copy');
	});
    });

    $(document).on('click', '.expand-record', function(e) {
	e.preventDefault();

	const btn = $(this);
	const row = $('.record-row[data-id=' + btn.data('id') + ']');

	if (row.hasClass('expanded')) {
	    row.removeClass('expanded');
	    btn.find('i').removeClass('bi-arrows-collapse').addClass('bi-arrows-expand');
	} else {
	    row.addClass('expanded');
	    btn.find('i').removeClass('bi-arrows-expand').addClass('bi-arrows-collapse');
	}
    });

    $(document).on('click', '.refresh-record', function(e) {
	e.preventDefault();

	const btn = $(this);
	const row = $('.record-row[data-id=' + btn.data('id') + ']');

	row.html('loading ...');
	
	$.ajax({
	    type: 'get',
	    url: btn.attr('href'),
	    success: function (resp) {
		row.html(resp);
	    },
	});
    });


    //search
    const search_el = $('.search');
    $('.clear-search').off('click').on('click', function(e) {
	console.log('clicked on clear-search');
	search_el.val('');
	$(document).trigger({type:'search',query:''});
    });

    //ignore RETURN action
    search_el.on('keydown', function(e) {
	if(e.keyCode === 13) {
	    e.preventDefault();
	    return false;
	}
    })
    search_el.on('keyup', function(e) {
	//ignore RETURN action
	if (e.keyCode === 13) {
	    e.preventDefault();
	    return false;
    	}
    	if (search_el.val() === '')
	    $(document).trigger({type:'search',query:''});
    })
    search_el.typeWatch({
	callback: function(query) {
	    $(document).trigger({type:'search',query:query});
	},
	wait: 300,
	highlight: true,
	allowSubmit: false,
	captureLength: 1,
    })
});

