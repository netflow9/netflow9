<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the Assistant in the administration's installation page (hook admin:installation-panels): $system (AssistantSettings::system: null = none),
    $table (AssistantWallet::rateTable): the System server, its credits and the credit rates per model with their margins (as ./v Assistant::credits::rates)
*/
use plugins\Assistant\models\AssistantSettings;
use plugins\Assistant\models\AssistantWallet;

$e = fn($v) => \V::app()->html->escape((string)$v);
$unit = (float)$table['credit_usd'];
$models = array_keys((array)$table['models']);
if ($system)
    array_unshift($models, $system->model);
$num = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
$cfg = (array)\V::app()->config('assistant.system');
?>
<h6 class="mb-2">Assistant (System)</h6>
<div class="small mb-2">
    <?php if ($system): ?>
    <?= $e(AssistantSettings::PROVIDERS[$system->provider] ?? $system->provider) ?>, model <code><?= $e($system->model) ?></code><?= $system->url !== '' ? ' at <code>' . $e($system->url) . '</code>' : '' ?>
    &middot; <?= (int)($cfg['monthly_credits'] ?? 0) ?> credits per account per month &middot; at most <?= (int)($cfg['max_credits_per_request'] ?? 0) ?: 'any' ?> per request &middot; 1 credit = <?= $e($unit) ?> USD
    <?php else: ?>
    <span class="text-muted">No System server (app.env ASSISTANT_PROVIDER, ASSISTANT_MODEL): accounts use their own keys.</span>
    <?php endif; ?>
</div>
<div class="table-responsive mb-4">
    <table class="table table-sm align-middle small">
	<thead><tr><th>Model</th><th class="text-end">Input</th><th class="text-end">Output</th><th class="text-end">Cache read</th><th class="text-end">Margin</th></tr></thead>
	<tbody>
	<?php foreach(array_unique($models) as $i => $model):
	    $s = $system && $model === $system->model ? $system : (object)[ 'model' => $model ];
	    $r = AssistantWallet::ratesOf($s, $table);
	    $p = AssistantSettings::pricesOf($s);
	    $margin = $r && $p && $p[0] > 0 ? round(($r[0] * $unit / $p[0] - 1) * 100) . '%' : '';
	?>
	    <tr>
		<td><code><?= $e($model) ?></code><?= $system && $i === 0 ? ' <span class="badge text-bg-primary">System</span>' : '' ?></td>
		<?php if ($r): foreach([ 0, 1, 2 ] as $j): ?>
		<td class="text-end"><?= $num($r[$j]) ?> <span class="text-muted">/ <?= $p ? '$' . $num($p[$j]) : '?' ?></span></td>
		<?php endforeach; else: ?>
		<td colspan="3" class="text-end text-danger">no rates: set them in credit_rates.php</td>
		<?php endif; ?>
		<td class="text-end"><?= $e($margin) ?></td>
	    </tr>
	<?php endforeach; ?>
	</tbody>
    </table>
    <div class="form-text">Credits per 1M tokens / the provider's price (USD). Rates and margins: plugins/Assistant/credit_rates.php</div>
</div>
