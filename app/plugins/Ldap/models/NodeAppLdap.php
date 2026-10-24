<?php

//CLD: 2026-10-21T10:00:00+03:00: new step: LDAP - reads and changes a directory (Active Directory, OpenLDAP, ...) through an LDAP server credential of the project (CredentialLdap,
//provides `ldap`). Operations (setting `operation`):
//	search          the entries of a filter under a base (default: the credential's Base DN): { entries: [ { dn, <attribute>: value or list } ], count, truncated }
//	get             one entry by DN (null when there is none)
//	add             a new entry: its attributes (a value giving a list = several values)
//	modify          changes of an entry's attributes: replace / add / delete (delete without a value: the whole attribute)
//	rename          a new RDN and / or a new parent (moves the entry)
//	delete          an entry (a leaf)
//	addMember       a DN (or a memberUid name) added to a group; already a member is not an error
//	removeMember    the opposite; not a member is not an error
//	checkPassword   a person's password: { valid, dn } (a wrong password is not an error)
//	setPassword     a new password for an entry: Active Directory: unicodePwd (needs an encrypted connection), else the password modify operation (RFC 3062; empty:
//	                the server generates one, returned)
//  Values: one value as text, several as a list; objectGUID / objectSid as their usual text, other binary values as base64 (util\LdapClient::plain).
//  On error (the directory's refusals, no connection): fail the step, or continue on the `error` output

namespace plugins\Ldap\models;

use plugins\Ldap\util\LdapClient;
use plugins\Ldap\util\LdapException;

class NodeAppLdap extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'ldap';
    public static $label = 'LDAP';
    public static $icon = 'bi-diagram-3';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const OPERATIONS = [
	'search' => 'Search entries',
	'get' => 'Get an entry',
	'add' => 'Add an entry',
	'modify' => 'Modify an entry',
	'rename' => 'Rename / move an entry',
	'delete' => 'Delete an entry',
	'addMember' => 'Add a member to a group',
	'removeMember' => 'Remove a member from a group',
	'checkPassword' => 'Check a password',
	'setPassword' => 'Set a password',
    ];
    const ON_DN = [ 'get', 'add', 'modify', 'rename', 'delete', 'setPassword' ];
    const ON_GROUP = [ 'addMember', 'removeMember' ];
    const CHANGES = [ 'replace' => 'Replace', 'add' => 'Add values', 'delete' => 'Delete values' ];
    const MEMBER_ATTRIBUTES = [ 'member' => 'member (a DN: groupOfNames, Active Directory)', 'uniqueMember' => 'uniqueMember (a DN: groupOfUniqueNames)', 'memberUid' => 'memberUid (a name: posixGroup)' ];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    const MAX_ENTRIES = 10000;
    const MAX_ROWS = 100;

    //the usual filters of checkPassword by directory (the credential's)
    const LOGIN_FILTERS = [ 'ad' => '(&(objectClass=user)(|(sAMAccountName={login})(userPrincipalName={login})(mail={login})))', 'openldap' => '(&(objectClass=person)(|(uid={login})(mail={login})))' ];

    public static function configSchema() {
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];
	$choices = [];
	foreach(self::credentialChoices() as $c)
	    $choices[$c['value']] = $c['label'];
	return [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'LDAP server',
		'choices' => $choices,
		'help' => 'An LDAP server credential of the project (menu: Credentials); the step works with its bind DN\'s rights',
		'required' => true,
	    ],
	    [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => 'search',
		'choices' => self::OPERATIONS,
	    ],
	    [
		'key' => 'base',
		'type' => 'text',
		'label' => 'Base DN',
		'default' => '',
		'help' => 'Empty: the credential\'s Base DN; {{ ... }} for expressions',
		'ui' => [ 'placeholder' => 'OU=People,DC=example,DC=com', 'expression' => true, 'width' => 8, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'scope',
		'type' => 'select',
		'label' => 'Scope',
		'required' => true,
		'default' => 'sub',
		'choices' => LdapClient::SCOPES,
		'ui' => [ 'width' => 4, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'filter',
		'type' => 'text',
		'label' => 'Filter',
		'default' => '(objectClass=person)',
		'help' => 'An LDAP filter, e.g. (&(objectClass=user)(department=Sales)). A {{ }} inside it is escaped for the filter ((cn={{ name }}): a* -> a\\2a); a filter that is one {{ }} only is used as it is',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'search' ] ],
		'ui' => [ 'expression' => true, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'attributes',
		'type' => 'text',
		'label' => 'Attributes',
		'default' => '',
		'help' => 'The attributes to read, separated by commas; empty: all',
		'ui' => [ 'placeholder' => 'cn, mail, memberOf', 'width' => 8, 'showIf' => $op([ 'search', 'get' ]) ],
	    ],
	    [
		'key' => 'sizeLimit',
		'type' => 'number',
		'label' => 'At most',
		'required' => true,
		'default' => 100,
		'help' => 'Entries; more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ENTRIES, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ENTRIES ] ],
		'ui' => [ 'width' => 4, 'step' => 1, 'showIf' => $op([ 'search' ]) ],
	    ],
	    [
		'key' => 'dn',
		'type' => 'text',
		'label' => 'DN',
		'default' => '',
		'help' => 'The entry\'s distinguished name. A {{ }} inside it is escaped for the DN (CN={{ user.name }},OU=...); a DN that is one {{ }} only is used as it is ({{ entry.dn }})',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_DN ],
		'ui' => [ 'placeholder' => 'CN={{ user.name }},OU=People,DC=example,DC=com', 'expression' => true, 'showIf' => $op(self::ON_DN) ],
	    ],
	    [
		'key' => 'entryAttributes',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Attributes',
		'itemLabel' => 'Attribute',
		'default' => [],
		'fields' => [
		    [ 'key' => 'name', 'type' => 'text', 'label' => 'Attribute', 'required' => true, 'help' => false, 'ui' => [ 'width' => 4, 'placeholder' => 'mail' ] ],
		    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => false, 'ui' => [ 'width' => 8, 'expression' => true, 'placeholder' => '{{ user.email }}' ] ],
		],
		'validateIf' => [ 'if' => 'operation', 'in' => [ 'add' ] ],
		'ui' => [ 'addLabel' => 'Add attribute', 'compact' => true, 'maxRows' => self::MAX_ROWS, 'showIf' => $op([ 'add' ]) ],
	    ],
	    [
		'key' => 'changes',
		'type' => 'nested',
		'multiple' => true,
		'label' => 'Changes',
		'itemLabel' => 'Change',
		'default' => [],
		'fields' => [
		    [ 'key' => 'action', 'type' => 'select', 'label' => 'Change', 'required' => true, 'default' => 'replace', 'help' => false, 'choices' => self::CHANGES, 'ui' => [ 'width' => 3 ] ],
		    [ 'key' => 'name', 'type' => 'text', 'label' => 'Attribute', 'required' => true, 'help' => false, 'ui' => [ 'width' => 3, 'placeholder' => 'telephoneNumber' ] ],
		    [ 'key' => 'value', 'type' => 'text', 'label' => 'Value', 'help' => false, 'ui' => [ 'width' => 6, 'expression' => true, 'placeholder' => '{{ phone }} (empty: delete all values)' ] ],
		],
		'validateIf' => [ 'if' => 'operation', 'in' => [ 'modify' ] ],
		'ui' => [ 'addLabel' => 'Add change', 'compact' => true, 'maxRows' => self::MAX_ROWS, 'showIf' => $op([ 'modify' ]) ],
	    ],
	    [
		'key' => 'newRdn',
		'type' => 'text',
		'label' => 'New RDN',
		'default' => '',
		'help' => 'The entry\'s new first part, e.g. CN=Ana Ionescu; empty: the same (a move)',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op([ 'rename' ]) ],
	    ],
	    [
		'key' => 'newParent',
		'type' => 'text',
		'label' => 'New parent',
		'default' => '',
		'help' => 'Moves the entry there, e.g. OU=Former,DC=example,DC=com; empty: stays',
		'ui' => [ 'expression' => true, 'width' => 6, 'showIf' => $op([ 'rename' ]) ],
	    ],
	    [
		'key' => 'group',
		'type' => 'text',
		'label' => 'Group DN',
		'default' => '',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_GROUP ],
		'ui' => [ 'expression' => true, 'placeholder' => 'CN=Sales,OU=Groups,DC=example,DC=com', 'showIf' => $op(self::ON_GROUP) ],
	    ],
	    [
		'key' => 'member',
		'type' => 'text',
		'label' => 'Member',
		'default' => '',
		'help' => 'A DN (member, uniqueMember) or a name (memberUid); {{ ... }} for expressions',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_GROUP ],
		'ui' => [ 'expression' => true, 'width' => 8, 'placeholder' => '{{ entry.dn }}', 'showIf' => $op(self::ON_GROUP) ],
	    ],
	    [
		'key' => 'memberAttribute',
		'type' => 'select',
		'label' => 'Member attribute',
		'required' => true,
		'default' => 'member',
		'choices' => self::MEMBER_ATTRIBUTES,
		'ui' => [ 'width' => 4, 'showIf' => $op(self::ON_GROUP) ],
	    ],
	    [
		'key' => 'login',
		'type' => 'text',
		'label' => 'Name',
		'default' => '',
		'help' => 'The name the person signs in with; {{ ... }} for expressions',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'checkPassword' ] ],
		'ui' => [ 'expression' => true, 'width' => 6, 'placeholder' => '{{ form.username }}', 'showIf' => $op([ 'checkPassword' ]) ],
	    ],
	    [
		'key' => 'password',
		'type' => 'text',
		'label' => 'Password',
		'default' => '',
		'help' => 'An expression giving the password, e.g. {{ form.password }} (not the password itself: settings are visible to the project)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'checkPassword' ] ],
		'ui' => [ 'expression' => true, 'width' => 6, 'placeholder' => '{{ form.password }}', 'showIf' => $op([ 'checkPassword' ]) ],
	    ],
	    [
		'key' => 'userFilter',
		'type' => 'text',
		'label' => 'User filter',
		'default' => '',
		'help' => 'Finds the person: {login} = the name. Empty: sAMAccountName / userPrincipalName / mail (Active Directory), uid / mail (others)',
		'ui' => [ 'showIf' => $op([ 'checkPassword' ]) ],
	    ],
	    [
		'key' => 'newPassword',
		'type' => 'text',
		'label' => 'New password',
		'default' => '',
		'help' => 'An expression giving it, e.g. {{ form.password }}. Empty (not Active Directory): the server generates one, in the result',
		'ui' => [ 'expression' => true, 'showIf' => $op([ 'setPassword' ]) ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'ldap',
		'help' => 'Search: { entries, count, truncated }; get: the entry or null; checkPassword: { valid, dn }; else { dn }',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'An error from the directory (e.g. no such entry, no access, already exists) or no connection',
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    //the project's LDAP server credentials, for the designer
    protected static function credentialChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor('ldap', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    //the credential: of the workflow's project, an LDAP server; a filter / rows that can run
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'ldap')))) {
	    $this->errors('config', 'LDAP server: choose an LDAP server credential of this project');
	    $valid = false;
	}
	$operation = (string)$this->cfg('operation', 'search');
	if ($operation === 'search' && strpos((string)$this->cfg('filter', ''), '{{') === false) {
	    try {
		\plugins\Ldap\util\LdapBer::parseFilter((string)$this->cfg('filter', ''));
	    } catch (\InvalidArgumentException $e) {
		$this->errors('config', 'Filter: ' . $e->getMessage());
		$valid = false;
	    }
	}
	if ($operation === 'add' && empty($this->cfg('entryAttributes', []))) {
	    $this->errors('config', 'Attributes: add the attributes of the entry (objectClass and the naming attribute at least)');
	    $valid = false;
	}
	if ($operation === 'modify' && empty($this->cfg('changes', []))) {
	    $this->errors('config', 'Changes: add a change');
	    $valid = false;
	}
	if ($operation === 'rename' && trim((string)$this->cfg('newRdn', '')) === '' && trim((string)$this->cfg('newParent', '')) === '') {
	    $this->errors('config', 'Rename: give a New RDN or a New parent');
	    $valid = false;
	}
	$filter = trim((string)$this->cfg('userFilter', ''));
	if ($operation === 'checkPassword' && $filter !== '' && strpos($filter, '{login}') === false) {
	    $this->errors('config', 'User filter: needs {login} (where the name goes)');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'ldap') : 'ldap';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$entry = [ 'type' => 'object', 'info' => 'an entry: { dn, <attribute>: value (several: a list) }', 'fields' => [ 'dn' => [ 'type' => 'string', 'info' => 'the distinguished name' ] ] ];
	return [ $name => match(is_array($config) ? ($config['operation'] ?? 'search') : 'search') {
	    'search' => [ 'type' => 'object', 'info' => 'the entries found: { entries, count, truncated }', 'fields' => [
		'entries' => [ 'type' => 'array', 'items' => $entry ], 'count' => [ 'type' => 'number' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more entries matched than At most' ] ] ],
	    'get' => $entry + [ 'info' => 'the entry, or null when there is none' ],
	    'checkPassword' => [ 'type' => 'object', 'info' => '{ valid, dn }', 'fields' => [ 'valid' => [ 'type' => 'boolean', 'info' => 'the password is right' ],
		'dn' => [ 'type' => 'string', 'info' => 'the person found (null: none)' ] ] ],
	    'setPassword' => [ 'type' => 'object', 'info' => '{ dn, generated }', 'fields' => [ 'dn' => [ 'type' => 'string' ],
		'generated' => [ 'type' => 'string', 'info' => 'the password the server made (when none was given), else null' ] ] ],
	    'rename' => [ 'type' => 'object', 'info' => '{ dn, oldDn }', 'fields' => [ 'dn' => [ 'type' => 'string', 'info' => 'the new DN' ], 'oldDn' => [ 'type' => 'string' ] ] ],
	    'addMember', 'removeMember' => [ 'type' => 'object', 'info' => '{ dn, member, changed }', 'fields' => [ 'dn' => [ 'type' => 'string', 'info' => 'the group' ],
		'member' => [ 'type' => 'string' ], 'changed' => [ 'type' => 'boolean', 'info' => 'false: it was already so' ] ] ],
	    default => [ 'type' => 'object', 'info' => '{ dn }', 'fields' => [ 'dn' => [ 'type' => 'string' ] ] ],
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	//CLD: 2026-10-24T09:45:36+03:00: a feature of the account's tier (util\Tiers)
	if (!\util\Tiers::has($this->exec->aid, 'ldap'))
	    throw new \ErrorException('LDAP: ' . \util\Tiers::featureMessage($this->exec->aid, 'ldap'));
	$vars = $this->exec->expressionVariables();
	$operation = (string)$this->cfg('operation', 'search');
	if (!isset(self::OPERATIONS[$operation]))
	    throw new \ErrorException("LDAP: unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'ldap');
	if (empty($cred))
	    throw new \ErrorException('LDAP: the credential is no longer available');

	$name = (string)$this->cfg('variable', 'ldap');
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $cred->withClient(fn(LdapClient $client) => $this->run($operation, $client, $cred, $vars));
	} catch (\RuntimeException $e) {
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, "LDAP {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException("LDAP: {$operation}: " . $e->getMessage());
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$this->exec->log($this->wfid, "LDAP {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //a setting as text, its {{ }} values inside other text escaped by $escape (a filter's, a DN's: CN={{ name }},OU=...); a setting that is one {{ }} only is its value as it is
    //(a whole DN or filter: {{ entry.dn }})
    public static function render($template, array $vars, ?callable $escape = null) {
	$template = (string)$template;
	$single = preg_match('/^\s*\{\{((?!\}\}).)*\}\}\s*$/s', $template) === 1;
	if ($escape === null || $single || strpos($template, '{{') === false)
	    return trim(\util\Expression::toText(\util\Expression::render($template, $vars)));
	$out = preg_replace_callback('/\{\{(.*?)\}\}/s', fn($m) => $escape(\util\Expression::toText(\util\Expression::render('{{' . $m[1] . '}}', $vars))), $template);
	return trim($out);
    }

    //a row's value: [] (empty), [ text ] or the texts of a list; booleans as TRUE / FALSE (LDAP's)
    public static function values($template, array $vars) {
	$v = \util\Expression::render((string)$template, $vars);
	$list = is_array($v) && array_is_list($v) ? $v : [ $v ];
	$out = [];
	foreach($list as $item) {
	    if (is_bool($item))
		$item = $item ? 'TRUE' : 'FALSE';
	    elseif (is_array($item) || is_object($item))
		throw new \RuntimeException('a value is an object: give text, a number or a list of them');
	    $text = (string)\util\Expression::toText($item);
	    if ($text !== '')
		$out[] = $text;
	}
	return $out;
    }

    protected function run($operation, LdapClient $client, $cred, array $vars) {
	$text = fn($key, $escape = null) => self::render($this->cfg($key, ''), $vars, $escape);
	$dnEscape = [ LdapClient::class, 'escapeDn' ];
	switch($operation) {
	    case 'search':
		$base = $text('base', $dnEscape) ?: trim((string)$cred->baseDn);
		$filter = $text('filter', [ LdapClient::class, 'escapeFilter' ]);
		$limit = max(1, min(self::MAX_ENTRIES, (int)$this->cfg('sizeLimit', 100)));
		$r = $client->search($base, $filter, (string)$this->cfg('scope', 'sub'), self::attributeNames($this->cfg('attributes', '')), $limit, min(500, $limit));
		$entries = array_map([ LdapClient::class, 'plain' ], $r['entries']);
		return [ [ 'entries' => $entries, 'count' => count($entries), 'truncated' => $r['truncated'] ], count($entries) . ' entr' . (count($entries) == 1 ? 'y' : 'ies')
		    . ($r['truncated'] ? ' (more matched: truncated)' : '') . " under {$base}" ];
	    case 'get':
		$dn = $this->dn($text);
		$entry = $client->read($dn, self::attributeNames($this->cfg('attributes', '')));
		return [ $entry ? LdapClient::plain($entry) : null, $entry ? "{$dn}: " . count($entry['attributes']) . ' attributes' : "{$dn}: not found" ];
	    case 'add':
		$dn = $this->dn($text);
		$attrs = [];
		foreach((array)$this->cfg('entryAttributes', []) as $row) {
		    $attr = trim((string)($row['name'] ?? ''));
		    $values = self::values($row['value'] ?? '', $vars);
		    if ($attr !== '' && $values)
			$attrs[$attr] = array_merge($attrs[$attr] ?? [], $values);
		}
		if (!$attrs)
		    throw new \RuntimeException('no attribute has a value');
		$client->add($dn, $attrs);
		return [ [ 'dn' => $dn ], "{$dn} added (" . count($attrs) . ' attributes)' ];
	    case 'modify':
		$dn = $this->dn($text);
		$changes = [];
		foreach((array)$this->cfg('changes', []) as $row) {
		    $attr = trim((string)($row['name'] ?? ''));
		    if ($attr === '')
			continue;
		    $changes[] = [ 'op' => isset(self::CHANGES[$row['action'] ?? '']) ? $row['action'] : 'replace', 'attr' => $attr, 'values' => self::values($row['value'] ?? '', $vars) ];
		}
		$client->modify($dn, $changes);
		return [ [ 'dn' => $dn ], "{$dn}: " . count($changes) . ' change' . (count($changes) == 1 ? '' : 's') ];
	    case 'rename':
		$dn = $this->dn($text);
		$rdns = LdapClient::splitDn($dn);
		$newRdn = $text('newRdn');
		if ($newRdn === '') {
		    $first = preg_split('/(?<!\\\\),/', $dn, 2)[0];
		    $newRdn = trim($first);
		}
		$newParent = $text('newParent');
		$parent = $newParent !== '' ? $newParent : (string)(preg_split('/(?<!\\\\),/', $dn, 2)[1] ?? '');
		if (!$rdns || !LdapClient::splitDn($newRdn))
		    throw new \RuntimeException("not a DN: `{$dn}` / `{$newRdn}`");
		$client->rename($dn, $newRdn, $newParent !== '' ? $newParent : null, true);
		$newDn = $newRdn . ($parent !== '' ? ',' . trim($parent) : '');
		return [ [ 'dn' => $newDn, 'oldDn' => $dn ], "{$dn} -> {$newDn}" ];
	    case 'delete':
		$dn = $this->dn($text);
		$client->delete($dn);
		return [ [ 'dn' => $dn ], "{$dn} deleted" ];
	    case 'addMember':
	    case 'removeMember':
		$group = $text('group');
		$member = $text('member');
		$attr = isset(self::MEMBER_ATTRIBUTES[$this->cfg('memberAttribute', 'member')]) ? (string)$this->cfg('memberAttribute', 'member') : 'member';
		if ($group === '' || $member === '')
		    throw new \RuntimeException('the group and the member are needed');
		$add = $operation === 'addMember';
		try {
		    $client->modify($group, [ [ 'op' => $add ? 'add' : 'delete', 'attr' => $attr, 'values' => [ $member ] ] ]);
		    $changed = true;
		} catch (LdapException $e) {
		    //already a member (20; Active Directory: 68) / not a member (16; Active Directory: 53 unwilling)
		    if (!in_array($e->ldapCode, $add ? [ 20, 68 ] : [ 16 ], true))
			throw $e;
		    $changed = false;
		}
		return [ [ 'dn' => $group, 'member' => $member, 'changed' => $changed ], "{$member} " . ($add ? 'added to' : 'removed from') . " {$group}" . ($changed ? '' : ' (it was already so)') ];
	    case 'checkPassword':
		$login = $text('login');
		$password = \util\Expression::toText(\util\Expression::render((string)$this->cfg('password', ''), $vars));
		$filter = trim((string)$this->cfg('userFilter', '')) ?: self::LOGIN_FILTERS[$cred->isAd() ? 'ad' : 'openldap'];
		$found = $client->search(trim((string)$cred->baseDn), str_replace('{login}', LdapClient::escapeFilter($login), $filter), 'sub', [ '1.1' ], 2)['entries'];
		if (count($found) !== 1 || $password === '')
		    return [ [ 'valid' => false, 'dn' => count($found) === 1 ? $found[0]['dn'] : null ], "`{$login}`: " . (count($found) === 1 ? 'no password given' : (count($found) ? 'several entries have this name' : 'not found')) ];
		try {
		    $client->bind($found[0]['dn'], $password);
		    $valid = true;
		} catch (LdapException $e) {
		    if (!$e->isInvalidCredentials())
			throw $e;
		    $valid = false;
		}
		return [ [ 'valid' => $valid, 'dn' => $found[0]['dn'] ], "`{$login}`: the password is " . ($valid ? 'right' : 'wrong') ];
	    case 'setPassword':
		$dn = $this->dn($text);
		$new = \util\Expression::toText(\util\Expression::render((string)$this->cfg('newPassword', ''), $vars));
		if ($cred->isAd()) {
		    if ($new === '')
			throw new \RuntimeException('Active Directory: give the new password');
		    if ($cred->encryption === 'none')
			throw new \RuntimeException('Active Directory changes passwords only over an encrypted connection (StartTLS or LDAPS)');
		    $client->modify($dn, [ [ 'op' => 'replace', 'attr' => 'unicodePwd', 'values' => [ mb_convert_encoding('"' . $new . '"', 'UTF-16LE', 'UTF-8') ] ] ]);
		    return [ [ 'dn' => $dn, 'generated' => null ], "{$dn}: password set" ];
		}
		$generated = $client->passwordModify($dn, null, $new !== '' ? $new : null);
		return [ [ 'dn' => $dn, 'generated' => $generated ], "{$dn}: password " . ($generated !== null ? 'generated' : 'set') ];
	}
	throw new \ErrorException("LDAP: unknown operation `{$operation}`");
    }

    //the DN setting ({{ }} values escaped for a DN)
    protected function dn(\Closure $text) {
	$dn = $text('dn', [ LdapClient::class, 'escapeDn' ]);
	if ($dn === '' || LdapClient::splitDn($dn) === null)
	    throw new \RuntimeException("not a DN: `{$dn}`");
	return $dn;
    }

    public static function attributeNames($text) {
	return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$text)), fn($a) => $a !== ''));
    }
}
