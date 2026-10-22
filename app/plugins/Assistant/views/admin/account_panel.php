<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the account's Assistant in the administration (hook admin:account-panels): $account, $settings (AssistantSettings|null), $wallet (AssistantWallet::current),
    $ledger (the last entries), $usage (AssistantUsage::current), $system (AssistantSettings::system); the plan and a top-up (controllers/AdminController)
*/
use plugins\Assistant\models\AssistantSettings;
use plugins\Assistant\models\AssistantWallet;
use plugins\Assistant\models\AssistantCreditLedger;

$e = fn($v) => \V::app()->html->escape((string)$v);
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . $e(\V::app()->csrf->getToken(false)) . '">';
$aid = (string)$account->_id;
$provider = (string)($settings->provider ?? '');
$held = array_sum(array_column($wallet['holds'], 'credits'));
$topups = $wallet['topups'];
usort($topups, fn($a, $b) => $b['at'] <=> $a['at']);
?>
<h6>Assistant</h6>
<div class="small text-muted mb-2">
    Provider: <?= $e($provider !== '' ? (AssistantSettings::PROVIDERS[$provider] ?? $provider) : 'not set up') ?>
    <?php if ($provider !== '' && $provider !== 'system'): ?> &middot; this month <?= sprintf('%.2f USD', $usage->cost) ?>, <?= number_format($usage->requests) ?> request(s) on its own key<?php endif; ?>
</div>

<div class="border rounded-3 p-3 mb-4" style="max-width: 880px">
    <div class="d-flex flex-wrap align-items-baseline gap-3 mb-2">
	<div class="fs-5 fw-semibold"><?= number_format(AssistantWallet::balance($wallet)) ?> <span class="fs-6 fw-normal text-muted">System credits</span></div>
	<div class="small text-muted">allowance <?= number_format($wallet['allowance_left']) ?> of <?= number_format($wallet['allowance']) ?> &middot; used this month <?= number_format($wallet['month_used']) ?><?= $held ? ' &middot; ' . number_format($held) . ' reserved' : '' ?><?= $system ? '' : ' &middot; <span class="text-warning-emphasis">the installation has no System server</span>' ?></div>
    </div>

    <div class="row g-3">
	<div class="col-md-5">
	    <form method="post" action="<?= \V::app()->createUrl('/Assistant/admin/plan') ?>" class="m-0">
		<?= $csrf ?><input type="hidden" name="id" value="<?= $e($aid) ?>">
		<label class="form-label small mb-1" for="plan-credits">Monthly allowance <span class="text-muted">(empty: the installation's, <?= (int)(\V::app()->config('assistant.system')['monthly_credits'] ?? 0) ?>)</span></label>
		<div class="d-flex gap-2">
		    <input id="plan-credits" type="number" min="0" step="1" name="credits" class="form-control form-control-sm" value="<?= $wallet['plan'] === null ? '' : (int)$wallet['plan'] ?>" placeholder="<?= (int)AssistantWallet::planOf(array_merge($wallet, [ 'plan' => null ])) ?>">
		    <button type="submit" class="btn btn-sm btn-outline-secondary">Set</button>
		</div>
	    </form>
	</div>
	<div class="col-md-7">
	    <form method="post" action="<?= \V::app()->createUrl('/Assistant/admin/grant') ?>" class="m-0">
		<?= $csrf ?><input type="hidden" name="id" value="<?= $e($aid) ?>">
		<label class="form-label small mb-1" for="topup-credits">Top-up <span class="text-muted">(valid <?= AssistantWallet::TOPUP_MONTHS ?> months)</span></label>
		<div class="d-flex gap-2">
		    <input id="topup-credits" type="number" min="1" step="1" name="credits" class="form-control form-control-sm" style="max-width: 120px" placeholder="Credits" required>
		    <input type="text" name="note" maxlength="200" class="form-control form-control-sm" placeholder="Note, e.g. invoice 123">
		    <button type="submit" class="btn btn-sm btn-primary text-nowrap">Add credits</button>
		</div>
	    </form>
	</div>
    </div>

    <?php if ($topups): ?>
    <div class="small mt-3">Top-ups:
	<?php foreach($topups as $t): ?>
	<span class="badge <?= $t['left'] > 0 && $t['expires'] > time() ? 'text-bg-light border' : 'text-bg-secondary' ?> fw-normal"><?= number_format($t['left']) ?>/<?= number_format($t['granted']) ?> until <?= gmdate('Y-m-d', $t['expires']) ?><?= $t['note'] !== '' ? ' &middot; ' . $e($t['note']) : '' ?></span>
	<?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($ledger): ?>
    <table class="table table-sm small mt-3 mb-0">
	<thead><tr><th>When (UTC)</th><th>What</th><th class="text-end">Credits</th><th class="text-end">Balance</th></tr></thead>
	<tbody>
	<?php foreach($ledger as $l): ?>
	    <tr>
		<td class="text-nowrap"><?= $l->created_at instanceof \MongoDB\BSON\UTCDateTime ? $e(gmdate('Y-m-d H:i', $l->created_at->toDateTime()->getTimestamp())) : '' ?></td>
		<td><?= $e(AssistantCreditLedger::TYPES[$l->type] ?? $l->type) ?><?= (string)$l->note !== '' ? ' <span class="text-muted">' . $e($l->note) . '</span>' : '' ?></td>
		<td class="text-end <?= $l->credits < 0 ? 'text-danger' : 'text-success' ?>"><?= ($l->credits > 0 ? '+' : '') . number_format((float)$l->credits) ?></td>
		<td class="text-end"><?= number_format((float)$l->balance) ?></td>
	    </tr>
	<?php endforeach; ?>
	</tbody>
    </table>
    <?php endif; ?>
</div>
