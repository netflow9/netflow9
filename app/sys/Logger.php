<?php

namespace sys;
/**
 * Log
 *
 * This is the primary logger for application. You may specify in config:
 * enabled - whether the component is enabled
 * level - the logging level (DEBUG, INFO, WARN, ERROR)
 * log.writer - name of the class that will perform the actual logging
 */
class Logger extends BaseObject {
    protected static $levels = [
        'ERROR'	=> 1,
        'WARN'	=> 2,
        'INFO'	=> 3,
        'DEBUG' => 4
    ];

    // Constructor
    public function __construct($config = []) {
	//set some defaults
	$defaults = [
	    'enabled' => true,
	    'level' => 'INFO',
	    'facility' => 'app',
	];
	$config += $defaults;
	parent::__construct($config);
    }

    public function _init() {
	parent::_init();
	$this->_config['level'] = self::$levels[$this->_config['level']];
    }

    // Set level; converts a label to numeric value
    public function setLevel($level) {
	$level = strtoupper($level);

	if (!isset(self::$levels[$level]))
            throw new \InvalidArgumentException('Invalid log level');
        $this->_config['level'] = self::$levels[$level];
    }

    // Get level as text
    public function getLevel() {
	$flip = array_flip(self::$levels);
	return $flip[$this->_config['level']];
    }

    // set facility
    public function setFacility($facility) {
	$this->_config['facility'] = $facility;
    }
    // get facility
    public function getFacility() {
	return $this->_config['facility'];
    }

    // add caller prefix
    public function prefixWithCaller($flag = true) {
	$this->_config['prefix_with_caller'] = !empty($flag);
    }


    public function writer($facility, $level, $message) {
	//console, stderr, a '/' file path, or a name
	switch(true) {
	    case $facility == 'console':
	    case $facility == 'stdout':
		$fname = 'php://stdout';
		break;
	    case $facility == 'stderr':
		$fname = 'php://stderr';
		break;
	    case $facility[0] == '/':
		$fname = $facility;
		break;
	    default:
		$fname = BASE . "/logs/{$facility}.log";
		break;
	}

	$fp = fopen($fname, 'a+');
	if (!is_resource($fp))
	    return false;

    	$message = date('c') . ": $level: " . (string)$message . PHP_EOL;

    	$res = fwrite($fp, $message);
    	fclose($fp);

    	return $res;
    }



    //universal method for 'debug', 'warn', 'info', 'error' -> these methods/keywords should be lowercased levels
    public function __call($level, $params) {
        if (!$this->_config['enabled'])
    	    return false;

	$level = strtoupper($level);

	//keep this first so that we detect programming errors
	if (!isset(self::$levels[$level]))
            throw new \ErrorException("Logger: method '$level' not defined");

	//skip logging if not enough level
    	if (self::$levels[$level] > $this->_config['level'])
            return false;


	//CLD: 2026-10-18T00:00:00+03:00: accept any number of params; facility is always the configured one (see setFacility)
	//called without any argument
	if (!count($params))
	    return false;

	$facility = $this->_config['facility'];

	//CLD: 2026-10-18T00:00:00+03:00: build message from all params, space separated; non-scalars are dumped with print_r
	$parts = [];
	foreach ($params as $p) {
	    if (is_null($p))
		$parts[] = 'NULL';
	    elseif (is_bool($p))
		$parts[] = $p ? 'true' : 'false';
	    elseif (is_scalar($p) || (is_object($p) && method_exists($p, '__toString')))
		$parts[] = (string)$p;
	    else
		$parts[] = print_r($p, true);
	}
	$message = implode(' ', $parts);

	$this->writer($facility, $level, $message);
    }
}
