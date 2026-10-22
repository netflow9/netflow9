/*!
 * jquery.fancytree.glyph.js
 *
 * Use glyph-fonts, ligature-fonts, or SVG icons instead of icon sprites.
 * (Extension module for jquery.fancytree.js: https://github.com/mar10/fancytree/)
 *
 * Copyright (c) 2008-2023, Martin Wendt (https://wwWendt.de)
 *
 * Released under the MIT license
 * https://github.com/mar10/fancytree/wiki/LicenseInfo
 *
 * @version 2.38.4-0
 * @date 2024-03-28T19:17:23Z
 */

(function (factory) {
	if (typeof define === "function" && define.amd) {
		// AMD. Register as an anonymous module.
		define(["jquery", "./jquery.fancytree"], factory);
	} else if (typeof module === "object" && module.exports) {
		// Node/CommonJS
		require("./jquery.fancytree");
		module.exports = factory(require("jquery"));
	} else {
		// Browser globals
		factory(jQuery);
	}
})(function ($) {
    "use strict";

    /******************************************************************************
     * Private functions and variables
     */

    var FT = $.ui.fancytree;
    var PRESETS = {
	awesome6: {
	    _addClass: "",
	    checkbox: "fa fa-square",
	    checkboxSelected: "fa fa-check-square",
	    // checkboxUnknown: "far fa-window-close",
	    checkboxUnknown: "fa fa-square fancytree-helper-indeterminate-cb",
	    radio: "fa fa-circle",
	    radioSelected: "fa fa-circle",
	    radioUnknown: "fa fa-dot-circle",
	    dragHelper: "fa fa-arrow-right",
	    dropMarker: "fa fa-long-arrow-alt-right",
	    error: "fa fa-exclamation-triangle",
	    expanderClosed: "fa fa-caret-right",
	    expanderLazy: "fa fa-angle-right",
	    expanderOpen: "fa fa-caret-down",
	    loading: "fa fa-spinner pulse",
	    nodata: "fa fa-meh",
	    noExpander: "",
	    // Default node icons.
	    // (Use tree.options.icon callback to define custom icons based on node data)
	    doc: "fa fa-file",
	    docOpen: "fa fa-file",
	    folder: "fa fa-folder",
	    folderOpen: "fa fa-folder-open",
	},
	bi: {
	    _addClass: "bi",
	    checkbox: "bi-square",
	    checkboxSelected: "bi-check-square",
	    checkboxUnknown: "bi-slash-square fancytree-helper-indeterminate-cb",
	    dragHelper: "bi-play-fill",
	    dropMarker: "bi-arrow-right",
	    error: "bi-exclamation-triangle",
	    expanderClosed: "bi-chevron-right",
	    expanderLazy: "bi-chevron-right", // glyphicon-plus-sign
	    expanderOpen: "bi-chevron-down", // glyphicon-minus-sign
	    loading: "spinner-border",
	    nodata: "bi-exclamation-triangle",
	    noExpander: "",
	    radio: "bi-circle", // "glyphicon-unchecked",
	    radioSelected: "bi-record-circle",
	    // radioUnknown: "glyphicon-ban-circle",
	    // Default node icons.
	    // (Use tree.options.icon callback to define custom icons based on node data)
	    doc: "bi-file-earmark",
	    docOpen: "bi-file-earmark",
	    folder: "bi-folder2",
	    folderOpen: "bi-folder2-open",
	},
    };

    function setIcon(node, span, baseClass, opts, type) {
	var map = opts.map,
	    icon = map[type],
	    $span = $(span),
	    $counter = $span.find(".fancytree-childcounter"),
	    setClass = baseClass + " " + (map._addClass || "");

	// #871 Allow a callback
	if (typeof icon === "function") {
	    icon = icon.call(this, node, span, type);
	}
	// node.debug( "setIcon(" + baseClass + ", " + type + "): " + "oldIcon" + " -> " + icon );
	// #871: propsed this, but I am not sure how robust this is, e.g.
	// the prefix (fas, far) class changes are not considered?
	// if (span.tagName === "svg" && opts.preset === "awesome5") {
	// 	// fa5 script converts <i> to <svg> so call a specific handler.
	// 	var oldIcon = "fa-" + $span.data("icon");
	// 	// node.debug( "setIcon(" + baseClass + ", " + type + "): " + oldIcon + " -> " + icon );
	// 	if (typeof oldIcon === "string") {
	// 		$span.removeClass(oldIcon);
	// 	}
	// 	if (typeof icon === "string") {
	// 		$span.addClass(icon);
	// 	}
	// 	return;
	// }
	if (typeof icon === "string") {
	    // #883: remove inner html that may be added by prev. mode
	    span.innerHTML = "";
	    $span.attr("class", setClass + " " + icon).append($counter);
	} else if (icon) {
	    if (icon.text) {
		span.textContent = "" + icon.text;
	    } else if (icon.html) {
		span.innerHTML = icon.html;
	    } else {
		span.innerHTML = "";
	    }
	    $span
		.attr("class", setClass + " " + (icon.addClass || ""))
		.append($counter);
	}
    }

    $.ui.fancytree.registerExtension({
		name: "glyph",
		version: "2.38.4-0",
		// Default options for this extension.
		options: {
			preset: null, // 'awesome3', 'awesome4', 'bootstrap3', 'material'
			map: {},
		},

		treeInit: function (ctx) {
			var tree = ctx.tree,
				opts = ctx.options.glyph;

			if (opts.preset) {
				FT.assert(
					!!PRESETS[opts.preset],
					"Invalid value for `options.glyph.preset`: " + opts.preset
				);
				opts.map = $.extend({}, PRESETS[opts.preset], opts.map);
			} else {
				tree.warn("ext-glyph: missing `preset` option.");
			}
			this._superApply(arguments);
			tree.$container.addClass("fancytree-ext-glyph");
		},
		nodeRenderStatus: function (ctx) {
			var checkbox,
				icon,
				res,
				span,
				node = ctx.node,
				$span = $(node.span),
				opts = ctx.options.glyph;

			res = this._super(ctx);

			if (node.isRootNode()) {
				return res;
			}
			span = $span.children(".fancytree-expander").get(0);
			if (span) {
				// if( node.isLoading() ){
				// icon = "loading";
				if (node.expanded && node.hasChildren()) {
					icon = "expanderOpen";
				} else if (node.isUndefined()) {
					icon = "expanderLazy";
				} else if (node.hasChildren()) {
					icon = "expanderClosed";
				} else {
					icon = "noExpander";
				}
				// span.className = "fancytree-expander " + map[icon];
				setIcon(node, span, "fancytree-expander", opts, icon);
			}

			if (node.tr) {
				span = $("td", node.tr).find(".fancytree-checkbox").get(0);
			} else {
				span = $span.children(".fancytree-checkbox").get(0);
			}
			if (span) {
				checkbox = FT.evalOption("checkbox", node, node, opts, false);
				if (
					(node.parent && node.parent.radiogroup) ||
					checkbox === "radio"
				) {
					icon = node.selected ? "radioSelected" : "radio";
					setIcon(
						node,
						span,
						"fancytree-checkbox fancytree-radio",
						opts,
						icon
					);
				} else {
					// eslint-disable-next-line no-nested-ternary
					icon = node.selected
						? "checkboxSelected"
						: node.partsel
						? "checkboxUnknown"
						: "checkbox";
					// span.className = "fancytree-checkbox " + map[icon];
					setIcon(node, span, "fancytree-checkbox", opts, icon);
				}
			}

			// Standard icon (note that this does not match .fancytree-custom-icon,
			// that might be set by opts.icon callbacks)
			span = $span.children(".fancytree-icon").get(0);
			if (span) {
				if (node.statusNodeType) {
					icon = node.statusNodeType; // loading, error
				} else if (node.folder) {
					icon =
						node.expanded && node.hasChildren()
							? "folderOpen"
							: "folder";
				} else {
					icon = node.expanded ? "docOpen" : "doc";
				}
				setIcon(node, span, "fancytree-icon", opts, icon);
			}
			return res;
		},
		nodeSetStatus: function (ctx, status, message, details) {
			var res,
				span,
				opts = ctx.options.glyph,
				node = ctx.node;

			res = this._superApply(arguments);

			if (
				status === "error" ||
				status === "loading" ||
				status === "nodata"
			) {
				if (node.parent) {
					span = $(".fancytree-expander", node.span).get(0);
					if (span) {
						setIcon(node, span, "fancytree-expander", opts, status);
					}
				} else {
					//
					span = $(
						".fancytree-statusnode-" + status,
						node[this.nodeContainerAttrName]
					)
						.find(".fancytree-icon")
						.get(0);
					if (span) {
						setIcon(node, span, "fancytree-icon", opts, status);
					}
				}
			}
			return res;
	},
    });
    // Value returned by `require('jquery.fancytree..')`
    return $.ui.fancytree;
}); // End of closure
