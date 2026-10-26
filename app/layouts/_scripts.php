<?php

//register some js before hook-call
$js_assets = [
    'app.pjax.js',
    'app.fieldhelper.js',
    'app.exprinput.js',
    'app.codeeditor.js',
    'app.formdesigner.js',
    'app.emaildesigner.js',
    'app.viewer.js',
    'app.overview.js',
    'app.smartform.js',
    'app.workflow.js',
    'app.wfexecutions.js',
    'app.wfdebug.js',
    //CLD: 2026-10-26T23:49:50+02:00: the designer's Assistant (was published by the Assistant plugin)
    'app.wfassistant.js',
    'app.dataview.js',
    //last
    'app.js',
];

foreach($js_assets as $asset) {
    $mtime = @filemtime(BASE . '/public_html/js/' . $asset);
    \V::app()->cs->addPageScriptFile('/js/' . $asset . '?' . $mtime, []);
}
