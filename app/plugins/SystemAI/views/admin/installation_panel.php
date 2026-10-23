<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the Assistant in the administration's installation page (hook admin:installation-panels): $system (AssistantSettings::system: null = none),
    $table (AssistantWallet::rateTable): the System server, its credits and the credit rates per model with their margins (as ./v Assistant::credits::rates)
    CLD: 2026-10-23T10:00:00+03:00: the System AI's (plugins\SystemAI; $system: SystemAi::config, $table: SystemAiWallet::rateTable; as ./v SystemAI::credits::rates)
*/
use plugins\SystemAI\util\SystemAi;
use plugins\SystemAI\models\SystemAiWallet;

//CLD: 2026-10-23T01:30:00+03:00: \V::app()->html->escape() called directly (was a $e wrapper)
$unit = (float)$table['credit_usd'];
$models = array_keys((array)$table['models']);
if ($system)
    array_unshift($models, $system->model);
$num = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
?>
<h6 class="mb-2">System AI</h6>
<div class="small mb-2">
    <?php if ($system): ?>
    <?= \V::app()->html->escape(SystemAi::PROVIDERS[$system->provider] ?? $system->provider) ?>, model <code><?= \V::app()->html->escape($system->model) ?></code><?= $system->url !== '' ? ' at <code>' . \V::app()->html->escape($system->url) . '</code>' : '' ?>
    &middot; <?= SystemAi::monthlyCredits() ?> credits per account per month &middot; at most <?= SystemAi::maxCreditsPerRequest() ?: 'any' ?> per request (an Assistant request, an AI step's run) &middot; 1 credit = <?= \V::app()->html->escape($unit) ?> USD
    <?php else: ?>
    <span class="text-muted">No System AI (app.env SYSTEM_AI_PROVIDER, SYSTEM_AI_MODEL): accounts use their own keys.</span>
    <?php endif; ?>
</div>
<div class="table-responsive mb-4">
    <table class="table table-sm align-middle small">
	<thead><tr><th>Model</th><th class="text-end">Input</th><th class="text-end">Output</th><th class="text-end">Cache read</th><th class="text-end">Margin</th></tr></thead>
	<tbody>
	<?php foreach(array_unique($models) as $i => $model):
	    $s = $system && $model === $system->model ? $system : (object)[ 'model' => $model ];
	    $r = SystemAiWallet::ratesOf($s, $table);
	    $p = SystemAi::pricesOf($s);
	    $margin = $r && $p && $p[0] > 0 ? round(($r[0] * $unit / $p[0] - 1) * 100) . '%' : '';
	?>
	    <tr>
		<td><code><?= \V::app()->html->escape($model) ?></code><?= $system && $i === 0 ? ' <span class="badge text-bg-primary">System</span>' : '' ?></td>
		<?php if ($r): foreach([ 0, 1, 2 ] as $j): ?>
		<td class="text-end"><?= $num($r[$j]) ?> <span class="text-muted">/ <?= $p ? '$' . $num($p[$j]) : '?' ?></span></td>
		<?php endforeach; else: ?>
		<td colspan="3" class="text-end text-danger">no rates: set them in credit_rates.php</td>
		<?php endif; ?>
		<td class="text-end"><?= \V::app()->html->escape($margin) ?></td>
	    </tr>
	<?php endforeach; ?>
	</tbody>
    </table>
    <div class="form-text">Credits per 1M tokens / the provider's price (USD). Rates and margins: plugins/SystemAI/credit_rates.php</div>
</div>
