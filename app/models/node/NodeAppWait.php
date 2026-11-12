<?php

namespace models\node;

class NodeAppWait extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'wait';
    public static $label = 'Wait';
    public static $icon = 'bi-stopwatch';
    public static $color = 'var(--wf-color-app)';
    //the step picker: in Flow
    public static $category = 'flow';
    public static $inputs = 1;
    public static $outputs = 1;

    //model field definitions with rules (WfNode::configForm(), a sys\NestedModel)
    public static function configSchema() {
	return [
	    [
		'key' => 'amount',
		'type' => 'number',
		'label' => 'Wait for',
		'required' => true,
		'default' => 1,
		//required again (notEmpty accepts 0); the range rule skips the empty value (one message)
		'validate' => [ [ 'inRange', 'lower' => 1, 'skipEmpty' => true, 'message' => 'must be 1 or more' ] ],
		//min / max from the validate rule (util\WidgetsUtil)
		'ui' => [ 'step' => 1, 'width' => 6 ],
	    ],
	    [
		'key' => 'unit',
		'type' => 'select',
		'label' => 'Units',
		'required' => true,
		'default' => 'minutes',
		'choices' => \data\Constants::TIME_UNITS_ALL,
		'ui' => [ 'width' => 6 ],
	    ],
	];
    }

    //first visit sets resume_at = now + amount x unit and sleeps; continues once the time has come
    //(the execution sleeps meanwhile: its wake_at tells a scheduler when to run it again)

    public function execute($edgeId = null) {
	$now = time();

	if (!($this->resume_at instanceof \MongoDB\BSON\UTCDateTime)) {
	    $amount = $this->cfg('amount');
	    $unit = (string)$this->cfg('unit', 'minutes');
	    if (!is_numeric($amount) || $amount < 1 || !isset(\data\Constants::TIME_UNIT_SECONDS_ALL[$unit]))
		throw new \ErrorException('Wait: invalid amount or unit');

	    $seconds = (int)round($amount * \data\Constants::TIME_UNIT_SECONDS_ALL[$unit]);
	    $this->resume_at = new \MongoDB\BSON\UTCDateTime(($now + $seconds) * 1000);
	    //CLD: 2026-11-12T12:00:00+02:00: with the time it continues and, as below, when it did: the log tells a planned sleep from a stuck one
	    $this->exec->log($this->wfid, "wait {$amount} {$unit}, until " . \util\Format::datetime($this->resume_at));
	}

	$due = $this->resume_at->toDateTime()->getTimestamp();
	if ($now < $due)
	    return self::SLEEP;

	$this->resume_at = null;
	$this->exec->log($this->wfid, 'waited, continues' . ($now - $due > 0 ? ' (' . ($now - $due) . ' s late)' : ''));
	return [ 0 ];
    }

}
