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

//CLD: 2026-11-02T15:46:06+02:00: the latest test result, above what the dialog says
$testState = (string)($gate['latest']['state'] ?? '');
$testTotals = (array)($gate['latest']['totals'] ?? []);
$testNote = '';
if ($gate['cases'] > 0 || $gate['required']) {
    $testNote = \util\VString::insert('<div class="alert alert-{:kind} small py-2 mb-3"><i class="bi bi-{:icon} me-1"></i>{:message}{:counts}{:why}</div>', [
	'kind' => $gate['ok'] ? 'success' : ($blocked ? 'danger' : 'warning'),
	'icon' => $gate['ok'] ? 'check-circle' : 'exclamation-triangle',
	'message' => \V::app()->html->escape($gate['message']),
	'counts' => $gate['latest'] && !in_array($testState, \models\wf\WfTestRun::ACTIVE, true) ? \V::app()->html->escape(' (' . (int)($testTotals['passed'] ?? 0) . ' of ' . (int)($testTotals['cases'] ?? 0) . ' passed, ' . (\util\Format::datetime($gate['latest']['ended_at'] ?? null) ?: '') . ')') : '',
	'why' => $blocked ? '. Tests must pass to publish: run them in the Tests panel.' : ($gate['required'] && !$gate['ok'] ? '. Tests must pass only for a new version of the draft: this is not one.' : ''),
    ]);
}

//CLD: 2026-11-03T16:00:00+02:00: shared, and its inputs or outputs change
if ($callers)
    $testNote .= \util\VString::insert('<div class="alert alert-warning small py-2 mb-3"><i class="bi bi-exclamation-triangle me-1"></i>This changes the inputs or outputs of a shared workflow. These workflows of other projects call it: {:names}.</div>', [ 'names' => \V::app()->html->escape(implode(', ', $callers)) ]);

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
    $footer = $blocked ? "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button><button type='button' class='btn btn-success' disabled>Publish v{$next}</button>"
	: "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button><button type='submit' class='btn btn-success'>Publish v{$next}</button>";
}

$modal = new \sys\html\Modal([
    'title' => $title,
    'body' => $testNote . $body,
    'footer' => $footer,
    'beforeContent' => $errors ? '' : \V::app()->form->create($wf, [ 'action' => $actionUrl ]),
    'afterContent' => $errors ? '' : \V::app()->form->end(),
    'size' => '',
]);
echo $modal->render();
