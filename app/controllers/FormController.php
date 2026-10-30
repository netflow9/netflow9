<?php

/*
    the pages of the Form triggers (plugins\Forms\models\NodeTriggerForm):
//  /form/<token>   GET: the form (project members: the app's layout, login required; public: a page of its own, no login); POST (JSON { answers }): the answers
//                  checked on the server (util\SmartForm::answers) -> { ok, message } and the workflow's published version started (after the answer), or
//                  422 { errors: { key: message } }
//  /form/list      the forms of the current project (members)
*/
namespace controllers;

use \models\node\NodeTriggerForm;

class FormController extends \sys\BaseController {

    public function list($req, $resp) {
	if (\V::app()->user->isGuest)
	    $this->redirect(\V::app()->user->getLoginUrl());
	$account = \V::app()->config('rt.account');
	//CLD: 2026-10-17T20:21:12+03:00: the forms of every project of the account (was the current project, members only) the user may open: public ones, and members ones of
	//the projects the user is a member of (as FormController::open)
	$aid = $account->_id ?? null;
	$forms = [];
	$projects = [];
	$member = [];
	//CLD: 2026-10-15T11:54:00+03:00: the token index is TriggerToken (was the FormLink model)
	foreach(\V::app()->om->find('TriggerToken', [ 'aid' => $aid, 'type' => 'trigger::form' ], [ 'sort' => [ 'title' => 1 ] ]) as $link) {
	    $pid = (string)$link->project;
	    if (!array_key_exists($pid, $member))
		$member[$pid] = \models\Project::isMember($link->project, $link->aid, \V::app()->user->_id);
	    if (($link->data['access'] ?? 'members') !== 'public' && !$member[$pid])
		continue;
	    $wf = \V::app()->om->findOne('Workflow', [ '_id' => $link->wf, 'aid' => $link->aid ]);
	    if (!$wf)
		continue;
	    if (!isset($projects[$pid]))
		$projects[$pid] = (string)(\V::app()->om->findOne('Project', [ '_id' => $link->project, 'aid' => $link->aid ])->name ?? '');
	    $forms[] = [ 'link' => $link, 'wf' => $wf ];
	}
	$this->render('list', compact('forms', 'projects'));
    }

    public function open($req, $resp) {
	$token = (string)($req->query['id'] ?? '');
	$post = $req->is('post');

	//CLD: 2026-10-18T18:37:53+03:00: + $resp (for sendJSON)
	$fail = function($status, $message) use ($post, $resp) {
	    if ($post)
		//CLD: 2026-10-18T18:37:53+03:00: $resp->sendJSON (was $this->json, removed)
		$resp->sendJSON([ 'ok' => false, 'error' => $message ], true, $status);
	    \V::app()->error($status, $message);
	};

	//the published step of the token
	//CLD: 2026-10-15T11:54:00+03:00: the token index is TriggerToken (was the FormLink model)
	//CLD: 2026-10-18T15:00:00+03:00: TriggerToken::isToken (was NodeTriggerForm::isToken)
	//CLD: 2026-10-21T20:10:00+03:00: the link's token is the credential and may be of another account than a signed-in user's: looked up across the accounts (ObjectManager
	//account guard)
	[ $link, $wf ] = \V::app()->om->acrossAccounts(function() use ($token) {
	    $link = \models\TriggerToken::isToken($token, 'hex') ? \V::app()->om->findOne('TriggerToken', [ 'type' => 'trigger::form', 'token' => $token ]) : null;
	    return [ $link, $link ? \V::app()->om->findOne('Workflow', [ '_id' => $link->wf, 'aid' => $link->aid ]) : null ];
	});
	$version = $wf ? $wf->publishedVersion() : null;
	$live = $version ? $wf->forVersion($version) : null;
	$node = null;
	foreach((is_array($live->nodes ?? null) ? $live->nodes : []) as $n) {
	    if (($n['wfid'] ?? null) == $link->node && ($n['config']['token'] ?? null) === $token)
		$node = $n;
	}
	if (empty($node))
	    $fail(404, 'This form does not exist.');

	$cfg = $node['config'];
	$public = ($cfg['access'] ?? 'members') === 'public';

	//who may fill it in
	$guest = \V::app()->user->isGuest;
	if (!$public) {
	    if ($guest) {
		if ($post)
		    $fail(401, 'Log in to send this form.');
		\V::app()->user->setReturnUrl('/form/' . $token);
		$this->redirect(\V::app()->user->getLoginUrl());
	    }
	    if ((string)(\V::app()->config('rt.account')->_id ?? '') !== (string)$link->aid || !\models\Project::isMember($link->project, $link->aid, \V::app()->user->_id))
		$fail(403, 'This form is for the members of its project.');
	}
	//CLD: 2026-10-21T20:10:00+03:00: also for a signed-in user of another account (a public form: the members-only ones were refused above): the form runs in its own account
	//(was: in the user's account)
	if ($guest || (string)(\V::app()->config('rt.account')->_id ?? '') !== (string)$link->aid)
	    \V::app()->var('rt.account', \V::app()->om->findById('Account', $link->aid));
	if ($wf->status !== \models\Workflow::PUBLISHED)
	    $fail(409, 'This form is not available now.');

	$form = \util\SmartForm::normalize($cfg['form'] ?? []);
	$title = trim((string)($node['title'] ?? '')) !== '' ? (string)$node['title'] : 'Form';
	if (!$post) {
	    if ($guest)
		$this->layout = 'form';
	    $this->render('open', [ 'title' => $title, 'form' => $form, 'url' => '/form/' . $token, 'submitLabel' => (string)($cfg['submitLabel'] ?? 'Send') ]);
	    return;
	}

	//the answers
	$body = json_decode((string)file_get_contents('php://input'), true);
	$data = is_array($body['answers'] ?? null) ? $body['answers'] : [];
	[ $answers, $errors ] = \util\SmartForm::answers($form, $data);
	if ($errors)
	    //CLD: 2026-10-18T18:37:53+03:00: $resp->sendJSON (was $this->json, removed)
	    $resp->sendJSON([ 'ok' => false, 'error' => 'Some fields need attention.', 'errors' => $errors ], true, 422);
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    $fail(409, 'This form cannot be sent now (its workflow has errors).');

	$variable = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'form';
	$user = $guest ? null : [ 'id' => (string)\V::app()->user->_id, 'name' => (string)\V::app()->user->name, 'email' => (string)\V::app()->user->email ];
	$exe = \models\WfExecution::startFor($live, 'form', [ 'form' => $token, 'user' => $user['id'] ?? null, 'received_at' => new \MongoDB\BSON\UTCDateTime(),
	    'vars' => [ $variable => $answers, $variable . '_user' => $user ] ], $link->node);
	$exe->log($link->node, 'form sent' . ($user ? ' by ' . $user['name'] : ''));
	//CLD: 2026-10-30T15:00:00+02:00: queued for the daemon (saved; was run here after the answer: runSync)
	$exe->queue();

	$resp->sendJSON([ 'ok' => true, 'message' => (string)($cfg['message'] ?? 'Thank you, your answers were sent.') ]);
    }
}
