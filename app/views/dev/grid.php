<?php
$this->pageTitle = 'User::table';
?>
<!-- header -->
<div class="d-flex align-items-center w-100 align-items-top">
    <div>
	<h5>User list</h5>
	<span class="text-muted">All users</span>
    </div>
    <div class="flex-fill"></div>
</div>
<?php


$account = \V::app()->config('rt.account');
$wDef = [
    'objectName' => 'User',
    'dataProvider' => \V::app()->om->find('User', [ 'aid' => $account->_id ]),

    'sort' => [ 'created_at' => 'asc' ],

    'columns' => [
	[
	    'field' => '_id',
	],
	[
	    'field' => 'firstname',
	    //'header' => 'State',
	    //'content' => function($model, $column) { return \models\Campaign::getState($model->state); },
	    //'filter' => true,
	    //'width' => '100px',
	],
	[
	    'field' => 'lastname',
	    //'content' => function($model, $column) { return $model->getStats()['total']; },
	    //'header' => 'Submitted',
	    //'filter' => true,
	],
	[
	    'field' => 'email',
	    //'content' => function($model, $column) { return $model->getStats()['processed'] . ' (' . $model->getStats()['processed_pct'] . '%)'; },
	    //'header' => 'Processed',
	    //'sort' => true,
	],
	[
	    'field' => 'created_at',
	    //'content' => function ($model, $column) { return date('Y-m-d H:i:s', $model->c_ts->sec); },
	    //'header' => 'createdAt',
	],
	[
	    'field' => 'updated_at',
	    //'content' => function ($model, $column) { return date('Y-m-d H:i:s', $model->c_ts->sec); },
	    //'header' => 'updatedAt',
	],

/*
	[
	    'class' => 'widget\ActionColumn',
	    'width' => '25px',
	    'header' => '*',
	    'buttons' => array(
		'send' => function ($url, $model, $column) {
		    if (!$model->canSend())
			return '';
        	    $title = \V::t('Send');
            	    $options = array(
                	'aria-label' => $title,
                	'data-confirm' => \V::t('Are you sure you want to send this campaign?'),
                	'data-method' => 'post',
                	'data-id' => (string)$model->key(),
                	//'data-pjax' => '0',
            	    );
		    $options = \util\Set::merge($options, $column->buttonOptions);
        	    return \widget\Widget::html()->tag('link', array('title' => '<i class="fa fa-play"></i>', 'url' => $url, 'options' => $options), $options + ['escape' => false]);
        	},
		'cancel' => function ($url, $model, $column) {
		    if (!$model->canCancel())
			return '';
        	    $title = \V::t('Cancel');
            	    $options = array(
                	'aria-label' => $title,
                	'data-confirm' => \V::t('Are you sure you want to cancel this campaign?'),
                	'data-method' => 'post',
                	'data-id' => (string)$model->key(),
                	//'data-pjax' => '0',
            	    );
		    $options = \util\Set::merge($options, $column->buttonOptions);
        	    return \widget\Widget::html()->tag('link', array('title' => '<i class="fa fa-stop"></i>', 'url' => $url, 'options' => $options), $options + ['escape' => false]);
        	},
	    ),
	    'template' => '{send} {cancel}',
	],
*/
    ],
];

echo (new \sys\widgets\GridView($wDef))->render();
