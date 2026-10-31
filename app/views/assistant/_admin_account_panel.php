<?php
/*
    CLD: 2026-10-23T10:00:00+03:00: new: the account's Assistant in the administration (hook admin:account-panels; its System credits: plugins\MultiTenant's panel): $settings
    (AssistantSettings|null), $usage (AssistantUsage::current: this month on its own key)
*/
use models\AssistantSettings;

$provider = (string)($settings->provider ?? '');
?>
<h6>Assistant</h6>
<div class="small text-muted mb-4">
    Provider: <?= \V::app()->html->escape($provider !== '' ? (AssistantSettings::PROVIDERS[$provider] ?? $provider) : 'not set up') ?>
    <?php if ($provider !== '' && $provider !== 'system'): ?> &middot; this month <?= sprintf('%.2f USD', $usage->cost) ?>, <?= \util\Format::number((int)$usage->requests) ?> request(s) on its own key<?php endif; ?>
</div>
