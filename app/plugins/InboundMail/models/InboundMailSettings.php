<?php

//CLD: 2026-10-19T21:10:00+03:00: new: the inbound mail settings of an account, one document per account: `prefix`, the start of its addresses <prefix>-<code>@MAIL_INBOUND_DOMAIN
//(was the account field mail_prefix). Read by the Email (inbound) step (its address) and by Haraka (haraka/plugins/nfn.js, collection inbound_mail_settings, by aid);
//changed on the plugin's page (InboundController::settings, linked from the Settings menu).
//  $prefix = InboundMailSettings::prefixOf($aid);   the account's; the first time its settings are saved (Haraka reads them), with defaultPrefixFor($aid)
//  CLD: 2026-10-21T11:30:00+03:00: forAccount() removed from the comment (it was never defined: prefixOf() called it); the settings are found with om->findOne

namespace plugins\InboundMail\models;

class InboundMailSettings extends \sys\BaseModel {
    protected static $collection = 'inbound_mail_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    //a new account's prefix (as the account field's default was)
    const DEFAULT_PREFIX = 'inbound';

    public function getSchema() {
	$fields = [
	    //moved from models\Account (mail_prefix)
	    [
		'key' => 'prefix',
		'type' => 'text',
		'label' => 'Email prefix',
		'help' => 'The start of your inbound email addresses (prefix-xxxxxxxx@' . (\V::app()->config('mail.inbound_domain') ?: 'domain') . '); changing it changes them all',
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		'default' => self::DEFAULT_PREFIX,
		'transform' => 'tolower',
		'validate' => [
		    [ 'lengthBetween', 'min' => 0, 'max' => 30, 'message' => 'At most 30 characters' ],
		    [ 'alphanumeric', 'skipEmpty' => true, 'message' => 'Only letters and digits' ],
		],
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //the prefix of account $aid; an account without settings yet gets them saved now (Haraka reads them), with defaultPrefixFor($aid)
    //CLD: 2026-10-21T11:30:00+03:00: found / created here (was self::forAccount(), never defined: a fatal error)
    public static function prefixOf($aid) {
	$om = \V::app()->om;
	$settings = $om->findOne('InboundMailSettings', [ 'aid' => $om->to_oid($aid) ]);
	if (!$settings) {
	    $settings = $om->create('InboundMailSettings', [ 'aid' => $om->to_oid($aid), 'prefix' => self::defaultPrefixFor($aid) ]);
	    if (!$settings->validates() || !$settings->save())
		return '';
	}
	return (string)$settings->prefix;
    }

    //CLD: 2026-10-21T11:30:00+03:00: the first prefix of account $aid: the one it had (the old account field mail_prefix) when valid, else DEFAULT_PREFIX
    public static function defaultPrefixFor($aid) {
	$account = \V::app()->om->findById('Account', (string)$aid);
	$old = strtolower(trim((string)($account->mail_prefix ?? '')));
	return preg_match('/^[a-z0-9]{1,30}$/', $old) ? $old : self::DEFAULT_PREFIX;
    }
}
