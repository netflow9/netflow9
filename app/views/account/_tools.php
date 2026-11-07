<?php
/*
    the installation's tool servers, a tab of the account's settings without the MultiTenant plugin (for the owners; AccountController::settings:
    $servers, $open, $enabled: the account's switches)
*/
?>
<div class="mt-3">
    <?php require(dirname(__DIR__) . '/toolservers/_table.php'); ?>
</div>
