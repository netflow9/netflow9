<?php

/*
    a message of a conversation with an agent (models\agent\AgentConversation, which writes and reads them): conversation, seq (its number), role (user | assistant | notice), text,
	label (a button's label, what the page shows; text: its value), format and html (an answer), buttons, execution (the run that answered), tools (on a person's message: what the tools did on that turn), at.
    Added through save(), the tools of a turn through an update ($push, noteTools); never in the audit or execution logs (auditLog false in data\SysObjects)
*/

namespace models\agent;

class AgentMessage extends \sys\BaseModel {
    protected static $collection = 'agent_messages';
    public static $indexes = [
	[ [ 'conversation' => 1, 'seq' => 1 ], [ 'unique' => true ] ],
	[ [ 'aid' => 1, 'at' => 1 ], [] ],
    ];

    public function getSchema() {
	$fields = [
	    [
		'key' => 'conversation',
		'type' => 'object',
		'object' => 'AgentConversation',
		'label' => 'Conversation',
		'help' => false
	    ],
	    [
		'key' => 'role',
		'type' => 'text',
		'label' => 'Role',
		'help' => false
	    ],
	    [
		'key' => 'text',
		'type' => 'text',
		'label' => 'Text',
		'help' => false
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }
}
