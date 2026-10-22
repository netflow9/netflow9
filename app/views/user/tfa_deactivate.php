<?php

$tpl = '
<p> Enter your 2FA code</p>
<input id="{:id}" type="text" name="code" class="form-control form-control-lg mt-2 w-50" placeholder="Enter code">
';

$body = \util\VString::insert($tpl, [
    'id' => $id = 'f-' . uniqid(),
]);

$footer = '
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Deactivate</button>
';

$modal = new \sys\html\Modal([
    'title' => 'Deactivate 2FA',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => \V::app()->form->create(null, [ 'action' => \V::app()->createUrl('') ]),
    'afterContent' => \V::app()->form->end(),
    'size' => '',
]);
echo $modal->render();

$script = "setTimeout(() => { $('#{$id}').focus(); }, 200)";
\V::app()->cs->addPageScript($script);
