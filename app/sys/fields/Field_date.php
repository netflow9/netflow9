<?php

//CLD: 2026-10-05T22:02:50+03:00: new field type: date only (no time); a Field_datetime fixed to the `date` format

namespace sys\fields;

class Field_date extends Field_datetime {
    public $name = 'Date';
    public $icon = 'bi bi-calendar';

    public function _init() {
	parent::_init();

	//always a date: display with app.format.date, date picker without time
	$this->_data['format'] = 'date';
    }

    //stored as the start of the day (midnight in the app timezone), whatever time the input carried
    public function cast($value) {
	$value = parent::cast($value);
	if (!($value instanceof \MongoDB\BSON\UTCDateTime))
	    return $value;

	$day = $value->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
	return new \MongoDB\BSON\UTCDateTime($day->getTimestamp() * 1000);
    }
}
