<?php

/*
    CLD: 2026-11-02T18:00:00+02:00: new: a message of a conversation with an agent (models\AgentConversation, which writes and reads them): conversation, seq (its number), role (user | assistant | notice), text,
	label (a button's label, what the page shows; text: its value), format and html (an answer), buttons, execution (the run that answered), tools (on a person's message: what the tools did on that turn), at.
    Written directly to the collection: never in the audit or execution logs
*/

namespace models;

class AgentMessage extends \sys\BaseModel {
    protected static $collection = 'agent_messages';
    public static $indexes = [
	[ [ 'conversation' => 1, 'seq' => 1 ], [ 'unique' => true ] ],
	[ [ 'aid' => 1, 'at' => 1 ], [] ],
    ];

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'conversation', 'type' => 'object', 'object' => 'AgentConversation', 'label' => 'Conversation', 'help' => false ],
	    [ 'key' => 'role', 'type' => 'text', 'label' => 'Role', 'help' => false ],
	    [ 'key' => 'text', 'type' => 'text', 'label' => 'Text', 'help' => false ],
	], $this->getSystemSchema());
    }

    public static function coll() {
	return \V::app()->mongo->selectCollection(static::_database(), static::_collection());
    }
}
