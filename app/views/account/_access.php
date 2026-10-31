<?php
//CLD: 2026-11-01T02:00:00+02:00: new: the Data access tab of the account's settings (AccountController::settings, the owners: Account::update): the projects whose MCP server is on
//(switches mcp_projects[]: Account::mcpEnabled), what the designer's Assistant may read (assistant_data: Account::assistantReadsData). $account, $projects (id => Project)
$on = array_map('strval', (array)($account->mcp_projects ?? []));
$rows = '';
foreach($projects as $id => $p)
    $rows .= \util\VString::insert('<tr><td class="align-middle">{:name}</td><td class="text-end"><div class="form-check form-switch d-inline-block mb-0">'
	. '<input class="form-check-input" type="checkbox" role="switch" name="mcp_projects[]" value="{:id}" id="mcp-{:id}" aria-label="MCP server of {:name}"{:checked}></div></td></tr>', [
	'name' => \V::app()->html->escape((string)$p->name),
	'id' => \V::app()->html->escape($id),
	'checked' => in_array($id, $on, true) ? ' checked' : '',
    ]);
?>
    <?= \V::app()->form->create($account, [ 'action' => \V::app()->createUrl('/account/settings', [ '__tab' => 'access' ]) ]) ?>
	<div class="row">
	    <div class="col-xl-6">
		<div class="pt-4">
		    <h6>MCP server</h6>
		    <div class="form-text mb-2">AI clients (Claude Desktop, Claude Code, Cursor, ...) can work with a project through its MCP server: its data tables, files and workflows, with the rights of the user who made the token. When it is on, the project's menu shows <strong>MCP server</strong>, where its members make their tokens. When it is off, the project's tokens stop working.</div>
		    <?php if ($rows === ''): ?>
			<div class="text-muted small">The account has no projects yet.</div>
		    <?php else: ?>
			<table class="table table-sm mb-0">
			    <thead><tr><th class="fw-normal text-muted small">Project</th><th class="fw-normal text-muted small text-end">MCP server</th></tr></thead>
			    <tbody><?= $rows ?></tbody>
			</table>
		    <?php endif; ?>
		</div>
	    </div>

	    <div class="col-xl-6">
		<div class="pt-4">
		    <h6>Assistant</h6>
		    <?= $account->schema['assistant_data']->input([ 'cols' => '6,6' ]) ?>
		    <div class="form-text">
			<strong>Structure only</strong>: the designer's Assistant sees what the project is made of (data tables and their columns, workflows, step types, file names, variable names), and can build with it, but reads no rows, file contents, images, run results or variable values.<br>
			<strong>Structure and data</strong>: it may also read and change rows, read files and images, and run workflows, with the rights of the person asking. What it reads is sent to the Assistant's AI provider.
		    </div>
		</div>
	    </div>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
