<?php

namespace models;

class Account extends \sys\BaseModel {
    protected static $collection = 'accounts';

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;
    }

    public function getSchema() {
	$fields = [
	    //CLD: 2026-10-16T12:14:57+03:00: the account's name (a company, a team; asked at sign-up)
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => 'Your company or team',
		'default' => '',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'validate' => [
		    [ 'lengthBetween', 'min' => 0, 'max' => 100, 'message' => 'At most 100 characters' ]
		],
	    ],
	    //hostname
	    [
		'key' => 'hostname',
		'type' => 'text',
		'label' => 'Hostname',
		'help' => 'Account hostname',
		'required' => true,
		'unique' => true,
		'default' => 'acc-' . uniqid(),
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		//CLD: 2026-10-10T18:57:33+03:00: not a form field (assign with permissions ignores it)
		'showInForms' => false,
	    ],
	    //state
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'help' => false,
		'required' => true,
		'perm:read' => 'permission',
		'perm:update' => 'permission',
		'default' => 'incomplete',
		'choices' => [
		    'incomplete' => 'Incomplete',
		    'active' => 'Active',
		    //CLD: 2026-10-22T23:00:00+03:00: suspended by a super-admin (the Admin plugin): no sign-in, no new executions, its queued jobs wait (isSuspended)
		    'disabled' => 'Suspended',
		],
		'showInForms' => false,
	    ],
	    //CLD: 2026-10-24T09:45:36+03:00: the account's tier (util\Tiers, app/tiers.php; empty: the default) and its own changes of the tier's limits / features ({ limits, features });
	    //set by a super-admin only (the Admin plugin, ./v tiers::set)
	    [
		'key' => 'tier',
		'type' => 'select',
		'label' => 'Tier',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'default' => '',
		'choices' => [ '' => 'Default' ] + \util\Tiers::names(),
		'showInForms' => false,
	    ],
	    [
		'key' => 'tier_overrides',
		'type' => 'array',
		'label' => 'Tier changes',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //enforce_mfa
	    [
		'key' => 'enforce_mfa',
		'type' => 'checkbox',
		'label' => 'Enforce MFA',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => false,
	    ],
	    //CLD: 2026-10-21T23:00:00+03:00: the session limits (sys\Boot::after_user_login): sign out after this many minutes without a request, and this many hours after signing in
	    [
		'key' => 'session_idle',
		'type' => 'select',
		'label' => 'Sign out after inactivity',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '30',
		'choices' => [
		    '15' => '15 minutes',
		    '30' => '30 minutes',
		    '60' => '1 hour',
		    '240' => '4 hours',
		    '1440' => '24 hours'
		],
	    ],
	    [
		'key' => 'session_max',
		'type' => 'select',
		'label' => 'Maximum session length',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '12',
		'choices' => [
		    '8' => '8 hours',
		    '12' => '12 hours',
		    '24' => '24 hours',
		    '168' => '7 days',
		    '720' => '30 days'
		],
	    ],
	    //CLD: 2026-10-11T17:45:25+03:00: how long the audit log / execution log keep entries (days, 0 = forever; models\log\LogTrait::RETENTION); a change applies to the
	    //entries already written too (Account::afterSave)
	    //CLD: 2026-10-22T00:00:00+03:00: the audit log: 1 year at least (models\AuditLog::MIN_RETENTION_DAYS, also for a shorter value already saved; SOC2.md gap 6), applied by
	    //the daemon (AuditLog::prune; there is no Account::afterSave); was 30 / 90 days / 1 year / forever
	    [
		'key' => 'audit_log_retention',
		'type' => 'select',
		'label' => 'Keep audit log entries',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '0',
		'choices' => [
		    '0' => 'Forever',
		    '365' => '1 year',
		    '730' => '2 years',
		    '1095' => '3 years'
		],
	    ],
	    [
		'key' => 'execution_log_retention',
		'type' => 'select',
		'label' => 'Keep execution log entries',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '90',
		'choices' => [
		    '0' => 'Forever',
		    '30' => '30 days',
		    '90' => '90 days',
		    '365' => '1 year'
		],
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //CLD: 2026-10-16T12:14:57+03:00: the system roles of a new account (data\SysRoles: Owner, Member, Participant); [ iname => Role ] (was written in the seed command; also used by
    //the sign-up)
    //CLD: 2026-10-22T23:00:00+03:00: the ids of the suspended accounts (state `disabled`), read again after 30 s (the daemon runs for days; $fresh: now): [ id => true ]
    public static function suspendedIds($fresh = false) {
	static $ids = null, $at = 0;
	if ($fresh || $ids === null || time() - $at >= 30) {
	    $ids = [];
	    foreach(\V::app()->om->acrossAccounts(fn() => iterator_to_array(\V::app()->om->find('Account', [ 'state' => 'disabled' ], [ 'projection' => [ '_id' => 1 ] ]), false)) as $a)
		$ids[(string)$a->_id] = true;
	    $at = time();
	}
	return $ids;
    }

    //CLD: 2026-10-22T23:00:00+03:00: whether account $aid is suspended
    public static function isSuspended($aid) {
	return !empty($aid) && isset(self::suspendedIds()[(string)$aid]);
    }

    public function setupRoles() {
	$roles = [];
	foreach(\data\SysRoles::$data as $def) {
	    $role = \models\Role::create([ 'aid' => $this->_id ] + $def);
	    if (!$role->validates() || !$role->save())
		throw new \ErrorException('Account::setupRoles: role `' . ($def['iname'] ?? '?') . '` not saved: ' . json_encode($role->errors()));
	    $roles[(string)$role->iname] = $role;
	}
	return $roles;
    }
}
