<?php
/**
 * AgeWallet OIDC Client
 *
 * A single-file, drop-in PHP client for AgeWallet age verification.
 * Handles OAuth2/OIDC authentication flow with PKCE support.
 *
 * @package     AgeWallet
 * @version     1.0.0
 * @link        https://github.com/agewallet-client/agewallet-php-client
 * @link        https://agewallet.io
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

$env = 'prod'; // 'prod' or 'dev'

$baseUrl = ($env === 'dev')
    ? 'https://dev.agewallet.io'
    : 'https://app.agewallet.io';

$GLOBALS['agewallet_config'] = [
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => '',
    'redirect_uri'  => 'https://yoursite.com/',

    'issuer'        => $baseUrl,
    'authorize_url' => $baseUrl . '/user/authorize',
    'token_url'     => $baseUrl . '/user/token',
    'jwks_uri'      => $baseUrl . '/.well-known/jwks.json',

    'scopes'        => 'openid age',
    'clock_skew'    => 60,
];

// ============================================================================
// INTERNAL HELPERS
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function _aw_random(int $len = 32): string {
    return bin2hex(random_bytes($len));
}

function _aw_base64url_decode(string $data): string {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 ? strlen($data) + 4 - strlen($data) % 4 : strlen($data), '='));
}

function _aw_parse_jwt(string $jwt): array {
    $parts = explode('.', $jwt);
    return [
        'header'  => json_decode(_aw_base64url_decode($parts[0]), true),
        'payload' => json_decode(_aw_base64url_decode($parts[1]), true),
        'sig'     => $parts[2],
        'signed'  => $parts[0] . '.' . $parts[1],
    ];
}

function _aw_asn1_len(int $len): string {
    if ($len < 0x80) return chr($len);
    $t = ltrim(pack('N', $len), "\x00");
    return chr(0x80 | strlen($t)) . $t;
}

function _aw_jwk_to_pem(array $jwk): string {
    $n = _aw_base64url_decode($jwk['n']);
    $e = _aw_base64url_decode($jwk['e']);

    $mod = ltrim($n, "\x00");
    if (ord($mod[0]) > 0x7f) $mod = "\x00" . $mod;
    $exp = ltrim($e, "\x00");
    if (ord($exp[0]) > 0x7f) $exp = "\x00" . $exp;

    $mod = "\x02" . _aw_asn1_len(strlen($mod)) . $mod;
    $exp = "\x02" . _aw_asn1_len(strlen($exp)) . $exp;
    $seq = "\x30" . _aw_asn1_len(strlen($mod . $exp)) . $mod . $exp;

    $oid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bit = "\x03" . _aw_asn1_len(strlen($seq) + 1) . "\x00" . $seq;
    $spki = "\x30" . _aw_asn1_len(strlen($oid . $bit)) . $oid . $bit;

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64) . "-----END PUBLIC KEY-----\n";
}

function _aw_verify_jwt(string $jwt, string $jwksUri): bool {
    $parsed = _aw_parse_jwt($jwt);
    $jwksData = @file_get_contents($jwksUri);
    if (!$jwksData) return false;

    $jwks = json_decode($jwksData, true)['keys'] ?? [];

    $key = null;
    foreach ($jwks as $k) {
        if (isset($parsed['header']['kid'], $k['kid']) && $parsed['header']['kid'] === $k['kid']) {
            $key = $k;
            break;
        }
        if (($k['kty'] ?? '') === 'RSA') $key = $key ?? $k;
    }

    if (!$key) return false;

    $algs = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
    $alg = $algs[$parsed['header']['alg'] ?? ''] ?? OPENSSL_ALGO_SHA256;

    return openssl_verify($parsed['signed'], _aw_base64url_decode($parsed['sig']), _aw_jwk_to_pem($key), $alg) === 1;
}

function _aw_validate_claims(array $claims, array $config, string $nonce): bool {
    $now = time();
    $skew = $config['clock_skew'];

    if (($claims['iss'] ?? '') !== $config['issuer']) return false;

    $aud = $claims['aud'] ?? '';
    if (is_array($aud) ? !in_array($config['client_id'], $aud) : $aud !== $config['client_id']) return false;

    if (($claims['exp'] ?? 0) < $now - $skew) return false;
    if (isset($claims['iat']) && $claims['iat'] > $now + $skew) return false;
    if (($claims['nonce'] ?? '') !== $nonce) return false;

    return true;
}

function _aw_exchange_code(string $code, string $verifier, array $config): ?array {
    $params = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $config['redirect_uri'],
        'client_id'     => $config['client_id'],
        'code_verifier' => $verifier,
    ];
    if (!empty($config['client_secret'])) {
        $params['client_secret'] = $config['client_secret'];
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($params),
        'timeout' => 10,
    ]]);

    $resp = @file_get_contents($config['token_url'], false, $ctx);
    return $resp ? json_decode($resp, true) : null;
}

// ============================================================================
// PUBLIC API
// ============================================================================

/**
 * Check if user is age-verified
 *
 * @return bool
 */
function agewallet_is_verified(): bool {
    return !empty($_SESSION['aw_verified']) && $_SESSION['aw_verified'] === true;
}

/**
 * Get verified claims (sub, age_verified, etc.)
 *
 * @return array|null
 */
function agewallet_get_claims(): ?array {
    return $_SESSION['aw_claims'] ?? null;
}

/**
 * Clear verification status
 *
 * @return void
 */
function agewallet_reset(): void {
    unset($_SESSION['aw_verified'], $_SESSION['aw_claims']);
}

/**
 * Start the OAuth flow - redirects to AgeWallet
 *
 * @return void
 */
function agewallet_start_auth(): void {
    $config = $GLOBALS['agewallet_config'];

    $state = _aw_random(16);
    $nonce = _aw_random(16);
    $verifier = _aw_random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $_SESSION['aw_state'] = $state;
    $_SESSION['aw_nonce'] = $nonce;
    $_SESSION['aw_verifier'] = $verifier;

    $url = $config['authorize_url'] . '?' . http_build_query([
        'response_type'         => 'code',
        'client_id'             => $config['client_id'],
        'redirect_uri'          => $config['redirect_uri'],
        'scope'                 => $config['scopes'],
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    header('Location: ' . $url);
    exit;
}

/**
 * Process OAuth callback - call from your redirect_uri handler
 *
 * @return bool True on success, false on failure
 */
function agewallet_process_callback(): bool {
    $config = $GLOBALS['agewallet_config'];

    $_SESSION['aw_verified'] = false;

    if (isset($_GET['error'])) {
        return false;
    }

    if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['aw_state'] ?? '')) {
        return false;
    }

    $tokens = _aw_exchange_code($_GET['code'] ?? '', $_SESSION['aw_verifier'] ?? '', $config);
    if (!$tokens || empty($tokens['id_token'])) {
        return false;
    }

    if (!_aw_verify_jwt($tokens['id_token'], $config['jwks_uri'])) {
        return false;
    }

    $claims = _aw_parse_jwt($tokens['id_token'])['payload'];
    if (!_aw_validate_claims($claims, $config, $_SESSION['aw_nonce'] ?? '')) {
        return false;
    }

    unset($_SESSION['aw_state'], $_SESSION['aw_nonce'], $_SESSION['aw_verifier']);

    $_SESSION['aw_verified'] = true;
    $_SESSION['aw_claims'] = $claims;

    return true;
}

// ============================================================================
// STANDALONE MODE
// ============================================================================

if (defined('AGEWALLET_INCLUDE')) {
    return;
}

$action = $_GET['action'] ?? 'check';

switch ($action) {
    case 'check':
        echo agewallet_is_verified() ? '1' : '0';
        break;

    case 'start':
        agewallet_start_auth();
        break;

    case 'callback':
        echo agewallet_process_callback() ? '1' : '0';
        break;

    case 'reset':
        agewallet_reset();
        echo '0';
        break;

    default:
        echo '0';
}
