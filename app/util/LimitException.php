<?php

namespace util;

//CLD: 2026-10-24T09:45:36+03:00: new: what goes over a limit of the account's tier (Tiers::check): an \ErrorException with the message for the user
class LimitException extends \ErrorException {
    public $key;
    public $limit;
    public $used;

    public function __construct($message, $key, $limit, $used) {
	parent::__construct($message);
	$this->key = $key;
	$this->limit = $limit;
	$this->used = $used;
    }
}
