<?php

//the agents the user may talk to (AgentController::list)

$this->pageTitle = 'Agents';
echo \util\VString::insert('<div class="p-3"><div class="d-flex align-items-center mb-3"><h4 class="mb-0 flex-fill"><i class="bi bi-chat-dots me-2"></i>Agents</h4>{:all}</div>', [
    'all' => \V::app()->user->can('Account::update') ? '<a class="btn btn-sm btn-outline-secondary" href="/agent/conversations"><i class="bi bi-chat-left-text me-1"></i>All conversations</a>' : '',
]);
if (!$agents) {
    echo "<div class='text-muted'>No agents for you yet. An agent is a published workflow that starts with a Chat trigger.</div></div>";
    return;
}
$rowTpl = '
<a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="/agent/{:token}">
    <i class="bi bi-chat-dots text-primary"></i>
    <span class="flex-fill">{:title}<span class="text-muted small ms-2">{:where}</span>{:description}</span>
</a>
';
echo "<div class='list-group'>";
foreach($agents as $a) {
    $description = trim((string)(((array)$a['link']->data)['description'] ?? ''));
    $title = trim((string)$a['link']->title);
    echo \util\VString::insert($rowTpl, [
	'token' => \V::app()->html->escape($a['link']->token),
	'title' => \V::app()->html->escape($title !== '' && $title !== 'Chat' ? $title : (string)$a['wf']->name),
	'where' => \V::app()->html->escape(trim(($projects[(string)$a['link']->project] ?? '') . ' / ' . $a['wf']->name, ' /')),
	'description' => $description !== '' ? \util\VString::insert('<div class="small text-muted">{:v}</div>', [ 'v' => \V::app()->html->escape($description) ]) : '',
    ]);
}
echo "</div></div>";
