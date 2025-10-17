<?php
// passkey_finish_login.php — Finish WebAuthn assertion (manual verify; COSE→PEM)
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/ppf_passkeys.php';

function b64url_decode_strict(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $bin = base64_decode($s, true);
    if ($bin === false) throw new RuntimeException('Bad base64url');
    return $bin;
}

/** ----- Minimal CBOR needed for COSE keys ----- */
function cbor_read_len(string $buf, int &$pos, int $ai): int {
    if ($ai < 24) return $ai;
    if ($ai === 24) { $v = ord($buf[$pos] ?? "\0"); $pos += 1; return $v; }
    if ($ai === 25) { $v = unpack('n', substr($buf, $pos, 2))[1] ?? 0; $pos += 2; return $v; }
    if ($ai === 26) { $v = unpack('N', substr($buf, $pos, 4))[1] ?? 0; $pos += 4; return $v; }
    if ($ai === 27) {
        $chunk = substr($buf, $pos, 8); $pos += 8;
        $parts = unpack('Nhi/Nlo', $chunk ?: str_repeat("\0", 8));
        return (int)(($parts['hi'] << 32) | $parts['lo']);
    }
    throw new RuntimeException('Unsupported CBOR length encoding');
}
function cbor_decode_one(string $buf, int &$pos) {
    $b = ord($buf[$pos] ?? "\0"); $pos += 1;
    $mt = $b >> 5; $ai = $b & 0x1f;
    switch ($mt) {
        case 0: return cbor_read_len($buf, $pos, $ai); // unsigned
        case 1: // negative
            $n = cbor_read_len($buf, $pos, $ai);
            return -1 - $n;
        case 2: { // byte string
            $len = cbor_read_len($buf, $pos, $ai);
            $s = (string)substr($buf, $pos, $len); $pos += $len;
            return $s;
        }
        case 3: { // text string
            $len = cbor_read_len($buf, $pos, $ai);
            $s = (string)substr($buf, $pos, $len); $pos += $len;
            return $s;
        }
        case 4: { // array
            $len = cbor_read_len($buf, $pos, $ai);
            $arr=[]; for ($i=0;$i<$len;$i++) $arr[] = cbor_decode_one($buf, $pos);
            return $arr;
        }
        case 5: { // map
            $len = cbor_read_len($buf, $pos, $ai);
            $map=[]; for ($i=0;$i<$len;$i++) { $k=cbor_decode_one($buf,$pos); $v=cbor_decode_one($buf,$pos); $map[$k]=$v; }
            return $map;
        }
        default: throw new RuntimeException('Unsupported CBOR major type '.$mt);
    }
}
function cbor_decode_all(string $buf) { $p=0; return cbor_decode_one($buf,$p); }

/** ----- COSE → PEM converters (ES256, RS256) ----- */
function der_len(string $data): string {
    $len = strlen($data);
    if ($len < 128) return chr($len);
    $out = '';
    while ($len > 0) { $out = chr($len & 0xff) . $out; $len >>= 8; }
    return chr(0x80 | strlen($out)) . $out;
}
function asn1_seq(string $inner): string { return "\x30" . der_len($inner) . $inner; }
function asn1_int(string $bin): string {
    $bin = ltrim($bin, "\x00");
    if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\x00".$bin;
    return "\x02" . der_len($bin) . $bin;
}
function asn1_bitstr(string $bin): string { return "\x03" . der_len("\x00".$bin) . "\x00" . $bin; }
function asn1_oid(array $oids): string {
    // oids as dotted array of ints, e.g. [1,2,840,10045,2,1]
    $first = 40*$oids[0] + $oids[1];
    $out = chr($first);
    for ($i=2;$i<count($oids);$i++) {
        $n = (int)$oids[$i];
        $stack=[];
        do { $stack[] = ($n & 0x7f); $n >>= 7; } while ($n > 0);
        for ($j=count($stack)-1;$j>=0;$j--) $out .= chr(($j>0?0x80:0) | $stack[$j]);
    }
    return "\x06" . der_len($out) . $out;
}
function spki_wrap_ec_p256(string $pub_uncompressed): string {
    // AlgorithmIdentifier: ecPublicKey (1.2.840.10045.2.1), parameters: prime256v1 (1.2.840.10045.3.1.7)
    $alg = asn1_seq(
        asn1_oid([1,2,840,10045,2,1]) .
        asn1_oid([1,2,840,10045,3,1,7])
    );
    $spki = asn1_seq(
        $alg .
        asn1_bitstr($pub_uncompressed)
    );
    return "-----BEGIN PUBLIC KEY-----\n" .
           chunk_split(base64_encode($spki), 64, "\n") .
           "-----END PUBLIC KEY-----\n";
}
function spki_wrap_rsa(string $n, string $e): string {
    // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) NULL
    $alg = asn1_seq(asn1_oid([1,2,840,113549,1,1,1]) . "\x05\x00");
    $rsaPub = asn1_seq(asn1_int($n) . asn1_int($e));
    $spki = asn1_seq($alg . asn1_bitstr($rsaPub));
    return "-----BEGIN PUBLIC KEY-----\n" .
           chunk_split(base64_encode($spki), 64, "\n") .
           "-----END PUBLIC KEY-----\n";
}
function cose_to_pem(string $cose): array {
    // returns [pem, alg] where alg is -7 (ES256) or -257 (RS256)
    $pos=0; $map=cbor_decode_one($cose,$pos);
    if (!is_array($map)) throw new RuntimeException('COSE parse failed');

    $kty = $map[1]  ?? null;   // 2=EC2, 3=RSA
    $alg = $map[3]  ?? null;   // -7=ES256, -257=RS256

    if ($kty === 2) { // EC2
        $crv = $map[-1] ?? null; // 1=P-256
        $x   = $map[-2] ?? null;
        $y   = $map[-3] ?? null;
        if ($alg !== -7 || $crv !== 1 || !is_string($x) || !is_string($y)) {
            throw new RuntimeException('Unsupported EC COSE key');
        }
        if (strlen($x)!==32 || strlen($y)!==32) throw new RuntimeException('Bad EC key length');
        $pub = "\x04" . $x . $y; // uncompressed point
        return [spki_wrap_ec_p256($pub), -7];
    } elseif ($kty === 3) { // RSA
        $n = $map[-1] ?? null; // modulus
        $e = $map[-2] ?? null; // exponent
        if ($alg !== -257 || !is_string($n) || !is_string($e)) {
            throw new RuntimeException('Unsupported RSA COSE key');
        }
        return [spki_wrap_rsa($n,$e), -257];
    }
    throw new RuntimeException('Unsupported COSE key type');
}

try {
    $state = $_SESSION['webauthn_assert'] ?? null;
    if (!$state || !isset($state['challenge'],$state['uid'],$state['rpId'],$state['origin'])) {
        throw new Exception('Assertion not initialized.');
    }

    // Inputs from client
    $clientDataJSON_b64 = (string)($_POST['clientDataJSON'] ?? '');
    $authData_b64       = (string)($_POST['authenticatorData'] ?? '');
    $sig_b64            = (string)($_POST['signature'] ?? '');
    $credId_b64url      = (string)($_POST['credentialId'] ?? '');
    $userHandle_b64     = (string)($_POST['userHandle'] ?? ''); // optional

    if ($clientDataJSON_b64 === '' || $authData_b64 === '' || $sig_b64 === '' || $credId_b64url === '') {
        throw new Exception('Invalid assertion payload.');
    }

    // Decode payloads (client sends standard base64 for response fields; we accept both)
    $clientDataJSON     = base64_decode(strtr($clientDataJSON_b64, '-_', '+/'), true);
    if ($clientDataJSON === false) $clientDataJSON = b64url_decode_strict($clientDataJSON_b64);
    $authenticatorData  = base64_decode(strtr($authData_b64, '-_', '+/'), true);
    if ($authenticatorData === false) $authenticatorData = b64url_decode_strict($authData_b64);
    $signature          = base64_decode(strtr($sig_b64, '-_', '+/'), true);
    if ($signature === false) $signature = b64url_decode_strict($sig_b64);
    $credId             = b64url_decode_strict($credId_b64url); // raw cred id

    // DB: lookup passkey by raw cred_id (BLOB)
    if (!$st = $conn->prepare("
        SELECT p.id, p.user_id, p.public_key, p.counter,
               u.email, u.role, u.first_name, u.last_name, u.photo_url
        FROM passkeys p
        JOIN users u ON u.id = p.user_id
        WHERE p.cred_id = ?
        LIMIT 1
    ")) {
        throw new Exception('DB error.');
    }
    $st->bind_param('b', $credId);
    $st->send_long_data(0, $credId);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();

    if (!$row) throw new Exception('Unknown credential.');

    // Enforce email-first constraint: the credential must belong to the same user picked at begin
    if ((int)$row['user_id'] !== (int)$state['uid']) {
        throw new Exception('Credential does not belong to this account.');
    }

    // 1) Parse clientDataJSON and verify basics
    $client = json_decode($clientDataJSON, true, 512, JSON_THROW_ON_ERROR);
    if (($client['type'] ?? '') !== 'webauthn.get') throw new Exception('wrong clientData type');
    $origin = (string)$state['origin'];
    if ((string)($client['origin'] ?? '') !== $origin) throw new Exception('origin mismatch');

    // challenge inside clientDataJSON must match original challenge (base64url of raw bytes)
    $chal_client_raw = b64url_decode_strict((string)($client['challenge'] ?? ''));
    if (!hash_equals((string)$state['challenge'], $chal_client_raw)) throw new Exception('challenge mismatch');

    // 2) Parse authenticatorData
    $ofs = 0;
    $rpIdHash = substr($authenticatorData, $ofs, 32); $ofs += 32;
    $flags    = ord($authenticatorData[$ofs] ?? "\0"); $ofs += 1;
    $signCnt  = unpack('N', substr($authenticatorData, $ofs, 4))[1] ?? 0; $ofs += 4;

    $expectedRpHash = hash('sha256', (string)$state['rpId'], true);
    if (!hash_equals($expectedRpHash, $rpIdHash)) throw new Exception('rpIdHash mismatch');

    // User verification bit must be set if we required it
    $FLAG_UV = 0x04; // userVerified
    if (!(($flags & $FLAG_UV) === $FLAG_UV)) throw new Exception('user not verified');

    // 3) Build the signed data (authenticatorData || SHA256(clientDataJSON))
    $clientDataHash = hash('sha256', $clientDataJSON, true);
    $signedData = $authenticatorData . $clientDataHash;

    // 4) Convert COSE key to PEM and verify signature
    $cose = (string)$row['public_key']; // stored raw COSE (CBOR)
    [$pem, $alg] = cose_to_pem($cose);

    // For ES256 and RS256, verification uses SHA256
    $ok = openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
    if (!$ok) throw new Exception('signature verify failed');

    // 5) (Optional) handle signature counter — just store the latest value
    if ($u=$conn->prepare("UPDATE passkeys SET counter=?, last_used_at=NOW() WHERE id=?")) {
        $ctr = (int)$signCnt; // we could also ensure monotonic increase per authenticator
        $pid = (int)$row['id'];
        $u->bind_param("ii",$ctr,$pid);
        $u->execute(); $u->close();
    }

    // 6) Establish app session
    $_SESSION['user_id']       = (int)$row['user_id'];
    $_SESSION['email']         = $row['email'];
    $_SESSION['role']          = $row['role'];
    $_SESSION['first_name']    = $row['first_name'] ?? '';
    $_SESSION['last_name']     = $row['last_name'] ?? '';
    $_SESSION['photo_url']     = $row['photo_url'] ?? '';
    $_SESSION['LAST_ACTIVITY'] = time();

    session_regenerate_id(true);
    ppf_sessions_create_on_login($conn, (int)$row['user_id']);

    ppf_log($conn, (int)$row['user_id'], $row['email'], $row['role'], 'login_success_passkey', 'user', (string)$row['user_id'], null);

    unset($_SESSION['webauthn_assert']);
    echo json_encode(['ok'=>true, 'redirect'=>'dashboard.php']);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}