<?php
// passkey_begin_register.php — Begin WebAuthn registration
// Returns a PublicKeyCredentialCreationOptions object (with helper hex fields your JS converts to bytes)

require_once __DIR__ . '/ppf_debug.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ppf_passkeys.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

try {
    $uid   = (int)($_SESSION['user_id'] ?? 0);
    $email = (string)($_SESSION['email'] ?? '');
    $first = (string)($_SESSION['first_name'] ?? '');
    $last  = (string)($_SESSION['last_name'] ?? '');
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh and try again.']);
        exit;
    }

    $emailVerifiedAt = (int)($_SESSION['passkey_email_verified'] ?? 0);
    if ($emailVerifiedAt === 0 || (time() - $emailVerifiedAt) > 15 * 60) {
        echo json_encode(['ok' => false, 'error' => 'Verify the email confirmation code before adding a passkey.']);
        exit;
    }

    $name = trim((string)($_POST['name'] ?? 'My Passkey'));
    if ($name === '') $name = 'My Passkey';

    // ----- Determine origin and rpId safely -----
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme  = $isHttps ? 'https' : 'http';
    $hostHdr = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'); // might include :port
    $host    = strtolower(preg_replace('/:\d+$/', '', $hostHdr));                 // strip optional :port and lowercase

    // WebAuthn requires domain-like rpId (no scheme/port). IPs are not allowed, except localhost.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if ($host === '127.0.0.1' || $host === '::1') {
            $host = 'localhost';
        } else {
            // Recommend using a dev hostname (e.g., ppf.local) pointing to your server
            throw new RuntimeException('RP ID cannot be an IP. Use a domain or localhost.');
        }
    }

    // WebAuthn needs a secure context: https or http+localhost
    $origin = $scheme . '://' . $hostHdr; // origin must preserve the port if any
    if ($scheme !== 'https' && $host !== 'localhost') {
        throw new RuntimeException('WebAuthn requires HTTPS (localhost is allowed over HTTP).');
    }

    // ----- Challenge (raw bytes kept server-side) -----
    $challenge      = random_bytes(32);
    $challenge_hex  = bin2hex($challenge);

    // ----- User handle (opaque bytes). Use stable per-user handle if you have one in DB; random is okay too. -----
    // If you have a stored stable handle, load and reuse it; otherwise random 16 bytes is fine.
    $user_id_bin = random_bytes(16);
    $user_id_hex = bin2hex($user_id_bin);

    // Persist registration state for the finish step
    $_SESSION['webauthn_reg'] = [
        'challenge_bin' => $challenge,
        'user_id_bin'   => $user_id_bin,
        'name'          => $name,
        'rpId'          => $host,     // strict host, no port/scheme
        'origin'        => $origin,   // exact origin (may include :port)
    ];

    // Build PublicKeyCredentialCreationOptions (with helper hex fields your JS converts to bytes)
    $publicKey = [
        'rp' => [
            'name' => 'Peter Pang Fit',
            'id'   => $host,
        ],
        'user' => [
            // Your JS converts idHex -> Uint8Array; do not also send string id
            'idHex'       => $user_id_hex,
            'name'        => $email !== '' ? $email : ("user{$uid}@example.com"),
            'displayName' => trim("$first $last") !== '' ? trim("$first $last") : "User {$uid}",
        ],
        'challengeHex' => $challenge_hex,
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],    // ES256
            ['type' => 'public-key', 'alg' => -257],  // RS256
        ],
        'attestation' => 'none',
        'timeout'     => 60000,
    ];

    echo json_encode(['ok' => true, 'publicKey' => $publicKey]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}