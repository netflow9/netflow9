<?php

/*
    the project's own tools a model may use: the MCP server's tools (util\McpServerTools, first, without NOT_FOR_MODELS: the tests), then the plugins'
	(hook `mcp:tools`: its argument is an \ArrayObject they add theirs to, $tools[name] = [ ... ]).
    Used by the AI steps (models\node\NodeAppAi: without permission checks, the workflow's own step) and the designer's Assistant (util\WorkflowAssistant: as the user).

	MCPTools::all() = [ name => [ name, title, description, inputSchema, annotations?, run ] ]
	    run: fn(array $args, $project, bool $asUser = false) => [ result (an array), images: [ [ mimeType, data (base64) ] ] ]; \ErrorException when the tool fails
	    ($asUser: the signed-in user's permissions apply)
*/

namespace util;

class MCPTools {

    public static function all() {
	$tools = new \ArrayObject();
	//the MCP server's tools, run as the signed-in user ($asUser: the designer's Assistant) or without permission checks (the AI steps: the workflow's own step);
	//not the tools that run a workflow's tests: for people's clients over MCP, an AI step or the Assistant must not start them
	foreach(McpServerTools::definitions() as $t)
	    if (!in_array($t['name'], McpServerTools::NOT_FOR_MODELS, true))
		$tools[$t['name']] = $t + [ 'run' => fn(array $args, $project, $asUser = false) => McpServerTools::images(McpServerTools::call($t['name'], $args, $project, $asUser)) ];
	\V::app()->hooks->applyHook('mcp:tools', [ $tools ]);
	return $tools->getArrayCopy();
    }
}
