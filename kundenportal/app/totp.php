<?php
/**
 * TOTP (RFC 6238) für Authenticator-Apps – ohne externe Bibliotheken.
 */

function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_generate_secret(): string
{
    return base32_encode(random_bytes(20)); // 160 Bit, 32 Zeichen
}

function totp_code(string $secret, int $step): string
{
    $key = base32_decode($secret);
    $msg = pack('N2', 0, $step);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $bin = ((ord($hash[$offset]) & 0x7F) << 24)
         | (ord($hash[$offset + 1]) << 16)
         | (ord($hash[$offset + 2]) << 8)
         | ord($hash[$offset + 3]);
    return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen Code (±1 Zeitfenster à 30 s). Gibt den verwendeten Zeitschritt zurück
 * oder null. $lastStep verhindert, dass ein Code zweimal verwendet wird.
 */
function totp_verify(string $secret, string $code, int $lastStep = 0, ?int $time = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $step = intdiv($time ?? time(), 30);
    foreach ([0, -1, 1] as $d) {
        $s = $step + $d;
        if ($s <= $lastStep) continue;
        if (hash_equals(totp_code($secret, $s), $code)) return $s;
    }
    return null;
}

function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
