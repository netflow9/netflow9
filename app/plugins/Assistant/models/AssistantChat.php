<?php

/*
    CLD: 2026-10-23T23:45:00+03:00: new: the Assistant's conversation of a workflow, one document per workflow (the draft's), shared by the people who may change it: it is
    there again on their next visit / login (was the browser tab's sessionStorage). `messages`: the panel's messages (js/app.wfassistant.js), the newest MAX_KEPT, each with
    who wrote it (uid, by) and when (at). Written by the designer (AssistantController::chat_add / chat_clear) with atomic updates ($push / $slice): two people may write at once.
    Removed with its workflow (hook workflow:before-delete, Plugin)
*/

namespace plugins\Assistant\models;

class AssistantChat extends \sys\BaseModel {
    protected static $collection = 'assistant_chats';
    public static $indexes = [
	[ [ 'aid' => 1, 'wf' => 1 ], [ 'unique' => true ] ],
    ];

    //the messages kept (as the panel's MAX_KEPT), the longest text kept, the most settings to fill in kept per message
    const MAX_KEPT = 40;
    const MAX_TEXT = 20000;
    const MAX_TO_FIX = 50;

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'wf', 'type' => 'object', 'object' => 'Workflow', 'label' => 'Workflow', 'required' => true ],
	    [ 'key' => 'messages', 'type' => 'array', 'label' => 'Messages', 'help' => false ],
	], $this->getSystemSchema());
    }

    //the messages of workflow $wf (oldest first; [] when none)
    public static function messagesOf($wf) {
	$chat = \V::app()->om->findOne('AssistantChat', [ 'wf' => $wf->_id, 'aid' => $wf->aid ]);
	return $chat && is_array($chat->messages) ? array_values($chat->messages) : [];
    }

    //message $m (the panel's, checked by clean()) added to workflow $wf's conversation, by the current user; the oldest dropped past MAX_KEPT => the message kept
    public static function add($wf, array $m) {
	$m = self::clean($m);
	$m['uid'] = (string)\V::app()->user->_id;
	$m['by'] = (string)\V::app()->user->name;
	$m['at'] = gmdate('c');
	\V::app()->om->updateOne('AssistantChat', [ 'wf' => $wf->_id, 'aid' => $wf->aid ], [
	    '$push' => [ 'messages' => [ '$each' => [ $m ], '$slice' => -self::MAX_KEPT ] ],
	], [ 'upsert' => true ]);
	return $m;
    }

    //a new conversation of workflow $wf
    public static function clear($wf) {
	\V::app()->om->updateOne('AssistantChat', [ 'wf' => $wf->_id, 'aid' => $wf->aid ], [ '$set' => [ 'messages' => [] ] ]);
    }

    //hook workflow:before-delete (Plugin)
    public static function beforeWorkflowDelete($wf) {
	foreach(\V::app()->om->find('AssistantChat', [ 'wf' => $wf->_id, 'aid' => $wf->aid ]) as $chat)
	    $chat->delete();
    }

    //only the fields the panel uses, of the expected types: id, role, text, error, unchanged, change { steps, errors, warnings, toFix }, credits, creditsLeft
    private static function clean(array $m) {
	$out = [
	    'id' => preg_match('/^[a-z0-9]{1,32}$/', (string)($m['id'] ?? '')) ? (string)$m['id'] : base_convert((string)time(), 10, 36) . bin2hex(random_bytes(2)),
	    'role' => ($m['role'] ?? '') === 'user' ? 'user' : 'assistant',
	    'text' => mb_substr(is_scalar($m['text'] ?? null) ? (string)$m['text'] : '', 0, self::MAX_TEXT),
	];
	foreach([ 'error', 'unchanged' ] as $k) {
	    if (!empty($m[$k]))
		$out[$k] = true;
	}
	if (isset($m['change']) && is_array($m['change'])) {
	    $c = $m['change'];
	    $toFix = array_slice(array_values(array_filter((array)($c['toFix'] ?? []), 'is_scalar')), 0, self::MAX_TO_FIX);
	    $out['change'] = [
		'steps' => (int)($c['steps'] ?? 0),
		'errors' => (int)($c['errors'] ?? 0),
		'warnings' => (int)($c['warnings'] ?? 0),
		'toFix' => array_map(fn($t) => mb_substr((string)$t, 0, 500), $toFix),
	    ];
	}
	if (!empty($m['credits'])) {
	    $out['credits'] = (int)$m['credits'];
	    $out['creditsLeft'] = isset($m['creditsLeft']) && is_numeric($m['creditsLeft']) ? (int)$m['creditsLeft'] : null;
	}
	return $out;
    }
}
