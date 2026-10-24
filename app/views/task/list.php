<?php
//CLD: 2026-10-13T18:29:31+03:00: My tasks: the open tasks of the current project the user may complete (TaskController::list)
$esc = fn($s) => \V::app()->html->escape((string)$s);
$this->pageTitle = 'My tasks';
//CLD: 2026-10-17T20:21:12+03:00: the tasks of the whole account, with their project ($projects)
echo "<div class='p-3'><h4 class='mb-3'><i class='bi bi-person-check me-2'></i>My tasks</h4>";
if (!$tasks) {
    echo "<div class='text-muted'>No open tasks for you.</div></div>";
    return;
}
echo "<div class='list-group'>";
foreach($tasks as $t) {
    //CLD: 2026-10-24T16:30:00+03:00: the account's datetime format (util\Format; was Y-m-d H:i)
    $created = \util\Format::datetime($t->created_at ?? null);
    $due = \util\Format::datetime($t->due_at);
    echo "<a class='list-group-item list-group-item-action d-flex align-items-center gap-2' href='/task/" . $esc($t->_id) . "'>"
	. "<i class='bi bi-person-check text-primary'></i><span class='flex-fill'>" . $esc($t->title)
	. "<span class='text-muted small ms-2'>" . $esc(trim(($projects[(string)$t->project] ?? '') . ' / ' . ($names[(string)$t->wf] ?? ''), ' /')) . "</span></span>"
	. ($due !== '' ? "<span class='badge text-bg-warning'>due " . $esc($due) . "</span>" : '')
	. ($created !== '' ? "<span class='text-muted small'>" . $esc($created) . "</span>" : '') . "</a>";
}
echo "</div></div>";
