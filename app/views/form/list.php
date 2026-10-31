<?php

//the published forms of the current project (FormController::list)

$this->pageTitle = 'Forms';
echo "<div class='p-3'><h4 class='mb-3'><i class='bi bi-ui-checks me-2'></i>Forms</h4>";
if (!$forms) {
    echo "<div class='text-muted'>No published forms: add a Form trigger to a workflow and publish it.</div></div>";
    return;
}
$rowTpl = '
<a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="/form/{:token}">
    <i class="bi bi-ui-checks text-primary"></i>
    <span class="flex-fill">{:title}<span class="text-muted small ms-2">{:where}</span></span>
    {:notPublished}<span class="badge {:accessClass}">{:access}</span>
</a>
';
echo "<div class='list-group'>";
foreach($forms as $f) {
    //CLD: 2026-10-15T11:54:00+03:00: the access is in the entry's data (TriggerToken; was FormLink.access)
    $public = ($f['link']->data['access'] ?? 'members') === 'public';
    echo \util\VString::insert($rowTpl, [
	'token' => \V::app()->html->escape($f['link']->token),
	'title' => \V::app()->html->escape($f['link']->title ?: 'Form'),
	'where' => \V::app()->html->escape(trim(($projects[(string)$f['link']->project] ?? '') . ' / ' . $f['wf']->name, ' /')),
	'notPublished' => $f['wf']->status !== \models\Workflow::PUBLISHED ? '<span class="badge text-bg-secondary">not published</span>' : '',
	'accessClass' => $public ? 'text-bg-warning' : 'text-bg-light border',
	'access' => $public ? 'anyone with the link' : 'members',
    ]);
}
echo "</div></div>";
