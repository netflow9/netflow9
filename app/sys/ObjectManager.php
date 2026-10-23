<?php

namespace sys;

class ObjectManager {

    //objects registry
    public $registry = [];

    public function __construct() {
	$this->registry = \data\SysObjects::get();
	//the core variants are checked too
	foreach(array_keys($this->registry) as $objectName)
	    $this->checkVariant($objectName);
    }

    public function registerDataTables() {
	//load dynamic objects for current account
	if (empty($account = \V::app()->var('rt.account'))) {
	    \V::app()->log->debug('register DataTables: empty account');
	    return;
	}

	$dts = $this->find('DataTableDef', [ 'aid' => $account->_id ], [ 'modelOptions' => [ 'attachSchema' => false ] ]);
	foreach ($dts as $dt) {
	    $this->registry[$dt->iname] = [
		'_id' => $dt->_id,
		'class' => '\sys\BaseModel',
		'collection' => $dt->iname,
		'userSelectable' => true,
		'name' => 'Data table: ' . $dt->name,
	    ];
	}
    }

    //get a list of fields in the form name => class for all available fields
    public function getFieldTypes() {
	$types = [];
	foreach($this->registry as $objectName => $objDef) {
	    if (!empty($objDef['field'])) {
		$fieldType = substr($objectName, 6);
		$types[$fieldType] = ucfirst($fieldType);
	    }
	}
	return $types;
    }

    //get a list of user-selectable objects; for use in Field_object field
    public function getSelectableObjects() {
	$this->registerDataTables();

	$objects = [];
	foreach($this->registry as $objectName => $objDef) {
	    if (!empty($objDef['userSelectable']))
		$objects[$objectName] = $objDef['name'] ?? $objectName;
	}
	return $objects;
    }

    public function getObjectDef($objectName) {
	if (empty($objectName))
	    throw new \ErrorException('OM::getObject: empty object name');

	if (!empty($this->registry[$objectName]))
	    return $this->registry[$objectName];

	//if not found, load definitions from db and try again
	$this->registerDataTables();

	return $this->registry[$objectName] ?? false;
    }

    public function isDefined($objectName) {
	return !empty($this->getObjectDef($objectName));
    }

    public function getObjectClass($objectName) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException("OM::getClass: unknown object: `$objectName`");

	return $objDef['class'];
    }

    //CLD: 2026-10-10T09:27:09+03:00: a variant is checked (checkVariant)
    public function registerObject($objectName, $data = []) {
	if (empty($data['class']))
	    throw new \ErrorException("empty class for `$objectName`");
	$this->registry[$objectName] = $data;
	$this->checkVariant($objectName);
    }

    //CLD: 2026-10-10T09:27:09+03:00: the variants are checked after the whole list is registered (a base and its variants may come in any order)
    public function registerObjects($data = []) {
	foreach($data as $objectName => $__data) {
	    if (empty($__data['class']))
		throw new \ErrorException("empty class for `$objectName`");
	    $this->registry[$objectName] = $__data;
	}
	foreach(array_keys($data) as $objectName)
	    $this->checkVariant($objectName);
    }

    //a variant ('variant-of' => base) must name a registered base with 'has-variants', and its class must extend the base's class
    //(it shares the base's collection, methods and permissions); a wrong entry fails at registration instead of at first use
    protected function checkVariant($objectName) {
	$base = $this->registry[$objectName]['variant-of'] ?? null;
	if (empty($base))
	    return;

	$baseDef = $this->registry[$base] ?? null;
	if (empty($baseDef))
	    throw new \ErrorException("OM: variant `$objectName`: base object `$base` is not registered");
	if (empty($baseDef['has-variants']))
	    throw new \ErrorException("OM: variant `$objectName`: base object `$base` has no 'has-variants'");

	$class = ltrim($this->registry[$objectName]['class'], '\\');
	$baseClass = ltrim($baseDef['class'], '\\');
	if (!class_exists($class))
	    throw new \ErrorException("OM: variant `$objectName`: class `$class` not found");
	if (!is_subclass_of($class, $baseClass))
	    throw new \ErrorException("OM: variant `$objectName`: class `$class` does not extend `$baseClass` (the class of `$base`)");
    }

    // =========================================================================================
    // Objects variants
    public function hasVariants($objectName) {
	if (empty($objectName))
	    throw new \ErrorException("OM::hasVariants: empty object name");

	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException("OM:hasVariants: object `$objectName` is not defined");

	return $objDef['has-variants'] ?? false;
    }

    public function isVariant($objectName) {
	if (empty($objectName))
	    throw new \ErrorException("OM::isVariant: empty object name");

	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException("OM::isVariant: object `$objectName` is not defined");

	return $objDef['variant-of'] ?? false;
    }

    public function getVariants($objectName) {
	if (empty($objectName))
	    throw new \ErrorException("OM::getVariants: empty object name");

	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException("OM::getVariants: object `$objectName` is not defined");

	if (empty($objDef['has-variants']))
	    return [];

	$result = [];
	foreach($this->registry as $object => $objDef) {
	    if (!empty($objDef['variant-of']) && $objDef['variant-of'] == $objectName)
		$result[$object] = $objDef['class'];
	}

	return $result;
    }



    // =========================================================================================
    //the account guard (SOC2.md gap 15): in a web request whose account is known (rt.account), the reads, updates and deletes of the objects
    //of an account (models that are not `global`) are limited to that account: a query without `aid` gets the account's, a query for another account is refused. Was: each
    //query had to add its `aid`, and one that did not reached every account's objects. Code that must reach other accounts (e.g. a sign-in, a token of another account)
    //runs inside acrossAccounts(). The console (daemon, checks, commands) is not limited

    //nesting depth of acrossAccounts()
    protected $acrossAccounts = 0;
    //[ class => bool ] whether the objects of a class are global (not of an account)
    protected $globalClasses = [];

    //runs $fn with the guard off and returns its result: $om->acrossAccounts(fn() => $om->findOne('TriggerToken', [ 'token' => $token ]))
    public function acrossAccounts(callable $fn) {
	$this->acrossAccounts++;
	try {
	    return $fn();
	} finally {
	    $this->acrossAccounts--;
	}
    }

    //whether $objectName's objects are global (the model's `global` option, set by its _init); a class that cannot be checked counts as an account's (limited)
    public function isGlobal($objectName, $objectClass) {
	$class = ltrim($objectClass, '\\');
	if (!array_key_exists($class, $this->globalClasses)) {
	    try {
		$proto = $class::create([], [ 'objectName' => $objectName, 'attachSchema' => false ]);
		$this->globalClasses[$class] = !empty($proto->config('global'));
	    } catch (\Throwable $e) {
		$this->globalClasses[$class] = false;
	    }
	}
	return $this->globalClasses[$class];
    }

    //$query limited to the current account (see above); $method names the caller in the refusal
    protected function accountQuery($objectName, $objectClass, array $query, $method) {
	if ($this->acrossAccounts > 0 || \V::app()->config('app.mode') != 'web')
	    return $query;
	$account = \V::app()->config('rt.account');
	if (empty($account->_id) || $this->isGlobal($objectName, $objectClass))
	    return $query;

	if (!array_key_exists('aid', $query)) {
	    $query['aid'] = $account->_id;
	    return $query;
	}
	$aid = $query['aid'];
	if ((is_string($aid) || $aid instanceof \MongoDB\BSON\ObjectId) && (string)$aid === (string)$account->_id)
	    return $query;

	\V::app()->log->warn("ObjectManager: refused a query of {$objectName} outside account {$account->_id} ({$method}): " . json_encode($query));
	throw new \ErrorException("{$method}: {$objectName}: query outside the current account (use acrossAccounts() where that is meant)");
    }

    //-----------------------------------------------------------------
    public static function to_oid($value) {
	if(is_null($value))
	    return null;
	try {
	    return new \MongoDB\BSON\ObjectId($value);
	} catch( \MongoDB\Driver\Exception\InvalidArgumentException $e) {
	    return null;
	}
    }

    //the _id of $query for the write methods (updateMany, updateOne, deleteOne, findOneAndUpdate): an ObjectId, or a string that is one, is an ObjectId;
    //also for find, findOne (findById) and count
    //another non-empty string is kept as it is (objects keyed by a string, e.g. ExecutionSlot `<aid>:<n>`); an operator array is left alone; anything else is invalid
    public static function keyIdQuery(array $query, $method) {
	if (!array_key_exists('_id', $query) || is_array($query['_id']) || $query['_id'] instanceof \MongoDB\BSON\ObjectId)
	    return $query;
	$id = self::to_oid($query['_id']);
	if ($id === null && is_string($query['_id']) && $query['_id'] !== '')
	    $id = $query['_id'];
	if ($id === null)
	    throw new \ErrorException($method . ': _id is invalid');
	$query['_id'] = $id;
	return $query;
    }

    public function create($objectName, $data = [], $options = []) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException("OM::create: unknown object: $objectName");

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//get object collection
	$collection = $objDef['collection'] ?? null;
	if (empty($collection))
	    $collection = $objectClass::_collection();

	//get object database
	$database = $objDef['database'] ?? null;
	if (empty($database))
	    $database = $objectClass::_database();

	//add
	$options['objectName'] = $objectName;
	$options['collection'] = $collection;
	$options['database'] = $database;

	return $objectClass::create($data, $options);
    }

    // $posts = $om->find('Posts', [ 'published' => true ], [ 'limit' => 10, 'skip' => 10, 'sort' => ... ]);
    public function find($objectName, $query = [], $options = []) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept (keyIdQuery; was refused as invalid)
	$query = self::keyIdQuery($query, __METHOD__);

	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");
	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);

	//get object collection
	$collection = $objDef['collection'] ?? null;
	if (empty($collection))
	    $collection = $objectClass::_collection();

	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");

	//get object database
	$database = $objDef['database'] ?? null;
	if (empty($database))
	    $database = $objectClass::_database();


	$modelOptions = $options['modelOptions'] ?? [];
	unset($options['modelOptions']);
	$modelOptions['collection'] = $collection;
	$modelOptions['database'] = $database;

	return new DataProvider($objectName, $objectClass, $query, $options, $modelOptions);
    }

    // $post = $om->findOne('Posts', [ 'published' => true ]);
    public function findOne($objectName, $query = [], $options = []) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept
	$query = self::keyIdQuery($query, __METHOD__);

	//also when querying by _id (a variant's findById returned an object of another variant with that id)
	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);

	//get object collection
	$collection = $objDef['collection'] ?? null;
	if (empty($collection))
	    $collection = $objectClass::_collection();

	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");

	//get object database
	$database = $objDef['database'] ?? null;
	if (empty($database))
	    $database = $objectClass::_database();

	$__query = new \MongoDB\Driver\Query($query, [ 'limit' => 1 ] + $options);
	$cursor = \V::app()->mongo->getManager()->executeQuery($database . '.' . $collection, $__query);
	$cursor->setTypeMap(\V::app()->mongo->driverOptions['typeMap']);
    	if (empty($result = current($cursor->toArray())))
    	    return null;

	$o = $objectClass::create($result, [
	    'database' => $database,
	    'collection' => $collection,
	    'exists' => true,
	    'objectName' => $objectName,
	]);
    	$o->afterFind();
    	return $o;
    }

    // $post = $om->findById('Posts', $id);
    public function findById($objectName, $id, $options = []) {
	return $this->findOne($objectName, [ '_id' => $id ], $options);
    }

    //removes every object of $objectName by dropping its collection (e.g. the rows of a deleted data table). Not for a variant or a family with
    //variants (they share one collection); objects are not loaded, so no model hooks run (no audit entries per object)
    public function drop($objectName) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");
	if ($this->isVariant($objectName) || $this->hasVariants($objectName))
	    throw new \ErrorException(__METHOD__ . ": $objectName shares its collection with its variants");

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");
	$collection = $objDef['collection'] ?? $objectClass::_collection();
	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");
	$database = $objDef['database'] ?? $objectClass::_database();

	\V::app()->mongo->selectCollection($database, $collection)->drop();
    }

    //changes every object of $objectName matching $query with the MongoDB update $update (operators, or an aggregation pipeline: a list); the objects
    //are not loaded, so no model hooks or checks run (e.g. re-dating immutable log entries). Returns the number of objects changed
    public function updateMany($objectName, array $query, array $update) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept (keyIdQuery; was refused as invalid)
	$query = self::keyIdQuery($query, __METHOD__);
	//a variant changes only its own objects (as find)
	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);
	$collection = $objDef['collection'] ?? $objectClass::_collection();
	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");
	$database = $objDef['database'] ?? $objectClass::_database();

	return \V::app()->mongo->selectCollection($database, $collection)->updateMany($query, $update)->getModifiedCount();
    }

    //changes ONE object of $objectName matching $query with the MongoDB update $update (as updateMany); options: upsert. Returns the number changed or
    //inserted (0 / 1). No model hooks or checks run for the change; a duplicate key (e.g. an upsert on a taken _id) throws as the driver does. An _id that is not an ObjectId
    //(e.g. a string key) is given as [ '$eq' => $id ] (a plain _id is converted)
    public function updateOne($objectName, array $query, array $update, array $options = []) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept (keyIdQuery; was refused as invalid)
	$query = self::keyIdQuery($query, __METHOD__);
	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);
	$collection = $objDef['collection'] ?? $objectClass::_collection();
	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");
	$database = $objDef['database'] ?? $objectClass::_database();

	$result = \V::app()->mongo->selectCollection($database, $collection)->updateOne($query, $update, $options);
	return $result->getModifiedCount() + (int)$result->getUpsertedCount();
    }

    //deletes ONE object of $objectName matching $query, without loading it (no model hooks: no beforeDelete, no audit entry); returns the number
    //deleted (0 / 1). _id as in updateOne
    public function deleteOne($objectName, array $query) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept (keyIdQuery; was refused as invalid)
	$query = self::keyIdQuery($query, __METHOD__);
	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);
	$collection = $objDef['collection'] ?? $objectClass::_collection();
	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");
	$database = $objDef['database'] ?? $objectClass::_database();

	return \V::app()->mongo->selectCollection($database, $collection)->deleteOne($query)->getDeletedCount();
    }

    //changes ONE object of $objectName matching $query with the MongoDB update $update, atomically, and returns it as changed (or as it was, option
    //'returnDocument' => 'before'); null when none matches. For counters that must never repeat (e.g. Workflow::nextVersionNumber). Options: projection, sort,
    //upsert, returnDocument (after | before). No model hooks or checks run for the change
    public function findOneAndUpdate($objectName, array $query, array $update, array $options = []) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept (keyIdQuery; was refused as invalid)
	$query = self::keyIdQuery($query, __METHOD__);
	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);
	$collection = $objDef['collection'] ?? $objectClass::_collection();
	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");
	$database = $objDef['database'] ?? $objectClass::_database();

	$options['returnDocument'] = ($options['returnDocument'] ?? 'after') === 'before'
	    ? \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_BEFORE : \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER;
	$options['typeMap'] = \V::app()->mongo->driverOptions['typeMap'];
	$result = \V::app()->mongo->selectCollection($database, $collection)->findOneAndUpdate($query, $update, $options);
	if (empty($result))
	    return null;

	$o = $objectClass::create($result, [
	    'database' => $database,
	    'collection' => $collection,
	    'exists' => true,
	    'objectName' => $objectName,
	]);
	$o->afterFind();
	return $o;
    }

    //stores new objects $models (models of $objectName, e.g. checked with validates()) in one write: their data as save() stores it
    //(storageDocument), with created_at; for many objects at once (e.g. a data table import). No model hooks run (no beforeSave, no audit entry per
    //object). Returns the number stored
    public function insertMany($objectName, array $models) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");
	if (empty($models))
	    return 0;

	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");
	$collection = $objDef['collection'] ?? $objectClass::_collection();
	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");
	$database = $objDef['database'] ?? $objectClass::_database();

	$docs = [];
	foreach($models as $m) {
	    $doc = $m->storageDocument($m->data());
	    $doc['created_at'] = $doc['created_at'] ?? new \MongoDB\BSON\UTCDateTime();
	    if ($this->isVariant($objectName))
		$doc['__variant'] = $objectName;
	    $docs[] = $doc;
	}
	return \V::app()->mongo->selectCollection($database, $collection)->insertMany($docs)->getInsertedCount();
    }

    public function count($objectName, $query = [], $options = []) {
	$objDef = $this->getObjectDef($objectName);
	if (empty($objDef))
	    throw new \ErrorException(__METHOD__ . ": unknown object: $objectName");

	//special case for id
	//a string _id that is not an ObjectId is kept
	$query = self::keyIdQuery($query, __METHOD__);

	//a variant counts only its own objects
	if ($this->isVariant($objectName))
	    $query['__variant'] = $objectName;

	//get object collection
	$objectClass = $objDef['class'] ?? null;
	if (empty($objectClass))
	    throw new \ErrorException(__METHOD__ . ": empty class for object: $objectName");

	//the account guard (accountQuery)
	$query = $this->accountQuery($objectName, $objectClass, $query, __METHOD__);

	$collection = $objDef['collection'] ?? null;
	if (empty($collection))
	    $collection = $objectClass::_collection();

	if (empty($collection))
	    throw new \ErrorException("Unknown collection for: $objectName");

	//get object database
	$database = $objDef['database'] ?? null;
	if (empty($database))
	    $database = $objectClass::_database();

	return \V::app()->mongo->selectCollection($database, $collection)->count($query, $options);
    }

    //creates the indexes every model declares (static $indexes of its class, plugins' models included);
    //creating an index that exists does nothing. Returns [ [ 'label' => collection keys options, 'name' => index name | null, 'error' => message | null ], ... ]
    public function createIndexes() {
	$done = [];
	$result = [];
	foreach($this->registry as $objectName => $def) {
	    $class = $def['class'] ?? null;
	    //models only: no field types, no variants (they share their family's collection and indexes)
	    if (empty($class) || !empty($def['field']) || !empty($def['variant-of']) || !class_exists($class) || !is_subclass_of($class, '\sys\BaseModel'))
		continue;
	    if (empty($class::$indexes))
		continue;
	    $database = $def['database'] ?? $class::_database();
	    $collection = $def['collection'] ?? $class::_collection();
	    if (empty($collection) || isset($done["{$database}.{$collection}"]))
		continue;
	    $done["{$database}.{$collection}"] = true;

	    $coll = \V::app()->mongo->selectCollection($database, $collection);
	    foreach($class::$indexes as $index) {
		$keys = $index[0];
		$options = $index[1] ?? [];
		$label = $collection . ' ' . json_encode($keys) . ($options ? ' ' . json_encode($options) : '');
		try {
		    $result[] = [ 'label' => $label, 'name' => $coll->createIndex($keys, (array)$options), 'error' => null ];
		} catch (\Throwable $e) {
		    $result[] = [ 'label' => $label, 'name' => null, 'error' => $e->getMessage() ];
		}
	    }
	}
	return $result;
    }
}
