<?php

//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\app)
namespace models\node;

class NodeAppWait extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'wait';
    public static $label = 'Wait';
    public static $icon = 'bi-stopwatch';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;

    //CLD: 2026-10-09T08:10:30+03:00: model field definitions with rules (WfNode::configForm(), a sys\NestedModel; were app.smartform fields)
    public static function configSchema() {
	return [
	    [
		'key' => 'amount',
		'type' => 'number',
		'label' => 'Wait for',
		'required' => true,
		'default' => 1,
		//CLD: 2026-10-09T08:35:15+03:00: required again (notEmpty accepts 0); the range rule skips the empty value (one message)
		'validate' => [ [ 'inRange', 'lower' => 1, 'skipEmpty' => true, 'message' => 'must be 1 or more' ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'step' => 1, 'width' => 6 ],
	    ],
	    [
		'key' => 'unit',
		'type' => 'select',
		'label' => 'Units',
		'required' => true,
		'default' => 'minutes',
		'choices' => [ 'seconds' => 'Seconds', 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days' ],
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    //CLD: 2026-10-06T23:21:04+03:00: first visit sets resume_at = now + amount x unit and sleeps; continues once the time has come
    //(the execution sleeps meanwhile: its wake_at tells a scheduler when to run it again)
    const UNIT_SECONDS = [ 'seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];

    public function execute($edgeId = null) {
	$now = time();

	if (!($this->resume_at instanceof \MongoDB\BSON\UTCDateTime)) {
	    $amount = $this->cfg('amount');
	    $unit = (string)$this->cfg('unit', 'minutes');
	    if (!is_numeric($amount) || $amount < 1 || !isset(self::UNIT_SECONDS[$unit]))
		throw new \ErrorException('Wait: invalid amount or unit');

	    $seconds = (int)round($amount * self::UNIT_SECONDS[$unit]);
	    $this->resume_at = new \MongoDB\BSON\UTCDateTime(($now + $seconds) * 1000);
	    $this->exec->log($this->wfid, "wait {$amount} {$unit}");
	}

	if ($now < $this->resume_at->toDateTime()->getTimestamp())
	    return self::SLEEP;

	$this->resume_at = null;
	return [ 0 ];
    }

}
