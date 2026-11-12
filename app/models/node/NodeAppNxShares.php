<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: Nextcloud Shares - shares of the user's files (the OCS sharing API, apps/files_sharing/api/v1/shares). Operations (setting `operation`):
	list    the user's shares: all, of a path, or those shared with the user
	share   a file or folder shared: a public link (its url), with a user, a group, or an email address; can view / can edit / file drop (a folder's link: upload only);
	        an optional password, expiry date, note, label
	update  a share's permissions, password, expiry date, note, label (those not empty)
	delete  a share removed (unshared)
    A share: Nextcloud's fields (id, share_type, path, item_type, permissions, url, token, share_with, share_with_displayname, expiration, note, label, uid_owner)
*/

namespace models\node;

class NodeAppNxShares extends NodeAppNextcloud {
    public static $name = 'nextcloud-shares';
    public static $label = 'Nextcloud Shares';
    public static $icon = 'bi-share';
    public static $defaultOperation = 'share';
    public static $defaultVariable = 'share';

    const OPERATIONS = [
	'list' => 'List shares',
	'share' => 'Share a file or folder',
	'update' => 'Update a share',
	'delete' => 'Delete a share',
    ];
    const WRITES = [ 'share', 'update', 'delete' ];
    const LISTS = [ 'list' ];
    const API = '/apps/files_sharing/api/v1/shares';
    //Nextcloud's share types
    const WITH = [ 'link' => 'A public link', 'user' => 'A user', 'group' => 'A group', 'email' => 'An email address' ];
    const TYPES = [ 'user' => 0, 'group' => 1, 'link' => 3, 'email' => 4 ];
    const ACCESS = [ 'read' => 'Can view', 'edit' => 'Can edit', 'drop' => 'File drop (a folder\'s link: upload only)' ];
    const LIST_WHICH = [ 'mine' => 'Mine', 'path' => 'Of a path', 'withMe' => 'Shared with me' ];

    protected static function operationFields() {
	return [
	    self::field('which', 'Shares', [ 'list' ], [ 'type' => 'select', 'default' => 'mine', 'choices' => self::LIST_WHICH, 'ui' => [ 'width' => 6 ] ]),
	    self::field('path', 'Path', [ 'share', 'list' ], [ 'help' => 'A file or folder, e.g. /Documents/report.pdf, or an item of a list. List: for "Of a path"',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'share' ] ], 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('with', 'Share with', [ 'share' ], [ 'type' => 'select', 'required' => true, 'default' => 'link', 'choices' => self::WITH, 'ui' => [ 'width' => 6, 'newRow' => true ] ]),
	    self::field('shareWith', 'User, group or email', [ 'share' ], [ 'help' => 'The user\'s or group\'s ID, or the email address', 'ui' => [ 'expression' => 'text', 'width' => 6,
		'showIf' => [ 'key' => 'with', 'operator' => 'in', 'value' => [ 'user', 'group', 'email' ] ] ] ]),
	    self::field('share', 'Share', [ 'update', 'delete' ], [ 'required' => true, 'help' => 'Its ID, or a share of a list or of Share', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('access', 'Access', [ 'share', 'update' ], [ 'type' => 'select', 'choices' => [ '' => 'Unchanged (share: can view)' ] + self::ACCESS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('password', 'Password', [ 'share', 'update' ], [ 'help' => 'For a link or an email share; empty: none / unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6, 'type' => 'password' ] ]),
	    self::field('expireDate', 'Expires', [ 'share', 'update' ], [ 'help' => 'A date (2026-12-31) or relative (+30 days); empty: none / unchanged', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('note', 'Note', [ 'share', 'update' ], [ 'help' => 'Shown to the recipient', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('shareLabel', 'Label', [ 'share', 'update' ], [ 'help' => 'Of a link, to tell links apart', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    //Nextcloud's permissions: view 1; edit: a file 3 (read, update), a folder 15 (+ create, delete); file drop 4 (create)
    public static function permissions($access, $isFolder) {
	return [ 'read' => 1, 'edit' => $isFolder ? 15 : 3, 'drop' => 4 ][$access] ?? 1;
    }

    //YYYY-MM-DD of a date or a relative date (+30 days); '' stays ''
    public static function date($v, $label = 'Expires') {
	$t = trim(\util\Expression::toText($v));
	if ($t === '')
	    return '';
	$ts = preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? strtotime("{$t} 00:00:00 UTC") : strtotime($t);
	if ($ts === false)
	    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($t, 0, 40) . '`');
	return gmdate('Y-m-d', $ts);
    }

    protected function shareId(\Closure $value) {
	$v = $value('share');
	if (is_array($v))
	    $v = $v['id'] ?? '';
	$t = trim(\util\Expression::toText($v));
	if (!preg_match('/^\d{1,12}$/', $t) && !preg_match('/^ocinternal:\d+$/', $t))
	    throw new \RuntimeException('Share: not a share ID: `' . mb_substr($t, 0, 40) . '`');
	return $t;
    }

    //the settings of a share that are not empty: password, expireDate, note, label (+ permissions)
    protected function shareParams(\Closure $text, $isFolder) {
	$p = [];
	$access = (string)$this->cfg('access', '');
	if (isset(self::ACCESS[$access])) {
	    $p['permissions'] = self::permissions($access, $isFolder);
	    if ($access === 'drop')
		$p['publicUpload'] = 'true';
	}
	foreach([ 'password' => 'password', 'note' => 'note', 'shareLabel' => 'label' ] as $k => $param)
	    if (($t = $text($k)) !== '')
		$p[$param] = $t;
	if (($d = self::date($text('expireDate'))) !== '')
	    $p['expireDate'] = $d;
	return $p;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		$which = (string)$this->cfg('which', 'mine');
		$q = $which === 'withMe' ? [ 'shared_with_me' => 'true' ] : ($which === 'path' ? [ 'path' => \util\Nextcloud::path($value('path')), 'reshares' => 'true' ] : []);
		return $this->listResult((array)$this->nc->ocs('GET', self::API, $q), 'share');
	    case 'share':
		$path = \util\Nextcloud::path($value('path'));
		if ($path === '/')
		    throw new \RuntimeException('Path: a file or folder, not the top');
		$with = isset(self::WITH[(string)$this->cfg('with', 'link')]) ? (string)$this->cfg('with', 'link') : 'link';
		$item = $this->nc->items($path, 0)[0] ?? null;
		if (!$item)
		    throw new \RuntimeException("{$path}: not found");
		if ((string)$this->cfg('access', '') === 'drop' && ($with !== 'link' || !$item['isFolder']))
		    throw new \RuntimeException('Access: file drop is for a folder\'s public link');
		$p = [ 'path' => $path, 'shareType' => self::TYPES[$with] ] + $this->shareParams($text, $item['isFolder']);
		if ($with !== 'link') {
		    $to = $text('shareWith');
		    if ($to === '')
			throw new \RuntimeException('User, group or email: required');
		    $p['shareWith'] = $to;
		}
		$s = (array)$this->nc->ocs('POST', self::API, $p);
		return [ $s, "{$path} shared " . ($with === 'link' ? 'by link' . (!empty($s['url']) ? " {$s['url']}" : '') : "with {$p['shareWith']}") ];
	    case 'update':
		$id = $this->shareId($value);
		$current = (array)($this->nc->ocs('GET', self::API . '/' . rawurlencode($id))[0] ?? []);
		$p = $this->shareParams($text, ($current['item_type'] ?? '') === 'folder');
		if (!$p)
		    throw new \RuntimeException('nothing to change');
		$s = (array)$this->nc->ocs('PUT', self::API . '/' . rawurlencode($id), $p);
		return [ $s, "share {$id} updated: " . implode(', ', array_keys($p)) ];
	    case 'delete':
		$id = $this->shareId($value);
		$this->nc->ocs('DELETE', self::API . '/' . rawurlencode($id));
		return [ [ 'id' => $id, 'deleted' => true ], "share {$id} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
