<?php
$this->pageTitle = 'My tasks';
echo "<div class='p-3'><h4 class='mb-3'><i class='bi bi-person-check me-2'></i>My tasks</h4>";
if (!$tasks) {
    echo "<div class='text-muted'>No open tasks for you.</div></div>";
    return;
}
$rowTpl = '
<a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="/task/{:id}">
    <i class="bi bi-person-check text-primary"></i>
    <span class="flex-fill">{:title}<span class="text-muted small ms-2">{:where}</span></span>
    {:due}{:created}
</a>
';
echo "<div class='list-group'>";
foreach($tasks as $t) {
    $created = \util\Format::datetime($t->created_at ?? null);
    $due = \util\Format::datetime($t->due_at);
    echo \util\VString::insert($rowTpl, [
	'id' => \V::app()->html->escape((string)$t->_id),
	'title' => \V::app()->html->escape((string)$t->title),
	'where' => \V::app()->html->escape(trim(($projects[(string)$t->project] ?? '') . ' / ' . ($names[(string)$t->wf] ?? ''), ' /')),
	'due' => $due !== '' ? \util\VString::insert('<span class="badge text-bg-warning">due {:v}</span>', [ 'v' => \V::app()->html->escape($due) ]) : '',
	'created' => $created !== '' ? \util\VString::insert('<span class="text-muted small">{:v}</span>', [ 'v' => \V::app()->html->escape($created) ]) : '',
    ]);
}
echo "</div></div>";
