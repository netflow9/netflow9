<?php
$actionUrl = \V::app()->createUrl('', [ 'id' => $wf->_id ]);
$form_begin = \V::app()->form->create($wf, [ 'action' => $actionUrl ]);

$id = 'f-' . uniqid();

$body = "<div id='{$id}'></div>";

$form_end = \V::app()->form->end();

$footer = "
<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
<button type='submit' class='btn btn-primary'>Update</button>
";

$modal = new \sys\html\Modal([
    'title' => 'Variables',
    'body' => $body,
    'footer' => $footer,
    'beforeContent' => $form_begin,
    'afterContent' => $form_end,
    'size' => 'small',
]);
echo $modal->render();

$schema = [
    'fields' => [
	[
	    'type' => 'title',
	    'label' => 'ttttt',
	    'level' => 'h4',
	],
	[
	    'type' => 'editgrid',
	    'key' => 'contacts',
	    'label' => 'Emergency contacts',
	    'addLabel' => '+ Add contact',
	    'minRows' => 0,
	    'maxRows' => 5,
	    'allowDeleteReadOnlyRows' => false,
	    'default' => [
		[ 'name' => 'Grace Hopper', 'relation' => 'Colleague', 'phone' => '555-0101', '__readOnly' => true ],
	    ],
	    'components' => [
		[
		    'type' => 'text',
		    'key' => 'name',
		    'label' => 'Name',
		    'width' => 5,
		    'required' => true
		],
		[
		    'type' => 'select',
		    'key' => 'relation',
		    'label' => 'Relation',
		    'width' => 4,
		    'choices' =>  [
        		[ 'value' => 'us', 'label' => 'United States' ],
        		[ 'value' => 'ro', 'label' => 'Romania' ],
        		[ 'value' => 'de', 'label' => 'Germany' ],
        		[ 'value' => 'fr', 'label' => 'France' ],
    		    ]
		],
		[
		    'type' => 'text',
		    'key' => 'phone',
		    'label' => 'Phone',
		    'width' => 3
		]
	    ]
	],
    ],
];
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
$schema = \util\JSON::encode($schema);
$script = "
+function() {
    const container = document.getElementById('{$id}');
    let f = app.smartform.mount(container, $schema);
    f.render();
}()";
\V::app()->cs->addPageScript($script);
