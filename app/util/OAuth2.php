<?php

/*
    OAuth 2.0 authorization code flow with PKCE (RFC 7636), provider independent;
    used by the OAuth credential types (models\credential\CredentialOAuth2).
*/

namespace util;

class OAuth2 {
    //PKCE verifier
    public static function newVerifier() {
	return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function challenge($verifier) {
	return rtrim(strtr(base64_encode(hash('sha256', (string)$verifier, true)), '+/', '-_'), '=');
    }

    //URL of the provider's consent page
    public static function authUrl($endpoint, array $params) {
	$params = array_filter($params, fn($v) => $v !== null && $v !== '');
	return $endpoint . (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    //POST to the token endpoint; the answer [ access_token, refresh_token, expires_in, id_token, ... ] or an exception
    //through util\Http
    public static function token($endpoint, array $params) {
	$r = Http::json('POST', $endpoint, [ 'form' => $params, 'timeout' => 20 ]);
	$status = $r['status'];
	if ($r['error'] !== null)
	    throw new \Exception('token request failed: ' . $r['error']);
	$data = $r['data'];
	if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
	    $msg = is_array($data) ? trim(($data['error'] ?? '') . ': ' . ($data['error_description'] ?? ''), ': ') : '';
	    throw new \Exception('token request refused (HTTP ' . $status . ')' . ($msg !== '' ? ': ' . $msg : ''));
	}
	return $data;
    }

    //the account address from an id_token received directly from the token endpoint over TLS (signature not checked)
    public static function emailFromIdToken($idToken) {
	$parts = explode('.', (string)$idToken);
	if (count($parts) < 2)
	    return null;
	$claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
	if (!is_array($claims))
	    return null;
	$email = $claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? null;
	return (is_string($email) && strpos($email, '@') !== false) ? strtolower($email) : null;
    }
}
