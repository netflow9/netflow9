<?php

namespace data;

class Http {
    //the API request steps
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];
    //the same with PATCH, in the order the lists show them
    const METHODS_PATCH = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE' ];
}
