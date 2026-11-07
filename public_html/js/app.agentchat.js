//the chat of an agent: the app's page (views/agent/open.php: /agent/send, /agent/poll, /agent/remove) and the embeddable one (views/chat/embed.php: /chat/<token>; its conversation and
//key kept in this browser). Messages as the designer's Assistant shows them (.wf-assistant .wfa-msg); an answer's html is the server's safe HTML (util\Markdown). What is new is polled: no streaming
window.AgentChat = (function() {
    const POLL_FAST = 1000;
    const POLL_SLOW = 4000;
    const FAST_FOR = 60000;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function start(o) {
	const root = document.getElementById(o.root);
	if (!root)
	    return;
	const embed = !!o.embed;
	const storeKey = embed ? 'netflow9.chat.' + o.embed : null;
	const store = {
	    get() {
		try {
		    return JSON.parse(localStorage.getItem(storeKey) || 'null');
		} catch (e) {
		    return null;
		}
	    },
	    set(v) {
		try {
		    localStorage.setItem(storeKey, JSON.stringify(v));
		} catch (e) {}
	    },
	    clear() {
		try {
		    localStorage.removeItem(storeKey);
		} catch (e) {}
	    },
	};
	const saved = embed ? store.get() : null;
	const state = { conversation: embed ? (saved && saved.conversation) || null : o.conversation, key: saved ? saved.key : null, messages: o.messages || [], status: o.status || 'idle', error: '', timer: null, since: 0 };

	root.innerHTML = `
	    ${embed ? `<div class="d-flex align-items-center border-bottom px-3 py-2"><strong class="flex-fill text-truncate">${esc(o.title)}</strong><button type="button" class="btn btn-sm btn-link ac-new">New conversation</button></div>` : ''}
	    <div class="ac-list wf-assistant flex-fill overflow-y-auto p-3" style="min-height: 0" aria-live="polite"></div>
	    <div class="border-top p-2 wf-assistant">
		<div class="small text-danger ac-error px-1"></div>
		<div class="d-flex gap-2 align-items-end">
		    <textarea class="form-control wfa-input ac-input" rows="2" maxlength="8000" placeholder="Write a message" aria-label="Your message"></textarea>
		    <button type="button" class="btn btn-primary ac-send" title="Send"><i class="bi bi-send"></i></button>
		    ${!embed && o.canRemove ? '<button type="button" class="btn btn-outline-secondary ac-remove" title="Delete this conversation"><i class="bi bi-trash"></i></button>' : ''}
		</div>
	    </div>`;
	const list = root.querySelector('.ac-list');
	const input = root.querySelector('.ac-input');
	const sendBtn = root.querySelector('.ac-send');
	const errorEl = root.querySelector('.ac-error');
	const lastSeq = () => state.messages.length ? state.messages[state.messages.length - 1].seq : 0;
	const busy = () => state.status !== 'idle' || state.sending;

	function render() {
	    const last = state.messages[state.messages.length - 1];
	    let html = '';
	    if (o.greeting)
		html += `<div class="mb-2"><div class="wfa-msg wfa-bot rounded-3 px-2 py-1 small d-inline-block">${esc(o.greeting)}</div></div>`;
	    for (const m of state.messages) {
		if (m.role === 'user') {
		    html += `<div class="d-flex flex-column align-items-end mb-2"><div class="wfa-msg wfa-user rounded-3 px-2 py-1 small" style="white-space: pre-wrap">${esc(m.text)}</div></div>`;
		} else if (m.role === 'notice') {
		    html += `<div class="mb-2"><div class="wfa-msg rounded-3 px-2 py-1 small text-danger-emphasis bg-danger-subtle d-inline-block"><i class="bi bi-exclamation-triangle me-1"></i>${esc(m.text)}</div></div>`;
		} else {
		    const body = m.html ? m.html : `<div style="white-space: pre-wrap">${esc(m.text)}</div>`;
		    const buttons = m === last && m.buttons && m.buttons.length && !busy()
			? `<div class="d-flex flex-wrap gap-1 mt-1">${m.buttons.map((b, i) => `<button type="button" class="btn btn-sm btn-outline-primary ac-choice" data-i="${i}">${esc(b.label)}</button>`).join('')}</div>` : '';
		    html += `<div class="mb-2"><div class="wfa-msg wfa-bot rounded-3 px-2 py-1 small d-inline-block">${body}</div>${buttons}</div>`;
		}
	    }
	    if (state.status === 'working' || state.sending)
		html += `<div class="mb-2 small text-muted"><span class="spinner-border spinner-border-sm me-2"></span>The agent is working on it&hellip;</div>`;
	    else if (state.status === 'approval')
		html += `<div class="mb-2 small text-muted"><i class="bi bi-hourglass-split me-2"></i>Waiting for a person to approve what the agent wants to do&hellip;</div>`;
	    list.innerHTML = html;
	    list.scrollTop = list.scrollHeight;
	    errorEl.textContent = state.error;
	    sendBtn.disabled = busy();
	}

	const headers = () => embed ? { 'Content-Type': 'application/json', 'X-Chat-Key': state.key || '' } : { 'Content-Type': 'application/json', 'X-Csrf-Token': document.querySelector('meta[name=csrf-token]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' };

	async function call(url, options) {
	    const r = await fetch(url, Object.assign({ credentials: embed ? 'omit' : 'same-origin', headers: headers() }, options));
	    const data = await r.json().catch(() => ({ ok: false, error: 'The server did not answer.' }));
	    if (!r.ok || !data.ok) {
		const e = new Error(data.error || 'The message was not sent.');
		e.status = r.status;
		throw e;
	    }
	    return data;
	}

	function take(data) {
	    const have = lastSeq();
	    state.messages.push(...(data.messages || []).filter((m) => m.seq > have));
	    state.status = data.status || 'idle';
	}

	function schedule() {
	    clearTimeout(state.timer);
	    if (state.status === 'idle' || !state.conversation || !root.isConnected)
		return;
	    state.timer = setTimeout(poll, Date.now() - state.since < FAST_FOR ? POLL_FAST : POLL_SLOW);
	}

	async function poll() {
	    if (!root.isConnected)
		return;
	    const url = embed
		? `/chat/${encodeURIComponent(o.embed)}?poll=1&conversation=${encodeURIComponent(state.conversation)}&after=${lastSeq()}`
		: `/agent/poll?conversation=${encodeURIComponent(state.conversation)}&after=${lastSeq()}`;
	    try {
		take(await call(url, { method: 'GET' }));
		state.error = '';
	    } catch (e) {
		//gone (the retention, an owner): an embedded page starts again
		if (embed && e.status === 404) {
		    store.clear();
		    state.conversation = state.key = null;
		    state.messages = [];
		    state.status = 'idle';
		}
		state.error = e.message;
	    }
	    render();
	    schedule();
	}

	async function send(text, label) {
	    if (busy() || !text.trim())
		return;
	    state.sending = true;
	    state.error = '';
	    render();
	    try {
		const body = { conversation: state.conversation, message: text, label: label ?? null };
		if (embed)
		    body.key = state.key;
		else
		    body.agent = o.agent;
		const data = await call(embed ? `/chat/${encodeURIComponent(o.embed)}` : '/agent/send', { method: 'POST', body: JSON.stringify(body) });
		//a new conversation of the app's page: its address, and in the list beside it
		if (!embed && !state.conversation && data.conversation) {
		    history.replaceState(history.state, '', `${o.pageUrl}?conversation=${encodeURIComponent(data.conversation)}`);
		    document.getElementById(o.listId)?.insertAdjacentHTML('afterbegin', `<a class="list-group-item list-group-item-action small text-truncate active" href="${esc(o.pageUrl)}?conversation=${esc(data.conversation)}">${esc((label ?? text).trim().slice(0, 80))}</a>`);
		}
		state.conversation = data.conversation;
		if (embed && data.key) {
		    state.key = data.key;
		    store.set({ conversation: state.conversation, key: state.key });
		}
		state.since = Date.now();
		state.sending = false;
		take(data);
		input.value = '';
	    } catch (e) {
		state.sending = false;
		state.error = e.message;
	    }
	    render();
	    schedule();
	}

	sendBtn.addEventListener('click', () => send(input.value));
	input.addEventListener('keydown', (e) => {
	    if (e.key === 'Enter' && !e.shiftKey) {
		e.preventDefault();
		send(input.value);
	    }
	});
	list.addEventListener('click', (e) => {
	    const b = e.target.closest('.ac-choice');
	    if (!b)
		return;
	    const buttons = state.messages[state.messages.length - 1].buttons;
	    const choice = buttons[Number(b.dataset.i)];
	    send(choice.value, choice.label);
	});
	root.querySelector('.ac-new')?.addEventListener('click', () => {
	    store.clear();
	    clearTimeout(state.timer);
	    state.conversation = state.key = null;
	    state.messages = [];
	    state.status = 'idle';
	    state.error = '';
	    render();
	});
	root.querySelector('.ac-remove')?.addEventListener('click', async () => {
	    if (!window.confirm('Delete this conversation?'))
		return;
	    try {
		await call('/agent/remove', { method: 'POST', body: JSON.stringify({ conversation: state.conversation }) });
		location.href = o.pageUrl;
	    } catch (e) {
		state.error = e.message;
		render();
	    }
	});

	//an embedded page comes back to its conversation
	if (embed && state.conversation) {
	    render();
	    poll();
	    return;
	}
	state.since = Date.now();
	render();
	schedule();
    }

    return { start };
})();
