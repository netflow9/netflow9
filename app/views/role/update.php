<?php
$this->pageTitle = 'Update role: ' . $role->name;
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Roles', 'href' => '/role/list' ],
	[ 'label' => \V::app()->html->escape($role->name) ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?= \V::app()->form->create($role) ?>

	<!-- header -->
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<?php if (empty($role->iname)): ?>
		    <input type="text" name="name" class="form-control form-control-sm mb-1" style="max-width: 20rem" required value="<?= \V::app()->html->escape((string)$role->name) ?>" aria-label="Role name">
		<?php else: ?>
		<h5><?= \V::app()->html->escape($role->name) ?></h5>
		<?php endif; ?>
		<span class="text-muted">All permissions for this role</span>
	    </div>
	    <div class="flex-fill"></div>
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>

	<div class="pt-4"></div>
	<div class="w-50">

	    <?php
	    $itemTpl = '
	    <div class="list-group-item">
		{:enabled}{:label}
		<div class="float-end">
		    {:scope}
		</div>
	    </div>
	    ';

	    foreach(\data\SysPermissions::get() as $name => $permissions) {
		$items = [];
		foreach($permissions as $permDef) {
		    if (empty($permDef['desc']))
			continue;

		    $permName = $permDef['name'];

		    //fill in permission not present in role
		    if (empty($role->permissions[$permName]))
			$role->permissions[$permName] = [ 'enabled' => false, 'scope' => 'global' ];

		    $permHasOverride = !empty($role->override[$permName]);

		    //make scope look nice
		    $scopeChoices = [];
		    foreach($permDef['scopes'] ?? [] as $scope)
			$scopeChoices[$scope] = "Scope: $scope";

		    $items[] = \util\VString::insert($itemTpl, [
			'label' => $permDef['desc'],
			'enabled' => \V::app()->form->checkbox(
			    "permissions.{$permName}.enabled",
			    [
				'value' => 1,
				'hidden' => true,
				'checked' => $role->permissions[$permName]['enabled'],
				'disabled' => $permHasOverride,
				'class' => 'form-check-input me-1',
			    ]
			),
			'scope' => \V::app()->form->select(
			    "permissions.{$permName}.scope",
			    $scopeChoices,
			    [
				'class' => 'form-select form-select-sm',
				'value' => $role->permissions[$permName]['scope'] ?? 'own',
				'disabled' => $permHasOverride,
			    ],
			),
		    ]);
		}
		$items = implode('', $items);

		echo "
		<h6>{$name}</h6>
		<div class='list-group pb-3'>
		    {$items}
		</div>
		";
	    }
	    ?>
	</div>
    <?= \V::app()->form->end() ?>
</div>