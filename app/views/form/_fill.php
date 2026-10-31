<?php

/*
    a form to fill in (a Form trigger's page, a Human task):
	$form (util\FormSchema, normalized),
	$url (POST: JSON { answers }),
	$submitLabel;
    the server's answer: { ok, message } (the form gives way to the message) or { error, errors: { key: message } } (shown at the fields)
*/

$id = 'netflow9-fill-' . bin2hex(random_bytes(4));

$tpl = '
<div id="{:id}">
    <div class="netflow9-fill-form"></div>
    <div class="d-flex gap-2 align-items-center mt-2">
	<button type="button" class="btn btn-primary netflow9-fill-send"><i class="bi bi-send me-1"></i>{:submit}</button>
	<span class="small text-danger netflow9-fill-msg"></span>
    </div>
</div>
';
echo \util\VString::insert($tpl, [
    'id' => $id,
    'submit' => \V::app()->html->escape($submitLabel),
]);

\V::app()->cs->addPageScript("(function() {
    const root = document.getElementById(" . \util\JSON::encode($id, JSON_UNESCAPED_UNICODE) . ");
    if (!root)
	return;

    const form = new app.smartform(root.querySelector('.netflow9-fill-form'), Object.assign({}, " . \util\JSON::encode($form, JSON_UNESCAPED_UNICODE) . ", { method: 'post' }));
    form.render();
    const btn = root.querySelector('.netflow9-fill-send');
    const msg = root.querySelector('.netflow9-fill-msg');
    btn.addEventListener('click', async () => {
	msg.textContent = '';
	if (!form.validate()) {
	    msg.textContent = 'Some fields need attention.';
	    return;
	}
	btn.disabled = true;
	try {
	    const res = await fetch(" . \util\JSON::encode($url, JSON_UNESCAPED_UNICODE) . ", {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
		    'X-Csrf-Token': document.querySelector('meta[name=csrf-token]')?.content || '' },
		body: JSON.stringify({ answers: form.getData() }),
	    });
	    const answer = await res.json().catch(() => ({ error: 'The server did not answer as expected.' }));
	    if (answer.ok) {
		root.innerHTML = '';
		const done = document.createElement('div');
		done.className = 'alert alert-success mb-0';
		done.textContent = answer.message || 'Sent.';
		root.appendChild(done);
		return;
	    }
	    Object.keys(answer.errors || {}).forEach((key) => {
		const field = form.fields.find((f) => f.key === key);
		if (field)
		    field.setInvalid(answer.errors[key]);
	    });
	    msg.textContent = answer.error || ('Not sent (' + res.status + ')');
	} catch (err) {
	    msg.textContent = 'Not sent: ' + err.message;
	}
	btn.disabled = false;
    });
})();");
