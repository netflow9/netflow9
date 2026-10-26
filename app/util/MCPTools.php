<?php

/*
    the project's own tools a model may use, given by the plugins (the SysMCPTools plugin gives its MCP server's tools; none without it).
    CLD: 2026-10-26T23:56:07+02:00: the MCP server's tools are core again (util\McpServerTools, first); plugins may add more
    Used by the AI steps (models\node\NodeAppAi: without permission checks, the workflow's own step) and the designer's Assistant (util\WorkflowAssistant: as the user).
    MCPTools::all()
	[
	    name => [ name, title, description, inputSchema, annotations?, run: fn(array $args, $project, bool $asUser = false) => [ result (an array),
                          images: [ [ mimeType, data (base64) ] ] ] ] ]; run throws \ErrorException when the tool fails ($asUser: the signed-in user's permissions apply)
  A plugin gives tools from the hook `mcp:tools`: its argument is an \ArrayObject it adds them to ($tools[name] = [ ... ]).
*/
namespace util;

class MCPTools {

    public static function all() {
	$tools = new \ArrayObject();
	//CLD: 2026-10-26T23:56:07+02:00: the MCP server's tools, run as the signed-in user ($asUser: the designer's Assistant) or without permission checks (the AI steps: the workflow's own step)
	//(were added by the SysMCPTools plugin from the hook)
	foreach(McpServerTools::definitions() as $t)
	    $tools[$t['name']] = $t + [ 'run' => fn(array $args, $project, $asUser = false) => McpServerTools::images(McpServerTools::call($t['name'], $args, $project, $asUser)) ];
	\V::app()->hooks->applyHook('mcp:tools', [ $tools ]);
	return $tools->getArrayCopy();
    }
}
