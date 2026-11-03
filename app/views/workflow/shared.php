<?php
//CLD: 2026-11-03T16:00:00+02:00: new: what a shared workflow offers (WorkflowController::shared): $wf, $live (its published version, null: not published now), $contract, $steps
$body = \util\VString::insert('<p class="small text-muted">From project <strong>{:project}</strong>. It runs there, with that project\'s credentials and tables.</p>', [ 'project' => \V::app()->html->escape($wf::projectName($wf->project)) ]);

if (!$live || $contract === null) {
    $body .= '<div class="alert alert-warning small py-2 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>It is not published now: calling it fails until its project publishes it again.</div>';
} else {
    if ($contract['description'] !== '')
	$body .= \util\VString::insert('<p>{:text}</p>', [ 'text' => \V::app()->html->escape($contract['description']) ]);

    $types = \models\node\NodeTriggerCall::TYPES;
    $rows = '';
    foreach($contract['inputs'] as $i)
	$rows .= \util\VString::insert('<tr><td><code>{:name}</code></td><td>{:type}</td><td>{:required}</td><td>{:default}</td></tr>', [
	    'name' => \V::app()->html->escape((string)($i['name'] ?? '')),
	    'type' => \V::app()->html->escape($types[$i['type'] ?? 'text'] ?? 'Any'),
	    'required' => !empty($i['required']) ? 'Yes' : 'No',
	    'default' => \V::app()->html->escape((string)($i['default'] ?? '')),
	]);
    $body .= '<h6>Inputs</h6>' . ($rows !== ''
	? '<div class="table-responsive mb-3"><table class="table table-sm mb-0"><thead><tr><th>Name</th><th>Type</th><th>Required</th><th>Default</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
	: '<p class="small text-muted">None</p>');

    $body .= '<h6>Outputs</h6>' . ($contract['outputs']
	? '<p>' . implode(' ', array_map(fn($o) => \util\VString::insert('<code class="me-2">{:name}</code>', [ 'name' => \V::app()->html->escape($o) ]), $contract['outputs'])) . '</p>'
	: '<p class="small text-muted">None</p>');

    $items = '';
    foreach($steps as $s)
	$items .= \util\VString::insert('<li>{:title} <span class="text-muted small">{:type}</span></li>', [ 'title' => \V::app()->html->escape($s['title']), 'type' => \V::app()->html->escape($s['type']) ]);
    $body .= '<h6>Steps</h6><ol class="mb-0">' . $items . '</ol>';
}

$modal = new \sys\html\Modal([
    'title' => \V::app()->html->escape((string)$wf->name),
    'body' => $body,
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>",
]);
echo $modal->render();
