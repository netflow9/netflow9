<?php
$params = [ 'id' => (string)$wf->_id ];
if ($source)
    $params['version'] = (string)$source->_id;
$actionUrl = \V::app()->createUrl('/workflow/publish', $params);

$noteInput = '
<div class="mt-3">
    <label class="form-label" for="publish-note">Note <span class="text-muted">(optional)</span></label>
    <textarea id="publish-note" name="note" class="form-control" rows="3" maxlength="1000" placeholder="What changed in this version"></textarea>
</div>';

if ($errors) {
    $title = 'Cannot publish';
    $items = implode('', array_map(fn($i) => \util\VString::insert('<li>{:message}</li>', [ 'message' => \V::app()->html->escape($i['message']) ]), $errors));
    $body = \util\VString::insert('<div class="alert alert-danger mb-0">The {:what} has errors (steps\' settings or connections): fix them and save it first.<ul class="mb-0 mt-2">{:items}</ul></div>', [
	'what' => $source ? \V::app()->html->escape($source) : 'saved draft',
	'items' => $items,
    ]);
    $footer = "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>";
} elseif ($reuse) {
    $title = 'Publish ' . \V::app()->html->escape($active);
    $body = \util\VString::insert('<p class="mb-0">The draft has not changed since <b>{:active}</b>: it is published again (no new version). Its trigger starts runs of this version.</p>', [
	'active' => \V::app()->html->escape($active),
    ]);
    $footer = "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button><button type='submit' class='btn btn-success'>Publish</button>";
} elseif ($source) {
    $title = 'Publish ' . \V::app()->html->escape($source) . ' again';
    $body = \util\VString::insert('<p class="mb-0">The steps of <b>{:source}</b> are published as <b>v{:next}</b>, and its trigger starts runs of that version. The draft is not changed.</p>{:note}', [
	'source' => \V::app()->html->escape($source),
	'next' => $next,
	'note' => $noteInput,
    ]);
    $footer = "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button><button type='submit' class='btn btn-success'>Publish as v{$next}</button>";
} else {
    $title = "Publish the draft as v{$next}";
    $body = \util\VString::insert('<p class="mb-0">The saved draft becomes <b>v{:next}</b>, and its trigger starts runs of this version. Later changes to the draft are not live until you publish again.</p>{:note}', [
	'next' => $next,
	'note' => $noteInput,
    ]);
    $footer = "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button><button type='submit' class='btn btn-success'>Publish v{$next}</button>";
}

$modal = new \sys\html\Modal([
    'title' => $title,
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $errors ? '' : \V::app()->form->create($wf, [ 'action' => $actionUrl ]),
    'afterContent' => $errors ? '' : \V::app()->form->end(),
    'size' => '',
]);
echo $modal->render();
