<?php

namespace commands;

//CLD: 2026-10-06T23:21:04+03:00: run / resume workflow executions from the console (was `test`, which picked a workflow by name)
//  ../v wf::run --id=<workflow id>       start an execution of the workflow and run it
//  ../v wf::resume --id=<execution id>   run a sleeping execution again (e.g. after a Wait)
class WfCommand extends \sys\BaseController {

    public function run($req = null, $resp = null) {
	$wf = $this->findOrExit('Workflow', $req->params['id'] ?? null);
	$this->setAccount($wf->aid);

	//CLD: 2026-10-06T23:25:57+03:00: do not run a workflow whose connections have errors (warnings are printed)
	//CLD: 2026-10-19T17:10:00+03:00: issues() (was validateFlow): + errors in the steps' settings (a draft may be saved with them)
	$issues = $wf->issues();
	foreach($issues as $i)
	    echo $i['level'] . ': ' . $i['message'] . ($i['node'] ? " [{$i['node']}]" : '') . "\n";
	if (array_filter($issues, fn($i) => $i['level'] == 'error')) {
	    echo "not started: fix the errors first\n";
	    exit(1);
	}

	//CLD: 2026-10-11T17:45:25+03:00: started from the console (was the default `manual`: the execution log and Executions panel showed a designer Run)
	$exe = \models\WfExecution::startFor($wf, 'console');
	$exe->save();
	echo "execution " . $exe->_id . " of workflow `" . $wf->name . "`\n";

	$exe->run();
	$this->report($exe);
    }

    public function resume($req = null, $resp = null) {
	$exe = $this->findOrExit('WfExecution', $req->params['id'] ?? null);
	$this->setAccount($exe->aid);

	if (!$exe->canRun()) {
	    echo "execution " . $exe->_id . " is `" . $exe->state . "`: nothing to run\n";
	    exit(1);
	}

	$exe->run();
	$this->report($exe);
    }

    private function findOrExit($objectName, $id) {
	if (empty($id) || !\util\Validator::rule('oid', (string)$id)) {
	    echo "usage: --id=<{$objectName} id>\n";
	    exit(1);
	}
	$o = \V::app()->om->findById($objectName, (string)$id);
	if (empty($o)) {
	    echo "{$objectName} {$id} not found\n";
	    exit(1);
	}
	return $o;
    }

    //nodes read objects of the account (e.g. Field_object choices)
    private function setAccount($aid) {
	\V::app()->var('rt.account', \V::app()->om->findById('Account', $aid));
    }

    private function report($exe) {
	echo "state: " . $exe->state . ($exe->error ? " (" . $exe->error . ")" : "") . "\n";
	if ($exe->state == 'sleep' && $exe->wake_at)
	    echo "wake at: " . $exe->wake_at->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') . "\n";

	echo "variables: " . json_encode($exe->variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	echo "log:\n";
	foreach($exe->log as $entry) {
	    $node = $entry['node'] ?? null;
	    $title = ($node && isset($exe->__nodes[$node])) ? (string)$exe->__nodes[$node] : '-';
	    echo "  [{$title}] " . $entry['message'] . "\n";
	}
    }
}
