<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new step: GravityZone Reports - the reports of a GravityZone console (Reports API). Operations:
	create     a report (createReport: name, type, targets; the rest, e.g. the schedule or the options, in Other parameters)
	list       the reports (getReportsList; all pages up to At most)
	links      a report's download addresses (getDownloadLinks: readyForDownload, lastInstanceUrl, allInstancesUrl)
	download   the last instance of a report saved in the project's Files (getDownloadLinks, then the file with the key): GravityZone's ZIP unpacked, its files of the types
	           Files allow (CSV, PDF, ...) saved (at most MAX_ENTRIES); a file that is not a ZIP saved as it is: { files: [ { id, filename, size, contentType } ], count }
	delete     a report (deleteReport)
*/

namespace models\node;

class NodeAppGzReports extends NodeAppGravityZone {
    public static $name = 'gz-reports';
    public static $label = 'GravityZone Reports';
    public static $icon = 'bi-file-earmark-bar-graph';
    public static $defaultOperation = 'download';
    public static $defaultVariable = 'report';

    const OPERATIONS = [
	'create' => 'Create a report',
	'list' => 'List reports',
	'links' => 'Get download links',
	'download' => 'Download into Files',
	'delete' => 'Delete a report',
    ];
    const LISTS = [ 'list' ];
    const MAX_FILE = 100 * 1024 * 1024;

    protected static function operationFields() {
	return [
	    self::field('reportName', 'Name', [ 'create' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 8 ] ]),
	    self::field('reportType', 'Type', [ 'create' ], [ 'type' => 'number', 'required' => true, 'default' => 1, 'help' => 'GravityZone\'s report type number (see its API reference)',
		'ui' => [ 'width' => 4, 'step' => 1 ] ]),
	    self::field('targetIds', 'Targets', [ 'create' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Endpoint or group ids: a list, or separated by commas',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('reportId', 'Report', [ 'links', 'download', 'delete' ], [ 'required' => true, 'help' => 'The report\'s id', 'ui' => [ 'expression' => true ] ]),
	];
    }

    public static function outputVariables($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if ($op === 'download' && \util\Validator::isIdentifier($name))
	    return [ $name => [ 'type' => 'object', 'info' => 'the files saved: { files, count }', 'fields' => [
		'files' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }: id in Files' ] ], 'count' => [ 'type' => 'number' ] ] ] ];
	return parent::outputVariables($config);
    }

    const MAX_ENTRIES = 20;

    //the report file $path saved in Files: the entries of a ZIP (of the types Files allow; at most MAX_ENTRIES), else the file as <report id>.<type>: [ { id, filename, size, contentType } ]
    protected function saveReport($path, $reportId, $mime) {
	$save = function($filename, $stream, $size) {
	    $id = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => 'gravityzone',
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	    return [ 'id' => (string)$id, 'filename' => $filename, 'size' => (int)$size, 'contentType' => \models\File::CONTENT_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? 'application/octet-stream' ];
	};
	$zip = new \ZipArchive();
	if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
	    $ext = [ 'text/csv' => 'csv', 'application/pdf' => 'pdf', 'application/json' => 'json' ][$mime] ?? 'csv';
	    $filename = "{$reportId}.{$ext}";
	    if (!\models\File::isAllowedName($filename))
		throw new \RuntimeException("file type not allowed: `{$filename}`");
	    $in = fopen($path, 'rb');
	    try {
		return [ $save($filename, $in, filesize($path)) ];
	    } finally {
		fclose($in);
	    }
	}
	$files = [];
	try {
	    for ($i = 0; $i < $zip->numFiles && count($files) < self::MAX_ENTRIES; $i++) {
		$stat = $zip->statIndex($i);
		$filename = basename(str_replace('\\', '/', (string)($stat['name'] ?? '')));
		if ($filename === '' || str_ends_with((string)$stat['name'], '/') || !\models\File::isAllowedName($filename))
		    continue;
		$in = $zip->getStream((string)$stat['name']);
		if (!$in)
		    continue;
		$tmp = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
		stream_copy_to_stream($in, $tmp, self::MAX_FILE);
		fclose($in);
		rewind($tmp);
		try {
		    $files[] = $save($filename, $tmp, (int)$stat['size']);
		} finally {
		    fclose($tmp);
		}
	    }
	} finally {
	    $zip->close();
	}
	if (!$files)
	    throw new \RuntimeException('the report\'s ZIP has no file of a type Files allow');
	return $files;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$report = fn() => [ 'reportId' => self::id($value('reportId'), 'Report') ];
	switch($operation) {
	    case 'create':
		if ($text('reportName') === '')
		    throw new \RuntimeException('Name: empty');
		$targets = self::ids($value('targetIds'), 'Targets');
		$r = $this->rpc('reports', 'createReport', [ 'name' => mb_substr($text('reportName'), 0, 255), 'type' => (int)$this->cfg('reportType', 1), 'targetIds' => $targets ]);
		return self::done($r, 'report `' . $text('reportName') . '` created');
	    case 'list':
		return $this->listOf('reports', 'getReportsList', [], 'report');
	    case 'links':
		return self::done($this->rpc('reports', 'getDownloadLinks', $report()), 'links of report ' . $text('reportId'));
	    case 'download':
		$links = $this->rpc('reports', 'getDownloadLinks', $report());
		$url = is_array($links) ? (string)($links['lastInstanceUrl'] ?? '') : '';
		if (empty($links['readyForDownload']) || $url === '')
		    throw new \RuntimeException('the report has no instance ready to download yet');
		$tmp = tempnam(sys_get_temp_dir(), 'gzr');
		$stream = fopen($tmp, 'w+b');
		try {
		    $r = $this->gz->download($url, $stream, self::MAX_FILE);
		    fclose($stream);
		    $stream = null;
		    $files = $this->saveReport($tmp, $text('reportId'), $r['mime']);
		} finally {
		    if ($stream)
			fclose($stream);
		    @unlink($tmp);
		}
		return self::done([ 'files' => $files, 'count' => count($files) ], 'saved ' . implode(', ', array_map(fn($f) => "`{$f['filename']}`", $files)));
	    case 'delete':
		return self::done($this->rpc('reports', 'deleteReport', $report()), 'report ' . $text('reportId') . ' deleted');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
