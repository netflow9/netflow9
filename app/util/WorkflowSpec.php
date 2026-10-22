<?php

/*
    a workflow as a compact spec, for agents (MCP tools list_step_types / get_workflow / create_workflow / update_workflow; later the
//designer's Assistant) - and back:
//  steps:       [ { id, type (fqname, e.g. app::set), title, config, parent (a loop's id) } ]
//  connections: [ { from, to, output (the output's label, e.g. true / otherwise / error, or its number; default 0), input (default 0) } ]
//The designer's data is made from it: ids checked, unknown types refused, connections resolved to output numbers (+ `branch` = the label, as the designer), positions laid
//out left to right (each level of a loop inside it, the loop sized around its steps); a step whose id is already in the workflow keeps its position and its server-set
//values (initConfig keys, e.g. the webhook token); new steps get initConfig() values (an agent cannot set them). Settings are checked by Workflow::validates, as when
//saving in the designer
*/

namespace util;

class WorkflowSpec {
    //most steps / connections per workflow
    const MAX_STEPS = 200;
    const MAX_CONNECTIONS = 400;

    //layout (px): gaps between columns / rows, a loop's inner margins (top: its title)
    const GAP_X = 80;
    const GAP_Y = 60;
    const PAD = 30;
    const PAD_TOP = 60;
    //below the last steps: their title is under them
    const PAD_BOTTOM = 50;

    // ------------------------------------------------------------------------------------
    //step types

    //the step types an agent may use: [ { type, label, kind, inputs, outputs, outputs_from, container, settings, variables } ]
    public static function stepTypes() {
	$types = [];
	foreach(\models\WfNode::getAll() as $fq => $def) {
	    $class = \V::app()->om->getObjectClass($def['objectName']);
	    $ports = $class::$outPorts;
	    $settings = [];
	    foreach($def['configSchema'] as $f)
		$settings[] = self::setting($f);
	    $vars = [];
	    foreach($class::outputVariables([]) as $name => $v)
		$vars[] = self::variable($name, $v);
	    $inner = [];
	    foreach($class::innerVariables([]) as $name => $v)
		$inner[] = self::variable($name, $v);
	    $types[] = array_filter([
		'type' => $fq,
		'label' => (string)$def['label'],
		'kind' => (string)$def['type'],
		'inputs' => (int)$def['inputs'],
		'outputs' => $class::outLabelsFor([]) ?? (int)$def['outputs'],
		'outputs_from' => empty($ports) ? null : (isset($ports['field']) ? "setting `{$ports['field']}`" : "the rows of setting `{$ports['rows']}` (their `{$ports['label']}`), then " . implode(', ', $ports['after'] ?? [])),
		'container' => $def['isContainer'] ? true : null,
		'note' => $def['isNote'] ? true : null,
		'settings' => $settings,
		'variables' => $vars ?: null,
		'variables_inside' => $inner ?: null,
	    ], fn($v) => $v !== null);
	}
	return $types;
    }

    //a setting as agents see it (from the smartform field the designer gets)
    private static function setting(array $f) {
	$s = [ 'key' => (string)$f['key'], 'label' => (string)($f['label'] ?? ''), 'type' => (string)($f['type'] ?? 'text') ];
	if (($f['type'] ?? '') === 'editgrid') {
	    $s['type'] = 'rows';
	    $s['row'] = array_map([ self::class, 'setting' ], (array)($f['components'] ?? []));
	//CLD: 2026-10-18T10:20:00+03:00: one nested object (app.smartform `nested`, sys\fields\Field_nested): its fields
	} elseif (($f['type'] ?? '') === 'nested') {
	    $s['type'] = 'object';
	    $s['fields'] = array_map([ self::class, 'setting' ], (array)($f['components'] ?? []));
	} elseif (!empty($f['multiple'])) {
	    $s['type'] = 'list';
	}
	//CLD: 2026-10-22T01:58:24+03:00: a select that takes typed values too (allowCustom, e.g. the AI steps' Model): text for agents (its choices are the designer's, by credential)
	if (!empty($f['allowCustom']) && $s['type'] === 'select')
	    $s['type'] = 'text';
	if (!empty($f['required']))
	    $s['required'] = true;
	if (array_key_exists('default', $f))
	    $s['default'] = $f['default'];
	if (!empty($f['choices']))
	    $s['choices'] = array_column((array)$f['choices'], 'label', 'value');
	if (!empty($f['expression']))
	    $s['syntax'] = is_string($f['expression']) ? $f['expression'] : 'text';
	if (!empty($f['variableName']))
	    $s['syntax'] = 'variable name';
	if (!empty($f['helpText']))
	    $s['help'] = (string)$f['helpText'];
	//CLD: 2026-10-19T17:02:48+03:00: an email built with the email designer (models\EmailBlocks): its shape, so agents can write one (they could not: "add at least one block")
	if (($f['type'] ?? '') === 'emaildesigner')
	    //CLD: 2026-10-23T01:10:00+03:00: + {{ markdown(...) }} (an AI step's answer formatted)
	    $s['format'] = '{ preheader: "", blocks: [ { type: "html", html: "<p>text with {{ expressions }}</p>" } ] }: at least one block; html blocks take p, br, strong, em, u, h1-h3, ul / ol / li, a href; '
		. 'values in {{ }} are shown as plain text; an AI step\'s answer is shown formatted with {{ ai.html }} (a field named html, alone in its {{ }}: its HTML cleaned) or '
		. '{{ markdown(ai.text) }} (any Markdown text), e.g. <p>{{ ai.html }}</p>; '
		. 'image blocks: { type: "image", file: <image file id (list_files)>, alt, width (px, at most ' . \models\EmailBlocks::WIDTH . '), align (left / center / right), href }';
	//CLD: 2026-10-14T20:17:14+03:00: + required_if; a condition { key, operator, value } as it is (util\Conditions' operators; was the JS text of showIf)
	foreach([ 'showIf' => 'shown_if', 'hideIf' => 'hidden_if', 'requiredIf' => 'required_if' ] as $k => $name) {
	    if (!empty($f[$k]))
		$s[$name] = $f[$k];
	}
	return $s;
    }

    //a variable a step provides: name, type, fields
    private static function variable($name, array $v) {
	return array_filter([ 'name' => (string)$name, 'type' => \util\Expression::typeLabel($v), 'info' => $v['info'] ?? null,
	    'fields' => isset($v['fields']) ? array_keys($v['fields']) : null, 'item_fields' => isset($v['items']['fields']) ? array_keys($v['items']['fields']) : null ], fn($x) => $x !== null);
    }

    // ------------------------------------------------------------------------------------
    //workflow -> spec

    //the draft of $wf as a spec: { id, name, status, steps, connections, problems }
    public static function toSpec($wf) {
	$steps = [];
	$connections = [];
	$nodes = is_array($wf->nodes) ? $wf->nodes : [];
	$defs = \models\WfNode::getAll();
	foreach($nodes as $n) {
	    if (!empty($n['isNode']))
		$steps[] = array_filter([ 'id' => (string)$n['wfid'], 'type' => (string)$n['fqname'], 'title' => (string)($n['title'] ?? ''),
		    'config' => is_array($n['config'] ?? null) && $n['config'] ? $n['config'] : null, 'parent' => !empty($n['parentId']) ? (string)$n['parentId'] : null ], fn($v) => $v !== null);
	}
	foreach($nodes as $e) {
	    if (empty($e['isEdge']))
		continue;
	    $c = [ 'from' => (string)$e['fromNode'], 'to' => (string)$e['toNode'], 'output' => isset($e['branch']) && $e['branch'] !== '' ? (string)$e['branch'] : (int)($e['fromPort'] ?? 0) ];
	    if ((int)($e['toPort'] ?? 0) !== 0)
		$c['input'] = (int)$e['toPort'];
	    $connections[] = $c;
	}
	return [ 'id' => (string)$wf->_id, 'name' => (string)$wf->name, 'status' => (string)($wf->status ?: \models\Workflow::DRAFT),
	    'steps' => $steps, 'connections' => $connections, 'problems' => self::problems($wf) ];
    }

    //the connection problems of $wf (Workflow::validateFlow): [ { level, step, message } ]
    public static function problems($wf) {
	return array_map(fn($i) => array_filter([ 'level' => $i['level'], 'step' => $i['node'] ?? null, 'message' => $i['message'] ], fn($v) => $v !== null), $wf->validateFlow());
    }

    // ------------------------------------------------------------------------------------
    //spec -> workflow

    //the designer's data (`nodes`) for steps $steps and connections $connections; $old: the workflow's current nodes (kept positions, server-set values); $relayout: lay out
    //every step (else only the new ones); \ErrorException for what cannot be built (an unknown type, a connection to a missing step, ...)
    public static function nodesFrom(array $steps, array $connections, array $old = [], $relayout = false) {
	if (count($steps) > self::MAX_STEPS)
	    throw new \ErrorException('steps: at most ' . self::MAX_STEPS);
	if (count($connections) > self::MAX_CONNECTIONS)
	    throw new \ErrorException('connections: at most ' . self::MAX_CONNECTIONS);
	$defs = \models\WfNode::getAll();
	$oldById = [];
	foreach($old as $o) {
	    if (!empty($o['isNode']))
		$oldById[(string)$o['wfid']] = $o;
	}

	//steps
	$nodes = [];
	foreach(array_values($steps) as $i => $s) {
	    $where = 'steps[' . $i . ']';
	    if (!is_array($s))
		throw new \ErrorException("{$where}: an object { id, type, title, config, parent }");
	    $id = (string)($s['id'] ?? '');
	    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id))
		throw new \ErrorException("{$where}: id: 1-40 letters, digits, _ or -");
	    if (isset($nodes[$id]))
		throw new \ErrorException("{$where}: the id `{$id}` is used twice");
	    $fq = (string)($s['type'] ?? '');
	    $def = $defs[$fq] ?? null;
	    if (!$def)
		throw new \ErrorException("{$where}: unknown type `{$fq}` (list_step_types)");
	    $class = \V::app()->om->getObjectClass($def['objectName']);
	    $config = $s['config'] ?? [];
	    if ($config instanceof \stdClass)
		$config = (array)$config;
	    if (!is_array($config) || ($config && array_is_list($config)))
		throw new \ErrorException("{$where}: config: an object { setting: value }");
	    $prev = $oldById[$id] ?? null;
	    if ($prev !== null && ($prev['fqname'] ?? '') !== $fq)
		$prev = null;     //another type under the same id: a new step
	    //server-set values: kept, or new ones
	    if ($def['hasInitConfig'])
		$config = array_merge($config, $prev !== null ? array_intersect_key(is_array($prev['config'] ?? null) ? $prev['config'] : [], $class::initConfig()) : $class::initConfig());
	    $title = trim((string)($s['title'] ?? ''));
	    $nodes[$id] = [ 'wfid' => $id, 'isNode' => true, 'parentId' => null, 'fqname' => $fq, 'title' => $title !== '' ? mb_substr($title, 0, 100) : (string)$def['label'],
		'config' => $config, 'geometry' => $prev !== null && !$relayout && is_array($prev['geometry'] ?? null) ? $prev['geometry'] : null,
		'__parent' => isset($s['parent']) && $s['parent'] !== '' ? (string)$s['parent'] : null ];
	}
	foreach($nodes as $id => &$n) {
	    $parent = $n['__parent'];
	    unset($n['__parent']);
	    if ($parent === null)
		continue;
	    if (!isset($nodes[$parent]) || empty($defs[$nodes[$parent]['fqname']]['isContainer']))
		throw new \ErrorException("step `{$id}`: parent `{$parent}` is not a loop of this workflow");
	    //CLD: 2026-10-14T21:17:55+03:00: a loop cannot be inside another loop (Workflow::validateFlow); so no loops containing each other (that check is gone)
	    if (!empty($defs[$n['fqname']]['isContainer']))
		throw new \ErrorException("step `{$id}`: a loop cannot be inside another loop");
	    $n['parentId'] = $parent;
	}
	unset($n);

	//connections: output label -> number (+ branch = the label, as the designer saves it)
	$edges = [];
	foreach(array_values($connections) as $i => $c) {
	    $where = 'connections[' . $i . ']';
	    if (!is_array($c))
		throw new \ErrorException("{$where}: an object { from, to, output }");
	    $from = (string)($c['from'] ?? '');
	    $to = (string)($c['to'] ?? '');
	    if (!isset($nodes[$from]) || !isset($nodes[$to]))
		throw new \ErrorException("{$where}: no step `" . (!isset($nodes[$from]) ? $from : $to) . '`');
	    $class = \V::app()->om->getObjectClass($defs[$nodes[$from]['fqname']]['objectName']);
	    $labels = $class::outLabelsFor($nodes[$from]['config']);
	    $count = $class::outputCountFor($nodes[$from]['config']);
	    $output = $c['output'] ?? 0;
	    if (is_int($output) || (is_string($output) && ctype_digit($output) && ($labels === null || !in_array($output, $labels, true)))) {
		$port = (int)$output;
	    } else {
		$port = $labels !== null ? array_search((string)$output, $labels, true) : false;
		if ($port === false)
		    throw new \ErrorException("{$where}: step `{$from}` has no output `{$output}`" . ($labels ? ' (outputs: ' . implode(', ', $labels) . ')' : ''));
	    }
	    if ($port < 0 || $port >= $count)
		throw new \ErrorException("{$where}: step `{$from}` has " . $count . ' output(s)');
	    $edge = [ 'wfid' => 'e' . ($i + 1) . '-' . $from . '-' . $to, 'isEdge' => true, 'fromNode' => $from, 'fromPort' => $port, 'toNode' => $to,
		'toPort' => max(0, (int)($c['input'] ?? 0)) ];
	    if ($labels !== null && isset($labels[$port]))
		$edge['branch'] = $labels[$port];
	    $edges[$edge['wfid']] = $edge;
	}

	self::layout($nodes, $edges, $defs);
	return $nodes + $edges;
    }

    //positions of the steps without one, levels top-down (the top, then inside each loop); a level of new steps only is laid out in columns by the longest path from its starts,
    //left to right (measure); new steps next to steps that keep their place go right of a placed predecessor (moved down while they overlap), else below the level; loops
    //are sized around their steps (new ones from their measured inside), then grown around them
    private static function layout(array &$nodes, array $edges, array $defs) {
	$preds = [];
	foreach($edges as $e)
	    $preds[$e['toNode']][] = $e['fromNode'];
	$level = fn($parent) => array_keys(array_filter($nodes, fn($n) => $n['parentId'] === $parent));
	$isLoop = fn($id) => !empty($defs[$nodes[$id]['fqname']]['isContainer']);

	//[ positions relative to the level's origin (id => [ x, y, w, h ]), width, height ] of a level as if all its steps were new
	$measure = function($parent) use (&$measure, &$nodes, $edges, $defs, $level, $isLoop) {
	    $ids = $level($parent);
	    if (!$ids)
		return [ [], 0, 0 ];
	    $col = array_fill_keys($ids, 0);
	    for($pass = 0; $pass < count($ids); $pass++) {
		$changed = false;
		foreach($edges as $e) {
		    if (isset($col[$e['fromNode']], $col[$e['toNode']]) && $col[$e['toNode']] < $col[$e['fromNode']] + 1 && $col[$e['fromNode']] + 1 < count($ids)) {
			$col[$e['toNode']] = $col[$e['fromNode']] + 1;
			$changed = true;
		    }
		}
		if (!$changed)
		    break;
	    }
	    $dims = [];
	    foreach($ids as $id) {
		$def = $defs[$nodes[$id]['fqname']];
		if ($isLoop($id)) {
		    [ , $w, $h ] = $measure($id);
		    $dims[$id] = [ max($def['defaultWidth'], $w + 2 * self::PAD), max($def['defaultHeight'], $h + self::PAD_TOP + self::PAD_BOTTOM) ];
		} else {
		    $dims[$id] = [ $def['defaultWidth'], $def['defaultHeight'] ];
		}
	    }
	    $widths = [];
	    foreach($ids as $id)
		$widths[$col[$id]] = max($widths[$col[$id]] ?? 0, $dims[$id][0]);
	    ksort($widths);
	    $x = 0;
	    $colX = [];
	    foreach($widths as $c => $w) {
		$colX[$c] = $x;
		$x += $w + self::GAP_X;
	    }
	    $pos = [];
	    $rowY = [];
	    $maxX = 0;
	    $maxY = 0;
	    foreach($ids as $id) {
		$y = $rowY[$col[$id]] ?? 0;
		$pos[$id] = [ $colX[$col[$id]], $y, $dims[$id][0], $dims[$id][1] ];
		$rowY[$col[$id]] = $y + $dims[$id][1] + self::GAP_Y;
		$maxX = max($maxX, $colX[$col[$id]] + $dims[$id][0]);
		$maxY = max($maxY, $y + $dims[$id][1]);
	    }
	    return [ $pos, $maxX, $maxY ];
	};

	$overlaps = function($g, $ids) use (&$nodes) {
	    foreach($ids as $o) {
		$h = $nodes[$o]['geometry'];
		if ($h !== null && $g['x'] < $h['x'] + $h['w'] && $h['x'] < $g['x'] + $g['w'] && $g['y'] < $h['y'] + $h['h'] && $h['y'] < $g['y'] + $g['h'])
		    return true;
	    }
	    return false;
	};

	//one level placed from its origin (absolute), then the loops in it
	$place = function($parent, $ox, $oy) use (&$place, &$nodes, $level, $isLoop, $measure, $preds, $overlaps) {
	    $ids = $level($parent);
	    $kept = array_values(array_filter($ids, fn($id) => $nodes[$id]['geometry'] !== null));
	    $new = array_values(array_filter($ids, fn($id) => $nodes[$id]['geometry'] === null));
	    if ($new) {
		[ $pos ] = $measure($parent);
		if (!$kept) {
		    foreach($new as $id)
			$nodes[$id]['geometry'] = [ 'x' => $ox + $pos[$id][0], 'y' => $oy + $pos[$id][1], 'w' => $pos[$id][2], 'h' => $pos[$id][3] ];
		} else {
		    foreach($new as $id) {
			[ , , $w, $h ] = $pos[$id];
			$anchor = null;
			foreach($preds[$id] ?? [] as $p) {
			    if (in_array($p, $ids, true) && $nodes[$p]['geometry'] !== null) {
				$anchor = $nodes[$p]['geometry'];
				break;
			    }
			}
			if ($anchor !== null) {
			    $g = [ 'x' => $anchor['x'] + $anchor['w'] + self::GAP_X, 'y' => $anchor['y'], 'w' => $w, 'h' => $h ];
			} else {
			    $placed = array_filter($ids, fn($o) => $nodes[$o]['geometry'] !== null);
			    $g = [ 'x' => min(array_map(fn($o) => $nodes[$o]['geometry']['x'], $placed)), 'y' => max(array_map(fn($o) => $nodes[$o]['geometry']['y'] + $nodes[$o]['geometry']['h'], $placed)) + self::GAP_Y, 'w' => $w, 'h' => $h ];
			}
			for($tries = 0; $tries < 100 && $overlaps($g, $ids); $tries++)
			    $g['y'] += $h + self::GAP_Y;
			$nodes[$id]['geometry'] = $g;
		    }
		}
	    }
	    foreach($ids as $id) {
		if ($isLoop($id))
		    $place($id, $nodes[$id]['geometry']['x'] + self::PAD, $nodes[$id]['geometry']['y'] + self::PAD_TOP);
	    }
	};
	$place(null, 0, 0);

	//loops grown around their steps
	//CLD: 2026-10-14T21:17:55+03:00: in any order (one level: a loop cannot be inside another loop; were sorted innermost first)
	foreach(array_keys($nodes) as $id) {
	    $parent = $nodes[$id]['parentId'];
	    if ($parent === null)
		continue;
	    $g = $nodes[$id]['geometry'];
	    $nodes[$parent]['geometry']['w'] = max($nodes[$parent]['geometry']['w'], $g['x'] + $g['w'] - $nodes[$parent]['geometry']['x'] + self::PAD);
	    $nodes[$parent]['geometry']['h'] = max($nodes[$parent]['geometry']['h'], $g['y'] + $g['h'] - $nodes[$parent]['geometry']['y'] + self::PAD_BOTTOM);
	}
    }

    //CLD: 2026-10-13T15:17:12+03:00: the steps / connections arguments of create_workflow, update_workflow (util\WorkflowSpec)
    //CLD: 2026-10-19T15:26:28+03:00: public: also the arguments of the designer Assistant's set_workflow (util\WorkflowAssistant)
    //CLD: 2026-10-19T20:10:00+03:00: now plugins\Assistant\util\WorkflowAssistant
    //CLD: 2026-10-20T23:40:00+03:00: here (was util\McpTools::specSchema: the Assistant uses it without the SysMCPTools plugin)
    public static function schema() {
	return [
	    'steps' => [ 'type' => 'array', 'maxItems' => self::MAX_STEPS, 'items' => [ 'type' => 'object', 'properties' => [
		'id' => [ 'type' => 'string', 'description' => '1-40 letters, digits, _ or -' ],
		'type' => [ 'type' => 'string', 'description' => 'A step type (list_step_types), e.g. trigger::manual, app::set, core::if, core::loop' ],
		'title' => [ 'type' => 'string' ],
		'config' => [ 'type' => 'object', 'description' => '{ setting key: value }; missing settings get their defaults' ],
		'parent' => [ 'type' => 'string', 'description' => 'The id of the loop this step is inside' ],
	    ], 'required' => [ 'id', 'type' ] ] ],
	    'connections' => [ 'type' => 'array', 'maxItems' => self::MAX_CONNECTIONS, 'items' => [ 'type' => 'object', 'properties' => [
		'from' => [ 'type' => 'string' ],
		'to' => [ 'type' => 'string' ],
		'output' => [ 'type' => [ 'string', 'integer' ], 'description' => 'The output of `from`: its label (e.g. true, false, otherwise, error, a case name) or number; default 0' ],
		'input' => [ 'type' => 'integer', 'description' => 'The input of `to` (Merge has 2); default 0' ],
	    ], 'required' => [ 'from', 'to' ] ] ],
	];
    }
}
