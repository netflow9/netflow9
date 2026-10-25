<?php
$id = (string)$wf->_id;
$published = $wf->status === \models\Workflow::PUBLISHED;

$rows = '';
foreach($versions as $v) {
    $vid = (string)$v->_id;
    $isCurrent = (string)$wf->version === $vid;
    $badge = '';
    if ($isCurrent)
	$badge = $published ? " <span class='badge text-bg-success'>published</span>" : " <span class='badge text-bg-secondary'>unpublished</span>";
    $when = \util\Format::datetime($v->created_at);
    $by = $names[(string)$v->published_by] ?? '';
    $note = \V::app()->html->escape($v->note);
    if (is_numeric($v->from_number))
	$note = "<span class='text-muted'>republished v" . (int)$v->from_number . "</span>" . ($note !== '' ? '<br>' . $note : '');

    $actions = "<a class='btn btn-sm btn-outline-secondary' title='View (read-only)' href='" . \V::app()->createUrl('/workflow/index', [ 'id' => $id, 'version' => $vid ]) . "'><i class='bi bi-eye'></i></a>";
    //CLD: 2026-10-11T19:47:51+03:00: publish again needs Workflow::publish (was Workflow::update); copy into the draft stays Workflow::update
    if ($canPublish && !($isCurrent && $published))
	$actions .= " <a class='btn btn-sm btn-outline-success' title='Publish this version again' href='" . \V::app()->createUrl('/workflow/publish', [ 'id' => $id, 'version' => $vid ]) . "'><i class='bi bi-broadcast'></i></a>";
    if ($canUpdate)
	$actions .= " <a class='btn btn-sm btn-outline-primary' title='Copy into the draft' href='" . \V::app()->createUrl('/workflow/restore', [ 'id' => $id, 'version' => $vid ]) . "' data-method='post' data-confirm='Replace the draft with the steps of " . \V::app()->html->escape($v) . "?'><i class='bi bi-arrow-counterclockwise'></i></a>";

    $rows .= "<tr><td class='text-nowrap'><b>" . \V::app()->html->escape($v) . "</b>{$badge}</td><td class='text-nowrap'>" . \V::app()->html->escape($when) . "<br><span class='text-muted small'>" . \V::app()->html->escape($by) . "</span></td><td class='small'>{$note}</td><td class='text-end text-nowrap'>{$actions}</td></tr>";
}

//CLD: 2026-10-19T22:19:24+03:00: in .table-responsive (scrolls sideways on phones)
$body = $rows === ''
    ? "<p class='text-muted mb-0'>No versions yet: publishing the workflow creates v1.</p>"
    : "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr><th>Version</th><th>Published</th><th>Note</th><th></th></tr></thead><tbody>{$rows}</tbody></table></div>";

$modal = new \sys\html\Modal([
    'title' => 'Versions of ' . \V::app()->html->escape($wf->name),
    'body' => $body,
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>",
    'size' => 'lg',
]);
echo $modal->render();
