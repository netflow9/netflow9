<?php
/*
    CLD: 2026-11-01T11:55:18+02:00: new: a tool server's form (ToolserversController::edit): $values (id, label, description, url, headers and tools as lines, maxSessions,
    maxSessionsPerAccount), $isNew; two columns, as the other settings forms (one screen)
*/
$this->pageTitle = $isNew ? 'Tool servers::Add' : 'Tool servers::' . $values['label'];
$back = \util\Services::adminPlugin() ? [ 'label' => 'Tool servers', 'href' => '/toolservers/index' ] : [ 'label' => 'Account settings', 'href' => '/account/settings?__tab=tools' ];
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();
    $bc = new \sys\html\Breadcrumbs([ 'items' => [ [ 'label' => 'Home', 'href' => '/site/index' ], $back, [ 'label' => $isNew ? 'Add a tool server' : $values['label'] ] ] ]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <h5 class="mb-3"><i class="bi bi-plug"></i> <?= $isNew ? 'Add a tool server' : \V::app()->html->escape($values['label']) ?></h5>
    <form method="post" action="<?= \V::app()->createUrl('/toolservers/edit') ?>">
	<input type="hidden" name="<?= \V::app()->csrf->csrf_key ?>" value="<?= \V::app()->html->escape(\V::app()->csrf->getToken(false)) ?>">
	<?php if (!$isNew): ?><input type="hidden" name="existing" value="1"><?php endif; ?>
	<div class="row g-4">
	    <div class="col-xl-6">
		<div class="row g-3">
		    <div class="col-md-4">
			<label class="form-label" for="ts-id">Id</label>
			<input type="text" class="form-control" id="ts-id" name="id" value="<?= \V::app()->html->escape((string)$values['id']) ?>" required pattern="[a-z][a-z0-9_]{0,30}" maxlength="31"<?= $isNew ? '' : ' readonly' ?>>
			<div class="form-text">Lowercase, e.g. <code>search</code>: the tools are named <code>&lt;id&gt;_&lt;tool&gt;</code>.</div>
		    </div>
		    <div class="col-md-8">
			<label class="form-label" for="ts-label">Label</label>
			<input type="text" class="form-control" id="ts-label" name="label" value="<?= \V::app()->html->escape((string)$values['label']) ?>" required maxlength="80">
		    </div>
		    <div class="col-12">
			<label class="form-label" for="ts-description">Description</label>
			<input type="text" class="form-control" id="ts-description" name="description" value="<?= \V::app()->html->escape((string)$values['description']) ?>">
			<div class="form-text">Shown in the AI step, and to the model with its tools.</div>
		    </div>
		    <div class="col-12">
			<label class="form-label" for="ts-url">URL</label>
			<input type="url" class="form-control font-monospace" id="ts-url" name="url" value="<?= \V::app()->html->escape((string)$values['url']) ?>" required placeholder="http://localhost:8000/mcp">
			<div class="form-text">The server's MCP endpoint (Streamable HTTP). It is called from this server, so it may be on this machine or its network.</div>
		    </div>
		    <div class="col-12">
			<label class="form-label" for="ts-headers">Headers</label>
			<textarea class="form-control font-monospace" id="ts-headers" name="headers" rows="3" placeholder="Authorization: Bearer ..."><?= \V::app()->html->escape((string)$values['headers']) ?></textarea>
			<div class="form-text">One per line, <code>Name: value</code>; sent with each request.</div>
		    </div>
		</div>
	    </div>
	    <div class="col-xl-6">
		<div class="row g-3">
		    <div class="col-12">
			<label class="form-label" for="ts-tools">Tools the model may use</label>
			<textarea class="form-control font-monospace" id="ts-tools" name="tools" rows="7"><?= \V::app()->html->escape((string)$values['tools']) ?></textarea>
			<div class="form-text">One per line. Empty: all the tools the server lists. Test shows them.</div>
		    </div>
		    <div class="col-md-6">
			<label class="form-label" for="ts-max">Sessions at once</label>
			<input type="number" class="form-control" id="ts-max" name="maxSessions" value="<?= \V::app()->html->escape((string)$values['maxSessions']) ?>" min="1" max="100" required>
			<div class="form-text">On the whole installation.</div>
		    </div>
		    <div class="col-md-6">
			<label class="form-label" for="ts-per">Per account</label>
			<input type="number" class="form-control" id="ts-per" name="maxSessionsPerAccount" value="<?= \V::app()->html->escape((string)$values['maxSessionsPerAccount']) ?>" min="1" max="100" required>
		    </div>
		</div>
	    </div>
	</div>
	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	    <a href="<?= \V::app()->html->escape($back['href']) ?>" class="btn btn-outline-secondary">Cancel</a>
	</div>
    </form>
</div>
