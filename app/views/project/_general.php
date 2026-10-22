<?= \V::app()->form->create($project, [ 'action' => \V::app()->createUrl('', [ 'id' => $project->_id, '__tab' => $tab ]) ]) ?>

<div class="w-50 pt-4">
    <div class="mb-2">
	<label class="form-label">Icon and project name</label>
	<div class="d-flex gap-2">
	    <?= $project->schema['icon']->input([ 'bare' => true ]); ?>
	    <?= $project->schema['name']->input([ 'bare' => true ]); ?>
	</div>
    </div>

    <button class="btn btn-primary mt-4" type="submit">Save</button>

</div>

<?= \V::app()->form->end(); ?>
