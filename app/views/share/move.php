<?php
//the Move dialog (ShareController::move): $resource, $object (its kind), $targets (project id => the projects it can move to)
$alertTpl = '<div class="alert alert-warning small py-2 mb-3"><i class="bi bi-exclamation-triangle me-2"></i>{:text}</div>';

$warnings = '';
$breaks = $resource->moveBreaks();
if ($breaks)
    $warnings .= \util\VString::insert($alertTpl, [ 'text' => \util\Format::plural(count($breaks), 'workflow') . (count($breaks) > 1 ? ' of this project use it' : ' of this project uses it') . ' and will fail at that step, until changed or until the new project shares it back: '
	. \V::app()->html->escape(implode(', ', $breaks)) ]);
foreach($resource->moveWarnings() as $warning)
    $warnings .= \util\VString::insert($alertTpl, [ 'text' => \V::app()->html->escape($warning) ]);

$options = '<option value="">Choose a project...</option>';
foreach($targets as $id => $p)
    $options .= \util\VString::insert('<option value="{:value}">{:label}</option>', [ 'value' => \V::app()->html->escape($id), 'label' => \V::app()->html->escape((string)$p->name) ]);

$problem = $resource->moveProblem();
if ($problem !== null)
    $body = \util\VString::insert($alertTpl, [ 'text' => \V::app()->html->escape($problem) ]);
elseif (!$targets)
    $body = '<p class="text-muted small mb-0">There is no other project you can move it to. Personal projects cannot receive it.</p>';
else
    $body = \util\VString::insert('<p class="small text-muted">It leaves project {:home} with everything it holds, and keeps its shares with other projects.</p>{:warnings}
<label class="form-label small mb-1" for="move-project">Move to</label>
<select id="move-project" name="project" class="form-select form-select-sm" required>{:options}</select>', [
	'home' => \V::app()->html->escape((string)\V::app()->om->findById('Project', $resource->project)),
	'warnings' => $warnings,
	'options' => $options,
    ]);

$canMove = $problem === null && $targets;
$modal = new \sys\html\Modal([
    'title' => 'Move: ' . \V::app()->html->escape((string)$resource->name),
    'body' => $body,
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>" . ($canMove ? 'Cancel' : 'Close') . '</button>'
	. ($canMove ? "<button type='submit' class='btn btn-primary'><i class='bi bi-folder-symlink me-1'></i>Move</button>" : ''),
    'beforeContent' => \util\VString::insert('<form method="post" action="{:action}" class="m-0">', [ 'action' => \V::app()->createUrl('/share/move', [ 'object' => $object, 'id' => $resource->_id ]) ]),
    'afterContent' => '</form>',
]);
echo $modal->render();
