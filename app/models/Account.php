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
	    //the account's name (a company, a team; asked at sign-up)
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
		    //suspended by a super-admin (the MultiTenant plugin): no sign-in, no new executions, its queued jobs wait (isSuspended)
		    'disabled' => 'Suspended',
		],
		'showInForms' => false,
	    ],
	    //the account's tier (util\Tiers, app/tiers.php; empty: the default) and its own changes of the tier's limits / features ({ limits, features });
	    //set by a super-admin only; the tiers are the MultiTenant plugin's (plugins.json: tiers, ./v MultiTenant::tiers::set); without it, none
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
	    //the session limits (sys\Boot::after_user_login): sign out after this many minutes without a request, and this many hours after signing in
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
	    //how long the audit log / execution log keep entries (days, 0 = forever; models\log\LogTrait::RETENTION), applied by the daemon to the entries already written too;
	    //the audit log: 1 year at least (models\AuditLog::MIN_RETENTION_DAYS, also for a shorter value already saved; SOC2.md gap 6; AuditLog::prune)
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
	    //the projects whose MCP server is on (ids; McpController, TokenController, the project menu: Account::mcpEnabled); none by default. Set by the owners
	    //in the account settings' Data access tab (Account::update)
	    [
		'key' => 'mcp_projects',
		'type' => 'array',
		'label' => 'MCP server',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => [],
		'showInForms' => false,
	    ],
	    //the integration steps whose servers may be on-premises (the GravityZone steps: util\GravityZone) may connect to addresses of a private network
	    //(util\Http `private`: never this server itself, loopback, link-local); off: public addresses only
	    [
		'key' => 'allow_private_addresses',
		'type' => 'checkbox',
		'label' => 'Integrations may connect to private network addresses',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => false,
	    ],
	    //how dates are shown (util\Format::date / datetime, the date fields, the date pickers): date() tokens; empty: the app's
	    //(app.format.date / app.format.datetime)
	    [
		'key' => 'date_format',
		'type' => 'select',
		'label' => 'Date format',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'choices' => self::formatChoices([ '', 'Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'j M Y', 'M j, Y' ], 'app.format.date'),
	    ],
	    [
		'key' => 'datetime_format',
		'type' => 'select',
		'label' => 'Date and time format',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'choices' => self::formatChoices([ '', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd.m.Y H:i', 'd/m/Y H:i', 'm/d/Y g:i A', 'j M Y H:i', 'M j, Y g:i A' ], 'app.format.datetime'),
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //whether the MCP server of project $projectId is on in this account
    public function mcpEnabled($projectId) {
	return $projectId && in_array((string)$projectId, array_map('strval', (array)($this->mcp_projects ?? [])), true);
    }

    //date formats as choices, labeled with an example (a fixed date); '' -> "Default", with the app's format ($config)
    private static function formatChoices(array $formats, string $config) {
	$sample = new \DateTimeImmutable('2026-12-31 15:45:30');
	$choices = [];
	foreach($formats as $f)
	    $choices[$f] = $f === '' ? 'Default (' . $sample->format((string)\V::app()->config($config)) . ')' : $sample->format($f);
	return $choices;
    }

    //the ids of the suspended accounts (state `disabled`), read again after 30 s (the daemon runs for days; $fresh: now): [ id => true ]
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

    //whether account $aid is suspended
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
