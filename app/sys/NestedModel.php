<?php

/*
    one object held by a `nested` field (sys\fields\Field_nested; never saved by itself):
    its schema is the field's `fields` (model field definitions), so its values are cast by the Field_* classes and checked by validates(), like a model's

    $o = \sys\NestedModel::forFields($fields, $data);      //defaults bound, $data assigned (cast; unknown keys dropped)
    $o->validates();
    \sys\NestedModel::forFields($fields, $data, [], false)->validates();     //$data as it is (not cast): validates what is stored

*/
namespace sys;

class NestedModel extends BaseModel {
    //not an object of an account (no aid check)
    public function _init() {
	$this->_config['global'] = true;
	parent::_init();
    }

    //the nested field's definitions (passed as the `nestedSchema` option); no system fields (created_at, updated_at: kept by the model holding it)
    public function getSchema() {
	return $this->_config['nestedSchema'] ?? [];
    }

    //an object of field definitions $fields holding $data: assigned (cast), or as it is ($cast false)
    public static function forFields(array $fields, $data = [], $options = [], $cast = true) {
	$data = is_array($data) ? $data : [];
	$o = static::create($cast ? [] : $data, [ 'nestedSchema' => $fields, 'objectName' => 'NestedModel' ] + $options);
	if ($cast) {
	    $o->assign($data);
	} else {
	    //CLD: 2026-10-18T10:20:00+03:00: the values given stay as they are (binding the schema set a field's default over a given null: e.g. an
	    //operator cast to null, not among the choices, was validated as the default `eq`); missing keys keep their defaults
	    foreach($data as $key => $value)
		$o->loadValue($key, $value);
	}
	return $o;
    }
}
