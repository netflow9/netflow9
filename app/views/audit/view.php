<?php
//CLD: 2026-10-11T17:35:22+03:00: new view: an audit log entry (AuditController::view, dialog): who, when, what, and its changes (before -> after)
$esc = fn($v) => \V::app()->html->escape((string)$v);
//CLD: 2026-10-24T16:30:00+03:00: the account's datetime format (util\Format; was Y-m-d H:i:s)
$when = \util\Format::datetime($entry->created_at);

//a logged value: text as is, other values as JSON
$show = function($v) use ($esc) {
    if ($v === null)
	return "<span class='text-muted'>empty</span>";
    if (is_bool($v))
	return $v ? 'yes' : 'no';
    if (is_array($v) || is_object($v))
	$v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE);
    return "<span style='white-space: pre-wrap; word-break: break-word'>" . $esc($v) . "</span>";
};

//CLD: 2026-10-11T17:45:25+03:00: an execution log entry shows what acted (source, workflow, execution, step, who clicked Run) instead of the user / IP / browser
$isExec = $entry instanceof \models\ExecutionLog;
$facts = [ 'When' => $esc($when) ];
if ($isExec) {
    $facts['Source'] = $entry->schema['source']->display();
    $facts['Started by'] = $esc($entry->started_by);
} else {
    $facts['User'] = $esc($entry->user_name);
}
$facts += [
    'Action' => $entry->schema['action']->display(),
    'Object' => $esc(trim($entry->type . ': ' . $entry->name, ': ')),
    'Project' => $entry->project ? $entry->schema['project']->display() : '',
];
if ($isExec) {
    $wfLink = '';
    if ($entry->workflow) {
	$wfLink = "<a href='" . \V::app()->createUrl('/workflow/index', [ 'id' => (string)$entry->workflow ]) . "'>" . $entry->schema['workflow']->display() . '</a>';
	if ($entry->execution)
	    $wfLink .= " <span class='small text-muted'>execution " . $esc($entry->execution) . '</span>';
    }
    $facts['Workflow'] = $wfLink;
    $facts['Step'] = $esc($entry->step);
}
$facts['Details'] = $esc($entry->details);
if (!$isExec) {
    $facts['IP'] = $esc($entry->ip);
    $facts['Browser'] = "<span class='small text-muted'>" . $esc($entry->user_agent) . "</span>";
}
$dl = '';
foreach($facts as $label => $value) {
    if ((string)$value !== '')
	$dl .= "<dt class='col-3'>{$label}</dt><dd class='col-9 mb-1'>{$value}</dd>";
}

$changes = is_array($entry->changes) ? $entry->changes : [];
$hasBefore = $hasAfter = false;
foreach($changes as $c) {
    $hasBefore = $hasBefore || array_key_exists('before', $c);
    $hasAfter = $hasAfter || array_key_exists('after', $c);
}

$rows = '';
foreach($changes as $c) {
    $label = $esc($c['label'] ?? $c['key'] ?? '');
    if (!empty($c['masked'])) {
	$rows .= "<tr><td class='text-nowrap'>{$label}</td><td colspan='" . ((int)$hasBefore + (int)$hasAfter) . "' class='text-muted'><i class='bi bi-lock me-1'></i>changed (not shown)</td></tr>";
	continue;
    }
    $cells = '';
    if ($hasBefore)
	$cells .= '<td>' . (array_key_exists('before', $c) ? $show($c['before']) : '') . '</td>';
    if ($hasAfter)
	$cells .= '<td>' . (array_key_exists('after', $c) ? $show($c['after']) : '') . (!empty($c['inner']) ? "<div class='small text-muted'>contents changed</div>" : '') . '</td>';
    $rows .= "<tr><td class='text-nowrap'>{$label}</td>{$cells}</tr>";
}

$table = '';
if ($rows !== '') {
    $head = '<th>Field</th>' . ($hasBefore ? '<th>Before</th>' : '') . ($hasAfter ? '<th>After</th>' : '');
    //CLD: 2026-10-19T22:19:24+03:00: in .table-responsive (long before / after values scroll sideways on phones)
    $table = "<div class='table-responsive'><table class='table table-sm align-top small mb-0'><thead><tr>{$head}</tr></thead><tbody>{$rows}</tbody></table></div>";
}

$modal = new \sys\html\Modal([
    'title' => $isExec ? 'Execution log entry' : 'Audit log entry',
    'body' => "<dl class='row mb-3'>{$dl}</dl>{$table}",
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>",
    'size' => 'lg',
]);
echo $modal->render();
