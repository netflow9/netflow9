<?php

//the chat page of an agent (AgentController::open): the user's conversations on the left, the conversation (public_html/js/app.agentchat.js)

$this->pageTitle = $title;
$id = 'agent-chat-' . bin2hex(random_bytes(4));
$rowTpl = '<a class="list-group-item list-group-item-action small text-truncate{:active}" href="/agent/{:token}?conversation={:id}">{:title}<div class="text-muted" style="font-size: .75rem">{:at}</div></a>';
$rows = '';
foreach($conversations as $c) {
    $isCurrent = $current && (string)$current['_id'] === (string)$c['_id'];
    $rows .= \util\VString::insert($rowTpl, [
	'active' => $isCurrent ? ' active' : '',
	'token' => \V::app()->html->escape($link->token),
	'id' => \V::app()->html->escape((string)$c['_id']),
	'title' => \V::app()->html->escape((string)($c['title'] ?: 'A conversation')),
	'at' => \V::app()->html->escape(\util\Format::datetime($c['last_at'] ?? null)),
    ]);
}
$tpl = '
<div class="p-3 d-flex flex-column" style="height: calc(100vh - 3.5rem)">
    <div class="mb-2"><a href="/agent/list" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Agents</a></div>
    <div class="d-flex align-items-center mb-2"><h4 class="mb-0 flex-fill"><i class="bi bi-chat-dots me-2"></i>{:title}</h4></div>
    <div class="row g-3 flex-fill min-height-0">
	<div class="col-md-3 d-none d-md-flex flex-column h-100 min-height-0">
	    <a class="btn btn-sm btn-outline-primary mb-2" href="/agent/{:token}"><i class="bi bi-plus-lg me-1"></i>New conversation</a>
	    <div id="{:id}-list" class="list-group overflow-y-auto">{:rows}</div>
	</div>
	<div class="col-md-9 d-flex flex-column h-100 min-height-0">
	    <div id="{:id}" class="card shadow-sm flex-fill d-flex flex-column min-height-0"></div>
	</div>
    </div>
</div>
';
echo \util\VString::insert($tpl, [
    'title' => \V::app()->html->escape($title),
    'token' => \V::app()->html->escape($link->token),
    'rows' => $rows,
    'id' => $id,
]);
\V::app()->cs->addPageScript('window.AgentChat && AgentChat.start(' . \util\JSON::encode([
    'root' => $id,
    'agent' => (string)$link->token,
    'conversation' => $current ? (string)$current['_id'] : null,
    'greeting' => $greeting,
    'messages' => $messages,
    'status' => $status,
    'canRemove' => (bool)$current,
    'pageUrl' => '/agent/' . $link->token,
    'listId' => $id . '-list',
]) . ');');
