<?php
// passkey_finish_register.php — Finish WebAuthn registration
require_once __DIR__ . '/ppf_debug.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/logs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

/**
 * Decode Base64URL (strict).
 */
function b64url_decode_strict(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $bin = base64_decode($s, true);
    if ($bin === false) throw new RuntimeException('Bad base64url');
    return $bin;
}

/**
 * Small CBOR decoder sufficient to decode the top-level map in attestationObject and extract 'authData'.
 * Supports major types: 0 (unsigned), 2 (byte string), 3 (text), 4 (array), 5 (map).
 * Only what's needed for WebAuthn attestationObject.
 */
function cbor_decode_all(string $buf) {
    $pos = 0;
    return cbor_decode_one($buf, $pos);
}
function cbor_read_len(string $buf, int &$pos, int $ai): int {
    if ($ai < 24) return $ai;
    if ($ai === 24) { $v = ord($buf[$pos] ?? "\0"); $pos += 1; return $v; }
    if ($ai === 25) { $v = unpack('n', substr($buf, $pos, 2))[1] ?? 0; $pos += 2; return $v; }
    if ($ai === 26) { $v = unpack('N', substr($buf, $pos, 4))[1] ?? 0; $pos += 4; return $v; }
    if ($ai === 27) {
        // 64-bit big-endian (network order). PHP 'J' is machine-endian; use manual for portability if needed.
        $chunk = substr($buf, $pos, 8);
        $pos += 8;
        // Read as big-endian unsigned 64, clamp to int
        $parts = unpack('Nhi/Nlo', $chunk ?: str_repeat("\0", 8));
        return (int)(($parts['hi'] << 32) | $parts['lo']);
    }
    throw new RuntimeException('Unsupported CBOR length encoding');
}
function cbor_decode_one(string $buf, int &$pos) {
    $b = ord($buf[$pos] ?? "\0");
    $pos += 1;
    $mt = $b >> 5;       // major type
    $ai = $b & 0x1f;     // additional info

    switch ($mt) {
        case 0: // unsigned int
            return cbor_read_len($buf, $pos, $ai);

        case 2: { // byte string
            $len = cbor_read_len($buf, $pos, $ai);
            $s = (string)substr($buf, $pos, $len);
            $pos += $len;                 // ✅ advance position (bug fix)
            return $s;
        }

        case 3: { // text string
            $len = cbor_read_len($buf, $pos, $ai);
            $s = (string)substr($buf, $pos, $len);
            $pos += $len;                 // ✅ advance position (bug fix)
            return $s;
        }

        case 4: { // array
            $len = cbor_read_len($buf, $pos, $ai);
            $arr = [];
            for ($i = 0; $i < $len; $i++) {
                $arr[] = cbor_decode_one($buf, $pos);
            }
            return $arr;
        }

        case 5: { // map
            $len = cbor_read_len($buf, $pos, $ai);
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $k = cbor_decode_one($buf, $pos);
                $v = cbor_decode_one($buf, $pos);
                $map[$k] = $v;
            }
            return $map;
        }

        default:
            throw new RuntimeException('Unsupported CBOR major type: ' . $mt);
    }
}

try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) throw new RuntimeException('Not authenticated.');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session expired. Refresh the page and try again.');
    }

    $reg = $_SESSION['webauthn_reg'] ?? null;
    if (
        !$reg ||
        !isset($reg['challenge_bin'], $reg['origin'], $reg['rpId'], $reg['name'])
    ) {
        throw new RuntimeException('Registration not initialized.');
    }

    $emailVerifiedAt = (int)($_SESSION['passkey_email_verified'] ?? 0);
    if ($emailVerifiedAt === 0 || (time() - $emailVerifiedAt) > 15 * 60) {
        throw new RuntimeException('Email verification required before adding a passkey.');
    }

    // 1) Read POST from your JS
    $clientDataJSON_b64 = (string)($_POST['clientDataJSON'] ?? '');
    $attObj_b64         = (string)($_POST['attestationObject'] ?? '');
    $password           = (string)($_POST['password'] ?? '');
    if ($clientDataJSON_b64 === '' || $attObj_b64 === '') {
        throw new RuntimeException('Invalid registration payload.');
    }
    if ($password === '') {
        throw new RuntimeException('Enter your current password to finish.');
    }

    // 2) Decode; your JS uses btoa() → standard base64; accept URL-safe too.
    $clientDataJSON_raw = base64_decode(strtr($clientDataJSON_b64, '-_', '+/'), true);
    if ($clientDataJSON_raw === false) $clientDataJSON_raw = b64url_decode_strict($clientDataJSON_b64);

    $attObj_raw = base64_decode(strtr($attObj_b64, '-_', '+/'), true);
    if ($attObj_raw === false) $attObj_raw = b64url_decode_strict($attObj_b64);

    // 3) Parse clientDataJSON
    $client = json_decode($clientDataJSON_raw, true, 512, JSON_THROW_ON_ERROR);

    if (($client['type'] ?? '') !== 'webauthn.create') {
        throw new RuntimeException('wrong clientData type');
    }

    $client_origin = (string)($client['origin'] ?? '');
    if ($client_origin !== $reg['origin']) {
        throw new RuntimeException('origin mismatch');
    }

    // clientData.challenge is base64url of the original raw challenge bytes
    $client_chal_raw = b64url_decode_strict((string)$client['challenge']);
    if (!hash_equals($reg['challenge_bin'], $client_chal_raw)) {
        throw new RuntimeException('challenge mismatch');
    }

    // 4) Parse attestationObject CBOR to extract authData
    $att = cbor_decode_all($attObj_raw); // top-level map: ['fmt'=>..., 'attStmt'=>..., 'authData'=>...]
    if (!is_array($att) || empty($att['authData']) || !is_string($att['authData'])) {
        throw new RuntimeException('bad attestation object');
    }
    $authData = $att['authData'];

    // 5) Parse authData (per WebAuthn)
    $ofs = 0;
    $rpIdHash = substr($authData, $ofs, 32); $ofs += 32;
    $flags    = ord($authData[$ofs]);       $ofs += 1;
    $signCnt  = unpack('N', substr($authData, $ofs, 4))[1]; $ofs += 4;

    // Validate rpIdHash matches session rpId
    $expectedRpHash = hash('sha256', (string)$reg['rpId'], true);
    if (!hash_equals($expectedRpHash, $rpIdHash)) {
        throw new RuntimeException('rpIdHash mismatch');
    }

    // Bits in flags
    $FLAG_AT = 0x40; // Attested credential data included
    if (!(($flags & $FLAG_AT) === $FLAG_AT)) {
        throw new RuntimeException('attested credential data missing');
    }

    // Attested credential data
    $aaguid    = substr($authData, $ofs, 16); $ofs += 16;
    $credIdLen = unpack('n', substr($authData, $ofs, 2))[1]; $ofs += 2;
    if ($credIdLen <= 0 || $credIdLen > 4096) {
        throw new RuntimeException('bad credential id length');
    }
    $credId    = substr($authData, $ofs, $credIdLen); $ofs += $credIdLen;

    // COSE public key (raw CBOR; can be parsed later if needed)
    $coseKeyRaw = substr($authData, $ofs);
    if ($coseKeyRaw === '') {
        throw new RuntimeException('missing COSE public key');
    }

    // verify password & fetch email for notification
    $infoStmt = $conn->prepare("SELECT password_hash, email, first_name, last_name, role FROM users WHERE id=? LIMIT 1");
    if (!$infoStmt) throw new RuntimeException('Unable to load user record.');
    $infoStmt->bind_param('i', $uid);
    $infoStmt->execute();
    $infoRes = $infoStmt->get_result();
    $info = $infoRes ? $infoRes->fetch_assoc() : null;
    $infoStmt->close();

    if (!$info || !password_verify($password, (string)$info['password_hash'])) {
        throw new RuntimeException('Incorrect password.');
    }

    // 6) Persist to DB
    // Ensure your schema:
    // passkeys(user_id INT, name VARCHAR, cred_id BLOB, public_key BLOB, counter INT, created_at DATETIME, last_used_at DATETIME NULL)
    $name  = (string)$reg['name'];
    $zero  = 0;

    if (!$stmt = $conn->prepare("INSERT INTO passkeys (user_id, name, cred_id, public_key, counter, created_at) VALUES (?, ?, ?, ?, ?, NOW())")) {
        throw new RuntimeException('DB prepare failed');
    }

    // Types: i = int, s = string, b = blob, b = blob, i = int
    $stmt->bind_param('isbbi', $uid, $name, $credId, $coseKeyRaw, $zero);
    // send_long_data for blob params (indexing starts from 0)
    $stmt->send_long_data(2, $credId);
    $stmt->send_long_data(3, $coseKeyRaw);

    if (!$stmt->execute()) {
        $err = $stmt->error ?: 'DB insert failed';
        $stmt->close();
        throw new RuntimeException($err);
    }
    $stmt->close();

    $_SESSION['passkey_email_verified'] = null;

        // --- Notify the user that a new passkey was added ---
try {
    // Load user info
    $uemail = ''; $ufirst=''; $ulast='';
    if ($st = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE id=? LIMIT 1")) {
        $st->bind_param("i", $uid);
        $st->execute();
        $r = $st->get_result();
        if ($r && ($rowU = $r->fetch_assoc())) {
            $uemail = (string)$rowU['email'];
            $ufirst = (string)($rowU['first_name'] ?? '');
            $ulast  = (string)($rowU['last_name'] ?? '');
        }
        $st->close();
    }

    // Compose email
    $pkName = (string)$reg['name']; // same value you inserted into DB
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    $ts     = date('Y-m-d H:i:s');

    if ($uemail !== '') {
        $toName = trim($ufirst . ' ' . $ulast) ?: $uemail;
        $subject = 'New passkey added to your account';
        $body = "Hi {$toName},\n\n"
              . "A new passkey was just added to your Peter Pang Fit account.\n\n"
              . "Passkey name: {$pkName}\n"
              . "Date/Time: {$ts}\n"
              . "If this was not you, please delete the passkey from Settings immediately and change your password.";

        @send_plain_email($uemail, $toName, $subject, $body);
    }

    // Security log
    ppf_log($conn, (int)$uid, $uemail ?: null, null, 'passkey_added', 'user', (string)$uid, 'name=' . $pkName);
    ppf_notifications_record($conn, (int)$uid, [
        'type_key' => 'security.passkey_added',
        'message' => 'A passkey named "' . $pkName . '" was added on ' . ppf_format_user_datetime(date('c'), ['fallback' => date('Y-m-d H:i:s')]) . '.',
        'send_email' => true,
    ]);
} catch (Throwable $e) {
    // Non-fatal: don’t break registration if email/logging fails
    error_log('passkey add notify failed: ' . $e->getMessage());
}

    // Cleanup session state
    unset($_SESSION['webauthn_reg']);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}