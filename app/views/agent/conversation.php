<?php

//CLD: 2026-11-02T18:00:00+02:00: new: one conversation, read only, for the account's owners (AgentController::conversations): the messages, what the tools did on each turn, a link to each run

$this->pageTitle = (string)($conv['title'] ?: 'A conversation');
$toolsOf = [];
foreach($tools as $t)
    $toolsOf[(int)$t['seq']] = $t['tools'];
$rows = '';
foreach($turns as $m) {
    $rowTpl = '<div class="d-flex flex-column mb-2 {:side}"><div class="wfa-msg {:class} rounded-3 px-2 py-1 small">{:body}{:buttons}</div>{:tools}<div class="text-muted" style="font-size: .75rem">{:at}</div></div>';
    $user = $m['role'] === 'user';
    $list = $toolsOf[(int)$m['seq']] ?? [];
    $rows .= \util\VString::insert($rowTpl, [
	'side' => $user ? 'align-items-end' : 'align-items-start',
	'class' => $user ? 'wfa-user' : ($m['role'] === 'notice' ? 'text-danger-emphasis bg-danger-subtle' : 'wfa-bot'),
	'body' => $m['html'] !== '' ? $m['html'] : nl2br(\V::app()->html->escape($m['text']), false),
	'buttons' => $m['buttons'] ? \util\VString::insert('<div class="mt-1 text-muted">Buttons: {:v}</div>', [ 'v' => \V::app()->html->escape(implode(', ', array_column($m['buttons'], 'label'))) ]) : '',
	'tools' => $list ? \util\VString::insert('<div class="small text-muted mt-1"><i class="bi bi-tools me-1"></i>{:v}</div>', [ 'v' => \V::app()->html->escape(\models\AgentConversation::toolsLine($list)) ]) : '',
	'at' => \V::app()->html->escape($m['at'] ? \util\Format::datetime(new \MongoDB\BSON\UTCDateTime(strtotime($m['at']) * 1000)) : ''),
    ]);
}
$tpl = '
<div class="p-3" style="max-width: 1000px">
    <div class="mb-2"><a href="/agent/conversations" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Conversations with agents</a></div>
    <div class="d-flex align-items-center mb-1">
	<h4 class="mb-0 flex-fill">{:title}</h4>
	<a href="/agent/conversations?id={:id}" data-method="post" data-confirm="Delete this conversation?" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</a>
    </div>
    <div class="small text-muted mb-3">{:agent} · {:who} · {:channel}</div>
    {:summary}
    <div class="wf-assistant">{:rows}</div>
</div>
';
echo \util\VString::insert($tpl, [
    'title' => \V::app()->html->escape((string)($conv['title'] ?: 'A conversation')),
    'id' => \V::app()->html->escape((string)$conv['_id']),
    'agent' => \V::app()->html->escape($agent),
    'who' => \V::app()->html->escape((string)($conv['user_name'] ?: $conv['user_email'] ?: 'Guest')),
    'channel' => \V::app()->html->escape((string)$conv['channel']),
    'summary' => (string)($conv['summary'] ?? '') !== '' ? \util\VString::insert('<div class="alert alert-secondary small"><strong>Summary of the older turns:</strong> {:v}</div>', [ 'v' => \V::app()->html->escape((string)$conv['summary']) ]) : '',
    'rows' => $rows,
]);
