<?php

/*
    CLD: 2026-11-13T00:30:00+02:00: an Infobip account, for the Infobip steps (SMS, WhatsApp, Email, 2FA, Account, API; util\Infobip): its base URL (the portal's home page) and an API
    key (Developer tools > API keys; encrypted; its scopes limit what the steps may do), and the senders the steps use when they name none: an SMS sender (alphanumeric or a
    number), the WhatsApp sender (the number registered with Infobip), the email sender (of a domain verified in Infobip). Test: the account's balance. Provides `infobip`
*/

namespace models\credential;

class CredentialInfobip extends \models\Credential {
    public static $label = 'Infobip';
    public static $icon = 'bi-chat-dots';
    public static $group = 'Messaging';
    public static $provides = [ 'infobip' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'baseUrl',
		'type' => 'text',
		'label' => 'Base URL',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Your account\'s (Infobip\'s portal, the home page\'s API panel), e.g. https://abc123.api.infobip.com',
		'validate' => [ [ 'regex', 'pattern' => '^(https://)?[A-Za-z0-9-]+\.api(-[a-z0-9]+)?\.infobip\.com/?$', 'skipEmpty' => true, 'message' => 'https://<xxxxx>.api.infobip.com' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'https://abc123.api.infobip.com' ],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'Developer tools > API keys: a key with the scopes of the channels used (e.g. sms:message:send, whatsapp:message:send)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'smsSender',
		'type' => 'text',
		'label' => 'SMS sender',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The default: a name (up to 11 letters or digits, where allowed) or a number',
		'ui' => [ 'width' => 4, 'newRow' => true ],
	    ],
	    [
		'key' => 'whatsappSender',
		'type' => 'text',
		'label' => 'WhatsApp sender',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The default: the number registered for WhatsApp with Infobip, e.g. 447860099299',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'emailFrom',
		'type' => 'text',
		'label' => 'Email sender',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The default, of a domain verified in Infobip, e.g. Acme <noreply@acme.com>',
		'ui' => [ 'width' => 4 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\Infobip($this, $timeout);
    }

    public function test() {
	try {
	    $b = $this->client(20)->request('GET', '/account/1/balance');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to Infobip: balance ' . (isset($b['balance']) ? number_format((float)$b['balance'], 2, '.', '') : '?') . ' ' . ($b['currency'] ?? '');
    }
}
