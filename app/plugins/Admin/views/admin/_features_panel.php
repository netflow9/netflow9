<?php
/*
    CLD: 2026-10-24T10:06:05+03:00: new: an account's features in the administration (account.php; were in _tier_panel.php), as the tool servers after them (hook
    admin:account-features): $account, $csrf; each: the tier's, on or off for this account (AdminController::account_feature)
*/
use util\Tiers;

$features = Tiers::features();
$overrides = Tiers::overridesOf($account)['features'];
$tierFeatures = (array)Tiers::table()['tiers'][Tiers::tierOf($account)]['features'];
if (!$features)
    return;
?>
<h6>Features <span class="text-muted fw-normal">(<?= \V::app()->html->escape(Tiers::labelOf($account)) ?> tier)</span></h6>
<div class="table-responsive mb-4">
    <table class="table table-sm align-middle small">
	<thead><tr><th>Feature</th><th>The tier's</th><th class="text-end">For this account</th></tr></thead>
	<tbody>
	<?php foreach($features as $key => $label):
	    $own = array_key_exists($key, $overrides) ? ($overrides[$key] ? '1' : '0') : '';
	    $inTier = in_array($key, $tierFeatures, true);
	    $on = Tiers::has($account, $key);
	?>
	    <tr>
		<td class="fw-semibold"><?= \V::app()->html->escape($label) ?></td>
		<td class="text-muted"><?= $inTier ? 'included' : 'not included' ?></td>
		<td class="text-end">
		    <form method="post" action="<?= \V::app()->createUrl('/Admin/admin/account_feature') ?>" class="d-inline">
			<?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($account->_id) ?>"><input type="hidden" name="feature" value="<?= \V::app()->html->escape($key) ?>">
			<div class="btn-group btn-group-sm" role="group" aria-label="<?= \V::app()->html->escape($label) ?>">
			    <button type="submit" name="value" value="" class="btn <?= $own === '' ? 'btn-secondary' : 'btn-outline-secondary' ?>" aria-pressed="<?= $own === '' ? 'true' : 'false' ?>">The tier's</button>
			    <button type="submit" name="value" value="1" class="btn <?= $own === '1' ? 'btn-success' : 'btn-outline-secondary' ?>" aria-pressed="<?= $own === '1' ? 'true' : 'false' ?>">On</button>
			    <button type="submit" name="value" value="0" class="btn <?= $own === '0' ? 'btn-danger' : 'btn-outline-secondary' ?>" aria-pressed="<?= $own === '0' ? 'true' : 'false' ?>">Off</button>
			</div>
		    </form>
		    <span class="badge <?= $on ? 'text-bg-success' : 'text-bg-light border' ?> ms-2" style="min-width: 3rem"><?= $on ? 'on' : 'off' ?></span>
		</td>
	    </tr>
	<?php endforeach; ?>
	</tbody>
    </table>
</div>
