<?php
/*
    CLD: 2026-10-31T22:00:00+02:00: new (was in the core's views/assistant/settings.php): the System part of the Assistant's settings page (util\AssistantSystem::settingsHtml,
    plugins\MultiTenant\util\AssistantProvider): the System AI's model and rates, the account's credits left, its monthly limit for the Assistant (credit_cap).
    $settings (models\AssistantSettings), $system (SystemAi::config), $wallet (SystemAiWallet::current), $rates (SystemAiWallet::ratesOf)
*/
use models\AssistantSettings;
use plugins\MultiTenant\models\SystemAiWallet;
?>
		<div class="form-group row mb-2">
		    <div class="col-sm-9 offset-sm-3 small text-muted">
			<i class="bi bi-hdd-network me-1"></i>The Assistant runs on this installation's System AI: <?= \V::app()->html->escape(AssistantSettings::PROVIDERS[$system->provider]) ?>, model <code><?= \V::app()->html->escape($system->model) ?></code>.
			No key to set: it is paid with credits<?= $rates ? sprintf(' (%s input / %s output credits per 1M tokens)', rtrim(rtrim(number_format($rates[0], 2), '0'), '.'), rtrim(rtrim(number_format($rates[1], 2), '0'), '.')) : '' ?>.
		    </div>
		</div>

		<?php
		    //CLD: 2026-10-22T15:00:00+03:00: the credits: this month's allowance, the top-ups (the one that expires first is used first), the last entries
		    //CLD: 2026-10-23T10:00:00+03:00: the credits left; the rest on the System AI's page
		    $held = array_sum(array_column($wallet['holds'], 'credits'));
		?>
		<div class="form-group row mb-2">
		    <label class="col-sm-3 col-form-label">Credits</label>
		    <div class="col-sm-9 pt-2 small">
			<span class="fs-5 fw-semibold"><?= \util\Format::number(SystemAiWallet::balance($wallet)) ?></span> <span class="text-muted">credits left<?= $held ? ' (' . \util\Format::number($held) . ' reserved by running requests)' : '' ?></span>
			&middot; <a href="<?= \V::app()->createUrl('/MultiTenant/credits/index') ?>">AI credits</a>
			<div class="form-text">Shared with the AI steps that use the System AI. Used this month: <?= \util\Format::number($wallet['month_used']) ?> credits.</div>
		    </div>
		</div>
		<?= $settings->schema['credit_cap']->input([ 'cols' => '3,9', 'input' => [ 'inputmode' => 'numeric', 'placeholder' => 'No limit' ] ]) ?>
