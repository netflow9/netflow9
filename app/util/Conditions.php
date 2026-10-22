<?php

/*
    conditions { key, operator, value }, all or any of them ($match).
    The operators are the fields' (sys\fields\Field::$ops['eval']:
	eq ne gt gte lt lte contains startsWith in empty notEmpty;
	a field type allows some of them, the base field all).
	One meaning everywhere:
	    operators($type) [ operator => label ] of field type $type (null: the base field, every operator)
	    holds($left, $operator, $right) one comparison: numbers when both sides are numeric, else text; contains / startsWith ignore case; a list on the left:
		contains = one of its items, in = one of its items in the values, empty = no items
	    test($conditions, $match, $values)        evaluates them against an object / a set of variables ($values: array, or a model: its data())
	    query($conditions, $match, $field) the MongoDB query of them; $field($key) gives the sys\fields\Field of a key (casts the values, its operators), null: unknown
		`in` takes a list, or comma-separated text. Used by the data grid filter, the Data Table step, MCP query_table, If / Switch and Forms (util\SmartForm)
*/
namespace util;

class Conditions {

    public static function operators($type = null) {
	$class = $type ? 'sys\\fields\\Field_' . $type : null;
	$field = $class && class_exists($class) ? \sys\fields\Field::create([ 'type' => $type ]) : new \sys\fields\Field([]);
	return (array)($field->ops['eval'] ?? []);
    }

    //a value that counts as empty: missing, '', false, no items, only whitespace
    private static function isEmpty($v) {
	if (is_array($v))
	    return count($v) == 0;
	return $v === null || $v === false || trim(\util\Expression::toText($v)) === '';
    }

    //the values of `in`: a list, or comma-separated text
    private static function values($v) {
	if (is_array($v))
	    return array_values(array_map([ \util\Expression::class, 'toText' ], $v));
	return array_values(array_filter(array_map('trim', explode(',', \util\Expression::toText($v))), fn($s) => $s !== ''));
    }

    public static function holds($left, $operator, $right) {
	if ($operator === 'empty' || $operator === 'notEmpty')
	    return ($operator === 'empty') === self::isEmpty($left);
	if ($operator === 'in') {
	    $in = self::values($right);
	    if (is_array($left))
		return (bool)array_intersect(array_map([ \util\Expression::class, 'toText' ], $left), $in);
	    return in_array(\util\Expression::toText($left), $in, true);
	}
	$right = \util\Expression::toText($right);
	if (is_array($left) && array_is_list($left)) {
	    if ($operator === 'contains')
		return in_array(mb_strtolower($right), array_map(fn($x) => mb_strtolower(\util\Expression::toText($x)), $left), true);
	}
	$left = \util\Expression::toText($left);
	$numeric = is_numeric($left) && is_numeric($right);
	$cmp = $numeric ? ((float)$left <=> (float)$right) : strcmp($left, $right);
	return match($operator) {
	    'eq' => $cmp == 0,
	    'ne' => $cmp != 0,
	    'gt' => $cmp > 0,
	    'gte' => $cmp >= 0,
	    'lt' => $cmp < 0,
	    'lte' => $cmp <= 0,
	    'contains' => $right !== '' && mb_stripos($left, $right) !== false,
	    'startsWith' => $right !== '' && mb_stripos($left, $right) === 0,
	    default => throw new \ErrorException("Conditions: unknown operator `{$operator}`"),
	};
    }

    public static function test(array $conditions, $match, $values) {
	if ($values instanceof \sys\BaseModel)
	    $values = $values->data();
	$any = $match === 'any';
	foreach($conditions as $c) {
	    $left = is_array($values) ? ($values[(string)($c['key'] ?? '')] ?? null) : null;
	    if (self::holds($left, (string)($c['operator'] ?? 'eq'), $c['value'] ?? '') === $any)
		return $any;
	}
	return !$any;
    }

    public static function query(array $conditions, $match, callable $field) {
	$parts = [];
	foreach($conditions as $c) {
	    $key = (string)($c['key'] ?? '');
	    $op = (string)($c['operator'] ?? 'eq');
	    $f = $field($key);
	    if (!$f)
		throw new \ErrorException("Conditions: unknown field `{$key}`");
	    if (!isset($f->ops['eval'][$op]))
		throw new \ErrorException("Conditions: `{$op}` is not an operator of `{$key}`");
	    $value = $c['value'] ?? '';
	    $text = preg_quote(\util\Expression::toText($value));
	    $parts[] = match($op) {
		'eq' => [ $key => $f->cast($value) ],
		'ne' => [ $key => [ '$ne' => $f->cast($value) ] ],
		'gt' => [ $key => [ '$gt' => $f->cast($value) ] ],
		'gte' => [ $key => [ '$gte' => $f->cast($value) ] ],
		'lt' => [ $key => [ '$lt' => $f->cast($value) ] ],
		'lte' => [ $key => [ '$lte' => $f->cast($value) ] ],
		'contains' => [ $key => new \MongoDB\BSON\Regex($text, 'i') ],
		'startsWith' => [ $key => new \MongoDB\BSON\Regex('^' . $text, 'i') ],
		'in' => [ $key => [ '$in' => array_map([ $f, 'cast' ], self::values($value)) ] ],
		'empty' => [ $key => [ '$in' => [ null, '', [] ] ] ],
		'notEmpty' => [ $key => [ '$nin' => [ null, '', [] ] ] ],
	    };
	}
	if (!$parts)
	    return [];
	return [ $match === 'any' ? '$or' : '$and' => $parts ];
    }
}
