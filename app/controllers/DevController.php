<?php

namespace controllers;


class AnObject extends \sys\BaseModel {
    protected static $collection = 'nothing';

    public function getSchema() {
	$schema = $this->getSystemSchema();

	$fields = [
	    //text
	    [
		'key' => 'field_text',
		'type' => 'text',
		'label' => 'Text',
		'help' => false,
	    ],
	    //textarea
	    [
		'key' => 'field_textarea',
		'type' => 'textarea',
		'label' => 'Textarea',
		'help' => false,
	    ],
	    //checkbox
	    [
		'key' => 'field_checkbox',
		'type' => 'checkbox',
		'label' => 'Checkbox',
		'help' => false,
    	    ],
	    //switch
	    [
		'key' => 'field_switch',
		'type' => 'switch',
		'label' => 'Switch',
		'help' => false,
	    ],
	    //select
	    [
		'key' => 'field_select',
		'type' => 'select',
		'label' => 'Select',
		'help' => false,
		'choices' => [
		    'one' => 'Choice one',
		    'two' => 'Choice two',
		    'three' => 'Choice three',
		],
	    ],
	    //datetime
	    [
		'key' => 'field_datetime',
		'type' => 'datetime',
		'label' => 'Datetime',
		'help' => false,
		'format' => 'datetime',
	    ],
	    //date
	    [
		'key' => 'field_date',
		'type' => 'datetime',
		'label' => 'Date',
		'help' => false,
		'format' => 'date',
	    ],
	    //icon
	    [
		'key' => 'field_icon',
		'type' => 'icon',
		'label' => 'Icon',
		'help' => false,
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }
}

class DevController extends \sys\BaseController {

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function info($req, $resp) {
	$account = \V::app()->config('rt.account');
	$model = AnObject::create([ 'aid' => $account->_id ]);
	if ($req->is('post'))
	    $model->assign($req->data);

	$this->render('info', compact('model'));
    }

    public function grid() {
	$this->render('grid');
    }


    public function form() {
	$this->render('form');
    }







}