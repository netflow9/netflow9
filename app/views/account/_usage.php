<?php
//CLD: 2026-10-24T16:30:00+03:00: the numbers: util\Format::number (was number_format)
/*
    CLD: 2026-10-24T09:45:36+03:00: new: the account's usage (AccountController::settings, its Usage tab): $account, $usage (util\Tiers::usage), $limits (util\Tiers::limits), $projects ([ id => Project ]);
    each limit with what is used (yellow from 80%, red at the limit), the tier's features, the usage per project (a breakdown: the limits are the account's)
*/
use util\Tiers;

//CLD: 2026-10-31T11:32:07+02:00: the Usage tab of the account's settings (was the page account/usage; AccountController::settings): no page header of its own;
//the plan's badge only with the Admin plugin (its super-admins set the plans; without it there is no plan to show)
$showTier = in_array('Admin', (array)\V::app()->config('modules'), true);

//the rows of the limits: [ key, label, used, limit, unit ]
$rows = [];
foreach(Tiers::LIMITS as $key => [ $label, $unit ]) {
    if (isset($usage['totals'][$key]))
	$rows[] = [ $key, $label, $usage['totals'][$key], $limits[$key], $unit ];
    elseif (isset($usage['extra'][$key]))
	$rows[] = [ $key, $label, $usage['extra'][$key][0], $usage['extra'][$key][1], $unit ];
}
$recordsLimit = $limits['datatable_records'];
?>

    <?php //CLD: 2026-10-31T11:58:40+02:00: the plan and its text only with the Admin plugin (self-hosted without it: no plan to speak of) ?>
    <?php if ($showTier): ?>
    <div class="pt-4 mb-4">
	<span class="badge text-bg-primary align-middle me-1"><?= \V::app()->html->escape(Tiers::labelOf($account)) ?></span>
	<span class="text-muted">What the account uses of its plan's limits. The limits are for the whole account.</span>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4 <?= $showTier ? '' : 'pt-4' ?>" style="max-width: 960px">
	<?php foreach($rows as [ $key, $label, $used, $limit, $unit ]):
	    $pct = $limit === null ? null : ($limit > 0 ? min(100, round($used * 100 / $limit)) : 100);
	    $color = $pct === null ? 'bg-primary' : ($pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success'));
	?>
	<div class="col-12 col-md-6">
	    <div class="border rounded-3 p-3 h-100">
		<div class="small text-muted"><?= \V::app()->html->escape($label) ?></div>
		<div class="fs-5 fw-semibold"><?= \V::app()->html->escape(Tiers::format($used, $unit)) ?>
		    <span class="fs-6 fw-normal text-muted"><?= $limit === null ? '&middot; no limit' : 'of ' . \V::app()->html->escape(Tiers::format($limit, $unit)) ?></span></div>
		<?php if ($pct !== null): ?>
		<div class="progress mt-2" role="progressbar" aria-label="<?= \V::app()->html->escape($label) ?>" aria-valuenow="<?= (int)$pct ?>" aria-valuemin="0" aria-valuemax="100" style="height: 6px">
		    <div class="progress-bar <?= $color ?>" style="width: <?= (int)$pct ?>%"></div>
		</div>
		<?php endif; ?>
		<?php if ($key === 'datatable_records'): ?>
		<div class="form-text">The largest table<?= $usage['tables'] ? ': ' . \V::app()->html->escape($usage['tables'][0]['name']) : '' ?>; the limit is for each table.</div>
		<?php elseif ($key === 'ai_monthly_credits'): ?>
		<div class="form-text">This month; top-ups are on top of it.</div>
		<?php endif; ?>
	    </div>
	</div>
	<?php endforeach; ?>
    </div>

    <?php if ($features = Tiers::features()): ?>
    <h6>Features</h6>
    <ul class="list-unstyled small mb-4">
	<?php foreach($features as $f => $label): $on = Tiers::has($account, $f); ?>
	<li><i class="bi <?= $on ? 'bi-check-circle text-success' : 'bi-x-circle text-muted' ?> me-1" aria-hidden="true"></i><?= \V::app()->html->escape($label) ?><?= $on ? '' : ' <span class="text-muted">(not included)</span>' ?></li>
	<?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php
    //the tables close to their limit (80% and more)
    $close = $recordsLimit === null ? [] : array_values(array_filter($usage['tables'], fn($t) => $recordsLimit > 0 ? $t['rows'] * 100 / $recordsLimit >= 80 : true));
    if ($close): ?>
    <h6>Data tables close to the row limit</h6>
    <div class="table-responsive mb-4" style="max-width: 960px">
	<table class="table table-sm align-middle small">
	    <thead><tr><th>Table</th><th>Project</th><th class="text-end">Rows</th></tr></thead>
	    <tbody>
	    <?php foreach($close as $t): ?>
		<tr>
		    <td><?= \V::app()->html->escape($t['name']) ?></td>
		    <td><?= \V::app()->html->escape(isset($projects[$t['project']]) ? $projects[$t['project']]->name : '') ?></td>
		    <td class="text-end <?= $t['rows'] >= $recordsLimit ? 'text-danger' : 'text-warning-emphasis' ?>"><?= \util\Format::number($t['rows']) ?> / <?= \util\Format::number($recordsLimit) ?></td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>
    <?php endif; ?>

    <h6>Per project</h6>
    <div class="table-responsive" style="max-width: 960px">
	<table class="table table-sm align-middle small">
	    <thead><tr><th>Project</th><th class="text-end">Data tables</th><th class="text-end">Rows</th><th class="text-end">Files</th></tr></thead>
	    <tbody>
	    <?php
	    //the account's projects, then what belongs to none of them (a project removed)
	    $ids = array_unique(array_merge(array_keys($projects), array_keys($usage['projects'])));
	    foreach($ids as $pid):
		$u = $usage['projects'][$pid] ?? [ 'datatables' => 0, 'records' => 0, 'files_bytes' => 0 ];
		$p = $projects[$pid] ?? null;
	    ?>
		<tr>
		    <td><?= $p ? \V::app()->html->escape($p->name) . (!empty($p->personal) ? ' <span class="text-muted">(personal)</span>' : '') : '<span class="text-muted">(removed project)</span>' ?></td>
		    <td class="text-end"><?= \util\Format::number($u['datatables']) ?></td>
		    <td class="text-end"><?= \util\Format::number($u['records']) ?></td>
		    <td class="text-end"><?= \V::app()->html->escape(Tiers::format($u['files_bytes'], 'bytes')) ?></td>
		</tr>
	    <?php endforeach; ?>
	    </tbody>
	</table>
    </div>
