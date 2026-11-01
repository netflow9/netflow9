<?php

/*
    CLD: 2026-11-01T12:00:00+02:00: new: an error answer of vCenter (util\VSphere): its type (REST error_type, e.g. ALREADY_IN_DESIRED_STATE; VI/JSON fault, e.g. InvalidPowerState)
    and HTTP status, so a step can take some as done already
*/

namespace util;

class VSphereError extends \RuntimeException {
    public $type;
    public $status;

    public function __construct($message, $type, $status) {
	parent::__construct($message);
	$this->type = (string)$type;
	$this->status = (int)$status;
    }
}
