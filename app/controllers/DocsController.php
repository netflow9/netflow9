<?php

/*
    the documentation inside the app, read from app/data/docs.json (the docs site's content: sections > pages > child pages).
    docs/index?s=<section>&p=<page>[&c=<child page>] shows one page (default: the first one);
    the pages' links (/<section>/<page>[/<child>]) are turned into this viewer's URLs.
    For signed-in users
*/

namespace controllers;

class DocsController extends \sys\BaseController {

    const FILE = BASE . '/app/data/docs.json';

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    public function index($req, $resp) {
	$doc = $this->load();

	$s = $this->slugParam('s');
	$p = $this->slugParam('p');
	$c = $this->slugParam('c');

	//the pages in reading order (a page, then its child pages): the current one, the previous and the next
	$pages = [];
	foreach ($doc['sections'] as $section) {
	    foreach ($section['pages'] as $page) {
		$pages[] = [ 'section' => $section, 'page' => $page, 'child' => null ];
		foreach ($page['pages'] ?? [] as $child)
		    $pages[] = [ 'section' => $section, 'page' => $page, 'child' => $child ];
	    }
	}
	if (!$pages)
	    \V::app()->error(404, 'No documentation');

	$at = 0;
	if ($s !== '') {
	    $at = null;
	    foreach ($pages as $i => $item) {
		if ($item['section']['id'] === $s && ($p === '' || $item['page']['id'] === $p) && ($item['child']['id'] ?? '') === $c) {
		    $at = $i;
		    break;
		}
	    }
	    if ($at === null)
		\V::app()->error(404, 'Page not found');
	}

	$current = $pages[$at];
	$prev = $pages[$at - 1] ?? null;
	$next = $pages[$at + 1] ?? null;
	[ $html, $toc ] = $this->prepare(($current['child'] ?? $current['page'])['html'] ?? '');

	$sections = $doc['sections'];
	$updated = $doc['updated'] ?? '';
	$this->render('index', compact('sections', 'current', 'prev', 'next', 'html', 'toc', 'updated'));
    }

    //the URL of a page ($child: a child page, or null)
    public static function pageUrl($section, $page = null, $child = null) {
	$params = [ 's' => is_array($section) ? $section['id'] : $section ];
	if ($page !== null)
	    $params['p'] = is_array($page) ? $page['id'] : $page;
	if ($child !== null)
	    $params['c'] = is_array($child) ? $child['id'] : $child;
	return \V::app()->createUrl('/docs/index', $params);
    }

    //docs.json, read once per request
    private function load() {
	static $doc = null;
	if ($doc !== null)
	    return $doc;

	$doc = is_file(self::FILE) ? json_decode((string)file_get_contents(self::FILE), true) : null;
	if (!is_array($doc) || !is_array($doc['sections'] ?? null))
	    \V::app()->error(404, 'No documentation');
	return $doc;
    }

    //a section / page id from the query string ('' when not given)
    private function slugParam($key) {
	$value = (string)(\V::app()->request->query[$key] ?? '');
	if ($value !== '' && !preg_match('/^[a-z0-9-]{1,80}$/i', $value))
	    \V::app()->error(400, "malformed request: $key");
	return $value;
    }

    //the page's HTML for the viewer: its links to other pages (/<section>/<page>[/<child>][#...]) are this viewer's URLs, its h2 / h3 get ids;
    //returns [ html, [ [ level, id, text ], ... ] ] (the headings: "On this page")
    private function prepare($html) {
	$html = preg_replace_callback('~href="/([a-z0-9-]+)(?:/([a-z0-9-]+))?(?:/([a-z0-9-]+))?(#[^"]*)?"~i', function($m) {
	    $url = self::pageUrl($m[1], ($m[2] ?? '') !== '' ? $m[2] : null, ($m[3] ?? '') !== '' ? $m[3] : null);
	    return 'href="' . \V::app()->html->escape($url . ($m[4] ?? '')) . '"';
	}, (string)$html);

	$toc = [];
	$used = [];
	$html = preg_replace_callback('~<h([23])>(.*?)</h\1>~s', function($m) use (&$toc, &$used) {
	    $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	    $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-') ?: 'section';
	    $base = $id;
	    for ($n = 2; isset($used[$id]); $n++)
		$id = $base . '-' . $n;
	    $used[$id] = true;
	    $toc[] = [ (int)$m[1], $id, $text ];
	    return "<h{$m[1]} id=\"{$id}\">{$m[2]}</h{$m[1]}>";
	}, $html);

	return [ $html, $toc ];
    }
}
