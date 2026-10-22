<?php

namespace models;

class Credential extends \sys\BaseModel {
    use \sys\PolymorphTrait;

    //CLD: 2026-10-09T17:21:14+03:00: the family's registry name (variants: 'variant-of' => FAMILY)
    const FAMILY = 'Credential';

    protected static $collection = 'credentials';
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1, '__variant' => 1 ], [] ],
    ];

    public static $label = '';
    public static $icon = 'bi-key';
    public static $group = 'Other';
    public static $provides = [];
    public static $testable = false;

    //CLD: 2026-10-09T17:21:14+03:00: the fields of every credential + the system fields; variants add theirs (parent::getSchema() + fields)
    public function getSchema() {
	return array_merge($this->commonSchema(), $this->getSystemSchema());
    }

    protected function commonSchema() {
	//CLD: 2026-10-09T17:21:14+03:00: the kind is the variant (was `type`, a CredentialType name; `config` removed: a variant's values are its own fields)
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
	    //CLD: 2026-10-22T01:58:24+03:00: the models of an AI server, recorded by its Test (recordModels): the choices of the AI steps' Model; not in the list, not in forms
	    [
		'key' => 'models',
		'type' => 'array',
		'label' => 'Models',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	];
    }

    //CLD: 2026-10-22T01:58:24+03:00: the model names that are not chat models (embeddings, speech, images, moderation, old completions): left out of the AI steps' choices
    const NOT_CHAT = '/(embed|tts|whisper|transcribe|dall-e|gpt-image|moderation|davinci|babbage|rerank)/i';

    //CLD: 2026-10-22T01:58:24+03:00: the chat models among $ids (NOT_CHAT left out), sorted, each once
    public static function chatModels(array $ids) {
	$list = array_values(array_unique(array_filter(array_map(fn($id) => trim((string)$id), $ids), fn($id) => $id !== '' && !preg_match(self::NOT_CHAT, $id))));
	natcasesort($list);
	return array_values($list);
    }

    //CLD: 2026-10-22T01:58:24+03:00: the models the server offers, recorded on the credential (only that field is saved: the object may hold unsaved form data, as setStatus); the AI steps offer them
    public function recordModels(array $ids) {
	$this->models = self::chatModels($ids);
	if ($this->exists())
	    \V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id ], [ '$set' => [ 'models' => $this->models ] ]);
	return $this->models;
    }

    //CLD: 2026-10-09T17:21:14+03:00: permissions are the family's (Credential::update, ...) for every variant
    public function checkAccess($userId, $permName, $params = []) {
	$role = \V::app()->auth->getUserRole();

	if (strpos($permName, '::') === false)
	    $permName = 'Credential::' . $permName;

	$scope = $role->getPermissionScope($permName);

	if ($scope == 'global')
	    return true;

	//project scope: the user must be a member of the credential's project
	if ($scope == 'project')
	    return \models\Project::isMember($this->project, $this->aid, $userId);

	return false;
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

    //OAuth variants: consent through /credential/connect (see models\credential\CredentialOAuth2)
    public static function isOAuth() {
	return false;
    }

    public function test() {
	throw new \Exception('this credential type has no connection test');
    }

    //CLD: 2026-10-12T21:15:28+03:00: for the connection tests of HTTP API credentials (AI servers): GET $url with $headers (name => value), the decoded JSON
    //answer; an exception when there is no answer, the status is 400 or more (with the server's error message when it gives one) or the answer is not JSON
    protected static function getJson($url, array $headers = [], $timeout = 15) {
	$lines = [ 'Accept: application/json', 'User-Agent: QSM-Workflow' ];
	foreach($headers as $name => $value)
	    $lines[] = $name . ': ' . $value;
	$ch = curl_init($url);
	curl_setopt_array($ch, [
	    CURLOPT_HTTPGET => true,
	    CURLOPT_HTTPHEADER => $lines,
	    CURLOPT_RETURNTRANSFER => true,
	    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
	    CURLOPT_TIMEOUT => $timeout,
	]);
	$body = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = $body === false ? curl_error($ch) : '';
	curl_close($ch);
	if ($error !== '')
	    throw new \Exception('no answer from ' . preg_replace('/[?#].*$/', '', $url) . ": {$error}");
	$data = json_decode((string)$body, true);
	if ($status >= 400) {
	    $message = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null) : null;
	    throw new \Exception("HTTP status {$status}" . (is_string($message) && $message !== '' ? ': ' . mb_substr($message, 0, 300) : ''));
	}
	if (!is_array($data))
	    throw new \Exception('the answer is not JSON (is the URL right?)');
	return $data;
    }

    //the form's app.smartform fields
    //CLD: 2026-10-10T09:14:41+03:00: util\WidgetsUtil handles the encrypted fields (write-only inputs, "leave empty to keep"; was done here for password fields)
    public function formFields() {
	//CLD: 2026-10-10T18:44:22+03:00: the fields with showInForms (was formKeys())
	return \util\SmartForm::modelFields($this);
    }

    //use

    //the result of a test or a use (saves only these fields: the object may hold unsaved form data)
    public function setStatus($status, $message) {
	$this->status = $status;
	$this->status_message = mb_substr((string)$message, 0, 500);
	$this->checked_at = new \MongoDB\BSON\UTCDateTime();
	//CLD: 2026-10-12T14:12:12+03:00: through ObjectManager (om->updateMany on this credential's id; was the MongoDB collection directly)
	if ($this->exists())
	    \V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id ],
		[ '$set' => [ 'status' => $this->status, 'status_message' => $this->status_message, 'checked_at' => $this->checked_at ] ]);
    }

    //credentials of project $projectId that provide $capability: app.smartform choices for node configs
    public static function choicesFor($capability, $aid, $projectId) {
	$variants = static::variantsProviding($capability);
	if (empty($variants) || empty($projectId))
	    return [];
	$choices = [];
	//CLD: 2026-10-09T17:21:14+03:00: by __variant (was type); each object is its variant's class
	foreach(\V::app()->om->find(self::FAMILY, [ 'aid' => $aid, 'project' => \V::app()->om->to_oid($projectId), '__variant' => [ '$in' => $variants ] ]) as $c) {
	    $account = (string)($c->email ?? '');
	    $choices[] = [ 'value' => (string)$c->_id, 'label' => (string)$c->name . ' (' . $c::$label . ($account !== '' ? ', ' . $account : '') . ')' ];
	}
	return $choices;
    }

    //credential $id of the workflow's project, if it provides $capability
    public static function findFor($wf, $id, $capability) {
	if (!\util\Validator::rule('oid', (string)$id))
	    return null;
	$c = \V::app()->om->findOne('Credential', [ '_id' => $id, 'aid' => $wf->aid, 'project' => $wf->project ]);
	return ($c && $c->provides($capability)) ? $c : null;
    }
}
