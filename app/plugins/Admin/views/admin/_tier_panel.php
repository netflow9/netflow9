<?php
/*
    an account's tier in the administration (account.php): $account, $usage (util\Tiers::usage), $csrf; the tier, the account's own limits
    (empty: the tier's) and features (the tier's / on / off), what it uses (AdminController::account_tier)
*/
use util\Tiers;

$table = Tiers::table();
$overrides = Tiers::overridesOf($account);
$tierDef = $table['tiers'][Tiers::tierOf($account)];
$limits = Tiers::limits($account);
?>
<h6>Tier &amp; limits</h6>

<div class="border rounded-3 p-3 mb-4" style="max-width: 880px">
    <form method="post" action="<?= \V::app()->createUrl('/Admin/admin/account_tier') ?>" class="m-0">
	<?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($account->_id) ?>">
	<div class="mb-3" style="max-width: 320px">
	    <label class="form-label small mb-1" for="tier-name">Tier</label>
	    <select id="tier-name" name="tier" class="form-select form-select-sm">
		<option value="">Default (<?= \V::app()->html->escape(Tiers::names()[$table['default']] ?? $table['default']) ?>)</option>
		<?php foreach(Tiers::names() as $name => $label): ?>
		<option value="<?= \V::app()->html->escape($name) ?>" <?= (string)$account->tier === $name ? 'selected' : '' ?>><?= \V::app()->html->escape($label) ?></option>
		<?php endforeach; ?>
	    </select>
	</div>

	<table class="table table-sm align-middle small">
	    <thead><tr><th>Limit</th><th class="text-end">Used</th><th class="text-end">Tier's</th><th style="width: 200px">This account's</th></tr></thead>
	    <tbody>
	    <?php foreach(Tiers::LIMITS as $key => [ $label, $unit ]):
		$used = $usage['totals'][$key] ?? ($usage['extra'][$key][0] ?? null);
		$tierValue = $tierDef['limits'][$key] ?? null;
		$own = array_key_exists($key, $overrides['limits']) ? ($overrides['limits'][$key] === null ? 'unlimited' : ($unit === 'bytes' ? (string)intdiv((int)$overrides['limits'][$key], 1024 * 1024) : (string)(int)$overrides['limits'][$key])) : '';
		$over = $used !== null && $limits[$key] !== null && $key !== 'ai_monthly_credits' && $used >= $limits[$key];
	    ?>
		<tr>
		    <td><?= \V::app()->html->escape($label) ?><?= $unit === 'bytes' ? ' <span class="text-muted">(MB)</span>' : '' ?></td>
		    <td class="text-end <?= $over ? 'text-danger' : '' ?>"><?= $used === null ? '<span class="text-muted">&ndash;</span>' : \V::app()->html->escape(Tiers::format($used, $unit)) ?></td>
		    <td class="text-end text-muted"><?= \V::app()->html->escape($tierValue === null ? ($key === 'ai_monthly_credits' ? "the installation's" : 'no limit') : Tiers::format($tierValue, $unit)) ?></td>
		    <td><input type="text" name="limits[<?= \V::app()->html->escape($key) ?>]" value="<?= \V::app()->html->escape($own) ?>" class="form-control form-control-sm" placeholder="the tier's" aria-label="<?= \V::app()->html->escape($label) ?>"></td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
	<div class="form-text mb-3">Empty: the tier's; <code>unlimited</code>; or a whole number (files in MB). Rows: the largest table, the limit is for each table. A System AI allowance set in its own panel goes before these.</div>

	<button type="submit" class="btn btn-sm btn-primary">Save</button>
    </form>
</div>
