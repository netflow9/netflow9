<?php

namespace sys;

class BaseModel {
    protected static $collection = null;

    //the validation rules
    public $validates = [];

    //schema
    public $schema = [];

    //object configuration
    protected $_config = [];

    // Associative array of the entity's fields and values.
    //including what to unset and array members that were accessed by ref.
    protected $_data = [];
    protected $_errors = [];
    protected $_updated = [];
    protected $_odata = [];
    protected $_by_ref = [];
    protected $_unset = [];

    //whether to compare values when assignment is made; used just initially when creating object
    //do not mess with this
    public $_trackChanges = true;

    //the object that we are instantiated (the called class, short name)
    public $__objectName = null;

    public static function _collection() {
	return static::$collection;
    }

    public static function _database() {
	return \V::app()->mongo->database;
    }
    //CLD: 2026-10-12T12:18:11+03:00: the indexes of this model's collection: [ [ keys, options ], ... ] (e.g. [ [ 'aid' => 1, 'project' => 1 ], [] ]) (created by `v setup::indexes`)
    public static $indexes = [];


    /**
     * Creates a new record object with default values.
     *
     * Options defined:
     * - 'data' _array_: Data to enter into the record. Defaults to an empty array.
     *
     * @param array $config
     * @return object Record object.
     */
    public function __construct(array $config = []) {
	$this->_config = $config['options'] ?? [];

	//the objectName
	$this->__objectName = $this->_config['objectName'] ?? (new \ReflectionClass($this))->getShortName();

	if (!isset($this->_config['init']))
	    $this->_config['init'] = true;
	if (!isset($this->_config['exists']))
	    $this->_config['exists'] = false;
	if (!isset($this->_config['attachSchema']))
	    $this->_config['attachSchema'] = true;
	if (!isset($this->_config['global']))
	    $this->_config['global'] = false;

	//the data
	$this->_trackChanges = false;
	$this->data($config['data'] ?? []);
	$this->_trackChanges = true;

	$this->_odata = $this->_data;
	$this->_updated = [];
	$this->_by_ref = [];

	//validation rules
	$this->validates = $config['validates'] ?? [];

	if ($this->_config['init'])
	    $this->_init();
    }

    // $obj = MyModel::create($data, $options);
    public static function create($data = [], $options = []) {
	$class = get_called_class();
	return new $class(compact('data', 'options'));
    }

    public function _init() {
	if ($this->_config['attachSchema'])
	    $this->attachSchema();
    }

    // ------------------------------------------------------------------------------------
    //helpers and utilities
    public function getObjectName() {
	if (empty($this->__objectName))
	    $this->__objectName = (new \ReflectionClass($this))->getShortName();
	return $this->__objectName;
    }

    //setter/getter
    public function config($name, $value = null) {
	if ($name === null)
	    return null;

	//get config parameter 'name'
	if ($value === null) {
	    if (!array_key_exists($name, $this->_config))
		$this->_config[$name] = null;
	    return $this->_config[$name];
	}

	//value is not null set it
	return $this->_config[$name] = $value;
    }

    // A flag indicating whether or not this record exists.
    public function exists() {
	if (isset($this->_config['exists']))
	    return $this->_config['exists'];
	return false;
    }

    public function modified() {
	//foreach key in __ref_updated, check if values differ
	foreach($this->_by_ref as $key) {
	    if (!$this->valuesAreIdentical($this->_data[$key], $this->_odata[$key] ?? null ))
		return true;
	}
	return !empty($this->_updated);
    }

    //get the old object data
    public function odata() {
	return $this->_odata;
    }

    //get the modified data
    public function updated() {
	$_by_ref = [];

	//foreach key in __ref_updated, check if values differ
	foreach($this->_by_ref as $key) {
	    if (!$this->valuesAreIdentical($this->_data[$key], $this->_odata[$key] ?? null ))
		$_by_ref[$key] = $this->_data[$key];
	}
	return array_merge($this->_updated, $_by_ref);
    }

    //get key
    public function key() {
	if (isset($this->_config['exists']))
	    return $this->_id;
	return false;
    }

    public function getSystemSchema() {
	return [
	    [
		'key' => 'created_at',
		'type' => 'datetime',
		'label' => 'Created at',
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'perm:delete' => 'nobody',
		//CLD: 2026-10-10T18:44:22+03:00: never in forms
		'showInForms' => false,
	    ],
	    [
		'key' => 'updated_at',
		'type' => 'datetime',
		'label' => 'Updated at',
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
		'perm:delete' => 'nobody',
		//CLD: 2026-10-10T18:44:22+03:00: never in forms
		'showInForms' => false,
	    ],
	];
    }

    //by default it will search for schema in DB
    public function getSchema() {
	$schema = \V::app()->om->find('Field', [ 'objectName' => $this->getObjectName() ])->toArray();
	$sys_fields = $this->getSystemSchema();
	foreach($this->getSystemSchema() as $fDef)
	    $schema[] = \sys\fields\Field::create($fDef);

	return $schema;
    }

    public function afterAttachSchema() {
    }

    // executed in object context, attaches a schema
    public function attachSchema($schema = []) {
	//\V::app()->log->debug('BaseModel::attachSchema: enter. current objectName=' . $this->getObjectName());
	//check if already attached
	if (!empty($this->schema))
	    return;

	//if no provided schema, get model schema
	$schema = (empty($schema) ? $this->getSchema() : []);

	foreach ($schema as $fDef) {
	    $f = (!is_object($fDef) ? \sys\fields\Field::create($fDef) : $fDef);
	    $f->bindTo($this);
	    $this->schema[$f->key] = $f;
	}

	$this->afterAttachSchema();
    }


    //--------------------------------------------------------------------------------------
    /**
     * Allows several properties to be assigned at once or return the current data, i.e.:
     * {{{
     * $record->data(array('title' => 'Lorem Ipsum', 'value' => 42, '__notassigned' => '5555'));
     * $data = $record->data();
     * keys beginning with '__' will not be assigned!!!
     * }}}
     *
     * @param array $data An associative array of fields and values to assign to this `Entity`
     *        instance.
     * @return void
     */

    //CLD: 2026-10-06T08:57:32+03:00: default string form of an object: its `name` value, else its id; models override it (User, Project, File, ...)
    //read from _data: some subclasses (fields) declare a `$name` property of their own
    public function __toString() {
	$name = $this->_data['name'] ?? null;
	if (is_scalar($name) && (string)$name !== '')
	    return (string)$name;
	return (string)($this->_data['_id'] ?? '');
    }

    public function data($data = null) {
	switch(true) {
	    //get all data
	    case ($data === null):
		return $this->_data;
		break;
	    //array => set
	    case is_array($data):
		foreach ($data as $name => $value)
		    $this->__set($name, $value);
		return;
		break;
	    //string, no dot
	    case is_string($data) && !strpos($data, '.'):
		return $this->__get($data);
		break;
	    //string, with dot
	    case is_string($data) && strpos($data, '.'):
		$data = explode('.', $data);
		$first = array_shift($data);
		$rv = $this->__get($first);
		$cur = & $rv;
		if (!is_array($cur))
		    $cur = [ $cur ];
		foreach ($data as $seg) {
		    if (!isset($cur[$seg]))
			return null;

		    $cur = $cur[$seg];
		}
		return $cur;
		break;
	}
    }

    // -----------------------------------------------------------------------------------
    // setter, getter and helpers

    public function valuesAreIdentical($a, $b) {
	if($a instanceof \MongoDB\BSON\UTCDateTime)
	    $a = $a->toDateTime()->getTimestamp();
	if($b instanceof \MongoDB\BSON\UTCDateTime)
	    $b = $b->toDateTime()->getTimestamp();

	if ($a instanceof \MongoDB\BSON\ObjectId)
	    $a = (string)$a;
	if ($b instanceof \MongoDB\BSON\ObjectId)
	    $b = (string)$b;

	if (is_array($a)) {
	    $a = serialize($a);
	}
	if (is_array($b)) {
	    $b = serialize($b);
	}

        if (gettype($a) != gettype($b))
    	    return false;

        return $a === $b;
    }

    public function __set($name, $value = null) {
	//if only a single argument was given and that is an array, call self foreach key => value pair
	if (is_array($name) && !$value) {
	    array_map([ &$this, '__set' ], array_keys($name), array_values($name));
	    return;
	}

	//check if there is a field by this name and cast if necesarry
	$field = $this->schema[$name] ?? null;

	if (!empty($field))
	    $value = $field->cast($value);

	if (!$this->_trackChanges) {
	    $this->_data[$name] = $value;
	} else {
	    $old_value = $this->_odata[$name] ?? null;
	    $crt_value = $this->_data[$name] ?? null;
	
	    if (!$this->valuesAreIdentical($value, $old_value) || !$this->valuesAreIdentical($value, $crt_value)) {
		$this->_updated[$name] = $value;
		$this->_data[$name] = $value;
	    }
	}
    }

    public function &__get($name) {
	if (isset($this->_data[$name])) {
	    //we cannot determine the change if modifing an array (smth like: $object->arr[1] = ....)
	    if (is_array($this->_data[$name]) && $this->_trackChanges) {
		$this->_by_ref[] = $name;
	    }
	    return $this->_data[$name];
	}

	$null = null;
	return $null;
    }

    // * Overloading for calling `isset()` or `empty()` on inaccessible properties.
    public function __isset($name) {
	return isset($this->_data[$name]);
    }

    public function __unset($name) {
	unset($this->_data[$name]);
	$this->_unset[$name] = 1;
    }




    //-------------------------------------------------------------------------------------------
    /**
     * An important part of describing the business logic of a model class is defining the
     * validation rules. In Lithium models, rules are defined through the `$validates` class
     * property, and are used by this method before saving to verify the correctness of the data
     * being sent to the backend data source.
     *
     * Note that these are application-level validation rules, and do not
     * interact with any rules or constraints defined in your data source. If such constraints fail,
     * an exception will be thrown by the database layer. The `validates()` method only checks
     * against the rules defined in application code.
     *
     * This method uses the `Validator` class to perform data validation. An array representation of
     * the entity object to be tested is passed to the `check()` method, along with the model's
     * validation rules. Any rules defined in the `Validator` class can be used to validate fields.
     * See the `Validator` class to add custom rules, or override built-in rules.
     *
     * @see lithium\data\Model::$validates
     * @see lithium\util\Validator::check()
     * @see lithium\data\Entity::errors()
     * @param string $entity Model entity to validate. Typically either a `Record` or `Document`
     *        object. In the following example:
     *        {{{
     *            $post = Posts::create($data);
     *            $success = $post->validates();
     *        }}}
     *        The `$entity` parameter is equal to the `$post` object instance.
     * @param array $options Available options:
     *        - `'rules'` _array_: If specified, this array will _replace_ the default
     *          validation rules defined in `$validates`.
     *        - `'events'` _mixed_: A string or array defining one or more validation
     *          _events_. Events are different contexts in which data events can occur, and
     *          correspond to the optional `'on'` key in validation rules. For example, by
     *          default, `'events'` is set to either `'create'` or `'update'`, depending on
     *          whether `$entity` already exists. Then, individual rules can specify
     *          `'on' => 'create'` or `'on' => 'update'` to only be applied at certain times.
     *          Using this parameter, you can set up custom events in your rules as well, such
     *          as `'on' => 'login'`. Note that when defining validation rules, the `'on'` key
     *          can also be an array of multiple events.
     * @return boolean Returns `true` if all validation rules on all fields succeed, otherwise
     *         `false`. After validation, the messages for any validation failures are assigned to
     *         the entity, and accessible through the `errors()` method of the entity object.
     * @filter
     */
    /**
     * Criteria for data validation.
     *
     * Example usage:
     * {{{
     * public $validates = array(
     *     'title' => 'please enter a title',
     *     'email' => array(
     *         array('notEmpty', 'message' => 'Email is empty.'),
     *         array('email', 'message' => 'Email is not valid.'),
     *     )
     * );
     * }}}
     *
     * @var array
     */
    public function validates($options = []) {
	//add aid validation
	if (empty($this->_config['global']))
	    $this->validates['aid'] = [
		[ 'notEmpty', 'message' => 'inconsistency: aid is empty' ],
	    ];

	$defaults = [
	    'rules' => $this->validates,
	    'events' => $this->_config['exists'] ? 'update' : 'create',
	];
	$options += $defaults;
	$this->errors(false);

	$rules = $options['rules'];
	unset($options['rules']);

	$errors = \util\Validator::check($this->data(), $rules, $options);

	//CLD: 2026-10-18T09:42:21+03:00: the fields' own validation (e.g. Field_nested: the nested objects, errors keyed `<key>.<nested key>`)
	foreach($this->schema as $f) {
	    foreach($f->validateBinding($options['events']) as $key => $messages)
		$errors[$key] = array_merge($errors[$key] ?? [], (array)$messages);
	}

	if ($errors)
	    $this->errors($errors);

	$result = empty($errors);

	return $result;
    }

    /**
     * Access the errors of the record.
     *
     * @see lithium\data\Entity::$_errors
     * @param array|string $field If an array, overwrites `$this->_errors` if it is empty,
     *        if not, merges the errors with the current values. If a string, and `$value`
     *        is not `null`, sets the corresponding key in `$this->_errors` to `$value`.
     *        Setting `$field` to `false` will reset the current state.
     * @param string $value Value to set.
     * @return mixed Either the `$this->_errors` array, or single value from it.
     */

    public function errors($field = null, $value = null) {
	if ($field === false) {
	    return ($this->_errors = []);
	}
	if ($field === null) {
	    return $this->_errors;
	}
	if (is_array($field)) {
	    return ($this->_errors = array_merge_recursive($this->_errors, $field));
	}
	if ($value === null && isset($this->_errors[$field])) {
	    return $this->_errors[$field];
	}
	if ($value !== null) {
	    if (array_key_exists($field, $this->_errors)) {
		$current = $this->_errors[$field];
		return ($this->_errors[$field] = array_merge((array) $current, (array) $value));
	    }
	    return ($this->_errors[$field] = $value);
	}
	return $value;
    }

    //CLD: 2026-10-18T10:20:00+03:00: the label of an error key of errors(): its field's label; a nested path (Field_nested: `conditions.0.left`) through the nested fields'
    //labels ("Condition 1: Test"); a key without a field: the key itself
    public function errorLabel($key) {
	$parts = explode('.', (string)$key);
	$f = $this->schema[array_shift($parts)] ?? null;
	return $f ? $f->pathLabel($parts) : (string)$key;
    }

    //CLD: 2026-10-10T18:54:42+03:00: $check_permissions = user input (a posted form): only the form fields (showInForms) the user may update; other keys are ignored
    //(e.g. a posted `project` or `status`; was assignForm()). Without it: every schema field (internal assignments)
    public function assign($data = [], $check_permissions = false) {
	foreach((is_array($data) ? $data : []) as $key => $user_value) {
	    //ignore unknown fields
	    if (!array_key_exists($key, $this->schema))
		continue;

	    $field = $this->schema[$key];
	    //CLD: 2026-10-10T09:14:41+03:00: an empty input does not clear an encrypted field (forms never show its value: empty = keep)
	    if (!empty($field->encrypt) && ($user_value === '' || $user_value === null))
		continue;
	    //CLD: 2026-10-06T08:35:38+03:00: was $field->user_can('edit'), which no field defines (Field::__call -> 500 "unknown method");
	    //the field's update permission is what inputs and forms use (perm:update: nobody / everybody / permission)
	    if ($check_permissions && (!$field->showInForms || !$field->checkAccess(\V::app()->user->_id, 'update')))
		continue;

	    $this->$key = $field->cast($user_value);
	}
    }

    //CLD: 2026-10-10T18:54:42+03:00: removed assignForm(): assign($data, true) does it

    public function save($options = [] ) {
	$options += [ 'fsync' => false ];

	$collection = $this->_config['collection'] ?? static::_collection();
	$database = $this->_config['database'] ?? static::_database();

	$coll = \V::app()->mongo->selectCollection($database, $collection);

	$this->beforeSave();

	if ($this->exists()) {
	    //CLD: 2026-10-10T09:21:29+03:00: the arrays changed by reference are added to _updated BEFORE the update set is built (were added after it: such changes were
	    //saved only when another field changed too)
	    //foreach key in _by_ref, check if values differ
	    foreach(array_unique($this->_by_ref) as $key) {
		if (array_key_exists($key, $this->_data) && !$this->valuesAreIdentical($this->_data[$key], $this->_odata[$key] ?? null))
		    $this->_updated[$key] = $this->_data[$key];
	    }

	    //update: build the update set
	    $__update = [];
	    if (!empty($this->_updated))
		$__update['$set'] = $this->_updated;
	    if (!empty($this->_unset))
		$__update['$unset'] = $this->_unset;

	    if (empty($__update))
		return true;

	    //CLD: 2026-10-11T17:35:22+03:00: the values before the change of the changed keys (model:after-save hook, e.g. the audit log)
	    $before = [];
	    foreach(array_merge(array_keys($__update['$set'] ?? []), array_keys($__update['$unset'] ?? [])) as $key)
		$before[$key] = $this->_odata[$key] ?? null;

	    $__update['$set']['updated_at'] = new \MongoDB\BSON\UTCDateTime();
	    //CLD: 2026-10-10T09:14:41+03:00: stored forms of the values (e.g. encrypted fields)
	    $__update['$set'] = $this->storageDocument($__update['$set']);

	    //update
	    try {
		$result = $coll->updateOne(['_id' => $this->_data['_id'] ], $__update, [ 'upsert' => false ]);

		//CLD: 2026-10-10T09:22:59+03:00: matched = saved, also when the stored values were already these (was getModifiedCount: such a save returned null,
		//without afterSave); the document no longer existing returns false
		if ($result->getMatchedCount() == 1) {
		    $this->_updated = [];
		    //CLD: 2026-10-10T09:21:29+03:00: what is saved is the new original (changes are counted from it; were kept: the same arrays / unsets were sent again)
		    $this->savedState();
		    $this->afterSave();
		    //after the model's own afterSave: [ $this, values before the change ]
		    \V::app()->hooks->applyHook('model:after-save', [ $this, $before ]);
		    return true;
		}
	    } catch (\Exception $e) {
		\V::app()->log->debug('QModel::save: error: message=' . $e->getMessage());
		\V::app()->log->debug('QModel::save: error: data:' . print_r($this->_updated, true));
		throw new \ErrorException($e->getMessage());
	    }
	    //CLD: 2026-10-10T09:22:59+03:00: the document is gone (was: no return value)
	    return false;
	} else {
	    //insert
	    $this->_data['created_at'] = new \MongoDB\BSON\UTCDateTime();
	    try {
		//CLD: 2026-10-10T09:14:41+03:00: stored forms of the values (e.g. encrypted fields)
		$result = $coll->insertOne($this->storageDocument($this->_data));
		if ($result->getInsertedCount() == 1) {
		    $this->_updated = [];
		    $this->_data['_id'] = $result->getInsertedId();
		    $this->_config['exists'] = true;
		    //CLD: 2026-10-10T09:21:29+03:00: what is saved is the new original
		    $this->savedState();
		    $this->afterSave();
		    //CLD: 2026-10-11T17:35:22+03:00: after the model's own afterSave: [ $this, null = created ]
		    \V::app()->hooks->applyHook('model:after-save', [ $this, null ]);
		    return true;
		}
	    } catch (\Exception $e) {
		\V::app()->log->debug('QModel::save: error: message=' . $e->getMessage());
		\V::app()->log->debug('QModel::save: error: data:' . print_r($this->_updated, true));
		throw new \ErrorException($e->getMessage());
	    }
	}
    }

    public function delete($options = []) {
	if (($this->_config['exists'] === false) || !isset($this->_data['_id'])) {
	    $this->_data = [];
	    $this->_config['exists'] = false;
	    return true;
	}

	//convert id to a MongoId Object
	//CLD: 2026-10-18T17:15:00+03:00: a string _id that is not an ObjectId is kept (ObjectManager::keyIdQuery; was refused as invalid)
	$id = \sys\ObjectManager::keyIdQuery([ '_id' => $this->_data['_id'] ], __METHOD__)['_id'];

	$this->beforeDelete();

	$collection = $this->_config['collection'] ?? static::_collection();
	$database = $this->_config['database'] ?? static::_database();

	$coll = \V::app()->mongo->selectCollection($database, $collection);
	$result = $coll->deleteOne( [ '_id' => $id ], $options);

	if ($result->getDeletedCount() == 1) {
	    //CLD: 2026-10-11T17:35:22+03:00: the deleted document for the model:after-delete hook (e.g. the audit log): [ $this, its data ]
	    $data = $this->_data;
	    $this->_config['exists'] = false;
	    $this->_data = [];
	    \V::app()->hooks->applyHook('model:after-delete', [ $this, $data ]);
	    return true;
	}

	return false;
    }



    //CLD: 2026-10-10T09:14:41+03:00: the model hooks call the fields' hooks (Field::afterBindingFind, afterBindingSave, beforeBindingDelete; beforeBindingSave is
    //applied by save() to the document it writes, see storageDocument); models overriding them call parent::
    public function afterFind() {
	foreach($this->schema as $f)
	    $f->afterBindingFind();
    }
    public function beforeDelete() {
	foreach($this->schema as $f)
	    $f->beforeBindingDelete();
    }
    public function beforeSave() {
    }
    public function afterSave() {
	foreach($this->schema as $f)
	    $f->afterBindingSave();
    }

    //the document as written to the database: each schema field gives its stored value (Field::beforeBindingSave: e.g. encrypted); the
    //model's data is not changed (an early return / failed save never leaves stored forms in it)
    public function storageDocument(array $doc) {
	foreach($doc as $key => $value) {
	    if (isset($this->schema[$key]))
		$doc[$key] = $this->schema[$key]->beforeBindingSave($value);
	}
	return $doc;
    }

    //CLD: 2026-10-10T09:21:29+03:00: after a save: the data is the original, nothing pending (changed arrays, unsets)
    protected function savedState() {
	$this->_odata = $this->_data;
	$this->_by_ref = [];
	$this->_unset = [];
    }

    //CLD: 2026-10-10T09:14:41+03:00: sets a value as loaded from the database (not a change: not saved again); used by Field::afterBindingFind
    public function loadValue($key, $value) {
	$this->_data[$key] = $value;
	$this->_odata[$key] = $value;
    }

    //CLD: 2026-10-11T17:35:22+03:00: the value of `$key` as written to the audit log (models\AuditLog); models override it for values too large or unreadable as such
    //(e.g. Workflow `nodes`: a summary)
    public function auditValue($key, $value) {
	return $value;
    }

    //CLD: 2026-10-10T09:14:41+03:00: keys of the fields stored encrypted ('encrypt' => true)
    public function encryptedFields() {
	return array_keys(array_filter($this->schema, fn($f) => !empty($f->encrypt)));
    }


    //deref on object field
    public function deref($key) {
        $attr = $this->schema[$key] ?? null;
        if (empty($attr))
            return null;

        if (empty($objectName = $attr->object))
	    return $this->$key;

	if (empty($objDef = \V::app()->om->getObjectDef($objectName)))
	    return null;

        $value = $this->$key;
        if (empty($value))
            return $attr->multiple ? [] : null;

	//simple values first
	if (!$attr->multiple)
            return \V::app()->om->findById($objectName, $value);

        if (!is_array($value))
            $value = [ $value ];

	$result = [];
        foreach($value as $__value) {
            if (!empty($__value)) {
        	if (!empty($o = \V::app()->om->findById($objectName, $__value)))
        	    $result[] = $o;
	    }
        }

	return $result;
    }

    //override in child classes
    public function checkAccess($userId, $permName, $params = []) {
	//\V::app()->log->debug('QModel::checkAccess: using default checkAccess method. always true');
	//\V::app()->log->debug('QModel::checkAccess: userId=' . $userId);
	//\V::app()->log->debug('QModel::checkAccess: permName=' . $permName);
	//\V::app()->log->debug('QModel::checkAccess: params=' . print_r($params, true));
	return false;
    }
}