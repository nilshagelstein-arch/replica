<?php
/**
 * E-Mail-Versand über PHP mail() (auf Webhosting in der Regel vorkonfiguriert).
 * Ist der Versand deaktiviert, werden Mails nach app/data/mail.log geschrieben.
 */

function send_mail(array $to, string $subject, string $body): void
{
    $to = array_values(array_unique(array_filter($to, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
    if (!$to) return;

    $from = cfg('mail.from', 'noreply@localhost');
    $fromName = cfg('mail.from_name', cfg('app_name', 'Kundenportal'));
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body .= "\n\n--\n" . $fromName . "\nDiese Nachricht wurde automatisch erzeugt.";

    if (!cfg('mail.enabled', false)) {
        $dir = APP_DIR . '/data';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        file_put_contents($dir . '/mail.log',
            '[' . now() . '] An: ' . implode(', ', $to) . "\nBetreff: $subject\n$body\n" . str_repeat('-', 60) . "\n",
            FILE_APPEND | LOCK_EX);
        return;
    }

    $headers = [
        'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Kundenportal',
    ];
    foreach ($to as $addr) {
        @mail($addr, $encSubject, $body, implode("\r\n", $headers), '-f' . $from);
    }
}

function request_link(int $id): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=request&id=' . $id;
}

/** E-Mail-Adressen der Benutzer einer Firma, die Aufträge sehen (Ersteller + Firmen-Admins) */
function company_recipients(int $companyId, ?int $creatorId = null): array
{
    $list = array_column(rows(
        "SELECT email FROM users WHERE company_id = ? AND active = 1 AND role = 'firma_admin'", [$companyId]), 'email');
    if ($creatorId) {
        $c = row('SELECT email FROM users WHERE id = ? AND active = 1', [$creatorId]);
        if ($c) $list[] = $c['email'];
    }
    $co = row('SELECT contact_email FROM companies WHERE id = ?', [$companyId]);
    if ($co && $co['contact_email']) $list[] = $co['contact_email'];
    return $list;
}
