<?php
/*
    the Assistant's settings page (AssistantController::settings;
    Only the settings of the chosen provider are shown (all are saved)
    //CLD: 2026-10-20T10:00:00+03:00: one server for both providers (Server URL, API key), then Connect (AssistantController::connect): when it answers, the models it lists
    //are offered in Model; Effort for Claude only. The saved model is shown until the next Connect (the page connects by itself when there is one)
    //CLD: 2026-10-22T14:00:00+03:00: bring your own key: System (the installation's server: nothing to set; offered only when there is one: $system), Claude, OpenAI, Gemini, Ollama,
    //Lemonade (the Server URL's placeholder is the provider's own). + the monthly budget, the prices (not for System) and this month's spending ($usage)
    //CLD: 2026-10-22T15:00:00+03:00: System is paid in credits: the wallet ($wallet: this month's allowance, the top-ups and their expiry), the model's rates ($rates), the last
    //entries ($ledger), the account's monthly limit (credit_cap). The budget and the prices are for the other providers only
*/
use plugins\Assistant\models\AssistantSettings;
use plugins\Assistant\models\AssistantWallet;
use plugins\Assistant\models\AssistantCreditLedger;

$this->pageTitle = 'Assistant settings';

$model = (string)$settings->model;
//CLD: 2026-10-22T14:00:00+03:00: + system (offered), urls (the providers' own), the connected model is the saved one only for the saved provider
$connectJs = \util\JSON::encode([ 'url' => \V::app()->createUrl('/Assistant/assistant/connect'), 'auto' => $model !== '' && (string)$settings->provider !== 'system',
    'system' => $system !== null, 'urls' => AssistantSettings::DEFAULT_URLS ]);
$budget = AssistantSettings::budgetOf($settings);
//CLD: 2026-10-23T01:30:00+03:00: \V::app()->html->escape() called directly (was a $esc wrapper)

//CLD: 2026-10-20T10:00:00+03:00: nowdoc: the script's text as is
\V::app()->cs->addPageScript("+function(o) {" . <<<'JS'
    const form = document.getElementById('assistant-settings');
    if (!form)
	return;
    const provider = form.querySelector('[name=provider]');
    const select = form.querySelector('#assistant-model');
    const btn = form.querySelector('.btn-assistant-connect');
    const status = form.querySelector('.assistant-connect-status');
    const esc = (v) => app.utils.escapeHtml(String(v ?? ''));

    //the settings of the chosen provider only
    //CLD: 2026-10-22T14:00:00+03:00: data-assistant-provider: the providers it is for (space-separated; was one); the Server URL shows the provider's own
    const urlInput = form.querySelector('[name=url]');
    const show = () => {
	form.querySelectorAll('[data-assistant-provider]').forEach((el) => el.classList.toggle('d-none', !el.dataset.assistantProvider.split(' ').includes(provider.value)));
	urlInput.placeholder = o.urls[provider.value] || '';
    };
    //CLD: 2026-10-22T14:00:00+03:00: System only when the installation provides a server
    const sysOption = provider.querySelector('option[value=system]');
    if (sysOption && !o.system) {
	sysOption.disabled = true;
	sysOption.textContent += ' (not available)';
    }

    const setStatus = (cls, html) => {
	status.className = 'assistant-connect-status small ' + cls;
	status.innerHTML = html;
    };

    //the models offered: the server's, the current one chosen when it is among them (else the first)
    const setModels = (models) => {
	const current = select.value;
	select.innerHTML = models.map((m) => `<option value="${esc(m.id)}">${esc(m.label)}</option>`).join('');
	select.value = models.some((m) => m.id === current) ? current : models[0].id;
    };

    //another provider: the models listed no longer apply (saved so, the Assistant is off until a model is chosen)
    const disconnected = () => {
	select.innerHTML = '<option value="">Connect to list the models</option>';
	setStatus('text-muted', 'Not connected');
    };

    const connect = () => {
	btn.disabled = true;
	setStatus('text-muted', '<span class="spinner-border spinner-border-sm me-1"></span>Connecting&hellip;');
	$.ajax({
	    url: o.url,
	    method: 'POST',
	    dataType: 'json',
	    headers: { 'X-Csrf-Token': $('meta[name=csrf-token]').attr('content') },
	    data: { provider: provider.value, url: form.querySelector('[name=url]').value, api_key: form.querySelector('[name=api_key]').value },
	}).done((r) => {
	    setModels(r.models || []);
	    setStatus('text-success', `<i class="bi bi-check-circle me-1"></i>Connected: ${(r.models || []).length} model(s). Choose one, then Save.`);
	}).fail((x, s) => {
	    setStatus('text-danger', '<i class="bi bi-exclamation-triangle me-1"></i>' + esc((x.responseJSON && x.responseJSON.error) || 'Could not connect (' + (x.status || s) + ')'));
	}).always(() => {
	    btn.disabled = false;
	});
    };

    provider.addEventListener('change', () => {
	show();
	disconnected();
	//CLD: 2026-10-22T14:00:00+03:00: the saved key is not kept for another provider (AssistantController::settings)
	form.querySelector('[name=api_key]').value = '';
    });
    form.querySelectorAll('[name=url], [name=api_key]').forEach((el) => el.addEventListener('input', () => setStatus('text-muted', 'Changed: Connect again to check')));
    btn.addEventListener('click', (e) => {
	e.preventDefault();
	connect();
    });
    show();
    if (o.auto)
	connect();
JS . "}({$connectJs});");
?>

<div class="main-header py-2 px-3">
    <?php
    $exp = new \sys\html\BtnExpandSidebar();
    echo $exp->render();

    $bc = new \sys\html\Breadcrumbs([ 'items' => [
	[ 'label' => 'Home', 'href' => '/site/index' ],
	[ 'label' => 'Assistant settings' ],
    ]]);
    echo $bc->render();
    ?>
</div>

<div class="main-body p-4">
    <?= \V::app()->form->create($settings, [ 'id' => 'assistant-settings' ]) ?>
	<!-- header -->
	<div class="d-flex align-items-center w-100 align-items-top">
	    <div>
		<h5>Assistant settings</h5>
		<span class="text-muted">Who builds workflows from your descriptions in the workflow designer, for every project of the account</span>
	    </div>
	    <div class="flex-fill"></div>
	</div>

	<div class="pt-4 w-50">
	    <?= $settings->schema['provider']->input([ 'cols' => '3,9' ]) ?>

	    <?php //CLD: 2026-10-22T14:00:00+03:00: System: what the installation provides ?>
	    <div data-assistant-provider="system">
		<div class="form-group row mb-2">
		    <div class="col-sm-9 offset-sm-3 small text-muted">
			<?php if ($system): ?>
			<i class="bi bi-hdd-network me-1"></i>The Assistant runs on this installation's server: <?= \V::app()->html->escape(AssistantSettings::PROVIDERS[$system->provider]) ?>, model <code><?= \V::app()->html->escape($system->model) ?></code>.
			No key to set: it is paid with credits<?= $rates ? sprintf(' (%s input / %s output credits per 1M tokens)', rtrim(rtrim(number_format($rates[0], 2), '0'), '.'), rtrim(rtrim(number_format($rates[1], 2), '0'), '.')) : '' ?>.
			<?php else: ?>
			<i class="bi bi-exclamation-triangle me-1"></i>This installation provides no Assistant server: choose a provider and set your own key.
			<?php endif; ?>
		    </div>
		</div>

		<?php if ($system):
		    //CLD: 2026-10-22T15:00:00+03:00: the credits: this month's allowance, the top-ups (the one that expires first is used first), the last entries
		    $held = array_sum(array_column($wallet['holds'], 'credits'));
		    $topups = array_values(array_filter($wallet['topups'], fn($t) => $t['left'] > 0));
		    usort($topups, fn($a, $b) => $a['expires'] <=> $b['expires']);
		    $pctA = $wallet['allowance'] > 0 ? min(100, round(($wallet['allowance'] - $wallet['allowance_left']) / $wallet['allowance'] * 100)) : 100;
		?>
		<div class="form-group row mb-2">
		    <label class="col-sm-3 col-form-label">Credits</label>
		    <div class="col-sm-9 pt-2 small">
			<div class="fs-5 fw-semibold"><?= number_format(AssistantWallet::balance($wallet)) ?> <span class="fs-6 fw-normal text-muted">credits left<?= $held ? ' (' . number_format($held) . ' reserved by running requests)' : '' ?></span></div>
			<div class="mt-2">Monthly allowance: <?= number_format($wallet['allowance_left']) ?> of <?= number_format($wallet['allowance']) ?> left, renewed on <?= gmdate('F 1', strtotime('first day of next month')) ?> (what is left is not carried over)</div>
			<div class="progress mt-1" style="height: 6px" role="progressbar" aria-label="Allowance used" aria-valuenow="<?= $pctA ?>" aria-valuemin="0" aria-valuemax="100">
			    <div class="progress-bar <?= $pctA >= 100 ? 'bg-danger' : ($pctA >= 80 ? 'bg-warning' : '') ?>" style="width: <?= $pctA ?>%"></div>
			</div>
			<div class="mt-2">Top-ups: <?= $topups ? '' : 'none' ?></div>
			<?php if ($topups): ?>
			<ul class="ps-3 mb-0">
			    <?php foreach($topups as $t): ?>
			    <li><?= number_format($t['left']) ?> of <?= number_format($t['granted']) ?>, until <?= gmdate('Y-m-d', $t['expires']) ?><?= $t['note'] !== '' ? ' &middot; ' . \V::app()->html->escape($t['note']) : '' ?></li>
			    <?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<div class="form-text">Used this month: <?= number_format($wallet['month_used']) ?> credits. The allowance is used first, then the top-up that expires first. Top-ups last <?= AssistantWallet::TOPUP_MONTHS ?> months.</div>
		    </div>
		</div>
		<?= $settings->schema['credit_cap']->input([ 'cols' => '3,9', 'input' => [ 'inputmode' => 'numeric', 'placeholder' => 'No limit' ] ]) ?>
		<?php if ($ledger): ?>
		<div class="form-group row mb-2">
		    <label class="col-sm-3 col-form-label">Last changes</label>
		    <div class="col-sm-9">
			<table class="table table-sm small mb-0">
			    <thead><tr><th>When (UTC)</th><th>What</th><th class="text-end">Credits</th><th class="text-end">Balance</th></tr></thead>
			    <tbody>
			    <?php foreach($ledger as $e): ?>
				<tr>
				    <td class="text-nowrap"><?= $e->created_at instanceof \MongoDB\BSON\UTCDateTime ? \V::app()->html->escape(gmdate('Y-m-d H:i', $e->created_at->toDateTime()->getTimestamp())) : \V::app()->html->escape($e->created_at) ?></td>
				    <td><?= \V::app()->html->escape(AssistantCreditLedger::TYPES[$e->type] ?? $e->type) ?><?= (string)$e->note !== '' ? '<div class="text-muted">' . \V::app()->html->escape($e->note) . '</div>' : '' ?></td>
				    <td class="text-end <?= $e->credits < 0 ? 'text-danger' : 'text-success' ?>"><?= ($e->credits > 0 ? '+' : '') . number_format((float)$e->credits) ?></td>
				    <td class="text-end"><?= number_format((float)$e->balance) ?></td>
				</tr>
			    <?php endforeach; ?>
			    </tbody>
			</table>
		    </div>
		</div>
		<?php endif; ?>
		<?php endif; ?>
	    </div>

	    <?php //CLD: 2026-10-22T14:00:00+03:00: your own server and key: every provider but System ?>
	    <div data-assistant-provider="anthropic openai gemini ollama lemonade">
	    <?= $settings->schema['url']->input([ 'cols' => '3,9' ]) ?>
	    <?= $settings->schema['api_key']->input([ 'cols' => '3,9', 'input' => [ 'autocomplete' => 'new-password' ] ]) ?>

	    <?php //CLD: 2026-10-20T10:00:00+03:00: Connect: the server's models in Model ?>
	    <div class="form-group row mb-2">
		<div class="col-sm-9 offset-sm-3 d-flex align-items-center gap-2">
		    <button type="button" class="btn btn-sm btn-outline-primary btn-assistant-connect"><i class="bi bi-plug me-1"></i>Connect</button>
		    <span class="assistant-connect-status small text-muted"><?= $model !== '' ? 'Saved model: ' . \V::app()->html->escape($model) : 'Not connected' ?></span>
		</div>
	    </div>

	    <?= \V::app()->form->field('model', [
		'id' => 'assistant-model',
		'type' => 'select',
		'label' => 'Model',
		'help' => 'The models the server lists (Connect)',
		'list' => $model !== '' ? [ $model => $model ] : [ '' => 'Connect to list the models' ],
		'value' => $model,
		'cols' => '3,9',
	    ]) ?>
	    </div>

	    <div data-assistant-provider="anthropic">
		<?= $settings->schema['effort']->input([ 'cols' => '3,9' ]) ?>
	    </div>
	    <?php //CLD: 2026-10-22T14:00:00+03:00: + gemini, ollama, lemonade ?>
	    <div data-assistant-provider="openai gemini ollama lemonade">
		<div class="form-text">The Server URL is reached from this application's server, so localhost means that machine. A local model needs a large context (the step types alone take about 10,000 tokens) and good tool calling.</div>
	    </div>

	    <?php //CLD: 2026-10-22T14:00:00+03:00: new: the monthly budget, the prices it is counted with, this month's spending ?>
	    <?php //CLD: 2026-10-22T15:00:00+03:00: the budget is for your own key (System: credits, above) ?>
	    <div data-assistant-provider="anthropic openai gemini ollama lemonade">
	    <h6 class="mt-4">Budget</h6>
	    <?= $settings->schema['budget']->input([ 'cols' => '3,9', 'input' => [ 'inputmode' => 'decimal', 'placeholder' => 'No limit' ] ]) ?>
		<?= $settings->schema['price_input']->input([ 'cols' => '3,9', 'input' => [ 'inputmode' => 'decimal' ] ]) ?>
		<?= $settings->schema['price_output']->input([ 'cols' => '3,9', 'input' => [ 'inputmode' => 'decimal' ] ]) ?>
	    </div>
	    <?php //CLD: 2026-10-22T15:00:00+03:00: your own key's spending (System: the credits, above) ?>
	    <div class="form-group row mb-2" data-assistant-provider="anthropic openai gemini ollama lemonade">
		<label class="col-sm-3 col-form-label">This month</label>
		<div class="col-sm-9 pt-2">
		    <?php
		    $pct = $budget ? min(100, round($usage->cost / $budget * 100)) : 0;
		    ?>
		    <div class="small"><?= sprintf('%.2f USD', $usage->cost) ?><?= $budget ? sprintf(' of %.2f USD', $budget) : '' ?>
			&middot; <?= number_format($usage->requests) ?> request(s), <?= number_format($usage->input_tokens) ?> input / <?= number_format($usage->output_tokens) ?> output tokens</div>
		    <?php if ($budget): ?>
		    <div class="progress mt-1" style="height: 6px" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
			<div class="progress-bar <?= $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : '') ?>" style="width: <?= $pct ?>%"></div>
		    </div>
		    <?php endif; ?>
		    <div class="form-text">Counted by the Assistant from the tokens and the prices; your provider's bill is what counts. Since <?= gmdate('F 1') ?> (UTC).</div>
		</div>
	    </div>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
</div>
