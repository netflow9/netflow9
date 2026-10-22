<?php

/*
    the forms made with the form designer (public_html/js/app.formdesigner.js)
	- app.smartform schemas { direction, fields } used by the Form trigger and Human task steps:

	normalize($form)
	    the form as stored: known node types and properties only, `showIf` made from each node's `condition` (any other showIf / hideIf /
                            requiredIf dropped: they run as code in the browsers of the people filling the form)
	errors($form)             what is wrong with it (for saving a step): [ message ]
	answers($form, $data)     a submission checked as the form says: [ answers (cast, only the form's keys, hidden values from the form), [ key => message ] ];
                            fields hidden by their condition are not required and give null
	type($form)               the answers' type description (util\Expression: suggestions after `form.`)
*/

namespace util;

class SmartForm {
    const INPUTS = [ 'text', 'email', 'url', 'textarea', 'number', 'date', 'datetime', 'select', 'checkbox', 'switch', 'hidden' ];
    const DESIGN = [ 'title', 'paragraph', 'divider', 'columns' ];
    const MAX_NODES = 200;
    const MAX_CHOICES = 200;
    const MAX_TEXT = 100000;

    //the properties kept per node type (besides type, width, showIf)
    const PROPS = [
	//CLD: 2026-10-14T19:54:21+03:00: text without inputType; + url
	'text' => [ 'key', 'label', 'required', 'helpText', 'placeholder', 'maxLength', 'pattern', 'patternMessage' ],
	'email' => [ 'key', 'label', 'required', 'helpText', 'placeholder' ],
	'url' => [ 'key', 'label', 'required', 'helpText', 'placeholder' ],
	'textarea' => [ 'key', 'label', 'required', 'helpText', 'placeholder', 'rows' ],
	'number' => [ 'key', 'label', 'required', 'helpText', 'placeholder', 'min', 'max', 'step' ],
	'date' => [ 'key', 'label', 'required', 'helpText' ],
	'datetime' => [ 'key', 'label', 'required', 'helpText' ],
	'select' => [ 'key', 'label', 'required', 'helpText', 'choices', 'multiple' ],
	'checkbox' => [ 'key', 'label', 'required', 'helpText' ],
	'switch' => [ 'key', 'label', 'required', 'helpText' ],
	'hidden' => [ 'key', 'default' ],

	'title' => [ 'label', 'subtitle', 'level', 'divider' ],
	'paragraph' => [ 'text' ],
	'divider' => [],
	'columns' => [],
    ];

    // ------------------------------------------------------------------------------------
    //the stored form

    //CLD: 2026-10-18T14:20:00+03:00: $form as plain arrays (the MongoDB type map, json_decode(..., true)); removed plain(): it turned BSON documents / stdClass into arrays, which
    //never reach here
    public static function normalize($form) {
	$form = is_array($form) ? $form : [];
	$out = [ 'direction' => ($form['direction'] ?? '') === 'horizontal' ? 'horizontal' : 'vertical', 'fields' => [] ];
	$out['fields'] = self::normalizeList(is_array($form['fields'] ?? null) ? $form['fields'] : [], true);
	return $out;
    }

    private static function normalizeList(array $list, $top) {
	$out = [];
	foreach(array_values($list) as $n) {
	    if (!is_array($n) || !isset(self::PROPS[$n['type'] ?? '']) || (!$top && ($n['type'] ?? '') === 'columns'))
		continue;
	    $type = $n['type'];
	    $c = [ 'type' => $type ];
	    foreach(self::PROPS[$type] as $k) {
		if (!array_key_exists($k, $n) || $n[$k] === null || $n[$k] === '')
		    continue;
		$v = $n[$k];
		switch($k) {
		    case 'required':
		    case 'multiple':
		    case 'divider':
			if ($v === true || $v === 'true' || $v === 1 || $v === '1')
			    $c[$k] = true;
			break;
		    case 'maxLength': case 'rows':
			if (is_numeric($v) && (int)$v > 0)
			    $c[$k] = (int)$v;
			break;
		    case 'min': case 'max': case 'step':
			if (is_numeric($v))
			    $c[$k] = $v + 0;
			break;
		    //CLD: 2026-10-14T19:54:21+03:00: removed the inputType case (no INPUT_TYPES any more)
		    case 'level':
			if (in_array($v, [ 'h4', 'h5', 'h6' ], true))
			    $c[$k] = $v;
			break;
		    case 'choices':
			$choices = [];
			foreach(array_slice(is_array($v) ? array_values($v) : [], 0, self::MAX_CHOICES) as $ch) {
			    $value = is_array($ch) ? (string)($ch['value'] ?? '') : (is_scalar($ch) ? (string)$ch : '');
			    $label = is_array($ch) ? (string)($ch['label'] ?? $value) : $value;
			    if ($value !== '')
				$choices[] = [ 'value' => mb_substr($value, 0, 200), 'label' => mb_substr($label !== '' ? $label : $value, 0, 200) ];
			}
			$c[$k] = $choices;
			break;
		    default:
			if (is_scalar($v))
			    $c[$k] = mb_substr((string)$v, 0, $k === 'text' ? self::MAX_TEXT : 1000);
		}
	    }
	    if ($type === 'columns') {
		$c['columns'] = [];
		foreach(array_slice(is_array($n['columns'] ?? null) ? array_values($n['columns']) : [], 0, 3) as $col) {
		    $col = is_array($col) ? $col : [];
		    $c['columns'][] = [ 'width' => max(1, min(12, (int)($col['width'] ?? 6))), 'fields' => self::normalizeList(is_array($col['fields'] ?? null) ? $col['fields'] : [], false) ];
		}
	    }
	    if ($type !== 'hidden' && is_numeric($n['width'] ?? null) && (int)$n['width'] >= 1 && (int)$n['width'] < 12)
		$c['width'] = (int)$n['width'];
	    $cond = $n['showIf'] ?? null;
	    if (is_array($cond) && \util\Validator::isIdentifier((string)($cond['key'] ?? '')) && isset(\util\Conditions::operators()[$cond['operator'] ?? ''])) {
		$value = $cond['operator'] === 'in'
		    ? array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(is_scalar($v) ? (string)$v : '', 0, 1000), array_slice(is_array($cond['value'] ?? null) ? $cond['value'] : [], 0, self::MAX_CHOICES)), fn($v) => $v !== '')))
		    : mb_substr(is_scalar($cond['value'] ?? null) ? (string)$cond['value'] : '', 0, 1000);
		$c['showIf'] = [ 'key' => (string)$cond['key'], 'operator' => $cond['operator'], 'value' => $value ];
	    }
	    $out[] = $c;
	}
	return $out;
    }


    //every node: [ node ] (inside columns too)
    public static function nodes(array $form) {
	$out = [];
	foreach(($form['fields'] ?? []) as $n) {
	    $out[] = $n;
	    foreach(($n['type'] ?? '') === 'columns' ? ($n['columns'] ?? []) : [] as $col)
		foreach(($col['fields'] ?? []) as $child)
		    $out[] = $child;
	}
	return $out;
    }

    public static function inputs(array $form) {
	return array_values(array_filter(self::nodes($form), fn($n) => in_array($n['type'] ?? '', self::INPUTS, true)));
    }

    //what is wrong with a (normalized) form: [ message ]
    public static function errors(array $form) {
	$errors = [];
	$types = [];
	$nodes = self::nodes($form);
	if (count($nodes) > self::MAX_NODES)
	    $errors[] = 'at most ' . self::MAX_NODES . ' fields';
	$keys = [];
	foreach(self::inputs($form) as $n) {
	    $key = (string)($n['key'] ?? '');
	    $name = ($n['label'] ?? '') !== '' ? "\"{$n['label']}\"" : $n['type'];
	    if (!\util\Validator::isIdentifier($key))
		$errors[] = "{$name}: the key must be letters, digits or underscore, not starting with a digit";
	    elseif (isset($keys[$key]))
		$errors[] = "the key `{$key}` is used twice";
	    $keys[$key] = true;
	    $types[$key] = $n['type'];
	    if ($n['type'] === 'select' && empty($n['choices']))
		$errors[] = "{$name}: add at least one choice";
	    if (($n['pattern'] ?? '') !== '' && @preg_match(self::regex($n['pattern']), '') === false)
		$errors[] = "{$name}: the pattern is not a valid regular expression";
	}
	//CLD: 2026-10-14T20:17:14+03:00: the condition is the node's showIf; its operator must be one of the field it reads (util\Conditions::operators of its type)
	foreach($nodes as $n) {
	    if (!isset($n['showIf']))
		continue;
	    $name = '"' . ($n['label'] ?? $n['type']) . '"';
	    if (!isset($keys[$n['showIf']['key']]))
		$errors[] = "{$name}: its condition uses `{$n['showIf']['key']}`, which is not in the form";
	    elseif (!isset(\util\Conditions::operators($types[$n['showIf']['key']])[$n['showIf']['operator']]))
		$errors[] = "{$name}: its condition's operator `{$n['showIf']['operator']}` does not apply to `{$n['showIf']['key']}`";
	}
	return array_values(array_unique($errors));
    }

    // ------------------------------------------------------------------------------------
    //answers

    //[ answers, [ key => message ] ]: $data checked against the (normalized) form
    //CLD: 2026-10-18T14:00:00+03:00: checked and cast by a sys\NestedModel of the visible fields (fieldDef: the form's rules as field definitions; was checked by hand in value()):
    //validated as given (a select's unknown choice is refused, not dropped by the cast), then cast; the first message of a field is its error
    public static function answers(array $form, array $data) {
	$inputs = self::inputs($form);
	//the raw values first (conditions read them), then visibility, then the visible fields
	$raw = [];
	foreach($inputs as $n)
	    $raw[$n['key']] = $n['type'] === 'hidden' ? (string)($n['default'] ?? '') : self::raw($n, $data[$n['key']] ?? null);
	$answers = [];
	$fields = [];
	foreach($inputs as $n) {
	    $answers[$n['key']] = null;
	    if ($n['type'] === 'hidden')
		$answers[$n['key']] = $raw[$n['key']];
	    elseif (self::visible($n, $raw, $form))
		$fields[] = self::fieldDef($n);
	}
	$values = array_intersect_key($raw, array_flip(array_column($fields, 'key')));

	$errors = [];
	$check = \sys\NestedModel::forFields($fields, $values, [], false);
	if (!$check->validates()) {
	    foreach($check->errors() as $key => $messages)
		$errors[$key] = (string)(((array)$messages)[0] ?? '');
	}
	$cast = \sys\NestedModel::forFields($fields, $values);
	foreach($values as $key => $value)
	    $answers[$key] = isset($errors[$key]) ? $value : ($cast->$key === '' ? null : $cast->$key);
	return [ $answers, $errors ];
    }

    //a node is shown when its condition holds and the node its condition reads is shown
    //CLD: 2026-10-14T20:17:14+03:00: the condition is the node's showIf, tested by util\Conditions::holds (was compared here)
    private static function visible(array $n, array $raw, array $form, $depth = 0) {
	if (empty($n['showIf']) || $depth > 20)
	    return true;
	$c = $n['showIf'];
	$on = null;
	foreach(self::inputs($form) as $i) {
	    if ($i['key'] === $c['key'])
		$on = $i;
	}
	if ($on === null || !self::visible($on, $raw, $form, $depth + 1))
	    return false;
	return \util\Conditions::holds($raw[$c['key']] ?? null, $c['operator'], $c['value']);
    }

    //CLD: 2026-10-18T14:00:00+03:00: the submitted value as the form's inputs send it (was part of value()): a checkbox / switch true or false, a multiple select a list of texts,
    //a single select its text, other inputs their text trimmed (a date and time up to the minutes); anything else is blank
    private static function raw(array $n, $v) {
	switch($n['type']) {
	    case 'checkbox':
	    case 'switch':
		return $v === true || $v === 'true' || $v === 1 || $v === '1' || $v === 'on';
	    case 'select':
		if (!empty($n['multiple']))
		    return array_values(array_filter(array_map(fn($x) => is_scalar($x) ? (string)$x : '', is_array($v) ? $v : ($v === null || $v === '' ? [] : [ $v ])), fn($x) => $x !== ''));
		return is_scalar($v) ? (string)$v : '';
	}
	$s = is_scalar($v) ? trim((string)$v) : '';
	return $n['type'] === 'datetime' ? substr($s, 0, 16) : $s;
    }

    //CLD: 2026-10-18T14:00:00+03:00: the field definition of an input (sys\fields\Field_<type>, its rules in `validate`; was checked by hand in value()): required, the choices,
    //min / max, the date format the pickers send, length, e-mail, url, pattern. Its own rules run before the type's (Field_number, Field_email, ...), with the form's messages
    private static function fieldDef(array $n) {
	$skip = [ 'skipEmpty' => true ];
	$f = [ 'key' => $n['key'], 'type' => $n['type'], 'label' => (string)($n['label'] ?? $n['key']), 'required' => !empty($n['required']), 'validate' => [] ];
	switch($n['type']) {
	    //required: checked
	    case 'checkbox':
	    case 'switch':
		$f['required'] = false;
		if (!empty($n['required']))
		    $f['validate'][] = [ 'notEmpty', 'message' => 'This must be checked.' ];
		return $f;
	    case 'select':
		$f['multiple'] = !empty($n['multiple']);
		$f['choices'] = [];
		foreach(($n['choices'] ?? []) as $c)
		    $f['choices'][(string)$c['value']] = (string)($c['label'] ?? $c['value']);
		return $f;
	    case 'number':
		$f['validate'][] = [ 'numeric', 'message' => 'Enter a number.' ] + $skip;
		if (isset($n['min']))
		    $f['validate'][] = [ 'inRange', 'lower' => $n['min'], 'message' => "At least {$n['min']}." ] + $skip;
		if (isset($n['max']))
		    $f['validate'][] = [ 'inRange', 'upper' => $n['max'], 'message' => "At most {$n['max']}." ] + $skip;
		return $f;
	    //the format the date pickers send (then cast by Field_date / Field_datetime, in the app's time zone)
	    case 'date':
		$f['validate'][] = [ 'dateFormat', 'format' => 'Y-m-d', 'message' => 'Enter a date (YYYY-MM-DD).' ] + $skip;
		return $f;
	    case 'datetime':
		$f['validate'][] = [ 'dateFormat', 'format' => 'Y-m-d\TH:i', 'message' => 'Enter a date and time.' ] + $skip;
		return $f;
	}
	//text, email, url, textarea
	$f['validate'][] = [ 'lengthBetween', 'min' => 0, 'max' => (int)($n['maxLength'] ?? self::MAX_TEXT), 'message' => 'Too long.' ] + $skip;
	if ($n['type'] === 'email')
	    $f['validate'][] = [ 'email', 'message' => 'Enter an e-mail address.' ] + $skip;
	if ($n['type'] === 'url')
	    $f['validate'][] = [ 'url', 'message' => 'Enter a web address.' ] + $skip;
	//anchored, as the HTML pattern attribute
	if (($n['pattern'] ?? '') !== '')
	    $f['validate'][] = [ 'regex', 'pattern' => '^(?:' . $n['pattern'] . ')$', 'message' => ($n['patternMessage'] ?? '') !== '' ? $n['patternMessage'] : 'The value does not match the expected format.' ] + $skip;
	return $f;
    }

    //a browser pattern (anchored, as the HTML pattern attribute) as a PHP regex
    //CLD: 2026-10-14T14:54:31+03:00: built as the Validator's `regex` rule builds it (value() runs that rule; was another delimiter)
    private static function regex($pattern) {
	return '/' . str_replace('/', '\/', '^(?:' . (string)$pattern . ')$') . '/u';
    }

    //the answers' type description: { key: type } (number, boolean, array for several choices, else string)
    public static function type(array $form, $info = 'the answers') {
	$fields = [];
	foreach(self::inputs($form) as $n) {
	    //CLD: 2026-10-20T21:40:00+03:00: + date: a date answer is a J Date in expressions (util\Expression::hostValue; was ISO 8601 text, type string)
	    $type = match($n['type']) { 'number' => 'number', 'checkbox', 'switch' => 'boolean', 'select' => !empty($n['multiple']) ? 'array' : 'string', 'date', 'datetime' => 'date', default => 'string' };
	    $fields[$n['key']] = [ 'type' => $type, 'info' => trim((string)($n['label'] ?? '')) !== '' ? (string)$n['label'] : $n['type'] ] + ($type === 'array' ? [ 'items' => [ 'type' => 'string' ] ] : []);
	}
	return [ 'type' => 'object', 'info' => $info, 'fields' => $fields ];
    }


    // ------------------------------------------------------------------------------------
    //forms of models

    public static function modelFields($model, $options = []) {
	$fields = [];
	foreach($model->schema as $key => $f) {
	    if (!$f->showInForms)
		continue;
	    //CLD: 2026-10-10T09:14:41+03:00: an updatable encrypted field is a write-only input (its value is never sent: formValues)
	    $writeOnly = !empty($f) && !empty($f->encrypt) && $f->{'perm:update'} !== 'nobody';
	    if (empty($f) || ($f->{'perm:read'} === 'nobody' && !$writeOnly))
		continue;

	    $type = $f->type;
	    $sf = [ 'key' => $f->key, 'label' => (string)$f->label ];
	    //CLD: 2026-10-10T18:27:49+03:00: app.smartform `password` field (show/hide button, no autofill; was text with inputType password); also for any write-only
	    //encrypted field (its value is never shown)
	    //CLD: 2026-10-20T16:00:00+03:00: an encrypted textarea stays a (write-only) textarea: multi-line secrets, e.g. PEM / PGP keys, keep their lines (a password input drops them)
	    if ($type === 'textarea' && $writeOnly) {
		$sf['type'] = 'textarea';
		$sf['spellcheck'] = false;
	    } elseif ($type === 'password' || $writeOnly) {
		$sf['type'] = 'password';
	    } else {
		//CLD: 2026-10-13T20:43:19+03:00: + email (app.smartform EmailField: an e-mail input checked in the browser; was a text input)
		//CLD: 2026-10-14T14:54:31+03:00: the app.smartform input types are util\FormSchema::INPUTS (were listed again here)
		$sf['type'] = in_array($type, self::INPUTS, true) ? $type : 'text';
	    }
	    //CLD: 2026-10-14T14:38:46+03:00: the date picker's display format (app.format.date / app.format.datetime)
	    if ($type === 'date' || $type === 'datetime')
		$sf['format'] = (string)\V::app()->config($f->format == 'date' ? 'app.format.date' : 'app.format.datetime');
	    //CLD: 2026-10-14T14:38:46+03:00: an object field: a select of the target's objects, value = id
	    if ($type === 'object') {
		$sf['type'] = 'select';
		$sf['choices'] = [];
		if (empty($f->choices) && $f->objectCount() > \sys\fields\Field_object::CHOICES_MAX) {
		    $value = (string)($model->$key ?? '');
		    $current = $value !== '' && \V::app()->om->getObjectDef($f->object) ? \V::app()->om->findOne($f->object, [ '_id' => $value, 'aid' => \V::app()->config('rt.account')->_id ?? null ]) : null;
		    if ($current)
			$sf['choices'][] = [ 'value' => $value, 'label' => (string)$current ];
		    if (is_callable($options['objectSearchUrl'] ?? null))
			$sf['ajaxUrl'] = $options['objectSearchUrl']($f);
		} else {
		    foreach($f->objectChoices() as $value => $label)
			$sf['choices'][] = [ 'value' => (string)$value, 'label' => (string)$label ];
		}
	    }
	    if (!empty($f->required))
		$sf['required'] = true;
	    //CLD: 2026-10-14T15:30:03+03:00: from the field's `requiredIf` property (was read from its requiredIf rule)
	    if (!empty($f->requiredIf['if']))
		//CLD: 2026-10-14T20:17:14+03:00: a condition array (app.smartform evaluates it; was JS made by showIf())
		$sf['requiredIf'] = [ 'key' => (string)$f->requiredIf['if'], 'operator' => 'in', 'value' => (array)($f->requiredIf['in'] ?? []) ];
	    //CLD: 2026-10-14T14:18:23+03:00: the model's `requiredIf` rule is the form's requiredIf (util\FormSchema::showIf): the browser asks what the server checks (was written
	    //again in each field's `ui`, as `required` or a hand-made requiredIf)
	    //CLD: 2026-10-14T14:27:53+03:00: likewise `identifier` / `regex` -> pattern + patternMessage, `inRange` -> min / max (were written again in `ui`)
	    foreach((array)($f->validate ?? []) as $rule) {
		if (!is_array($rule))
		    continue;
		switch($rule[0] ?? null) {
		    case 'identifier':
			$sf['pattern'] = '^[A-Za-z_][A-Za-z0-9_]*$';
			$sf['patternMessage'] = (string)($rule['message'] ?? '');
			break;
		    case 'regex':
			$sf['pattern'] = (string)($rule['pattern'] ?? '');
			$sf['patternMessage'] = (string)($rule['message'] ?? '');
			break;
		    case 'inRange':
			if (isset($rule['lower']))
			    $sf['min'] = $rule['lower'];
			if (isset($rule['upper']))
			    $sf['max'] = $rule['upper'];
			break;
		    //CLD: 2026-10-20T14:07:31+03:00: the key / certificate rules (util\Validator) -> `rules`, checked in the browser in their order by app.smartform (its structure checks;
		    //OpenSSL's stay on the server); `when` = { field: [ values ] }
		    case 'pgpPublicKey':
		    case 'pgpPrivateKey':
		    case 'pemCertificate':
		    case 'pemPrivateKey':
		    case 'pemPublicKey':
		    case 'sshPrivateKey':
		    case 'notPrivateKey':
		    case 'notPublicKey':
			$sf['rules'][] = [
			    'rule' => $rule[0],
			    'when' => (object)($rule['when'] ?? []),
			    'message' => (string)($rule['message'] ?? ''),
			    'multiple' => $rule['multiple'] ?? null,
			];
			break;
		}
	    }
	    if (isset($f->default))
		$sf['default'] = $f->default;
	    if ($type === 'select') {
		$sf['choices'] = [];
		foreach($f->choicesToArray() as $value => $label)
		    $sf['choices'][] = [ 'value' => (string)$value, 'label' => (string)$label ];
		//CLD: 2026-10-14T13:56:48+03:00: a `multiple` select field is a multiple select in the form (was set only through `ui`)
		if (!empty($f->multiple))
		    $sf['multiple'] = true;
	    }
	    //CLD: 2026-10-18T10:20:00+03:00: a nested field (sys\fields\Field_nested): its fields are the components (app.smartform `editgrid`, a row each, when multiple; `nested`,
	    //one object, else); a required list: at least one row (unless ui says otherwise)
	    if ($type === 'nested') {
		$sf['type'] = $f->multiple ? 'editgrid' : 'nested';
		$sf['components'] = self::modelFields($f->nestedObject(), $options);
		if ($f->multiple && !empty($f->required))
		    $sf['minRows'] = 1;
	    }
	    if ($f->{'perm:update'} === 'nobody')
		$sf['editable'] = false;
	    if (is_string($f->help) && $f->help !== '')
		$sf['helpText'] = $f->help;
	    if (is_array($f->ui))
		$sf = array_merge($sf, $f->ui);
	    //CLD: 2026-10-14T20:17:14+03:00: the form designer offers, for a condition, the operators of the field it reads: [ type => [ { value, label } ] ] of the input types
	    if (($sf['type'] ?? '') === 'formdesigner') {
		$sf['operators'] = [];
		foreach(self::INPUTS as $t)
		    foreach(\util\Conditions::operators($t) as $op => $label)
			$sf['operators'][$t][] = [ 'value' => $op, 'label' => $label ];
	    }
	    //CLD: 2026-10-10T09:14:41+03:00: a stored encrypted value is kept when its input stays empty (BaseModel::assign skips it): not required then (was Credential::formFields)
	    //CLD: 2026-10-10T18:27:49+03:00: `stored` makes the password field say so (its placeholder; was appended to the help text)
	    if ($writeOnly && !in_array($model->$key, [ null, '' ], true)) {
		unset($sf['required'], $sf['requiredIf']);
		$sf['stored'] = true;
	    }
	    $fields[] = $sf;
	}
	return $fields;
    }

    public static function modelValues($model) {
	$values = [];
	foreach($model->schema as $key => $f) {
	    if (!$f->showInForms)
		continue;
	    $value = $model->$key ?? $f->default ?? '';
	    //CLD: 2026-10-14T14:38:46+03:00: values as the inputs expect them (were converted in views/datatable/row_form.php): checkbox / switch -> boolean, date / datetime -> local
	    //Y-m-d / Y-m-d\TH:i (stored as UTC; Field_datetime::cast parses the posted one in the app timezone), object -> the id as text
	    switch($f->type) {
		case 'checkbox':
		case 'switch':
		    $value = !empty($value);
		    break;
		case 'date':
		case 'datetime':
		    $value = $f->display([ 'format' => $f->format == 'date' ? 'Y-m-d' : 'Y-m-d\TH:i' ]);
		    break;
		case 'object':
		    $value = empty($value) ? '' : (string)$value;
		    break;
	    }
	    $values[$key] = !empty($f->encrypt) ? '' : $value;
	}
	return $values;
    }
}
