<?php
$this->pageTitle = 'My tasks';
echo "<div class='p-3'><h4 class='mb-3'><i class='bi bi-person-check me-2'></i>My tasks</h4>";
if (!$tasks) {
    echo "<div class='text-muted'>No open tasks for you.</div></div>";
    return;
}
echo "<div class='list-group'>";
foreach($tasks as $t) {
    $created = \util\Format::datetime($t->created_at ?? null);
    $due = \util\Format::datetime($t->due_at);
    echo "<a class='list-group-item list-group-item-action d-flex align-items-center gap-2' href='/task/" . $esc($t->_id) . "'>"
	. "<i class='bi bi-person-check text-primary'></i><span class='flex-fill'>" . $esc($t->title)
	. "<span class='text-muted small ms-2'>" . \V::app()->html->escape(trim(($projects[(string)$t->project] ?? '') . ' / ' . ($names[(string)$t->wf] ?? ''), ' /')) . "</span></span>"
	. ($due !== '' ? "<span class='badge text-bg-warning'>due " . \V::app()->html->escape($due) . "</span>" : '')
	. ($created !== '' ? "<span class='text-muted small'>" . \V::app()->html->escape($created) . "</span>" : '') . "</a>";
}
echo "</div></div>";
