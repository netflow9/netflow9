<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'DEV - fields' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">

    <div class="p-4">
	<div class="d-flex gap-2 my-1">
	    <button type="button" class="btn btn-primary">Primary</button>
	    <button type="button" class="btn btn-secondary">Secondary</button>
	    <button type="button" class="btn btn-success">Success</button>
	    <button type="button" class="btn btn-danger">Danger</button>
	    <button type="button" class="btn btn-warning">Warning</button>
	    <button type="button" class="btn btn-info">Info</button>
	    <button type="button" class="btn btn-light">Light</button>
	    <button type="button" class="btn btn-dark">Dark</button>
	    <button type="button" class="btn btn-link">Link</button>
	</div>

	<div class="d-flex gap-2 my-1">
	    <button type="button" class="btn btn-outline-primary">Primary</button>
	    <button type="button" class="btn btn-outline-secondary">Secondary</button>
	    <button type="button" class="btn btn-outline-success">Success</button>
	    <button type="button" class="btn btn-outline-danger">Danger</button>
	    <button type="button" class="btn btn-outline-warning">Warning</button>
	    <button type="button" class="btn btn-outline-info">Info</button>
	    <button type="button" class="btn btn-outline-light">Light</button>
	    <button type="button" class="btn btn-outline-dark">Dark</button>
	</div>
<?php

echo \V::app()->form->create($model, [ 'class' => 'mt-4' ]);

$fields = [
    'field_text', 'field_textarea', 'field_checkbox', 'field_switch',
    'field_select', 'field_datetime', 'field_date', 'field_icon', 
];

foreach($fields as $f)
    echo $model->schema[$f]->input();

echo \V::app()->form->end();
?>


    </div>
</div>

