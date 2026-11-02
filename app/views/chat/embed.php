<?php

//CLD: 2026-11-02T18:00:00+02:00: new: the embeddable chat page (ChatController::open): the page is the chat, nothing around it

$this->pageTitle = $title;
$id = 'agent-chat-' . bin2hex(random_bytes(4));
echo \util\VString::insert('<div id="{:id}" class="d-flex flex-column" style="height: 100vh"></div>', [ 'id' => $id ]);
\V::app()->cs->addPageScriptFile('/js/app.agentchat.js?' . @filemtime(BASE . '/public_html/js/app.agentchat.js'), []);
\V::app()->cs->addPageScript('AgentChat.start(' . \util\JSON::encode([ 'root' => $id, 'embed' => $token, 'greeting' => $greeting, 'title' => $title ]) . ');');
