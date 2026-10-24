<?php
//CLD: 2026-10-24T16:30:00+03:00: the numbers: util\Format::number (was number_format); the dates in the account's formats (util\Format::date / datetime; were Y-m-d / Y-m-d H:i, UTC)
/*
    CLD: 2026-10-23T10:00:00+03:00: new: the account's System AI credits (CreditsController::index; were on the Assistant's settings page): $system (SystemAi::config: null = none),
    $rates (its model's credits per 1M tokens), $wallet (SystemAiWallet::current: this month's allowance, the top-ups and their expiry), $ledger (the last entries),
    $workflows ([ id => name ]: those of the entries)
*/
use plugins\SystemAI\util\SystemAi;
use plugins\SystemAI\models\SystemAiWallet;
use plugins\SystemAI\models\SystemAiLedger;

$this->pageTitle = 'AI credits';

$num = fn($n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
$held = array_sum(array_column($wallet['holds'], 'credits'));
$topups = array_values(array_filter($wallet['topups'], fn($t) => $t['left'] > 0));
usort($topups, fn($a, $b) => $a['expires'] <=> $b['expires']);
$pctA = $wallet['allowance'] > 0 ? min(100, round(($wallet['allowance'] - $wallet['allowance_left']) / $wallet['allowance'] * 100)) : 100;
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'AI credits' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <div>
	<h5>AI credits</h5>
	<span class="text-muted">The System AI this installation provides, paid with the account's credits: by the Assistant (provider System) and by the AI steps whose Credential is System AI</span>
    </div>

    <div class="pt-4" style="max-width: 880px">
	<div class="form-group row mb-3">
	    <label class="col-sm-3 col-form-label">System AI</label>
	    <div class="col-sm-9 pt-2 small">
		<?php if ($system): ?>
		<i class="bi bi-hdd-network me-1"></i><?= \V::app()->html->escape(SystemAi::PROVIDERS[$system->provider]) ?>, model <code><?= \V::app()->html->escape($system->model) ?></code>
		<?= $rates ? sprintf('&middot; %s input / %s output credits per 1M tokens', $num($rates[0]), $num($rates[1])) : '' ?>
		<div class="form-text">The AI steps of <?= \V::app()->html->escape(SystemAi::PROVIDERS[$system->provider]) ?> may use it (Credential: System AI), with this model or another one of the provider this installation prices.
		    A request reserves <?= SystemAi::maxCreditsPerRequest() ? 'at most ' . SystemAi::maxCreditsPerRequest() . ' credits' : 'the credits' ?> while it runs and is charged what it used (whole credits, at least 1).</div>
		<?php else: ?>
		<i class="bi bi-exclamation-triangle me-1"></i>This installation provides no System AI: the Assistant and the AI steps use your own keys.
		<?php endif; ?>
	    </div>
	</div>

	<div class="form-group row mb-3">
	    <label class="col-sm-3 col-form-label">Credits</label>
	    <div class="col-sm-9 pt-2 small">
		<div class="fs-5 fw-semibold"><?= \util\Format::number(SystemAiWallet::balance($wallet)) ?> <span class="fs-6 fw-normal text-muted">credits left<?= $held ? ' (' . \util\Format::number($held) . ' reserved by running requests)' : '' ?></span></div>
		<div class="mt-2">Monthly allowance: <?= \util\Format::number($wallet['allowance_left']) ?> of <?= \util\Format::number($wallet['allowance']) ?> left, renewed on <?= gmdate('F 1', strtotime('first day of next month')) ?> (what is left is not carried over)</div>
		<div class="progress mt-1" style="height: 6px" role="progressbar" aria-label="Allowance used" aria-valuenow="<?= $pctA ?>" aria-valuemin="0" aria-valuemax="100">
		    <div class="progress-bar <?= $pctA >= 100 ? 'bg-danger' : ($pctA >= 80 ? 'bg-warning' : '') ?>" style="width: <?= $pctA ?>%"></div>
		</div>
		<div class="mt-2">Top-ups: <?= $topups ? '' : 'none' ?></div>
		<?php if ($topups): ?>
		<ul class="ps-3 mb-0">
		    <?php foreach($topups as $t): ?>
		    <li><?= \util\Format::number($t['left']) ?> of <?= \util\Format::number($t['granted']) ?>, until <?= \V::app()->html->escape(\util\Format::date($t['expires'])) ?><?= $t['note'] !== '' ? ' &middot; ' . \V::app()->html->escape($t['note']) : '' ?></li>
		    <?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<div class="form-text">Used this month: <?= \util\Format::number($wallet['month_used']) ?> credits. The allowance is used first, then the top-up that expires first. Top-ups last <?= SystemAiWallet::TOPUP_MONTHS ?> months.</div>
	    </div>
	</div>

	<?php if ($ledger): ?>
	<div class="form-group row mb-2">
	    <label class="col-sm-3 col-form-label">Last changes</label>
	    <div class="col-sm-9">
		<div class="table-responsive">
		<table class="table table-sm small mb-0">
		    <thead><tr><th>When (UTC)</th><th>What</th><th class="text-end">Credits</th><th class="text-end">Balance</th></tr></thead>
		    <tbody>
		    <?php foreach($ledger as $e):
			$wfid = (string)$e->wfid;
		    ?>
			<tr>
			    <td class="text-nowrap"><?= \V::app()->html->escape(\util\Format::datetime($e->created_at)) ?></td>
			    <td>
				<?= \V::app()->html->escape(SystemAiLedger::TYPES[$e->type] ?? $e->type) ?>
				<?= (string)$e->model !== '' ? ' <code>' . \V::app()->html->escape($e->model) . '</code>' : '' ?>
				<?php if ((string)$e->note !== '' || $wfid !== ''): ?>
				<div class="text-muted">
				    <?= \V::app()->html->escape($e->note) ?>
				    <?php if (isset($workflows[$wfid])): ?>
				    &middot; <a href="<?= \V::app()->createUrl('/workflow/index', [ 'id' => $wfid ]) ?>"><?= \V::app()->html->escape($workflows[$wfid]) ?></a>
				    <?php endif; ?>
				</div>
				<?php endif; ?>
			    </td>
			    <td class="text-end <?= $e->credits < 0 ? 'text-danger' : 'text-success' ?>"><?= ($e->credits > 0 ? '+' : '') . \util\Format::number((float)$e->credits) ?></td>
			    <td class="text-end"><?= \util\Format::number((float)$e->balance) ?></td>
			</tr>
		    <?php endforeach; ?>
		    </tbody>
		</table>
		</div>
	    </div>
	</div>
	<?php endif; ?>
    </div>
</div>
