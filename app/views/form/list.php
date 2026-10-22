<?php
//CLD: 2026-10-13T18:28:46+03:00: the published forms of the current project (FormController::list)
$esc = fn($s) => \V::app()->html->escape((string)$s);
$this->pageTitle = 'Forms';
//CLD: 2026-10-17T20:21:12+03:00: the forms of the whole account, with their project ($projects)
echo "<div class='p-3'><h4 class='mb-3'><i class='bi bi-ui-checks me-2'></i>Forms</h4>";
if (!$forms) {
    echo "<div class='text-muted'>No published forms: add a Form trigger to a workflow and publish it.</div></div>";
    return;
}
echo "<div class='list-group'>";
foreach($forms as $f) {
    //CLD: 2026-10-15T11:54:00+03:00: the access is in the entry's data (TriggerToken; was FormLink.access)
    $public = ($f['link']->data['access'] ?? 'members') === 'public';
    echo "<a class='list-group-item list-group-item-action d-flex align-items-center gap-2' href='/form/" . $esc($f['link']->token) . "'>"
	. "<i class='bi bi-ui-checks text-primary'></i><span class='flex-fill'>" . $esc($f['link']->title ?: 'Form') . "<span class='text-muted small ms-2'>" . $esc(trim(($projects[(string)$f['link']->project] ?? '') . ' / ' . $f['wf']->name, ' /')) . "</span></span>"
	. ($f['wf']->status !== \models\Workflow::PUBLISHED ? "<span class='badge text-bg-secondary'>not published</span>" : '')
	. "<span class='badge " . ($public ? 'text-bg-warning' : 'text-bg-light border') . "'>" . ($public ? 'anyone with the link' : 'members') . "</span></a>";
}
echo "</div></div>";
