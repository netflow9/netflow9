<?php

namespace sys;

class DataProvider implements \Iterator {
    public $objectName = null;
    public $objectClass = null;

    public $query = [];
    public $options = [];
    public $modelOptions = [];

    public $iterator = null;
    public $cursor = null;
    public $cached_count = null;


    //construtor receives:
    // 'objectName' => the object that we are creating
    // 'objectClass' => object class to be created
    // 'query' => query filter
    // 'options' => sort, skip, limit, projection, hint...
    // 'modelOptions' => [
    //     'database' => database to query
    //     'collection' => collection to query

    public function __construct($objectName, $objectClass, $query, $options, $modelOptions) {
	$this->query = $query;
	$this->options = $options + [ 'batchSize' => 101 ];
	$this->objectClass = $objectClass;
	$this->objectName = $objectName;

	$this->modelOptions = $modelOptions ?? [];
    	$this->modelOptions['exists'] = true;
    	$this->modelOptions['objectName'] = $objectName ?? null;
    }

    public function count($options = []) {
	if ($this->cached_count === null) {
	    $coll = \V::app()->mongo->selectCollection(
		$this->modelOptions['database'],
		$this->modelOptions['collection']
	    );
	    //CLD: 2026-10-26T07:17:30+02:00: countDocuments (count() is deprecated, and slower)
	    $this->cached_count = $coll->countDocuments($this->query, $options);
	}
	return $this->cached_count;
    }

    public function skip($value = 0) {
	if (!empty($this->cursor))
	    throw new \ErrorException('cannot set `skip` after the cursor has been initialized');
	$this->options['skip'] = (int)$value;
    }

    public function limit($value = 10) {
	if (!empty($this->cursor))
	    throw new \ErrorException('cannot set `limit` after the cursor has been initialized');
	$this->options['limit'] = (int)$value;
    }

    public function sort($field, $dir) {
	if (!empty($this->cursor))
	    throw new \ErrorException('cannot set `sort` after the cursor has been initialized');
	$this->options['sort'] = [ $field => (int)$dir ];
    }

    public function query($new = null) {
	if (!empty($this->cursor) && !empty($new))
	    throw new \ErrorException('cannot set `query` after the cursor has been initialized');

	if (empty($new))
	    return $this->query;
	$this->query = $new;
    }

    private function __init_cursor() {
	if (!empty($this->cursor))
	    throw new \ErrorException('cannot re-initialize cursor');

	$coll = \V::app()->mongo->selectCollection($this->modelOptions['database'], $this->modelOptions['collection']);
	$this->cursor = $coll->find($this->query, $this->options);
	$this->iterator = new \IteratorIterator($this->cursor);
	$this->iterator->rewind();
    }


    #[\ReturnTypeWillChange]
    public function current() {
	if (empty($this->cursor))
	    $this->__init_cursor();

    	$class = $this->objectClass;
    	$o = $class::create($this->iterator->current(), $this->modelOptions);
    	$o->afterFind();
    	return $o;
    }

    public function toArray() {
	if (empty($this->cursor))
	    $this->__init_cursor();

	$data = [];
	foreach($this as $o)
	    $data[] = $o;
	return $data;
    }

    #[\ReturnTypeWillChange]
    public function next() {
	if (empty($this->cursor))
	    $this->__init_cursor();

	return $this->iterator->next();
    }

    #[\ReturnTypeWillChange]
    public function key() {
	if (empty($this->cursor))
	    $this->__init_cursor();

	return $this->iterator->key();
    }

    public function valid(): bool {
	if (empty($this->cursor))
	    $this->__init_cursor();

	return $this->iterator->valid();
    }

    public function rewind(): void {
	if (empty($this->cursor))
	    $this->__init_cursor();

	$this->iterator->rewind();
    }
}