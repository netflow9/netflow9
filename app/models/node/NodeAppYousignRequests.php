<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: Yousign Signature Requests - documents sent for e-signature, and what follows. Operations (setting `operation`):
	send       a new signature request: the project's files (PDF, DOCX: signed; Attachments: only read), the signers (lines "First Last <email> [+phone]", or a list of objects
	           { firstName, lastName, email, phone, locale, fields }), each with a signature field on a page of the first document (Position: in a row from X / Y, the next row
	           when the page is full; None: the objects' own `fields`), the level (simple, advanced, qualified), the authentication (OTP by email / SMS, none), ordered or not,
	           an expiration, an external ID; sent (activated) at once or kept as a draft. A step that fails after the request was made deletes the draft
	template   a new signature request from a template of Yousign's: its signers' labels filled ("Label: First Last <email>" lines), sent or kept as a draft
	get        a request with its signers (their signature link when delivery is None)
	list       requests: by status, external ID, name, created after (Yousign lists those made through the API unless Source says otherwise)
	activate   a draft sent; cancel (a reason, a note); delete (a draft, or one done / canceled); remind (a signer, or every signer not done yet)
	download   the documents to sign (signed when the request is done) saved in the project's Files, a PDF each: { files, count, file (the first), signed }
	audit      the audit trail (a PDF) of the request, or of one signer, saved in the project's Files
    A request's result (requestOf): { id, name, status, deliveryMode, created, activated, completed, expires, externalId, workspaceId, signers: [ { id, status, firstName,
    lastName, email, phone, signatureLink, signedAt } ], documents: [ { id, nature, filename } ], url }
*/

namespace models\node;

class NodeAppYousignRequests extends NodeAppYousign {
    public static $name = 'yousign-requests';
    public static $label = 'Yousign Signature Requests';
    public static $icon = 'bi-pen';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'signature';

    const OPERATIONS = [
	'send' => 'Send documents for signature',
	'template' => 'Send from a template',
	'get' => 'Get a request',
	'list' => 'List requests',
	'activate' => 'Send a draft',
	'cancel' => 'Cancel a request',
	'delete' => 'Delete a request',
	'remind' => 'Remind signers',
	'download' => 'Download the documents',
	'audit' => 'Download the audit trail',
    ];
    const WRITES = [ 'send', 'template', 'activate', 'cancel', 'delete', 'remind', 'download', 'audit' ];
    const LISTS = [ 'list' ];
    const MAX_DOCUMENTS = 50;
    const MAX_SIGNERS = 100;
    const MAX_DOCUMENT_MB = 50;

    const STATUSES = [ 'draft' => 'Draft', 'ongoing' => 'Ongoing', 'done' => 'Done', 'declined' => 'Declined', 'expired' => 'Expired', 'canceled' => 'Canceled',
	'approval' => 'Awaiting approval', 'rejected' => 'Rejected', 'paused' => 'Paused', 'deleted' => 'Deleted' ];
    const LEVELS = [ 'electronic_signature' => 'Simple electronic signature', 'advanced_electronic_signature' => 'Advanced electronic signature (AES)',
	'qualified_electronic_signature' => 'Qualified electronic signature (QES)' ];
    const AUTH = [ 'otp_email' => 'Code by email', 'otp_sms' => 'Code by SMS (a phone number needed)', 'no_otp' => 'None' ];
    const LOCALES = [ 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'nl' => 'Dutch', 'es' => 'Spanish', 'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian' ];
    const DELIVERY = [ 'email' => 'Yousign emails the signers', 'none' => 'None (the signature links are in the result)' ];
    const PLACEMENT = [ 'position' => 'A signature field each, at a position', 'none' => 'The signers\' own fields (objects with `fields`)' ];
    const REASONS = [ 'contractualization_aborted' => 'The contract is abandoned', 'errors_in_document' => 'Errors in the document', 'other' => 'Other' ];
    const SOURCES = [ 'public_api' => 'Made through the API', 'app' => 'Made in Yousign\'s app', '' => 'All' ];
    //the width of a page (A4, in points), where a row of signature fields ends
    const PAGE_WIDTH = 595;

    protected static function operationFields() {
	$id = [ 'get', 'activate', 'cancel', 'delete', 'remind', 'download', 'audit' ];
	return [
	    self::field('requestId', 'Signature request ID', $id, [ 'required' => true, 'help' => 'e.g. {{ signature.id }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('name', 'Name', [ 'send', 'template' ], [ 'help' => 'Shown to the signers, e.g. Contract {{ order.number }}; empty: the first document\'s name (a template: its own)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'send' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('templateId', 'Template ID', [ 'template' ], [ 'required' => true, 'help' => 'The Yousign Account step lists them', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('documents', 'Documents', [ 'send' ], [ 'required' => true, 'help' => 'Files of the project to sign (PDF, DOCX): ids separated by commas, or a list, e.g. {{ file.id }}',
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('attachments', 'Attachments', [ 'send' ], [ 'help' => 'Files the signers read, not signed (PDF, DOCX, JPG, PNG)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('signers', 'Signers', [ 'send', 'template' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'One a line: First Last <email> +phone (the phone for codes by SMS); or a list of objects { firstName, lastName, email, phone, locale }. A template: Label: First Last <email>',
		'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('signatureLevel', 'Signature level', [ 'send' ], [ 'type' => 'select', 'default' => 'electronic_signature', 'choices' => self::LEVELS,
		'help' => 'AES and QES need Yousign\'s add-ons and verify the signers\' identity', 'ui' => [ 'width' => 6 ] ]),
	    self::field('authMode', 'Signers\' authentication', [ 'send' ], [ 'type' => 'select', 'default' => 'otp_email', 'choices' => self::AUTH, 'ui' => [ 'width' => 6 ] ]),
	    self::field('locale', 'Language', [ 'send', 'template' ], [ 'type' => 'select', 'default' => 'en', 'choices' => self::LOCALES, 'help' => 'Of the signers (an object\'s own locale first)',
		'ui' => [ 'width' => 4 ] ]),
	    self::field('deliveryMode', 'Delivery', [ 'send', 'template' ], [ 'type' => 'select', 'default' => 'email', 'choices' => self::DELIVERY, 'ui' => [ 'width' => 8 ] ]),
	    self::field('placement', 'Signature fields', [ 'send' ], [ 'type' => 'select', 'default' => 'position', 'choices' => self::PLACEMENT, 'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	    self::field('page', 'Page', [ 'send' ], [ 'default' => 'last', 'help' => 'Of the first document: a number, or last', 'ui' => [ 'expression' => 'text', 'width' => 2 ] ]),
	    self::field('x', 'X', [ 'send' ], [ 'default' => '60', 'help' => 'Points from the left', 'ui' => [ 'expression' => 'text', 'width' => 2 ] ]),
	    self::field('y', 'Y', [ 'send' ], [ 'default' => '680', 'help' => 'Points from the top', 'ui' => [ 'expression' => 'text', 'width' => 2 ] ]),
	    self::field('orderedSigners', 'Signers in order', [ 'send' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Each signs after the one before', 'ui' => [ 'width' => 4, 'newRow' => true ] ]),
	    self::field('expiration', 'Expires on', [ 'send', 'template' ], [ 'help' => 'YYYY-MM-DD (at most a year); empty: Yousign\'s (6 months)', 'ui' => [ 'expression' => 'text', 'width' => 4, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('externalId', 'External ID', [ 'send', 'template', 'list' ], [ 'help' => 'Your reference, given back in the events (letters, digits, - _ @ . % +)', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('activate', 'Send now', [ 'send', 'template' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Off: kept as a draft (Send a draft sends it)', 'ui' => [ 'width' => 4 ] ]),
	    self::field('statuses', 'Statuses', [ 'list' ], [ 'type' => 'select', 'multiple' => true, 'default' => [], 'choices' => self::STATUSES, 'help' => 'None chosen: all', 'ui' => [ 'width' => 6 ] ]),
	    self::field('search', 'Name has', [ 'list' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('createdAfter', 'Created after', [ 'list' ], [ 'help' => 'YYYY-MM-DD', 'ui' => [ 'expression' => 'text', 'width' => 4, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('source', 'Source', [ 'list' ], [ 'type' => 'select', 'default' => 'public_api', 'choices' => self::SOURCES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('reason', 'Reason', [ 'cancel' ], [ 'type' => 'select', 'default' => 'other', 'choices' => self::REASONS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('note', 'Note', [ 'cancel' ], [ 'help' => 'At most 500 characters', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('signerId', 'Signer ID', [ 'remind', 'audit' ], [ 'help' => 'Empty: every signer not done yet (audit: the whole request)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filename', 'File name', [ 'download', 'audit' ], [ 'help' => 'Empty: the request\'s name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    // ------------------------------------------------------------------------------------
    //values

    //a request made plain; $signers: the signers' details (GET signers), else those the request has
    public static function requestOf(array $r, ?array $signers = null) {
	$signers = $signers ?? (array)($r['signers'] ?? []);
	return [
	    'id' => $r['id'] ?? null,
	    'name' => $r['name'] ?? null,
	    'status' => $r['status'] ?? null,
	    'deliveryMode' => $r['delivery_mode'] ?? null,
	    'created' => $r['created_at'] ?? null,
	    'activated' => $r['activated_at'] ?? null,
	    'completed' => $r['completed_at'] ?? null,
	    'expires' => $r['expiration_date'] ?? null,
	    'externalId' => $r['external_id'] ?? null,
	    'workspaceId' => $r['workspace_id'] ?? null,
	    'signers' => array_map([ self::class, 'signerOf' ], array_values(array_filter($signers, 'is_array'))),
	    'documents' => array_map(fn($d) => [ 'id' => $d['id'] ?? null, 'nature' => $d['nature'] ?? null, 'filename' => $d['filename'] ?? null ], array_values(array_filter((array)($r['documents'] ?? []), 'is_array'))),
	    'declined' => isset($r['decline_information']) && is_array($r['decline_information']) ? ($r['decline_information']['reason'] ?? null) : null,
	];
    }

    public static function signerOf(array $s) {
	$i = (array)($s['info'] ?? []);
	return [ 'id' => $s['id'] ?? null, 'status' => $s['status'] ?? null, 'firstName' => $i['first_name'] ?? null, 'lastName' => $i['last_name'] ?? null, 'email' => $i['email'] ?? null,
	    'phone' => $i['phone_number'] ?? null, 'signatureLink' => $s['signature_link'] ?? null, 'signedAt' => $s['signed_at'] ?? null ];
    }

    //a signer of a line ("[Label:] First Last <email> [+phone]") or an object: [ label, info, fields ]
    public static function signer($v, $locale, $label = 'Signers') {
	if (is_array($v)) {
	    $first = trim((string)($v['firstName'] ?? $v['first_name'] ?? ''));
	    $last = trim((string)($v['lastName'] ?? $v['last_name'] ?? ''));
	    if ($first === '' && $last === '' && isset($v['name']))
		[ $first, $last ] = self::splitName((string)$v['name']);
	    $info = [ 'first_name' => $first, 'last_name' => $last, 'email' => trim((string)($v['email'] ?? '')), 'phone' => trim((string)($v['phone'] ?? $v['phone_number'] ?? '')),
		'locale' => trim((string)($v['locale'] ?? '')) ?: $locale ];
	    $tag = trim((string)($v['label'] ?? ''));
	    $fields = isset($v['fields']) && is_array($v['fields']) ? $v['fields'] : null;
	} else {
	    $t = trim((string)$v);
	    $tag = '';
	    if (preg_match('/^([^<:@]{1,64}):\s*(.+)$/', $t, $m)) {
		$tag = trim($m[1]);
		$t = trim($m[2]);
	    }
	    if (!preg_match('/^(.*?)\s*<([^<>\s]+@[^<>\s]+)>\s*(\+?[\d\s().\-]{6,20})?\s*$/', $t, $m))
		throw new \RuntimeException("{$label}: not `First Last <email>`: `" . mb_substr($t, 0, 80) . '`');
	    [ $first, $last ] = self::splitName($m[1]);
	    $info = [ 'first_name' => $first, 'last_name' => $last, 'email' => $m[2], 'phone' => trim($m[3] ?? ''), 'locale' => $locale ];
	    $fields = null;
	}
	if ($info['first_name'] === '' || $info['last_name'] === '')
	    throw new \RuntimeException("{$label}: a first and a last name for {$info['email']}");
	if (!filter_var($info['email'], FILTER_VALIDATE_EMAIL))
	    throw new \RuntimeException("{$label}: not an email address: `" . mb_substr($info['email'], 0, 80) . '`');
	if ($info['phone'] !== '') {
	    $p = preg_replace('/[\s().\-]/', '', $info['phone']);
	    $p = preg_replace('/^00/', '+', $p);
	    if (!preg_match('/^\+\d{6,15}$/', $p))
		throw new \RuntimeException("{$label}: not a phone number in international format (+40722123456): `{$info['phone']}`");
	    $info['phone_number'] = $p;
	}
	unset($info['phone']);
	if (!isset(self::LOCALES[$info['locale']]))
	    $info['locale'] = 'en';
	return [ $tag, $info, $fields ];
    }

    //"Ana Maria Pop" => [ Ana Maria, Pop ]
    protected static function splitName($n) {
	$n = trim(preg_replace('/\s+/', ' ', trim((string)$n, " \t\"'")));
	$at = strrpos($n, ' ');
	return $at === false ? [ $n, '' ] : [ substr($n, 0, $at), substr($n, $at + 1) ];
    }

    //the signers of the setting: a list, or lines
    protected function signersOf(\Closure $value, $locale) {
	$v = $value('signers');
	$parts = is_array($v) ? (array_is_list($v) ? $v : [ $v ]) : preg_split('/\r?\n/', \util\Expression::toText($v));
	$out = [];
	foreach($parts as $p)
	    if (is_array($p) || trim((string)$p) !== '')
		$out[] = self::signer($p, $locale);
	if (!$out)
	    throw new \RuntimeException('Signers: none');
	if (count($out) > self::MAX_SIGNERS)
	    throw new \RuntimeException('Signers: more than ' . self::MAX_SIGNERS);
	return $out;
    }

    //file ids: a list (of ids, or of objects with `id`), or separated by commas / new lines
    protected function filesOf($v, $label) {
	$parts = is_array($v) ? (array_is_list($v) ? $v : [ $v ]) : preg_split('/[\s,;]+/', \util\Expression::toText($v));
	$files = [];
	foreach($parts as $p)
	    if (is_array($p) || trim((string)$p) !== '')
		$files[] = $this->projectFile($p, $label);
	return $files;
    }

    //an expiration date (YYYY-MM-DD); null: none
    protected static function expirationOf($t) {
	if ($t === '')
	    return null;
	$ts = strtotime($t);
	if ($ts === false)
	    throw new \RuntimeException('Expires on: not a date: `' . mb_substr($t, 0, 32) . '`');
	return date('Y-m-d', $ts);
    }

    protected function externalIdOf($t) {
	if ($t !== '' && !preg_match('/^[\w\-@.%+]{1,255}$/', $t))
	    throw new \RuntimeException('External ID: letters, digits and - _ @ . % + only (at most 255)');
	return $t !== '' ? $t : null;
    }

    //the multipart body of a document: [ body, content type ]
    protected static function multipart($file, $nature) {
	$boundary = 'rail22-' . bin2hex(random_bytes(12));
	$name = str_replace([ '"', "\r", "\n", '\\', '/' ], '_', (string)$file->filename);
	$body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"nature\"\r\n\r\n{$nature}\r\n"
	    . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: " . (\models\File::CONTENT_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream') . "\r\n\r\n"
	    . $file->getContents() . "\r\n--{$boundary}--\r\n";
	return [ $body, "multipart/form-data; boundary={$boundary}" ];
    }

    //the signature fields of $n signers in rows on $page of $doc, from x / y (width 180, height 60, 20 apart)
    public static function positions($n, $doc, $page, $x, $y, $w = 180, $h = 60, $gap = 20) {
	$cols = max(1, (int)floor((self::PAGE_WIDTH - $x + $gap) / ($w + $gap)));
	$out = [];
	for ($i = 0; $i < $n; $i++)
	    $out[] = [ [ 'type' => 'signature', 'document_id' => $doc, 'page' => $page, 'x' => $x + ($i % $cols) * ($w + $gap), 'y' => $y + intdiv($i, $cols) * ($h + $gap), 'width' => $w, 'height' => $h ] ];
	return $out;
    }

    protected function intOf($t, $label, $min, $max) {
	if (!preg_match('/^\d+$/', $t) || (int)$t < $min || (int)$t > $max)
	    throw new \RuntimeException("{$label}: a whole number from {$min} to {$max}");
	return (int)$t;
    }

    //the PDFs of a ZIP: [ [ name, body ] ]
    public static function unzip($body) {
	$tmp = tempnam(sys_get_temp_dir(), 'ys');
	file_put_contents($tmp, $body);
	$out = [];
	$zip = new \ZipArchive();
	if ($zip->open($tmp) === true) {
	    for ($i = 0; $i < $zip->numFiles && $i < self::MAX_DOCUMENTS; $i++) {
		$n = (string)$zip->getNameIndex($i);
		$st = $zip->statIndex($i);
		if (preg_match('/\.pdf$/i', $n) && (int)($st['size'] ?? 0) <= self::MAX_DOCUMENT_MB * 1024 * 1024 && ($b = $zip->getFromIndex($i)) !== false)
		    $out[] = [ basename($n), $b ];
	    }
	    $zip->close();
	}
	@unlink($tmp);
	return $out;
    }

    //a request's name as a file's
    protected static function safeName($n) {
	return trim(preg_replace('/[\\\\\/:*?"<>|]+/', '_', $n)) ?: 'signature';
    }

    //a request with its signers' details
    protected function fetch($id) {
	$Y = $this->yousign;
	$r = $Y->request('GET', '/signature_requests/' . rawurlencode($id));
	$signers = $Y->request('GET', '/signature_requests/' . rawurlencode($id) . '/signers');
	return self::requestOf($r, array_is_list($signers) ? $signers : (array)($signers['data'] ?? []));
    }

    protected function idOf(\Closure $text, $key = 'requestId', $label = 'Signature request ID') {
	$id = $text($key);
	if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id))
	    throw new \RuntimeException("{$label}: not one of Yousign's IDs: `" . mb_substr($id, 0, 40) . '`');
	return strtolower($id);
    }

    // ------------------------------------------------------------------------------------
    //operations

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$Y = $this->yousign;
	if ($operation === 'send' || $operation === 'template')
	    return $this->createRequest($operation, $text, $value);
	if ($operation === 'list') {
	    $q = [ 'status' => ($s = array_values(array_intersect(array_map('strval', (array)$this->cfg('statuses', [])), array_keys(self::STATUSES)))) ? [ 'in' => $s ] : null,
		'external_id' => ($x = $this->externalIdOf($text('externalId'))) !== null ? [ 'eq' => $x ] : null, 'q' => $text('search'),
		'created_at' => $text('createdAfter') !== '' ? [ 'after' => self::expirationOf($text('createdAfter')) ] : null ];
	    $src = (string)$this->cfg('source', 'public_api');
	    if ($src !== '' && isset(self::SOURCES[$src]))
		$q['source'] = [ 'eq' => $src ];
	    elseif ($src === '')
		$q['source'] = [ 'in' => 'app,public_api,hubspot_integration,connector_salesforce_api,connector_google_api,connector_zapier_api' ];
	    return $this->pagedResult('/signature_requests', $q, 'request', fn($r) => self::requestOf((array)$r));
	}
	$id = $this->idOf($text);
	$path = '/signature_requests/' . $id;
	switch($operation) {
	    case 'get':
		$r = $this->fetch($id);
		return [ $r, "{$r['name']}: {$r['status']}" ];
	    case 'activate':
		$r = self::requestOf($Y->request('POST', "{$path}/activate"));
		return [ $r, "{$r['name']}: {$r['status']}" ];
	    case 'cancel':
		$note = $text('note');
		if (mb_strlen($note) > 500)
		    throw new \RuntimeException('Note: more than 500 characters');
		$reason = isset(self::REASONS[(string)$this->cfg('reason', 'other')]) ? (string)$this->cfg('reason', 'other') : 'other';
		$r = self::requestOf($Y->request('POST', "{$path}/cancel", [], array_filter([ 'reason' => $reason, 'custom_note' => $note ], 'strlen')));
		return [ $r, "{$r['name']}: {$r['status']} ({$reason})" ];
	    case 'delete':
		$Y->request('DELETE', $path);
		return [ [ 'id' => $id, 'deleted' => true ], "{$id} deleted" ];
	    case 'remind':
		$sid = $text('signerId');
		if ($sid !== '')
		    $ids = [ $this->idOf($text, 'signerId', 'Signer ID') ];
		else {
		    $r = $this->fetch($id);
		    $ids = array_column(array_filter($r['signers'], fn($s) => in_array($s['status'], [ 'notified', 'verified', 'consent_given' ], true)), 'id');
		}
		foreach($ids as $s)
		    $Y->request('POST', "{$path}/signers/{$s}/send_reminder");
		return [ [ 'id' => $id, 'reminded' => $ids, 'count' => count($ids) ], \util\Format::plural(count($ids), 'signer') . ' reminded' ];
	    case 'download':
		//the request's download (signed: version completed), a ZIP when it has several documents: its PDFs saved one by one (the project's Files take no ZIP)
		$r = $Y->request('GET', $path);
		$done = ($r['status'] ?? '') === 'done';
		[ $body, $mime, $name ] = $Y->download("{$path}/documents/download", $done ? [ 'version' => 'completed' ] : []);
		$parts = str_contains($mime, 'zip') || str_starts_with($body, "PK\x03\x04") ? self::unzip($body) : [ [ $name, $body ] ];
		if (!$parts)
		    throw new \RuntimeException('Yousign\'s download has no PDF');
		$base = preg_replace('/\.pdf$/i', '', $text('filename'));
		$files = [];
		foreach($parts as $k => [ $n, $b ]) {
		    $n = $base !== '' ? $base . (count($parts) > 1 ? ' ' . ($k + 1) : '') . '.pdf' : ($n !== '' ? $n : self::safeName((string)($r['name'] ?? $id)) . (count($parts) > 1 ? ' ' . ($k + 1) : '') . '.pdf');
		    $files[] = $this->saveFile($n, $b, 'application/pdf');
		}
		return [ [ 'files' => $files, 'count' => count($files), 'file' => $files[0], 'requestId' => $id, 'status' => $r['status'] ?? null, 'signed' => $done ],
		    \util\Format::plural(count($files), 'document') . ' saved (' . implode(', ', array_map(fn($f) => "`{$f['filename']}`", $files)) . ')' . ($done ? '' : ', not signed yet') ];
	    case 'audit':
		$r = $Y->request('GET', $path);
		$sid = $text('signerId');
		[ $body, $mime, $name ] = $Y->download($sid !== '' ? "{$path}/signers/" . $this->idOf($text, 'signerId', 'Signer ID') . '/audit_trails/download' : "{$path}/audit_trails/download");
		$base = $text('filename');
		$name = $base !== '' ? preg_replace('/\.pdf$/i', '', $base) . '.pdf' : self::safeName((string)($r['name'] ?? $id)) . ' - audit trail.pdf';
		$file = $this->saveFile($name, $body, $mime ?: 'application/pdf');
		return [ $file + [ 'requestId' => $id, 'status' => $r['status'] ?? null ], "`{$file['filename']}` saved ({$file['size']} bytes)" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //send / template: the request made, its documents and signers added, activated (or kept as a draft)
    protected function createRequest($operation, \Closure $text, \Closure $value) {
	$Y = $this->yousign;
	$locale = isset(self::LOCALES[(string)$this->cfg('locale', 'en')]) ? (string)$this->cfg('locale', 'en') : 'en';
	$delivery = (string)$this->cfg('deliveryMode', 'email') === 'none' ? 'none' : 'email';
	$signers = $this->signersOf($value, $locale);
	$body = array_filter([ 'name' => mb_substr($text('name'), 0, 128), 'delivery_mode' => $delivery, 'expiration_date' => self::expirationOf($text('expiration')),
	    'external_id' => $this->externalIdOf($text('externalId')), 'workspace_id' => trim((string)$this->cred->workspaceId) ?: null ], fn($v) => $v !== null && $v !== '');
	if ($operation === 'template') {
	    $tid = $this->idOf($text, 'templateId', 'Template ID');
	    foreach($signers as [ $tag, $info ])
		if ($tag === '')
		    throw new \RuntimeException("Signers: a template's signer needs its label (Label: First Last <email>): {$info['email']}");
	    $body += [ 'template_id' => $tid, 'template_placeholders' => [ 'signers' => array_map(fn($s) => [ 'label' => $s[0], 'info' => $s[1] ], $signers) ] ];
	    $r = $Y->request('POST', '/signature_requests', [], $body);
	    return $this->finish($r, $text);
	}
	$docs = $this->filesOf($value('documents'), 'Documents');
	$attachments = $this->filesOf($value('attachments'), 'Attachments');
	if (!$docs)
	    throw new \RuntimeException('Documents: none');
	if (count($docs) + count($attachments) > self::MAX_DOCUMENTS)
	    throw new \RuntimeException('Documents: more than ' . self::MAX_DOCUMENTS . ' with the attachments');
	foreach(array_merge($docs, $attachments) as $f)
	    if ((int)$f->length > self::MAX_DOCUMENT_MB * 1024 * 1024)
		throw new \RuntimeException("`{$f->filename}` is larger than " . self::MAX_DOCUMENT_MB . ' MB');
	foreach($docs as $f)
	    if (!preg_match('/\.(pdf|docx)$/i', (string)$f->filename))
		throw new \RuntimeException("Documents: `{$f->filename}` is not a PDF or DOCX (images can only be attachments)");
	$placement = (string)$this->cfg('placement', 'position') === 'none' ? 'none' : 'position';
	$pageText = strtolower($text('page'));
	$page = $pageText === '' || $pageText === 'last' ? null : $this->intOf($pageText, 'Page', 1, 10000);
	$x = $this->intOf($text('x') !== '' ? $text('x') : '60', 'X', 0, 32000);
	$y = $this->intOf($text('y') !== '' ? $text('y') : '680', 'Y', 0, 32000);
	if ($placement === 'none')
	    foreach($signers as [ , $info, $fields ])
		if (!$fields)
		    throw new \RuntimeException("Signers: {$info['email']} has no `fields` (Signature fields: the signers' own)");
	if (!isset($body['name']))
	    $body['name'] = mb_substr(preg_replace('/\.\w+$/', '', (string)$docs[0]->filename), 0, 128) ?: 'Documents to sign';
	$body['ordered_signers'] = (bool)$this->cfg('orderedSigners', false);
	$r = $Y->request('POST', '/signature_requests', [], $body);
	$id = (string)($r['id'] ?? '');
	if ($id === '')
	    throw new \RuntimeException('Yousign gave no signature request ID');
	try {
	    $first = null;
	    foreach($docs as $f) {
		$d = $Y->request('POST', "/signature_requests/{$id}/documents", [], null, self::multipart($f, 'signable_document'));
		$first = $first ?? $d;
	    }
	    foreach($attachments as $f)
		$Y->request('POST', "/signature_requests/{$id}/documents", [], null, self::multipart($f, 'attachment'));
	    $level = isset(self::LEVELS[(string)$this->cfg('signatureLevel', '')]) ? (string)$this->cfg('signatureLevel') : 'electronic_signature';
	    $auth = isset(self::AUTH[(string)$this->cfg('authMode', '')]) ? (string)$this->cfg('authMode') : 'otp_email';
	    $positions = $placement === 'position'
		? self::positions(count($signers), (string)$first['id'], $page ?? max(1, (int)($first['total_pages'] ?? 1)), $x, $y) : [];
	    foreach($signers as $i => [ , $info, $fields ]) {
		if ($auth === 'otp_sms' && empty($info['phone_number']))
		    throw new \RuntimeException("Signers: {$info['email']} needs a phone number for codes by SMS");
		$fields = $placement === 'position' ? $positions[$i] : array_map(fn($fl) => (array)$fl + [ 'document_id' => (string)$first['id'], 'type' => 'signature' ], $fields);
		$Y->request('POST', "/signature_requests/{$id}/signers", [], [ 'info' => $info, 'signature_level' => $level, 'signature_authentication_mode' => $auth, 'fields' => $fields ]);
	    }
	} catch (\RuntimeException $e) {
	    try {
		$Y->request('DELETE', "/signature_requests/{$id}");
	    } catch (\RuntimeException $e2) {
	    }
	    throw new \RuntimeException($e->getMessage() . ' (the draft was deleted)');
	}
	return $this->finish($r, $text);
    }

    //a new request activated when Send now, then given with its signers
    protected function finish(array $r, \Closure $text) {
	$id = (string)($r['id'] ?? '');
	if ($this->cfg('activate', true))
	    $this->yousign->request('POST', "/signature_requests/{$id}/activate");
	$out = $this->fetch($id);
	return [ $out, "{$out['name']}: {$out['status']}, " . \util\Format::plural(count($out['signers']), 'signer') . ' (' . implode(', ', array_slice(array_filter(array_column($out['signers'], 'email')), 0, 3))
	    . (count($out['signers']) > 3 ? ', …' : '') . ')' ];
    }
}
