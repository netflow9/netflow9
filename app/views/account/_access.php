<?php
//CLD: 2026-11-01T02:00:00+02:00: new: the Data access tab of the account's settings (AccountController::settings, the owners: Account::update): the projects whose MCP server is on
//(switches mcp_projects[]: Account::mcpEnabled), what the designer's Assistant may read (assistant_data: Account::assistantReadsData). $account, $projects (id => Project)
//CLD: 2026-11-01T03:30:00+02:00: + $owners (id => User): the owners of the personal projects
//CLD: 2026-11-01T03:00:00+02:00: the MCP server only: what the Assistant may read moved to the Assistant's settings (AssistantSettings data_access), linked from here
$on = array_map('strval', (array)($account->mcp_projects ?? []));
$rows = '';
foreach($projects as $id => $p)
    $rows .= \util\VString::insert('<tr><td class="align-middle">{:name}{:owner}</td><td class="text-end"><div class="form-check form-switch d-inline-block mb-0">'
	. '<input class="form-check-input" type="checkbox" role="switch" name="mcp_projects[]" value="{:id}" id="mcp-{:id}" aria-label="MCP server of {:label}"{:checked}></div></td></tr>', [
	'name' => \V::app()->html->escape((string)$p->name),
	//CLD: 2026-11-01T03:30:00+02:00: a personal project: its owner's name ($owners: id => User), as every user's is named "Personal"
	'owner' => $p->personal ? \util\VString::insert(' <span class="text-muted">({:who})</span>', [
	    'who' => \V::app()->html->escape(isset($owners[(string)$p->owner]) ? trim((string)$owners[(string)$p->owner]) : 'a removed user') ]) : '',
	'label' => \V::app()->html->escape((string)$p->name . ($p->personal ? ' (' . (isset($owners[(string)$p->owner]) ? trim((string)$owners[(string)$p->owner]) : 'a removed user') . ')' : '')),
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
		    <div class="form-text">What the designer's Assistant may read of the projects (their structure only, or their data too) is set in the <?= \V::app()->html->link('Assistant settings', \V::app()->createUrl('/assistant/settings')) ?>.</div>
		</div>
	    </div>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
