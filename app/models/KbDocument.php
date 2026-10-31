<?php

/*
    a document of a knowledge base (models\KnowledgeBase::addDocument): its key (unique in the knowledge base: file:<id>, url:<address>, or given),
    title, source (file | url | text; file, url), meta, the hash of what was indexed (unchanged: not indexed again), the number of its chunks and characters. Written and read
    through the collection by models\KnowledgeBase; registered for its indexes
*/
namespace models;

class KbDocument extends \sys\BaseModel {
    protected static $collection = 'kb-documents';
    public static $indexes = [
	[ [ 'kb' => 1, 'key' => 1 ], [ 'unique' => true ] ],
	[ [ 'kb' => 1, 'file' => 1 ], [] ],
	[ [ 'kb' => 1, 'updated_at' => -1 ], [] ],
    ];

    public function getSchema() {
	$fields = [
	    [ 'key' => 'kb', 'type' => 'object', 'object' => 'KnowledgeBase', 'label' => 'Knowledge base', 'help' => false, 'required' => true ],
	    [ 'key' => 'key', 'type' => 'text', 'label' => 'Key', 'help' => false, 'required' => true ],
	    [ 'key' => 'title', 'type' => 'text', 'label' => 'Title', 'help' => false ],
	    [ 'key' => 'source', 'type' => 'text', 'label' => 'Source', 'help' => false ],
	    [ 'key' => 'file', 'type' => 'text', 'label' => 'File', 'help' => false ],
	    [ 'key' => 'url', 'type' => 'text', 'label' => 'Address', 'help' => false ],
	    [ 'key' => 'meta', 'type' => 'array', 'label' => 'Metadata', 'help' => false ],
	    [ 'key' => 'hash', 'type' => 'text', 'label' => 'Hash', 'help' => false ],
	    [ 'key' => 'chunks', 'type' => 'number', 'label' => 'Chunks', 'help' => false ],
	    [ 'key' => 'chars', 'type' => 'number', 'label' => 'Characters', 'help' => false ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }
}
