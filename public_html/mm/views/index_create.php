<script language="javascript">
    $(document).off('click', '.btn-add-field').on('click', '.btn-add-field', function(e) {
	e.preventDefault();
	const row = $(this).closest('div.field');
	row.clone().insertAfter(row);
    });

    $(document).off('click', '.btn-remove-field').on('click', '.btn-remove-field', function(e) {
	e.preventDefault();
	const fields = $(this).closest('form').find('.field');
	if (fields.length == 1)
	    return;
	$(this).closest('div.field').remove();
    });

    $('input[name=partial]').off('click').on('click', function(e) {
	if ($(this).is(':checked'))
	    $('textarea[name=partial_filter]').parents().first().removeClass('d-none');
	else
	    $('textarea[name=partial_filter]').parents().first().addClass('d-none');
    });

</script>



<!-- Navigation & Menu -->
<div class="row mx-1">
    <div class="col">
	<h5>
	    <?= $db . '.' . $collection ?> - create index
	</h5>
    </div>
    <div class="col-auto">
    </div>
</div>


<?php
    if (!empty($error))
	echo "<div class='alert alert-danger'>$error</div>";
?>

<form method="post" class="validate" novalidate>
    <div class="row mx-0">
	<div class="col-4">
	    <?php
	    echo R::app()->html->field('name', [
		'type' => 'text',
		'label' => 'Name',
		'value' => $name ?? '',
		'input' => [ 'class' => 'form-control' ],
	    ]);
	    ?>
	</div>
    </div>

    <div class="m-1">Fields <small>(use $** for wildcard index)</small></div>

    <?php foreach ($attrs as $field => $order): ?>
    <div class="field row mx-1 mb-1">
	<?php
	echo R::app()->html->field('field[]', [
	    'type' => 'text',
	    'label' => false,
	    'value' => $field,
	    'input' => [ 'class' => 'form-control' ],
	    'wrap' => [ 'class' => 'col-auto' ],
	]);

	echo R::app()->html->field('order[]', [
	    'type' => 'select',
	    'label' => false,
	    'value' => $order,
	    'list' => [ '1' => 'ASC', '-1' => 'DESC', 'text' => 'text', '2d' => '2d', '2dsphere' => '2dsphere', ],
	    'wrap' => [ 'class' => 'col-auto' ],
	]);
	?>
	<div class="col-auto">
	    <input type="button" value="+" class="btn btn-light mx-1 btn-add-field">
	    <input type="button" value="-" class="btn btn-light mx-1 btn-remove-field">
	</div>
    </div>
    <?php endforeach ?>

    <div class="m-1">Options</div>
    <div class="mx-1">
	<?php
	echo R::app()->html->field('unique', [
	    'type' => 'checkbox',
	    'label' => 'Is unique',
	    'value' => $unique ?? 0,
	]);
	echo R::app()->html->field('sparse', [
	    'type' => 'checkbox',
	    'label' => 'Is sparse',
	    'value' => $sparse ?? 0,
	]);
	echo R::app()->html->field('partial', [
	    'type' => 'checkbox',
	    'label' => 'Is partial',
	    'value' => $partial ?? 0,
	]);
	echo R::app()->html->field('partial_filter', [
	    'type' => 'textarea',
	    'label' => 'Partial filter expression',
	    'value' => $partial_filter ?? '',
	    'rows' => 5,
	    'wrap' => ($partial ?? 0) ? [ 'class' => 'mb-2' ] : [ 'class' => 'd-none mb-2' ],
	]);
	?>
	<input type="submit" value="Create" class="btn btn-sm btn-success" pjax-submit="1">
	<?= R::app()->html->link('Back', 'javascript:history.back()', [ 'class' => 'btn btn-sm btn-outline-secondary mx-1' ]); ?>
    </div>
</form>
