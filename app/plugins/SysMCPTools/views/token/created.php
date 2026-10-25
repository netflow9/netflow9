<?php
//CLD: 2026-10-12T21:51:51+03:00: new view: the token just made, shown once (modal), with the server URL and a client configuration to copy
//CLD: 2026-10-18T23:04:55+03:00: absolute url from V::createAbsoluteUrl (app.env BASE_URL, always https://); was built from the request host
$url = \V::app()->createAbsoluteUrl('/mcp');
$config = json_encode([ 'mcpServers' => [ 'netflow9' => [ 'type' => 'http', 'url' => $url, 'headers' => [ 'Authorization' => 'Bearer ' . $secret ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$copy = fn($id) => "<button type='button' class='btn btn-sm btn-outline-secondary' onclick=\"navigator.clipboard.writeText(document.getElementById('{$id}').textContent); this.innerHTML='<i class=\\'bi bi-check2\\'></i>'\" title='Copy'><i class='bi bi-clipboard'></i></button>";

$body = "
<div class='alert alert-warning py-2'><i class='bi bi-exclamation-triangle me-1'></i>Copy the token now: it is not shown again.</div>
<label class='form-label mb-1'>Token <span class='text-muted'>(" . \V::app()->html->escape($token->name) . ")</span></label>
<div class='d-flex gap-2 align-items-center mb-3'><code id='mcp-token-value' class='flex-fill p-2 border rounded text-break user-select-all'>" . \V::app()->html->escape($secret) . "</code>" . $copy('mcp-token-value') . "</div>
<label class='form-label mb-1'>Server URL</label>
<div class='d-flex gap-2 align-items-center mb-3'><code id='mcp-token-url' class='flex-fill p-2 border rounded'>" . \V::app()->html->escape($url) . "</code>" . $copy('mcp-token-url') . "</div>
<label class='form-label mb-1'>Client configuration <span class='text-muted'>(e.g. Claude Code <code>.mcp.json</code>)</span></label>
<div class='d-flex gap-2 align-items-start'><pre id='mcp-token-config' class='flex-fill p-2 border rounded mb-0 small' style='white-space: pre-wrap; word-break: break-all'>" . \V::app()->html->escape($config) . "</pre>" . $copy('mcp-token-config') . "</div>
";

$modal = new \sys\html\Modal([
    'title' => 'Token created',
    'body' => $body,
    'footer' => "<button type='button' class='btn btn-primary' data-bs-dismiss='modal'>Done</button>",
]);
echo $modal->render();
