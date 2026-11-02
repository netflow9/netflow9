<?php

//CLD: 2026-11-02T18:00:00+02:00: new: every conversation of the account, for its owners (AgentController::conversations)

$this->pageTitle = 'Conversations with agents';
echo \util\VString::insert('
<div class="p-3">
    <div class="mb-2"><a href="/agent/list" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Agents</a></div>
    <h4 class="mb-1"><i class="bi bi-chat-left-text me-2"></i>Conversations with agents</h4>
    <div class="small text-muted mb-3">{:retention}</div>
', [ 'retention' => \V::app()->html->escape($retention > 0 ? "Kept {$retention} days after their last message (Account settings: log retention)." : 'Kept until they are deleted (Account settings: log retention).') ]);
if (!$list) {
    echo "<div class='text-muted'>No conversations yet.</div></div>";
    return;
}
$rowTpl = '<tr><td><a href="/agent/conversations?id={:id}">{:title}</a></td><td>{:agent}</td><td>{:who}</td><td><span class="badge text-bg-light border">{:channel}</span></td><td class="text-end">{:turns}</td><td class="text-nowrap">{:at}</td></tr>';
echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Conversation</th><th>Agent</th><th>With</th><th>Channel</th><th class="text-end">Messages</th><th>Last message</th></tr></thead><tbody>';
foreach($list as $c)
    echo \util\VString::insert($rowTpl, [
	'id' => \V::app()->html->escape((string)$c['_id']),
	'title' => \V::app()->html->escape((string)($c['title'] ?: 'A conversation')),
	'agent' => \V::app()->html->escape((string)$c['agent']),
	'who' => \V::app()->html->escape((string)($c['user_name'] ?: $c['user_email'] ?: 'Guest')),
	'channel' => \V::app()->html->escape((string)$c['channel']),
	'turns' => (int)$c['turns'],
	'at' => \V::app()->html->escape(\util\Format::datetime($c['last_at'] ?? null)),
    ]);
echo '</tbody></table></div></div>';
