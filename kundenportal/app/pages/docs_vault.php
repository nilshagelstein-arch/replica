<?php
/**
 * App „Dokumente & Anleitungen“ – Stufe 4: Passwort-Tresor (Zero-Knowledge)
 *
 * Verschlüsselt wird ausschließlich im Browser (assets/vault.js, Web Crypto). Der Server speichert nur:
 * - je Konto ein ECDH-Schlüsselpaar (P-256): öffentlicher Schlüssel im Klartext, privater Schlüssel zweimal
 *   verschlüsselt – mit dem Tresor-Passwort (PBKDF2-SHA256, 600.000 Runden → AES-256-GCM) und mit dem Notfallkit
 *   (32 Zufallsbytes, HKDF → AES-256-GCM). Tresor-Passwort und Notfallkit sieht der Server nie.
 * - je Firma ein Schlüsselpaar für den Firmen-Notfallschlüssel; der private Teil nur verschlüsselt mit dem
 *   ausgedruckten Firmen-Notfallkit.
 * - je Ordner einen zufälligen AES-256-Schlüssel, für jedes berechtigte Konto und den Firmen-Notfallschlüssel
 *   einzeln verpackt (ECDH mit Einmal-Schlüssel + HKDF + AES-GCM).
 * - je Eintrag: Titel, Art, Ablaufdatum im Klartext (für Liste und spätere Erinnerungen); alles andere verschlüsselt.
 *
 * Rechte: Ordnerrechte über Gruppen (Abteilungen) oder einzelne Konten (für den Dienstleister nur so, und nur wenn die
 * Firma ihn ausdrücklich einträgt). Firmen-Admins sind in jedem Ordner Mitglied mit Bearbeiten-Recht und verwalten
 * Ordner und Rechte. Verliert jemand den Zugriff, wird sein verpackter Schlüssel gelöscht und der Ordner beim nächsten
 * Öffnen durch eine Person mit Bearbeiten-Recht neu verschlüsselt (Schlüsselwechsel).
 * Jedes Abrufen eines verschlüsselten Eintrags steht im Protokoll (wk_vault_log), Kopieren meldet der Browser.
 */

declare(strict_types=1);

const WK_V_PERM_VIEW = 1;
const WK_V_PERM_EDIT = 2;
const WK_V_KINDS = ['login', 'wlan', 'netz', 'portal', 'server', 'notiz'];
const WK_V_LOCK_DEFAULT = 15;
const WK_V_BLOB_MAX = 200000;   // verschlüsselter Eintrag (Base64), reicht für lange Notizen
const WK_V_KDF_ITER = 600000;
const WK_V_CHANGE_MONTHS = [0, 3, 6, 12, 24];
const WK_V_IMPORT_MAX = 500;      // Einträge je Import-Anfrage
const WK_V_REMIND_DAYS = 14;      // so viele Tage vor „Ändern bis“ kommt die Erinnerung

function wkv_company_id(): int
{
    $cid = wk_company_id();
    if (wk_is_hub($cid)) abort(404);
    return $cid;
}

function wkv_company(int $cid): ?array
{
    return row('SELECT * FROM wk_vault_company WHERE company_id = ?', [$cid]);
}

function wkv_account(int $userId): ?array
{
    return row('SELECT * FROM wk_vault_accounts WHERE user_id = ?', [$userId]);
}

function wkv_is_company_admin(int $cid, ?array $u = null): bool
{
    $u ??= current_user();
    return $u && $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
}

/**
 * Recht eines Benutzers in einem Ordner: 0, 1 (ansehen) oder 2 (bearbeiten).
 * Firmen-Admins: immer 2. Firmenbenutzer: über Gruppen oder eigenes Konto. Dienstleister: nur eigenes Konto.
 */
function wkv_perm(int $cid, int $folderId, array $u): int
{
    if (!(int)($u['active'] ?? 1)) return 0;
    if (wkv_is_company_admin($cid, $u)) return WK_V_PERM_EDIT;
    $staff = is_staff_role((string)$u['role']);
    if (!$staff && (int)($u['company_id'] ?? 0) !== $cid) return 0;
    $p = (int)(row('SELECT MAX(perm) AS p FROM wk_vault_folder_perms WHERE company_id = ? AND folder_id = ? AND user_id = ?', [$cid, $folderId, $u['id']])['p'] ?? 0);
    if (!$staff) {
        $p = max($p, (int)(row('SELECT MAX(fp.perm) AS p FROM wk_vault_folder_perms fp JOIN user_departments ud ON ud.department_id = fp.department_id
            WHERE fp.company_id = ? AND fp.folder_id = ? AND ud.user_id = ?', [$cid, $folderId, $u['id']])['p'] ?? 0));
    }
    return min(WK_V_PERM_EDIT, $p);
}

/** Alle Personen mit Recht im Ordner: [user_id => [user, perm]] */
function wkv_members(int $cid, int $folderId): array
{
    $ids = [];
    foreach (rows("SELECT id FROM users WHERE company_id = ? AND active = 1", [$cid]) as $r) $ids[(int)$r['id']] = true;
    foreach (rows('SELECT user_id FROM wk_vault_folder_perms WHERE company_id = ? AND folder_id = ? AND user_id IS NOT NULL', [$cid, $folderId]) as $r) $ids[(int)$r['user_id']] = true;
    $out = [];
    foreach (array_keys($ids) as $id) {
        $u = row('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
        if (!$u) continue;
        $p = wkv_perm($cid, $folderId, $u);
        if ($p > 0) $out[$id] = [$u, $p];
    }
    return $out;
}

/** Kurzer Prüfwert eines öffentlichen Schlüssels (zum Vergleichen) */
function wkv_fingerprint(string $pub): string
{
    return implode(' ', str_split(strtoupper(substr(hash('sha256', $pub), 0, 16)), 4));
}

function wkv_log(int $cid, ?int $folderId, ?int $entryId, string $action, string $detail = ''): void
{
    insert('wk_vault_log', ['company_id' => $cid, 'folder_id' => $folderId, 'entry_id' => $entryId, 'user_id' => current_user()['id'],
        'action' => $action, 'detail' => mb_substr($detail, 0, 255), 'created_at' => now()]);
}

/** Prüft einen verschlüsselten Block {i, c} (optional s, e) aus dem Browser und gibt ihn als JSON zurück */
function wkv_blob(mixed $v, array $need = ['i', 'c'], int $max = WK_V_BLOB_MAX): string
{
    if (!is_array($v)) wkv_fail('Ungültige Daten.');
    $out = [];
    foreach (array_merge($need, ['s', 'e', 'it']) as $k) {
        if (!array_key_exists($k, $v)) { if (in_array($k, $need, true)) wkv_fail('Ungültige Daten.'); continue; }
        if ($k === 'it') { $out[$k] = (int)$v[$k]; continue; }
        if (!is_string($v[$k]) || strlen($v[$k]) > $max || !preg_match('#^[A-Za-z0-9+/=]*$#', $v[$k])) wkv_fail('Ungültige Daten.');
        $out[$k] = $v[$k];
    }
    return json_encode($out);
}

function wkv_pub(mixed $v): string
{
    if (!is_string($v) || strlen($v) < 80 || strlen($v) > 400 || !preg_match('#^[A-Za-z0-9+/=]+$#', $v)) wkv_fail('Ungültiger Schlüssel.');
    return $v;
}

function wkv_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wkv_fail(string $msg, int $status = 400): never
{
    wkv_json(['ok' => false, 'error' => t($msg)], $status);
}

// ---------------------------------------------------------------------------
// Seite (die Oberfläche baut assets/vault.js auf)
// ---------------------------------------------------------------------------

function page_doc_vault(): void
{
    $cid = wkv_company_id();
    $u = current_user();
    // Dienstleister nur mit mindestens einem ausdrücklich freigegebenen Ordner
    if (is_staff_role($u['role']) && !row('SELECT id FROM wk_vault_folder_perms WHERE company_id = ? AND user_id = ? AND perm > 0 LIMIT 1', [$cid, $u['id']])) {
        render_page(t('Tresor') . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid) {
            echo wk_switcher($cid, 'doc_vault');
            wk_tabs($cid, 'doc_vault'); ?>
            <div class="alert info"><?= e(t('Der Tresor dieser Firma ist für Ihr Konto nicht freigegeben. Nur die Firma selbst kann einzelne Ordner für Sie freigeben.')) ?></div>
        <?php });
        return;
    }
    render_page(t('Tresor') . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $u) {
        echo wk_switcher($cid, 'doc_vault');
        wk_tabs($cid, 'doc_vault'); ?>
        <div id="vault" class="vault" data-api="<?= e(wkurl('doc_vault_api')) ?>" data-csrf="<?= e(csrf_token()) ?>"
             data-user="<?= (int)$u['id'] ?>" data-admin="<?= wkv_is_company_admin($cid) ? '1' : '0' ?>" data-company="<?= e(wk_company($cid)['name'] ?? '') ?>"
             data-user-name="<?= e($u['name']) ?>" data-user-email="<?= e($u['email']) ?>" data-iter="<?= WK_V_KDF_ITER ?>">
            <noscript><div class="alert error"><?= e(t('Der Tresor braucht JavaScript: Ver- und Entschlüsselung laufen ausschließlich in Ihrem Browser.')) ?></div></noscript>
            <div class="card"><p class="muted"><?= e(t('Tresor wird geladen …')) ?></p></div>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// JSON-Schnittstelle für assets/vault.js
// ---------------------------------------------------------------------------

function page_doc_vault_api(): void
{
    $cid = wkv_company_id();
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wkv_fail('Nur POST.', 405);
    $tok = $_POST['_csrf'] ?? '';
    if (!is_string($tok) || !hash_equals(csrf_token(), $tok)) wkv_fail('Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.', 400);
    $action = (string)($_POST['action'] ?? '');
    $p = json_decode((string)($_POST['p'] ?? '{}'), true);
    if (!is_array($p)) $p = [];
    $admin = wkv_is_company_admin($cid);
    $co = wkv_company($cid);
    $acc = wkv_account((int)$u['id']);

    // Ordner mit Recht prüfen
    $folder = function (int $id, int $need) use ($cid, $u): array {
        $f = row('SELECT * FROM wk_vault_folders WHERE id = ? AND company_id = ?', [$id, $cid]);
        if (!$f) wkv_fail('Ordner nicht gefunden.', 404);
        if (wkv_perm($cid, (int)$f['id'], $u) < $need) wkv_fail('Dafür fehlt Ihnen das Recht.', 403);
        return $f;
    };
    $hasKey = fn(array $f) => (bool)row('SELECT id FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND user_id = ? AND key_version = ?',
        [$cid, $f['id'], $u['id'], $f['key_version']]);

    switch ($action) {
        case 'state':
            $folders = [];
            if ($co) {
                foreach (rows('SELECT * FROM wk_vault_folders WHERE company_id = ? ORDER BY sort, name', [$cid]) as $f) {
                    $fid = (int)$f['id'];
                    $perm = wkv_perm($cid, $fid, $u);
                    // Schlüssel von Personen ohne Recht entfernen → Schlüsselwechsel nötig
                    $members = wkv_members($cid, $fid);
                    foreach (rows('SELECT id, user_id FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND user_id > 0', [$cid, $fid]) as $k) {
                        if (!isset($members[(int)$k['user_id']])) {
                            q('DELETE FROM wk_vault_keys WHERE id = ?', [$k['id']]);
                            if (!(int)$f['needs_rotation']) { q('UPDATE wk_vault_folders SET needs_rotation = 1 WHERE id = ?', [$fid]); $f['needs_rotation'] = 1; }
                        }
                    }
                    if ($perm === 0 && !$admin) continue;
                    $ver = (int)$f['key_version'];
                    $mine = row('SELECT wrapped FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND user_id = ? AND key_version = ?', [$cid, $fid, $u['id'], $ver]);
                    $have = array_map('intval', array_column(rows('SELECT user_id FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND key_version = ?', [$cid, $fid, $ver]), 'user_id'));
                    $pending = [];
                    $recipients = [['user_id' => 0, 'public_key' => $co['public_key']]];
                    foreach ($members as $mid => [$mu, $mp]) {
                        $ma = wkv_account($mid);
                        if (!$ma) continue;
                        $recipients[] = ['user_id' => $mid, 'public_key' => $ma['public_key']];
                        if (!in_array($mid, $have, true)) $pending[] = ['user_id' => $mid, 'name' => $mu['name'], 'public_key' => $ma['public_key']];
                    }
                    if (!in_array(0, $have, true)) $pending[] = ['user_id' => 0, 'name' => t('Firmen-Notfallschlüssel'), 'public_key' => $co['public_key']];
                    $entries = $perm > 0 ? rows('SELECT e.id, e.title, e.kind, e.expires, e.change_months, e.updated_at, u.name AS updated_by_name FROM wk_vault_entries e
                        LEFT JOIN users u ON u.id = e.updated_by WHERE e.company_id = ? AND e.folder_id = ? ORDER BY e.title', [$cid, $fid]) : [];
                    $row = ['id' => $fid, 'name' => $f['name'], 'perm' => $perm, 'key_version' => $ver, 'needs_rotation' => (int)$f['needs_rotation'],
                        'my_key' => $mine ? json_decode($mine['wrapped'], true) : null, 'pending' => $mine ? $pending : [], 'entries' => $entries,
                        'recipients' => $mine && $perm >= WK_V_PERM_EDIT ? $recipients : [],
                        'members_without_account' => array_values(array_map(fn($m) => $m[0]['name'], array_filter($members, fn($m, $id) => !wkv_account((int)$id), ARRAY_FILTER_USE_BOTH)))];
                    if ($admin) {
                        $ck = row('SELECT wrapped FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND user_id = 0 AND key_version = ?', [$cid, $fid, $ver]);
                        $row['company_key'] = $ck ? json_decode($ck['wrapped'], true) : null;
                        $row['acl'] = rows('SELECT department_id, user_id, perm FROM wk_vault_folder_perms WHERE company_id = ? AND folder_id = ?', [$cid, $fid]);
                    }
                    $folders[] = $row;
                }
            }
            $out = ['ok' => true, 'company' => $co ? ['active' => true, 'public_key' => $co['public_key'], 'lock_minutes' => (int)$co['lock_minutes'],
                    'fingerprint' => wkv_fingerprint($co['public_key'])] : ['active' => false],
                'account' => $acc ? ['public_key' => $acc['public_key'], 'priv_pw' => json_decode($acc['priv_pw'], true), 'priv_kit' => json_decode($acc['priv_kit'], true),
                    'fingerprint' => wkv_fingerprint($acc['public_key'])] : null,
                'admin' => $admin, 'folders' => $folders];
            if ($admin) {
                $out['departments'] = rows('SELECT id, name FROM departments WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]);
                $out['users'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'staff' => is_staff_role($r['role']),
                    'has_account' => (bool)wkv_account((int)$r['id'])],
                    rows("SELECT * FROM users WHERE active = 1 AND (company_id = ? OR role IN ('superadmin', 'admin')) ORDER BY company_id IS NULL, name", [$cid]));
            }
            wkv_json($out);

        case 'account_create':
            if ($acc) wkv_fail('Für Ihr Konto ist der Tresor schon eingerichtet.');
            insert('wk_vault_accounts', ['user_id' => $u['id'], 'public_key' => wkv_pub($p['public_key'] ?? null), 'priv_pw' => wkv_blob($p['priv_pw'] ?? null, ['s', 'i', 'c']),
                'priv_kit' => wkv_blob($p['priv_kit'] ?? null), 'created_at' => now(), 'updated_at' => now()]);
            wkv_log($cid, null, null, 'account_create');
            wkv_json(['ok' => true]);

        case 'account_password':
            // neues Tresor-Passwort (nach Änderung oder Wiederherstellung mit dem Notfallkit); der Schlüssel selbst bleibt gleich
            if (!$acc) wkv_fail('Für Ihr Konto ist der Tresor noch nicht eingerichtet.');
            update('wk_vault_accounts', (int)$acc['id'], ['priv_pw' => wkv_blob($p['priv_pw'] ?? null, ['s', 'i', 'c']), 'updated_at' => now()]);
            wkv_log($cid, null, null, !empty($p['recovered']) ? 'account_recover' : 'account_password');
            wkv_json(['ok' => true]);

        case 'company_activate':
            if (!$admin) wkv_fail('Den Tresor aktivieren nur die Administratoren der Firma.', 403);
            if ($co) wkv_fail('Der Tresor ist für diese Firma schon aktiviert.');
            if (!$acc) wkv_fail('Bitte richten Sie zuerst Ihren eigenen Tresor-Zugang ein.');
            insert('wk_vault_company', ['company_id' => $cid, 'public_key' => wkv_pub($p['public_key'] ?? null), 'priv_kit' => wkv_blob($p['priv_kit'] ?? null),
                'lock_minutes' => WK_V_LOCK_DEFAULT, 'created_by' => $u['id'], 'created_at' => now()]);
            wkv_log($cid, null, null, 'company_activate');
            wkv_json(['ok' => true]);

        case 'company_key':
            if (!$admin || !$co) wkv_fail('Dafür fehlt Ihnen das Recht.', 403);
            wkv_log($cid, null, null, 'emergency_open');
            wkv_json(['ok' => true, 'priv_kit' => json_decode($co['priv_kit'], true)]);

        case 'settings':
            if (!$admin || !$co) wkv_fail('Dafür fehlt Ihnen das Recht.', 403);
            $m = max(1, min(240, (int)($p['lock_minutes'] ?? WK_V_LOCK_DEFAULT)));
            q('UPDATE wk_vault_company SET lock_minutes = ? WHERE company_id = ?', [$m, $cid]);
            wkv_log($cid, null, null, 'settings', 'lock=' . $m);
            wkv_json(['ok' => true]);

        case 'folder_save':
            if (!$admin || !$co) wkv_fail('Ordner und Rechte pflegen die Administratoren der Firma.', 403);
            $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 120);
            if ($name === '') wkv_fail('Bitte einen Namen angeben.');
            $id = (int)($p['id'] ?? 0);
            if ($id) {
                $f = $folder($id, WK_V_PERM_EDIT);
                update('wk_vault_folders', $id, ['name' => $name, 'updated_at' => now()]);
            } else {
                // neuer Ordner: der Browser hat den Schlüssel erzeugt und für das eigene Konto und den Firmen-Notfallschlüssel verpackt
                $keys = is_array($p['keys'] ?? null) ? $p['keys'] : [];
                $byUser = [];
                foreach ($keys as $k) $byUser[(int)($k['user_id'] ?? -1)] = wkv_blob($k['wrapped'] ?? null, ['e', 'i', 'c'], 2000);
                if (!isset($byUser[(int)$u['id']], $byUser[0])) wkv_fail('Der Ordnerschlüssel fehlt.');
                $max = (int)(row('SELECT MAX(sort) AS m FROM wk_vault_folders WHERE company_id = ?', [$cid])['m'] ?? 0);
                $id = insert('wk_vault_folders', ['company_id' => $cid, 'name' => $name, 'sort' => $max + 10, 'key_version' => 1, 'needs_rotation' => 0,
                    'created_by' => $u['id'], 'created_at' => now(), 'updated_at' => now()]);
                foreach ([(int)$u['id'], 0] as $uid) insert('wk_vault_keys', ['company_id' => $cid, 'folder_id' => $id, 'user_id' => $uid, 'key_version' => 1,
                    'wrapped' => $byUser[$uid], 'created_by' => $u['id'], 'created_at' => now()]);
            }
            // Rechte: Gruppen und einzelne Konten (Dienstleister nur einzeln)
            q('DELETE FROM wk_vault_folder_perms WHERE company_id = ? AND folder_id = ?', [$cid, $id]);
            $deps = array_map('intval', array_column(rows('SELECT id FROM departments WHERE company_id = ?', [$cid]), 'id'));
            foreach ((array)($p['acl'] ?? []) as $a) {
                $perm = min(WK_V_PERM_EDIT, max(0, (int)($a['perm'] ?? 0)));
                if (!$perm) continue;
                if (!empty($a['department_id']) && in_array((int)$a['department_id'], $deps, true)) {
                    insert('wk_vault_folder_perms', ['company_id' => $cid, 'folder_id' => $id, 'department_id' => (int)$a['department_id'], 'user_id' => null, 'perm' => $perm]);
                } elseif (!empty($a['user_id'])) {
                    $tu = row('SELECT * FROM users WHERE id = ? AND active = 1', [(int)$a['user_id']]);
                    if ($tu && ((int)$tu['company_id'] === $cid || is_staff_role($tu['role']))) {
                        insert('wk_vault_folder_perms', ['company_id' => $cid, 'folder_id' => $id, 'department_id' => null, 'user_id' => (int)$tu['id'], 'perm' => $perm]);
                    }
                }
            }
            wkv_log($cid, $id, null, 'folder_save', $name);
            wkv_json(['ok' => true, 'id' => $id]);

        case 'folder_delete':
            if (!$admin) wkv_fail('Dafür fehlt Ihnen das Recht.', 403);
            $f = $folder((int)($p['id'] ?? 0), WK_V_PERM_EDIT);
            foreach (['wk_vault_entries', 'wk_vault_keys', 'wk_vault_folder_perms'] as $tbl) q("DELETE FROM $tbl WHERE company_id = ? AND folder_id = ?", [$cid, $f['id']]);
            q('DELETE FROM wk_vault_folders WHERE id = ?', [$f['id']]);
            wkv_log($cid, (int)$f['id'], null, 'folder_delete', (string)$f['name']);
            wkv_json(['ok' => true]);

        case 'share':
            // Ordnerschlüssel für weitere berechtigte Konten (oder den Firmen-Notfallschlüssel) verpacken
            $f = $folder((int)($p['folder_id'] ?? 0), WK_V_PERM_VIEW);
            if ((int)($p['key_version'] ?? 0) !== (int)$f['key_version']) wkv_fail('Der Ordner wurde inzwischen neu verschlüsselt. Bitte laden Sie die Seite neu.', 409);
            if (!$hasKey($f)) wkv_fail('Sie haben keinen Schlüssel für diesen Ordner.', 403);
            $members = wkv_members($cid, (int)$f['id']);
            $n = 0;
            foreach ((array)($p['keys'] ?? []) as $k) {
                $tid = (int)($k['user_id'] ?? -1);
                if ($tid !== 0 && (!isset($members[$tid]) || !wkv_account($tid))) continue;
                if ($tid === 0 && !$co) continue;
                if (row('SELECT id FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND user_id = ? AND key_version = ?', [$cid, $f['id'], $tid, $f['key_version']])) continue;
                insert('wk_vault_keys', ['company_id' => $cid, 'folder_id' => $f['id'], 'user_id' => $tid, 'key_version' => $f['key_version'],
                    'wrapped' => wkv_blob($k['wrapped'] ?? null, ['e', 'i', 'c'], 2000), 'created_by' => $u['id'], 'created_at' => now()]);
                $n++;
            }
            if ($n) wkv_log($cid, (int)$f['id'], null, 'share', (string)$n);
            wkv_json(['ok' => true, 'shared' => $n]);

        case 'emergency_take':
            // Firmen-Admin hat den Ordnerschlüssel mit dem Firmen-Notfallkit entpackt und für sich neu verpackt
            if (!$admin || !$co) wkv_fail('Dafür fehlt Ihnen das Recht.', 403);
            $f = $folder((int)($p['folder_id'] ?? 0), WK_V_PERM_EDIT);
            if ((int)($p['key_version'] ?? 0) !== (int)$f['key_version']) wkv_fail('Der Ordner wurde inzwischen neu verschlüsselt. Bitte laden Sie die Seite neu.', 409);
            if (!$acc) wkv_fail('Bitte richten Sie zuerst Ihren eigenen Tresor-Zugang ein.');
            q('DELETE FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND user_id = ? AND key_version = ?', [$cid, $f['id'], $u['id'], $f['key_version']]);
            insert('wk_vault_keys', ['company_id' => $cid, 'folder_id' => $f['id'], 'user_id' => $u['id'], 'key_version' => $f['key_version'],
                'wrapped' => wkv_blob($p['wrapped'] ?? null, ['e', 'i', 'c'], 2000), 'created_by' => $u['id'], 'created_at' => now()]);
            wkv_log($cid, (int)$f['id'], null, 'emergency_take');
            wkv_json(['ok' => true]);

        case 'rotate':
            // neuer Ordnerschlüssel: alle Einträge neu verschlüsselt, Schlüssel für alle Mitglieder und den Firmen-Notfallschlüssel
            $f = $folder((int)($p['folder_id'] ?? 0), WK_V_PERM_EDIT);
            if ((int)($p['old_version'] ?? 0) !== (int)$f['key_version']) wkv_fail('Der Ordner wurde inzwischen neu verschlüsselt. Bitte laden Sie die Seite neu.', 409);
            if (!$hasKey($f)) wkv_fail('Sie haben keinen Schlüssel für diesen Ordner.', 403);
            $new = (int)$f['key_version'] + 1;
            $members = wkv_members($cid, (int)$f['id']);
            $keys = [];
            foreach ((array)($p['keys'] ?? []) as $k) {
                $tid = (int)($k['user_id'] ?? -1);
                if ($tid === 0 || (isset($members[$tid]) && wkv_account($tid))) $keys[$tid] = wkv_blob($k['wrapped'] ?? null, ['e', 'i', 'c'], 2000);
            }
            if (!isset($keys[(int)$u['id']], $keys[0])) wkv_fail('Der neue Ordnerschlüssel fehlt.');
            $ids = array_map('intval', array_column(rows('SELECT id FROM wk_vault_entries WHERE company_id = ? AND folder_id = ?', [$cid, $f['id']]), 'id'));
            $data = [];
            foreach ((array)($p['entries'] ?? []) as $en) $data[(int)($en['id'] ?? 0)] = wkv_blob($en['data'] ?? null);
            sort($ids);
            $got = array_keys($data);
            sort($got);
            if ($ids !== $got) wkv_fail('Es müssen alle Einträge des Ordners neu verschlüsselt werden. Bitte laden Sie die Seite neu.', 409);
            db()->beginTransaction();
            try {
                foreach ($data as $eid => $blob) q('UPDATE wk_vault_entries SET data = ?, key_version = ? WHERE id = ? AND company_id = ?', [$blob, $new, $eid, $cid]);
                q('DELETE FROM wk_vault_keys WHERE company_id = ? AND folder_id = ?', [$cid, $f['id']]);
                foreach ($keys as $tid => $w) insert('wk_vault_keys', ['company_id' => $cid, 'folder_id' => $f['id'], 'user_id' => $tid, 'key_version' => $new,
                    'wrapped' => $w, 'created_by' => $u['id'], 'created_at' => now()]);
                q('UPDATE wk_vault_folders SET key_version = ?, needs_rotation = 0, updated_at = ? WHERE id = ?', [$new, now(), $f['id']]);
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollBack();
                throw $ex;
            }
            wkv_log($cid, (int)$f['id'], null, 'rotate', 'v' . $new . ', ' . count($keys) . ' Schlüssel');
            wkv_json(['ok' => true, 'key_version' => $new]);

        case 'entry_get':
            $e = row('SELECT * FROM wk_vault_entries WHERE id = ? AND company_id = ?', [(int)($p['id'] ?? 0), $cid]);
            if (!$e) wkv_fail('Eintrag nicht gefunden.', 404);
            $folder((int)$e['folder_id'], WK_V_PERM_VIEW);
            // Schlüsselwechsel und Export lesen alle Einträge – im Protokoll eigens gekennzeichnet
            $purpose = (string)($p['purpose'] ?? '');
            if ($purpose === 'rotate') $folder((int)$e['folder_id'], WK_V_PERM_EDIT);
            if ($purpose === 'export' && !$admin) wkv_fail('Exportieren dürfen nur die Administratoren der Firma.', 403);
            wkv_log($cid, (int)$e['folder_id'], (int)$e['id'], ['rotate' => 'rotate_read', 'export' => 'export_read'][$purpose] ?? 'view', (string)$e['title']);
            wkv_json(['ok' => true, 'data' => json_decode($e['data'], true), 'key_version' => (int)$e['key_version']]);

        case 'entry_save':
            $f = $folder((int)($p['folder_id'] ?? 0), WK_V_PERM_EDIT);
            if ((int)($p['key_version'] ?? 0) !== (int)$f['key_version']) wkv_fail('Der Ordner wurde inzwischen neu verschlüsselt. Bitte laden Sie die Seite neu.', 409);
            $title = mb_substr(trim((string)($p['title'] ?? '')), 0, 190);
            if ($title === '') wkv_fail('Bitte einen Titel angeben.');
            $kind = in_array($p['kind'] ?? '', WK_V_KINDS, true) ? $p['kind'] : 'notiz';
            $exp = (string)($p['expires'] ?? '');
            if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $exp = '';
            $cm = (int)($p['change_months'] ?? 0);
            $data = ['title' => $title, 'kind' => $kind, 'expires' => $exp, 'change_months' => in_array($cm, WK_V_CHANGE_MONTHS, true) ? $cm : 0,
                'data' => wkv_blob($p['data'] ?? null), 'key_version' => $f['key_version'], 'updated_by' => $u['id'], 'updated_at' => now()];
            $id = (int)($p['id'] ?? 0);
            if ($id) {
                $e = row('SELECT * FROM wk_vault_entries WHERE id = ? AND company_id = ?', [$id, $cid]);
                if (!$e) wkv_fail('Eintrag nicht gefunden.', 404);
                $folder((int)$e['folder_id'], WK_V_PERM_EDIT);
                if ($exp !== $e['expires']) $data['expires_notified'] = '';
                update('wk_vault_entries', $id, $data + ['folder_id' => $f['id']]);
                wkv_log($cid, (int)$f['id'], $id, 'update', $title);
            } else {
                $id = insert('wk_vault_entries', $data + ['company_id' => $cid, 'folder_id' => $f['id'], 'created_by' => $u['id'], 'created_at' => now()]);
                wkv_log($cid, (int)$f['id'], $id, 'create', $title);
            }
            wkv_json(['ok' => true, 'id' => $id]);

        case 'entries_import':
            // Import aus Bitwarden, KeePass, 1Password oder einer Tresor-Exportdatei: im Browser gelesen und verschlüsselt
            $f = $folder((int)($p['folder_id'] ?? 0), WK_V_PERM_EDIT);
            if ((int)($p['key_version'] ?? 0) !== (int)$f['key_version']) wkv_fail('Der Ordner wurde inzwischen neu verschlüsselt. Bitte laden Sie die Seite neu.', 409);
            $items = is_array($p['items'] ?? null) ? $p['items'] : [];
            if (!$items || count($items) > WK_V_IMPORT_MAX) wkv_fail('Bitte zwischen 1 und 500 Einträge auf einmal importieren.');
            $rowsIn = [];
            foreach ($items as $it) {
                $title = mb_substr(trim((string)($it['title'] ?? '')), 0, 190);
                if ($title === '') $title = t('Ohne Titel');
                $exp = (string)($it['expires'] ?? '');
                $cm = (int)($it['change_months'] ?? 0);
                $rowsIn[] = ['title' => $title, 'kind' => in_array($it['kind'] ?? '', WK_V_KINDS, true) ? $it['kind'] : 'notiz',
                    'expires' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : '', 'change_months' => in_array($cm, WK_V_CHANGE_MONTHS, true) ? $cm : 0,
                    'data' => wkv_blob($it['data'] ?? null)];
            }
            db()->beginTransaction();
            try {
                foreach ($rowsIn as $r) insert('wk_vault_entries', $r + ['company_id' => $cid, 'folder_id' => $f['id'], 'key_version' => $f['key_version'],
                    'created_by' => $u['id'], 'updated_by' => $u['id'], 'created_at' => now(), 'updated_at' => now()]);
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollBack();
                throw $ex;
            }
            wkv_log($cid, (int)$f['id'], null, 'import', mb_substr((string)($p['source'] ?? ''), 0, 40) . ': ' . count($rowsIn));
            wkv_json(['ok' => true, 'imported' => count($rowsIn)]);

        case 'log_export':
            if (!$admin) wkv_fail('Exportieren dürfen nur die Administratoren der Firma.', 403);
            $f = $folder((int)($p['folder_id'] ?? 0), WK_V_PERM_VIEW);
            $fmt = ($p['format'] ?? '') === 'csv' ? 'export_csv' : 'export_file';
            wkv_log($cid, (int)$f['id'], null, $fmt, (string)(int)($p['count'] ?? 0));
            wkv_json(['ok' => true]);

        case 'entry_delete':
            $e = row('SELECT * FROM wk_vault_entries WHERE id = ? AND company_id = ?', [(int)($p['id'] ?? 0), $cid]);
            if (!$e) wkv_fail('Eintrag nicht gefunden.', 404);
            $folder((int)$e['folder_id'], WK_V_PERM_EDIT);
            q('DELETE FROM wk_vault_entries WHERE id = ?', [$e['id']]);
            wkv_log($cid, (int)$e['folder_id'], (int)$e['id'], 'delete', (string)$e['title']);
            wkv_json(['ok' => true]);

        case 'log':
            // vom Browser gemeldet: Kopieren eines Werts
            $e = row('SELECT * FROM wk_vault_entries WHERE id = ? AND company_id = ?', [(int)($p['id'] ?? 0), $cid]);
            if (!$e) wkv_fail('Eintrag nicht gefunden.', 404);
            $folder((int)$e['folder_id'], WK_V_PERM_VIEW);
            $field = preg_replace('/[^a-z_]/', '', (string)($p['field'] ?? ''));
            wkv_log($cid, (int)$e['folder_id'], (int)$e['id'], 'copy', $field);
            wkv_json(['ok' => true]);

        case 'entry_log':
            $e = row('SELECT * FROM wk_vault_entries WHERE id = ? AND company_id = ?', [(int)($p['id'] ?? 0), $cid]);
            if (!$e) wkv_fail('Eintrag nicht gefunden.', 404);
            $folder((int)$e['folder_id'], WK_V_PERM_EDIT);
            wkv_json(['ok' => true, 'log' => rows('SELECT l.action, l.detail, l.created_at, u.name FROM wk_vault_log l LEFT JOIN users u ON u.id = l.user_id
                WHERE l.company_id = ? AND l.entry_id = ? ORDER BY l.id DESC LIMIT 100', [$cid, $e['id']])]);

        case 'account_reset':
            // Firmen-Admin setzt den Tresor-Zugang eines Benutzers der eigenen Firma zurück (beides verloren)
            if (!$admin) wkv_fail('Dafür fehlt Ihnen das Recht.', 403);
            $tu = row('SELECT * FROM users WHERE id = ? AND company_id = ?', [(int)($p['user_id'] ?? 0), $cid]);
            if (!$tu) wkv_fail('Benutzer nicht gefunden.', 404);
            q('DELETE FROM wk_vault_keys WHERE user_id = ?', [$tu['id']]);
            q('DELETE FROM wk_vault_accounts WHERE user_id = ?', [$tu['id']]);
            wkv_log($cid, null, null, 'account_reset', (string)$tu['name']);
            wkv_json(['ok' => true]);
    }
    wkv_fail('Unbekannte Aktion.');
}

// ---------------------------------------------------------------------------
// Erinnerung „Ändern bis“ (nur Titel und Datum, nie Inhalte)
// ---------------------------------------------------------------------------

/** Sammelmail je Person mit Bearbeiten-Recht für Einträge, deren „Ändern bis“ in 14 Tagen oder früher liegt */
function wkv_reminders_run(): array
{
    $log = [];
    $limit = date('Y-m-d', strtotime('+' . WK_V_REMIND_DAYS . ' days'));
    $due = rows("SELECT e.id, e.company_id, e.folder_id, e.title, e.expires, f.name AS folder_name FROM wk_vault_entries e
        JOIN wk_vault_folders f ON f.id = e.folder_id WHERE e.expires <> '' AND e.expires <= ? AND e.expires_notified <> e.expires LIMIT 1000", [$limit]);
    $byUser = [];
    foreach ($due as $e) {
        $cid = (int)$e['company_id'];
        q('UPDATE wk_vault_entries SET expires_notified = ? WHERE id = ?', [$e['expires'], $e['id']]);
        if (!company_has_app($cid, WK_APP) || !wkv_company($cid)) continue;
        foreach (wkv_members($cid, (int)$e['folder_id']) as $uid => [$mu, $mp]) {
            if ($mp < WK_V_PERM_EDIT || !wkv_account((int)$uid)) continue;
            $byUser[$cid . ':' . $uid]['u'] = $mu;
            $byUser[$cid . ':' . $uid]['cid'] = $cid;
            $byUser[$cid . ':' . $uid]['items'][] = $e;
        }
    }
    foreach ($byUser as $b) {
        $u = $b['u'];
        $cid = $b['cid'];
        try {
            with_lang(user_lang($u), function () use ($u, $b, $cid) {
                $lines = array_map(fn($e) => '- ' . $e['title'] . ' (' . t('Ordner') . ' „' . $e['folder_name'] . '“): ' . fmt_date($e['expires']), $b['items']);
                $link = rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=doc_vault';
                send_mail([(string)$u['email']], t('Tresor: Zugangsdaten bald ändern'),
                    t('Guten Tag {n},', ['n' => $u['name']]) . "\n\n" . t('für diese Einträge im Tresor ist bald oder bereits ein Passwortwechsel fällig:') . "\n\n"
                    . implode("\n", $lines) . "\n\n" . t('Ändern Sie das Passwort beim Dienst, tragen Sie es im Tresor ein und setzen Sie „Ändern bis“ neu.') . "\n\n" . t('Zum Tresor:') . ' ' . $link, $cid);
            });
        } catch (Throwable $ex) {
            error_log('[kundenportal] Tresor-Erinnerung: ' . $ex->getMessage());
        }
        $log[] = 'Tresor: Erinnerung an ' . $u['email'] . ' (' . count($b['items']) . ' Einträge)';
    }
    return $log;
}
