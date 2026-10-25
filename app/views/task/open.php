<?php
$this->pageTitle = (string)$task->title;
echo "<div class='p-3' style='max-width: 900px'><div class='mb-2'><a href='/task/list' class='small text-decoration-none'><i class='bi bi-arrow-left me-1'></i>My tasks</a></div>"
    . "<div class='card shadow-sm'><div class='card-body'><h4 class='mb-2'>" . $esc($task->title) . "</h4>";
if (trim((string)$task->instructions) !== '')
    echo "<div class='mb-3' style='white-space: pre-line'>" . \V::app()->html->escape($task->instructions) . "</div>";
if ($task->state !== 'open' || $expired) {
    echo "<div class='alert " . ($task->state === 'done' ? 'alert-success' : 'alert-secondary') . " mb-0'>"
	. ($task->state === 'done' ? 'This task was completed.' : 'This task is no longer open.') . "</div>";
} else {
    $form = \util\SmartForm::normalize($task->form ?? []);
    $url = '/task/' . (string)$task->_id;
    $submitLabel = (string)($task->submitLabel ?: 'Complete');
    echo $this->renderFile('/views/form/_fill', compact('form', 'url', 'submitLabel'));
}
echo "</div></div></div>";
