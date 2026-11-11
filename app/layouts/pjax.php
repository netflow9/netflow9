<?php
\V::app()->response->addHeader('Content-Type', 'application/json');
echo \util\JSON::encode([
    'title' => $this->pageTitle ?? '',
    'content' => $content,
    'script' => \V::app()->cs->renderPageScripts() ?? '',
    'meta' => $this->meta ?? [],
]);
