<?php

$tpl = '
<p> Scan the QRCode with your authenticator app then enter the code below</p>
<div class="text-center">
    <div class="w-50" style="margin:0 auto">
	<div id="{:qrId}" class="bg-white" data-uri="{:uri}" role="img" aria-label="QR Code"></div>
	<p class="small mt-2 mb-1">Or type this key: <code class="user-select-all">{:secret}</code></p>
	<input id="{:id}" type="text" name="code" class="form-control form-control-lg mt-2" placeholder="Enter code">
    </div>
</div>
';

$body = \util\VString::insert($tpl, [
    'uri' => \V::app()->html->escape($totp->getURI($user->email, $secret)),
    'secret' => \V::app()->html->escape($secret),
    'qrId' => $qrId = 'q-' . uniqid(),
    'id' => $id = 'f-' . uniqid(),
]);

$footer = '
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Activate</button>
';

$modal = new \sys\html\Modal([
    'title' => 'Activate 2FA',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => \V::app()->form->create(null, [ 'action' => \V::app()->createUrl('') ]),
    'afterContent' => \V::app()->form->end(),
    'size' => '',
]);
echo $modal->render();

//CLD: 2026-11-03T08:20:00+02:00: the QR code is drawn in the browser by qrcode-generator (imported here: this dialog comes through pjax, which runs only inline page scripts); if it cannot load, the key above still works
$script = "setTimeout(() => { $('#{$id}').focus(); }, 200);
import('/js/vendor/qrcode-generator-2.0.4/qrcode.mjs').then(({ default: qrcode }) => {
    const el = document.getElementById('{$qrId}');
    const qr = qrcode(0, 'L');
    qr.addData(el.dataset.uri, 'Byte');
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, scalable: true });
}).catch((err) => {
    console.error(err);
    document.getElementById('{$qrId}').textContent = 'The QR code could not be drawn: type the key below.';
});";
\V::app()->cs->addPageScript($script);
