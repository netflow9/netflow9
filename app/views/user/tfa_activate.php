<?php

$tpl = '
<p> Scan the QRCode with your authenticator app then enter the code below</p>
<div class="text-center">
    <div class="w-50" style="margin:0 auto">
	<img src="{:image}" alt="QR Code">
	<input id="{:id}" type="text" name="code" class="form-control form-control-lg mt-2" placeholder="Enter code">
    </div>
</div>
';

$qrCode = new \chillerlan\QRCode\QRCode();
$body = \util\VString::insert($tpl, [
    'image' => $qrCode->render($totp->getURI($user->email, $secret)),
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

$script = "setTimeout(() => { $('#{$id}').focus(); }, 200)";
\V::app()->cs->addPageScript($script);
