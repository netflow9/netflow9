<?php

//CLD: 2026-10-17T20:40:00+03:00: new step: File upload - starts the workflow (its published version) for each file a user uploads to the workflow's project (Files page:
//FileController::upload -> uploaded()); optionally only for some file types (File::$extensions). Files made by runs (mail attachments, Download step, ...) do not start it,
//so a workflow cannot start itself again. The run is a daemon job (deliver), the upload is not delayed
namespace models\node;

class NodeTriggerFile extends \models\WfNode {
    public static $type = 'trigger';
    public static $name = 'file';
    public static $label = 'File upload';
    public static $icon = 'bi-file-earmark-arrow-up';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    //the file types to choose from: type => 'Type (ext, ext)' (the types of File::$extensions)
    public static function typeChoices() {
	$exts = [];
	foreach(\models\File::$extensions as $ext => $type)
	    $exts[$type][] = $ext;
	$choices = [];
	foreach($exts as $type => $list)
	    $choices[$type] = $type . ' (' . implode(', ', $list) . ')';
	return $choices;
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'types',
		'type' => 'select',
		'multiple' => true,
		'label' => 'Only these file types',
		'default' => [],
		'choices' => self::typeChoices(),
		'help' => 'Files uploaded to this project\'s Files start the workflow once it is published; none chosen: any file',
		'ui' => [ 'placeholder' => 'Any file' ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'File variable',
		'required' => true,
		'default' => 'file',
		'help' => 'The uploaded file, e.g. {{ file.id }}, {{ file.filename }}, {{ file.type }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	];
    }

    //CLD: 2026-10-18T13:30:00+03:00: removed validates(): its check of `types` against typeChoices() is the select field's own `choices` rule (sys\fields\Field_select::bindTo)

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'file') : 'file';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object',
	    'info' => 'the uploaded file: { id, filename, extension, type, size, uploaded_by: { id, name, email } }',
	    'fields' => [
		'id' => [ 'type' => 'string' ],
		'filename' => [ 'type' => 'string' ],
		'extension' => [ 'type' => 'string', 'info' => 'lower case, e.g. pdf' ],
		'type' => [ 'type' => 'string', 'info' => 'e.g. PDF, Excel, Image' ],
		'size' => [ 'type' => 'number', 'info' => 'bytes' ],
		'uploaded_by' => [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ] ] ],
	    ] ] ] : [];
    }

    //whether a file of type $type is one step config $cfg accepts
    public static function accepts(array $cfg, $type) {
	$types = array_map('strval', (array)($cfg['types'] ?? []));
	return !$types || in_array((string)$type, $types, true);
    }

    //the published workflows of project $project with a File upload step accepting $type: [ [ wf, node ] ]
    protected static function stepsFor($aid, $project, $type) {
	$found = [];
	foreach(\V::app()->om->find('Workflow', [ 'aid' => $aid, 'project' => $project, 'status' => \models\Workflow::PUBLISHED, 'version' => [ '$ne' => null ] ]) as $wf) {
	    $version = $wf->publishedVersion();
	    foreach((is_array($version->nodes ?? null) ? $version->nodes : []) as $n) {
		if (!empty($n['isNode']) && ($n['fqname'] ?? '') === 'trigger::file' && !empty($n['wfid']) && self::accepts(is_array($n['config'] ?? null) ? $n['config'] : [], $type))
		    $found[] = [ $wf, $n ];
	    }
	}
	return $found;
    }

    //a user uploaded file $fileId (FileController::upload): one daemon job per step it starts
    public static function uploaded($fileId) {
	$file = \V::app()->om->findOne('File', [ '_id' => $fileId ]);
	if (!$file)
	    return;
	$aid = $file->metadata['aid'] ?? null;
	$project = $file->metadata['project'] ?? null;
	foreach(self::stepsFor($aid, $project, $file->type) as [ $wf, $node ]) {
	    \models\Job::submit('\models\node\NodeTriggerFile::deliver', [
		'wf' => (string)$wf->_id,
		'node' => (string)$node['wfid'],
		'file' => (string)$file->_id,
		'user' => (string)($file->metadata['owner'] ?? ''),
	    ], [
		'aid' => $aid,
		'project' => $project,
		'key' => 'file:' . $wf->_id . ':' . $node['wfid'] . ':' . $file->_id,
		'delete_on_done' => true,
	    ]);
	}
    }

    //Job handler (worker): start and run the workflow's published version for the file; skipped when the workflow, the step or the file is gone, or the
    //step no longer accepts the file's type (changed since)
    public static function deliver($data) {
	$om = \V::app()->om;
	$wf = $om->findOne('Workflow', [ '_id' => $om->to_oid($data->wf ?? null), 'aid' => $data->aid ?? null ]);
	$live = $wf ? $wf->liveCopy() : null;
	$file = $live ? $om->findOne('File', [ '_id' => $om->to_oid($data->file ?? null), 'metadata.aid' => $wf->aid, 'metadata.project' => $wf->project ]) : null;
	$node = null;
	foreach(($file && is_array($live->nodes) ? $live->nodes : []) as $n) {
	    if (($n['wfid'] ?? null) === ($data->node ?? null) && ($n['fqname'] ?? '') === 'trigger::file')
		$node = $n;
	}
	$cfg = is_array($node['config'] ?? null) ? $node['config'] : [];
	if (!$node || !self::accepts($cfg, $file->type)) {
	    \V::app()->log->info('File upload: workflow ' . ($data->wf ?? '?') . ', file ' . ($data->file ?? '?') . ': the workflow is not published, or the step or the file is gone, skipped');
	    return;
	}
	\V::app()->var('rt.account', $om->findById('Account', $wf->aid));
	if (array_filter($live->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \ErrorException("File upload: the workflow `{$wf->name}` has errors and cannot run");

	$user = ($data->user ?? '') !== '' ? $om->findOne('User', [ '_id' => $om->to_oid($data->user), 'aid' => $wf->aid ]) : null;
	$variable = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'file';
	$value = [
	    'id' => (string)$file->_id,
	    'filename' => (string)$file->filename,
	    'extension' => $file->extension(),
	    'type' => (string)$file->type,
	    'size' => (int)$file->length,
	    'uploaded_by' => $user ? [ 'id' => (string)$user->_id, 'name' => (string)$user->name, 'email' => (string)$user->email ] : null,
	];
	$exe = \models\WfExecution::startFor($live, 'file', [ 'file' => (string)$file->_id, 'user' => $user ? (string)$user->_id : null,
	    'received_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $variable => $value ] ], $node['wfid']);
	$exe->log($node['wfid'], 'file uploaded: ' . $file->filename . ($user ? ' by ' . $user->name : ''));
	$exe->save();
	$exe->run();
    }
}
