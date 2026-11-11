<?php
//an audit log entry (AuditController::view, dialog): who, when, what, and its changes (before -> after)
//the account's datetime format (util\Format)
$when = \util\Format::datetime($entry->created_at);

//a logged value (models\AuditLog::valueText: text as is, other values as JSON), or empty
$valueTpl = '<span style="white-space: pre-wrap; word-break: break-word">{:v}</span>';
$emptyHtml = "<span class='text-muted'>empty</span>";

//an execution log entry shows what acted (source, workflow, execution, step, who clicked Run) instead of the user / IP / browser
$isExec = $entry instanceof \models\ExecutionLog;
$facts = [ 'When' => \V::app()->html->escape($when) ];
if ($isExec) {
    $facts['Source'] = $entry->schema['source']->display();
    $facts['Started by'] = \V::app()->html->escape($entry->started_by);
} else {
    $facts['User'] = \V::app()->html->escape($entry->user_name);
}
$facts += [
    'Action' => $entry->schema['action']->display(),
    'Object' => \V::app()->html->escape(trim($entry->type . ': ' . $entry->name, ': ')),
    'Project' => $entry->project ? $entry->schema['project']->display() : '',
];
if ($isExec) {
    $wfLink = '';
    if ($entry->workflow) {
	$wfLink = \util\VString::insert('<a href="{:href}">{:name}</a>{:execution}', [
	    'href' => \V::app()->createUrl('/workflow/index', [ 'id' => $entry->workflow ]),
	    'name' => $entry->schema['workflow']->display(),
	    'execution' => $entry->execution
		? \util\VString::insert(' <span class="small text-muted">execution {:v}</span>', [ 'v' => \V::app()->html->escape($entry->execution) ])
		: '',
	]);
    }
    $facts['Workflow'] = $wfLink;
    $facts['Step'] = \V::app()->html->escape($entry->step);
}
$facts['Details'] = \V::app()->html->escape($entry->details);
//an agent's tool call: its workflow, execution, step, tool, read or write, how it ended, who approved it, the arguments (secrets removed)
$agent = !$isExec && is_array($entry->agent) ? $entry->agent : null;
if ($agent) {
    $agentWf = $entry->workflow ? \V::app()->om->findById('Workflow', (string)$entry->workflow) : null;
    $facts['Workflow'] = $entry->workflow ? \util\VString::insert('<a href="{:href}">{:name}</a> <span class="small text-muted">execution {:execution}</span>', [
	'href' => \V::app()->createUrl('/workflow/index', [ 'id' => $entry->workflow ]),
	'name' => \V::app()->html->escape($agentWf ? (string)$agentWf->name : (string)$entry->workflow),
	'execution' => \V::app()->html->escape($entry->execution),
    ]) : '';
    $facts['Step'] = \V::app()->html->escape($entry->step);
    $facts['Tool'] = \V::app()->html->escape($agent['tool'] ?? '');
    $facts['Read or write'] = \V::app()->html->escape($agent['access'] ?? '');
    $facts['Result'] = \V::app()->html->escape($agent['status'] ?? '');
    $facts['Approved by'] = \V::app()->html->escape($agent['approved_by'] ?? '');
    $facts['Arguments'] = (($t = \models\AuditLog::valueText($agent['arguments'] ?? [])) === null ? $emptyHtml : \util\VString::insert($valueTpl, [ 'v' => \V::app()->html->escape($t) ]));
}
if (!$isExec && !$agent) {
    $facts['IP'] = \V::app()->html->escape($entry->ip);
    $facts['Browser'] = \util\VString::insert('<span class="small text-muted">{:v}</span>', [ 'v' => \V::app()->html->escape($entry->user_agent) ]);
}
$dl = '';
foreach($facts as $label => $value) {
    if ((string)$value !== '')
	$dl .= \util\VString::insert('<dt class="col-3">{:label}</dt><dd class="col-9 mb-1">{:value}</dd>', [ 'label' => $label, 'value' => $value ]);
}

$changes = is_array($entry->changes) ? $entry->changes : [];
$hasBefore = $hasAfter = false;
foreach($changes as $c) {
    $hasBefore = $hasBefore || array_key_exists('before', $c);
    $hasAfter = $hasAfter || array_key_exists('after', $c);
}

$rowTpl = '<tr><td class="text-nowrap">{:label}</td>{:cells}</tr>';
$maskedTpl = '<td colspan="{:span}" class="text-muted"><i class="bi bi-lock me-1"></i>changed (not shown)</td>';
$rows = '';
foreach($changes as $c) {
    $cells = '';
    if (!empty($c['masked'])) {
	$cells = \util\VString::insert($maskedTpl, [ 'span' => (int)$hasBefore + (int)$hasAfter ]);
    } else {
	if ($hasBefore)
	    $cells .= \util\VString::insert('<td>{:value}</td>', [ 'value' => array_key_exists('before', $c) ? (($t = \models\AuditLog::valueText($c['before'])) === null ? $emptyHtml : \util\VString::insert($valueTpl, [ 'v' => \V::app()->html->escape($t) ])) : '' ]);
	if ($hasAfter)
	    $cells .= \util\VString::insert('<td>{:value}{:inner}</td>', [
		'value' => array_key_exists('after', $c) ? (($t = \models\AuditLog::valueText($c['after'])) === null ? $emptyHtml : \util\VString::insert($valueTpl, [ 'v' => \V::app()->html->escape($t) ])) : '',
		'inner' => !empty($c['inner']) ? '<div class="small text-muted">contents changed</div>' : '',
	    ]);
    }
    $rows .= \util\VString::insert($rowTpl, [ 'label' => \V::app()->html->escape($c['label'] ?? $c['key'] ?? ''), 'cells' => $cells ]);
}

$table = '';
if ($rows !== '') {
    //in .table-responsive (long before / after values scroll sideways on phones)
    $tableTpl = '
<div class="table-responsive"><table class="table table-sm align-top small mb-0">
    <thead><tr><th>Field</th>{:before}{:after}</tr></thead>
    <tbody>{:rows}</tbody>
</table></div>
';
    $table = \util\VString::insert($tableTpl, [
	'before' => $hasBefore ? '<th>Before</th>' : '',
	'after' => $hasAfter ? '<th>After</th>' : '',
	'rows' => $rows,
    ]);
}

$modal = new \sys\html\Modal([
    'title' => $isExec ? 'Execution log entry' : 'Audit log entry',
    'body' => \util\VString::insert('<dl class="row mb-3">{:dl}</dl>{:table}', [ 'dl' => $dl, 'table' => $table ]),
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>",
    'size' => 'lg',
]);
echo $modal->render();
