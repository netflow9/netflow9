<?php
/*
    the account's settings, in tabs (AccountController::settings: $tab, $tabs):
	general (_general.php: the settings, $account)
	and usage (_usage.php: $usage, $limits, $projects)
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
    <div class="d-flex align-items-center w-100 align-items-top">
	<h5 class="mb-0"><i class="bi bi-house-gear"></i> <?= \V::app()->html->escape((string)$account->name !== '' ? (string)$account->name : 'Account settings') ?></h5>
	<div class="flex-fill"></div>
    </div>
    <?php
    //CLD: 2026-11-02T10:00:00+02:00: the tabs besides Settings and Usage: Services (_services.php: $services) and Tool servers (_tools.php: $servers, $open, $enabled)
    //without the MultiTenant plugin; Data access (_access.php: $projects); Inbound mail (_inbound.php: $settings, $domain; where Haraka is found); AI (_ai.php:
    //$policy, $spent; the owners)
    $labels = [
	'general' => 'Settings',
	'access' => 'Data access',
	'ai' => 'AI',
	'inbound' => 'Inbound mail',
	'usage' => 'Usage',
	'services' => 'Services',
	'tools' => 'Tool servers'
    ];
    $items = [];
    foreach($tabs as $t)
	$items[$t] = [ 'label' => $labels[$t], 'href' => \V::app()->createUrl('/account/settings', [ '__tab' => $t ]) ];
    echo (new \sys\html\Nav([ 'items' => $items, 'active' => $tab ]))->render();
    ?>
    <?php require(dirname(__FILE__) . '/_' . $tab . '.php'); ?>
</div>
