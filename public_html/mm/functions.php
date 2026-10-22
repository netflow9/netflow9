<?php

//variable services
function ObjectId($id) {
    if (is_string($id) && strlen($id) == 24)
	return new \MongoDB\BSON\ObjectId($id);
    return $id;
}
function MongoDate($v) {
    if (is_string($v))
	$v = new DateTime($v);
    return new \MongoDB\BSON\UTCDateTime($v);
}
function MongoBinaryData($v) {
    return new \MongoDB\BSON\Binary(base64_decode($v));
}
