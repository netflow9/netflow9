<?php
$id = (string)$wf->_id;
$published = $wf->status === \models\Workflow::PUBLISHED;

$rowTpl = '
<tr>
    <td class="text-nowrap"><b>{:version}</b>{:badge}</td>
    <td class="text-nowrap">{:when}<br><span class="text-muted small">{:by}</span></td>
    <td class="small">{:note}</td>
    <td class="text-end text-nowrap">{:actions}</td>
</tr>
';
$actionTpl = '<a class="btn btn-sm {:class}" title="{:title}" href="{:href}"{:attrs}><i class="bi {:icon}"></i></a>';
$rows = '';
foreach($versions as $v) {
    $vid = (string)$v->_id;
    $isCurrent = (string)$wf->version === $vid;
    $badge = '';
    if ($isCurrent)
	$badge = $published ? ' <span class="badge text-bg-success">published</span>' : ' <span class="badge text-bg-secondary">unpublished</span>';
    $when = \util\Format::datetime($v->created_at);
    $by = $names[(string)$v->published_by] ?? '';
    $note = \V::app()->html->escape($v->note);
    if (is_numeric($v->from_number))
	$note = \util\VString::insert('<span class="text-muted">republished v{:from}</span>{:note}', [
	    'from' => (int)$v->from_number,
	    'note' => $note !== '' ? '<br>' . $note : '',
	]);

    $q = [ 'id' => $id, 'version' => $vid ];
    $actions = [ \util\VString::insert($actionTpl, [
	'class' => 'btn-outline-secondary',
	'title' => 'View (read-only)',
	'href' => \V::app()->createUrl('/workflow/index', $q),
	'attrs' => '',
	'icon' => 'bi-eye',
    ]) ];
    //CLD: 2026-10-11T19:47:51+03:00: publish again needs Workflow::publish (was Workflow::update); copy into the draft stays Workflow::update
    if ($canPublish && !($isCurrent && $published))
	$actions[] = \util\VString::insert($actionTpl, [
	    'class' => 'btn-outline-success',
	    'title' => 'Publish this version again',
	    'href' => \V::app()->createUrl('/workflow/publish', $q),
	    'attrs' => '',
	    'icon' => 'bi-broadcast',
	]);
    if ($canUpdate)
	$actions[] = \util\VString::insert($actionTpl, [
	    'class' => 'btn-outline-primary',
	    'title' => 'Copy into the draft',
	    'href' => \V::app()->createUrl('/workflow/restore', $q),
	    'attrs' => \util\VString::insert(' data-method="post" data-confirm="Replace the draft with the steps of {:v}?"', [ 'v' => \V::app()->html->escape($v) ]),
	    'icon' => 'bi-arrow-counterclockwise',
	]);

    $rows .= \util\VString::insert($rowTpl, [
	'version' => \V::app()->html->escape($v),
	'badge' => $badge,
	'when' => \V::app()->html->escape($when),
	'by' => \V::app()->html->escape($by),
	'note' => $note,
	'actions' => implode(' ', $actions),
    ]);
}

//CLD: 2026-10-19T22:19:24+03:00: in .table-responsive (scrolls sideways on phones)
$body = $rows === ''
    ? "<p class='text-muted mb-0'>No versions yet: publishing the workflow creates v1.</p>"
    : \util\VString::insert('<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Version</th><th>Published</th><th>Note</th><th></th></tr></thead><tbody>{:rows}</tbody></table></div>', [ 'rows' => $rows ]);

$modal = new \sys\html\Modal([
    'title' => 'Versions of ' . \V::app()->html->escape($wf->name),
    'body' => $body,
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>",
    'size' => 'lg',
]);
echo $modal->render();
