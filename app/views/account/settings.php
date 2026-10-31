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
    $labels = [ 'general' => 'Settings', 'usage' => 'Usage' ];
    $items = [];
    foreach($tabs as $t)
	$items[$t] = [ 'label' => $labels[$t], 'href' => \V::app()->createUrl('/account/settings', [ '__tab' => $t ]) ];
    echo (new \sys\html\Nav([ 'items' => $items, 'active' => $tab ]))->render();
    ?>
    <?php require(dirname(__FILE__) . '/_' . $tab . '.php'); ?>
</div>
