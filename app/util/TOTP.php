<?php

namespace util;

class TOTP {
    private static $_base32dict = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567=';
    private static $_base32 = [];
    private static $_base32lookup = [];

    private $issuer = 'NetFlow9';
    private $digits = 6;
    private $period = 30;
    private $algorithm = 'SHA256';

    public function __construct($issuer = '', $digits = 6, $period = 30, $algorithm = 'sha1') {
	$this->issuer = $issuer;
	$this->digits = $digits;
	$this->period = $period;
	$this->algorithm = strtoupper($algorithm);

        self::$_base32 = str_split(self::$_base32dict);
        self::$_base32lookup = array_flip(self::$_base32);

	if (empty($this->issuer))
	    $this->issuer = \V::app()->config('app.name');

    }

    private function getTime($time = null) {
	if (!empty($time))
	    return $time;

	$provider = \V::app()->config('app.ntp.server');
	if (empty($provider) || $provider == 'local')
	    return time();

	//try to connect to ntp
	$hostport = explode(':', $provider);
	$hostport[1] = $hostport[1] ?? 123;

        try {
            // Create a socket and connect to NTP server
            $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, [ 'sec' => 10, 'usec' => 0 ]);
            socket_connect($sock, $hostport[0], $hostport[1]);

            // Send request
            $msg = "\010" . str_repeat("\0", 47);
            socket_send($sock, $msg, strlen($msg), 0);

            // Receive response and close socket
            if (socket_recv($sock, $recv, 48, MSG_WAITALL) === false) {
                throw new \Exception(socket_strerror(socket_last_error($sock)));
            }
            socket_close($sock);

            // Interpret response
            $data = unpack('N12', $recv);
            $timestamp = (int)sprintf('%u', $data[9]);

            // NTP is number of seconds since 0000 UT on 1 January 1900 Unix time is seconds since 0000 UT on 1 January 1970
            return $timestamp - 2208988800;
        } catch (\Exception $ex) {
            throw new \Exception(sprintf('Unable to retrieve time from %s (%s)', $hostport[0], $ex->getMessage()));
        }
    }


    // Create a new secret
    public function createSecret($bits = 80) {
        $secret = '';
        $bytes = (int)ceil($bits / 5);   // We use 5 bits of each byte (since we have a 32-character 'alphabet' / BASE32)

        $rnd = random_bytes($bytes);
        for ($i = 0; $i < $bytes; $i++) {
            $secret .= self::$_base32[ord($rnd[$i]) & 31];  //Mask out left 3 bits for 0-31 values
        }
        return $secret;
    }

    // Calculate the code with given secret and current time
    public function getCode($secret, $time = null) {
        $secretkey = $this->base32Decode(strtoupper($secret));

        $timestamp = "\0\0\0\0" . pack('N*', $this->getTimeSlice($this->getTime($time)));  // Pack time into binary string
        $hashhmac = hash_hmac($this->algorithm, $timestamp, $secretkey, true);             // Hash it with users secret key
        $hashpart = substr($hashhmac, ord(substr($hashhmac, -1)) & 0x0F, 4);               // Use last nibble of result as index/offset and grab 4 bytes of the result
        $value = unpack('N', $hashpart);                                                   // Unpack binary value
        $value = $value[1] & 0x7FFFFFFF;                                                   // Drop MSB, keep only 31 bits

        return str_pad((string)($value % 10 ** $this->digits), $this->digits, '0', STR_PAD_LEFT);
    }

    // Check if the code is correct. This will accept codes starting from ($discrepancy * $period) sec ago to ($discrepancy * period) sec from now
    public function verifyCode($secret, $code, $discrepancy = 1, $time = null, &$timeslice = 0) {
        $timestamp = $this->getTime($time);

        $timeslice = 0;

        // To keep safe from timing-attacks we iterate *all* possible codes even though we already may have
        // verified a code is correct. We use the timeslice variable to hold either 0 (no match) or the timeslice
        // of the match. Each iteration we either set the timeslice variable to the timeslice of the match
        // or set the value to itself.  This is an effort to maintain constant execution time for the code.
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $ts = $timestamp + ($i * $this->period);
            $slice = $this->getTimeSlice($ts);
            $timeslice = ($this->codeEquals($this->getCode($secret, $ts), $code) ? $slice : $timeslice);
        }

        return $timeslice > 0;
    }

    // Builds a string to be encoded in a QR code
    public function getURI($label, $secret) {
	$query = [
	    'secret' => $secret,
	    'issuer' => $this->issuer,
	    'period' => $this->period,
	    'algorithm' => $this->algorithm,
	    'digits' => $this->digits,
	];
        return 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query($query);
    }

    // Timing-attack safe comparison of 2 codes (see http://blog.ircmaxell.com/2014/11/its-all-about-time.html)
    private function codeEquals($safe, $user) {
        return hash_equals($safe, $user);
    }

    private function getTimeSlice($time = null, $offset = 0) {
        return (int)floor($time / $this->period) + ($offset * $this->period);
    }

    private function base32Decode($value) {
        if ($value === '')
            return '';

        if (preg_match('/[^' . preg_quote(self::$_base32dict, '/') . ']/', $value) !== 0) {
            throw new \Exception('Invalid base32 string');
        }

        $buffer = '';
        foreach (str_split($value) as $char) {
            if ($char !== '=') {
                $buffer .= str_pad(decbin(self::$_base32lookup[$char]), 5, '0', STR_PAD_LEFT);
            }
        }
        $length = strlen($buffer);
        $blocks = trim(chunk_split(substr($buffer, 0, $length - ($length % 8)), 8, ' '));

        $output = '';
        foreach (explode(' ', $blocks) as $block) {
            $output .= chr(bindec(str_pad($block, 8, '0', STR_PAD_RIGHT)));
        }
        return $output;
    }
}
