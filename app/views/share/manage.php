<?php
//CLD: 2026-11-03T14:00:00+02:00: new: the Share dialog (ShareController): $resource, $object (its kind), $names (project id => name), $targets (the projects it can still be shared with)
$q = [ 'object' => $object, 'id' => (string)$resource->_id ];
//CLD: 2026-11-07T01:20:20+02:00: the level select (`access`): one option per share level
$levelSelectTpl = '<select name="access" class="form-select form-select-sm" {:attrs}>{:options}</select>';
$levelOptionTpl = '<option value="{:value}"{:selected}>{:label}</option>';
//CLD: 2026-11-03T18:00:00+02:00: a credential an HTTP step can send anywhere: the hosts it may be sent to, or any host (the owner's override); {:id}: unique within the dialog
$hostsTpl = '
<div class="mt-2">
    <label class="form-label small mb-1" for="share-hosts-{:id}">Hosts it may be sent to</label>
    <input id="share-hosts-{:id}" type="text" name="hosts" class="form-control form-control-sm" value="{:hosts}" placeholder="api.example.com, *.example.org">
</div>
<div class="form-check mt-1">
    <input id="share-any-{:id}" class="form-check-input" type="checkbox" name="any_host" value="1"{:any}>
    <label class="form-check-label small" for="share-any-{:id}">Allow any host: workflow editors there can read its secret</label>
</div>';

$rows = '';
$rowTpl = '<tr><td>{:name}{:used}</td><td style="width: 22rem"><form method="post" action="{:change}" class="m-0">{:select}{:hosts}</form></td><td class="text-end" style="width: 7rem">{:remove}</td></tr>';
$removeTpl = '<a class="btn btn-sm btn-outline-danger" href="{:href}" data-method="post" data-confirm="{:confirm}"><i class="bi bi-x-lg me-1"></i>Remove</a>';
foreach((array)$resource->shares as $share) {
    $pid = (string)$share['project'];
    $used = array_column($resource->consumers($pid), 'name');
    $name = $names[$pid] ?? 'A deleted project';
    $levelOptions = '';
    foreach($resource::SHARE_LEVELS as $value => $label)
	$levelOptions .= \util\VString::insert($levelOptionTpl, [ 'value' => \V::app()->html->escape($value), 'selected' => $value === (string)$share['access'] ? ' selected' : '', 'label' => \V::app()->html->escape($label) ]);
    $confirm = 'Stop sharing with ' . \V::app()->html->escape($name) . '?' . ($used ? ' ' . count($used) . ' workflow(s) there will fail when they use it.' : '');
    $rows .= \util\VString::insert($rowTpl, [
	'name' => \V::app()->html->escape($name),
	'used' => $used
	    ? '<div class="form-text mt-0">Used by: ' . \V::app()->html->escape(implode(', ', $used)) . '</div>'
	    : '<div class="form-text mt-0">No workflow uses it there</div>',
	'change' => \V::app()->createUrl('/share/change', $q + [ 'project' => $pid ]),
	//CLD: 2026-11-03T18:00:00+02:00: with hosts: a Save button (the fields are typed; the level alone saves when it changes)
	'select' => \util\VString::insert($levelSelectTpl, [ 'attrs' => ($resource->shareUsesHosts() ? '' : "onchange='this.form.requestSubmit()' ") . "aria-label='Level'", 'options' => $levelOptions ]),
	'hosts' => $resource->shareUsesHosts()
	    ? \util\VString::insert($hostsTpl, [ 'id' => $pid, 'hosts' => \V::app()->html->escape(implode(', ', array_map('strval', (array)($share['hosts'] ?? [])))), 'any' => !empty($share['any_host']) ? ' checked' : '' ]) . '<button type="submit" class="btn btn-sm btn-outline-primary mt-2">Save</button>'
	    : '',
	//the confirmation is shown as HTML after the attribute is read: escaped for both
	'remove' => \util\VString::insert($removeTpl, [
	    'href' => \V::app()->createUrl('/share/remove', $q + [ 'project' => $pid ]),
	    'confirm' => \V::app()->html->escape($confirm),
	]),
    ]);
}

$list = $rows === ''
    ? '<p class="text-muted small mb-3">It is not shared yet: only its own project can use it.</p>'
    : '<div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0"><thead><tr><th>Project</th><th>Level</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

$options = '<option value="">Choose a project...</option>';
foreach($targets as $id => $label)
    $options .= \util\VString::insert('<option value="{:value}">{:label}</option>', [ 'value' => \V::app()->html->escape($id), 'label' => \V::app()->html->escape($label) ]);
//CLD: 2026-11-03T16:00:00+02:00: what the resource refuses comes first (a workflow not published)
$problem = $resource->shareProblem();
$add = $problem !== null
    ? \util\VString::insert('<div class="alert alert-warning small py-2 mb-0"><i class="bi bi-exclamation-triangle me-2"></i>{:text}</div>', [ 'text' => \V::app()->html->escape($problem) ])
    : ($targets
    ? \util\VString::insert('
<form method="post" action="{:action}" class="row g-2 align-items-end">
    <div class="col-sm-6"><label class="form-label small mb-1">Share with</label><select name="project" class="form-select form-select-sm" required>{:options}</select></div>
    <div class="col-sm-4"><label class="form-label small mb-1">Level</label>{:level}</div>
    <div class="col-sm-2"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-share me-1"></i>Share</button></div>
    <div class="col-12">{:hosts}</div>
</form>', [
	'action' => \V::app()->createUrl('/share/manage', $q),
	'options' => $options,
	'level' => \util\VString::insert($levelSelectTpl, [ 'attrs' => '', 'options' => implode('', array_map(fn($value, $label) => \util\VString::insert($levelOptionTpl, [ 'value' => \V::app()->html->escape($value), 'selected' => $value === array_key_first($resource::SHARE_LEVELS) ? ' selected' : '', 'label' => \V::app()->html->escape($label) ]), array_keys($resource::SHARE_LEVELS), $resource::SHARE_LEVELS)) ]),
	'hosts' => $resource->shareUsesHosts() ? \util\VString::insert($hostsTpl, [ 'id' => 'new', 'hosts' => '', 'any' => '' ]) : '',
    ])
    : '<p class="text-muted small mb-0">No other project can receive it. Personal projects do not take part in sharing.</p>');

$warnings = '';
foreach($resource->shareWarnings() as $warning)
    $warnings .= \util\VString::insert('<div class="alert alert-warning small py-2 mb-3"><i class="bi bi-exclamation-triangle me-2"></i>{:text}</div>', [ 'text' => \V::app()->html->escape($warning) ]);

$modal = new \sys\html\Modal([
    'title' => 'Share: ' . \V::app()->html->escape((string)$resource->name),
    'body' => '<p class="small text-muted">Every workflow editor of a project you choose can use it. It stays in this project: only this project can change or delete it.</p>' . $warnings . $list . $add,
    'footer' => "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>",
]);
echo $modal->render();
