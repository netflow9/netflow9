<?php

namespace sys;

class DataProviderArray implements \Iterator {
    public $data = [];
    public $options = [];

    public $iterator = null;

    //construtor receives:
    // 'data' => the data
    // 'options' => sort, skip, limit,

    public function __construct($data = [], $options = []) {
	$this->data = $data;
	$this->options = $options;
    }

    public function count($options = []) {
	return count($this->data);
    }

    public function skip($value = 0) {
	$this->options['skip'] = (int)$value;
    }

    public function limit($value = 10) {
	$this->options['limit'] = (int)$value;
    }

    //ignored
    public function sort($field, $dir) {
    }

    //ignored
    public function query($new = null) {
    }

    private function __init_iterator() {
	if (!empty($this->iterator))
	    return;

	$this->iterator = new \ArrayIterator($this->data);
	$this->iterator->rewind();
    }

    #[\ReturnTypeWillChange]
    public function current() {
	$this->__init_iterator();
    	return $this->iterator->current();
    }

    #[\ReturnTypeWillChange]
    public function next() {
	$this->__init_iterator();
	return $this->iterator->next();
    }

    #[\ReturnTypeWillChange]
    public function key() {
	$this->__init_iterator();
	return $this->iterator->key();
    }

    public function valid(): bool {
	$this->__init_iterator();
	return $this->iterator->valid();
    }

    public function rewind(): void {
	$this->__init_iterator();
	$this->iterator->rewind();
    }
}