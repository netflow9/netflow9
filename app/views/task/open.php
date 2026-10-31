<?php
$this->pageTitle = (string)$task->title;
$tpl = '
<div class="p-3" style="max-width: 900px">
    <div class="mb-2"><a href="/task/list" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>My tasks</a></div>
    <div class="card shadow-sm"><div class="card-body">
	<h4 class="mb-2">{:title}</h4>
	{:instructions}
	{:content}
    </div></div>
</div>
';
if ($task->state !== 'open' || $expired) {
    $content = \util\VString::insert('<div class="alert {:class} mb-0">{:text}</div>', [
	'class' => $task->state === 'done' ? 'alert-success' : 'alert-secondary',
	'text' => $task->state === 'done' ? 'This task was completed.' : 'This task is no longer open.',
    ]);
} else {
    $form = \util\SmartForm::normalize($task->form ?? []);
    $url = '/task/' . (string)$task->_id;
    $submitLabel = (string)($task->submitLabel ?: 'Complete');
    $content = $this->renderFile('/views/form/_fill', compact('form', 'url', 'submitLabel'));
}
echo \util\VString::insert($tpl, [
    'title' => \V::app()->html->escape((string)$task->title),
    'instructions' => trim((string)$task->instructions) !== ''
	? \util\VString::insert('<div class="mb-3" style="white-space: pre-line">{:text}</div>', [ 'text' => \V::app()->html->escape($task->instructions) ])
	: '',
    'content' => $content,
]);
