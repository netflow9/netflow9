<?php
/*
    CLD: 2026-10-21T10:00:00+03:00: new: the LDAP sign-in settings page (LdapController::settings): the settings (app.smartform of models\LdapSettings, in sections; posted as one JSON
    value `settings_json`: the groups' rows are an edit grid), Test connection and Try a sign-in (LdapController::test, with the form's values: nothing saved), the LDAP users
    of the account (Unlink). The empty attribute / filter fields show the chosen directory's usual values
*/
use plugins\Ldap\models\LdapSettings;

$this->pageTitle = 'LDAP sign-in';
$esc = fn($v) => \V::app()->html->escape((string)$v);

$containerId = 'ldap-settings-' . uniqid();
$formId = $containerId . '-form';

//the form's fields, with the section titles before their first field
$sections = [ 'directory' => 'Server', 'userFilter' => 'People', 'groupSource' => 'Groups', 'provision' => 'Who signs in' ];
$fields = [];
foreach(\util\SmartForm::modelFields($settings) as $f) {
    if (isset($sections[$f['key']])) {
	$fields[] = [ 'type' => 'divider' ];
	$fields[] = [ 'type' => 'title', 'text' => $sections[$f['key']] ];
    }
    $fields[] = $f;
}

$errors = [];
foreach((array)$settings->errors() as $key => $messages)
    $errors[strtok((string)$key, '.')] = is_array($messages) ? (string)reset($messages) : (string)$messages;

$js = \util\JSON::encode([
    'containerId' => $containerId,
    'schema' => [ 'id' => $formId, 'action' => \V::app()->createUrl('/Ldap/ldap/settings'), 'method' => 'post', 'fields' => $fields ],
    'values' => \util\SmartForm::modelValues($settings),
    'errors' => (object)$errors,
    'defaults' => LdapSettings::DEFAULTS,
    'testUrl' => \V::app()->createUrl('/Ldap/ldap/test'),
]);

//CLD: 2026-10-21T10:00:00+03:00: nowdoc: the script's text as is
\V::app()->cs->addPageScript("+function(o) {" . <<<'JS'
    const container = document.getElementById(o.containerId);
    if (!container)
	return;
    const sf = new app.smartform(container, o.schema);
    sf.render();
    sf.setData(o.values);
    Object.keys(o.errors).forEach((key) => {
	const field = sf.fields.find((f) => f.key === key);
	if (field)
	    field.setInvalid(o.errors[key]);
    });
    const form = container.querySelector('form');
    const esc = (v) => app.utils.escapeHtml(String(v ?? ''));

    //the whole form as one JSON value (the edit grid's rows are not inputs of the form)
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'settings_json';
    form.appendChild(hidden);
    form.addEventListener('submit', () => {
	hidden.value = JSON.stringify(sf.getData());
    });
    document.querySelectorAll('[data-ldap-save]').forEach((b) => b.addEventListener('click', (e) => {
	e.preventDefault();
	form.requestSubmit();
    }));

    //the empty fields show the directory's usual values
    const placeholders = () => {
	const d = o.defaults[(form.querySelector('[name=directory]') || {}).value === 'ad' ? 'ad' : 'openldap'];
	Object.keys(d).forEach((key) => {
	    const input = form.querySelector(`[name=${key}]`);
	    if (input)
		input.placeholder = d[key];
	});
    };
    form.querySelector('[name=directory]')?.addEventListener('change', placeholders);
    placeholders();

    //Test connection / Try a sign-in: the form's values (not saved)
    const out = document.getElementById(o.containerId + '-result');
    const test = (mode) => {
	out.className = 'mt-3 small text-muted';
	out.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Trying&hellip;';
	$.ajax({
	    url: o.testUrl,
	    method: 'POST',
	    dataType: 'json',
	    headers: { 'X-Csrf-Token': $('meta[name=csrf-token]').attr('content') },
	    data: { mode, settings_json: JSON.stringify(sf.getData()), login: document.getElementById(o.containerId + '-login').value,
		password: document.getElementById(o.containerId + '-password').value },
	}).done((r) => {
	    out.className = 'mt-3 alert small ' + (r.ok ? 'alert-success' : 'alert-warning');
	    out.innerHTML = (r.lines || []).map((l) => `<div>${esc(l)}</div>`).join('');
	}).fail((x, s) => {
	    out.className = 'mt-3 alert alert-danger small';
	    out.textContent = (x.responseJSON && x.responseJSON.error) || 'The test did not run (' + (x.status || s) + ')';
	});
    };
    document.getElementById(o.containerId + '-test').addEventListener('click', () => test('connection'));
    document.getElementById(o.containerId + '-try').addEventListener('click', () => test('signin'));
JS . "}({$js});");
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'LDAP sign-in' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <div class="d-flex align-items-center w-100 align-items-top">
	<div>
	    <h5>LDAP sign-in</h5>
	    <span class="text-muted">The people of the account sign in with their directory (Active Directory, OpenLDAP, ...) name and password; their role comes from their groups</span>
	</div>
	<div class="flex-fill"></div>
	<button type="button" class="btn btn-primary" data-ldap-save>Save</button>
    </div>

    <div class="row pt-4 g-4">
	<div class="col-xl-8">
	    <div id="<?= $containerId ?>"></div>
	    <div class="pt-2">
		<button type="button" class="btn btn-primary" data-ldap-save>Save</button>
	    </div>
	</div>

	<div class="col-xl-4">
	    <div class="card">
		<div class="card-body">
		    <h6 class="card-title">Try the settings</h6>
		    <p class="small text-muted">With the values of the form, saved or not. Trying a sign-in signs nobody in and saves nothing.</p>
		    <button type="button" class="btn btn-sm btn-outline-primary" id="<?= $containerId ?>-test"><i class="bi bi-plug me-1"></i>Test connection</button>
		    <hr>
		    <input type="text" class="form-control form-control-sm mb-2" id="<?= $containerId ?>-login" placeholder="name (as typed at sign-in)" autocomplete="off">
		    <input type="password" class="form-control form-control-sm mb-2" id="<?= $containerId ?>-password" placeholder="password" autocomplete="new-password">
		    <button type="button" class="btn btn-sm btn-outline-primary" id="<?= $containerId ?>-try"><i class="bi bi-person-check me-1"></i>Try a sign-in</button>
		    <div id="<?= $containerId ?>-result"></div>
		    <p class="small text-muted mt-3 mb-0">Connection: <?= $esc(\plugins\Ldap\util\LdapClient::autoDriver() === 'ext' ? 'PHP\'s ldap extension' : 'built-in client (PHP\'s ldap extension is not loaded)') ?></p>
		</div>
	    </div>
	</div>
    </div>

    <div class="pt-4">
	<h6>LDAP users (<?= count($links) ?>)</h6>
	<?php if (!$links): ?>
	<p class="text-muted small">None yet: people become LDAP users at their first LDAP sign-in.</p>
	<?php else: ?>
	<div class="table-responsive">
	    <table class="table table-sm align-middle small">
		<thead><tr><th>User</th><th>Email</th><th>Directory name</th><th>DN</th><th>Last sign-in</th><th></th></tr></thead>
		<tbody>
		<?php foreach($links as $row): $u = $row['user']; $l = $row['link']; ?>
		    <tr>
			<td><?= $esc($u) ?><?= $u->state !== 'active' ? ' <span class="badge text-bg-secondary">' . $esc($u->state) . '</span>' : '' ?></td>
			<td><?= $esc($u->email) ?></td>
			<td><?= $esc($l->login) ?></td>
			<td class="text-break"><?= $esc($l->dn) ?></td>
			<td><?= $l->synced_at ? $esc($l->schema['synced_at']->display()) : '' ?></td>
			<td class="text-end">
			    <a class="btn btn-sm btn-outline-danger" href="<?= \V::app()->createUrl('/Ldap/ldap/unlink', [ 'id' => (string)$u->_id ]) ?>" data-method="post"
				data-confirm="<?= $esc('Unlink ' . $u . '? The user then signs in with a password of this application (set with "Forgot username or password?")') ?>"><i class="bi bi-link-45deg me-1"></i>Unlink</a>
			</td>
		    </tr>
		<?php endforeach; ?>
		</tbody>
	    </table>
	</div>
	<?php endif; ?>
    </div>
</div>
