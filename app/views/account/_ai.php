<?php
//CLD: 2026-11-02T10:00:00+02:00: new: the AI tab of the account's settings (AccountController::settings, the owners): what the agents may do (models\AgentPolicy: $policy), the month's spending ($spent, models\AgentUsage)
$families = '';
foreach(\util\AgentTools::FAMILIES as $key => $fam) {
    if (!\util\AgentTools::operations($key))
	continue;
    $families .= \util\VString::insert('<div class="col-md-6"><div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="allowed_families[]" value="{:key}" id="fam-{:key}"{:checked}>'
	. '<label class="form-check-label small" for="fam-{:key}">{:label}</label></div></div>', [
	'key' => \V::app()->html->escape($key),
	'label' => \V::app()->html->escape($fam['label']),
	'checked' => $policy->familyAllowed($key) ? ' checked' : '',
    ]);
}
//CLD: 2026-11-02T14:00:00+02:00: the price table: a row (the empty one: the template Add copies), the posted rows when not saved
$priceRow = function($i, array $row) {
    $options = '<option value="">Any</option>';
    foreach(\models\AgentPolicy::PRICE_PROVIDERS as $value => $label)
	$options .= \util\VString::insert('<option value="{:value}"{:selected}>{:label}</option>', [ 'value' => \V::app()->html->escape($value), 'label' => \V::app()->html->escape($label), 'selected' => ($row['provider'] ?? '') === $value ? ' selected' : '' ]);
    return \util\VString::insert('<tr><td><select class="form-select form-select-sm" name="price_table[{:i}][provider]" aria-label="Provider">{:options}</select></td>'
	. '<td><input class="form-control form-control-sm" name="price_table[{:i}][model]" value="{:model}" maxlength="100" aria-label="Model, or the start of its name"></td>'
	. '<td><input class="form-control form-control-sm" type="number" min="0" step="any" name="price_table[{:i}][input]" value="{:input}" aria-label="Input price"></td>'
	. '<td><input class="form-control form-control-sm" type="number" min="0" step="any" name="price_table[{:i}][output]" value="{:output}" aria-label="Output price"></td>'
	. '<td><button type="button" class="btn btn-sm btn-link text-body-secondary p-0 ai-price-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>', [
	'i' => $i,
	'options' => $options,
	'model' => \V::app()->html->escape((string)($row['model'] ?? '')),
	'input' => \V::app()->html->escape((string)($row['input'] ?? '')),
	'output' => \V::app()->html->escape((string)($row['output'] ?? '')),
    ]);
};
$priceRows = '';
foreach(array_values((array)$policy->price_table) as $i => $row)
    $priceRows .= $priceRow($i, (array)$row);
$priceTemplate = json_encode($priceRow('__i__', []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$priceMax = \models\AgentPolicy::MAX_PRICES;
\V::app()->cs->addPageScript("
+function() {
    const table = document.getElementById('ai-prices');
    if (table) {
	let next = table.querySelectorAll('tbody tr').length;
	const body = table.querySelector('tbody');
	document.getElementById('ai-price-add').addEventListener('click', () => {
	    if (body.children.length < {$priceMax})
		body.insertAdjacentHTML('beforeend', {$priceTemplate}.replaceAll('__i__', String(next++)));
	});
	body.addEventListener('click', (e) => {
	    const btn = e.target.closest('.ai-price-remove');
	    if (btn)
		btn.closest('tr').remove();
	});
    }
}();
+function() {
    const mode = document.querySelector('[name=\"write_mode\"]');
    const chosen = document.getElementById('ai-chosen-writes');
    if (!mode || !chosen)
	return;
    const sync = () => { chosen.classList.toggle('d-none', mode.value !== 'chosen'); };
    mode.addEventListener('change', sync);
    sync();
}();
");
?>
    <?= \V::app()->form->create($policy, [ 'action' => \V::app()->createUrl('/account/settings', [ '__tab' => 'ai' ]) ]) ?>
	<div class="row">
	    <div class="col-xl-6">
		<div class="pt-4">
		    <h6>Integrations agents may use</h6>
		    <div class="row"><?= $families ?></div>
		    <div class="form-text">The AI steps' agents only. A step that names one that is off is refused when saved, and does not get its tools when it runs.</div>
		</div>

		<?php //CLD: 2026-11-02T14:00:00+02:00: the prices per provider and model (the budget, the cost of an agent trace) ?>
		<div class="pt-4">
		    <h6>Prices per model</h6>
		    <table class="table table-sm table-borderless align-middle mb-1" id="ai-prices">
			<thead><tr class="small text-body-secondary"><th style="width: 24%">Provider</th><th>Model, or the start of its name</th><th style="width: 16%">Input</th><th style="width: 16%">Output</th><th style="width: 2rem"></th></tr></thead>
			<tbody><?= $priceRows ?></tbody>
		    </table>
		    <button type="button" class="btn btn-sm btn-outline-primary" id="ai-price-add"><i class="bi bi-plus-lg"></i> Add a price</button>
		    <?php foreach((array)($policy->errors()['price_table'] ?? []) as $message): ?>
		    <div class="text-danger small mt-1"><?= \V::app()->html->escape((string)$message) ?></div>
		    <?php endforeach; ?>
		    <div class="form-text">USD per 1M tokens, instead of the prices Rail22 knows, in the budget above and the cost of an agent trace. A step's own prices come first.</div>
		</div>
	    </div>

	    <div class="col-xl-6">
		<div class="pt-4">
		    <h6>Tools that change something</h6>
		    <?= $policy->schema['write_mode']->input([ 'cols' => '6,6' ]) ?>
		    <div id="ai-chosen-writes"><?= $policy->schema['allowed_writes']->input([ 'cols' => '6,6' ]) ?></div>
		    <?= $policy->schema['writes_need_approval']->input([ 'cols' => '0,12' ]) ?>
		    <div class="form-text">Integration operations that change something, workflows run as tools, project tools and MCP tools not marked read-only.</div>
		</div>

		<div class="pt-4">
		    <h6>Monthly AI budget</h6>
		    <?= $policy->schema['budget']->input([ 'cols' => '6,6' ]) ?>
		    <div class="row">
			<div class="col-6"><?= $policy->schema['price_input']->input([ 'cols' => '6,6' ]) ?></div>
			<div class="col-6"><?= $policy->schema['price_output']->input([ 'cols' => '6,6' ]) ?></div>
		    </div>
		    <div class="form-text">All AI steps with the account's own keys, per calendar month (UTC), estimated from the tokens: about <strong><?= \V::app()->html->escape(number_format($spent, 2)) ?> USD</strong> so far. Empty: no limit. Prices: USD per 1M tokens, for models whose price is not known. The System AI is paid in credits instead.</div>
		</div>

	    </div>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
