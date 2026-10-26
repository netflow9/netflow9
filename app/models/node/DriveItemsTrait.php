<?php

//CLD: 2026-10-22T00:41:28+03:00: new trait: the drive and the items of the OneDrive / SharePoint and Excel steps: a drive given as empty (the account's OneDrive), a SharePoint site's address or
//a drive id; an item (file / folder) given by id, by path in the drive (/Reports/2026.xlsx) or by a sharing link. The step sets $drivePath (resolveDrive) before the items

//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\MicrosoftIntegration\models): the Microsoft credentials and steps are core, for every account (no plan feature)
namespace models\node;

trait DriveItemsTrait {
    //the drive's path (asked once per run): /me/drive, /drives/<id>
    protected $drivePath = null;

    //the drive's path: empty - /me/drive; a SharePoint site address - its document library (/drives/<id>); else a drive id
    protected function resolveDrive($drive) {
	$drive = trim((string)$drive);
	if ($drive === '')
	    return '/me/drive';
	if (str_starts_with($drive, 'https://')) {
	    [ $host, $site ] = self::siteOf($drive);
	    $answer = $this->api('GET', '/sites/' . $host . ':' . $site . ':/drive', [ '$select' => 'id' ]);
	    if (empty($answer['id']))
		throw new \RuntimeException("no document library at {$drive}");
	    return '/drives/' . rawurlencode((string)$answer['id']);
	}
	return '/drives/' . self::graphId($drive, 'drive');
    }

    //[ host, site path ] of a SharePoint address (https://contoso.sharepoint.com/sites/Team/Shared Documents/... -> contoso.sharepoint.com, /sites/Team); the root site: /
    public static function siteOf($url) {
	$parts = parse_url((string)$url);
	$host = strtolower((string)($parts['host'] ?? ''));
	if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.sharepoint\.com$/', $host))
	    throw new \RuntimeException('not a SharePoint address: `' . mb_substr((string)$url, 0, 100) . '`');
	$segments = array_values(array_filter(explode('/', rawurldecode((string)($parts['path'] ?? ''))), fn($s) => $s !== ''));
	$site = isset($segments[0], $segments[1]) && in_array(strtolower($segments[0]), [ 'sites', 'teams' ], true) ? '/' . $segments[0] . '/' . $segments[1] : '/';
	return [ $host, implode('/', array_map('rawurlencode', explode('/', $site))) ];
    }

    //the API path of an item given by id, path in the drive (/a/b.txt) or sharing link
    protected function itemRef($value, $what = 'file') {
	$v = trim((string)$value);
	if ($v === '')
	    throw new \RuntimeException("no {$what} given");
	if (str_starts_with($v, 'https://'))
	    return '/shares/' . self::shareToken($v) . '/driveItem';
	if (str_starts_with($v, '/')) {
	    $path = self::encodePath($v);
	    return $path === '' ? $this->drivePath . '/root' : $this->drivePath . '/root:' . $path . ':';
	}
	return $this->drivePath . '/items/' . self::graphId($v, $what);
    }

    //a folder's API path: empty - the top of the drive
    protected function folderRef($value) {
	return trim((string)$value) === '' ? $this->drivePath . '/root' : $this->itemRef($value, 'folder');
    }

    //{ id, driveId } of a folder (asked: a path or a link may point anywhere); \RuntimeException when it is a file
    protected function folderId($value) {
	$f = $this->api('GET', $this->folderRef($value), [ '$select' => 'id,name,folder,parentReference' ]);
	if (!isset($f['folder']))
	    throw new \RuntimeException('`' . ($f['name'] ?? $value) . '` is not a folder');
	return [ 'id' => (string)$f['id'], 'driveId' => (string)($f['parentReference']['driveId'] ?? '') ];
    }

    //a path in the drive, each name URL-encoded (/a b/c.txt -> /a%20b/c.txt); '' for the top
    public static function encodePath($path) {
	$names = array_values(array_filter(explode('/', (string)$path), fn($s) => $s !== ''));
	foreach($names as $n) {
	    if ($n === '.' || $n === '..')
		throw new \RuntimeException('not a path in the drive: `' . mb_substr((string)$path, 0, 100) . '`');
	}
	return $names ? '/' . implode('/', array_map('rawurlencode', $names)) : '';
    }

    //Graph's token of a sharing link: u! + base64url without padding
    public static function shareToken($url) {
	return 'u!' . rtrim(strtr(base64_encode((string)$url), '+/', '-_'), '=');
    }
}
