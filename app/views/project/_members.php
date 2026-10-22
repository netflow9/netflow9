<?php
//CLD: 2026-10-13T20:55:43+03:00: members can be managed (Project::update): add a user of the account with a role, change a member's role (the select saves it), remove a member
//(ProjectController::member_add / member_role / member_remove); read-only without the permission; a personal project has no members tab content (as before)
//CLD: 2026-10-13T21:07:43+03:00: a personal project says why it has no members (the tab was empty)
if ($project->personal) {
    echo "<div class='alert alert-info mt-4 mb-0'><i class='bi bi-person-lock me-2'></i>This is your personal project: it has no other members. "
	. "To work with others, create a project (<i class='bi bi-plus-lg'></i> at the top of the sidebar, <b>New project</b>) and add them in its Members tab.</div>";
    return;
}

$esc = fn($s) => \V::app()->html->escape((string)$s);
$canManage = \V::app()->user->can('Project::update', [ 'object' => $project ]);
$roles = [];
foreach(\V::app()->om->find('Role', [ 'aid' => $project->aid ], [ 'sort' => [ 'name' => 1 ] ]) as $r)
    $roles[(string)$r->_id] = (string)$r;
$members = (array)$project->members;
$roleSelect = function($name, $selected, $attrs = '') use ($roles, $esc) {
    $html = "<select name='{$name}' class='form-select form-select-sm' {$attrs}>";
    foreach($roles as $id => $label)
	$html .= "<option value='" . $esc($id) . "'" . ($id === (string)$selected ? ' selected' : '') . ">" . $esc($label) . "</option>";
    return $html . '</select>';
};

echo '<div class="w-100 mt-4"></div>';

if ($canManage) {
    $candidates = [];
    foreach(\V::app()->om->find('User', [ 'aid' => $project->aid ], [ 'sort' => [ 'firstname' => 1 ] ]) as $u) {
	if (!isset($members[(string)$u->_id]))
	    $candidates[(string)$u->_id] = (string)$u . ($u->email ? ' <' . $u->email . '>' : '');
    }
    $memberRole = (string)(\V::app()->om->findOne('Role', [ 'aid' => $project->aid, 'iname' => 'member' ])->_id ?? '');
    echo "<form method='post' action='" . \V::app()->createUrl('/project/member_add', [ 'id' => (string)$project->_id ]) . "' class='d-flex flex-wrap gap-2 align-items-end mb-3'>"
	. "<div><label class='form-label small mb-1'>Add a member</label><select name='user' class='form-select form-select-sm' required style='min-width: 16rem'" . ($candidates ? '' : ' disabled') . ">"
	. ($candidates ? "<option value=''>Choose a user...</option>" : "<option>All the account's users are members</option>");
    foreach($candidates as $id => $label)
	echo "<option value='" . $esc($id) . "'>" . $esc($label) . "</option>";
    echo "</select></div><div><label class='form-label small mb-1'>Role</label>" . $roleSelect('role', $memberRole) . "</div>"
	. "<button type='submit' class='btn btn-sm btn-primary'" . ($candidates ? '' : ' disabled') . "><i class='bi bi-person-plus me-1'></i>Add</button></form>";

    //CLD: 2026-10-17T22:00:00+03:00: invite by email (ProjectController::member_invite): a user of the account is added; another address gets a link to join (needs User::create)
    $canInvite = \V::app()->user->can('User::create');
    echo "<form method='post' action='" . \V::app()->createUrl('/project/member_invite', [ 'id' => (string)$project->_id ]) . "' class='d-flex flex-wrap gap-2 align-items-end mb-1'>"
	. "<div><label class='form-label small mb-1'>Invite by email</label><input type='email' name='email' class='form-control form-control-sm' required style='min-width: 16rem'"
	. " placeholder='name@example.com' autocomplete='off'></div><div><label class='form-label small mb-1'>Role</label>" . $roleSelect('role', $memberRole) . "</div>"
	. "<button type='submit' class='btn btn-sm btn-outline-primary'><i class='bi bi-envelope-plus me-1'></i>Invite</button></form>"
	. "<div class='form-text mb-3'>" . ($canInvite ? 'A user of the account is added now; anyone else gets an email with a link to join, valid 7 days.'
	    : 'Users of the account are added now; only who may add users can invite new people.') . "</div>";
}

$wDef = [
	'objectName' => 'User',
	'dataProvider' => new \sys\DataProviderArray($members),

	'layout' => "
	    {items}
	",

	'columns' => [
	    [
		'header' => 'User',
		'content' => function($key, $item, $widget) use ($esc) {
		    return $esc(\V::app()->om->findById('User', $key));
		},
	    ],
	    [
		'header' => 'Role',
		'content' => function($key, $item, $widget) use ($canManage, $roles, $roleSelect, $project, $esc) {
		    if (!$canManage)
			return $esc($roles[(string)($item['role'] ?? '')] ?? '');
		    return "<form method='post' action='" . \V::app()->createUrl('/project/member_role', [ 'id' => (string)$project->_id, 'user' => (string)$key ]) . "' class='m-0' style='max-width: 14rem'>"
			. $roleSelect('role', $item['role'] ?? '', "onchange='this.form.requestSubmit()' aria-label='Role'") . "</form>";
		},
		'cellOptions' => [
		    'style' => 'width: 16rem',
		],
	    ],
	    [
		'header' => '&nbsp;',
		'content' => function($key, $item, $widget) use ($canManage, $project, $esc) {
		    if (!$canManage)
			return '';
		    $name = (string)\V::app()->om->findById('User', $key);
		    return "<a class='btn btn-sm btn-outline-danger' href='" . \V::app()->createUrl('/project/member_remove', [ 'id' => (string)$project->_id, 'user' => (string)$key ]) . "'"
			. " data-method='post' data-confirm='" . $esc('Remove ' . $name . ' from the project?') . "'><i class='bi bi-person-dash me-1'></i>Remove</a>";
		},
		'cellOptions' => [
		    'style' => 'width: 8rem',
		],
	    ]
	],
];

echo (new \sys\widgets\GridView($wDef))->render();

//CLD: 2026-10-17T22:00:00+03:00: the pending invitations (not joined yet): send again / cancel
$invites = $canManage ? iterator_to_array(\models\user\ProjectInvite::pending($project), false) : [];
if ($invites) {
    //CLD: 2026-10-19T22:19:24+03:00: in .table-responsive (scrolls sideways on phones; its fixed-width columns did not fit)
    echo "<h6 class='mt-4 mb-2'>Invited, not joined yet</h6><div class='table-responsive'><table class='table table-sm align-middle'><tbody>";
    foreach($invites as $inv) {
	$by = $inv->invited_by ? \V::app()->om->findById('User', $inv->invited_by) : null;
	$q = [ 'id' => (string)$project->_id, 'invite' => (string)$inv->_id ];
	echo "<tr><td><i class='bi bi-envelope me-2 text-body-secondary'></i>" . $esc($inv->email) . "</td>"
	    . "<td style='width: 16rem'>" . $esc($roles[(string)$inv->role] ?? '') . "</td>"
	    . "<td class='small text-body-secondary'>" . ($by ? 'by ' . $esc($by) . ', ' : '') . 'until ' . $esc($inv->expires_at->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')) . "</td>"
	    . "<td class='text-end text-nowrap' style='width: 14rem'>"
	    . "<a class='btn btn-sm btn-outline-secondary me-1' href='" . \V::app()->createUrl('/project/member_invite_resend', $q) . "' data-method='post'><i class='bi bi-arrow-repeat me-1'></i>Resend</a>"
	    . "<a class='btn btn-sm btn-outline-danger' href='" . \V::app()->createUrl('/project/member_invite_cancel', $q) . "' data-method='post' data-confirm='"
	    . $esc('Cancel the invitation of ' . $inv->email . '?') . "'><i class='bi bi-x-lg me-1'></i>Cancel</a></td></tr>";
    }
    echo '</tbody></table></div>';
}
