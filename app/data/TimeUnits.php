<?php

namespace data;

class TimeUnits {
    const LABELS = [ 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days' ];
    const SECONDS = [ 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];

    //with seconds: only the Wait step takes them (for the others a unit of seconds is no unit: a Task has no time limit then)
    const LABELS_ALL = [ 'seconds' => 'Seconds' ] + self::LABELS;
    const SECONDS_ALL = [ 'seconds' => 1 ] + self::SECONDS;
}
