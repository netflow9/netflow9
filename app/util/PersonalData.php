<?php

/*
    the personal data of an AI step's run, hidden from a cloud provider (util\AgentGuard, setting Mask personal data): email addresses, phone numbers and
    IBANs in a text become placeholders ([EMAIL_ab12_1], the same value always the same one, ab12 random per run: a text from outside cannot name one); restore() puts the values back in
    what the model answers and in a tool's arguments. By pattern, so best effort: a number without a prefix or separators (40721234567) is not known to be a phone; IBANs are checked (mod 97).
    Tool results are mostly JSON: a value right after an escaped line break (\n) still counts as a value's start. A text the patterns cannot be run on is not sent (masked whole)
*/

namespace util;

class PersonalData {
    //a value starts after none of these, or after an escaped \n \r \t (JSON)
    const START = '(?:(?<![\p{L}\p{N}_.+@\/-])|(?<=\\\\[nrt]))';
    const EMAIL = '/[\p{L}\p{N}._%+\'-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}/u';
    //groups of 4 with single spaces, a shorter last one; the words after it are given back (iban())
    const IBAN = '/' . self::START . '[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?(?![\p{L}\p{N}_])/iu';
    //8 to 19 digits with at most two separators between them; which ones are phones: phone()
    const PHONE = '/' . self::START . '\+?\(?\d(?:[ \-().\/]{0,2}\d){7,18}(?![\p{L}\p{N}_@]|[.\-]\d)/u';
    const NOT_SENT = '[this text was not sent: its personal data could not be masked]';

    //[ value => placeholder ], the part of the placeholders that is random, the last number of each kind
    private $values = [];
    private $run;
    private $numbers = [];

    public function __construct(array $saved = []) {
	$this->values = (array)($saved['values'] ?? []);
	$this->run = (string)($saved['run'] ?? bin2hex(random_bytes(2)));
	$this->numbers = (array)($saved['numbers'] ?? []);
    }

    //what a resumed run needs (a run that waits for an approval)
    public function save() {
	return [ 'values' => $this->values, 'run' => $this->run, 'numbers' => $this->numbers ];
    }

    //an example of the placeholders, for the system prompt
    public function example($kind) {
	return "[{$kind}_{$this->run}_1]";
    }

    public function mask($text) {
	$text = mb_scrub((string)$text, 'UTF-8');
	$text = preg_replace_callback(self::EMAIL, fn($m) => $this->placeholder('EMAIL', $m[0]), $text);
	$text = $text === null ? null : preg_replace_callback(self::IBAN, fn($m) => $this->iban($m[0]), $text);
	$text = $text === null ? null : preg_replace_callback(self::PHONE, fn($m) => $this->phone($m[0]), $text);
	return $text ?? self::NOT_SENT;
    }

    //$value with the placeholders back: a text, or a list / object's texts
    public function restore($value) {
	if (is_string($value))
	    return $this->values ? strtr($value, array_flip($this->values)) : $value;
	if (is_array($value))
	    return array_map(fn($v) => $this->restore($v), $value);
	return $value;
    }

    private function placeholder($kind, $value) {
	if (!isset($this->values[$value])) {
	    $this->numbers[$kind] = (int)($this->numbers[$kind] ?? 0) + 1;
	    $this->values[$value] = "[{$kind}_{$this->run}_{$this->numbers[$kind]}]";
	}
	return $this->values[$value];
    }

    //the longest run of groups in $s that is an IBAN (the words after it are not), the rest as it is
    private function iban($s) {
	$tokens = explode(' ', $s);
	for($n = count($tokens); $n > 0; $n--) {
	    $iban = implode(' ', array_slice($tokens, 0, $n));
	    if (self::validIban(str_replace(' ', '', $iban)))
		return $this->placeholder('IBAN', $iban) . ($n < count($tokens) ? ' ' . implode(' ', array_slice($tokens, $n)) : '');
	}
	return $s;
    }

    public static function validIban($iban) {
	$iban = strtoupper($iban);
	$length = strlen($iban);
	if ($length < 15 || $length > 34)
	    return false;
	$rest = 0;
	foreach(str_split(substr($iban, 4) . substr($iban, 0, 4)) as $c)
	    foreach(str_split(ctype_digit($c) ? $c : (string)(ord($c) - 55)) as $d)
		$rest = ($rest * 10 + (int)$d) % 97;
	return $rest === 1;
    }

    //the phone in $s: all of it, else what follows its first word (`100 0721234567`)
    private function phone($s) {
	if (self::isPhone($s))
	    return $this->placeholder('PHONE', $s);
	$space = strpos($s, ' ');
	return $space === false ? $s : substr($s, 0, $space + 1) . $this->phone(substr($s, $space + 1));
    }

    //with a + (8 to 15 digits), a leading 0 (9 to 15 digits; not 0.123456789), or written 212-555-0100 / (212) 555 0100. Dates, addresses and other numbers are none of these
    public static function isPhone($s) {
	$digits = strlen(preg_replace('/\D/', '', $s));
	if ($s[0] === '+')
	    return $digits >= 8 && $digits <= 15;
	if ($s[0] === '0' || str_starts_with($s, '(0'))
	    return $digits >= 9 && $digits <= 15 && !preg_match('/^0\.\d+$/', $s);
	return (bool)preg_match('/^\(?\d{3}\)?[ .\-]{1,2}\d{3}[ .\-]\d{4}$/', $s);
    }
}
