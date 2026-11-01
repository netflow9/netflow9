<?php
/*
    CLD: 2026-10-31T11:32:07+02:00: the account's settings, in tabs (AccountController::settings: $tab, $tabs): general (_general.php: the settings, $account) and usage (_usage.php:
    $usage, $limits, $projects; was the page account/usage)
*/
$this->pageTitle = 'Account::Settings';
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Account settings' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?php //CLD: 2026-10-31T11:51:30+02:00: as the project's settings (views/project/_header.php): the account's name, then the tabs (was a title and a description) ?>
    <div class="d-flex align-items-center w-100 align-items-top">
	<h5 class="mb-0"><i class="bi bi-house-gear"></i> <?= \V::app()->html->escape((string)$account->name !== '' ? (string)$account->name : 'Account settings') ?></h5>
	<div class="flex-fill"></div>
    </div>
    <?php
    //CLD: 2026-10-31T19:30:00+02:00: + Services (_services.php: $services; without the Admin plugin)
    //CLD: 2026-11-01T02:00:00+02:00: + Data access (_access.php: $projects)
    //CLD: 2026-11-01T11:55:18+02:00: + Tool servers (_tools.php: $servers, $open, $enabled; without the MultiTenant plugin)
    //CLD: 2026-11-01T16:11:37+02:00: + Inbound mail (_inbound.php: $settings, $domain; where Haraka is found)
    $labels = [ 'general' => 'Settings', 'access' => 'Data access', 'inbound' => 'Inbound mail', 'usage' => 'Usage', 'services' => 'Services', 'tools' => 'Tool servers' ];
    $items = [];
    foreach($tabs as $t)
	$items[$t] = [ 'label' => $labels[$t], 'href' => \V::app()->createUrl('/account/settings', [ '__tab' => $t ]) ];
    echo (new \sys\html\Nav([ 'items' => $items, 'active' => $tab ]))->render();
    ?>
    <?php require(dirname(__FILE__) . '/_' . $tab . '.php'); ?>
</div>
