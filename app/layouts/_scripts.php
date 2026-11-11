<?php

//register some js before hook-call
$js_assets = [
    'app.formatter.js',
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
    //the designer's Tests panel
    'app.wftests.js',
    'app.wfassistant.js',
    'app.dataview.js',
    //the chat pages of the agents
    'app.agentchat.js',
    //last
    'app.js',
];

foreach($js_assets as $asset) {
    $mtime = @filemtime(BASE . '/public_html/js/' . $asset);
    \V::app()->cs->addPageScriptFile('/js/' . $asset . '?' . $mtime, []);
}
