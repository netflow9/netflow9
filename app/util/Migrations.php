<?php

/*
    the changes of the stored data that a version of the app needs (a function renamed in the expressions, a setting moved, ...), run once per
    database by `../v sys::migrate` (SysCommand::migrate; scripts/deploy.sh runs it after the indexes, before the restart). Each migration has an id, the app's version that needs
    it and a method of this class; one is run when the app's version (data\Version::NUMBER) has reached its version and the database has no record of it (models\Migration),
    oldest version first; a failure stops the ones after it. A migration can be run again (it only changes what still needs it), so a failure is fixed and the command run again.
    A new database (sys::install) has nothing to migrate: the migrations run there change nothing and are recorded.

    Migrations::pending()                  those to run: [ [ id, version, label, method ], ... ]
    Migrations::run($m, $dryRun)           runs one: its summary (what it changed, or would change); \RuntimeException on a failure
    Migrations::record($m, $summary)       notes it as done

    Adding one: an entry in LIST (the version of the commit that needs it) and its method, which returns a summary
*/

namespace util;

class Migrations {
    const LIST = [
	[ 'id' => 'markdown2html', 'version' => '0.99.820', 'label' => 'the expression function markdown() renamed markdown2html()', 'method' => 'markdown2html' ],
	//the users' UI fields into User prefs
	[ 'id' => 'userPrefs', 'version' => '0.99.839', 'label' => 'the users\' last_project, sidebar_width, sidebar_collapsed, hide_advanced_steps moved into prefs', 'method' => 'userPrefs' ],
    ];

    //the states of an execution that may still run (a Wait, a Human task): it runs from its own copy of the steps
    const ACTIVE_EXECUTIONS = [ 'new', 'run', 'sleep' ];

    public static function pending() {
	$list = array_filter(self::LIST, fn($m) => version_compare(\data\Version::NUMBER, $m['version'], '>='));
	$done = [];
	foreach(\V::app()->om->find('Migration', [ 'name' => [ '$in' => array_column($list, 'id') ] ]) as $r)
	    $done[(string)$r->name] = true;
	$list = array_values(array_filter($list, fn($m) => !isset($done[$m['id']])));
	usort($list, fn($a, $b) => version_compare($a['version'], $b['version']));
	return $list;
    }

    //[ applied: [ id => applied_at ] ] of every migration of LIST
    public static function applied() {
	$done = [];
	foreach(\V::app()->om->find('Migration', []) as $r)
	    $done[(string)$r->name] = $r->applied_at;
	return $done;
    }

    public static function run(array $m, $dryRun = false) {
	if (!method_exists(self::class, $m['method']))
	    throw new \RuntimeException("no method {$m['method']} for migration {$m['id']}");
	return (string)self::{$m['method']}($dryRun);
    }

    public static function record(array $m, $summary) {
	$r = \V::app()->om->create('Migration', [ 'name' => $m['id'], 'version' => $m['version'], 'label' => $m['label'], 'summary' => mb_substr((string)$summary, 0, 1000),
	    'app_version' => \data\Version::NUMBER, 'applied_at' => new \MongoDB\BSON\UTCDateTime() ]);
	if (!$r->save([ 'validate' => false ]))
	    throw new \RuntimeException("migration {$m['id']}: not recorded");
    }

    // ------------------------------------------------------------------------------------
    //helpers

    //the steps (nodes) of the workflows, their published versions and the executions that may still run, each changed by $change(array $node): the node, or null when unchanged.
    //Only the documents that change are written (their nodes only). Returns [ documents changed, steps changed ]
    protected static function eachNode(\Closure $change, $dryRun) {
	$om = \V::app()->om;
	$docs = 0;
	$steps = 0;
	foreach([ [ 'Workflow', [] ], [ 'WfVersion', [] ], [ 'WfExecution', [ 'state' => [ '$in' => self::ACTIVE_EXECUTIONS ] ] ] ] as [ $model, $query ]) {
	    foreach($om->find($model, $query) as $doc) {
		$nodes = self::plain($doc->nodes);
		if (!is_array($nodes))
		    continue;
		$n = 0;
		foreach($nodes as $k => $node) {
		    if (!is_array($node))
			continue;
		    $new = $change($node);
		    if ($new !== null && $new !== $node) {
			$nodes[$k] = $new;
			$n++;
		    }
		}
		if (!$n)
		    continue;
		$docs++;
		$steps += $n;
		if (!$dryRun)
		    $om->updateOne($model, [ '_id' => $doc->_id ], [ '$set' => [ 'nodes' => $nodes ] ]);
	    }
	}
	return [ $docs, $steps ];
    }

    //BSON documents / arrays as PHP arrays, everything else (dates, ids) kept as it is
    protected static function plain($v) {
	if ($v instanceof \MongoDB\Model\BSONDocument || $v instanceof \MongoDB\Model\BSONArray)
	    $v = $v->getArrayCopy();
	if (is_array($v)) {
	    foreach($v as $k => $x)
		$v[$k] = self::plain($x);
	}
	return $v;
    }

    //each text of $v (nested arrays too) through $f
    protected static function mapTexts($v, \Closure $f) {
	if (is_string($v))
	    return $f($v);
	if (is_array($v)) {
	    foreach($v as $k => $x)
		$v[$k] = self::mapTexts($x, $f);
	}
	return $v;
    }

    //a call of function $from renamed $to in J code (not a method: x.markdown(), not part of a name)
    public static function renameCall($code, $from, $to) {
	return preg_replace('/(?<![\w.$])' . preg_quote($from, '/') . '(?=\s*\()/', $to, $code);
    }

    //the same, only inside the {{ }} of a text
    public static function renameCallInTemplates($text, $from, $to) {
	if (!str_contains($text, '{{') || !str_contains($text, $from))
	    return $text;
	return preg_replace_callback('/\{\{.*?\}\}/s', fn($m) => self::renameCall($m[0], $from, $to), $text);
    }

    // ------------------------------------------------------------------------------------
    //the migrations

    //0.99.820: {{ markdown(x) }} is {{ markdown2html(x) }}; in every setting's {{ }}, and anywhere in a Code step's code
    protected static function markdown2html($dryRun) {
	[ $docs, $steps ] = self::eachNode(function(array $node) {
	    if (!isset($node['config']) || !is_array($node['config']))
		return null;
	    $config = $node['config'];
	    if (($node['fqname'] ?? '') === 'app::code' && isset($config['code']) && is_string($config['code']))
		$config['code'] = self::renameCall($config['code'], 'markdown', 'markdown2html');
	    $config = self::mapTexts($config, fn($t) => self::renameCallInTemplates($t, 'markdown', 'markdown2html'));
	    return $config === $node['config'] ? null : [ 'config' => $config ] + $node;
	}, $dryRun);
	return ($dryRun ? 'would change ' : 'changed ') . Format::plural($steps, 'step') . ' in ' . Format::plural($docs, 'workflow, version or execution', 'workflows, versions or executions');
    }

    //0.99.839: a user's last_project, sidebar_width, sidebar_collapsed and hide_advanced_steps are keys of its `prefs` (merged
    //into the ones it may have already; one already in prefs is kept), the old fields removed
    protected static function userPrefs($dryRun) {
	$keys = [ 'last_project', 'sidebar_width', 'sidebar_collapsed', 'hide_advanced_steps' ];
	$coll = \V::app()->mongo->selectCollection(\models\User::_database(), \models\User::_collection());
	$n = 0;
	foreach($coll->find([ '$or' => array_map(fn($k) => [ $k => [ '$exists' => true ] ], $keys) ], [ 'typeMap' => \sys\Mongo::TYPEMAP ]) as $u) {
	    $prefs = (is_array($u['prefs'] ?? null) ? $u['prefs'] : []) + array_filter(array_intersect_key($u, array_flip($keys)), fn($v) => $v !== null);
	    if (!$dryRun)
		$coll->updateOne([ '_id' => $u['_id'] ], [ '$set' => [ 'prefs' => (object)$prefs ], '$unset' => array_fill_keys($keys, '') ]);
	    $n++;
	}
	return ($dryRun ? 'would change ' : 'changed ') . Format::plural($n, 'user');
    }
}
