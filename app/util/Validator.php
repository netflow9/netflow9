<?php
namespace util;

/**
 * The `Validator` class provides static access to commonly used data validation logic. These common
 * routines cover HTML form input data such as phone and credit card numbers, dates and postal
 * codes, but also include general checks for regular expressions and booleans and numericality.
 *
 * General data checking is done by using `Validator` statically. Rules can be specified as a
 * parameter to the `rule()` method or accessed directly via the `is[RuleName]()` method name
 * convention:
 *
 * {{{
 * use util\Validator;
 *
 * // The following are equivalent:
 * Validator::rule('email', 'foo@example.com');  // true
 * Validator::isEmail('foo-at-example.com');     // false
 * }}}
 *
 * Data can also be validated against multiple rules, each having their own associated error
 * message. The rule structure is array-based and hierarchical based on rule names and
 * messages. Responses match the keys present in the `$data` parameter of `check()` up with an array
 * of rules which they violate.
 *
 * See the `check()` method for more information an multi-value datasets. Custom validation rules
 * can also be added to `Validator` at runtime. These can either take the form of regular expression
 * strings or functions supplied to the `add()` method.
 *
 * ### Rules
 *
 * The `Validator` class includes a series of commonly-used rules by default, any of which may be
 * used in calls to `rule()` or `check()`, or called directly as a method. Additionally, many rules
 * have a variety of different _formats_ in which they may be specified. The following is the list
 * of the built-in rules, but keep in mind that none of them are hard-coded. Any rule may be
 * overridden by adding a new rule of the same name using the `add()` method.
 *
 * - `notEmpty`: Checks that a string contains at least one non-whitespace character.
 *
 * - `alphaNumeric`: Checks that a string contains only integer or letters.
 *
 * - `identifier`: Checks that a string contains only letters, digits or underscore and does not
 *   start with a digit (e.g. variable names).
 *
 * - `lengthBetween`: Checks that a string length is within a specified range. Spaces are included
 *   in the character count. The available options are `'min'` and `'max'`, which designate the
 *   minimum and maximum length of the string.
 *
 * - `blank`: Checks that a field is left blank **OR** only whitespace characters are present in its
 *   value. Whitespace characters include spaces, tabs, carriage returns and newlines.
 *
 * - `date`: Checks that a value is a valid date that complies with one or more formats. Also
 *   validates leap years. Possible formats are `'dmy'` (27-12-2010 or 27-12-10 separators can be a
 *   space, period, dash, forward slash), `'mdy'` (12-27-2010 or 12-27-10 separators can be a space,
 *   period, dash, forward slash), `'ymd'` (2010-12-27 or 10-12-27 separators can be a space,
 *   period, dash, forward slash), `'dMy'` (27 December 2010 or 27 Dec 2010), `'Mdy'` (December 27,
 *   2010 or Dec 27, 2010 comma is optional), `'My'` (December 2010 or Dec 2010) or `'my'` (12/2010
 *   separators can be a space, period, dash, forward slash).
 *
 * - `time`: Checks that a value is a valid time. Validates time as 24hr (HH:MM) or am/pm
 *   ([ H]H:MM[a|p]m). Does not allow / validate seconds.
 *
 * - `boolean`: Checks that the value is or looks like a boolean value. The following types of
 *   values are interpreted as boolean and will pass the check.
 *   - boolean (`true`, `false`, `'true'`, `'false'`)
 *   - boolean number (`1`, `0`, `'1'`, `'0'`)
 *   - boolean text string (`'on'`, `'off'`, `'yes'`, `'no'`)
 *
 * - `decimal`: Checks that a value is a valid decimal. Takes one option, `'precision'`, which is
 *   an optional integer value defining the level of precision the decimal number must match.
 *
 * - `email`: Checks that a value is (probably) a valid email address. The subject of validating
 *   an actual email address is complicated and problematic. A regular expression that correctly
 *   validates addresses against [RFC 5322](http://tools.ietf.org/html/rfc5322) would be several
 *   pages long, with the drawback of being unable to keep up as new top-level domains are added.
 *   Instead, this validator uses PHP's internal input filtering API to check the format, and
 *   provides an option, `'deep'` ( _boolean_) which, if set to `true`, will validate that the email
 *   address' domain contains a valid MX record. Keep in mind, this is just one of the many ways to
 *   validate an email address in the overall context of an application. For other ideas or
 *   examples, [ask Sean](http://seancoates.com/).
 *
 * - `ip`: Validates a string as a valid IPv4 or IPv6 address.
 *
 * - `numeric`: Checks that a value is numeric.
 *
 * - `inRange`: Checks that a numeric value is within a specified range. This value has two options,
 *    `'upper'` and `'lower'`, which specify the boundary of the value.
 *
 * - `url`: Checks that a value is a valid URL according to
 *   [RFC 2395](http://www.faqs.org/rfcs/rfc2396.html). Uses PHP's filter API, and accepts any
 *   options accepted for
 *   [the validation URL filter](http://www.php.net/manual/en/filter.filters.validate.php).
 *
 * - `inList`: Checks that a value is in a pre-defined list of values. This validator accepts one
 *   option, `'list'`, which is an array containing acceptable values.
 *
 * ### UTF-8 encoded input strings
 *
 * All rules operating on strings have been created with the possibility of
 * UTF-8 encoded input in mind. A default PHP binary and an enabled Lithium
 * g11n bootstrap will make these rules work correctly in any case. Should you
 * ever experience odd behavior following paragraph with implementation
 * details might help you to track to the cause.
 *
 * The rules `alphaNumeric` and `money` rely on additional functionality of
 * PCRE to validate UTF-8 encoded strings. As no PCRE feature detection is
 * done, having this feature enabled in PCRE isn't optional. Please ensure
 * you've got PCRE compiled with UTF-8 support.
 */
class Validator {

    /**
     * An array of validation rules.  May contain a single regular expression, an array of regular
     * expressions (where the array keys define various possible 'formats' of the same rule), or a
     * closure which accepts a value to be validated, and an array of options, and returns a
     * boolean value, indicating whether the validation succeeded or failed.
     *
     * @var array
     * @see lithium\util\Validator::add()
     * @see lithium\util\Validator::rule()
     */
    protected static $_rules = array();

    /**
     * Default options used when defining a new validator rule. Each key contains method-specific
     * options that should always be applied, or options that should be applied to all rules in the
     * `'defaults'` key.
     *
     * @see lithium\util\Validator::add()
     * @see lithium\util\Validator::rule()
     * @var array
     */
    protected static $_options = array(
	'defaults' => array('contains' => true)
    );

    /**
     * Initializes the list of default validation rules.
     *
     * @return void
     */
    public static function __init() {
	$alnum = '[A-Fa-f0-9]';

	static::$_rules = array(
	    //the value should be alphanumeric: digits, letters
	    'alphanumeric' => function($value) {
		if (!is_string($value))
		    return false;
		return preg_match('/^[0-9A-Za-z]+$/', $value);
	    },

	    //the value should be an identifier: letters, digits, underscore; not starting with a digit
	    'identifier' => function($value) {
		if (!is_string($value))
		    return false;
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value);
	    },


	    //CLD: 2026-10-06T09:23:42+03:00: custom rules, registered as ChangePasswordForm does; `values` = all form values
	    'fieldKeyNotReserved' => function($value) {
		$RESERVED_KEYS = [ '_id', 'aid', 'created_at', 'updated_at' ];
		return !in_array($value, $RESERVED_KEYS, true);
	    },

	    'string' => function($value) {
		return is_string($value);
	    },

	    'blank'        => '/[^\\s]/',

	    'ip' => function($value, $format = null, array $options = array()) {
			$options += array('flags' => array());
			return (boolean) filter_var($value, FILTER_VALIDATE_IP, $options);
	    },

	    //CLD: 2026-10-09T08:35:15+03:00: missing = null, '', false or []; 0 and '0' are values (was !empty(), so a required number could not be 0)
	    'notEmpty' => function($value) {
		return !($value === null || $value === '' || $value === false || $value === []);
	    },

	    'time'         => '%^((0?[1-9]|1[012])(:[0-5]\d){0,2}([AP]M|[ap]m))$|^([01]\d|2[0-3])' .
			      '(:[0-5]\d){0,2}$%',

	    'boolean' => function($value) {
		$bool = is_bool($value);
		$filter = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		return ($bool || $filter !== null || empty($value));
	    },

	    'decimal' => function($value, $format = null, array $options = array()) {
			if (isset($options['precision'])) {
			    $precision = strlen($value) - strrpos($value, '.') - 1;

			    if ($precision !== (int) $options['precision']) {
				return false;
			    }
			}
			return (filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) !== null);
	    },

	    'inList' => function($value, $format, $options) {
			$options += array('list' => array());
			$strict = is_bool($value) || $value === '';
			return in_array($value, $options['list'], $strict);
	    },

	    'lengthBetween' => function($value, $format, $options) {
			//CLD: 2026-10-14T14:54:31+03:00: characters (mb_strlen; was bytes: strlen, so UTF-8 text counted longer)
			$length = mb_strlen((string)$value);
			$options += array('min' => 1, 'max' => 255);
			return ($length >= $options['min'] && $length <= $options['max']);
	    },

	    'numeric' => function($value) {
			return is_numeric($value);
	    },

	    'inRange' => function($value, $format, $options) {
			$defaults = array('upper' => null, 'lower' => null);
			$options += $defaults;

			if (!is_numeric($value)) {
			    return false;
			}
			switch (true) {
			    case (!is_null($options['upper']) && !is_null($options['lower'])):
				return ($value >= $options['lower'] && $value <= $options['upper']);
			    case (!is_null($options['upper'])):
				return ($value <= $options['upper']);
			    case (!is_null($options['lower'])):
				return ($value >= $options['lower']);
			}
			return is_finite($value);
	    },

	    'email' => function($value) {
		return filter_var($value, FILTER_VALIDATE_EMAIL);
	    },

	    'url' => function($value, $format = null, $options = []) {
		$options += array('flags' => []);
		return (boolean) filter_var($value, FILTER_VALIDATE_URL, $options);
	    },

	    'notInList' => function($value, $format, $options) {
		$options += [ 'list' => [] ];
		if (is_string($value))
		    $value = strtolower($value);
		return !in_array($value, $options['list']);
	    },


	    'unique' => function($value, $format, $options) {
		$coll = \V::app()->mongo->selectCollection(\V::app()->mongo->database, $options['collection']);

		$query = [
		    $options['field'] => $value, //name is here
		];
		if (!empty($account = \V::app()->var('rt.account')))
		    $query['aid'] = $account->_id;

		if (empty($obj = $coll->findOne($query)))
		    return true;
		if (!empty($options['values']['_id']) && $obj['_id'] == $options['values']['_id'])
		    return true;

		return false;
	    },

	    //CLD: 2026-10-06T21:09:08+03:00: `uniqueInObject`: value unique among the records with the same `objectName` (e.g. field keys of one
	    //object / data table); options: `collection`; the record itself (values._id) does not count
	    'uniqueInObject' => function($value, $format, $options) {
		$coll = \V::app()->mongo->selectCollection(\V::app()->mongo->database, $options['collection']);

		$query = [
		    $options['field'] => $value,
		    'objectName' => $options['values']['objectName'] ?? null,
		];
		if (!empty($account = \V::app()->var('rt.account')))
		    $query['aid'] = $account->_id;

		if (empty($obj = $coll->findOne($query)))
		    return true;
		if (!empty($options['values']['_id']) && $obj['_id'] == $options['values']['_id'])
		    return true;

		return false;
	    },

	    //CLD: 2026-10-06T21:22:22+03:00: `requiredIf`: required (non-empty, not only whitespace) when the field `if` has one of the values `in`;
	    //e.g. [ 'requiredIf', 'if' => 'type', 'in' => [ 'select' ] ]
	    'requiredIf' => function($value, $format, $options) {
		$other = $options['values'][$options['if'] ?? ''] ?? null;
		if (!in_array($other, (array)($options['in'] ?? []), true))
		    return true;
		//CLD: 2026-10-14T14:18:23+03:00: a list is given when it is not empty (Validator::check runs this rule on lists and on missing values)
		if (is_array($value))
		    return !empty($value);
		return is_scalar($value) && trim((string)$value) !== '';
	    },

	    //CLD: 2026-10-18T13:10:00+03:00: `choices`: the value, or each value of a list, is a key of the `choices` (an array or a callable returning one;
	    //sys\fields\Field_select); no choices (e.g. depending on a user, outside a web request): not checked
	    'choices' => function($value, $format, $options) {
		$choices = $options['choices'] ?? [];
		if (is_callable($choices))
		    $choices = $choices();
		if (empty($choices) || !is_array($choices))
		    return true;
		foreach((array)$value as $v) {
		    if (!is_scalar($v) || !array_key_exists((string)$v, $choices))
			return false;
		}
		return true;
	    },

	    //CLD: 2026-10-09T08:05:11+03:00: `regex`: the value matches the PCRE `pattern` (without delimiters, unicode), e.g. [ 'regex', 'pattern' => '^[A-Za-z0-9-]+$',
	    //'skipEmpty' => true ]
	    'regex' => function($value, $format, $options) {
		if (!is_scalar($value) || empty($options['pattern']))
		    return false;
		return @preg_match('/' . str_replace('/', '\/', (string)$options['pattern']) . '/u', (string)$value) === 1;
	    },

	    //CLD: 2026-10-18T14:00:00+03:00: `dateFormat`: a text that is a real date in the `format` (DateTime::createFromFormat, e.g. 'Y-m-d'; 2026-02-30 is not one),
	    //e.g. [ 'dateFormat', 'format' => 'Y-m-d', 'skipEmpty' => true ] (util\SmartForm: the dates of the form answers)
	    'dateFormat' => function($value, $format, $options) {
		if (!is_string($value) || empty($options['format']))
		    return false;
		$d = \DateTime::createFromFormat('!' . $options['format'], $value);
		return $d !== false && $d->format($options['format']) === $value;
	    },

	    //CLD: 2026-10-09T08:10:30+03:00: `cron`: a 5-field cron expression (util\Cron)
	    'cron' => function($value) {
		return is_string($value) && \util\Cron::isValid($value);
	    },

	    //add `uniqueInProject` validator for names
	    'uniqueInProject' => function($value, $format, $options) {
		$coll = \V::app()->mongo->selectCollection(\V::app()->mongo->database, $options['collection']);

		$query = [
		    'aid' => \V::app()->var('rt.account')->_id,
		    $options['field'] => $value, //name is here
		    'project' => $options['values']['project'] ?? null,
		];

		if (empty($obj = $coll->findOne($query)))
		    return true;
		if (!empty($options['values']['_id']) && $obj['_id'] == $options['values']['_id'])
		    return true;

		return false;
	    },


	    'oid' => function($value) {
		return \V::app()->om->to_oid($value) !== null;
	    },

	    //CLD: 2026-10-20T14:03:59+03:00: keys and certificates (credentials: Encryption, Decryption, FTP). Each takes `when` => [ <field> => [ values ] ]: checked only
	    //when every listed field has one of its values (e.g. [ 'method' => [ 'pgp' ] ]); a blank text passes (use with 'skipEmpty' => true; required-ness is requiredIf's)

	    //`pgpPublicKey`: armored PGP public key block(s) (gpg --armor --export), each a public key packet
	    'pgpPublicKey' => function($value, $format, $options) {
		if (!static::_when($options) || static::_blank($value))
		    return true;
		$blocks = static::pemBlocks($value, 'PGP PUBLIC KEY BLOCK');
		foreach($blocks as $b) {
		    if (static::pgpPacketTag($b['data']) !== 6)
			return false;
		}
		return !empty($blocks);
	    },

	    //`pgpPrivateKey`: one armored PGP private key block (gpg --armor --export-secret-keys), a secret key packet
	    //CLD: 2026-10-20T14:07:31+03:00: 'multiple' => true: one or more blocks (e.g. signing: the key is chosen by its id)
	    'pgpPrivateKey' => function($value, $format, $options) {
		if (!static::_when($options) || static::_blank($value))
		    return true;
		$blocks = static::pemBlocks($value, 'PGP PRIVATE KEY BLOCK');
		if (empty($blocks) || (empty($options['multiple']) && count($blocks) > 1))
		    return false;
		foreach($blocks as $b) {
		    if (static::pgpPacketTag($b['data']) !== 5)
			return false;
		}
		return true;
	    },

	    //CLD: 2026-10-20T14:07:31+03:00: `pemPublicKey`: one PEM public key (-----BEGIN PUBLIC KEY----- or RSA PUBLIC KEY: RSA, EC, Ed25519), readable by OpenSSL
	    'pemPublicKey' => function($value, $format, $options) {
		if (!static::_when($options) || static::_blank($value))
		    return true;
		$blocks = array_merge(static::pemBlocks($value, 'PUBLIC KEY'), static::pemBlocks($value, 'RSA PUBLIC KEY'));
		return count($blocks) === 1 && $blocks[0]['data'] !== null && @openssl_pkey_get_public($blocks[0]['pem']) !== false;
	    },

	    //`pemCertificate`: PEM certificate(s) (-----BEGIN CERTIFICATE-----), each readable by OpenSSL; 'multiple' => false: exactly one
	    'pemCertificate' => function($value, $format, $options) {
		if (!static::_when($options) || static::_blank($value))
		    return true;
		$blocks = static::pemBlocks($value, 'CERTIFICATE');
		if (empty($blocks) || (($options['multiple'] ?? true) === false && count($blocks) > 1))
		    return false;
		foreach($blocks as $b) {
		    if (@openssl_x509_read($b['pem']) === false)
			return false;
		}
		return true;
	    },

	    //`pemPrivateKey`: one PEM private key (PKCS#8, encrypted PKCS#8, RSA, EC, DSA); an unencrypted one must be readable by OpenSSL (an encrypted one needs
	    //its passphrase: the credential's test() opens it)
	    'pemPrivateKey' => function($value, $format, $options) {
		if (!static::_when($options) || static::_blank($value))
		    return true;
		return static::isPemPrivateKey($value);
	    },

	    //`sshPrivateKey`: an SSH private key: OpenSSH (-----BEGIN OPENSSH PRIVATE KEY-----) or PEM (as pemPrivateKey)
	    'sshPrivateKey' => function($value, $format, $options) {
		if (!static::_when($options) || static::_blank($value))
		    return true;
		$blocks = static::pemBlocks($value, 'OPENSSH PRIVATE KEY');
		if ($blocks)
		    return count($blocks) === 1 && str_starts_with((string)$blocks[0]['data'], "openssh-key-v1\0");
		return static::isPemPrivateKey($value);
	    },

	    //`notPrivateKey` / `notPublicKey`: the text holds no private / public key block (PEM or PGP): for a message telling the wrong key was pasted
	    'notPrivateKey' => function($value, $format, $options) {
		return !static::_when($options) || !preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY( BLOCK)?-----/', (string)$value);
	    },
	    'notPublicKey' => function($value, $format, $options) {
		return !static::_when($options) || !preg_match('/-----BEGIN [A-Z0-9 ]*PUBLIC KEY( BLOCK)?-----/', (string)$value);
	    },
	);

    }

    //CLD: 2026-10-20T14:03:59+03:00: the `when` option of a rule: every field listed has one of its values (no `when`: true)
    protected static function _when($options) {
	foreach((array)($options['when'] ?? []) as $field => $in) {
	    if (!in_array($options['values'][$field] ?? null, (array)$in, true))
		return false;
	}
	return true;
    }

    //CLD: 2026-10-20T14:03:59+03:00: not a text, or only whitespace
    protected static function _blank($value) {
	return !is_scalar($value) || trim((string)$value) === '';
    }

    //CLD: 2026-10-20T14:03:59+03:00: the armored / PEM blocks of $text labelled $label (e.g. 'CERTIFICATE'), in order: [ 'label', 'pem' (the whole block),
    //'data' (the decoded body; null when it is not base64) ]; headers (Version:, Proc-Type:, DEK-Info:, ...) and the PGP checksum line are not part of the body
    public static function pemBlocks($text, $label) {
	$q = preg_quote($label, '/');
	preg_match_all('/-----BEGIN (' . $q . ')-----(.*?)-----END ' . $q . '-----/s', (string)$text, $m, PREG_SET_ORDER);
	$blocks = [];
	foreach($m as $match) {
	    $lines = [];
	    foreach(preg_split('/\R/', trim($match[2])) as $line) {
		$line = trim($line);
		if ($line === '' || str_contains($line, ':') || preg_match('/^=[A-Za-z0-9+\/]{4}$/', $line))
		    continue;
		$lines[] = $line;
	    }
	    $data = base64_decode(implode('', $lines), true);
	    $blocks[] = [ 'label' => $match[1], 'pem' => $match[0], 'data' => ($data === false || $data === '') ? null : $data ];
	}
	return $blocks;
    }

    //CLD: 2026-10-20T14:03:59+03:00: the tag of the first OpenPGP packet of $data (RFC 4880 4.2: old and new formats; 5 = secret key, 6 = public key); null: not a packet
    public static function pgpPacketTag($data) {
	if (!is_string($data) || $data === '')
	    return null;
	$b = ord($data[0]);
	if (!($b & 0x80))
	    return null;
	return ($b & 0x40) ? ($b & 0x3F) : (($b >> 2) & 0x0F);
    }

    //CLD: 2026-10-20T14:03:59+03:00: $text is one PEM private key; an unencrypted one is read by OpenSSL
    public static function isPemPrivateKey($text) {
	$blocks = [];
	foreach([ 'PRIVATE KEY', 'ENCRYPTED PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY', 'DSA PRIVATE KEY' ] as $label)
	    $blocks = array_merge($blocks, static::pemBlocks($text, $label));
	if (count($blocks) !== 1 || $blocks[0]['data'] === null)
	    return false;
	$b = $blocks[0];
	if ($b['label'] === 'ENCRYPTED PRIVATE KEY' || str_contains($b['pem'], 'Proc-Type: 4,ENCRYPTED'))
	    return true;
	return @openssl_pkey_get_private($b['pem']) !== false;
    }

    /**
     * Maps method calls to validation rule names.  For example, a validation rule that would
     * normally be called as `Validator::rule('email', 'foo@bar.com')` can also be called as
     * `Validator::isEmail('foo@bar.com')`.
     *
     * @param string $method The name of the method called, i.e. `'isEmail'` or `'isCreditCard'`.
     * @param array $args
     * @return boolean
     */
    public static function __callStatic($method, $args = array()) {
	if (!isset($args[0])) {
	    return false;
	}
	$args = array_filter($args) + array(0 => $args[0], 1 => 'any', 2 => array());
	$rule = preg_replace("/^is([A-Z][A-Za-z0-9]+)$/", '$1', $method);
	$rule[0] = strtolower($rule[0]);
	return static::rule($rule, $args[0], $args[1], $args[2]);
    }

    /**
     * Checks a single value against a single validation rule in one or more formats.
     *
     * @param string $rule
     * @param mixed $value
     * @param string $format
     * @param array $options
     * @return boolean Returns `true` or `false` indicating whether the validation rule check
     *         succeeded or failed.
     * @filter
     */
    public static function rule($rule, $value, $format = 'any', array $options = array()) {
	if (!isset(static::$_rules[$rule])) {
	    throw new \InvalidArgumentException("Rule `{$rule}` is not a validation rule.");
	}

	$defaults = isset(static::$_options[$rule]) ? static::$_options[$rule] : array();
	$options = (array) $options + $defaults + static::$_options['defaults'];

	$ruleCheck = static::$_rules[$rule];
	$ruleCheck = is_array($ruleCheck) ? $ruleCheck : array($ruleCheck);

	if (!$options['contains'] && !empty($ruleCheck)) {
	    foreach ($ruleCheck as $key => $item) {
		$ruleCheck[$key] = is_string($item) ? "/^{$item}$/" : $item;
	    }
	}

	return static::_checkFormats($ruleCheck, $value, $format, $options);

    }
    /**
     * Perform validation checks against a value using an array of all possible formats for a rule,
     * and an array specifying which formats within the rule to use.
     *
     * @param array $rules All available rules.
     * @return Closure Function returning boolean `true` if validation succeeded, `false` otherwise.
     */
    protected static function _checkFormats($rules, $value, $format, $options) {

	$defaults = array('all' => true);
	$options += $defaults;

	$formats = (array) $format;
	$options['all'] = ($format === 'any');

	foreach ($rules as $index => $check) {
	    if (!$options['all'] && !(in_array($index, $formats) || isset($formats[$index]))) {
		continue;
	    }

	    $regexPassed = (is_string($check) && preg_match($check, $value));
	    $closurePassed = (is_object($check) && $check($value, $format, $options));

	    if (!$options['all'] && ($regexPassed || $closurePassed)) {
		return true;
	    }
	    if ($options['all'] && (!$regexPassed && !$closurePassed)) {
		return false;
	    }
	}
	return $options['all'];
    }


	/**
	 * Checks a set of values against a specified rules list. This method may be used to validate
	 * any arbitrary array of data against a set of validation rules.
	 *
	 * @param array $values An array of key/value pairs, where the values are to be checked.
	 * @param array $rules An array of rules to check the values in `$values` against. Each key in
	 *        `$rules` should match a key contained in `$values`, and each value should be a
	 *        validation rule in one of the allowable formats. For example, if you are
	 *        validating a data set containing a `'credit_card'` key, possible values for
	 *        `$rules` would be as follows:
	 *        - `array('credit_card' => 'You must include a credit card number')`: This is the
	 *          simplest form of validation rule, in which the value is simply a message to
	 *          display if the rule fails. Using this format, all other validation settings
	 *          inherit from the defaults, including the validation rule itself, which only
	 *          checks to see that the corresponding key in `$values` is present and contains
	 *          a value that is not empty. _Please note when globalizing validation messages:_
	 *          When specifying messages, it may be preferable to use a code string (i.e.
	 *          `'ERR_NO_TITLE'`) instead of the full text of the validation error. These code
	 *          strings may then be translated by the appropriate tools in the templating layer.
	 *         - `array('credit_card' => array('creditCard', 'message' => 'Invalid CC #'))`:
	 *           In the second format, the validation rule (in this case `creditCard`) and
	 *           associated configuration are specified as an array, where the rule to use is
	 *           the first value in the array (no key), and additional settings are specified
	 *           as other keys in the array. Please see the list below for more information on
	 *           allowed keys.
	 *         - The final format allows you to apply multiple validation rules to a single
	 *           value, and it is specified as follows:
	 *           `array('credit_card' => array(
	 *                array('notEmpty', 'message' => 'You must include credit card number'),
	 *                array('creditCard', 'message' => 'Your credit card number must be valid')
	 *           ));`
	 * @param array $options Validator-specific options.
	 *        Each rule defined as an array can contain any of the following settings
	 *        (in addition to the first value, which represents the rule to be used):
	 *        - `'message'` _string_: The error message to be returned if the validation
	 *          rule fails. See the note above regarding globalization of error messages.
	 *        - `'required`' _boolean_: Represents whether the value is required to be
	 *          present in `$values`. If `'required'` is set to `false`, the validation rule
	 *          will be skipped if the corresponding key is not present. Defaults to `true`.
	 *        - `'skipEmpty'` _boolean_: Similar to `'required'`, this setting (if `true`)
	 *          will cause the validation rule to be skipped if the corresponding value
	 *          is empty (an empty string or `null`). Defaults to `false`.
	 *        - `'format'` _string_: If the validation rule has multiple format definitions
	 *          (see the `add()` or `__init()` methods), the name of the format to be used
	 *          can be specified here. Additionally, two special values can be used:
	 *          either `'any'`, which means that all formats will be checked and the rule
	 *          will pass if any format passes, or `'all'`, which requires all formats to
	 *          pass in order for the rule check to succeed.
	 * @return array Returns an array containing all validation failures for data in `$values`,
	 *         where each key matches a key in `$values`, and each value is an array of
	 *         that element's validation errors.
	 * @filter
	 */
	public static function check(array $values, array $arules, array $options = array()) {
		$defaults = array(
			'notEmpty',
			'message' => null,
			'required' => true,
			'skipEmpty' => false,
			//CLD: 2026-10-09T08:29:52+03:00: removed skipBlank (skipEmpty itself no longer skips 0 / '0')
			'format' => 'any',
			'on' => null,
			'last' => false,
			'translate' => true,
		);

//		\V::app()->log->debug("values=" . print_r($values, true) );
//		\V::app()->log->debug("rules=" . print_r($arules, true));

		$options += $defaults;

		$errors = array();
		$events = (array) (isset($options['events']) ? $options['events'] : null);
		$o_values = $values;
		$values = Set::flatten($values);
//		\V::app()->log->debug("flat values=" . print_r($values, true) );


		//\V::app()->log->debug("    Events:" . print_r($events, true) );
		

		foreach ($arules as $field => $rules) {
		    $rules = is_string($rules) ? array('message' => $rules) : $rules;
		    $rules = is_array(current($rules)) ? $rules : array($rules);
		    $errors[$field] = array();
		    $options['field'] = $field;

		    foreach ($rules as $key => $rule) {
			$rule += $options + compact('values');
			list($name) = $rule;

			//\V::app()->log->debug("checking rule:" . print_r($rule, true));


			if ($events && $rule['on'] && !array_intersect($events, (array) $rule['on'])) {
			    //\V::app()->log->debug("Validator::check: not checking rule!!!");
			    //echo "not checking rule!!!";
			    continue;
			}

			//SC: 2020-05-23: moved empty check before checking key existence
			//CLD: 2026-10-09T08:29:52+03:00: only blank values are skipped (missing, null, '', [], false); 0 and '0' are values (were skipped through empty(), so
			//e.g. a range rule never saw 0)
			$__blank = array_key_exists($field, $o_values) ? $o_values[$field] : ($values[$field] ?? null);
			if ($rule['skipEmpty'] && ($__blank === null || $__blank === '' || $__blank === [] || $__blank === false)) {
			    //\V::app()->log->debug("has skipEmpty, ignore rule");
			    continue;
			}

			//CLD: 2026-10-14T14:18:23+03:00: `requiredIf` runs on any value: a missing one (empty; was skipped, so fields needed 'default' => '') and a list (was skipped)
			$__anyValue = $name === 'requiredIf';

			//SC: 2022-03-17: check flatten and original values for key existence
			if (!$__anyValue && !array_key_exists($field, $values) && !array_key_exists($field, $o_values)) {
			    if ($rule['required']) {
				$errors[$field][] = $rule['message'] ?: $key;
			    }
			    if ($rule['last']) {
				break;
			    }
			    continue;
			}

			//SC: 2022-03-17: extract value 
			$__value = $__anyValue ? $__blank : ( (strpos($field, '.') === false) ? $o_values[$field] : $values[$field] );
			//do not run rules on array
			//SC: 2024-03-04: except: empty arrays & required
			//CLD: 2026-10-18T13:10:00+03:00: except `choices` (checks each value of a list)
			if (!$__anyValue && $name !== 'choices' && is_array($__value)) {
			    if ($rule['required'] && empty($__value)) {
				$errors[$field][] = $rule['message'] ?: $key;
			    }
			    continue;
			}

			if (!static::rule($name, $__value, $rule['format'], $rule + $options)) {
			    //SC: 2019-07-16: add translation if rule fails
			    $message = $rule['message'] ?: $key;
			    $message = $options['translate'] ? \V::t($message) : $message;
			    $errors[$field][] = $message;
			    //$errors[$field][] = $rule['message'] ?: $key;

			    if ($rule['last']) {
				break;
			    }
			}
		    }
		}
		return array_filter($errors);
	}

    /**
     * Adds to or replaces built-in validation rules specified in `Validator::$_rules`.  Any new
     * validation rules created are automatically callable as validation methods.
     *
     * For example:
     * {{{
     * Validator::add('zeroToNine', '/^[0-9]$/');
     * $isValid = Validator::isZeroToNine("5"); // true
     * $isValid = Validator::isZeroToNine("20"); // false
     * }}}
     *
     * Alternatively, the first parameter may be an array of rules expressed as key/value pairs,
     * as in the following:
     * {{{
     * Validator::add(array(
     * 	'zeroToNine' => '/^[0-9]$/',
     * 	'tenToNineteen' => '/^1[0-9]$/',
     * ));
     * }}}
     *
     * In addition to regular expressions, validation rules can also be defined as full anonymous
     * functions:
     * {{{
     * use app\models\Account;
     *
     * Validator::add('accountActive', function($value) {
     * 	$value = is_int($value) ? Account::find($value) : $value;
     * 	return (boolean) $value->is_active;
     * });
     *
     * $testAccount = Account::create(array('is_active' => false));
     * Validator::isAccountActive($testAccount); // returns false
     * }}}
     *
     * These functions can take up to 3 parameters:
     * 	- `$value` _mixed_: This is the actual value to be validated (as in the above example).
     * 	- `$format` _string_: Often, validation rules come in multiple "formats", for example:
     * 	  postal codes, which vary by country or region. Defining multiple formats allows you to
     * 	  retian flexibility in how you validate data. In cases where a user's country of origin
     * 	  is known, the appropriate validation rule may be selected. In cases where it is not
     * 	  known, the value of `$format` may be `'any'`, which should pass if any format matches.
     * 	  In cases where validation rule formats are not mutually exclusive, the value may be
     * 	  `'all'`, in which case all must match.
     * 	- `$options` _array_: This parameter allows a validation rule to implement custom
     * 	  options.
     *
     * @see lithium\util\Validator::$_rules
     * @param mixed $name The name of the validation rule (string), or an array of key/value pairs
     *        of names and rules.
     * @param string $rule If $name is a string, this should be a string regular expression, or a
     *        closure that returns a boolean indicating success. Should be left blank if
     *        `$name` is an array.
     * @param array $options The default options for validating this rule. An option which applies
     *        to all regular expression rules is `'contains'` which, if set to true, allows
     *        validated values to simply _contain_ a match to a rule, rather than exactly
     *        matching it in whole.
     */
    public static function add($name, $rule = null, array $options = array()) {
	if (!is_array($name)) {
	    $name = array($name => $rule);
	}
	static::$_rules = Set::merge(static::$_rules, $name);

	if (!empty($options)) {
	    $options = array_combine(array_keys($name), array_fill(0, count($name), $options));
	    static::$_options = Set::merge(static::$_options, $options);
	}
    }


    /**
     * Returns a list of available validation rules, or the configuration details of a single rule.
     *
     * @param string $name Optional name of a rule to get the details of. If not specified, an array
     *        of all available rule names is returned. Otherwise, returns the details of a
     *        single rule. This can be a regular expression string, a closure object, or an
     *        array of available rule formats made up of string regular expressions,
     *        closures, or both.
     * @return mixed Returns either an single array of rule names, or the details of a single rule.
     */
    public static function rules($name = null) {
	if (!$name) {
	    return array_keys(static::$_rules);
	}
	return isset(static::$_rules[$name]) ? static::$_rules[$name] : null;
    }

}

// initialize
Validator::__init();
