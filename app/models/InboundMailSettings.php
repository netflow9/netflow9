<?php

/*
    the inbound mail settings of an account, one document per account:
	`prefix`, the start of its addresses <prefix>-<code>@MAIL_INBOUND_DOMAIN.
	Read by the Email (inbound) step (its address) and by Haraka (haraka/plugins/nfn.js,
	collection inbound_mail_settings, by aid);
    per account; changed on the Inbound mail tab of the account's settings (AccountController::settings)
    $prefix = InboundMailSettings::prefixOf($aid);
	the account's; the first time its settings are saved (Haraka reads them), with DEFAULT_PREFIX
*/

namespace models;

class InboundMailSettings extends \sys\BaseModel {
    protected static $collection = 'inbound_mail_settings';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    //a new account's prefix (as the account field's default was)
    const DEFAULT_PREFIX = 'inbound';

    public function getSchema() {
	$fields = [
	    [
		'key' => 'prefix',
		'type' => 'text',
		'label' => 'Email prefix',
		'help' => 'The start of your inbound email addresses (prefix-xxxxxxxx@' . (\util\InboundMail::domain() ?: 'domain') . '); changing it changes them all',
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

    //the prefix of account $aid; an account without settings yet gets them saved now (Haraka reads them),
    // with DEFAULT_PREFIX found / created here
    public static function prefixOf($aid) {
	$aid = \V::app()->om->to_oid($aid);
	$settings = \V::app()->om->findOne('InboundMailSettings', [ 'aid' => $aid ]);
	if (!$settings) {
	    $settings = \V::app()->om->create('InboundMailSettings', [ 'aid' => $aid, 'prefix' => self::DEFAULT_PREFIX ]);
	    if (!$settings->validates() || !$settings->save())
		return '';
	}
	return (string)$settings->prefix;
    }
}
