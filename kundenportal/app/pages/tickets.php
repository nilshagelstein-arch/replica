<?php
/**
 * App „Tickets“: interner Helpdesk je Firma.
 *
 * Mitarbeiter melden Anliegen in eine Queue; Bearbeiter der Queue ziehen (ansehen) oder buchen
 * (übernehmen) Tickets, antworten im Chat-Verlauf und schreiben interne Notizen.
 * Der Ersteller wird über jede Änderung per Mail informiert.
 *
 * Stufe 1 (Grundgerüst): Queues mit Bearbeitern je Benutzer, Tickets, Verlauf, Anhänge, Status,
 * Buchen/Abgeben/Weitergeben, Wiedervorlage, automatisches Schließen, Mails.
 * Stufe 2 (Gruppen/Rechte): Gruppen = Abteilungen der Firma (ein Konto kann in mehreren sein). Bearbeiter einer
 * Queue sind eingetragene Benutzer, Mitglieder der eingetragenen Gruppen, die Teamleitung und die Firmen-Admins
 * (Admin-Gruppe). Zuweisen an andere dürfen Teamleitung und Admins; der gebuchte Bearbeiter darf weitergeben.
 * Beim Erstellen wählt man „nur ich“ oder „meine Gruppe“: Gruppenmitglieder sehen das Ticket und schreiben mit.
 * Stufe 3 (Zeitsteuerung): Wartet ein Ticket zu lange auf die Bearbeiter, gibt es je Dringlichkeit eine Erinnerung
 * (gebuchter Bearbeiter, sonst die Queue), dann Eskalation an die Teamleitung und danach an die Firmen-Admins.
 * Fälligkeit je Ticket. Läuft über cron.php und zusätzlich höchstens alle 5 Minuten bei einem Seitenaufruf.
 * E-Mail-Eingang, Dienstleister und Auswertung folgen (siehe Pflichtenheft).
 *
 * Tickets bleiben firmenintern: Verwaltungsadmins des Dienstleisters richten Queues ein, sehen Tickets
 * aber nur, wenn die Firma die „Einsicht für den IT-Dienstleister“ eingeschaltet hat.
 */
declare(strict_types=1);

const TICKETS_APP = 'tickets';
const TICKET_STATUSES = [
    'offen' => 'Offen', 'in_bearbeitung' => 'In Bearbeitung', 'rueckfrage' => 'Rückfrage', 'zurueckgestellt' => 'Zurückgestellt',
    'beim_dienstleister' => 'Beim Dienstleister', 'geloest' => 'Gelöst', 'wieder_eroeffnet' => 'Wieder eröffnet', 'geschlossen' => 'Geschlossen',
];
/** Status, die Bearbeiter von Hand setzen (Beim Dienstleister folgt mit der Dienstleister-Stufe) */
const TICKET_MANUAL_STATUSES = ['in_bearbeitung', 'rueckfrage', 'zurueckgestellt', 'geloest', 'geschlossen'];
const TICKET_CLOSED = ['geloest', 'geschlossen'];
const TICKET_PRIORITIES = ['frage' => 'Frage / Wunsch', 'einschraenkung' => 'Einschränkung', 'blockiert' => 'Ich kann nicht arbeiten', 'mehrere' => 'Mehrere / alle betroffen'];
const TICKET_PRIORITY_RANK = ['mehrere' => 4, 'blockiert' => 3, 'einschraenkung' => 2, 'frage' => 1];
const TICKET_ATTACH_MAX = 10485760;      // 10 MB je Datei
const TICKET_ATTACH_COUNT = 5;           // Dateien je Nachricht
const TICKET_ATTACH_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'zip', 'eml', 'msg'];
const TICKET_VIEW_MINUTES = 15;          // so lange gilt „wird gerade angesehen von …“
/** Standardzeiten in Stunden je Dringlichkeit: [Erinnerung, Eskalation an die Teamleitung]; Admins bei doppelter Eskalationszeit */
const TICKET_TIMES_DEFAULT = ['mehrere' => [1, 2], 'blockiert' => [2, 4], 'einschraenkung' => [8, 24], 'frage' => [24, 72]];
/** Status, in denen ein Ticket auf die Bearbeiter wartet (bei Rückfrage, Zurückgestellt usw. läuft keine Uhr) */
const TICKET_WAIT_STATUSES = ['offen', 'in_bearbeitung', 'wieder_eroeffnet'];
const TICKET_TIMER_INTERVAL = 300;      // Sekunden zwischen zwei Läufen bei Seitenaufrufen

// ---------------------------------------------------------------------------
// Firmenkontext und Rechte
// ---------------------------------------------------------------------------

function tickets_company_id(): int
{
    $u = require_app(TICKETS_APP);
    $cid = context_company_id();
    if (!$cid) abort(404, t('Es ist noch keine Firma angelegt.'));
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    if (!is_staff_role($u['role']) && !company_has_app($cid, TICKETS_APP)) abort(403);
    $GLOBALS['tickets_cid'] = $cid;
    tickets_housekeeping($cid);
    return $cid;
}

function tickets_company(int $cid): array
{
    static $cache = [];
    return $cache[$cid] ??= (row('SELECT id, name, ticket_autoclose_days, ticket_provider_access, ticket_categories, ticket_times FROM companies WHERE id = ?', [$cid]) ?? []);
}

/** Dienstleister (Verwaltungsadmins) sehen Tickets nur mit Freigabe der Firma */
function tickets_staff_may_see(int $cid): bool
{
    return (int)(tickets_company($cid)['ticket_provider_access'] ?? 0) === 1;
}

/** Queues und Einstellungen verwalten: Firmen-Admin oder Dienstleister mit Recht „Tickets bearbeiten“ */
function tickets_can_manage(): bool
{
    $u = current_user();
    if ($u && is_staff_role($u['role'])) return admin_can('tickets', true);
    return $u && $u['role'] === 'firma_admin';
}

/** Queues, deren Tickets der Benutzer bearbeiten darf */
function tickets_my_queue_ids(int $cid): array
{
    static $cache = [];
    $u = current_user();
    $key = $cid . ':' . (int)$u['id'];
    if (isset($cache[$key])) return $cache[$key];
    if (is_staff_role($u['role'])) {
        $ids = tickets_staff_may_see($cid) && admin_can('tickets', false)
            ? array_map('intval', array_column(rows('SELECT id FROM ticket_queues WHERE company_id = ?', [$cid]), 'id')) : [];
    } elseif ($u['role'] === 'firma_admin') {
        $ids = array_map('intval', array_column(rows('SELECT id FROM ticket_queues WHERE company_id = ?', [$cid]), 'id'));
    } else {
        $uid = (int)$u['id'];
        $ids = array_map('intval', array_column(rows('SELECT q.id FROM ticket_queues q WHERE q.company_id = ? AND (q.lead_user_id = ?
            OR q.id IN (SELECT m.queue_id FROM ticket_queue_members m WHERE m.user_id = ?)
            OR q.id IN (SELECT g.queue_id FROM ticket_queue_groups g JOIN user_departments ud ON ud.department_id = g.department_id WHERE ud.user_id = ?))',
            [$cid, $uid, $uid, $uid]), 'id'));
    }
    return $cache[$key] = $ids;
}

/** Gruppen (Abteilungen) eines Benutzers, nur aktive: [id => Name] */
function user_groups(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $out = [];
    foreach (rows('SELECT d.id, d.name FROM user_departments ud JOIN departments d ON d.id = ud.department_id
        WHERE ud.user_id = ? AND d.active = 1 ORDER BY d.name', [$userId]) as $d) $out[(int)$d['id']] = $d['name'];
    return $cache[$userId] = $out;
}

/** Gruppen-Ticket, und der Benutzer ist in dieser Gruppe */
function ticket_in_group(array $tk): bool
{
    $u = current_user();
    if (!$u || is_staff_role($u['role']) || ($tk['visibility'] ?? 'me') !== 'group' || !$tk['group_id']) return false;
    return isset(user_groups((int)$u['id'])[(int)$tk['group_id']]);
}

/** Teamleitung der Queue oder Admin: darf Tickets beliebigen Bearbeitern zuweisen */
function ticket_is_lead(array $tk): bool
{
    $u = current_user();
    if (!$u || !ticket_can_process($tk)) return false;
    if ($u['role'] === 'firma_admin' || is_staff_role($u['role'])) return true;
    return (int)($tk['lead_user_id'] ?? 0) === (int)$u['id'];
}

function tickets_is_processor(int $cid): bool
{
    return (bool)tickets_my_queue_ids($cid);
}

function ticket_can_process(array $tk): bool
{
    return in_array((int)$tk['queue_id'], tickets_my_queue_ids((int)$tk['company_id']), true);
}

function ticket_can_view(array $tk): bool
{
    $u = current_user();
    return (!is_staff_role($u['role']) && (int)$tk['created_by'] === (int)$u['id']) || ticket_in_group($tk) || ticket_can_process($tk);
}

/** Bearbeiter einer Queue (aktive Benutzer): eingetragene Benutzer, Mitglieder der Gruppen, Teamleitung und Firmen-Admins */
function ticket_queue_processors(int $queueId, int $cid): array
{
    $list = rows("SELECT u.id, u.name, u.email, u.lang, u.company_id FROM users u WHERE u.company_id = ? AND u.active = 1 AND (
            u.role = 'firma_admin'
            OR u.id IN (SELECT m.user_id FROM ticket_queue_members m WHERE m.queue_id = ?)
            OR u.id IN (SELECT ud.user_id FROM ticket_queue_groups g JOIN user_departments ud ON ud.department_id = g.department_id WHERE g.queue_id = ?)
            OR u.id IN (SELECT q.lead_user_id FROM ticket_queues q WHERE q.id = ?)
        ) ORDER BY u.name", [$cid, $queueId, $queueId, $queueId]);
    $all = [];
    foreach ($list as $p) $all[(int)$p['id']] = $p;
    return array_values($all);
}

/** URL innerhalb der App; Dienstleister behalten die gewählte Firma */
function turl(string $route, array $params = []): string
{
    if (is_admin() && !empty($GLOBALS['tickets_cid'])) $params = ['company' => (int)$GLOBALS['tickets_cid']] + $params;
    return url($route, $params);
}

function tredirect(string $route, array $params = []): never
{
    header('Location: ' . turl($route, $params));
    exit;
}

function tickets_app_name(): string
{
    return t((string)(row('SELECT name FROM apps WHERE slug = ?', [TICKETS_APP])['name'] ?? 'Tickets'));
}

function ticket_link(int $id): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=ticket&id=' . $id;
}

function ticket_status_badge(string $s): string
{
    return '<span class="badge tk-' . e($s) . '">' . e(t(TICKET_STATUSES[$s] ?? $s)) . '</span>';
}

function ticket_priority_badge(string $p): string
{
    return '<span class="prio prio-' . e($p) . '">' . e(t(TICKET_PRIORITIES[$p] ?? $p)) . '</span>';
}

/** Fälligkeitsdatum, rot wenn überfällig */
function ticket_due_badge(array $tk): string
{
    if ($tk['due_at'] === '') return '';
    $over = !in_array($tk['status'], TICKET_CLOSED, true) && $tk['due_at'] < date('Y-m-d');
    $today = $tk['due_at'] === date('Y-m-d');
    return '<span class="due' . ($over ? ' over' : ($today ? ' today' : '')) . '" title="' . e($over ? t('überfällig') : t('Fällig am')) . '">📅 ' . fmt_date($tk['due_at']) . '</span>';
}

/** „vor 5 Min.“, „vor 3 Std.“, „vor 2 Tagen“ */
function ticket_age(string $dt): string
{
    $s = max(0, time() - (int)strtotime($dt));
    if ($s < 3600) return t('vor {n} Min.', ['n' => max(1, intdiv($s, 60))]);
    if ($s < 86400) return t('vor {n} Std.', ['n' => intdiv($s, 3600)]);
    $d = intdiv($s, 86400);
    return $d === 1 ? t('vor 1 Tag') : t('vor {n} Tagen', ['n' => $d]);
}

function ticket_categories(int $cid): array
{
    $raw = (string)(tickets_company($cid)['ticket_categories'] ?? '');
    $list = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    return $list ?: ['Arbeitsplatz / PC', 'Drucker', 'E-Mail / Microsoft 365', 'Telefon', 'Netzwerk / VPN', 'Software', 'Zugang / Passwort', 'Haustechnik', 'Sonstiges'];
}

function load_ticket(int $id): ?array
{
    return row('SELECT t.*, q.name AS queue_name, q.lead_user_id, lu.name AS lead_name, cu.name AS creator_name, cu.email AS creator_email,
            au.name AS assignee_name, vu.name AS viewer_name, gd.name AS group_name
        FROM tickets t
        LEFT JOIN ticket_queues q ON q.id = t.queue_id
        LEFT JOIN users lu ON lu.id = q.lead_user_id
        LEFT JOIN departments gd ON gd.id = t.group_id
        LEFT JOIN users cu ON cu.id = t.created_by
        LEFT JOIN users au ON au.id = t.assigned_to
        LEFT JOIN users vu ON vu.id = t.viewing_by
        WHERE t.id = ?', [$id]);
}

// ---------------------------------------------------------------------------
// Zeitgesteuertes: Wiedervorlage und automatisches Schließen (beim Aufruf, höchstens einmal je Minute)
// ---------------------------------------------------------------------------

function tickets_housekeeping(int $cid, bool $force = false): void
{
    $key = 'tk_hk_' . $cid;
    if (!$force) {
        if (isset($_SESSION[$key]) && $_SESSION[$key] > time() - 60) return;
        $_SESSION[$key] = time();
    }
    foreach (rows("SELECT id, assigned_to FROM tickets WHERE company_id = ? AND status = 'zurueckgestellt' AND follow_up_at <> '' AND follow_up_at <= ?",
        [$cid, date('Y-m-d')]) as $tk) {
        $to = $tk['assigned_to'] ? 'in_bearbeitung' : 'offen';
        update('tickets', (int)$tk['id'], ['status' => $to, 'follow_up_at' => '', 'updated_at' => now(), 'last_activity_at' => now(),
            'waiting_since' => now(), 'reminder_level' => 0]);
        ticket_log((int)$tk['id'], $cid, null, 'event', 'Wiedervorlage fällig', ['status_from' => 'zurueckgestellt', 'status_to' => $to]);
    }
    $days = max(1, (int)(tickets_company($cid)['ticket_autoclose_days'] ?? 7));
    $limit = date('Y-m-d H:i:s', time() - $days * 86400);
    foreach (rows("SELECT id FROM tickets WHERE company_id = ? AND status = 'geloest' AND resolved_at <> '' AND resolved_at <= ?", [$cid, $limit]) as $tk) {
        update('tickets', (int)$tk['id'], ['status' => 'geschlossen', 'closed_at' => now(), 'updated_at' => now()]);
        ticket_log((int)$tk['id'], $cid, null, 'event', 'Automatisch geschlossen', ['status_from' => 'geloest', 'status_to' => 'geschlossen']);
    }
}

// ---------------------------------------------------------------------------
// Zeitsteuerung: Wartezeit, Erinnerung, Eskalation, Fälligkeit
// ---------------------------------------------------------------------------

/** Zeiten der Firma: ['on' => bool, 'skip_weekend' => bool, 'times' => [prio => [Erinnerung, Eskalation]]] (Stunden) */
function ticket_times(int $cid): array
{
    $raw = json_decode((string)(tickets_company($cid)['ticket_times'] ?? ''), true);
    $raw = is_array($raw) ? $raw : [];
    $times = [];
    foreach (TICKET_TIMES_DEFAULT as $p => [$r, $x]) {
        $v = $raw['times'][$p] ?? [$r, $x];
        $times[$p] = [max(0, (int)($v[0] ?? $r)), max(0, (int)($v[1] ?? $x))];
    }
    return ['on' => (bool)($raw['on'] ?? true), 'skip_weekend' => (bool)($raw['skip_weekend'] ?? true), 'times' => $times];
}

/** Die Uhr läuft (wieder) an. $restart: auch wenn sie schon läuft, neu beginnen */
function ticket_wait_start(int $id, bool $restart = false): void
{
    q("UPDATE tickets SET waiting_since = ?, reminder_level = 0 WHERE id = ?" . ($restart ? '' : " AND waiting_since = ''"), [now(), $id]);
}

/** Die Bearbeiter haben reagiert: Uhr anhalten */
function ticket_wait_stop(int $id): void
{
    q("UPDATE tickets SET waiting_since = '', reminder_level = 0 WHERE id = ?", [$id]);
}

/** Stunden seit $since; Samstag und Sonntag zählen auf Wunsch nicht mit */
function ticket_hours_since(string $since, bool $skipWeekend, ?int $now = null): float
{
    $from = (int)strtotime($since);
    $to = $now ?? time();
    if ($to <= $from) return 0.0;
    if (!$skipWeekend) return ($to - $from) / 3600;
    $sec = 0;
    $t = $from;
    while ($t < $to) {
        $next = min($to, (int)strtotime('tomorrow', $t));
        if ((int)date('N', $t) < 6) $sec += $next - $t;
        $t = $next;
    }
    return $sec / 3600;
}

/** Mail an eine Liste von Benutzern (Zeitsteuerung, ohne angemeldeten Benutzer) */
function ticket_mail_users(array $users, array $tk, callable $text): void
{
    $to = array_values(array_filter(array_map(fn($p) => (string)$p['email'], $users)));
    if (!$to) return;
    send_mail_t($to, fn() => [ticket_mail_subject($tk), $text() . t("\n\nZum Ticket: ") . ticket_link((int)$tk['id'])], (int)$tk['company_id']);
}

/** Erinnerungen, Eskalationen und Fälligkeiten aller Firmen prüfen. Gibt Textzeilen fürs Protokoll zurück. */
function tickets_timers_run(): array
{
    $out = [];
    $companies = array_map('intval', array_column(rows("SELECT DISTINCT company_id FROM tickets WHERE status <> 'geschlossen'"), 'company_id'));
    foreach ($companies as $cid) {
        tickets_housekeeping($cid, true);
        $cfg = ticket_times($cid);
        $tickets = rows("SELECT t.*, q.name AS queue_name, q.lead_user_id FROM tickets t LEFT JOIN ticket_queues q ON q.id = t.queue_id
            WHERE t.company_id = ? AND t.status NOT IN ('geloest', 'geschlossen')", [$cid]);
        $admins = rows("SELECT id, name, email FROM users WHERE company_id = ? AND role = 'firma_admin' AND active = 1 ORDER BY name", [$cid]);
        foreach ($tickets as $tk) {
            $id = (int)$tk['id'];
            // Fälligkeit erreicht: einmal Bescheid an den Bearbeiter bzw. die Queue
            if ($tk['due_at'] !== '' && $tk['due_at'] <= date('Y-m-d') && !(int)$tk['due_notified']) {
                if (q('UPDATE tickets SET due_notified = 1 WHERE id = ? AND due_notified = 0', [$id])->rowCount() === 1) {
                    $who = $tk['assigned_to'] ? rows('SELECT id, name, email FROM users WHERE id = ? AND active = 1', [$tk['assigned_to']])
                        : ticket_queue_processors((int)$tk['queue_id'], $cid);
                    ticket_log($id, $cid, null, 'event', 'Fälligkeit erreicht');
                    $due = $tk['due_at'];
                    ticket_mail_users($who, $tk, fn() => t('Das Ticket ist am {x} fällig und noch nicht gelöst.', ['x' => fmt_date($due)]));
                    $out[] = '#' . $tk['number'] . ' ' . t('Fälligkeit erreicht');
                }
            }
            if (!$cfg['on'] || $tk['waiting_since'] === '' || !in_array($tk['status'], TICKET_WAIT_STATUSES, true)) continue;
            [$remind, $escalate] = $cfg['times'][$tk['priority']] ?? [24, 72];
            $hours = ticket_hours_since((string)$tk['waiting_since'], $cfg['skip_weekend']);
            $level = (int)$tk['reminder_level'];
            $lead = $tk['lead_user_id'] ? row('SELECT id, name, email FROM users WHERE id = ? AND active = 1', [$tk['lead_user_id']]) : null;
            // je Lauf höchstens eine Stufe weiter: 1 Erinnerung, 2 Teamleitung (ohne Teamleitung: Admins), 3 Admins
            $thresholds = [1 => $remind, 2 => $escalate, 3 => $lead ? $escalate * 2 : 0];
            $target = $level + 1;
            while ($target <= 3 && $thresholds[$target] <= 0) $target++;
            if ($target > 3 || $hours < $thresholds[$target]) continue;
            // gegen doppelten Versand bei gleichzeitigen Läufen
            if (q('UPDATE tickets SET reminder_level = ? WHERE id = ? AND reminder_level = ?', [$target, $id, $level])->rowCount() !== 1) continue;
            $h = (int)floor($hours);
            $prio = $tk['priority'];
            if ($target === 1) {
                $who = $tk['assigned_to'] ? rows('SELECT id, name, email FROM users WHERE id = ? AND active = 1', [$tk['assigned_to']])
                    : ticket_queue_processors((int)$tk['queue_id'], $cid);
                ticket_log($id, $cid, null, 'event', 'Erinnerung gesendet');
                ticket_mail_users($who, $tk, fn() => t("Erinnerung: Das Ticket wartet seit {n} Std. auf eine Reaktion (Dringlichkeit: {prio}).", [
                    'n' => $h, 'prio' => t(TICKET_PRIORITIES[$prio] ?? $prio)]));
            } else {
                // Stufe 2: Teamleitung (ohne Teamleitung gleich die Admins), Stufe 3: Admins
                $who = $target === 2 && $lead ? [$lead] : $admins;
                $names = implode(', ', array_column($who, 'name'));
                ticket_log($id, $cid, null, 'event', 'Eskaliert an: ' . ($names !== '' ? $names : '–'));
                $assignee = $tk['assigned_to'] ? (row('SELECT name FROM users WHERE id = ?', [$tk['assigned_to']])['name'] ?? '') : '';
                ticket_mail_users($who, $tk, fn() => t("Eskalation: Das Ticket in der Queue „{queue}“ wartet seit {n} Std. auf eine Reaktion (Dringlichkeit: {prio}).", [
                    'queue' => (string)$tk['queue_name'], 'n' => $h, 'prio' => t(TICKET_PRIORITIES[$prio] ?? $prio)])
                    . "\n" . ($assignee !== '' ? t('Bearbeiter: {name}', ['name' => $assignee]) : t('Es hat noch niemand das Ticket gebucht.')));
            }
            $out[] = '#' . $tk['number'] . ' ' . t('Stufe {n}', ['n' => $target]);
        }
    }
    return $out;
}

/** Bei Seitenaufrufen höchstens alle 5 Minuten (ergänzt cron.php, falls kein Cronjob eingerichtet ist) */
function tickets_timers_tick(): void
{
    try {
        $last = setting_get('tickets_timer_last');
        if ($last !== '' && (int)$last > time() - TICKET_TIMER_INTERVAL) return;
        if ($last === '') setting_set('tickets_timer_last', '0');
        // nur ein Lauf gleichzeitig: wer den Zeitstempel umschreibt, darf laufen
        if (q('UPDATE settings SET value = ? WHERE name = ? AND value = ?', [(string)time(), 'tickets_timer_last', $last === '' ? '0' : $last])->rowCount() !== 1) return;
        tickets_timers_run();
    } catch (Throwable $ex) {
        error_log('[kundenportal] Ticket-Zeitsteuerung: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Verlauf, Anhänge, Nummern
// ---------------------------------------------------------------------------

/** Eintrag im Verlauf. kind: message (für alle), note (intern), event (Statuswechsel, Buchung …) */
function ticket_log(int $ticketId, int $cid, ?int $userId, string $kind, string $body, array $extra = []): int
{
    return insert('ticket_messages', ['ticket_id' => $ticketId, 'company_id' => $cid, 'user_id' => $userId, 'kind' => $kind,
        'body' => $body, 'status_from' => $extra['status_from'] ?? '', 'status_to' => $extra['status_to'] ?? '', 'created_at' => now()]);
}

/** Nächste Ticketnummer der Firma im laufenden Jahr, z. B. 2026-0001 */
function ticket_next_number(int $cid): array
{
    $year = (int)date('Y');
    $max = (int)(row('SELECT MAX(seq_no) AS m FROM tickets WHERE company_id = ? AND seq_year = ?', [$cid, $year])['m'] ?? 0);
    return [$year, $max + 1, sprintf('%d-%04d', $year, $max + 1)];
}

function ticket_files_dir(int $cid): string
{
    $dir = APP_DIR . '/data/tickets/' . $cid;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/** Hochgeladene Dateien prüfen. Gibt [Liste, Fehler] zurück */
function ticket_uploads(): array
{
    $f = $_FILES['files'] ?? null;
    $out = [];
    if (!$f || !is_array($f['name'] ?? null)) return [[], null];
    foreach ($f['name'] as $i => $name) {
        $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $name = trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '_', (string)$name), ' .');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)$f['size'][$i] > TICKET_ATTACH_MAX) {
            return [[], t('Die Datei „{x}“ ist zu groß (höchstens 10 MB).', ['x' => $name])];
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i])) return [[], t('Die Datei „{x}“ konnte nicht hochgeladen werden.', ['x' => $name])];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, TICKET_ATTACH_EXT, true)) return [[], t('Der Dateityp von „{x}“ ist nicht erlaubt.', ['x' => $name])];
        $out[] = ['name' => mb_substr($name ?: 'datei.' . $ext, 0, 190), 'tmp' => $f['tmp_name'][$i], 'size' => (int)$f['size'][$i]];
    }
    if (count($out) > TICKET_ATTACH_COUNT) return [[], t('Bitte höchstens {n} Dateien auf einmal anhängen.', ['n' => TICKET_ATTACH_COUNT])];
    return [$out, null];
}

function ticket_store_uploads(array $files, int $ticketId, int $messageId, int $cid): void
{
    $dir = ticket_files_dir($cid);
    foreach ($files as $f) {
        $stored = $ticketId . '-' . bin2hex(random_bytes(12)) . '.bin';
        if (!move_uploaded_file($f['tmp'], $dir . '/' . $stored)) continue;
        @chmod($dir . '/' . $stored, 0640);
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
        insert('ticket_attachments', ['ticket_id' => $ticketId, 'message_id' => $messageId, 'company_id' => $cid, 'filename' => $f['name'],
            'mime' => mb_substr($mime, 0, 100), 'size' => $f['size'], 'stored_name' => $stored, 'created_at' => now()]);
    }
}

// ---------------------------------------------------------------------------
// Benachrichtigungen
// ---------------------------------------------------------------------------

function ticket_mail_subject(array $tk, string $prefix = ''): string
{
    return '[#' . $tk['number'] . '] ' . ($prefix !== '' ? $prefix . ' ' : '') . $tk['subject'];
}

/** Mail an den Ersteller (nicht, wenn er die Änderung selbst gemacht hat) */
function ticket_notify_creator(array $tk, callable $text): void
{
    $u = current_user();
    if (!$tk['created_by'] || ($u && (int)$u['id'] === (int)$tk['created_by'])) return;
    $c = row('SELECT email, active FROM users WHERE id = ?', [$tk['created_by']]);
    if (!$c || !(int)$c['active'] || $c['email'] === '') return;
    send_mail_t([$c['email']], fn() => [ticket_mail_subject($tk), $text() . t("\n\nZum Ticket: ") . ticket_link((int)$tk['id'])], (int)$tk['company_id']);
}

/** Mail an Bearbeiter: der gebuchte, sonst alle Bearbeiter der Queue (ohne den Handelnden) */
function ticket_notify_processors(array $tk, callable $text, bool $wholeQueue = false, array $exclude = []): void
{
    $u = current_user();
    if (!$wholeQueue && $tk['assigned_to']) {
        $list = rows('SELECT id, email FROM users WHERE id = ? AND active = 1', [$tk['assigned_to']]);
    } else {
        $list = ticket_queue_processors((int)$tk['queue_id'], (int)$tk['company_id']);
    }
    $to = [];
    foreach ($list as $p) if ($p['email'] !== '' && (!$u || (int)$p['id'] !== (int)$u['id']) && !in_array((int)$p['id'], $exclude, true)) $to[] = $p['email'];
    if (!$to) return;
    send_mail_t($to, fn() => [ticket_mail_subject($tk), $text() . t("\n\nZum Ticket: ") . ticket_link((int)$tk['id'])], (int)$tk['company_id']);
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------

function page_tickets(): void
{
    $cid = tickets_company_id();
    $u = current_user();
    $staff = is_staff_role($u['role']);
    $queues = rows('SELECT * FROM ticket_queues WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]);
    $myQueues = tickets_my_queue_ids($cid);
    $processor = (bool)$myQueues;
    $views = $staff ? [] : ['mine' => t('Meine Tickets')];
    $myGroups = $staff ? [] : user_groups((int)$u['id']);
    if ($myGroups) $views['group'] = t('Meine Gruppen');
    if ($processor) $views += ['queue' => t('Offen in meinen Queues'), 'assigned' => t('Mir zugewiesen'), 'waiting' => t('Wartet zu lange'), 'all' => t('Alle meiner Queues')];
    $view = (string)($_GET['view'] ?? '');
    if (!isset($views[$view])) $view = $processor ? 'queue' : 'mine';
    $state = in_array($_GET['state'] ?? '', ['open', 'closed', 'all'], true) ? (string)$_GET['state'] : 'open';
    $queueFilter = query_int('queue');
    $search = trim((string)($_GET['q'] ?? ''));

    $where = ['t.company_id = ?'];
    $params = [$cid];
    if ($view === 'mine') { $where[] = 't.created_by = ?'; $params[] = $u['id']; }
    elseif ($view === 'group') { $where[] = "t.visibility = 'group' AND t.group_id IN (" . implode(',', array_map('intval', array_keys($myGroups))) . ')'; }
    else {
        $where[] = $myQueues ? 't.queue_id IN (' . implode(',', $myQueues) . ')' : '1 = 0';
        if ($view === 'queue') { $where[] = "t.assigned_to IS NULL AND t.status IN ('offen', 'wieder_eroeffnet')"; }
        if ($view === 'assigned') { $where[] = 't.assigned_to = ?'; $params[] = $u['id']; }
        if ($view === 'waiting') { $where[] = "((t.reminder_level > 0 AND t.waiting_since <> '') OR (t.due_at <> '' AND t.due_at <= ?))"; $params[] = date('Y-m-d'); }
    }
    if ($view !== 'queue') {
        if ($state === 'open') $where[] = "t.status NOT IN ('geloest', 'geschlossen')";
        if ($state === 'closed') $where[] = "t.status IN ('geloest', 'geschlossen')";
    }
    if ($queueFilter) { $where[] = 't.queue_id = ?'; $params[] = $queueFilter; }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)) . '%';
        $where[] = '(t.subject LIKE ? OR t.number LIKE ? OR cu.name LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $list = $staff && !tickets_staff_may_see($cid) ? [] : rows('SELECT t.*, q.name AS queue_name, cu.name AS creator_name, au.name AS assignee_name, gd.name AS group_name
        FROM tickets t LEFT JOIN ticket_queues q ON q.id = t.queue_id LEFT JOIN users cu ON cu.id = t.created_by LEFT JOIN users au ON au.id = t.assigned_to
        LEFT JOIN departments gd ON gd.id = t.group_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY t.last_activity_at DESC, t.id DESC LIMIT 300', $params);
    // dringende zuerst, innerhalb gleicher Dringlichkeit die ältesten zuerst (offene Ansichten)
    if ($state === 'open' || $view === 'queue') {
        usort($list, fn($a, $b) => [TICKET_PRIORITY_RANK[$b['priority']] ?? 0, $a['created_at']] <=> [TICKET_PRIORITY_RANK[$a['priority']] ?? 0, $b['created_at']]);
    }
    $counts = [];
    if ($processor) {
        $in = implode(',', $myQueues);
        $counts['queue'] = (int)row("SELECT COUNT(*) AS n FROM tickets WHERE company_id = ? AND queue_id IN ($in) AND assigned_to IS NULL AND status IN ('offen', 'wieder_eroeffnet')", [$cid])['n'];
        $counts['assigned'] = (int)row("SELECT COUNT(*) AS n FROM tickets WHERE company_id = ? AND assigned_to = ? AND status NOT IN ('geloest', 'geschlossen')", [$cid, $u['id']])['n'];
        $counts['waiting'] = (int)row("SELECT COUNT(*) AS n FROM tickets WHERE company_id = ? AND queue_id IN ($in) AND status NOT IN ('geloest', 'geschlossen')
            AND ((reminder_level > 0 AND waiting_since <> '') OR (due_at <> '' AND due_at <= ?))", [$cid, date('Y-m-d')])['n'];
    }

    render_page(tickets_app_name(), function () use ($cid, $u, $staff, $queues, $views, $view, $state, $queueFilter, $search, $list, $counts, $processor) {
        if (is_admin()) echo company_switcher($cid, 'tickets');
        if ($staff && !tickets_staff_may_see($cid)): ?>
            <div class="alert info"><?= e(t('Die Tickets dieser Firma sind nur intern sichtbar. Die Firma kann die Einsicht für den IT-Dienstleister in den Ticket-Einstellungen einschalten. Queues und Einstellungen können Sie trotzdem pflegen.')) ?></div>
        <?php endif; ?>
        <?php if (!$queues): ?>
            <div class="alert info"><?= e(t('Es ist noch keine Queue eingerichtet.')) ?>
                <?php if (tickets_can_manage()): ?> <a href="<?= e(turl('ticket_settings')) ?>"><?= e(t('Queues einrichten')) ?></a><?php else: ?> <?= e(t('Bitte wenden Sie sich an Ihren Firmen-Administrator.')) ?><?php endif; ?></div>
        <?php endif; ?>
        <div class="toolbar">
            <?php if (!$staff && $queues): ?><a class="btn primary" href="<?= e(turl('ticket_new')) ?>"><?= e(t('+ Neues Ticket')) ?></a><?php endif; ?>
            <?php if ($staff && $queues && tickets_staff_may_see($cid)): ?><a class="btn primary" href="<?= e(turl('ticket_new')) ?>"><?= e(t('+ Ticket für einen Mitarbeiter')) ?></a><?php endif; ?>
            <?php if (tickets_can_manage()): ?><a class="btn" href="<?= e(turl('ticket_settings')) ?>"><?= e(t('Queues & Einstellungen')) ?></a><?php endif; ?>
        </div>
        <?php if (count($views) > 1): ?>
        <nav class="type-tabs">
            <?php foreach ($views as $k => $label): ?>
                <a class="<?= $k === $view ? 'active' : '' ?>" href="<?= e(turl('tickets', ['view' => $k])) ?>"><?= e($label) ?><?= isset($counts[$k]) && $counts[$k] ? ' <span class="count">' . $counts[$k] . '</span>' : '' ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="tickets"><input type="hidden" name="view" value="<?= e($view) ?>">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <?php if ($view !== 'queue'): ?>
            <label><?= e(t('Status')) ?> <select name="state">
                <option value="open" <?= $state === 'open' ? 'selected' : '' ?>><?= e(t('offen')) ?></option>
                <option value="closed" <?= $state === 'closed' ? 'selected' : '' ?>><?= e(t('gelöst / geschlossen')) ?></option>
                <option value="all" <?= $state === 'all' ? 'selected' : '' ?>><?= e(t('alle')) ?></option>
            </select></label>
            <?php endif; ?>
            <?php if (count($queues) > 1): ?>
            <label><?= e(t('Queue')) ?> <select name="queue"><option value="0"><?= e(t('– alle –')) ?></option>
                <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>" <?= $queueFilter === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Betreff, Nummer oder Name')) ?>">
            <button class="btn"><?= e(t('Filtern')) ?></button>
        </form>
        <div class="card">
        <?php if (!$list): ?>
            <p class="muted"><?= e($view === 'queue' ? t('Keine offenen Tickets ohne Bearbeiter.') : t('Keine Tickets gefunden.')) ?></p>
        <?php else: ?>
            <table class="table tickets-table">
                <thead><tr><th><?= e(t('Nr.')) ?></th><th><?= e(t('Betreff')) ?></th><th><?= e(t('Queue')) ?></th><th><?= e(t('Dringlichkeit')) ?></th>
                    <th><?= e(t('Status')) ?></th><th><?= e(t('Bearbeiter')) ?></th><?php if ($view !== 'mine'): ?><th><?= e(t('Erstellt von')) ?></th><?php endif; ?><th><?= e(t('Seit')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($list as $tk): ?>
                    <tr>
                        <td class="nowrap"><a href="<?= e(turl('ticket', ['id' => $tk['id']])) ?>">#<?= e($tk['number']) ?></a></td>
                        <td><a href="<?= e(turl('ticket', ['id' => $tk['id']])) ?>"><strong><?= e($tk['subject']) ?></strong></a>
                            <?php if ($tk['category'] !== ''): ?><br><span class="muted small"><?= e(t($tk['category'])) ?></span><?php endif; ?>
                            <?php if ($tk['visibility'] === 'group' && $tk['group_name'] !== null): ?> <span class="tag" title="<?= e(t('Sichtbar für die Gruppe')) ?>">👥 <?= e($tk['group_name']) ?></span><?php endif; ?></td>
                        <td><?= e($tk['queue_name'] ?? '–') ?></td>
                        <td><?= ticket_priority_badge($tk['priority']) ?></td>
                        <td><?= ticket_status_badge($tk['status']) ?><?= $tk['status'] === 'zurueckgestellt' && $tk['follow_up_at'] ? '<br><span class="muted small">' . e(t('bis')) . ' ' . fmt_date($tk['follow_up_at']) . '</span>' : '' ?>
                            <?php if ($tk['due_at'] !== ''): ?><br><?= ticket_due_badge($tk) ?><?php endif; ?>
                            <?php if ($view !== 'mine' && $view !== 'group' && $tk['waiting_since'] !== '' && (int)$tk['reminder_level'] > 0): ?><br><span class="tag <?= (int)$tk['reminder_level'] >= 2 ? 'danger' : 'warn' ?>" title="<?= e(t('Wartet seit')) ?> <?= e(ticket_age($tk['waiting_since'])) ?>"><?= e((int)$tk['reminder_level'] >= 2 ? t('eskaliert') : t('erinnert')) ?></span><?php endif; ?></td>
                        <td><?= $tk['assignee_name'] !== null ? e($tk['assignee_name']) : '<span class="muted">–</span>' ?></td>
                        <?php if ($view !== 'mine'): ?><td><?= e($tk['creator_name'] ?? '–') ?></td><?php endif; ?>
                        <td class="nowrap" title="<?= e(fmt_dt($tk['created_at'])) ?>"><?= e(ticket_age($tk['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Neues Ticket
// ---------------------------------------------------------------------------

function page_ticket_new(): void
{
    $cid = tickets_company_id();
    $u = current_user();
    $staff = is_staff_role($u['role']);
    if ($staff && !tickets_staff_may_see($cid)) abort(403, t('Die Tickets dieser Firma sind nur intern sichtbar.'));
    $queues = rows('SELECT * FROM ticket_queues WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]);
    if (!$queues) tredirect('tickets');
    // Bearbeiter (und der Dienstleister mit Einsicht) dürfen ein Ticket im Namen eines Mitarbeiters anlegen
    $onBehalf = $staff || tickets_is_processor($cid);
    $people = $onBehalf ? rows('SELECT id, name, email FROM users WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]) : [];
    $employees = rows("SELECT id, first_name, last_name, employee_no FROM employees WHERE company_id = ? AND status <> 'ausgetreten' ORDER BY last_name, first_name", [$cid]);
    $cats = ticket_categories($cid);
    // Sichtbarkeit: nur der Ersteller oder eine seiner Gruppen. Wer für jemanden anlegt, wählt aus allen Gruppen der Firma.
    $groups = [];
    if ($onBehalf) foreach (departments_of($cid) as $d) $groups[(int)$d['id']] = $d['name'];
    elseif (!$staff) $groups = user_groups((int)$u['id']);
    $data = ['queue_id' => (int)($queues[0]['id']), 'subject' => '', 'body' => '', 'priority' => 'einschraenkung', 'category' => '', 'employee_id' => 0,
        'created_by' => $staff ? 0 : (int)$u['id'], 'group_id' => 0];
    if (count($queues) > 1) $data['queue_id'] = query_int('queue');
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        $data['queue_id'] = (int)post('queue_id', 0);
        $data['subject'] = mb_substr(trim((string)post('subject')), 0, 190);
        $data['body'] = mb_substr(trim((string)post('body')), 0, 20000);
        $data['priority'] = (string)post('priority');
        $data['category'] = (string)post('category');
        $data['employee_id'] = (int)post('employee_id', 0);
        if ($onBehalf) $data['created_by'] = (int)post('created_by', 0);
        $data['group_id'] = (int)post('group_id', 0);
        if ($data['group_id'] && !isset($groups[$data['group_id']])) $data['group_id'] = 0;
        if (!in_array($data['queue_id'], array_map('intval', array_column($queues, 'id')), true)) $errors[] = t('Bitte eine Queue wählen.');
        if ($data['subject'] === '') $errors[] = t('Bitte einen Betreff angeben.');
        if ($data['body'] === '') $errors[] = t('Bitte beschreiben Sie das Anliegen.');
        if (!isset(TICKET_PRIORITIES[$data['priority']])) $errors[] = t('Bitte die Dringlichkeit wählen.');
        if ($data['category'] !== '' && !in_array($data['category'], $cats, true)) $data['category'] = '';
        if ($data['employee_id'] && !in_array($data['employee_id'], array_map('intval', array_column($employees, 'id')), true)) $data['employee_id'] = 0;
        if ($onBehalf && !in_array($data['created_by'], array_map('intval', array_column($people, 'id')), true)) $errors[] = t('Bitte wählen Sie, für wen das Ticket angelegt wird.');
        elseif ($data['group_id'] && $data['created_by'] && !isset(user_groups((int)$data['created_by'])[$data['group_id']])) {
            $errors[] = t('Der Ersteller ist nicht Mitglied der Gruppe „{x}“.', ['x' => $groups[$data['group_id']]]);
        }
        [$files, $fileErr] = ticket_uploads();
        if ($fileErr) $errors[] = $fileErr;
        if (!$errors) {
            $id = 0;
            for ($try = 0; $try < 3 && !$id; $try++) {
                [$year, $seq, $number] = ticket_next_number($cid);
                try {
                    $id = insert('tickets', ['company_id' => $cid, 'seq_year' => $year, 'seq_no' => $seq, 'number' => $number, 'queue_id' => $data['queue_id'],
                        'subject' => $data['subject'], 'priority' => $data['priority'], 'category' => $data['category'], 'status' => 'offen',
                        'created_by' => $data['created_by'], 'entered_by' => (int)$u['id'], 'employee_id' => $data['employee_id'] ?: null,
                        'visibility' => $data['group_id'] ? 'group' : 'me', 'group_id' => $data['group_id'] ?: null,
                        'waiting_since' => now(), 'reminder_level' => 0, 'due_at' => '', 'due_notified' => 0,
                        'assigned_to' => null, 'follow_up_at' => '', 'resolved_at' => '', 'closed_at' => '', 'viewing_by' => null, 'viewing_at' => '',
                        'created_at' => now(), 'updated_at' => now(), 'last_activity_at' => now()]);
                } catch (PDOException $ex) {
                    if ($try === 2) throw $ex;   // Nummer gerade vergeben: neu ziehen
                }
            }
            $msgId = ticket_log($id, $cid, (int)$u['id'], 'message', $data['body']);
            if ($files) ticket_store_uploads($files, $id, $msgId, $cid);
            if ((int)$data['created_by'] !== (int)$u['id']) {
                ticket_log($id, $cid, (int)$u['id'], 'event', 'Im Namen des Mitarbeiters angelegt');
            }
            $tk = load_ticket($id);
            audit('ticket.create', '#' . $tk['number'] . ' ' . $tk['subject'], $cid);
            $body = $data['body'];
            ticket_notify_processors($tk, fn() => t("Neues Ticket in der Queue „{queue}“\nVon: {name}\nDringlichkeit: {prio}\n\n", [
                'queue' => $tk['queue_name'], 'name' => $tk['creator_name'] ?? '–', 'prio' => t(TICKET_PRIORITIES[$tk['priority']])]) . $body, true);
            if ((int)$data['created_by'] !== (int)$u['id']) {
                ticket_notify_creator($tk, fn() => t("Für Sie wurde ein Ticket angelegt. Sie werden über jede Änderung informiert.\n\n") . $body);
            }
            flash('success', t('Das Ticket #{n} wurde angelegt.', ['n' => $tk['number']]));
            tredirect('ticket', ['id' => $id]);
        }
    }

    render_page(t('Neues Ticket'), function () use ($queues, $data, $errors, $cats, $employees, $onBehalf, $people, $groups) { ?>
        <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" class="form card">
            <?= csrf_field() ?>
            <?php if ($onBehalf): ?>
                <label><?= e(t('Für wen? *')) ?> <select name="created_by" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$data['created_by'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name'] . ' (' . $p['email'] . ')') ?></option><?php endforeach; ?>
                </select><span class="muted small"><?= e(t('Z. B. nach einem Anruf. Die Person gilt als Ersteller und wird über jede Änderung informiert.')) ?></span></label>
            <?php endif; ?>
            <div class="grid2">
                <label><?= e(t('Queue *')) ?> <select name="queue_id" required>
                    <?php if (count($queues) > 1): ?><option value=""><?= e(t('– bitte wählen –')) ?></option><?php endif; ?>
                    <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>" <?= (int)$data['queue_id'] === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?>
                </select></label>
                <label><?= e(t('Dringlichkeit *')) ?> <select name="priority" required>
                    <?php foreach (TICKET_PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>" <?= $data['priority'] === $k ? 'selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach; ?>
                </select></label>
            </div>
            <?php $qd = array_filter($queues, fn($q) => $q['description'] !== ''); if ($qd): ?>
                <ul class="muted small queue-hints"><?php foreach ($qd as $q): ?><li><strong><?= e($q['name']) ?>:</strong> <?= e($q['description']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <label><?= e(t('Betreff *')) ?> <input name="subject" maxlength="190" required value="<?= e($data['subject']) ?>"></label>
            <label><?= e(t('Beschreibung *')) ?> <textarea name="body" rows="7" required placeholder="<?= e(t('Was ist passiert? Seit wann? Was haben Sie schon versucht?')) ?>"><?= e($data['body']) ?></textarea></label>
            <div class="grid2">
                <label><?= e(t('Kategorie')) ?> <select name="category"><option value=""><?= e(t('– keine –')) ?></option>
                    <?php foreach ($cats as $c): ?><option value="<?= e($c) ?>" <?= $data['category'] === $c ? 'selected' : '' ?>><?= e(t($c)) ?></option><?php endforeach; ?>
                </select></label>
                <?php if ($employees): ?>
                <label><?= e(t('Betroffener Mitarbeiter')) ?> <select name="employee_id"><option value="0"><?= e(t('– keiner / ich selbst –')) ?></option>
                    <?php foreach ($employees as $em): ?><option value="<?= (int)$em['id'] ?>" <?= (int)$data['employee_id'] === (int)$em['id'] ? 'selected' : '' ?>><?= e($em['last_name'] . ', ' . $em['first_name']) ?></option><?php endforeach; ?>
                </select></label>
                <?php endif; ?>
            </div>
            <?php if ($groups): ?>
            <label><?= e(t('Wer soll das Ticket sehen?')) ?> <select name="group_id">
                <option value="0"><?= e($onBehalf ? t('Nur der Ersteller (und die Bearbeiter)') : t('Nur ich (und die Bearbeiter)')) ?></option>
                <?php foreach ($groups as $gid => $gname): ?><option value="<?= (int)$gid ?>" <?= (int)$data['group_id'] === (int)$gid ? 'selected' : '' ?>><?= e(t('Gruppe: {x}', ['x' => $gname])) ?></option><?php endforeach; ?>
            </select><span class="muted small"><?= e(t('Mitglieder der Gruppe sehen das Ticket und können mitschreiben. Mails bekommt nur der Ersteller.')) ?></span></label>
            <?php endif; ?>
            <label><?= e(t('Anhänge')) ?> <input type="file" name="files[]" multiple>
                <span class="muted small"><?= e(t('Bis zu {n} Dateien, je höchstens 10 MB (Bilder, PDF, Office, Text, ZIP).', ['n' => TICKET_ATTACH_COUNT])) ?></span></label>
            <div class="buttons"><button class="btn primary"><?= e(t('Ticket absenden')) ?></button> <a class="btn" href="<?= e(turl('tickets')) ?>"><?= e(t('Abbrechen')) ?></a></div>
        </form>
    <?php });
}

// ---------------------------------------------------------------------------
// Ticket ansehen
// ---------------------------------------------------------------------------

function ticket_for_request(): array
{
    $cid = tickets_company_id();
    $tk = load_ticket(query_int('id'));
    if (!$tk || (int)$tk['company_id'] !== $cid) abort(404);
    if (!ticket_can_view($tk)) abort(403);
    return $tk;
}

function page_ticket(): void
{
    $tk = ticket_for_request();
    $u = current_user();
    $cid = (int)$tk['company_id'];
    $proc = ticket_can_process($tk);
    // „Ziehen“: Bearbeiter, die das Ticket gerade ansehen, werden angezeigt
    if ($proc && !is_post()) {
        q('UPDATE tickets SET viewing_by = ?, viewing_at = ? WHERE id = ?', [$u['id'], now(), $tk['id']]);
    }
    $othersViewing = $proc && $tk['viewing_by'] && (int)$tk['viewing_by'] !== (int)$u['id']
        && strtotime((string)$tk['viewing_at']) > time() - TICKET_VIEW_MINUTES * 60 ? $tk['viewer_name'] : null;
    $msgs = rows('SELECT m.*, u.name AS user_name, u.role AS user_role FROM ticket_messages m LEFT JOIN users u ON u.id = m.user_id
        WHERE m.ticket_id = ?' . ($proc ? '' : " AND m.kind <> 'note'") . ' ORDER BY m.id', [$tk['id']]);
    $files = [];
    foreach (rows('SELECT id, message_id, filename, size FROM ticket_attachments WHERE ticket_id = ? ORDER BY id', [$tk['id']]) as $f) {
        $files[(int)$f['message_id']][] = $f;
    }
    $processors = $proc ? ticket_queue_processors((int)$tk['queue_id'], $cid) : [];
    $queues = $proc ? rows('SELECT id, name FROM ticket_queues WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]) : [];
    $employee = $tk['employee_id'] ? row('SELECT first_name, last_name, employee_no FROM employees WHERE id = ?', [$tk['employee_id']]) : null;
    $isCreator = (int)$tk['created_by'] === (int)$u['id'];
    $requester = $isCreator || ticket_in_group($tk);
    $lead = ticket_is_lead($tk);
    // Zuweisen: Teamleitung und Admins an jeden Bearbeiter; der gebuchte Bearbeiter gibt weiter
    $mayAssign = $lead || ($proc && (int)$tk['assigned_to'] === (int)$u['id']);

    render_page('#' . $tk['number'] . ' ' . $tk['subject'], function () use ($tk, $u, $proc, $othersViewing, $msgs, $files, $processors, $queues, $employee, $isCreator, $requester, $lead, $mayAssign) {
        $closed = in_array($tk['status'], TICKET_CLOSED, true);
        $action = e(turl('ticket_action', ['id' => $tk['id']])); ?>
        <p><a href="<?= e(turl('tickets')) ?>"><?= e(t('← Zur Übersicht')) ?></a></p>
        <?php if ($othersViewing): ?><div class="alert info"><?= e(t('{x} sieht sich dieses Ticket gerade an.', ['x' => $othersViewing])) ?></div><?php endif; ?>
        <div class="ticket-layout">
            <div class="ticket-main">
                <div class="card">
                    <ul class="chat">
                    <?php foreach ($msgs as $m):
                        $mine = (int)$m['user_id'] === (int)$u['id'];
                        $fromCreator = (int)$m['user_id'] === (int)$tk['created_by']; ?>
                        <?php if ($m['kind'] === 'event'): ?>
                            <li class="chat-event"><span class="muted small"><?= fmt_dt($m['created_at']) ?> · <?= e($m['user_name'] ?? t('System')) ?>:</span>
                                <?= e(t_parts($m['body'])) ?>
                                <?php if ($m['status_to'] !== ''): ?> <?= ticket_status_badge($m['status_from']) ?> → <?= ticket_status_badge($m['status_to']) ?><?php endif; ?></li>
                        <?php else: ?>
                            <li class="chat-msg <?= $m['kind'] === 'note' ? 'note' : ($fromCreator ? 'from-creator' : 'from-processor') ?> <?= $mine ? 'mine' : '' ?>">
                                <div class="meta"><strong><?= e($m['user_name'] ?? t('Gelöschter Benutzer')) ?></strong>
                                    <?= is_staff_role((string)($m['user_role'] ?? '')) ? ('<span class="tag">' . e(t('IT-Service')) . '</span>') : '' ?>
                                    <?= $m['kind'] === 'note' ? ('<span class="tag warn">' . e(t('interne Notiz')) . '</span>') : '' ?>
                                    · <span title="<?= e(fmt_dt($m['created_at'])) ?>"><?= e(ticket_age($m['created_at'])) ?></span></div>
                                <div class="prewrap"><?= e($m['body']) ?></div>
                                <?php if (!empty($files[(int)$m['id']])): ?>
                                    <ul class="attachments"><?php foreach ($files[(int)$m['id']] as $f): ?>
                                        <li><a href="<?= e(turl('ticket_file', ['id' => $f['id']])) ?>">📎 <?= e($f['filename']) ?></a> <span class="muted small"><?= e(backup_fmt_size((int)$f['size'])) ?></span></li>
                                    <?php endforeach; ?></ul>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </ul>
                </div>

                <?php if ($tk['status'] !== 'geschlossen' && ($proc || $requester)): ?>
                <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="form card">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reply">
                    <label><?= e($tk['status'] === 'rueckfrage' && $isCreator ? t('Antwort auf die Rückfrage') : t('Nachricht')) ?>
                        <textarea name="body" rows="4" required></textarea></label>
                    <label><?= e(t('Anhänge')) ?> <input type="file" name="files[]" multiple></label>
                    <?php if ($proc): ?>
                        <div class="grid2">
                            <label class="check"><input type="checkbox" name="note" value="1"> <?= e(t('Interne Notiz (für den Ersteller nicht sichtbar)')) ?></label>
                            <label><?= e(t('Status danach')) ?> <select name="status"><option value=""><?= e(t('– unverändert –')) ?></option>
                                <?php foreach (TICKET_MANUAL_STATUSES as $s): if ($s === 'zurueckgestellt') continue; ?><option value="<?= e($s) ?>"><?= e(t(TICKET_STATUSES[$s])) ?></option><?php endforeach; ?>
                            </select></label>
                        </div>
                    <?php endif; ?>
                    <button class="btn primary"><?= e(t('Senden')) ?></button>
                </form>
                <?php endif; ?>
            </div>

            <aside class="ticket-side">
                <div class="card">
                    <dl class="facts">
                        <dt><?= e(t('Status')) ?></dt><dd><?= ticket_status_badge($tk['status']) ?><?= $tk['status'] === 'zurueckgestellt' && $tk['follow_up_at'] ? '<br><span class="small">' . e(t('Wiedervorlage')) . ' ' . fmt_date($tk['follow_up_at']) . '</span>' : '' ?></dd>
                        <dt><?= e(t('Dringlichkeit')) ?></dt><dd><?= ticket_priority_badge($tk['priority']) ?></dd>
                        <?php if ($tk['due_at'] !== ''): ?><dt><?= e(t('Fällig am')) ?></dt><dd><?= ticket_due_badge($tk) ?></dd><?php endif; ?>
                        <?php if ($proc && $tk['waiting_since'] !== '' && !$closed): ?><dt><?= e(t('Wartet seit')) ?></dt><dd><?= e(ticket_age($tk['waiting_since'])) ?><?= (int)$tk['reminder_level'] >= 2 ? ' <span class="tag danger">' . e(t('eskaliert')) . '</span>' : ((int)$tk['reminder_level'] === 1 ? ' <span class="tag warn">' . e(t('erinnert')) . '</span>' : '') ?></dd><?php endif; ?>
                        <dt><?= e(t('Queue')) ?></dt><dd><?= e($tk['queue_name'] ?? '–') ?></dd>
                        <?php if ($tk['category'] !== ''): ?><dt><?= e(t('Kategorie')) ?></dt><dd><?= e(t($tk['category'])) ?></dd><?php endif; ?>
                        <dt><?= e(t('Bearbeiter')) ?></dt><dd><?= $tk['assignee_name'] !== null ? e($tk['assignee_name']) : '<span class="muted">' . e(t('noch niemand')) . '</span>' ?></dd>
                        <dt><?= e(t('Erstellt von')) ?></dt><dd><?= e($tk['creator_name'] ?? '–') ?></dd>
                        <dt><?= e(t('Sichtbar für')) ?></dt><dd><?= $tk['visibility'] === 'group' && $tk['group_name'] !== null ? '👥 ' . e(t('Gruppe: {x}', ['x' => $tk['group_name']])) : e(t('Ersteller')) ?></dd>
                        <?php if ($proc && $tk['lead_name'] !== null): ?><dt><?= e(t('Teamleitung')) ?></dt><dd><?= e($tk['lead_name']) ?></dd><?php endif; ?>
                        <?php if ($employee): ?><dt><?= e(t('Betroffener Mitarbeiter')) ?></dt><dd><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></dd><?php endif; ?>
                        <dt><?= e(t('Erstellt')) ?></dt><dd><?= fmt_dt($tk['created_at']) ?><br><span class="muted small"><?= e(ticket_age($tk['created_at'])) ?></span></dd>
                    </dl>
                </div>

                <?php if ($proc && $tk['status'] !== 'geschlossen'): ?>
                <div class="card ticket-actions">
                    <h3><?= e(t('Bearbeitung')) ?></h3>
                    <?php if ((int)$tk['assigned_to'] !== (int)$u['id']): ?>
                        <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="book">
                            <button class="btn primary"><?= e($tk['assigned_to'] ? t('Übernehmen') : t('Fest buchen')) ?></button></form>
                    <?php else: ?>
                        <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="release">
                            <button class="btn"><?= e(t('In die Queue zurückgeben')) ?></button></form>
                    <?php endif; ?>
                    <?php if ($mayAssign): ?>
                    <form method="post" action="<?= $action ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
                        <label><?= e((int)$tk['assigned_to'] === (int)$u['id'] ? t('Weitergeben an') : t('Zuweisen an')) ?> <select name="user_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                            <?php foreach ($processors as $p): if ((int)$p['id'] === (int)$tk['assigned_to']) continue; ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                        </select></label> <button class="btn small"><?= e(t('Zuweisen')) ?></button></form>
                    <?php endif; ?>
                    <?php if (count($queues) > 1): ?>
                    <form method="post" action="<?= $action ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="queue">
                        <label><?= e(t('Queue wechseln')) ?> <select name="queue_id">
                            <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>" <?= (int)$q['id'] === (int)$tk['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?>
                        </select></label> <button class="btn small"><?= e(t('Verschieben')) ?></button></form>
                    <?php endif; ?>
                    <form method="post" action="<?= $action ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="defer">
                        <label><?= e(t('Zurückstellen bis')) ?> <input type="date" name="follow_up_at" required min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"></label>
                        <button class="btn small"><?= e(t('Zurückstellen')) ?></button></form>
                    <form method="post" action="<?= $action ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="due">
                        <label><?= e(t('Fällig am')) ?> <input type="date" name="due_at" value="<?= e($tk['due_at']) ?>"></label>
                        <button class="btn small"><?= e(t('Setzen')) ?></button></form>
                    <form method="post" action="<?= $action ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="priority">
                        <label><?= e(t('Dringlichkeit')) ?> <select name="priority">
                            <?php foreach (TICKET_PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>" <?= $tk['priority'] === $k ? 'selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach; ?>
                        </select></label> <button class="btn small"><?= e(t('Ändern')) ?></button></form>
                    <?php if ($tk['status'] !== 'geloest'): ?>
                    <form method="post" action="<?= $action ?>"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="geloest">
                        <button class="btn"><?= e(t('Als gelöst markieren')) ?></button></form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($isCreator && $tk['status'] === 'geloest'): ?>
                <div class="card">
                    <p><?= e(t('Das Ticket wurde als gelöst markiert. Passt alles?')) ?></p>
                    <form method="post" action="<?= $action ?>" class="btn-row"><?= csrf_field() ?>
                        <button class="btn primary" name="action" value="confirm"><?= e(t('Ja, schließen')) ?></button>
                        <button class="btn" name="action" value="reopen"><?= e(t('Nein, wieder öffnen')) ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </aside>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Aktionen
// ---------------------------------------------------------------------------

function page_ticket_action(): void
{
    if (!is_post()) abort(405);
    require_post_csrf();
    $tk = ticket_for_request();
    $u = current_user();
    $cid = (int)$tk['company_id'];
    $id = (int)$tk['id'];
    $proc = ticket_can_process($tk);
    $isCreator = (int)$tk['created_by'] === (int)$u['id'];
    $requester = $isCreator || ticket_in_group($tk);
    $action = (string)post('action');
    $back = fn() => tredirect('ticket', ['id' => $id]);
    $setStatus = function (string $to, string $label = '', bool $notify = true) use (&$tk, $id, $cid, $u) {
        if ($to === $tk['status']) return;
        $data = ['status' => $to, 'updated_at' => now(), 'last_activity_at' => now()];
        if ($to === 'geloest') $data['resolved_at'] = now();
        if ($to === 'geschlossen') $data['closed_at'] = now();
        if (!in_array($to, TICKET_CLOSED, true)) { $data['resolved_at'] = ''; $data['closed_at'] = ''; }
        if ($to !== 'zurueckgestellt') $data['follow_up_at'] = '';
        // Uhr: wartet das Ticket wieder auf die Bearbeiter, läuft sie; sonst steht sie
        if (in_array($to, ['offen', 'wieder_eroeffnet'], true)) { if ($tk['waiting_since'] === '') { $data['waiting_since'] = now(); $data['reminder_level'] = 0; } }
        elseif ($to !== 'in_bearbeitung') { $data['waiting_since'] = ''; $data['reminder_level'] = 0; }
        update('tickets', $id, $data);
        ticket_log($id, $cid, (int)$u['id'], 'event', $label !== '' ? $label : 'Status geändert', ['status_from' => $tk['status'], 'status_to' => $to]);
        $from = $tk['status'];
        $tk = load_ticket($id);
        $t2 = $tk;
        if ($notify) ticket_notify_creator($t2, fn() => t("Der Status Ihres Tickets hat sich geändert: {from} → {to}", [
            'from' => t(TICKET_STATUSES[$from]), 'to' => t(TICKET_STATUSES[$t2['status']])]));
    };

    if ($tk['status'] === 'geschlossen' && $action !== 'reopen_closed') {
        flash('error', t('Das Ticket ist geschlossen.'));
        $back();
    }

    switch ($action) {
        case 'reply':
            if (!$proc && !$requester) abort(403);
            $body = mb_substr(trim((string)post('body')), 0, 20000);
            if ($body === '') { flash('error', t('Bitte eine Nachricht eingeben.')); $back(); }
            [$files, $fileErr] = ticket_uploads();
            if ($fileErr) { flash('error', $fileErr); $back(); }
            $note = $proc && post('note') === '1';
            $msgId = ticket_log($id, $cid, (int)$u['id'], $note ? 'note' : 'message', $body);
            if ($files) ticket_store_uploads($files, $id, $msgId, $cid);
            update('tickets', $id, ['updated_at' => now(), 'last_activity_at' => now()]);
            $status = (string)post('status');
            $statusLine = '';
            if ($proc && in_array($status, TICKET_MANUAL_STATUSES, true) && $status !== 'zurueckgestellt' && $status !== $tk['status']) {
                if ($status === 'in_bearbeitung' && !$tk['assigned_to']) {
                    update('tickets', $id, ['assigned_to' => $u['id']]);
                    ticket_log($id, $cid, (int)$u['id'], 'event', 'Fest gebucht');
                }
                $from = $tk['status'];
                $setStatus($status, '', $note);   // bei einer Nachricht steht der neue Status in derselben Mail
                $statusLine = $note ? '' : $from . '>' . $status;
            }
            if (!$note) {
                // Antwort der Bearbeiter hält die Uhr an, Antwort der Ersteller-Seite startet sie
                if ($requester && (!$proc || (int)$tk['assigned_to'] !== (int)$u['id'])) ticket_wait_start($id);
                elseif ($proc) ticket_wait_stop($id);
                if ($requester && (!$proc || (int)$tk['assigned_to'] !== (int)$u['id'])) {
                    // Gruppen-Ticket: der Ersteller bekommt seine eigene Mail (unten), auch wenn er Bearbeiter ist
                    ticket_notify_processors($tk, fn() => t("{name} hat geantwortet:\n\n", ['name' => $u['name']]) . $body, false, $isCreator ? [] : [(int)$tk['created_by']]);
                    // Gruppen-Ticket: der Ersteller erfährt, was ein Gruppenmitglied geschrieben hat
                    if (!$isCreator) ticket_notify_creator($tk, fn() => t("{name} hat auf Ihr Ticket geantwortet:\n\n", ['name' => $u['name']]) . $body);
                    // Antwort auf eine Rückfrage oder auf ein gelöstes Ticket: zurück in die Bearbeitung
                    if ($tk['status'] === 'rueckfrage') $setStatus($tk['assigned_to'] ? 'in_bearbeitung' : 'offen', 'Rückfrage beantwortet');
                    elseif ($tk['status'] === 'geloest') $setStatus('wieder_eroeffnet', 'Vom Ersteller wieder eröffnet');
                } else {
                    ticket_notify_creator($tk, function () use ($u, $body, $statusLine) {
                        $txt = t("{name} hat auf Ihr Ticket geantwortet:\n\n", ['name' => $u['name']]) . $body;
                        if ($statusLine !== '') {
                            [$a, $b] = explode('>', $statusLine);
                            $txt .= "\n\n" . t('Neuer Status: {to}', ['to' => t(TICKET_STATUSES[$b])]);
                        }
                        return $txt;
                    });
                }
            }
            flash('success', $note ? t('Die interne Notiz wurde gespeichert.') : t('Die Nachricht wurde gesendet.'));
            $back();

        case 'book':
            if (!$proc) abort(403);
            $prev = $tk['assignee_name'];
            update('tickets', $id, ['assigned_to' => $u['id'], 'updated_at' => now(), 'last_activity_at' => now()]);
            ticket_wait_stop($id);
            ticket_log($id, $cid, (int)$u['id'], 'event', $prev !== null ? 'Übernommen von: ' . $prev : 'Fest gebucht');
            if (in_array($tk['status'], ['offen', 'wieder_eroeffnet'], true)) $setStatus('in_bearbeitung');
            else ticket_notify_creator($tk, fn() => t('{name} bearbeitet jetzt Ihr Ticket.', ['name' => $u['name']]));
            if ($prev !== null && $tk['assigned_to']) {
                $other = row('SELECT email FROM users WHERE id = ? AND active = 1', [$tk['assigned_to']]);
                if ($other) send_mail_t([$other['email']], fn() => [ticket_mail_subject($tk),
                    t('{name} hat das Ticket von Ihnen übernommen.', ['name' => $u['name']]) . t("\n\nZum Ticket: ") . ticket_link($id)], $cid);
            }
            flash('success', t('Das Ticket ist jetzt Ihnen zugeordnet.'));
            $back();

        case 'release':
            if (!$proc) abort(403);
            update('tickets', $id, ['assigned_to' => null, 'updated_at' => now(), 'last_activity_at' => now()]);
            if (in_array($tk['status'], TICKET_WAIT_STATUSES, true)) ticket_wait_start($id, true);
            ticket_log($id, $cid, (int)$u['id'], 'event', 'In die Queue zurückgegeben');
            if ($tk['status'] === 'in_bearbeitung') $setStatus('offen');
            $t2 = load_ticket($id);
            ticket_notify_processors($t2, fn() => t('{name} hat das Ticket in die Queue „{queue}“ zurückgegeben.', ['name' => $u['name'], 'queue' => $t2['queue_name']]), true);
            flash('success', t('Das Ticket liegt wieder offen in der Queue.'));
            $back();

        case 'assign':
            if (!ticket_is_lead($tk) && !($proc && (int)$tk['assigned_to'] === (int)$u['id'])) {
                abort(403, t('Zuweisen dürfen die Teamleitung der Queue und die Administratoren. Sie können das Ticket selbst übernehmen.'));
            }
            $to = (int)post('user_id', 0);
            $target = null;
            foreach (ticket_queue_processors((int)$tk['queue_id'], $cid) as $p) if ((int)$p['id'] === $to) $target = $p;
            if (!$target) { flash('error', t('Bitte einen Bearbeiter dieser Queue wählen.')); $back(); }
            $prevAssigned = (int)$tk['assigned_to'];
            update('tickets', $id, ['assigned_to' => $to, 'updated_at' => now(), 'last_activity_at' => now()]);
            if ($to === (int)$u['id']) ticket_wait_stop($id);
            elseif (in_array($tk['status'], TICKET_WAIT_STATUSES, true)) ticket_wait_start($id, true);
            ticket_log($id, $cid, (int)$u['id'], 'event', 'Zugewiesen an: ' . $target['name']);
            if (in_array($tk['status'], ['offen', 'wieder_eroeffnet'], true)) $setStatus('in_bearbeitung');
            if ($prevAssigned !== $to) {
                $t2 = load_ticket($id);
                ticket_notify_creator($t2, fn() => t('Ihr Ticket wird jetzt von {name} bearbeitet.', ['name' => $target['name']]));
            }
            if ($to !== (int)$u['id']) {
                send_mail_t([$target['email']], fn() => [ticket_mail_subject($tk),
                    t('{name} hat Ihnen das Ticket zugewiesen.', ['name' => $u['name']]) . t("\n\nZum Ticket: ") . ticket_link($id)], $cid);
            }
            flash('success', t('Das Ticket wurde {x} zugewiesen.', ['x' => $target['name']]));
            $back();

        case 'queue':
            if (!$proc) abort(403);
            $q = row('SELECT id, name FROM ticket_queues WHERE id = ? AND company_id = ? AND active = 1', [(int)post('queue_id', 0), $cid]);
            if (!$q || (int)$q['id'] === (int)$tk['queue_id']) $back();
            update('tickets', $id, ['queue_id' => $q['id'], 'assigned_to' => null, 'updated_at' => now(), 'last_activity_at' => now()]);
            if (in_array($tk['status'], TICKET_WAIT_STATUSES, true)) ticket_wait_start($id, true);
            ticket_log($id, $cid, (int)$u['id'], 'event', 'Verschoben in Queue: ' . $q['name']);
            if ($tk['status'] === 'in_bearbeitung') $setStatus('offen');
            $t2 = load_ticket($id);
            ticket_notify_processors($t2, fn() => t('Das Ticket wurde in die Queue „{queue}“ verschoben.', ['queue' => $q['name']]), true);
            ticket_notify_creator($t2, fn() => t('Ihr Ticket wurde an die Queue „{queue}“ weitergegeben.', ['queue' => $q['name']]));
            flash('success', t('Das Ticket liegt jetzt in der Queue „{x}“.', ['x' => $q['name']]));
            if (!ticket_can_process($t2) && !$isCreator) tredirect('tickets');
            $back();

        case 'defer':
            if (!$proc) abort(403);
            $d = (string)post('follow_up_at');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d <= date('Y-m-d')) { flash('error', t('Bitte ein Datum in der Zukunft wählen.')); $back(); }
            $setStatus('zurueckgestellt', 'Zurückgestellt bis: ' . fmt_date($d));
            update('tickets', $id, ['follow_up_at' => $d]);
            flash('success', t('Das Ticket ist bis {x} zurückgestellt.', ['x' => fmt_date($d)]));
            $back();

        case 'priority':
            if (!$proc) abort(403);
            $p = (string)post('priority');
            if (!isset(TICKET_PRIORITIES[$p]) || $p === $tk['priority']) $back();
            update('tickets', $id, ['priority' => $p, 'updated_at' => now()]);
            ticket_log($id, $cid, (int)$u['id'], 'event', 'Dringlichkeit: ' . TICKET_PRIORITIES[$p]);
            $back();

        case 'due':
            if (!$proc) abort(403);
            $d = (string)post('due_at');
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $back();
            if ($d === $tk['due_at']) $back();
            update('tickets', $id, ['due_at' => $d, 'due_notified' => 0, 'updated_at' => now()]);
            ticket_log($id, $cid, (int)$u['id'], 'event', $d !== '' ? 'Fällig am: ' . fmt_date($d) : 'Fälligkeit entfernt');
            flash('success', $d !== '' ? t('Das Ticket ist am {x} fällig.', ['x' => fmt_date($d)]) : t('Die Fälligkeit wurde entfernt.'));
            $back();

        case 'status':
            if (!$proc) abort(403);
            $s = (string)post('status');
            if (!in_array($s, TICKET_MANUAL_STATUSES, true) || $s === 'zurueckgestellt') $back();
            $setStatus($s);
            if ($s === 'in_bearbeitung') ticket_wait_stop($id);
            flash('success', t('Der Status wurde geändert.'));
            $back();

        case 'confirm':
            if (!$isCreator || $tk['status'] !== 'geloest') abort(403);
            $setStatus('geschlossen', 'Vom Ersteller bestätigt');
            flash('success', t('Danke! Das Ticket ist geschlossen.'));
            $back();

        case 'reopen':
            if (!$isCreator || $tk['status'] !== 'geloest') abort(403);
            $setStatus('wieder_eroeffnet', 'Vom Ersteller wieder eröffnet');
            $t2 = load_ticket($id);
            ticket_notify_processors($t2, fn() => t('{name} hat das Ticket wieder eröffnet.', ['name' => $u['name']]));
            flash('success', t('Das Ticket ist wieder offen. Schreiben Sie gern dazu, was noch nicht passt.'));
            $back();
    }
    $back();
}

// ---------------------------------------------------------------------------
// Anhang herunterladen
// ---------------------------------------------------------------------------

function page_ticket_file(): void
{
    $cid = tickets_company_id();
    $f = row('SELECT a.*, m.kind FROM ticket_attachments a JOIN ticket_messages m ON m.id = a.message_id WHERE a.id = ? AND a.company_id = ?', [query_int('id'), $cid]);
    if (!$f) abort(404);
    $tk = load_ticket((int)$f['ticket_id']);
    if (!$tk || !ticket_can_view($tk)) abort(403);
    if ($f['kind'] === 'note' && !ticket_can_process($tk)) abort(403);
    $path = ticket_files_dir($cid) . '/' . basename((string)$f['stored_name']);
    if (!is_file($path)) abort(404);
    $inline = in_array($f['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true);
    header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string)$f['filename']));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------------
// Queues & Einstellungen
// ---------------------------------------------------------------------------

function page_ticket_settings(): void
{
    $cid = tickets_company_id();
    if (!tickets_can_manage()) abort(403, t('Queues und Einstellungen pflegen die Administratoren der Firma.'));
    $u = current_user();
    $staff = is_staff_role($u['role']);
    $users = rows("SELECT id, name, email, role FROM users WHERE company_id = ? AND active = 1 ORDER BY name", [$cid]);
    $userIds = array_map('intval', array_column($users, 'id'));
    $deps = departments_of($cid);
    $depIds = array_map('intval', array_column($deps, 'id'));

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        if ($action === 'queue_save') {
            $qid = (int)post('queue_id', 0);
            $name = mb_substr(trim((string)post('name')), 0, 120);
            if ($name === '') { flash('error', t('Bitte einen Namen angeben.')); tredirect('ticket_settings'); }
            $lead = (int)post('lead_user_id', 0);
            $data = ['name' => $name, 'description' => mb_substr(trim((string)post('description')), 0, 500), 'sort' => (int)post('sort', 0),
                'active' => post('active') === '1' ? 1 : 0, 'lead_user_id' => in_array($lead, $userIds, true) ? $lead : null];
            if ($qid) {
                if (!row('SELECT id FROM ticket_queues WHERE id = ? AND company_id = ?', [$qid, $cid])) abort(404);
                update('ticket_queues', $qid, $data);
            } else {
                $qid = insert('ticket_queues', $data + ['company_id' => $cid, 'created_at' => now()]);
            }
            $members = array_values(array_intersect(array_map('intval', (array)($_POST['members'] ?? [])), $userIds));
            q('DELETE FROM ticket_queue_members WHERE queue_id = ?', [$qid]);
            foreach ($members as $m) insert('ticket_queue_members', ['queue_id' => $qid, 'user_id' => $m]);
            $qgroups = array_values(array_intersect(array_map('intval', (array)($_POST['groups'] ?? [])), $depIds));
            q('DELETE FROM ticket_queue_groups WHERE queue_id = ?', [$qid]);
            foreach ($qgroups as $g) insert('ticket_queue_groups', ['queue_id' => $qid, 'department_id' => $g]);
            audit('ticket.queue', $name . ' (' . count($members) . ' / ' . count($qgroups) . ')', $cid);
            flash('success', t('Die Queue „{x}“ wurde gespeichert.', ['x' => $name]));
            tredirect('ticket_settings');
        }
        if ($action === 'queue_delete') {
            $q = row('SELECT * FROM ticket_queues WHERE id = ? AND company_id = ?', [(int)post('queue_id', 0), $cid]);
            if (!$q) abort(404);
            if (row('SELECT id FROM tickets WHERE queue_id = ? LIMIT 1', [$q['id']])) {
                flash('error', t('Die Queue enthält Tickets und kann nur deaktiviert werden.'));
                tredirect('ticket_settings');
            }
            q('DELETE FROM ticket_queue_members WHERE queue_id = ?', [$q['id']]);
            q('DELETE FROM ticket_queue_groups WHERE queue_id = ?', [$q['id']]);
            q('DELETE FROM ticket_queues WHERE id = ?', [$q['id']]);
            audit('ticket.queue_delete', $q['name'], $cid);
            flash('success', t('Die Queue wurde gelöscht.'));
            tredirect('ticket_settings');
        }
        if ($action === 'group_save') {
            $did = (int)post('department_id', 0);
            if (!in_array($did, $depIds, true)) abort(404);
            $ids = array_values(array_intersect(array_map('intval', (array)($_POST['members'] ?? [])), $userIds));
            user_departments_set_for_group($did, $ids, $userIds);
            $dname = '';
            foreach ($deps as $d) if ((int)$d['id'] === $did) $dname = $d['name'];
            audit('ticket.group', $dname . ' (' . count($ids) . ')', $cid);
            flash('success', t('Die Gruppe „{x}“ wurde gespeichert.', ['x' => $dname]));
            tredirect('ticket_settings');
        }
        if ($action === 'settings') {
            $data = ['ticket_autoclose_days' => max(1, min(90, (int)post('autoclose_days', 7))),
                'ticket_categories' => mb_substr(implode("\n", array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 60),
                    preg_split('/\R/', (string)post('categories')) ?: []))), 0, 40)), 0, 3000)];
            $times = [];
            foreach (TICKET_TIMES_DEFAULT as $p => $def) {
                $r = max(0, min(720, (int)(($_POST['remind'] ?? [])[$p] ?? $def[0])));
                $x = max(0, min(720, (int)(($_POST['escalate'] ?? [])[$p] ?? $def[1])));
                if ($x && $r && $x < $r) $x = $r;   // Eskalation nicht vor der Erinnerung
                $times[$p] = [$r, $x];
            }
            $data['ticket_times'] = json_encode(['on' => post('times_on') === '1', 'skip_weekend' => post('skip_weekend') === '1', 'times' => $times]);
            // Die Einsicht für den Dienstleister schaltet nur die Firma selbst
            if (!$staff) $data['ticket_provider_access'] = post('provider_access') === '1' ? 1 : 0;
            update('companies', $cid, $data);
            audit('ticket.settings', json_encode($data, JSON_UNESCAPED_UNICODE), $cid);
            flash('success', t('Die Einstellungen wurden gespeichert.'));
            tredirect('ticket_settings');
        }
    }

    $queues = rows('SELECT q.*, (SELECT COUNT(*) FROM tickets t WHERE t.queue_id = q.id AND t.status NOT IN (\'geloest\', \'geschlossen\')) AS open_count
        FROM ticket_queues q WHERE q.company_id = ? ORDER BY q.sort, q.name', [$cid]);
    $members = [];
    foreach (rows('SELECT m.queue_id, m.user_id FROM ticket_queue_members m JOIN ticket_queues q ON q.id = m.queue_id WHERE q.company_id = ?', [$cid]) as $m) {
        $members[(int)$m['queue_id']][] = (int)$m['user_id'];
    }
    $qgroups = [];
    foreach (rows('SELECT g.queue_id, g.department_id FROM ticket_queue_groups g JOIN ticket_queues q ON q.id = g.queue_id WHERE q.company_id = ?', [$cid]) as $g) {
        $qgroups[(int)$g['queue_id']][] = (int)$g['department_id'];
    }
    $gmembers = [];
    foreach (rows('SELECT ud.department_id, ud.user_id FROM user_departments ud JOIN departments d ON d.id = ud.department_id WHERE d.company_id = ?', [$cid]) as $m) {
        $gmembers[(int)$m['department_id']][] = (int)$m['user_id'];
    }
    $userNames = array_column($users, 'name', 'id');
    $depNames = array_column($deps, 'name', 'id');
    $co = tickets_company($cid);
    $cats = ticket_categories($cid);
    $tcfg = ticket_times($cid);

    render_page(t('Queues & Einstellungen'), function () use ($cid, $staff, $users, $queues, $members, $qgroups, $deps, $gmembers, $userNames, $depNames, $co, $cats, $tcfg) {
        if (is_admin()) echo company_switcher($cid, 'ticket_settings'); ?>
        <p><a href="<?= e(turl('tickets')) ?>"><?= e(t('← Zur Übersicht')) ?></a></p>
        <div class="card">
            <h2><?= e(t('Queues')) ?></h2>
            <p class="muted"><?= e(t('Beim Erstellen wählt man die Queue. Bearbeiter einer Queue sehen und bearbeiten deren Tickets und bekommen bei neuen Tickets eine Mail. Bearbeiter sind die angehakten Gruppen und Personen, die Teamleitung und die Firmen-Administratoren (Admin-Gruppe). Zuweisen dürfen Teamleitung und Administratoren.')) ?></p>
            <?php foreach ($queues as $q): $qm = $members[(int)$q['id']] ?? []; $qg = $qgroups[(int)$q['id']] ?? []; ?>
                <details class="queue-box">
                    <summary><strong><?= e($q['name']) ?></strong> <?= (int)$q['active'] ? '' : '<span class="tag">' . e(t('inaktiv')) . '</span>' ?>
                        <span class="muted small">· <?= e(t('{n} offene Tickets', ['n' => (int)$q['open_count']])) ?>
                        <?php if ($qg): ?>· 👥 <?= e(implode(', ', array_map(fn($g) => $depNames[$g] ?? '?', $qg))) ?><?php endif; ?>
                        <?php if ($qm): ?>· <?= e(t('{n} Personen', ['n' => count($qm)])) ?><?php endif; ?>
                        <?php if ($q['lead_user_id'] && isset($userNames[$q['lead_user_id']])): ?>· <?= e(t('Teamleitung: {x}', ['x' => $userNames[$q['lead_user_id']]])) ?><?php endif; ?></span></summary>
                    <?php ticket_queue_form($q, $qm, $qg, $users, $deps); ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="queue_delete"><input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                        <button class="btn small danger" data-confirm="<?= e(t('Queue „{x}“ löschen?', ['x' => $q['name']])) ?>"><?= e(t('Queue löschen')) ?></button></form>
                </details>
            <?php endforeach; ?>
            <details class="queue-box" <?= $queues ? '' : 'open' ?>>
                <summary><strong><?= e(t('+ Neue Queue')) ?></strong></summary>
                <?php ticket_queue_form(null, [], [], $users, $deps); ?>
            </details>
        </div>
        <div class="card">
            <h2><?= e(t('Gruppen')) ?></h2>
            <p class="muted"><?= e(t('Gruppen sind die Abteilungen der Firma (Stammdaten). Ein Konto kann in mehreren Gruppen sein. Gruppen werden als Bearbeiter einer Queue eingetragen; beim Erstellen kann man ein Ticket für die eigene Gruppe sichtbar machen.')) ?></p>
            <?php if (!$deps): ?>
                <p class="muted"><?= e(t('Es sind noch keine Abteilungen angelegt.')) ?> <a href="<?= e(url('structure', is_admin() ? ['company' => $cid] : [])) ?>"><?= e(t('Abteilungen anlegen')) ?></a></p>
            <?php endif; ?>
            <?php foreach ($deps as $d): $gm = $gmembers[(int)$d['id']] ?? []; ?>
                <details class="queue-box">
                    <summary><strong><?= e($d['name']) ?></strong> <span class="muted small">· <?= e(t('{n} Mitglieder', ['n' => count(array_intersect($gm, array_map('intval', array_keys($userNames))))])) ?>
                        <?php if ($gm): ?>: <?= e(implode(', ', array_filter(array_map(fn($x) => $userNames[$x] ?? null, $gm)))) ?><?php endif; ?></span></summary>
                    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="group_save"><input type="hidden" name="department_id" value="<?= (int)$d['id'] ?>">
                        <div class="checks">
                        <?php foreach ($users as $usr): ?>
                            <label class="check"><input type="checkbox" name="members[]" value="<?= (int)$usr['id'] ?>" <?= in_array((int)$usr['id'], $gm, true) ? 'checked' : '' ?>> <?= e($usr['name']) ?></label>
                        <?php endforeach; ?>
                        </div>
                        <button class="btn primary"><?= e(t('Gruppe speichern')) ?></button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
        <form method="post" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="settings">
            <h2><?= e(t('Einstellungen')) ?></h2>
            <label><?= e(t('Gelöste Tickets automatisch schließen nach (Tagen)')) ?> <input type="number" name="autoclose_days" min="1" max="90" value="<?= (int)($co['ticket_autoclose_days'] ?? 7) ?>">
                <span class="muted small"><?= e(t('Bis dahin kann der Ersteller das Ticket wieder öffnen.')) ?></span></label>
            <h3><?= e(t('Erinnerung und Eskalation')) ?></h3>
            <p class="muted small"><?= e(t('Wartet ein Ticket zu lange auf eine Reaktion der Bearbeiter, bekommt zuerst der gebuchte Bearbeiter (sonst die Queue) eine Erinnerung, danach die Teamleitung der Queue und nach der doppelten Eskalationszeit die Firmen-Administratoren. Die Uhr steht bei Rückfrage, Zurückgestellt und Gelöst und beginnt neu, sobald ein Bearbeiter antwortet oder das Ticket bucht. 0 = aus.')) ?></p>
            <label class="check"><input type="checkbox" name="times_on" value="1" <?= $tcfg['on'] ? 'checked' : '' ?>> <?= e(t('Erinnerungen und Eskalation einschalten')) ?></label>
            <label class="check"><input type="checkbox" name="skip_weekend" value="1" <?= $tcfg['skip_weekend'] ? 'checked' : '' ?>> <?= e(t('Samstag und Sonntag nicht mitzählen')) ?></label>
            <table class="table times-table">
                <thead><tr><th><?= e(t('Dringlichkeit')) ?></th><th><?= e(t('Erinnerung nach (Std.)')) ?></th><th><?= e(t('Eskalation nach (Std.)')) ?></th></tr></thead>
                <tbody>
                <?php foreach (TICKET_PRIORITIES as $p => $label): [$r, $x] = $tcfg['times'][$p]; ?>
                    <tr><td><?= ticket_priority_badge($p) ?></td>
                        <td><input type="number" name="remind[<?= e($p) ?>]" min="0" max="720" value="<?= (int)$r ?>" aria-label="<?= e(t('Erinnerung nach (Std.)')) ?>"></td>
                        <td><input type="number" name="escalate[<?= e($p) ?>]" min="0" max="720" value="<?= (int)$x ?>" aria-label="<?= e(t('Eskalation nach (Std.)')) ?>"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small"><?= e(t('Geprüft wird bei jedem Lauf des Cronjobs (siehe Datensicherung, am besten stündlich) und zusätzlich alle paar Minuten, während jemand das Portal nutzt.')) ?></p>
            <h3><?= e(t('Allgemein')) ?></h3>
            <label><?= e(t('Kategorien (eine pro Zeile)')) ?> <textarea name="categories" rows="6"><?= e(implode("\n", $cats)) ?></textarea></label>
            <label class="check"><input type="checkbox" name="provider_access" value="1" <?= (int)($co['ticket_provider_access'] ?? 0) ? 'checked' : '' ?> <?= $staff ? 'disabled' : '' ?>>
                <?= e(t('Einsicht für den IT-Dienstleister: Unser IT-Dienstleister darf unsere Tickets sehen und bearbeiten')) ?></label>
            <?php if ($staff): ?><p class="muted small"><?= e(t('Diese Einsicht kann nur ein Administrator der Firma ein- oder ausschalten.')) ?></p><?php endif; ?>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>
    <?php });
}

function ticket_queue_form(?array $q, array $qm, array $qg, array $users, array $deps): void
{ ?>
    <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="queue_save"><input type="hidden" name="queue_id" value="<?= (int)($q['id'] ?? 0) ?>">
        <div class="grid2">
            <label><?= e(t('Name *')) ?> <input name="name" maxlength="120" required value="<?= e($q['name'] ?? '') ?>" placeholder="<?= e(t('z. B. IT, Haustechnik, Personal')) ?>"></label>
            <label><?= e(t('Reihenfolge')) ?> <input type="number" name="sort" value="<?= (int)($q['sort'] ?? 0) ?>"></label>
        </div>
        <label><?= e(t('Beschreibung (wird beim Erstellen angezeigt)')) ?> <input name="description" maxlength="500" value="<?= e($q['description'] ?? '') ?>"></label>
        <?php if ($deps): ?>
        <fieldset><legend><?= e(t('Bearbeiter-Gruppen')) ?></legend>
            <div class="checks">
            <?php foreach ($deps as $d): ?>
                <label class="check"><input type="checkbox" name="groups[]" value="<?= (int)$d['id'] ?>" <?= in_array((int)$d['id'], $qg, true) ? 'checked' : '' ?>> 👥 <?= e($d['name']) ?></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endif; ?>
        <label><?= e(t('Teamleitung')) ?> <select name="lead_user_id"><option value="0"><?= e(t('– keine –')) ?></option>
            <?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (int)($q['lead_user_id'] ?? 0) === (int)$usr['id'] ? 'selected' : '' ?>><?= e($usr['name']) ?></option><?php endforeach; ?>
        </select><span class="muted small"><?= e(t('Weist Tickets zu und ist Bearbeiter der Queue. Bei zu langer Wartezeit wird an sie eskaliert (folgt).')) ?></span></label>
        <fieldset><legend><?= e(t('Weitere Bearbeiter (einzelne Personen)')) ?></legend>
            <div class="checks">
            <?php foreach ($users as $usr): ?>
                <label class="check"><input type="checkbox" name="members[]" value="<?= (int)$usr['id'] ?>" <?= in_array((int)$usr['id'], $qm, true) || $usr['role'] === 'firma_admin' ? 'checked' : '' ?> <?= $usr['role'] === 'firma_admin' ? 'disabled' : '' ?>>
                    <?= e($usr['name']) ?><?= $usr['role'] === 'firma_admin' ? ' <span class="tag">' . e(t('Admin')) . '</span>' : '' ?></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <label class="check"><input type="checkbox" name="active" value="1" <?= !$q || (int)$q['active'] ? 'checked' : '' ?>> <?= e(t('Aktiv (beim Erstellen auswählbar)')) ?></label>
        <button class="btn primary"><?= e(t('Queue speichern')) ?></button>
    </form>
<?php }

/** Mitglieder einer Gruppe setzen (nur Konten der Firma, Mitgliedschaften in anderen Gruppen bleiben) */
function user_departments_set_for_group(int $departmentId, array $userIds, array $companyUserIds): void
{
    if ($companyUserIds) q('DELETE FROM user_departments WHERE department_id = ? AND user_id IN (' . implode(',', array_map('intval', $companyUserIds)) . ')', [$departmentId]);
    foreach (array_unique($userIds) as $uid) insert('user_departments', ['user_id' => (int)$uid, 'department_id' => $departmentId]);
}

/** Gruppen eines Kontos setzen (nur Abteilungen der Firma) */
function user_departments_set_for_user(int $userId, array $departmentIds, array $companyDepartmentIds): void
{
    if ($companyDepartmentIds) q('DELETE FROM user_departments WHERE user_id = ? AND department_id IN (' . implode(',', array_map('intval', $companyDepartmentIds)) . ')', [$userId]);
    foreach (array_unique(array_intersect(array_map('intval', $departmentIds), $companyDepartmentIds)) as $d) insert('user_departments', ['user_id' => $userId, 'department_id' => (int)$d]);
}
