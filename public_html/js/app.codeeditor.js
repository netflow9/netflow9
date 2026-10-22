if (!window.app)
    window.app = {};

/*!
 * a code editor (CodeMirror 6) in place of a textarea, for J code (the Code step). CodeMirror, loaded when an
 * editor is first shown
 * Line numbers, JavaScript colouring, bracket matching / closing, indenting with Tab, undo.
 * The live help is the server's, as for the expression fields (app.ExprInput.request: POST text + syntax, `before` for suggestions): its problems are shown as lint marks, its
 * suggestions in the completion list. The textarea stays the form's input: it gets every change (an `input` event). When CodeMirror cannot be loaded, the textarea stays,
 * with app.ExprInput's live help.
 *
 *   new app.CodeEditor(textarea, { url, params: () => ({ ... }), syntax: 'script' })
 */
window.app.CodeEditor = (function() {

    const KINDS = { variable: 'variable', global: 'namespace', method: 'method', property: 'property', literal: 'keyword' };

    function load() {
	if (window.CodeMirror6)
	    return Promise.resolve(window.CodeMirror6);
	if (document.readyState === 'complete')
	    return Promise.reject(new Error('CodeMirror6 is not loaded'));
	return new Promise((resolve, reject) => window.addEventListener('load', () => window.CodeMirror6 ? resolve(window.CodeMirror6) : reject(new Error('CodeMirror6 is not loaded')), { once: true }));
    }

    class CodeEditor {
	constructor(textarea, options) {
	    this.textarea = textarea;
	    this.options = options || {};
	    this.syntax = this.options.syntax || 'script';
	    this.view = null;
	    this.fallback = null;
	    this.lintController = null;
	    this.completeController = null;

	    textarea.classList.add('font-monospace');
	    textarea.setAttribute('spellcheck', 'false');
	    load().then((cm) => this.mount(cm)).catch((err) => {
		console.warn('CodeEditor: CodeMirror could not be loaded, using the textarea', err);
		if (window.app.ExprInput && this.options.url)
		    this.fallback = new window.app.ExprInput(textarea, this.options);
	    });
	}

	//the server's answer for the current text (+ the text before $pos for suggestions)
	ask(text, pos, kind) {
	    if (!this.options.url)
		return Promise.resolve(null);
	    const key = kind + 'Controller';
	    if (this[key])
		this[key].abort();
	    this[key] = new AbortController();
	    const fields = Object.assign({ text: text, syntax: this.syntax }, pos === null ? {} : { before: text.slice(0, pos) }, (this.options.params && this.options.params()) || {});
	    return window.app.ExprInput.request(this.options.url, fields, this[key].signal);
	}

	mount(cm) {
	    const ta = this.textarea;
	    if (!ta.isConnected && !ta.parentNode)
		return;
	    const { EditorState } = cm.state;
	    const { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection } = cm.view;
	    const { syntaxHighlighting, defaultHighlightStyle, bracketMatching, indentOnInput } = cm.language;
	    const { defaultKeymap, history, historyKeymap, indentWithTab } = cm.commands;
	    const { autocompletion, completionKeymap, closeBrackets, closeBracketsKeymap, completionStatus } = cm.autocomplete;
	    const { linter, lintGutter } = cm.lint;

	    const lines = Math.max(6, parseInt(ta.getAttribute('rows') || '10', 10));
	    const state = EditorState.create({
		doc: ta.value,
		extensions: [
		    lineNumbers(), highlightActiveLineGutter(), highlightActiveLine(), drawSelection(), history(), indentOnInput(), bracketMatching(), closeBrackets(),
		    syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
		    keymap.of([ ...closeBracketsKeymap, ...defaultKeymap, ...historyKeymap, ...completionKeymap, indentWithTab ]),
		    cm.javascript.javascript(),
		    autocompletion({ override: [ (ctx) => this.complete(ctx) ], activateOnTyping: true }),
		    linter((view) => this.lint(view), { delay: 400 }),
		    lintGutter(),
		    EditorView.updateListener.of((u) => {
			if (!u.docChanged)
			    return;
			ta.value = u.state.doc.toString();
			ta.dispatchEvent(new Event('input', { bubbles: true }));
		    }),
		    EditorView.theme({
			'&': { border: '1px solid var(--bs-border-color)', borderRadius: 'var(--bs-border-radius)', backgroundColor: 'var(--bs-body-bg)', fontSize: '.875rem' },
			'&.cm-focused': { outline: 'none', borderColor: 'var(--bs-primary)' },
			'.cm-scroller': { fontFamily: 'var(--bs-font-monospace)', minHeight: (lines * 1.45) + 'em', maxHeight: '60vh' },
			'.cm-gutters': { backgroundColor: 'var(--bs-tertiary-bg)', borderRight: '1px solid var(--bs-border-color)' },
		    }),
		    EditorState.tabSize.of(4),
		],
	    });
	    const view = this.view = new EditorView({ state: state });
	    view.dom.classList.add('code-editor');

	    //Escape closing the suggestions must not close the dialog around
	    let menuOpen = false;
	    view.dom.addEventListener('keydown', (e) => { menuOpen = e.key === 'Escape' && completionStatus(view.state) !== null; }, true);
	    view.dom.addEventListener('keydown', (e) => { if (menuOpen) { e.stopPropagation(); menuOpen = false; } });

	    ta.style.display = 'none';
	    ta.insertAdjacentElement('afterend', view.dom);
	}

	//problems -> lint marks (the server's positions are characters)
	async lint(view) {
	    const text = view.state.doc.toString();
	    if (text.trim() === '')
		return [];
	    const resp = await this.ask(text, null, 'lint');
	    if (!resp || view.state.doc.toString() !== text)
		return [];
	    const len = view.state.doc.length;
	    return (resp.problems || []).map((p) => ({
		from: Math.min(p.start, len),
		to: Math.min(Math.max(p.end, p.start), len),
		severity: p.severity === 'error' ? 'error' : 'warning',
		message: p.message,
	    }));
	}

	//suggestions after a name or `.` (or Ctrl+Space)
	async complete(ctx) {
	    const word = ctx.matchBefore(/[\w$]*/);
	    const afterDot = /\.\s*$/.test(ctx.state.sliceDoc(Math.max(0, ctx.pos - (word ? word.text.length : 0) - 2), ctx.pos - (word ? word.text.length : 0)));
	    if (!ctx.explicit && (!word || word.text === '') && !afterDot)
		return null;
	    const text = ctx.state.doc.toString();
	    const resp = await this.ask(text, ctx.pos, 'complete');
	    const c = resp && resp.completion;
	    if (!c || !c.items || !c.items.length)
		return null;
	    return {
		from: ctx.pos - (c.replace || 0),
		options: c.items.map((it) => ({ label: it.label, type: KINDS[it.kind] || 'text', detail: it.detail || '', apply: it.kind === 'method' ? it.label + '()' : it.label })),
		validFor: /^[\w$]*$/,
	    };
	}

	destroy() {
	    if (this.view)
		this.view.destroy();
	}
    }

    CodeEditor.load = load;
    return CodeEditor;
})();
