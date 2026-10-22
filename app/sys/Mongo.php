<?php

namespace sys;

class Mongo extends BaseObject {

    private $client = null;

    // Checks if all data is in place for connection.
    public function __construct($config = array()) {
	//set some defaults
	$defaults = array(
	    'uri' => 'mongodb://localhost:27017',
	    'uriOptions' => [
		//'appname' => 'MyAPP',
		//'authSource' => null,
		//'canonicalizeHostname' => null,
		//'connectTimeoutMS' => 10000,
		//'gssapiServiceName' => null,
		//'heartbeatFrequencyMS' => 60000,
		//'journal' => true,
		//'localThresholdMS' => 15,
		//'maxStalenessSeconds' => null,
		//'password' => null,
		//'readConcernLevel' => '', //local, majority, linearizable
		//'readPreference' => 'primary',
		//'readPreferenceTags' => [],
		//'replicaSet' => null,
		//'safe' => true, //deprecated
		//'serverSelectionTimeoutMS' => 30000,
		//'serverSelectionTryOnce' => true,
		//'slaveOk' => true, //deprecated
		//'socketCheckIntervalMS' => 5000,
		//'socketTimeoutMS' => 300000,
		//'ssl' => false,
		//'username' => null,
		//'w' => 'majority',
		//'wTimeoutMS' => 0,
	    ],

	    'driverOptions' => [
		'typeMap' => [ 'array' => 'array', 'document' => 'array', 'root' => 'array', ],
	    ],
	);
	$config = \util\Set::merge($defaults, $config);

	//make sure we have `majority`
	$config['uriOptions']['w'] = 'majority';

	parent::__construct($config);
    }

    private function getClient() {
	if (empty($this->client))
	    $this->client = new \MongoDB\Client($this->uri, $this->uriOptions, $this->driverOptions);
	return $this->client;
    }

    public function __call($method, $params) {
	$client = $this->getClient();

	switch (count($params)) {
	    case 0:
		return $client->{$method}();
	    case 1:
		return $client->{$method}($params[0]);
	    case 2:
		return $client->{$method}($params[0], $params[1]);
	    case 3:
		return $client->{$method}($params[0], $params[1], $params[2]);
	    default:
		return call_user_func_array([ $client, $method ], $params);
	}
    }
}