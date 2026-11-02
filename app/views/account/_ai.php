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
\V::app()->cs->addPageScript("
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
