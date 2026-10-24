if (!window.app)
    window.app = {};
    
/*!
 * form-renderer.js
 * A dependency-free (except Bootstrap 5 CSS) JSON-driven form renderer.
 *
 * Usage:
 *   const renderer = new FormRenderer(document.getElementById('root'), schema);
 *   renderer.render();
 *   const data = renderer.getData();
 *   const isValid = renderer.validate();
 *
 * Schema shape:
 * {
 *   "direction": "vertical" | "horizontal",   // default label layout for the whole form
 *   "fields": [ ...field or layout nodes... ]
 * }
 *
 * Every field node supports:
 *   type          - "text" | "email" | "url" | "textarea" | "select" | "checkbox" | "switch" |
 *                   "date" | "datetime" | "number" | "editgrid" | "password" | "nested"
 *                   (CLD: 2026-10-18T10:20:00+03:00: "nested": one object, its fields in `components`, scope 'row' of their conditions = the object)
 *   key           - unique data key (required for real fields)
 *   label         - visible label text
 *   editable      - true/false (false renders the field disabled/read-only)
 *   required      - true/false
 *   requiredIf    - JS expression string, evaluated with (data, row, index) in scope, or a condition (CLD: 2026-10-14T20:17:14+03:00):
 *                   { key, operator, value, scope: 'row' (the editgrid row) | data } with the fields' operators (as util\Conditions on the server)
 *   showIf        - JS expression string, or a condition
 *   hideIf        - JS expression string, or a condition (wins over showIf when both are true)
 *   width         - bootstrap column width 1-12 (default 12) used for simple 2/3/4-col layouts
 *   newRow        - (with width) the field starts a new line instead of following the narrow fields before it (CLD: 2026-10-24T12:41:49+03:00)
 *   direction     - per-field override of "vertical" | "horizontal"
 *   default       - initial value
 *   helpText      - small muted helper text under the field
 *   format        - (date/datetime) display format, PHP date() tokens, passed to app.DatePicker; the value
 *                   stays 'Y-m-d' / 'Y-m-dTH:i' (local time)
 *   choices       - (select) [ {value,label}, ... ] | [ 'a', 'b' ] | { value: label }; rendered with app.SelectPlus when loaded
 *   variableName  - (text) 'read' | 'write': the value is a variable name; the form's `expression` server suggests the available variables (app.ExprInput
 *                   mode `name`; 'read' also warns about a name no step before sets)
 *   code          - (textarea) CLD: 2026-10-13T15:33:17+03:00: the language of a code editor in place of the textarea (app.CodeEditor; 'j'); live help in the `expression` syntax
 *                   CLD: 2026-10-23T12:30:00+03:00: the editor's language follows the syntax (app.CodeEditor LANGUAGES); `code`: true is enough
 *   syntaxBy      - (textarea with `code`) CLD: 2026-10-23T12:30:00+03:00: the syntax follows other fields: [ { syntax, if: condition | [ conditions, all true ] } ], the first
 *                   that holds wins, else the `expression` syntax; e.g. JSON / JSON-RPC by the body type of the HTTP Request step
 *   propose       - (checkbox, switch) CLD: 2026-10-23T12:30:00+03:00: checking it proposes a value for another (top-level) field: { key, value, confirm }; set when that
 *                   field is empty, else when the user agrees to `confirm` (no `confirm`: only when empty)
 *   expression    - (text, textarea) the value may hold expressions between {{ and }}: checked and completed live on the server (app.ExprInput),
 *                   when the form has the `expression` option
 *                   CLD: 2026-10-13T12:17:47+03:00: true = syntax `text` (text with any {{ }} parts), or the syntax name: 'reference' (only {{ variable }} /
 *                   {{ variable.field }}), 'value' (CLD: 2026-10-13T12:43:20+03:00: one {{ expression }}); the server checks and suggests in that syntax (util\Expression::SYNTAX)
 *   ajaxUrl       - (select) the choices are searched on the server as the user types (app.SelectPlus ajax: GET ?q=&page=, answer
 *                   { results: [ {id, text} ], pagination: { more } }); `choices` then holds only the current value (its label)
 *   choicesBy     - (select) CLD: 2026-10-22T01:58:24+03:00: the choices follow another field's value: { key, choices: { <its value>: choices (as `choices`) }, empty: text }; rebuilt
 *                   when that field changes (switching from one value to another clears a value the new choices do not have; a value set with no value before,
 *                   e.g. by setData, is kept and listed);
 *                   `empty`: the placeholder when that value has no choices
 *   allowCustom   - (select) CLD: 2026-10-22T01:58:24+03:00: a value that is not among the choices can be typed in (app.SelectPlus `create`)
 *   pattern       - regular expression (string) a non-empty string value must match, as a whole (anchored, as the HTML pattern attribute)
 *   patternMessage- error shown when `pattern` does not match
 *   stored        - (password) a value is stored on the server: the input stays empty and an empty input keeps it
 *                   (placeholder says so, unless `placeholder` is given)
 *   reveal        - (password) show the show/hide button (default true)
 *   autocomplete  - (password) the input's autocomplete (default "new-password": the browser never fills in saved logins)
 *
 * Form-level options (schema root):
 *   id / action / method - attributes of the rendered <form> (method defaults to "post"); inputs are named after
 *                 their key (`key[]` for multiple selects), so the form can be submitted natively / by app.pjax;
 *                 submitting runs validate() first and stops an invalid form
 *   expression  - { url, params: () => ({...}) }: the server of the live help of `expression` fields (app.ExprInput: POST text, before + params)
 *   size        - "sm" | "lg": Bootstrap sized controls (form-control-sm, form-select-sm, input-group-sm, col-form-label-sm),
 *                 also in edit grid rows
 *
 * Layout helper node:
 *   { "type": "columns", "columns": [ { "width": 6, "fields": [...] }, { "width": 6, "fields": [...] } ] }
 *
 * CLD: 2026-10-13T18:20:23+03:00: design nodes (no data; showIf / hideIf apply): { "type": "paragraph", "text": "..." } (line breaks kept), { "type": "divider" }; and the field
 * type "formdesigner": its value is a form schema ({ direction, fields }) edited with app.FormDesigner (forms of the Form trigger / Human task steps)
 * CLD: 2026-10-16T12:01:59+03:00: type "emaildesigner": its value is an email of blocks ({ preheader, blocks }, models\EmailBlocks) edited with app.EmailDesigner (Send Email step)
 */

window.app.smartform = (function() {

    class FieldBase {
	/**
	 * @param {object} schema - the field definition from the JSON schema
	 * @param {object} api - { direction, onChange(key,value), getContext() }
	 */
	constructor(schema, api) {
	    this.schema = schema || {};
	    this.api = api;
	    this.key = this.schema.key;
	    this.id = app.utils.uid('field');
	    this.el = null; // wrapper element, set in render()
	    this.inputEl = null; // primary input control
	    this.feedbackEl = null;
	    this.labelEl = null;
	    this._visible = true;
	    this._required = !!this.schema.required;
	    this._forceDisabled = false;
	    this._hiddenDisplayClass = 'd-none';
	    this.currentValue = this.schema.default !== undefined
    		? this.schema.default
    		: this.getEmptyValue();
	}

	/** Override in subclasses if the "empty" value isn't an empty string. */
	getEmptyValue() {
    	    return '';
	}

	direction() {
    	    return this.schema.direction || this.api.direction || 'vertical';
	}

	isEditable() {
    	    return this.schema.editable !== false && !this._forceDisabled;
	}

	label() {
    	    return this.schema.label || this.key || '';
	}

	sized(cls) {
	    return this.api.size ? cls + ' ' + cls + '-' + this.api.size : cls;
	}

	//CLD: 2026-10-11T22:38:36+03:00: an `expression` field of a form with the `expression` option gets the live help (app.ExprInput: problems below it, suggestions)
	_expression(input) {
	    if (!this.api.expression || !window.app.ExprInput)
		return;
	    //CLD: 2026-10-13T12:17:47+03:00: + the field's syntax (expression: true = text, or its name)
	    if (this.schema.expression)
		this.exprInput = new window.app.ExprInput(input, Object.assign({}, this.api.expression, { syntax: typeof this.schema.expression === 'string' ? this.schema.expression : 'text' }));
	    //CLD: 2026-10-11T23:02:07+03:00: + variable name fields (suggestions of the variables)
	    else if (this.schema.variableName)
		this.exprInput = new window.app.ExprInput(input, Object.assign({}, this.api.expression, { mode: 'name', usage: this.schema.variableName }));
	}

	/** Build the wrapper + label + input, wire up events, return the wrapper element. */
	render() {
    	    const horizontal = this.direction() === 'horizontal';
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper mb-3' + (horizontal ? ' row' : '');
    	    wrapper.dataset.key = this.key || '';
    	    wrapper.dataset.type = this.schema.type || '';
    	    this.el = wrapper;

    	    const label = document.createElement('label');
	    //CLD: 2026-10-11T17:02:49+03:00: sized label (form-level `size`)
    	    label.className = horizontal ? 'col-sm-3 ' + this.sized('col-form-label') : 'form-label';
    	    label.setAttribute('for', this.id);
    	    label.textContent = this.label();
    	    this.labelEl = label;
    	    this._appendRequiredMark();

    	    const inputHolder = document.createElement('div');
    	    inputHolder.className = horizontal ? 'col-sm-9' : '';

    	    const control = this.renderInput();
    	    this.inputEl = control;
	    //CLD: 2026-10-05T21:39:55+03:00: named input, so the form can be submitted (FormData / app.pjax)
	    this._applyName(control);
    	    inputHolder.appendChild(control);

    	    const feedback = document.createElement('div');
    	    feedback.className = 'invalid-feedback';
    	    this.feedbackEl = feedback;
    	    inputHolder.appendChild(feedback);

    	    if (this.schema.helpText) {
    		const help = document.createElement('div');
    		help.className = 'form-text';
    		help.textContent = this.schema.helpText;
    		inputHolder.appendChild(help);
	    }

    	    if (this.label())
    		wrapper.appendChild(label);
    	    if (horizontal) {
    		wrapper.appendChild(inputHolder);
    	    } else {
    		// In vertical mode inputHolder has no special class; just unwrap its children.
    		while (inputHolder.firstChild)
    		    wrapper.appendChild(inputHolder.firstChild);
    	    }

    	    this._applyEditableState();
    	    return wrapper;
	}

	_appendRequiredMark() {
    	    if (!this.labelEl)
    		return;
    	    let mark = this.labelEl.querySelector('.text-danger');
    	    if (this._required) {
    		if (!mark) {
        	    mark = document.createElement('span');
        	    mark.className = 'text-danger ms-1';
        	    mark.textContent = '*';
        	    this.labelEl.appendChild(mark);
    		}
    	    } else if (mark) {
    		mark.remove();
    	    }
	}

	/** Subclasses must implement: build & return the actual <input>/<select>/etc. */
	renderInput() {
    	    throw new Error('renderInput() not implemented for field type: ' + this.schema.type);
	}

	/**
	 * Compact rendering used inside table cells (edit-grid rows): just the
	 * control + inline validation feedback, no label/help text. `this.el`
	 * is set to the returned holder so applyConditions()/validate() still work.
	 */
	renderBare() {
    	    const holder = document.createElement('div');
	    holder.className = 'position-relative';
    	    holder.dataset.key = this.key || '';

    	    const control = this.renderInput();
    	    control.id = this.id;
    	    this.inputEl = control;
    	    holder.appendChild(control);

    	    const feedback = document.createElement('div');
    	    feedback.className = 'invalid-feedback';
    	    this.feedbackEl = feedback;
    	    holder.appendChild(feedback);

    	    this.el = holder;
    	    this._hiddenDisplayClass = 'invisible'; // keep the table cell's width/alignment when hidden
    	    this._applyEditableState();
    	    return holder;
	}

	/** Called by the concrete input's event listener. */
	handleInput(value) {
    	    this.currentValue = value;
    	    this.clearInvalid();
    	    this.api.onChange(this.key, value);
	}

	getValue() {
    	    return this.currentValue;
	}

	//CLD: 2026-10-05T21:39:55+03:00: name the submitted control after the field key (`key[]` for multiple values)
	_applyName(control) {
    	    if (this.key && control)
    		control.name = this.schema.multiple ? this.key + '[]' : this.key;
	}

	setValue(value, silent) {
    	    this.currentValue = value;
    	    this._reflectValue();
    	    if (!silent)
    		this.api.onChange(this.key, value);
	}

	/** Push this.currentValue back into the DOM control. Override for checkboxes etc. */
	_reflectValue() {
    	    if (this.inputEl)
    		this.inputEl.value = this.currentValue === undefined || this.currentValue === null ? '' : this.currentValue;
	}

	_applyEditableState() {
    	    const disabled = !this.isEditable();
    	    if (this.inputEl) {
    		this.inputEl.disabled = disabled;
    		this.inputEl.classList.toggle('bg-light', disabled);
    	    }
	}

	/**
	 * Re-evaluate show-if / hide-if / required-if against the latest context
	 * and update the DOM (visibility, required flag/marker, native `required`).
	 */
	applyConditions(context) {
	    let visible = true;
    	    if (this.schema.showIf)
    		visible = evalExpr(this.schema.showIf, context, true);
    	    if (this.schema.hideIf && evalExpr(this.schema.hideIf, context, false))
    		visible = false;
    	    this._visible = visible;
    	    if (this.el)
    		this.el.classList.toggle(this._hiddenDisplayClass, !visible);
	    //CLD: 2026-10-24T12:48:19+03:00: its column too, in a row of narrow fields (the fields after it on the line move up; was an empty column left in place)
	    flowColVisible(this.el, visible);

    	    const required = !!this.schema.required || evalExpr(this.schema.requiredIf, context, false);
    	    this._required = required;
    	    this._appendRequiredMark();
    	    if (this.inputEl)
    		this.inputEl.required = required && visible;

    	    if (!visible && this.schema.clearValueOnHide) {
    		this.setValue(this.getEmptyValue());
    	    }
	}

	isVisible() {
    	    return this._visible;
	}

	setInvalid(message) {
	    if (!this.inputEl)
		return;
    	    this.inputEl.classList.add('is-invalid');
    	    if (this.feedbackEl)
    		this.feedbackEl.textContent = message || 'This field is invalid.';
	}

	clearInvalid() {
	    if (!this.inputEl)
		return;
    	    this.inputEl.classList.remove('is-invalid');
    	    if (this.feedbackEl)
    		this.feedbackEl.textContent = '';
	}

	/** Returns true if valid. Hidden fields are always considered valid. */
	validate(context) {
	    if (!this.isVisible()) {
		this.clearInvalid();
		return true;
	    }
	    const value = this.getValue();
	    const empty = value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0);
	    if (this._required && empty) {
		this.setInvalid('This field is required.');
		return false;
	    }
	    //CLD: 2026-10-14T14:54:31+03:00: the whole value must match (anchored, as the HTML pattern attribute and the server: util\FormSchema, the Validator's regex rule); was any part of it
	    if (this.schema.pattern && typeof value === 'string' && value !== '' && !new RegExp('^(?:' + this.schema.pattern + ')$').test(value)) {
		this.setInvalid(this.schema.patternMessage || 'Invalid format.');
		return false;
	    }
	    //CLD: 2026-10-20T14:07:31+03:00: the server's key / certificate rules (`rules`, util\SmartForm::modelFields), in order: the first failing one is the error
	    const failed = checkRules(this.schema.rules, value, context);
	    if (failed) {
		this.setInvalid(failed.message || 'Invalid format.');
		return false;
	    }
	    this.clearInvalid();
	    return true;
	}
    }


    class CheckboxField extends FieldBase {
	getEmptyValue() {
    	    return false;
	}

	/** Shared render for checkbox/switch: bootstrap `.form-check` block. */
	render() {
    	    const horizontal = this.direction() === 'horizontal';
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper mb-3' + (horizontal ? ' row' : '');
    	    wrapper.dataset.key = this.key || '';
    	    wrapper.dataset.type = this.schema.type || '';
    	    this.el = wrapper;

    	    const checkWrap = document.createElement('div');
    	    checkWrap.className = 'form-check' + (this._switchClass() ? ' ' + this._switchClass() : '');

    	    const input = document.createElement('input');
    	    input.type = 'checkbox';
    	    input.id = this.id;
    	    input.className = 'form-check-input';
    	    input.checked = !!this.currentValue;
	    //CLD: 2026-10-05T21:39:55+03:00: submitted as key=1 when checked (absent when unchecked)
	    //CLD: 2026-10-06T08:33:42+03:00: ...and key=0 when unchecked, through the hidden input added below
    	    input.value = '1';
    	    this._applyName(input);
    	    input.addEventListener('change', () => this.handleInput(input.checked));
    	    this.inputEl = input;

    	    const label = document.createElement('label');
    	    label.className = 'form-check-label';
    	    label.setAttribute('for', this.id);
    	    label.textContent = this.label();
    	    this.labelEl = label;
    	    this._appendRequiredMark();

	    //CLD: 2026-10-06T08:33:42+03:00: hidden "0" with the same name, before the checkbox: an unchecked box still submits key=0;
	    //a checked one also sends key=1, which wins (last value). "0" (not "false"): reads false both for the
	    //fields' boolean cast and for !empty() on the server
    	    if (input.name) {
    		const hidden = document.createElement('input');
    		hidden.type = 'hidden';
    		hidden.name = input.name;
    		hidden.value = '0';
    		this.hiddenEl = hidden;
    		checkWrap.appendChild(hidden);
    	    }

    	    checkWrap.appendChild(input);
    	    checkWrap.appendChild(label);

    	    const feedback = document.createElement('div');
    	    feedback.className = 'invalid-feedback';
    	    this.feedbackEl = feedback;
    	    checkWrap.appendChild(feedback);

    	    if (this.schema.helpText) {
    		const help = document.createElement('div');
    		help.className = 'form-text';
    		help.textContent = this.schema.helpText;
    		checkWrap.appendChild(help);
    	    }

    	    if (horizontal) {
    		const spacer = document.createElement('div');
    		spacer.className = 'col-sm-3';
    		const holder = document.createElement('div');
    		holder.className = 'col-sm-9';
    		holder.appendChild(checkWrap);
    		wrapper.appendChild(spacer);
    		wrapper.appendChild(holder);
    	    } else {
    		wrapper.appendChild(checkWrap);
    	    }

	    this._applyEditableState();
    	    return wrapper;
	}

	_switchClass() {
    	    return '';
	}

	//CLD: 2026-10-23T12:30:00+03:00: checked: the `propose` value for its field
	handleInput(value) {
	    super.handleInput(value);
	    const p = this.schema.propose;
	    if (!value || !p || !p.key || !this.api.setValue)
		return;
	    const current = (this.api.getContext().data || {})[p.key];
	    if (current === p.value)
		return;
	    if (current !== undefined && current !== null && String(current).trim() !== '' && !(p.confirm && window.confirm(p.confirm)))
		return;
	    this.api.setValue(p.key, p.value);
	}

	//CLD: 2026-10-06T08:33:42+03:00: a non-editable checkbox must not submit its hidden "0" either
	_applyEditableState() {
    	    super._applyEditableState();
    	    if (this.hiddenEl)
    		this.hiddenEl.disabled = !this.isEditable();
	}

	/** Compact table-cell rendering: bare checkbox/switch, centered, no label. */
	renderBare() {
    	    const holder = document.createElement('div');
    	    holder.className = 'position-relative d-flex justify-content-center';
    	    holder.dataset.key = this.key || '';

    	    const checkWrap = document.createElement('div');
    	    checkWrap.className = 'form-check' + (this._switchClass() ? ' ' + this._switchClass() + ' mb-0' : ' mb-0');

    	    const input = document.createElement('input');
    	    input.type = 'checkbox';
    	    input.id = this.id;
    	    input.className = 'form-check-input';
    	    input.checked = !!this.currentValue;
    	    input.addEventListener('change', () => this.handleInput(input.checked));
    	    this.inputEl = input;
    	    checkWrap.appendChild(input);
    	    holder.appendChild(checkWrap);

    	    const feedback = document.createElement('div');
    	    feedback.className = 'invalid-feedback text-center';
    	    this.feedbackEl = feedback;
    	    holder.appendChild(feedback);

    	    this.el = holder;
    	    this._hiddenDisplayClass = 'invisible';
    	    this._applyEditableState();
    	    return holder;
	}

	_reflectValue() {
    	    if (this.inputEl) this.inputEl.checked = !!this.currentValue;
	}

	validate(context) {
    	    if (!this.isVisible()) {
    		this.clearInvalid();
    		return true;
    	    }
    	    if (this._required && !this.getValue()) {
    		this.setInvalid('This must be checked.');
    		return false;
    	    }
    	    this.clearInvalid();
    	    return true;
	}
    }

    class SwitchField extends CheckboxField {
	_switchClass() {
	    return 'form-switch';
	}
    }

    class TextField extends FieldBase {
	renderInput() {
    	    const input = document.createElement('input');
    	    input.type = this.schema.inputType || 'text';
    	    input.id = this.id;
    	    input.className = this.sized('form-control'); //CLD: 2026-10-11T17:02:49+03:00: form-level `size`
    	    if (this.schema.placeholder)
    		input.placeholder = this.schema.placeholder;
    	    if (this.schema.maxLength)
    		input.maxLength = this.schema.maxLength;
    	    input.value = this.currentValue || '';
    	    input.addEventListener('input', () => this.handleInput(input.value));
	    this._expression(input);
    	    return input;
	}
    }

    //CLD: 2026-10-13T20:41:25+03:00: new field type `email` (the NetFlow9 field type sys\fields\Field_email): an e-mail input, checked as an address
    class EmailField extends TextField {
	renderInput() {
	    const input = super.renderInput();
	    input.type = 'email';
	    input.autocomplete = 'email';
	    return input;
	}

	validate(context) {
	    if (!super.validate(context))
		return false;
	    const value = String(this.getValue() ?? '').trim();
	    if (this.isVisible() && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
		this.setInvalid('Enter an e-mail address.');
		return false;
	    }
	    return true;
	}
    }

    //CLD: 2026-10-14T19:54:21+03:00: new field type `url` (the NetFlow9 field type sys\fields\Field_url): a web-address input, checked as an http:// or https:// address (the browser's URL parser)
    class UrlField extends TextField {
	renderInput() {
	    const input = super.renderInput();
	    input.type = 'url';
	    input.autocomplete = 'url';
	    return input;
	}

	validate(context) {
	    if (!super.validate(context))
		return false;
	    const value = String(this.getValue() ?? '').trim();
	    //CLD: 2026-10-14T19:59:06+03:00: a valid address, as the server's url rule (no http(s) restriction)
	    if (this.isVisible() && value !== '' && !URL.canParse(value)) {
		this.setInvalid('Enter a web address.');
		return false;
	    }
	    return true;
	}
    }

    //CLD: 2026-10-10T18:27:49+03:00: new field type `password`: a password input with a show/hide button (input-group); autocomplete new-password by default (a
    //saved login is never filled into e.g. a credential form); `stored`: a value is kept on the server (the input stays empty, empty = keep it)
    class PasswordField extends FieldBase {
	renderInput() {
	    const input = document.createElement('input');
	    input.type = 'password';
	    input.id = this.id;
	    input.className = this.sized('form-control'); //CLD: 2026-10-11T17:02:49+03:00: form-level `size`
	    input.autocomplete = this.schema.autocomplete || 'new-password';
	    input.spellcheck = false;
	    if (this.schema.placeholder)
		input.placeholder = this.schema.placeholder;
	    else if (this.schema.stored)
		input.placeholder = 'Stored; leave empty to keep it';
	    if (this.schema.maxLength)
		input.maxLength = this.schema.maxLength;
	    input.value = this.currentValue || '';
	    input.addEventListener('input', () => this.handleInput(input.value));
	    this._input = input;

	    if (this.schema.reveal === false)
		return input;

	    const group = document.createElement('div');
	    group.className = this.sized('input-group') + ' has-validation'; //CLD: 2026-10-11T17:02:49+03:00: form-level `size` (sizes the button too)
	    const btn = document.createElement('button');
	    btn.type = 'button';
	    btn.className = 'btn btn-outline-secondary';
	    btn.tabIndex = -1;
	    btn.title = 'Show';
	    btn.innerHTML = "<i class='bi bi-eye'></i>";
	    btn.addEventListener('click', () => {
		const show = input.type === 'password';
		input.type = show ? 'text' : 'password';
		btn.title = show ? 'Hide' : 'Show';
		btn.innerHTML = show ? "<i class='bi bi-eye-slash'></i>" : "<i class='bi bi-eye'></i>";
	    });
	    group.appendChild(input);
	    group.appendChild(btn);
	    this._group = group;
	    return group;
	}

	//the base renderers take renderInput()'s element as the input: with the button it is the group, so the input, its name, the
	//feedback (inside the group, for Bootstrap's has-validation) and the editable state are set again
	_fixGroup() {
	    if (!this._group)
		return;
	    this.inputEl = this._input;
	    this._group.removeAttribute('name');
	    this._group.removeAttribute('id');
	    this._group.removeAttribute('disabled');
	    this._group.classList.remove('bg-light');
	    this._input.id = this.id;
	    this._applyName(this._input);
	    if (this.feedbackEl)
		this._group.appendChild(this.feedbackEl);
	    this._applyEditableState();
	}

	render() {
	    const wrapper = super.render();
	    this._fixGroup();
	    return wrapper;
	}

	renderBare() {
	    const holder = super.renderBare();
	    this._fixGroup();
	    return holder;
	}

	_applyEditableState() {
	    super._applyEditableState();
	    if (this._group)
		this._group.querySelector('button').disabled = !this.isEditable();
	}
    }

    class TextareaField extends FieldBase {
	renderInput() {
    	    const input = document.createElement('textarea');
    	    input.id = this.id;
    	    input.className = this.sized('form-control'); //CLD: 2026-10-11T17:02:49+03:00: form-level `size`
    	    input.rows = this.schema.rows || 3;
    	    if (this.schema.placeholder)
    		input.placeholder = this.schema.placeholder;
	    //CLD: 2026-10-20T16:00:00+03:00: a write-only encrypted textarea (e.g. a private key): `stored` says a value is kept (empty = keep it); no spell check
	    else if (this.schema.stored)
		input.placeholder = 'Stored; leave empty to keep it';
	    if (this.schema.spellcheck === false)
		input.spellcheck = false;
    	    input.value = this.currentValue || '';
    	    input.addEventListener('input', () => this.handleInput(input.value));
	    //CLD: 2026-10-20T14:07:31+03:00: a pasted key / certificate is checked on leaving the field (not only on submit)
	    if (Array.isArray(this.schema.rules) && this.schema.rules.length)
		input.addEventListener('blur', () => this.validate(this.api.getContext()));
	    //CLD: 2026-10-13T15:33:17+03:00: `code` (e.g. 'j'): a code editor (app.CodeEditor: CodeMirror, with the server's live help in the field's expression syntax) in place of the textarea
	    //CLD: 2026-10-23T12:30:00+03:00: the syntax by syntaxBy (_syntax)
	    if (this.schema.code && window.app.CodeEditor && this.api.expression)
		this.codeEditor = new window.app.CodeEditor(input, Object.assign({}, this.api.expression, { syntax: this._syntax(this.api.getContext()) }));
	    else
		this._expression(input);
    	    return input;
	}

	//CLD: 2026-10-23T12:30:00+03:00: the code editor's syntax: the first of syntaxBy that holds, else the `expression` syntax (default script)
	_syntax(context) {
	    const by = (Array.isArray(this.schema.syntaxBy) ? this.schema.syntaxBy : []).find((s) => (Array.isArray(s.if) ? s.if : [ s.if ]).every((c) => evalExpr(c, context, false)));
	    return by ? by.syntax : (typeof this.schema.expression === 'string' ? this.schema.expression : 'script');
	}

	//CLD: 2026-10-23T12:30:00+03:00: the syntax follows the other fields (applyConditions runs on every change of the form)
	applyConditions(context) {
	    super.applyConditions(context);
	    if (this.codeEditor && this.schema.syntaxBy)
		this.codeEditor.setSyntax(this._syntax(context));
	}

	//CLD: 2026-10-23T12:30:00+03:00: a value set from outside (setData, `propose`) shown by the code editor too
	_reflectValue() {
	    super._reflectValue();
	    if (this.codeEditor)
		this.codeEditor.setValue(this.currentValue);
	}
    }

    class NumberField extends FieldBase {
	getEmptyValue() {
    	    return null;
	}

	renderInput() {
    	    const input = document.createElement('input');
    	    input.type = 'number';
    	    input.id = this.id;
    	    input.className = this.sized('form-control'); //CLD: 2026-10-11T17:02:49+03:00: form-level `size`
    	    if (this.schema.min !== undefined)
    		input.min = this.schema.min;
    	    if (this.schema.max !== undefined)
    		input.max = this.schema.max;
    	    input.step = this.schema.step !== undefined ? this.schema.step : 'any';
    	    if (this.schema.placeholder)
    		input.placeholder = this.schema.placeholder;
    	    input.value = this.currentValue === null || this.currentValue === undefined ? '' : this.currentValue;
    	    input.addEventListener('input', () => {
    		const raw = input.value;
    		this.handleInput(raw === '' ? null : Number(raw));
    	    });
    	    return input;
	}

	validate(context) {
    	    const baseValid = super.validate(context);
    	    if (!baseValid || !this.isVisible())
    		return baseValid;
    	    const value = this.getValue();
    	    if (value === null)
    		return true;
    	    if (this.schema.min !== undefined && value < this.schema.min) {
    		this.setInvalid('Must be at least ' + this.schema.min + '.');
    		return false;
    	    }
    	    if (this.schema.max !== undefined && value > this.schema.max) {
    		this.setInvalid('Must be at most ' + this.schema.max + '.');
    		return false;
    	    }
    	    return true;
	}
    }

    //CLD: 2026-10-05T22:32:08+03:00: uses app.DatePicker (when loaded) instead of the native date/datetime-local input; `format` = display
    //format (PHP date() tokens); the value (and what is submitted) stays 'Y-m-d' / 'Y-m-dTH:i' local time
    class DateField extends FieldBase {
	_isDateTime() {
    	    return this.schema.type === 'datetime' || this.schema.dateType === 'datetime';
	}

	renderInput() {
    	    const input = document.createElement('input');
    	    input.id = this.id;
    	    input.className = this.sized('form-control'); //CLD: 2026-10-11T17:02:49+03:00: form-level `size`

    	    if (window.app.DatePicker) {
    		//display only: the picker writes the value; typing would not reach it
    		input.type = 'text';
    		input.readOnly = true;
    		input.autocomplete = 'off';
    		if (this.schema.placeholder)
    		    input.placeholder = this.schema.placeholder;
    		return input;
    	    }

    	    //fallback: native input
    	    input.type = this._isDateTime() ? 'datetime-local' : 'date';
    	    if (this.schema.min) input.min = this.schema.min;
    	    if (this.schema.max) input.max = this.schema.max;
    	    input.value = this.currentValue || '';
    	    input.addEventListener('input', () => this.handleInput(input.value));
    	    return input;
	}

	//the picker needs the input inside its wrapper (it adds a hidden input and a popup next to it)
	render() {
    	    const el = super.render();
    	    this._enhance();
    	    return el;
	}

	renderBare() {
    	    const el = super.renderBare();
    	    this._enhance();
    	    return el;
	}

	_enhance() {
    	    if (!window.app.DatePicker || !this.inputEl)
    		return;

    	    const options = {
    		mode: this._isDateTime() ? 'datetime' : 'date',
    		//the hidden input holds 'Y-m-d' / 'Y-m-dTH:i'; empty after Clear
    		onChange: () => this.handleInput(this.datePicker.hiddenInput.value),
    	    };
    	    if (this.schema.format)
    		options.format = this.schema.format;
    	    if (this.schema.min)
    		options.min = parseLocalDate(this.schema.min);
    	    if (this.schema.max)
    		options.max = parseLocalDate(this.schema.max);

    	    this.datePicker = new window.app.DatePicker(this.inputEl, options);
    	    this.datePicker.setValue(this.currentValue || '');
    	    this._applyEditableState();
	}

	_reflectValue() {
    	    if (this.datePicker)
    		this.datePicker.setValue(this.currentValue || '');
    	    else
    		super._reflectValue();
	}

	//a non-editable field must not submit the picker's hidden input either
	_applyEditableState() {
    	    super._applyEditableState();
    	    if (this.datePicker)
    		this.datePicker.hiddenInput.disabled = !this.isEditable();
	}
    }

    //CLD: 2026-10-05T22:32:08+03:00: 'Y-m-d' or 'Y-m-dTH:i' as a local Date (new Date('Y-m-d') would be UTC midnight); null if not parsable
    function parseLocalDate(value) {
	if (value instanceof Date)
    	    return value;
	const m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
	return m ? new Date(+m[1], +m[2] - 1, +m[3], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0) : null;
    }

    class SelectField extends FieldBase {
	getEmptyValue() {
    	    return this.schema.multiple ? [] : '';
	}

	renderInput() {
    	    const select = document.createElement('select');
    	    select.id = this.id;
    	    select.className = this.sized('form-select'); //CLD: 2026-10-11T17:02:49+03:00: form-level `size` (SelectPlus copies form-select-sm)
    	    select.multiple = !!this.schema.multiple;

    	    if (!this.schema.multiple) {
    		const placeholderOpt = document.createElement('option');
    		placeholderOpt.value = '';
    		placeholderOpt.textContent = this.schema.placeholder || 'Select...';
    		placeholderOpt.disabled = !!this.schema.required;
    		select.appendChild(placeholderOpt);
    	    }

    	    normalizeChoices(this.schema.choices).forEach((choice) => {
    		const option = document.createElement('option');
    		option.value = choice.value;
    		option.textContent = choice.label;
    		select.appendChild(option);
    	    });

    	    this._reflectSelectValue(select);

    	    select.addEventListener('change', () => {
    		if (this.schema.multiple) {
        	    const values = Array.from(select.selectedOptions).map((o) => o.value);
        	    this.handleInput(values);
    		} else {
        	    this.handleInput(select.value);
    		}
    	    });

    	    return select;
	}

	//the native <select> must already be in its wrapper: SelectPlus inserts its widget right after it
	render() {
    	    const el = super.render();
    	    this._enhance();
    	    return el;
	}

	renderBare() {
    	    const el = super.renderBare();
    	    this._enhance();
    	    return el;
	}

	_enhance() {
    	    if (window.app.SelectPlus && this.inputEl)
    		//CLD: 2026-10-11T22:16:09+03:00: + ajaxUrl: the choices come from the server (search as you type)
    		//CLD: 2026-10-22T01:58:24+03:00: + allowCustom (create: a typed value becomes the value)
    		this.selectPlus = new window.app.SelectPlus(this.inputEl, { placeholder: this.schema.placeholder || null, ajax: this.schema.ajaxUrl ? { url: this.schema.ajaxUrl } : null,
    		    create: !!this.schema.allowCustom });
	}

	//CLD: 2026-10-22T01:58:24+03:00: choicesBy: the options of the other field's value, rebuilt when it changes (applyConditions runs on every change of the form)
	applyConditions(context) {
    	    super.applyConditions(context);
    	    const by = this.schema.choicesBy;
    	    if (!by || !by.key || !this.inputEl || this.schema.multiple)
    		return;
    	    const dep = String(((context || {}).data || {})[by.key] ?? '');
    	    if (this._choicesFor === dep)
    		return;
    	    //CLD: 2026-10-22T02:10:00+03:00: cleared only when switching from one value to another (was: after the first render, which runs before setData: the saved value was lost)
    	    const switched = !!this._choicesFor && dep !== '';
    	    this._choicesFor = dep;
    	    const list = normalizeChoices((by.choices || {})[dep] || []);
    	    let current = this.currentValue === undefined || this.currentValue === null ? '' : String(this.currentValue);
    	    //another credential: a value it does not have is cleared
    	    if (switched && current !== '' && !list.some((c) => c.value === current)) {
    		current = '';
    		this.currentValue = '';
    		this.api.onChange(this.key, '');
    	    }
    	    if (current !== '' && !list.some((c) => c.value === current))
    		list.unshift({ value: current, label: current });
    	    const select = this.inputEl;
    	    while (select.options.length)
    		select.remove(0);
    	    const placeholderOpt = document.createElement('option');
    	    placeholderOpt.value = '';
    	    placeholderOpt.textContent = (list.length ? '' : by.empty) || this.schema.placeholder || 'Select...';
    	    //SelectPlus shows its own placeholder (set when it was made)
    	    if (this.selectPlus && this.selectPlus.o)
    		this.selectPlus.o.placeholder = placeholderOpt.textContent;
    	    placeholderOpt.disabled = !!this.schema.required;
    	    select.appendChild(placeholderOpt);
    	    list.forEach((c) => {
    		const option = document.createElement('option');
    		option.value = c.value;
    		option.textContent = c.label;
    		select.appendChild(option);
    	    });
    	    this._reflectValue();
	}

	_reflectSelectValue(select) {
    	    const el = select || this.inputEl;
    	    if (!el)
    		return;
    	    if (this.schema.multiple) {
    		const values = Array.isArray(this.currentValue) ? this.currentValue.map(String) : [];
    		Array.from(el.options).forEach((o) => { o.selected = values.includes(o.value); });
    	    } else {
    		el.value = this.currentValue || '';
    	    }
	}

	_reflectValue() {
    	    this._reflectSelectValue();
    	    if (this.selectPlus)
    		this.selectPlus.refresh();
	}
    }

    /**
     * Layout helper: splits a set of fields into N Bootstrap columns.
     * Schema: { "type": "columns", "columns": [ { "width": 6, "fields": [...] }, ... ] }
     * Not a data-bearing field itself - it just arranges child field/layout nodes.
     */
    class ColumnsLayout {
	constructor(schema, formRenderer) {
    	    this.schema = schema;
    	    this.formRenderer = formRenderer;
	}

	render() {
    	    const row = document.createElement('div');
    	    row.className = 'row g-3 mb-1';
    	    (this.schema.columns || []).forEach((colSchema) => {
    		const colEl = document.createElement('div');
    		colEl.className = 'col-12 col-md-' + (colSchema.width || Math.floor(12 / (this.schema.columns.length || 1)));
    		this.formRenderer._buildNodes(colSchema.fields || [], colEl);
    		row.appendChild(colEl);
    	    });
    	    return row;
	}

	// Layout nodes are transparent to visibility/validation/data - nothing to do here.
	applyConditions() {
	}
	validate() {
    	    return true;
	}
	isVisible() {
    	    return true;
	}
    }

    /*!
     * field-editgrid.js
     * EditGridField renders a repeatable set of rows, each row built from the
     * same `components` schema (a mini form). Supports:
     *   - adding new rows
     *   - deleting rows
     *   - locking/unlocking a row to make it read-only ("read-only rows")
     *   - a grid-level `readOnly` flag that freezes the whole grid (view mode)
     *
     * Schema:
     * {
     *   "type": "editgrid",
     *   "key": "contacts",
     *   "label": "Contacts",
     *   "components": [ {field schema}, {field schema}, ... ],  // per-row fields
     *   "readOnly": false,             // whole grid is view-only, no add/remove/lock
     *   "allowLock": true,             // show per-row lock/unlock ("Edit") toggle
     *   "allowDeleteReadOnlyRows": false,
     *   "minRows": 0,
     *   "maxRows": null,
     *   "addLabel": "Add row",
     *   "compact": false,              // narrow # and actions columns, no "Actions" heading (e.g. a narrow panel)
     *   "layout": "table",             // CLD: 2026-10-13T09:52:24+03:00: "rows": as the data grid filter: one line of fields per row (no headings, the
     *                                  //   placeholders say what they are), an x to remove it, a green + below to add one; "emptyText", the help under the rows
     *   "default": [ { ...rowData, "__readOnly": true }, ... ]
     * }
     *
     * Requires form-renderer.js to be loaded first.
     */
    //  const { createField, evalExpr } = global.__FormRendererInternal;

    class EditGridField extends FieldBase {
	getEmptyValue() {
    	    return [];
	}

	constructor(schema, api) {
    	    super(schema, api);
    	    this.rows = [];
    	    this.tbodyEl = null;
    	    const initial = Array.isArray(this.currentValue) ? this.currentValue : [];
    	    initial.forEach((rowData) => this._pushRow(rowData));
    	    this.currentValue = this.getValue();
	}

	_pushRow(rowData) {
    	    rowData = rowData || {};
    	    const readOnly = !!rowData.__readOnly;
    	    const data = {};
    	    (this.schema.components || []).forEach((c) => {
    		data[c.key] = rowData[c.key] !== undefined ? rowData[c.key] : (c.default !== undefined ? c.default : '');
    	    });
    	    this.rows.push({ id: app.utils.uid('row'), data, readOnly });
	}

	isGridReadOnly() {
    	    return this.schema.readOnly === true || !this.isEditable();
        }

	//allowLock() {
    	//    return this.schema.allowLock !== false;
	//}

	//allowDeleteReadOnlyRows() {
    	//    return this.schema.allowDeleteReadOnlyRows === true;
	//}

	// ---- Custom top-level rendering (no single <input>, so we skip FieldBase.render) ----
	render() {
    	    if (this.schema.layout === 'rows')
    		return this._renderRowsLayout();
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper edit-grid mb-4';
    	    wrapper.dataset.key = this.key || '';
    	    wrapper.dataset.type = 'editgrid';
    	    this.el = wrapper;

    	    const header = document.createElement('div');
    	    header.className = 'd-flex justify-content-between align-items-center mb-2';

    	    const label = document.createElement('span');
    	    label.className = 'fw-semibold';
    	    label.textContent = this.label();
    	    this.labelEl = label;
    	    this._appendRequiredMark();
    	    header.appendChild(label);

    	    if (!this.isGridReadOnly()) {
    		const addBtn = document.createElement('button');
    		addBtn.type = 'button';
    		addBtn.className = 'btn btn-sm btn-outline-primary';
    		addBtn.textContent = this.schema.addLabel || '+ Add row';
    		addBtn.addEventListener('click', () => this.addRow());
    		this.addBtnEl = addBtn;
    		header.appendChild(addBtn);
    	    }

    	    wrapper.appendChild(header);

    	    this.feedbackEl = document.createElement('div');
    	    this.feedbackEl.className = 'text-danger small mb-2 d-none';
    	    wrapper.appendChild(this.feedbackEl);

    	    const tableResponsive = document.createElement('div');
    	    tableResponsive.className = 'table-responsive';

    	    const table = document.createElement('table');
    	    table.className = 'table table-sm table-bordered align-middle mb-0 edit-grid-table';

    	    const thead = document.createElement('thead');
    	    thead.appendChild(this._buildHeaderRow());
    	    table.appendChild(thead);

    	    this.tbodyEl = document.createElement('tbody');
    	    table.appendChild(this.tbodyEl);

    	    tableResponsive.appendChild(table);
    	    wrapper.appendChild(tableResponsive);

    	    this.renderRows();
    	    return wrapper;
	}

	//CLD: 2026-10-13T09:52:24+03:00: layout `rows` (as the data grid filter): label, the rows, the help, a green + at the right
	_renderRowsLayout() {
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper edit-grid edit-grid-rows mb-3';
    	    wrapper.dataset.key = this.key || '';
    	    wrapper.dataset.type = 'editgrid';
    	    this.el = wrapper;

    	    if (this.label()) {
    		const label = document.createElement('div');
    		label.className = 'form-label';
    		label.textContent = this.label();
    		this.labelEl = label;
    		this._appendRequiredMark();
    		wrapper.appendChild(label);
    	    }

    	    this.feedbackEl = document.createElement('div');
    	    this.feedbackEl.className = 'text-danger small mb-2 d-none';
    	    wrapper.appendChild(this.feedbackEl);

    	    //renderRows / setValue fill it as the tbody of the table layout
    	    this.tbodyEl = document.createElement('div');
    	    this.tbodyEl.className = 'edit-grid-row-list';
    	    wrapper.appendChild(this.tbodyEl);

    	    const footer = document.createElement('div');
    	    footer.className = 'd-flex align-items-start gap-2';
    	    const help = document.createElement('div');
    	    help.className = 'form-text mt-0 flex-fill';
    	    help.textContent = this.schema.helpText || '';
    	    footer.appendChild(help);
    	    if (!this.isGridReadOnly()) {
    		const addBtn = document.createElement('button');
    		addBtn.type = 'button';
    		addBtn.className = 'btn btn-sm btn-success flex-shrink-0';
    		addBtn.title = this.schema.addLabel || 'Add row';
    		addBtn.setAttribute('aria-label', addBtn.title);
    		addBtn.innerHTML = '<i class="bi bi-plus"></i>';
    		addBtn.addEventListener('click', () => this.addRow());
    		this.addBtnEl = addBtn;
    		footer.appendChild(addBtn);
    	    }
    	    wrapper.appendChild(footer);

    	    this.renderRows();
    	    return wrapper;
	}

	_buildHeaderRow() {
    	    const tr = document.createElement('tr');
    	    const indexTh = document.createElement('th');
    	    indexTh.className = 'text-muted small fw-normal';
    	    //CLD: 2026-10-11T20:24:33+03:00: compact: narrow columns (# and actions)
    	    indexTh.style.width = this.schema.compact ? '1.5rem' : '3rem';
    	    indexTh.textContent = '#';
    	    tr.appendChild(indexTh);

    	    (this.schema.components || []).forEach((compSchema) => {
    		const th = document.createElement('th');
    		th.className = 'small';
    		th.textContent = compSchema.label || compSchema.key || '';
    		if (compSchema.required) {
        	    const mark = document.createElement('span');
        	    mark.className = 'text-danger ms-1';
        	    mark.textContent = '*';
        	    th.appendChild(mark);
    		}
    		if (compSchema.width)
    		    th.style.width = (compSchema.width / 12 * 100) + '%';
    		tr.appendChild(th);
    	    });

    	    const actionsTh = document.createElement('th');
    	    actionsTh.className = 'text-end small fw-normal';
    	    actionsTh.style.width = this.schema.compact ? '2.5rem' : '9rem';
    	    actionsTh.textContent = this.schema.compact ? '' : 'Actions';
    	    tr.appendChild(actionsTh);
    	    return tr;
	}

	renderRows() {
    	    const tbody = this.tbodyEl;
    	    tbody.innerHTML = '';

    	    //CLD: 2026-10-13T09:52:24+03:00: layout `rows`: a line of text when there are none
    	    if (this.rows.length === 0 && this.schema.layout === 'rows') {
    		const hint = document.createElement('div');
    		hint.className = 'text-muted small text-center p-2';
    		hint.textContent = this.schema.emptyText || 'No entries yet.';
    		tbody.appendChild(hint);
    		this._updateAddButtonState();
    		return;
    	    }
    	    if (this.rows.length === 0) {
    		const tr = document.createElement('tr');
    		const td = document.createElement('td');
    		td.colSpan = (this.schema.components || []).length + 2;
    		td.className = 'text-muted small fst-italic text-center py-3';
    		td.textContent = 'No entries yet.';
    		tr.appendChild(td);
    		tbody.appendChild(tr);
    		return;
    	    }

    	    this.rows.forEach((row, index) => tbody.appendChild(this._buildRowElement(row, index)));
    	    this._updateAddButtonState();
	}

	_buildRowElement(row, index) {
    	    if (this.schema.layout === 'rows')
    		return this._buildFlatRow(row);
    	    const tr = document.createElement('tr');
    	    if (row.readOnly)
    		tr.className = 'table-light';

    	    const indexTd = document.createElement('td');
    	    indexTd.className = 'text-muted small';
    	    indexTd.textContent = String(index + 1);

    	    //if (row.readOnly) {
    	    //	const badge = document.createElement('span');
    	    //	badge.className = 'badge text-bg-secondary ms-1';
    	    //	badge.textContent = 'RO';
    	    //	badge.title = 'Read-only';
    	    //	indexTd.appendChild(badge);
    	    //}
    	    tr.appendChild(indexTd);

    	    row.fieldInstances = {};
    	    const rowApi = this._buildRowApi(row);
    	    row.api = rowApi;

    	    (this.schema.components || []).forEach((compSchema) => {
    		const td = document.createElement('td');
    		const fieldInstance = createField(compSchema, rowApi);
    		fieldInstance.setValue(row.data[compSchema.key], true);
    		if (this.isGridReadOnly() || row.readOnly) fieldInstance._forceDisabled = true;
    		const cell = fieldInstance.renderBare();
    		fieldInstance._applyEditableState();
    		fieldInstance.applyConditions(rowApi.getContext());
    		row.fieldInstances[compSchema.key] = fieldInstance;
    		td.appendChild(cell);
    		tr.appendChild(td);
    	    });

    	    const actionsTd = document.createElement('td');
    	    actionsTd.className = 'text-end';
    	    const actions = document.createElement('div');
    	    actions.className = 'btn-group btn-group-sm';

    	    //if (!this.isGridReadOnly() && this.allowLock()) {
    	    //	const lockBtn = document.createElement('button');
    	    //	lockBtn.type = 'button';
    	    //	lockBtn.className = 'btn btn-outline-secondary';
    	    //	lockBtn.textContent = row.readOnly ? 'Edit' : 'Lock';
    	    //	lockBtn.addEventListener('click', () => this.toggleLock(row.id));
    	    //	actions.appendChild(lockBtn);
    	    //}

    	    //const canDelete = !this.isGridReadOnly() && (!row.readOnly || this.allowDeleteReadOnlyRows());
    	    const canDelete = !this.isGridReadOnly() && !row.readOnly;
    	    if (canDelete) {
    		const delBtn = document.createElement('button');
    		delBtn.type = 'button';
    		delBtn.className = 'btn btn-sm btn-outline-danger';
    		//delBtn.textContent = 'Remove';
    		const delIcon = document.createElement('i');
    		delIcon.className = 'bi bi-trash';
    		delBtn.appendChild(delIcon);
    		delBtn.addEventListener('click', () => this.removeRow(row.id));
    		actions.appendChild(delBtn);
    	    }

    	    actionsTd.appendChild(actions);
    	    tr.appendChild(actionsTd);
    	    return tr;
	}

	//CLD: 2026-10-13T09:52:24+03:00: layout `rows`: one line: the fields (sized by their width, 1-12) and an x (disabled at minRows)
	_buildFlatRow(row) {
    	    const line = document.createElement('div');
    	    line.className = 'edit-grid-row d-flex align-items-start gap-2 mb-1';

    	    row.fieldInstances = {};
    	    const rowApi = this._buildRowApi(row);
    	    row.api = rowApi;

    	    (this.schema.components || []).forEach((compSchema) => {
    		const cell = document.createElement('div');
    		cell.style.flex = (compSchema.width || 1) + ' 1 0';
    		cell.style.minWidth = '0';
    		const fieldInstance = createField(compSchema, rowApi);
    		fieldInstance.setValue(row.data[compSchema.key], true);
    		if (this.isGridReadOnly() || row.readOnly) fieldInstance._forceDisabled = true;
    		cell.appendChild(fieldInstance.renderBare());
    		fieldInstance._applyEditableState();
    		fieldInstance.applyConditions(rowApi.getContext());
    		row.fieldInstances[compSchema.key] = fieldInstance;
    		line.appendChild(cell);
    	    });

    	    if (!this.isGridReadOnly() && !row.readOnly) {
    		const removeBtn = document.createElement('button');
    		removeBtn.type = 'button';
    		removeBtn.className = 'btn btn-sm btn-link flex-shrink-0';
    		removeBtn.title = 'Remove';
    		removeBtn.setAttribute('aria-label', 'Remove');
    		removeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    		removeBtn.disabled = !!(this.schema.minRows && this.rows.length <= this.schema.minRows);
    		removeBtn.addEventListener('click', () => this.removeRow(row.id));
    		line.appendChild(removeBtn);
    	    }
    	    return line;
	}

	/** Re-run show-if/hide-if/required-if for every field within one row (siblings can depend on each other). */
	_recomputeRowConditions(row) {
    	    if (!row.fieldInstances || !row.api)
    		return;
    	    const context = row.api.getContext();
    	    Object.keys(row.fieldInstances).forEach((key) => {
    		row.fieldInstances[key].applyConditions(context);
    	    });
	}

	_buildRowApi(row) {
    	    return {
    		direction: this.schema.rowDirection || 'vertical',
		//CLD: 2026-10-11T17:02:49+03:00: the form's size, also in the rows
		size: this.api.size,
		//CLD: 2026-10-11T22:38:36+03:00: the form's expression help, also in the rows
		expression: this.api.expression,
    		onChange: (key, value) => {
        	    row.data[key] = value;
        	    this._commit();
        	    this._recomputeRowConditions(row);
    		},
    		getContext: () => ({
        	    data: this.api.getContext().data,
        	    row: row.data,
        	    index: this.rows.indexOf(row),
    		}),
    	    };
	}

	addRow() {
    	    if (this.schema.maxRows && this.rows.length >= this.schema.maxRows)
    		return;
	    this._pushRow({});
    	    this._commit();
    	    this.renderRows();
	}

	removeRow(rowId) {
    	    if (this.schema.minRows && this.rows.length <= this.schema.minRows)
    		return;
    	    const row = this.rows.find((r) => r.id === rowId);
    	    if (row && row.readOnly && !this.allowDeleteReadOnlyRows())
    		return; // read-only rows are protected
    	    this.rows = this.rows.filter((r) => r.id !== rowId);
    	    this._commit();
    	    this.renderRows();
	}

	//toggleLock(rowId) {
    	//    const row = this.rows.find((r) => r.id === rowId);
    	//    if (row)
    	//	row.readOnly = !row.readOnly;
    	//    this.renderRows();
	//}

	_updateAddButtonState() {
    	    if (this.addBtnEl) {
    		const atMax = this.schema.maxRows && this.rows.length >= this.schema.maxRows;
    	        this.addBtnEl.disabled = !!atMax;
    	    }
	}

	_commit() {
    	    this.currentValue = this.getValue();
    	    this.api.onChange(this.key, this.currentValue);
	}

	getValue() {
    	    return this.rows.map((r) => Object.assign({}, r.data, r.readOnly ? { __readOnly: true } : {}));
	}

	setValue(value, silent) {
    	    this.rows = [];
    	    (Array.isArray(value) ? value : []).forEach((rowData) => this._pushRow(rowData));
    	    this.currentValue = this.getValue();
    	    if (this.tbodyEl)
    		this.renderRows();
    	    if (!silent)
    		this.api.onChange(this.key, this.currentValue);
	}

	applyConditions(context) {
    	    // Edit grids support the same show/hide/required-if semantics as any field.
    	    let visible = true;
    	    if (this.schema.showIf) visible = evalExpr(this.schema.showIf, context, true);
    	    if (this.schema.hideIf && evalExpr(this.schema.hideIf, context, false)) visible = false;
    	    this._visible = visible;
    	    if (this.el) this.el.classList.toggle('d-none', !visible);
	    flowColVisible(this.el, visible);
	}

	validate() {
    	    if (!this.isVisible()) return true;
    	    let valid = true;
    	    if (this.schema.minRows && this.rows.length < this.schema.minRows) {
    		valid = false;
    		this.feedbackEl.textContent = 'At least ' + this.schema.minRows + ' row(s) required.';
    		this.feedbackEl.classList.remove('d-none');
    	    } else {
    		this.feedbackEl.classList.add('d-none');
    	    }

    	    // Validate using the live field instances backing each rendered row, so
    	    // any invalid-state styling (is-invalid / feedback text) shows up in place.
    	    this.rows.forEach((row) => {
    		if (!row.fieldInstances) return;
    		Object.keys(row.fieldInstances).forEach((key) => {
        	    const fieldInstance = row.fieldInstances[key];
        	    if (!fieldInstance.validate(row.api.getContext())) valid = false;
    		});
    	    });

    	    return valid;
	}
    }

    //CLD: 2026-10-18T10:20:00+03:00: one nested object (sys\fields\Field_nested, not multiple; a list of them is an editgrid): the fields of `components` in a group,
    //the value an object { key: value }; its fields' conditions see the object as `row` (scope 'row'), the form's data as `data` (as an editgrid row)
    class NestedField extends FieldBase {
	getEmptyValue() {
    	    return {};
	}

	constructor(schema, api) {
    	    super(schema, api);
    	    this.fieldInstances = {};
    	    this.bodyEl = null;
    	    this.data = this._withDefaults(this.currentValue);
    	    this.currentValue = this.getValue();
	}

	//the components' values from $value, else their defaults (as an editgrid row)
	_withDefaults(value) {
    	    value = (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
    	    const data = {};
    	    (this.schema.components || []).forEach((c) => {
    		data[c.key] = value[c.key] !== undefined ? value[c.key] : (c.default !== undefined ? c.default : '');
    	    });
    	    return data;
	}

	_buildApi() {
    	    return {
    		direction: this.schema.rowDirection || this.direction(),
    		size: this.api.size,
    		expression: this.api.expression,
    		onChange: (key, value) => {
        	    this.data[key] = value;
        	    this._commit();
        	    this._recomputeConditions();
    		},
    		getContext: () => ({ data: this.api.getContext().data, row: this.data, index: null }),
    	    };
	}

	render() {
    	    const wrapper = document.createElement('fieldset');
    	    wrapper.className = 'field-wrapper nested-field border rounded px-3 pt-2 mb-3';
    	    wrapper.dataset.key = this.key || '';
    	    wrapper.dataset.type = 'nested';
    	    this.el = wrapper;

    	    if (this.label()) {
    		const legend = document.createElement('legend');
    		legend.className = 'form-label fs-6 fw-semibold float-none w-auto px-1 mb-1';
    		legend.textContent = this.label();
    		this.labelEl = legend;
    		this._appendRequiredMark();
    		wrapper.appendChild(legend);
    	    }
    	    if (this.schema.helpText) {
    		const help = document.createElement('div');
    		help.className = 'form-text mt-0 mb-2';
    		help.textContent = this.schema.helpText;
    		wrapper.appendChild(help);
    	    }

    	    this.bodyEl = document.createElement('div');
    	    this.bodyEl.className = 'row';
    	    wrapper.appendChild(this.bodyEl);
    	    this._renderFields();
    	    return wrapper;
	}

	//inside an editgrid cell: the same group
	renderBare() {
    	    return this.render();
	}

	//the components, sized by their width (1-12)
	_renderFields() {
    	    this.bodyEl.innerHTML = '';
    	    this.fieldInstances = {};
    	    this.childApi = this._buildApi();
    	    (this.schema.components || []).forEach((c) => {
    		const fieldInstance = createField(c, this.childApi);
    		fieldInstance.setValue(this.data[c.key], true);
    		if (!this.isEditable())
    		    fieldInstance._forceDisabled = true;
    		const col = document.createElement('div');
    		col.className = 'col-12 col-md-' + (c.width && c.width >= 1 && c.width <= 12 ? c.width : 12);
    		col.appendChild(fieldInstance.render());
    		fieldInstance._applyEditableState();
    		fieldInstance.applyConditions(this.childApi.getContext());
    		this.fieldInstances[c.key] = fieldInstance;
    		this.bodyEl.appendChild(col);
    	    });
	}

	_recomputeConditions() {
    	    if (!this.childApi)
    		return;
    	    const context = this.childApi.getContext();
    	    Object.keys(this.fieldInstances).forEach((key) => this.fieldInstances[key].applyConditions(context));
	}

	_commit() {
    	    this.currentValue = this.getValue();
    	    this.api.onChange(this.key, this.currentValue);
	}

	getValue() {
    	    return Object.assign({}, this.data);
	}

	setValue(value, silent) {
    	    this.data = this._withDefaults(value);
    	    this.currentValue = this.getValue();
    	    if (this.bodyEl)
    		this._renderFields();
    	    if (!silent)
    		this.api.onChange(this.key, this.currentValue);
	}

	//its own show / hide, then its fields' conditions (they may read the form's data)
	applyConditions(context) {
    	    let visible = true;
    	    if (this.schema.showIf) visible = evalExpr(this.schema.showIf, context, true);
    	    if (this.schema.hideIf && evalExpr(this.schema.hideIf, context, false)) visible = false;
    	    this._visible = visible;
    	    if (this.el) this.el.classList.toggle('d-none', !visible);
	    flowColVisible(this.el, visible);
    	    this._recomputeConditions();
	}

	validate() {
    	    if (!this.isVisible())
    		return true;
    	    let valid = true;
    	    Object.keys(this.fieldInstances).forEach((key) => {
    		if (!this.fieldInstances[key].validate(this.childApi.getContext()))
    		    valid = false;
    	    });
    	    return valid;
	}
    }

    class HiddenField extends FieldBase {
	getEmptyValue() {
    	    return '';
	}

	/** No label, no wrapper chrome - just a hidden input carrying the value. */
	render() {
    	    const input = document.createElement('input');
    	    input.type = 'hidden';
    	    input.id = this.id;
    	    input.dataset.key = this.key || '';
	    //CLD: 2026-10-05T21:39:55+03:00: submitted with the form
    	    this._applyName(input);
    	    input.value = this.currentValue === undefined || this.currentValue === null ? '' : this.currentValue;
    	    this.inputEl = input;
    	    this.el = input;
    	    return input;
	}

	_reflectValue() {
    	    if (this.inputEl)
    		this.inputEl.value = this.currentValue === undefined || this.currentValue === null ? '' : this.currentValue;
	}

	_applyEditableState() {
    	    // nothing to disable - there's no interactive control
	}

	/** Hidden fields carry data but aren't shown, so they're never validated. */
	validate() {
    	    return true;
	}
    }

    class TitleField extends FieldBase {
	getEmptyValue() {
    	    return undefined;
	}

	label() {
    	    return this.schema.text || this.schema.label || '';
	}

	render() {
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper title-block mb-3';
    	    wrapper.dataset.type = 'title';
    	    this.el = wrapper;

    	    const level = /^h[1-6]$/.test(this.schema.level || '') ? this.schema.level : 'h5';
    	    const heading = document.createElement(level);
    	    heading.className = this.schema.className || 'mb-1';
    	    heading.textContent = this.label();
    	    wrapper.appendChild(heading);

    	    if (this.schema.subtitle) {
    		const subtitle = document.createElement('div');
    		subtitle.className = 'text-muted small mb-2';
    		subtitle.textContent = this.schema.subtitle;
    		wrapper.appendChild(subtitle);
    	    }

    	    if (this.schema.divider) {
    		wrapper.appendChild(document.createElement('hr'));
    	    }

    	    return wrapper;
	}

	/** Titles support show-if/hide-if but have no required/requiredIf concept. */
	applyConditions(context) {
    	    let visible = true;
    	    if (this.schema.showIf) visible = evalExpr(this.schema.showIf, context, true);
    	    if (this.schema.hideIf && evalExpr(this.schema.hideIf, context, false)) visible = false;
    	    this._visible = visible;
    	    if (this.el) this.el.classList.toggle('d-none', !visible);
	    flowColVisible(this.el, visible);
	}

	getValue() {
    	    return undefined;
	}

	setValue() {
    	    // no-op: a title never holds data
	}

	validate() {
    	    return true;
	}
    }



    //CLD: 2026-10-13T18:20:23+03:00: design nodes: a paragraph of text (textContent; line breaks kept) and a divider line; no data, conditions apply (as TitleField)
    class ParagraphField extends TitleField {
	render() {
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper paragraph-block mb-3';
    	    wrapper.dataset.type = 'paragraph';
    	    wrapper.style.whiteSpace = 'pre-line';
    	    wrapper.textContent = this.schema.text || '';
    	    this.el = wrapper;
    	    return wrapper;
	}
    }

    class DividerField extends TitleField {
	render() {
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper divider-block';
    	    wrapper.dataset.type = 'divider';
    	    wrapper.appendChild(document.createElement('hr'));
    	    this.el = wrapper;
    	    return wrapper;
	}
    }

    //CLD: 2026-10-13T18:20:23+03:00: a form designer (app.FormDesigner): the value is a form schema { direction, fields }; a hidden input carries it as JSON when the form is submitted
    //CLD: 2026-10-16T12:01:48+03:00: a designer field (renamed from FormDesignerField): `formdesigner` (app.FormDesigner, the form of the Form / Human task steps) or `emaildesigner`
    //(app.EmailDesigner, an email of blocks: the Send Email step); the designer edits the value (a hidden input holds it as JSON)
    class DesignerField extends FieldBase {
	get isEmail() {
	    return this.schema.type === 'emaildesigner';
	}

	getEmptyValue() {
    	    return this.isEmail ? { preheader: '', blocks: [] } : { direction: 'vertical', fields: [] };
	}

	render() {
    	    const wrapper = document.createElement('div');
    	    wrapper.className = 'field-wrapper mb-3';
    	    wrapper.dataset.key = this.key || '';
    	    wrapper.dataset.type = this.schema.type;
    	    this.el = wrapper;
    	    if (this.label()) {
    		const label = document.createElement('div');
    		label.className = 'form-label';
    		label.textContent = this.label();
    		this.labelEl = label;
    		this._appendRequiredMark();
    		wrapper.appendChild(label);
    	    }
    	    const hidden = document.createElement('input');
    	    hidden.type = 'hidden';
    	    this._applyName(hidden);
    	    this.inputEl = hidden;
    	    wrapper.appendChild(hidden);
    	    const holder = document.createElement('div');
    	    wrapper.appendChild(holder);
    	    this.feedbackEl = document.createElement('div');
    	    this.feedbackEl.className = 'invalid-feedback d-block';
    	    wrapper.appendChild(this.feedbackEl);
    	    if (this.schema.helpText) {
    		const help = document.createElement('div');
    		help.className = 'form-text';
    		help.textContent = this.schema.helpText;
    		wrapper.appendChild(help);
    	    }
    	    const Designer = this.isEmail ? window.app.EmailDesigner : window.app.FormDesigner;
    	    this.designer = new Designer(holder, {
    		value: this.currentValue,
    		readonly: !this.isEditable(),
		//CLD: 2026-10-14T20:17:14+03:00: the operators of each field type, for conditions (util\SmartForm::modelFields)
		operators: this.schema.operators || {},
		//CLD: 2026-10-16T12:01:48+03:00: the email designer's "Insert variable": the expression help of the form (the step's variables)
		expression: this.api.expression || null,
    		onChange: (value) => {
    		    this.handleInput(value);
    		    this.inputEl.value = JSON.stringify(value);
    		},
    	    });
    	    this._reflectValue();
    	    return wrapper;
	}

	_reflectValue() {
    	    if (this.inputEl)
    		this.inputEl.value = JSON.stringify(this.currentValue || this.getEmptyValue());
    	    if (this.designer && this.designer.getValue() !== this.currentValue)
    		this.designer.setValue(this.currentValue);
	}

	_applyEditableState() {
	}

	validate() {
    	    if (!this.isVisible())
    		return true;
    	    const problem = this.designer ? this.designer.problem() : null;
    	    const list = this.currentValue && (this.isEmail ? this.currentValue.blocks : this.currentValue.fields);
    	    const empty = !(Array.isArray(list) && list.length);
    	    const message = problem || (this._required && empty ? (this.isEmail ? 'Add at least one block to the email.' : 'Add at least one field to the form.') : '');
    	    this.feedbackEl.textContent = message;
    	    return !message;
	}
    }

    /*!
     * FormRenderer - the orchestrator. Ties together FieldBase subclasses and
     * the "columns" layout helper to render a JSON schema into a live Bootstrap 5
     * form, and manages the resulting data object.
     */
    class SmartForm {
	/**
	 * @param {HTMLElement} container - element the form will be rendered into
         * @param {object} schema - { direction, fields: [...] }
	 */
	constructor(container, schema) {
    	    if (typeof container === 'string')
    		container = document.querySelector(container);
    	    if (container._smartForm)
    		return container._smartForm;

    	    this.container = container;
    	    this.schema = schema || { fields: [] };
    	    this.direction = this.schema.direction === 'horizontal' ? 'horizontal' : 'vertical';
    	    this.data = {};
    	    this.fields = []; // flat list of every top-level field instance (for recompute/validate)
    	    this.formEl = null;

	    container._smartForm = this;
	}

	/** Build the root <form> element, walk the schema, and mount it into the container. */
	render() {
    	    this.container.innerHTML = '';
    	    this.fields = [];
    	    this.data = {};

    	    const form = document.createElement('form');
    	    form.className = 'form-renderer-root needs-validation';
    	    form.noValidate = true;
    	    form.id = this.schema.id || window.app.utils.uid('form');
    	    form.action = this.schema.action || '';
	    //CLD: 2026-10-05T21:39:55+03:00: method attribute (app.pjax reads it), and no submit of an invalid form: an invalid submit stops here,
	    //a valid one bubbles on to app.pjax's global submit handler (or submits natively without app.pjax)
    	    form.setAttribute('method', this.schema.method || 'post');
    	    form.addEventListener('submit', (e) => {
    		if (!this.validate()) {
    		    e.preventDefault();
    		    e.stopPropagation();
    		}
    	    });
    	    this.formEl = form;

    	    this._buildNodes(this.schema.fields || [], form);
    	    this.container.appendChild(form);

    	    // seed this.data with every field's initial value, then run conditions once.
    	    this._collectInitialData(this.fields);
    	    this.recomputeVisibility();
    	    return form;
	}

	/**
	 * Recursively turn schema nodes into DOM, registering real fields (and
	 * their data) as we go. `columns` nodes recurse without creating a field.
	 */
	_buildNodes(nodeList, targetEl) {
	    /** Wrap an element in a Bootstrap column div of the given width (1-12). */
	    function colWrap(el, width) {
		const col = document.createElement('div');
		col.className = 'col-12 col-md-' + (width && width >= 1 && width <= 12 ? width : 12);
		col.appendChild(el);
		return col;
	    }


    	    (nodeList || []).forEach((node) => {
    		if (node.type === 'columns') {
        	    const layout = new ColumnsLayout(node, this);
        	    targetEl.appendChild(layout.render());
        	    return;
    		}

    		const api = {
        	    direction: node.direction || this.direction,
		    //CLD: 2026-10-11T17:02:49+03:00: form-level size (FieldBase.sized)
		    size: this.schema.size || null,
		    //CLD: 2026-10-11T22:38:36+03:00: form-level expression help (FieldBase._expression)
		    expression: this.schema.expression || null,
        	    onChange: (key, value) => {
        		this.data[key] = value;
        		this.recomputeVisibility();
        	    },
        	    getContext: () => ({ data: this.data, row: null, index: null }),
		    //CLD: 2026-10-23T12:30:00+03:00: another field's value set by a field (`propose`)
		    setValue: (key, value) => {
			const field = this.fields.find((f) => f.key === key);
			if (field)
			    field.setValue(value);
		    },
    		};

    		const fieldInstance = createField(node, api);
    		this.fields.push(fieldInstance);

    		const el = fieldInstance.render();
    		if (node.width && node.width < 12) {
        	    // Simple 2/3/4-column helper: give the field's own row a width and
        	    // let sibling fields with widths flow onto the same line.
		    //CLD: 2026-10-24T12:41:42+03:00: + newRow: the field starts a new line (was always after the narrow fields before it)
        	    this._appendToFlowRow(targetEl, colWrap(el, node.width), !!node.newRow);
    		} else {
        	    targetEl.appendChild(el);
    		}
    	    });
	}

	/** Groups consecutively-declared narrow-width fields onto shared Bootstrap rows. */
	_appendToFlowRow(targetEl, colEl, newRow) {
    	    let lastRow = targetEl.lastElementChild;
    	    const isFlowRow = lastRow && lastRow.dataset && lastRow.dataset.flowRow === '1';
	    //CLD: 2026-10-24T12:50:33+03:00: a new line in the same row: a line break (Bootstrap's w-100 column; a new .row g-3 overlapped the line above by its negative margin)
	    //no gutter of its own; shown / hidden with the field's column (flowColVisible)
	    if (isFlowRow && newRow) {
		const br = document.createElement('div');
		br.className = 'w-100 mt-0 p-0';
		lastRow.appendChild(br);
		colEl._flowBreak = br;
	    }
    	    if (!isFlowRow) {
    		lastRow = document.createElement('div');
    		//CLD: 2026-10-24T12:52:25+03:00: no vertical gutter (gy-0; was g-3): the fields' own bottom margin spaces the lines, as it spaces the fields one under the other (the lines were twice as far apart)
    		lastRow.className = 'row gx-3 gy-0';
    		lastRow.dataset.flowRow = '1';
    		targetEl.appendChild(lastRow);
    	    }
    	    lastRow.appendChild(colEl);
	}

	_collectInitialData(fieldList) {
    	    fieldList.forEach((f) => {
    		if (f.key) this.data[f.key] = f.getValue();
    	    });
	}

	/** Re-run show-if/hide-if/required-if across every top-level field. */
	recomputeVisibility() {
	    //CLD: 2026-10-24T12:50:55+03:00: in their order, a hidden field's value is empty for the conditions of the fields after it (a field shown by a hidden one's value is hidden too: e.g.
	    //the link's scope when the share settings are hidden; was the hidden field's value, often its default)
	    const data = Object.assign({}, this.data);
    	    const context = { data: data, row: null, index: null };
    	    this.fields.forEach((f) => {
		f.applyConditions(context);
		if (f.key && f._visible === false)
		    data[f.key] = undefined;
	    });
        }

	/** Validate every visible field; returns true only if all pass. */
	validate() {
    	    const context = { data: this.data, row: null, index: null };
    	    let valid = true;
    	    this.fields.forEach((f) => {
    		if (!f.validate(context)) valid = false;
    	    });
    	    return valid;
	}

	/** Returns a deep-ish copy of the current form data. */
	getData() {
    	    return JSON.parse(JSON.stringify(this.data));
	}

	/** Prefill the form. Pass { key: value, ... } matching your schema's field keys. */
	setData(values) {
    	    Object.keys(values || {}).forEach((key) => {
    		const field = this.fields.find((f) => f.key === key);
    		if (field) field.setValue(values[key], true);
    		    this.data[key] = values[key];
    	    });
    	    this.recomputeVisibility();
	}
    }

    var registry = {
	'text': TextField,
	//CLD: 2026-10-13T20:41:25+03:00: e-mail (EmailField)
	'email': EmailField,
	//CLD: 2026-10-14T19:54:21+03:00: web address (UrlField)
	'url': UrlField,
	//CLD: 2026-10-10T18:27:49+03:00: password input with show/hide (PasswordField)
	'password': PasswordField,
	'textarea': TextareaField,
	'select': SelectField,
	'number': NumberField,
	'date': DateField,
	'datetime': DateField,
	'checkbox': CheckboxField,
	'switch': SwitchField,
	'editgrid': EditGridField,
	//CLD: 2026-10-18T10:20:00+03:00: one nested object (NestedField)
	'nested': NestedField,

	'title': TitleField,
	'hidden': HiddenField,
	//CLD: 2026-10-13T18:20:23+03:00: design nodes, the form designer
	'paragraph': ParagraphField,
	'divider': DividerField,
	'formdesigner': DesignerField,
	//CLD: 2026-10-16T12:01:48+03:00: the email designer (Send Email step)
	'emaildesigner': DesignerField,
    };

    function createField(schema, api) {
	const FieldClass = registry[schema.type];
	if (!FieldClass) {
    	    throw new Error('[form-renderer] Unknown field type: "' + schema.type + '"');
	}
	return new FieldClass(schema, api);
    }

    /**
     * Normalize `choices` into [{ value, label }], keeping their order. Accepted forms:
     *   [ { value, label }, ... ]  |  [ 'a', 'b', ... ]  |  { value: label, ... }
     * Note: a { value: label } map with numeric keys is iterated in numeric order by JS;
     * use the array form when the order matters.
     */
    function normalizeChoices(choices) {
	if (!choices)
    	    return [];
	if (!Array.isArray(choices))
    	    return Object.keys(choices).map((value) => ({ value: String(value), label: String(choices[value]) }));
	return choices.map((c) => (c !== null && typeof c === 'object')
    	    ? { value: String(c.value), label: String(c.label !== undefined ? c.label : c.value) }
    	    : { value: String(c), label: String(c) });
    }

    /**
     * Evaluate a JS expression string against a data context.
     * Context exposes `data` (the whole form's data object), `row`
     * (the current edit-grid row's data, or null) and `index` (row index, or null).
     * Returns `defaultValue` (false) if the expression is empty or throws.
     */
    //CLD: 2026-10-14T20:17:14+03:00: a condition { key, operator, value, scope } (scope 'row': the field's editgrid row, else the form's data): evaluated as util\Conditions::holds on
    //the server (the fields' operators eq ne gt gte lt lte contains startsWith in empty notEmpty; numbers when both sides are numeric, else text; contains / startsWith
    //ignore case; `in` takes a list or comma-separated text); conditions need no JS written by the server (were JS strings made by util\SmartForm::showIf)
    const toText = (v) => v === undefined || v === null ? '' : (typeof v === 'boolean' ? (v ? 'true' : 'false') : (typeof v === 'object' ? JSON.stringify(v) : String(v)));
    const isNumeric = (s) => s.trim() !== '' && isFinite(Number(s));
    function holds(left, operator, right) {
	if (operator === 'empty' || operator === 'notEmpty')
	    return (operator === 'empty') === (left === undefined || left === null || left === false || (Array.isArray(left) ? left.length === 0 : toText(left).trim() === ''));
	if (operator === 'in') {
	    const list = Array.isArray(right) ? right.map(toText) : toText(right).split(',').map((x) => x.trim()).filter((x) => x !== '');
	    return Array.isArray(left) ? left.some((x) => list.includes(toText(x))) : list.includes(toText(left));
	}
	const r = toText(right);
	if (Array.isArray(left) && operator === 'contains')
	    return left.map((x) => toText(x).toLowerCase()).includes(r.toLowerCase());
	const l = toText(left);
	const cmp = isNumeric(l) && isNumeric(r) ? Math.sign(Number(l) - Number(r)) : (l < r ? -1 : (l > r ? 1 : 0));
	switch (operator) {
	    case 'eq': return cmp === 0;
	    case 'ne': return cmp !== 0;
	    case 'gt': return cmp > 0;
	    case 'gte': return cmp >= 0;
	    case 'lt': return cmp < 0;
	    case 'lte': return cmp <= 0;
	    case 'contains': return r !== '' && l.toLowerCase().includes(r.toLowerCase());
	    case 'startsWith': return r !== '' && l.toLowerCase().startsWith(r.toLowerCase());
	}
	console.warn('[form-renderer] Unknown operator:', operator);
	return false;
    }

    //CLD: 2026-10-20T14:07:31+03:00: the key / certificate rules of util\Validator (same names, options `when` and `multiple`), as structure checks: the armored / PEM blocks,
    //their base64 bodies, the first OpenPGP packet (5 = secret key, 6 = public key), the OpenSSH marker, a DER SEQUENCE; whether OpenSSL reads them is checked by the server.
    //A blank value passes (required-ness is required / requiredIf's)
    function pemBlocks(text, label) {
	const q = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const re = new RegExp('-----BEGIN (' + q + ')-----([\\s\\S]*?)-----END ' + q + '-----', 'g');
	const blocks = [];
	for (const m of String(text).matchAll(re)) {
	    //headers (Version:, Proc-Type:, DEK-Info:, ...) and the PGP checksum line are not part of the body
	    const body = m[2].split(/\r\n|\r|\n/).map((l) => l.trim()).filter((l) => l !== '' && !l.includes(':') && !/^=[A-Za-z0-9+/]{4}$/.test(l)).join('');
	    let data = null;
	    if (body !== '' && /^[A-Za-z0-9+/]+={0,2}$/.test(body)) {
		try { data = atob(body); } catch (e) { data = null; }
	    }
	    blocks.push({ label: m[1], pem: m[0], data: data });
	}
	return blocks;
    }
    const pgpTag = (data) => {
	const b = data ? data.charCodeAt(0) : 0;
	return (b & 0x80) ? ((b & 0x40) ? (b & 0x3F) : ((b >> 2) & 0x0F)) : null;
    };
    const isDer = (data) => !!data && data.charCodeAt(0) === 0x30;
    const PEM_PRIVATE = [ 'PRIVATE KEY', 'ENCRYPTED PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY', 'DSA PRIVATE KEY' ];
    function isPemPrivateKey(text) {
	const blocks = PEM_PRIVATE.flatMap((label) => pemBlocks(text, label));
	if (blocks.length !== 1 || blocks[0].data === null)
	    return false;
	//a traditional encrypted key's body is ciphertext
	return blocks[0].pem.includes('Proc-Type: 4,ENCRYPTED') || isDer(blocks[0].data);
    }
    const KEY_RULES = {
	pgpPublicKey: (v) => {
	    const blocks = pemBlocks(v, 'PGP PUBLIC KEY BLOCK');
	    return blocks.length > 0 && blocks.every((b) => pgpTag(b.data) === 6);
	},
	pgpPrivateKey: (v, r) => {
	    const blocks = pemBlocks(v, 'PGP PRIVATE KEY BLOCK');
	    return blocks.length > 0 && (r.multiple || blocks.length === 1) && blocks.every((b) => pgpTag(b.data) === 5);
	},
	pemCertificate: (v, r) => {
	    const blocks = pemBlocks(v, 'CERTIFICATE');
	    return blocks.length > 0 && (r.multiple !== false || blocks.length === 1) && blocks.every((b) => isDer(b.data));
	},
	pemPrivateKey: (v) => isPemPrivateKey(v),
	pemPublicKey: (v) => {
	    const blocks = pemBlocks(v, 'PUBLIC KEY').concat(pemBlocks(v, 'RSA PUBLIC KEY'));
	    return blocks.length === 1 && isDer(blocks[0].data);
	},
	sshPrivateKey: (v) => {
	    const blocks = pemBlocks(v, 'OPENSSH PRIVATE KEY');
	    if (blocks.length)
		return blocks.length === 1 && !!blocks[0].data && blocks[0].data.startsWith('openssh-key-v1\0');
	    return isPemPrivateKey(v);
	},
	notPrivateKey: (v) => !/-----BEGIN [A-Z0-9 ]*PRIVATE KEY( BLOCK)?-----/.test(v),
	notPublicKey: (v) => !/-----BEGIN [A-Z0-9 ]*PUBLIC KEY( BLOCK)?-----/.test(v),
    };

    //the first rule of `rules` the value fails (null: none); `when` { field: [ values ] } is read in the field's row (edit grid / nested object) or the form's data
    function checkRules(rules, value, context) {
	if (!Array.isArray(rules) || typeof value !== 'string' || value.trim() === '')
	    return null;
	const scope = (context && (context.row || context.data)) || {};
	for (const r of rules) {
	    const check = KEY_RULES[r.rule];
	    const when = r.when || {};
	    if (!check || !Object.keys(when).every((k) => holds(scope[k], 'in', when[k])))
		continue;
	    if (!check(value, r))
		return r;
	}
	return null;
    }

    //CLD: 2026-10-24T12:48:19+03:00: shows / hides the column of a field shown in a row of narrow fields (app.smartform _appendToFlowRow), with the field
function flowColVisible(el, visible) {
    const col = el && el.parentElement;
    if (col && col.parentElement && col.parentElement.dataset && col.parentElement.dataset.flowRow === '1' && col.children.length === 1) {
	col.classList.toggle('d-none', !visible);
	if (col._flowBreak)
	    col._flowBreak.classList.toggle('d-none', !visible);
    }
}

function evalExpr(expr, context, defaultValue) {
	if (expr && typeof expr === 'object' && !Array.isArray(expr) && expr.key) {
	    const scope = expr.scope === 'row' ? context.row : context.data;
	    return holds(scope ? scope[expr.key] : undefined, expr.operator || 'eq', expr.value);
	}
	if (!expr || typeof expr !== 'string' || !expr.trim()) {
    	    return defaultValue === undefined ? false : defaultValue;
	}
	try {
    	    // eslint-disable-next-line no-new-func
    	    const fn = new Function('data', 'row', 'index', 'return (' + expr + ');');
    	    return !!fn(context.data, context.row, context.index);
	} catch (err) {
    	    console.warn('[form-renderer] Failed to evaluate expression:', expr, err);
    	    return defaultValue === undefined ? false : defaultValue;
	}
    }


    return SmartForm;
})();
