<?php
//CLD: 2026-10-21T00:05:00+03:00: renamed ProjectTools -> MCPTools (util/ProjectTools.php -> util/MCPTools.php)

/*
    the project's own tools a model may use, given by the plugins (the SysMCPTools plugin gives its MCP server's tools; none without it).
    Used by the AI steps (models\node\NodeAppAi: without permission checks, the workflow's own step) and the designer's Assistant (plugins\Assistant: as the user).
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
	//CLD: 2026-10-21T00:15:00+03:00: hook mcp:tools (was ai:project-tools)
	\V::app()->hooks->applyHook('mcp:tools', [ $tools ]);
	return $tools->getArrayCopy();
    }
}
