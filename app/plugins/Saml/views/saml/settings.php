<?php
//CLD: 2026-10-24T16:30:00+03:00: the certificate's end in the account's date format (util\Format::date; was Y-m-d)
/*
    CLD: 2026-10-23T12:00:00+03:00: new: the SAML single sign-on settings page (SamlController::settings): the settings (app.smartform of models\SamlSettings, in sections; posted
    as one JSON value `settings_json`: the groups' rows are an edit grid), Import of the IdP's metadata into the form (SamlController::import: nothing saved), the details of
    this service provider for the IdP (entity ID, ACS URL, metadata, certificate: Renew), Test sign-in (saved settings), the SSO users of the account (Unlink)
*/
use plugins\Saml\models\SamlSettings;

$this->pageTitle = 'SAML single sign-on';

$containerId = 'saml-settings-' . uniqid();
$formId = $containerId . '-form';

//the form's fields, with the section titles before their first field
$sections = [ 'idpMetadataUrl' => 'Identity provider (IdP)', 'spEntityId' => 'This application (service provider)', 'attrEmail' => 'Attributes',
    'groupMap' => 'Groups and roles', 'provision' => 'Who signs in' ];
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
    'schema' => [ 'id' => $formId, 'action' => \V::app()->createUrl('/Saml/saml/settings'), 'method' => 'post', 'fields' => $fields ],
    'values' => \util\SmartForm::modelValues($settings),
    'errors' => (object)$errors,
    'importUrl' => \V::app()->createUrl('/Saml/saml/import'),
]);

//the SP's details for the IdP
$expires = $settings->spCertExpires();
$details = [
    'Entity ID (Identifier, Audience)' => $settings->spEntityId(),
    'ACS URL (Reply URL, single sign-on URL)' => $settings->acsUrl(),
    'Metadata URL' => $settings->metadataUrl(),
    'Sign-in link of the account' => $settings->loginUrl(),
];

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
    document.querySelectorAll('[data-saml-save]').forEach((b) => b.addEventListener('click', (e) => {
	e.preventDefault();
	form.requestSubmit();
    }));

    //Import: the IdP's metadata (the URL of the form, or pasted) into the form's IdP fields
    const out = document.getElementById(o.containerId + '-result');
    document.getElementById(o.containerId + '-import').addEventListener('click', () => {
	out.className = 'mt-3 small text-muted';
	out.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reading&hellip;';
	$.ajax({
	    url: o.importUrl,
	    method: 'POST',
	    dataType: 'json',
	    headers: { 'X-Csrf-Token': $('meta[name=csrf-token]').attr('content') },
	    data: { url: sf.getData().idpMetadataUrl || '', xml: document.getElementById(o.containerId + '-xml').value },
	}).done((r) => {
	    if (r.ok)
		sf.setData(r.values);
	    out.className = 'mt-3 alert small ' + (r.ok ? 'alert-success' : 'alert-warning');
	    out.innerHTML = (r.lines || []).map((l) => `<div class="text-break">${esc(l)}</div>`).join('');
	}).fail((x, s) => {
	    out.className = 'mt-3 alert alert-danger small';
	    out.textContent = (x.responseJSON && x.responseJSON.error) || 'The import did not run (' + (x.status || s) + ')';
	});
    });

    //copy a detail
    document.querySelectorAll('[data-saml-copy]').forEach((b) => b.addEventListener('click', () => {
	navigator.clipboard?.writeText(b.dataset.samlCopy).then(() => {
	    b.innerHTML = '<i class="bi bi-check2"></i>';
	    setTimeout(() => { b.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
	});
    }));
JS . "}({$js});");
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'SAML single sign-on' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <div class="d-flex align-items-center w-100 align-items-top">
	<div>
	    <h5>SAML single sign-on</h5>
	    <span class="text-muted">The people of the account sign in at your identity provider (Microsoft Entra ID, Okta, Google Workspace, ADFS, Keycloak, ...); their role comes from their groups</span>
	</div>
	<div class="flex-fill"></div>
	<button type="button" class="btn btn-primary" data-saml-save>Save</button>
    </div>

    <div class="row pt-4 g-4">
	<div class="col-xl-8">
	    <div id="<?= $containerId ?>"></div>
	    <div class="pt-2">
		<button type="button" class="btn btn-primary" data-saml-save>Save</button>
	    </div>
	</div>

	<div class="col-xl-4">
	    <div class="card mb-4">
		<div class="card-body">
		    <h6 class="card-title">For the identity provider</h6>
		    <p class="small text-muted">Register this application at the IdP with its metadata, or with these values.</p>
		    <?php foreach($details as $label => $value): ?>
			<div class="small text-muted"><?= \V::app()->html->escape($label) ?></div>
			<div class="d-flex align-items-start gap-1 mb-2">
			    <code class="small text-break flex-fill"><?= \V::app()->html->escape($value) ?></code>
			    <button type="button" class="btn btn-sm btn-link p-0" title="Copy" data-saml-copy="<?= \V::app()->html->escape($value) ?>"><i class="bi bi-clipboard"></i></button>
			</div>
		    <?php endforeach; ?>
		    <div class="small text-muted">NameID format</div>
		    <div class="small mb-3"><?= \V::app()->html->escape(SamlSettings::NAMEID_FORMATS[$settings->nameIdFormat] ?? $settings->nameIdFormat) ?></div>
		    <div class="small text-muted">Certificate (signing, encryption)</div>
		    <div class="small mb-3">Valid until <?= $expires ? \V::app()->html->escape(\util\Format::date($expires)) : '?' ?></div>
		    <div class="d-flex flex-wrap gap-2">
			<a class="btn btn-sm btn-outline-primary" href="<?= \V::app()->html->escape($settings->metadataUrl() . '?download=1') ?>"><i class="bi bi-download me-1"></i>Metadata</a>
			<a class="btn btn-sm btn-outline-primary" href="<?= \V::app()->html->escape('data:application/x-pem-file;base64,' . base64_encode((string)$settings->spCert)) ?>" download="sp-certificate.pem"><i class="bi bi-file-earmark-lock me-1"></i>Certificate</a>
			<a class="btn btn-sm btn-outline-danger" href="<?= \V::app()->createUrl('/Saml/saml/renew') ?>" data-method="post"
			    data-confirm="<?= \V::app()->html->escape('Make a new certificate? Sign-ins fail until the IdP has the new metadata (or certificate) when it checks signed requests or encrypts the assertions') ?>"><i class="bi bi-arrow-repeat me-1"></i>Renew</a>
		    </div>
		</div>
	    </div>

	    <div class="card">
		<div class="card-body">
		    <h6 class="card-title">Import the IdP's metadata</h6>
		    <p class="small text-muted">From the IdP metadata URL of the form, or pasted here. It fills the IdP fields; nothing is saved.</p>
		    <textarea class="form-control form-control-sm mb-2 font-monospace" rows="3" id="<?= $containerId ?>-xml" placeholder="&lt;EntityDescriptor ...&gt; (optional)" spellcheck="false"></textarea>
		    <button type="button" class="btn btn-sm btn-outline-primary" id="<?= $containerId ?>-import"><i class="bi bi-cloud-download me-1"></i>Import</button>
		    <div id="<?= $containerId ?>-result"></div>
		    <hr>
		    <h6 class="card-title">Test the sign-in</h6>
		    <p class="small text-muted">With the saved settings, on or off: you sign in at the IdP, and see what the sign-in would do here. Nobody is signed in, nothing is saved.</p>
		    <a class="btn btn-sm btn-outline-primary" href="<?= \V::app()->createUrl('/Saml/saml/test') ?>" target="_blank" rel="noopener"><i class="bi bi-person-check me-1"></i>Test sign-in</a>
		</div>
	    </div>
	</div>
    </div>

    <div class="pt-4">
	<h6>SSO users (<?= count($links) ?>)</h6>
	<?php if (!$links): ?>
	<p class="text-muted small">None yet: people become SSO users at their first single sign-on.</p>
	<?php else: ?>
	<div class="table-responsive">
	    <table class="table table-sm align-middle small">
		<thead><tr><th>User</th><th>Email</th><th>Subject (NameID)</th><th>Last sign-in</th><th></th></tr></thead>
		<tbody>
		<?php foreach($links as $row): $u = $row['user']; $l = $row['link']; ?>
		    <tr>
			<td><?= \V::app()->html->escape((string)$u) ?><?= $u->state !== 'active' ? ' <span class="badge text-bg-secondary">' . \V::app()->html->escape($u->state) . '</span>' : '' ?></td>
			<td><?= \V::app()->html->escape($u->email) ?></td>
			<td class="text-break"><?= \V::app()->html->escape($l->subject) ?></td>
			<td><?= $l->synced_at ? \V::app()->html->escape($l->schema['synced_at']->display()) : '' ?></td>
			<td class="text-end">
			    <a class="btn btn-sm btn-outline-danger" href="<?= \V::app()->createUrl('/Saml/saml/unlink', [ 'id' => (string)$u->_id ]) ?>" data-method="post"
				data-confirm="<?= \V::app()->html->escape('Unlink ' . $u . '? The user then signs in with a password of this application (set with "Forgot username or password?")') ?>"><i class="bi bi-link-45deg me-1"></i>Unlink</a>
			</td>
		    </tr>
		<?php endforeach; ?>
		</tbody>
	    </table>
	</div>
	<?php endif; ?>
    </div>
</div>
