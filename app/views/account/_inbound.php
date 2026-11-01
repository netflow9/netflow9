<?php
/*
    CLD: 2026-11-01T16:11:37+02:00: new (was the InboundMail plugin's views/inbound/settings.php): the Inbound mail tab of the account's settings, the prefix of the account's
    Email (inbound) addresses (AccountController::settings: $settings, InboundMailSettings; $domain, MAIL_INBOUND_DOMAIN); shown where Haraka is found
*/
?>
    <?= \V::app()->form->create($settings, [ 'action' => \V::app()->createUrl('/account/settings', [ '__tab' => 'inbound' ]) ]) ?>
	<div class="row">
	    <div class="col-xl-6">
		<div class="pt-4">
		    <h6>Email (inbound) addresses</h6>
		    <p class="text-muted small">Mail sent to the address of an Email (inbound) step starts its workflow.</p>
		    <?= $settings->schema['prefix']->input([ 'cols' => '3,9' ]) ?>
		    <?php if ($domain === ''): ?>
		    <div class="alert alert-warning small mt-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>The inbound domain is not set: set MAIL_INBOUND_DOMAIN in app.env (the domain Haraka receives), then the steps get their addresses.</div>
		    <?php else: ?>
		    <div class="form-text"><?= \util\VString::insert('Each Email (inbound) step has its own address: <code>{:prefix}-xxxxxxxx@{:domain}</code>.', [
			'prefix' => \V::app()->html->escape((string)$settings->prefix),
			'domain' => \V::app()->html->escape($domain),
		    ]) ?></div>
		    <?php endif; ?>
		</div>
	    </div>
	</div>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
