<?php

namespace sys\fields;

class Field extends \sys\BaseModel {
    //CLD: 2026-10-26T07:23:58+02:00: a field's own schema (attached explicitly) has the registry's objects as choices (Field_object): not cached (BaseModel::attachSchema)
    protected static $cacheSchema = false;

    protected static $collection = 'fields';

    //the fields (columns) of an object / data table (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'objectName' => 1 ], [] ],
    ];

    //stored form of a field with 'encrypt' => true: prefix + \sys\Security::encrypt(json_encode(value)); the prefix tells encrypted
    //values from plain ones (a field may get `encrypt` after values were stored) and versions the format (key rotation)
    const ENC_PREFIX = 'enc:v1:';
    public $binding = null;

    public $icon = 'bi bi-question';
    public $name = 'Default field name';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type (eq ne gt gte lt lte contains startsWith in empty notEmpty), labels per type; the base field has them all (conditions on values without a type: If / Switch)
	'eval' => [
	    'eq' => 'equals',
	    'ne' => 'not equal',
	    'gt' => 'greater than',
	    'gte' => 'greater than or equal to',
	    'lt' => 'less than',
	    'lte' => 'less than or equal to',
	    'contains' => 'contains',
	    'startsWith' => 'starts with',
	    'in' => 'is one of',
	    'empty' => 'is empty',
	    'notEmpty' => 'is not empty',
	],
	'set' => [],
    ];

    use FieldTraitCast;

    public static function create($data = [], $options = []) {
	$type = $data['type'] ?? null;
	if (empty($type))
	    throw new \ErrorException('QField::create: `type` needs to be set');

	$__class = "sys\\fields\\Field_{$type}";
	return new $__class(compact('data', 'options'));
    }

    public function _init() {
	$this->_config['attachSchema'] = false;

	parent::_init();

	$this->type = $this->type ?? 'text';
	$this->help = $this->help ?? false;
	$this->label = $this->label ?? 'Label';

	//an encrypted field is not readable unless its definition says so (its inputs are write-only: see WidgetsUtil::smartFormFields)
	if (!empty($this->_data['encrypt']))
	    $this->_data['perm:read'] = $this->_data['perm:read'] ?? 'nobody';

	//`showInForms`: the field is part of the model's editing form (WidgetsUtil::smartFormFields / formValues, BaseModel::assignForm);
	//true by default, false for fields set by the system (e.g. status, created_at). Independent of the permissions (a form field may be read-only)
	$this->_data['showInForms'] = $this->_data['showInForms'] ?? true;

	//initialize permissions if not null - mainly in user defined attributes
	$this->_data['perm:delete'] = $this->_data['perm:delete'] ?? 'nobody';
	$this->_data['perm:read'] = $this->_data['perm:read'] ?? 'everybody';
	$this->_data['perm:update'] = $this->_data['perm:update'] ?? 'everybody';
	$this->_data['translate'] = $this->_data['translate'] ?? true;
    }

    //CLD: 2026-10-26T07:17:30+02:00: a stored field changed: the ObjectManager's cached fields / data tables are forgotten (ObjectManager::fieldDefs)
    public function afterSave() {
	parent::afterSave();
	\V::app()->om->resetCache();
    }
    public function beforeDelete() {
	parent::beforeDelete();
	\V::app()->om->resetCache();
    }

    //after fields are attached to a field (yes!), we set some things on special fields
    public function afterAttachSchema() {
	if (empty($this->schema))
	    return;

	//key is readonly once defined
	$this->schema['key']->{'perm:update'} = ($this->exists() ? 'nobody' : 'everybody');
    }

    public function bindTo(&$object) {
	$this->binding = &$object;

	//an encrypted value differs at every save (random IV): it cannot be checked for uniqueness
	if (!empty($this->encrypt)) {
	    $rules = array_map(fn($r) => is_array($r) ? ($r[0] ?? null) : $r, (array)($this->validate ?? []));
	    if (!empty($this->unique) || array_intersect([ 'unique', 'uniqueInProject' ], $rules))
		throw new \ErrorException("Field `{$this->key}`: an encrypted field cannot be unique");
	}

	//add validation rules
	if ($this->required) {
	    if (empty($object->validates[$this->key]))
		$object->validates[$this->key] = [];
	    $object->validates[$this->key][] = [
		'notEmpty',
		'message' => 'This field is required'
	    ];
	}

	//`requiredIf` => [ 'if' => <field>, 'in' => [ values ], 'message' => optional ]: required when field `if` has one of the values (the
	//Validator's requiredIf rule; was written as that rule in each field's `validate`); the form's requiredIf is made from it (util\WidgetsUtil)
	if (!empty($this->requiredIf['if'])) {
	    if (empty($object->validates[$this->key]))
		$object->validates[$this->key] = [];
	    $object->validates[$this->key][] = [
		'requiredIf',
		'if' => (string)$this->requiredIf['if'],
		'in' => (array)($this->requiredIf['in'] ?? []),
		'message' => $this->requiredIf['message'] ?? 'This field is required'
	    ];
	}

	if (!empty($this->unique)) {
	    if (empty($object->validates[$this->key]))
		$object->validates[$this->key] = [];

	    $objectClassName = \V::app()->om->getObjectClass($object->getObjectName());

	    //skipEmpty: an empty value is not a duplicate (a query for null also matches the records without the field: e.g. every custom role, whose
	    //internal name `iname` is empty, clashed with the others); a required field is checked by notEmpty
	    $object->validates[$this->key][] = [
		'unique',
		'message' => 'This value is not unique',
		'collection' => $objectClassName::_collection(),
		'skipEmpty' => true,
	    ];
	}

	if (!empty($this->validate)) {
	    if (empty($object->validates[$this->key]))
		$object->validates[$this->key] = [];
	    $object->validates[$this->key] = array_merge(
		$object->validates[$this->key],
		$this->validate
	    );
	}

/*
	//initialize fixed values
	if (isset($this->{'data:value'})) {
	    $object->{$this->key} = $this->{'data:value'};
	    return;
	}
*/

	//initialize default values
	$object->_trackChanges = false;
	$value = $object->{$this->key};
	$object->_trackChanges = true;

	if ($value === null && isset($this->default))
	    $object->{$this->key} = $this->default;
    }

    public function cast($value) {
	return $value;
    }

    //CLD: 2026-10-18T10:20:00+03:00: the label of an error path inside this field's value ($parts: the path's keys after the field's; BaseModel::errorLabel); a plain field has none
    public function pathLabel(array $parts = []) {
	return (string)$this->label . ($parts ? ': ' . implode('.', $parts) : '');
    }

    //CLD: 2026-10-18T09:42:21+03:00: the field's own validation of the bound model's value, after the model's rules (BaseModel::validates), on the model's
    //$events: [ key => [ messages ] ]; none by default (Field_nested: the nested objects' errors)
    public function validateBinding($events = null) {
	return [];
    }

    //after the model was loaded: an encrypted value is decrypted into the model (the plain value is what the model holds)
    public function afterBindingFind() {
	if (empty($this->encrypt))
	    return;
	$data = $this->binding->data();
	if (array_key_exists($this->key, $data))
	    $this->binding->loadValue($this->key, $this->decryptValue($data[$this->key]));
    }

    //the value written to the database for this field (save() passes the document it writes: $set of an update, the inserted document);
    //the model keeps its own value
    public function beforeBindingSave($value) {
	return empty($this->encrypt) ? $value : $this->encryptValue($value);
    }

    public function afterBindingSave() {
    }

    public function beforeBindingDelete() {
    }

    //null and '' are stored as they are; an already encrypted value is not encrypted again
    public function encryptValue($value) {
	if ($value === null || $value === '' || (is_string($value) && str_starts_with($value, self::ENC_PREFIX)))
	    return $value;
	//util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	return self::ENC_PREFIX . \V::app()->security->encrypt(\util\JSON::encode($value));
    }

    //a plain (not prefixed) value is returned as it is; a value that cannot be decrypted (other APP_SECRET, altered) is null
    public function decryptValue($value) {
	if (!is_string($value) || !str_starts_with($value, self::ENC_PREFIX))
	    return $value;
	$plain = \V::app()->security->decrypt(substr($value, strlen(self::ENC_PREFIX)));
	if (!is_string($plain)) {
	    \V::app()->log->warn("Field `{$this->key}`: cannot decrypt the stored value (APP_SECRET changed or value altered)");
	    return null;
	}
	return json_decode($plain, true);
    }


    public function checkAccess($userId, $permName, $params = []) {
	if (!in_array($permName, [ 'read', 'update' ]))
	    throw new \ErrorException('invalid permission');

	switch($this->{"perm:$permName"}) {
	    case 'nobody':
		return false;
	    case 'everybody':
		return true;

	    //let the parent model decide
	    default:
		$params['object'] = $this->binding;
		$params['field'] = $this->key;
		$params['permName'] = $permName;
		$params['permVal'] = $this->{"perm:$permName"};
		return $this->binding->checkAccess($userId, $permName, $params);
	}
    }


    //universal method for 'input', 'display' and others on fields
    //$this->schema[<field>]->input([ 'class' => '....' ]);
    public function __call($method, $params) {
	$method = strtolower($method);
	$_method = "__$method";
	if (!method_exists($this, $_method))
	    \V::app()->error(500, "unknown method: $method");

	if ($_method === '__display' && !empty($this->encrypt))
	    return '******';

	if (count($params) == 0) {
	    return $this->$_method();
	} else {
	    return $this->$_method($params[0]);
	}
    }

    //default rendering empty methods
    public function __display(&$options = []) {
	return 'please implement in:' . get_called_class();
    }

    //this renderer is used in forms, to display the input field
    public function __input(&$options = []) {
	return 'please implement in:' . get_called_class();
    }


    //this is the only instance that does override
    public function getSchema() {
	return [
	    //objectName
	    [
		'key' => 'objectName',
		'label' => 'Object Name',
		'type' => 'text',
		'required' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
	    ],

	    //objectId
	    [
		'key' => 'objectId',
		'label' => 'Object ID',
		'type' => 'oid',
		'required' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
	    ],

	    //label
	    [
		'key' => 'label',
		'label' => 'Label',
		'help' => 'Field label',
		'type' => 'text',
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    //help
	    [
		'key' => 'help',
		'label' => 'Help',
		'help' => 'Field help text',
		'type' => 'text',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    //key, readonly once created
	    [
		'key' => 'key',
		'label' => 'Key',
		'help' => 'Readonly once created',

		//CLD: 2026-10-22T00:08:07+03:00: uniqueInObject with `collection` (was `unique` with `coll`, an option the validator does not read: it got no
		//collection; and `unique` checked the key across all the account's objects, not among the fields of the same objectName), as models\datatable\FieldForm
		'validate' => [
		    [ 'uniqueInObject', 'message' => 'This key already exists', 'collection' => self::_collection(), 'on' => [ 'create' ] ],
		    [ 'alphanumeric', 'message' => 'Only letters and numbers' ],
		],
		'type' => 'text',
		//keep this behavior here and try not to override somewhere else
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    //properties ------------------------------------------------------
	    // required, might be editable or not, depending on override
	    [
		'key' => 'required',
		'help' => false,
		'label' => 'Is required',
		'type' => 'checkbox',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
	    ],
	    //permissions section ------------------------------------------------------------------------------
	    //perm:read
	    [
		'key' => 'perm:read',
		'help' => false,
		'label' => 'Visible by',
		//CLD: 2026-10-06T21:05:48+03:00: select (was radio: there is no Field_radio, attachSchema() on a Field failed)
		'type' => 'select',
		//field visible/editable only for sysadmins
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'choices' => [
		    'nobody' => 'Nobody',
		    'everybody' => 'Everybody',
		    'permission' => 'By permission'
		],
	    ],
	    //perm:update
	    [
		'key' => 'perm:update',
		'help' => false,
		'label' => 'Update by',
		//CLD: 2026-10-06T21:05:48+03:00: select (was radio: there is no Field_radio, attachSchema() on a Field failed)
		'type' => 'select',
		//field visible/editable only for sysadmins
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'choices' => [
		    'nobody' => 'Nobody',
		    'everybody' => 'Everybody',
		    'permission' => 'By permission'
		],
	    ],
	    //perm:delete
	    [
		'key' => 'perm:delete',
		'help' => false,
		'label' => 'Delete by',
		//CLD: 2026-10-06T21:05:48+03:00: select (was radio: there is no Field_radio, attachSchema() on a Field failed)
		'type' => 'select',
		//field visible/editable only for sysadmins
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'choices' => [
		    'nobody' => 'Nobody',
		    'everybody' => 'Everybody',
		    'permission' => 'By permission'
		],
	    ],
	];
    }


    //helpers
    public function getLabel() {
	return (($this->{'translate'} === false) ? $this->label : \V::t($this->label));
    }

    public function getHelp() {
	return (($this->{'translate'} === false) ? $this->help : \V::t($this->help));
    }
}