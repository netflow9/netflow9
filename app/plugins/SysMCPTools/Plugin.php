<?php

/*
    the project's MCP server and its tools (was in the core).
    Enabled in app/plugins.php; without it there is no /mcp endpoint, no MCP server menu link
    and the AI steps offer no NetFlow9 tools (their MCP server credentials still work).

      util/McpTools.php                  the tools: data tables, workflows (built by the core's util\WorkflowSpec), executions, files, variables
      controllers/McpController          POST /mcp: JSON-RPC, a token's user on the token's project; the rule and no session: here
      controllers/TokenController        /SysMCPTools/token/<list (?data=1: the grid's data)|create|delete> (was /token/...): the project's access tokens (views/token/)
      models/McpToken.php                an access token, its permissions: here (data\SysPermissions::register)

    The AI steps and the designer's Assistant get the tools through the hook mcp:tools (util\MCPTools).
*/

namespace plugins\SysMCPTools;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    //the same object name as in the core (stored tokens, audit entries and roles keep working)
	    'McpToken' => [
		'class' => __NAMESPACE__ . '\\models\\McpToken',
	    ],
	]);

	//access tokens of the project's MCP server (a token acts as the user who made it, within that user's permissions); defaults: also for the roles already stored
	\data\SysPermissions::register('McpToken', [
	    [
		'name' => 'McpToken::create',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Create MCP server tokens',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	    [
		'name' => 'McpToken::read',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'View MCP server tokens (also grants: list); the tokens themselves are shown only once',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	    [
		'name' => 'McpToken::list',
		'alias-of' => 'McpToken::read'
	    ],
	    [
		'name' => 'McpToken::delete',
		'scopes' => [ 'global', 'project' ],
		'desc' => 'Delete (revoke) MCP server tokens',
		'defaults' => [
		    'owner' => [ 'enabled' => true, 'scope' => 'global' ],
		    'member' => [ 'enabled' => true, 'scope' => 'project' ]
		]
	    ],
	]);

	//the tools (util\MCPTools): run as the signed-in user ($asUser: the designer's Assistant) or without permission checks (the AI steps: the workflow's own step)
	\V::app()->hooks->addHook('mcp:tools', function(\ArrayObject $tools) {
	    foreach(util\McpTools::definitions() as $t)
		$tools[$t['name']] = $t + [ 'run' => fn(array $args, $project, $asUser = false) => util\McpTools::images(util\McpTools::call($t['name'], $args, $project, $asUser)) ];
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	//POST /mcp -> McpController::serve (first rule); its callers are tokens, not browser users: no session
	\V::app()->router->add_rule('mcp', 'SysMCPTools/mcp/serve', true);
	\V::app()->session->noSessionFor('/mcp');

	//the MCP server link of the project menu (its tokens); checked on an unsaved token of the current project (project scope = membership)
	\V::app()->hooks->addHook('layout:aside-project-menu', function($crtProject) {
	    if (!\V::app()->user->can('McpToken::list', [ 'object' => \V::app()->om->create('McpToken', [ 'aid' => $crtProject->aid, 'project' => $crtProject->_id ]) ]))
		return;
	    echo \V::app()->html->link(
		'<i class="bi bi-plug"></i> MCP server',
		\V::app()->createUrl('/SysMCPTools/token/list'),
		[ 'class' => 'nav-link py-1 w-100', 'escape' => false ]
	    );
	});
    }
}
