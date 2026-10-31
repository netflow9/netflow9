<?php
//CLD: 2026-10-11T17:35:22+03:00: new view: an audit log entry (AuditController::view, dialog): who, when, what, and its changes (before -> after)
//CLD: 2026-10-24T16:30:00+03:00: the account's datetime format (util\Format; was Y-m-d H:i:s)
$when = \util\Format::datetime($entry->created_at);

//a logged value: text as is, other values as JSON
$show = function($v) {
    if ($v === null)
	return "<span class='text-muted'>empty</span>";
    if (is_bool($v))
	return $v ? 'yes' : 'no';
    if (is_array($v) || is_object($v))
	$v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE);
    return \util\VString::insert('<span style="white-space: pre-wrap; word-break: break-word">{:v}</span>', [ 'v' => \V::app()->html->escape($v) ]);
};

//CLD: 2026-10-11T17:45:25+03:00: an execution log entry shows what acted (source, workflow, execution, step, who clicked Run) instead of the user / IP / browser
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
	    'href' => \V::app()->createUrl('/workflow/index', [ 'id' => (string)$entry->workflow ]),
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
if (!$isExec) {
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
	    $cells .= \util\VString::insert('<td>{:value}</td>', [ 'value' => array_key_exists('before', $c) ? $show($c['before']) : '' ]);
	if ($hasAfter)
	    $cells .= \util\VString::insert('<td>{:value}{:inner}</td>', [
		'value' => array_key_exists('after', $c) ? $show($c['after']) : '',
		'inner' => !empty($c['inner']) ? '<div class="small text-muted">contents changed</div>' : '',
	    ]);
    }
    $rows .= \util\VString::insert($rowTpl, [ 'label' => \V::app()->html->escape($c['label'] ?? $c['key'] ?? ''), 'cells' => $cells ]);
}

$table = '';
if ($rows !== '') {
    //CLD: 2026-10-19T22:19:24+03:00: in .table-responsive (long before / after values scroll sideways on phones)
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
