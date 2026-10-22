<?php
\V::app()->response->addHeader('Content-Type', 'application/json');
//CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
echo \util\JSON::encode([
    'title' => $this->pageTitle ?? '',
    'content' => $content,
    'script' => \V::app()->cs->renderPageScripts() ?? '',
    'meta' => $this->meta ?? [],
]);
