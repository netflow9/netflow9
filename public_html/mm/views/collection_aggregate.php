<script language="javascript">
    $(document).off('click', '.btn-add-stage').on('click', '.btn-add-stage', function(e) {
	e.preventDefault();
	const row = $(this).closest('div.stage');
	row.clone().insertAfter(row);
	//$('[name^=stage_data]').ace();
    });

    $(document).off('click', '.btn-remove-stage').on('click', '.btn-remove-stage', function(e) {
	e.preventDefault();
	const fields = $(this).closest('form').find('.stage');
	if (fields.length == 1)
	    return;
	$(this).closest('div.stage').remove();
    });
    //$('[name^=stage_data]').ace();
</script>

<?= R::app()->view->renderFile('collection_nav', [ 'tab' => 'aggregate', 'db' => $db, 'collection' => $collection ]) ?>

<!-- Query box -->
<form method="post" action="<?= R::app()->url('app.collection_aggregate', [ 'db' => $db, 'collection' => $collection ]) ?>">
    <div class="row mx-1" style="background-color: #eeefff">
	<div class="col">
	    <div class="m-1">Pipeline</div>

	    <?php foreach($pipeline as $op => $data): ?>
	    <div class="stage row mx-1 mb-1">
		    <?php
		    echo R::app()->html->field('stage_op[]', [
			'type' => 'select',
			'label' => false,
			'value' => $op,
			'input' => [ 'class' => 'form-control' ],
			'wrap' => [ 'class' => 'col-auto' ],
			'list' => array_combine($stage_ops, $stage_ops),
		    ]);

		    echo R::app()->html->field('stage_data[]', [
			'type' => 'textarea',
			'label' => false,
			'value' => $data,
			'wrap' => [ 'class' => 'col' ],
			'rows' => 5,
			'id' => 'data-' . uniqid(),
		    ]);
		    ?>
		    <div class="col-auto">
			<input type="button" value="+" class="btn btn-light mx-1 btn-add-stage">
			<input type="button" value="-" class="btn btn-light mx-1 btn-remove-stage">
		    </div>
	    </div>
	    <?php endforeach ?>

	    <?php
		if (!empty($error))
		    echo "<div class='alert alert-danger'>$error</div>";
	    ?>
	</div>
	<div class="col-auto">
	    <input type="submit" class="btn btn-sm btn-success mt-4" value="Go" pjax-submit>
	</div>
    </div>
</form>

<div class='row mx-1'>
    <div class='col-12'>
	<pre>
	<?php print_r($output ?? null) ?>
	</pre> 
    </div>
</div>


