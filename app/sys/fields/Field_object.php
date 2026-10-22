<?php

namespace sys\fields;

class Field_object extends Field {
    public $icon = 'bi bi-box';
    public $name = 'Object';
    public $ops = [
	//CLD: 2026-10-14T20:17:14+03:00: the conditions' operators (util\Conditions): one vocabulary for every type, labels per type; was [ 'is' => 'Is', 'in' => 'In', ]
	'eval' => [ 'eq' => 'Is', 'ne' => 'Is not', 'in' => 'Is one of', 'empty' => 'Is empty', 'notEmpty' => 'Is not empty', ],
	'set' => [ 'set' => 'Set' ],
    ];

    public function _init() {
	parent::_init();

	if (empty($this->_data['data:multiple']))
	    $this->_data['data:multiple'] = false;
    }

    public function cast($value) {
	return $this->__cast_oid($value, $this->{'data:multiple'});
    }

    //CLD: 2026-10-05T21:59:39+03:00: [ id => label ] of the objects this field can point to: `choices` if given, else all `object` objects of the account
    public function objectChoices() {
	$choices = $this->choices;
	if (is_callable($choices))
	    $choices = $choices();
	if (!empty($choices) && is_array($choices))
	    return $choices;

	$choices = [];
	if (empty($this->object) || empty(\V::app()->om->getObjectDef($this->object)))
	    return $choices;

	$account = \V::app()->config('rt.account');
	foreach(\V::app()->om->find($this->object, [ 'aid' => $account->_id ]) as $o)
	    $choices[(string)$o->_id] = (string)$o;
	return $choices;
    }

    //CLD: 2026-10-11T22:16:09+03:00: a form lists all target objects up to this many; with more, it searches them on the server (searchChoices)
    const CHOICES_MAX = 100;

    //CLD: 2026-10-11T22:16:09+03:00: how many objects of the account this field can point to (no `choices` given)
    public function objectCount() {
	if (empty($this->object) || empty(\V::app()->om->getObjectDef($this->object)))
	    return 0;
	return \V::app()->om->count($this->object, [ 'aid' => \V::app()->config('rt.account')->_id ]);
    }

    //CLD: 2026-10-11T22:16:09+03:00: one page of the objects this field can point to whose text fields contain $term (case-insensitive; an id finds that object), ordered by
    //name when the target has one: [ [ [ id, text ], ... ], more ]. For a select that searches on the server (app.SelectPlus ajax)
    public function searchChoices($term, $page = 1, $limit = 20) {
	if (empty($this->object) || empty(\V::app()->om->getObjectDef($this->object)))
	    return [ [], false ];

	$query = [ 'aid' => \V::app()->config('rt.account')->_id ];
	$term = trim((string)$term);
	$target = \V::app()->om->create($this->object, []);
	if ($term !== '') {
	    $or = [];
	    foreach($target->schema as $key => $f) {
		if (in_array($f->type, [ 'text', 'textarea', 'email', 'select' ], true) && empty($f->encrypt))
		    $or[] = [ $key => new \MongoDB\BSON\Regex(preg_quote($term), 'i') ];
	    }
	    if (\util\Validator::rule('oid', $term))
		$or[] = [ '_id' => new \MongoDB\BSON\ObjectId($term) ];
	    if (empty($or))
		return [ [], false ];
	    $query['$or'] = $or;
	}

	$page = max(1, (int)$page);
	$sort = isset($target->schema['name']) ? [ 'name' => 1, '_id' => 1 ] : [ '_id' => 1 ];
	$results = [];
	foreach(\V::app()->om->find($this->object, $query, [ 'sort' => $sort, 'skip' => ($page - 1) * $limit, 'limit' => $limit + 1 ]) as $o)
	    $results[] = [ 'id' => (string)$o->_id, 'text' => (string)$o ];
	$more = count($results) > $limit;
	return [ array_slice($results, 0, $limit), $more ];
    }

    //add data:object field
    public function getSchema() {
	$schema = parent::getSchema();

	//CLD: 2026-10-06T08:51:09+03:00: a select of the user-selectable objects (was a free text), as `choices` in Field_select
	$schema['object'] = [
	    'key' => 'object',
	    'help' => false,
	    'label' => 'Object',
	    'type' => 'select',
	    'choices' => \V::app()->om->getSelectableObjects(),
	    'prop:required' => true,
	    'perm:read' => 'everybody',
	    'perm:update' => 'everybody',
	];
	$schema['multiple'] = [
	    'key' => 'multiple',
	    'help' => false,
	    'label' => 'Multiple ?',
	    'type' => 'checkbox',
	    'perm:read' => 'everybody',
	    'perm:update' => 'everybody',
	];

	return $schema;
    }

    public function afterAttachSchema() {
	parent::afterAttachSchema();
	//CLD: 2026-10-10T19:00:56+03:00: perm:update (perm:edit is read by nothing)
	$this->schema['object']->{'perm:update'} = ($this->exists() ? 'nobody' : 'everybody');
	$this->schema['multiple']->{'perm:update'} = ($this->exists() ? 'nobody' : 'everybody');
    }

    public function __input(&$options = []) {
	$inputOptions = $options['input'] ?? [];
	if (!$this->checkAccess(\V::app()->user->_id, 'update')) {
	    $inputOptions['readonly'] = 'readonly';
	    $inputOptions['disabled'] = 'disabled';
	}

	$fieldId = ($options['id'] ?? "field-{$this->key}-" . uniqid());
	$inputOptions['id'] = $fieldId;
	$inputOptions['placeholder'] = 'Search...';
	$inputOptions['multiple'] = !empty($this->multiple) ? 'multiple' : null;

	//choices
	//CLD: 2026-10-05T21:59:39+03:00: shared with the row editor (objectChoices); labels also work for data table rows
	$choices = $this->objectChoices();

	//\V::app()->cs->addPageScript("new TomSelect('#{$fieldId}', {});");

	return \V::app()->form->field($this->key, [
	    'id' => $fieldId,
	    'type' => 'select',
	    'label' => $this->getLabel(),
	    'help' => $this->getHelp(),
	    'list' => $choices,
	    'value' => $this->binding ? $this->binding->data($this->key) : '',
	    'input' => $inputOptions,
	    'bare' => $options['bare'] ?? null,
	    'empty' => !$this->required,
	    'dir' => $options['dir'] ?? null,
	    'cols' => $options['cols'] ?? null,
	    'wrap_options' => $options['wrap_options'] ?? null,
	]);
    }

    public function __display(&$options = []) {
	if (!$this->checkAccess(\V::app()->user->_id, 'read'))
	    return '';

	if (empty($objectName = $this->{'object'}))
	    return '';

	$objDef = \V::app()->om->getObjectDef($objectName);
	if (empty($objDef))
	    return '';

	$value = $this->binding->data($this->key);

	if (!is_array($value))
	    $value = [ $value ];

	$out = [];
	//value is array of objects
	foreach($value as $oid) {
	    if (!empty($oid)) {
		if (!empty($o = \V::app()->om->findbyId($objectName, $oid))) {
		    //CLD: 2026-10-06T09:12:58+03:00: cast to string (objectLabel removed)
		    $out[] = (string)$o;
		} else {
		    $out[] = '???';
		}
	    }
	}
	return implode(',', $out);
    }

}