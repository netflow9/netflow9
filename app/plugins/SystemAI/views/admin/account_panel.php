<?php
/*
    CLD: 2026-10-22T23:00:00+03:00: new: the account's Assistant in the administration (hook admin:account-panels): $account, $settings (AssistantSettings|null), $wallet (AssistantWallet::current),
    $ledger (the last entries), $usage (AssistantUsage::current), $system (AssistantSettings::system); the plan and a top-up (controllers/AdminController)
    CLD: 2026-10-23T10:00:00+03:00: the account's System AI credits (plugins\SystemAI; was the Assistant's panel): $account, $wallet (SystemAiWallet::current), $ledger, $system
    (SystemAi::config); the Assistant's provider and spending: its own panel (plugins/Assistant/views/admin/account_panel)
*/
use plugins\SystemAI\util\SystemAi;
use plugins\SystemAI\models\SystemAiWallet;
use plugins\SystemAI\models\SystemAiLedger;

//CLD: 2026-10-23T01:30:00+03:00: \V::app()->html->escape() called directly (was a $e wrapper)
$csrf = '<input type="hidden" name="' . \V::app()->csrf->csrf_key . '" value="' . \V::app()->html->escape(\V::app()->csrf->getToken(false)) . '">';
$aid = (string)$account->_id;
$held = array_sum(array_column($wallet['holds'], 'credits'));
$topups = $wallet['topups'];
usort($topups, fn($a, $b) => $b['at'] <=> $a['at']);
?>
<h6>System AI</h6>

<div class="border rounded-3 p-3 mb-4" style="max-width: 880px">
    <div class="d-flex flex-wrap align-items-baseline gap-3 mb-2">
	<div class="fs-5 fw-semibold"><?= number_format(SystemAiWallet::balance($wallet)) ?> <span class="fs-6 fw-normal text-muted">System credits</span></div>
	<div class="small text-muted">allowance <?= number_format($wallet['allowance_left']) ?> of <?= number_format($wallet['allowance']) ?> &middot; used this month <?= number_format($wallet['month_used']) ?><?= $held ? ' &middot; ' . number_format($held) . ' reserved' : '' ?><?= $system ? '' : ' &middot; <span class="text-warning-emphasis">the installation has no System AI</span>' ?></div>
    </div>

    <div class="row g-3">
	<div class="col-md-5">
	    <form method="post" action="<?= \V::app()->createUrl('/SystemAI/admin/plan') ?>" class="m-0">
		<?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($aid) ?>">
		<label class="form-label small mb-1" for="plan-credits">Monthly allowance <span class="text-muted">(empty: the tier's or the installation's, <?= (int)SystemAiWallet::planOf(array_merge($wallet, [ 'plan' => null ]), $aid) ?>)</span></label>
		<div class="d-flex gap-2">
		    <input id="plan-credits" type="number" min="0" step="1" name="credits" class="form-control form-control-sm" value="<?= $wallet['plan'] === null ? '' : (int)$wallet['plan'] ?>" placeholder="<?= (int)SystemAiWallet::planOf(array_merge($wallet, [ 'plan' => null ]), $aid) ?>">
		    <button type="submit" class="btn btn-sm btn-outline-secondary">Set</button>
		</div>
	    </form>
	</div>
	<div class="col-md-7">
	    <form method="post" action="<?= \V::app()->createUrl('/SystemAI/admin/grant') ?>" class="m-0">
		<?= $csrf ?><input type="hidden" name="id" value="<?= \V::app()->html->escape($aid) ?>">
		<label class="form-label small mb-1" for="topup-credits">Top-up <span class="text-muted">(valid <?= SystemAiWallet::TOPUP_MONTHS ?> months)</span></label>
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
	<span class="badge <?= $t['left'] > 0 && $t['expires'] > time() ? 'text-bg-light border' : 'text-bg-secondary' ?> fw-normal"><?= number_format($t['left']) ?>/<?= number_format($t['granted']) ?> until <?= gmdate('Y-m-d', $t['expires']) ?><?= $t['note'] !== '' ? ' &middot; ' . \V::app()->html->escape($t['note']) : '' ?></span>
	<?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($ledger): ?>
    <table class="table table-sm small mt-3 mb-0">
	<thead><tr><th>When (UTC)</th><th>What</th><th class="text-end">Credits</th><th class="text-end">Balance</th></tr></thead>
	<tbody>
	<?php foreach($ledger as $l): ?>
	    <tr>
		<td class="text-nowrap"><?= $l->created_at instanceof \MongoDB\BSON\UTCDateTime ? \V::app()->html->escape(gmdate('Y-m-d H:i', $l->created_at->toDateTime()->getTimestamp())) : '' ?></td>
		<td><?= \V::app()->html->escape(SystemAiLedger::TYPES[$l->type] ?? $l->type) ?><?= (string)$l->model !== '' ? ' <code>' . \V::app()->html->escape($l->model) . '</code>' : '' ?><?= (string)$l->note !== '' ? ' <span class="text-muted">' . \V::app()->html->escape($l->note) . '</span>' : '' ?></td>
		<td class="text-end <?= $l->credits < 0 ? 'text-danger' : 'text-success' ?>"><?= ($l->credits > 0 ? '+' : '') . number_format((float)$l->credits) ?></td>
		<td class="text-end"><?= number_format((float)$l->balance) ?></td>
	    </tr>
	<?php endforeach; ?>
	</tbody>
    </table>
    <?php endif; ?>
</div>
