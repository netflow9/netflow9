<?php

namespace models;

class Credential extends \sys\BaseModel {
    use \sys\PolymorphTrait;
    //a credential can be shared with other projects of the account: their workflows use it.
    //No permission comes with it: they do not open, test or change it. Variables are not shared
    use \models\share\ShareableTrait;
    //and moved to another project
    use \models\share\MovableTrait;

    const MOVE_PERMS = [
	'from' => 'Credential::delete',
	'to' => 'Credential::create'
    ];
    const MOVE_ACTION = 'credential.move';

    const SHARE_PERM = 'Credential::share';
    const SHARE_WHAT = 'Credential';
    const SHARE_LEVELS = [ 'use' => 'Use in workflows' ];
    const SHARE_PERMS = [ 'use' => [] ];

    //the family's registry name (variants: 'variant-of' => FAMILY)
    const FAMILY = 'Credential';

    protected static $collection = 'credentials';
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1, '__variant' => 1 ], [] ],
	[ [ 'aid' => 1, 'shares.project' => 1 ], [] ],
    ];

    public static $label = '';
    public static $icon = 'bi-key';
    public static $group = 'Other';
    public static $provides = [];
    public static $testable = false;

    //the fields of every credential + the system fields; variants add theirs (parent::getSchema() + fields)
    public function getSchema() {
	return array_merge($this->commonSchema(), $this->getSystemSchema());
    }

    protected function commonSchema() {
	$variants = [];
	foreach(\V::app()->om->getVariants('Credential') as $name => $class)
	    $variants[$name] = $class::$label;

	return [
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
		'validate' => [
		    [
			'uniqueInProject',
			'message' => 'This name already exists in the project',
			'collection' => self::_collection(),
		    ],
		],
	    ],
	    [
		'key' => '__variant',
		'type' => 'select',
		'label' => 'Type',
		'help' => false,
		'choices' => $variants,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],

	    //last test / use
	    [
		'key' => 'status',
		'type' => 'select',
		'label' => 'Status',
		'help' => false,
		'default' => 'new',
		'choices' => [
		    'new' => 'Not checked',
		    'ok' => 'OK',
		    'error' => 'Error'
		],
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'status_message',
		'type' => 'text',
		'label' => 'Last message',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    [
		'key' => 'checked_at',
		'type' => 'datetime',
		'label' => 'Last checked',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'permission',
		'showInForms' => false,
	    ],
	    //the models of an AI server, recorded by its Test (recordModels): the choices of the AI steps' Model; not in the list, not in forms
	    [
		'key' => 'models',
		'type' => 'array',
		'label' => 'Models',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    //the projects it is shared with
	    [
		'key' => 'shares',
		'type' => 'array',
		'label' => 'Shared with',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	];
    }

    //variables are read by name in expressions: each project keeps its own
    public function shareProblem() {
	return $this instanceof \models\credential\CredentialVariable ? 'Variables are not shared: each project has its own.' : null;
    }

    //read by name, so nothing can name what would break
    public function moveProblem() {
	return $this instanceof \models\credential\CredentialVariable ? 'Variables are not moved: each project has its own, and workflows read them by name.' : null;
    }

    //the name is unique among all the kinds of credentials
    protected function moveFamily() {
	return self::FAMILY;
    }

    //a credential an HTTP step can send anywhere: the Share dialog asks for its hosts
    public function shareUsesHosts() {
	return $this->provides('http');
    }

    //the hosts it may be sent to, or any host when the owner says so: [ options, error ]. A host: name or *.name
    public function shareOptions(array $data) {
	if (!$this->shareUsesHosts())
	    return [ [], null ];
	$hosts = [];
	foreach(preg_split('/[\s,;]+/', mb_strtolower(trim((string)($data['hosts'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $host) {
	    if (!preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host))
		return [ [], "`{$host}` is not a host name: use names like api.example.com or *.example.com" ];
	    $hosts[] = $host;
	}
	$any = !empty($data['any_host']);
	if (!$hosts && !$any)
	    return [ [], 'List the hosts it may be sent to, or allow any host' ];
	//the list stays while any host is allowed, so taking that back needs no typing
	return [ [ 'hosts' => array_values(array_unique($hosts)), 'any_host' => $any ], null ];
    }

    //what the Share dialog says
    public function shareWarnings() {
	$warnings = [ 'Workflows there act with its full rights. They do not see its secret or settings, and cannot change, test or delete it.' ];
	if ($this->shareUsesHosts())
	    $warnings[] = 'An HTTP Request or Download File step chooses its own address. Without a list of hosts, a workflow editor there can send this secret to a server of theirs and read it.';
	if ($this->provides('imap'))
	    $warnings[] = 'If several projects start workflows from this mailbox, each one reads the same mail.';
	return $warnings;
    }

    //whether a share with project $projectId limits it to hosts: such a request follows no redirect (only Authorization and Cookie are dropped on the way to another host, not an API key)
    public function hostLockedIn($projectId) {
	if ($this->isHome($projectId) || !$this->shareUsesHosts())
	    return false;
	return empty($this->shareOf($projectId)['any_host']);
    }

    //whether a request of project $projectId may carry it to $url: its own project, a share with any host, a host on the share's list
    public function allowsUrlIn($projectId, $url) {
	if (!$this->hostLockedIn($projectId))
	    return true;
	//parsers disagree about a backslash in the address (which host is it?)
	if (str_contains((string)$url, '\\'))
	    return false;
	$host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
	if ($host === '')
	    return false;
	foreach((array)($this->shareOf($projectId)['hosts'] ?? []) as $allowed) {
	    $allowed = (string)$allowed;
	    if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))))
		return true;
	}
	return false;
    }

    //the chat models among $ids (NOT_CHAT left out), sorted, each once
    public static function chatModels(array $ids) {
	$list = array_values(array_unique(array_filter(array_map(fn($id) => trim((string)$id), $ids), fn($id) => $id !== '' && !preg_match(\data\Constants::NOT_CHAT, $id))));
	natcasesort($list);
	return array_values($list);
    }

    //the models the server offers, recorded on the credential (only that field is saved: the object may hold unsaved form data, as setStatus); the AI steps offer them
    public function recordModels(array $ids) {
	$this->models = self::chatModels($ids);
	if ($this->exists())
	    \V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id ], [ '$set' => [ 'models' => $this->models ] ]);
	return $this->models;
    }

    //permissions are the family's (Credential::update, ...) for every variant
    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = 'Credential::' . $permName;

	//the user's role in the credential's project decides
	return \V::app()->om->findById('Project', $this->project)?->checkAccess($userId, $permName) ?? false;
    }

    public function __toString() {
	return (string)$this->name;
    }

    //the class of variant $name; null when it is not a credential variant (e.g. its plugin was disabled)
    public static function classFor($name) {
	$name = (string)$name;
	$om = \V::app()->om;
	if ($name === '' || !$om->isDefined($name) || $om->isVariant($name) !== 'Credential')
	    return null;
	return $om->getObjectClass($name);
    }

    //names of the variants providing $capability
    public static function variantsProviding($capability) {
	return array_keys(array_filter(\V::app()->om->getVariants('Credential'), fn($class) => in_array($capability, $class::$provides, true)));
    }

    public function provides($capability) {
	return in_array($capability, static::$provides, true);
    }

    //whether this PHP can use the type (e.g. a database type: its PDO driver is loaded); not available, it is not offered for new credentials
    public static function available() {
	return true;
    }

    //OAuth variants: consent through /credential/connect (see models\credential\CredentialOAuth2)
    public static function isOAuth() {
	return false;
    }

    public function test() {
	throw new \Exception('this credential type has no connection test');
    }

    //for the connection tests of HTTP API credentials (AI servers): GET $url with $headers (name => value) through util\Http, the decoded JSON
    //answer; an exception when there is no answer, the status is 400 or more (with the server's error message when it gives one) or the answer is not JSON
    protected static function getJson($url, array $headers = [], $timeout = 15) {
	$r = \util\Http::json('GET', $url, [ 'headers' => $headers, 'timeout' => $timeout ]);
	if ($r['error'] !== null)
	    throw new \Exception('no answer from ' . \util\Http::shown($url) . ": {$r['error']}");
	$status = $r['status'];
	$data = $r['data'];
	if ($status >= 400) {
	    $message = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null) : null;
	    throw new \Exception("HTTP status {$status}" . (is_string($message) && $message !== '' ? ': ' . mb_substr($message, 0, 300) : ''));
	}
	if (!is_array($data))
	    throw new \Exception('the answer is not JSON (is the URL right?)');
	return $data;
    }

    //the form's app.smartform fields
    //util\WidgetsUtil handles the encrypted fields (write-only inputs, "leave empty to keep")
    public function formFields() {
	//the fields with showInForms
	return \util\SmartForm::modelFields($this);
    }

    //use

    //the result of a test or a use (saves only these fields: the object may hold unsaved form data)
    public function setStatus($status, $message) {
	$this->status = $status;
	$this->status_message = mb_substr((string)$message, 0, 500);
	$this->checked_at = new \MongoDB\BSON\UTCDateTime();
	if ($this->exists())
	    \V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id ],
		[ '$set' => [ 'status' => $this->status, 'status_message' => $this->status_message, 'checked_at' => $this->checked_at ] ]);
    }

    //credentials of project $projectId that provide $capability, and those shared with it, named with their project (only its name and type): app.smartform choices for node configs
    public static function choicesFor($capability, $aid, $projectId) {
	$variants = static::variantsProviding($capability);
	if (empty($variants) || empty($projectId))
	    return [];
	//read once per request (each AI step's form asks the same ones)
	return \models\wf\WfNode::rememberedChoices("credentials:{$aid}:{$projectId}:{$capability}", function() use ($variants, $aid, $projectId) {
	    $choices = [];
	    foreach(\V::app()->om->find(self::FAMILY, [ 'aid' => $aid, '__variant' => [ '$in' => $variants ] ] + self::visibleInQuery($projectId)) as $c) {
		if (!$c->isHome($projectId)) {
		    $choices[] = [ 'value' => (string)$c->_id, 'label' => (string)$c->name . ' (' . $c::$label . ', shared from ' . \V::app()->om->findById('Project', $c->project) . ')' ];
		    continue;
		}
		$account = (string)($c->email ?? '');
		$choices[] = [ 'value' => (string)$c->_id, 'label' => (string)$c->name . ' (' . $c::$label . ($account !== '' ? ', ' . $account : '') . ')' ];
	    }
	    return $choices;
	});
    }

    //credential $id of the workflow's project, if it provides $capability
    //or shared with that project; asked at each use, so a share taken back is gone at the next step that uses it
    public static function findFor($wf, $id, $capability) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	$c = \V::app()->om->findOne('Credential', [ '_id' => $id, 'aid' => $wf->aid ] + self::visibleInQuery($wf->project));
	return ($c && $c->provides($capability) && \util\Tiers::allows($wf->aid, $c->getObjectName())) ? $c : null;
    }
}
