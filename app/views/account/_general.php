<?php
//CLD: 2026-10-31T11:32:07+02:00: the Settings tab of the account's settings (was the whole page: views/account/settings.php)
//CLD: 2026-11-01T00:30:00+02:00: two columns (security and logs on the left, dates and integrations on the right; one column below xl), to fit one screen (were one w-50 column);
//+ Integrations: private network addresses (Account allow_private_addresses)
?>
    <?= \V::app()->form->create($account) ?>
	<div class="row">
	    <div class="col-xl-6">
		<div class="pt-4">
		    <h6>MFA</h6>
		    <?= $account->schema['enforce_mfa']->input([ 'cols' => '0,12' ]) ?>
		</div>

		<?php //CLD: 2026-10-21T23:00:00+03:00: the session limits (sys\Boot::after_user_login) ?>
		<div class="pt-4">
		    <h6>Sessions</h6>
		    <?= $account->schema['session_idle']->input([ 'cols' => '6,6' ]) ?>
		    <?= $account->schema['session_max']->input([ 'cols' => '6,6' ]) ?>
		    <div class="form-text">Apply to "Remember me" sign-ins too, which only stay signed in after the browser is closed.</div>
		</div>

		<div class="pt-4">
		    <h6>Logs</h6>
		    <?= $account->schema['audit_log_retention']->input([ 'cols' => '6,6' ]) ?>
		    <?= $account->schema['execution_log_retention']->input([ 'cols' => '6,6' ]) ?>
		    <?php //CLD: 2026-10-22T00:00:00+03:00: the audit log is kept 1 year at least (models\AuditLog::MIN_RETENTION_DAYS) ?>
		    <div class="form-text">A shorter time also removes the older entries already written. Audit log entries are kept at least 1 year.</div>
		</div>
	    </div>

	    <div class="col-xl-6">
		<?php //CLD: 2026-10-24T16:30:00+03:00: how dates are shown (util\Format) ?>
		<div class="pt-4">
		    <h6>Dates</h6>
		    <?= $account->schema['date_format']->input([ 'cols' => '6,6' ]) ?>
		    <?= $account->schema['datetime_format']->input([ 'cols' => '6,6' ]) ?>
		</div>

		<div class="pt-4">
		    <h6>Integrations</h6>
		    <?= $account->schema['allow_private_addresses']->input([ 'cols' => '0,12' ]) ?>
		    <div class="form-text">For servers in your own network, such as an on-premises Bitdefender GravityZone console. Off: only public internet addresses. This server itself is never reachable.</div>
		</div>
	    </div>
	</div>

	<?php //CLD: 2026-10-19T21:10:00+03:00: the inbound mail prefix moved into the InboundMail plugin (Settings menu: Inbound mail) ?>

	<div class="pt-4">
	    <button type="submit" class="btn btn-primary">Save</button>
	</div>
    <?= \V::app()->form->end() ?>
