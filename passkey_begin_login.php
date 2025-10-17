<?php
// passkey_begin_login.php — Begin WebAuthn assertion for a specific email
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';

try {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email.');
    }

    // Normalize RP parameters (domain only, no scheme/port)
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme  = $isHttps ? 'https' : 'http';
    $hostHdr = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $rpId    = strtolower(preg_replace('/:\d+$/', '', $hostHdr));

    if (filter_var($rpId, FILTER_VALIDATE_IP)) {
        if ($rpId === '127.0.0.1' || $rpId === '::1') $rpId = 'localhost';
        else throw new Exception('RP ID cannot be an IP. Use a domain or localhost.');
    }
    $origin = $scheme . '://' . $hostHdr;
    if ($scheme !== 'https' && $rpId !== 'localhost') {
        throw new Exception('WebAuthn requires HTTPS (localhost is allowed over HTTP).');
    }

    // Find user
    if (!$st = $conn->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1")) {
        throw new Exception('DB error.');
    }
    $st->bind_param("s", $email);
    $st->execute(); $rs = $st->get_result(); $user = $rs ? $rs->fetch_assoc() : null; $st->close();
    if (!$user) throw new Exception('No account found for that email.');
    $uid = (int)$user['id'];

    // Pull that user's credential IDs
    $credIds = [];
    if ($ps = $conn->prepare("SELECT cred_id FROM passkeys WHERE user_id=?")) {
        $ps->bind_param("i", $uid);
        $ps->execute();
        $res = $ps->get_result();
        while ($row = $res->fetch_assoc()) {
            $cid = (string)$row['cred_id']; // BLOB string
            // send to browser as base64url
            $credIds[] = rtrim(strtr(base64_encode($cid), '+/', '-_'), '=');
        }
        $ps->close();
    }
    if (!$credIds) {
        throw new Exception('No passkeys found for this account. Use your password or add a passkey in Settings.');
    }

    // Create a fresh challenge and persist
    $challenge = random_bytes(32);
    $_SESSION['webauthn_assert'] = [
        'challenge' => $challenge,
        'uid'       => $uid,
        'rpId'      => $rpId,
        'origin'    => $origin,
    ];

    // Build PublicKeyCredentialRequestOptions
    $publicKey = [
        // challenge is base64url
        'challenge' => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
        'rpId'      => $rpId,
        'timeout'   => 60000,
        'userVerification' => 'required',
        'allowCredentials' => array_map(function($idB64url){
            return [
                'type' => 'public-key',
                'id'   => $idB64url,
                'transports' => ['internal','hybrid','usb','nfc','ble'],
            ];
        }, $credIds),
    ];

    echo json_encode(['ok' => true, 'publicKey' => $publicKey]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}