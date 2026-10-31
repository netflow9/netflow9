<?php

/*
    a chunk of a knowledge base's document (models\KnowledgeBase): its text, the document's key and title, its position (seq) and page, the document's
//meta, and its embedding (a float32 BSON vector: \MongoDB\BSON\Binary::fromVector). Written and read in bulk through the collection by models\KnowledgeBase; registered for its
//indexes: the chunks of a document, and the text index of the search by words (within a knowledge base: kb first; language none: no stemming, any language)
*/
namespace models;

class KbChunk extends \sys\BaseModel {
    protected static $collection = 'kb-chunks';
    public static $indexes = [
	[ [ 'kb' => 1, 'doc' => 1, 'seq' => 1 ], [] ],
	[ [ 'kb' => 1, 'text' => 'text' ], [ 'default_language' => 'none', 'name' => 'kb_text' ] ],
    ];

    public function getSchema() {
	$fields = [
	    [ 'key' => 'kb', 'type' => 'object', 'object' => 'KnowledgeBase', 'label' => 'Knowledge base', 'help' => false, 'required' => true ],
	    [ 'key' => 'doc', 'type' => 'object', 'object' => 'KbDocument', 'label' => 'Document', 'help' => false, 'required' => true ],
	    [ 'key' => 'key', 'type' => 'text', 'label' => 'Document key', 'help' => false ],
	    [ 'key' => 'title', 'type' => 'text', 'label' => 'Title', 'help' => false ],
	    [ 'key' => 'seq', 'type' => 'number', 'label' => 'Position', 'help' => false ],
	    [ 'key' => 'page', 'type' => 'number', 'label' => 'Page', 'help' => false ],
	    [ 'key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'help' => false ],
	    [ 'key' => 'meta', 'type' => 'array', 'label' => 'Metadata', 'help' => false ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }
}
