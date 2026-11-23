<?php
/**
 * App „Tickets“ – Auswertung (Stufe 6)
 *
 * Sieht die Admin-Gruppe der Firma (Firmen-Admins) und der IT-Dienstleister mit Einsicht.
 * Zahlen je Queue, Dringlichkeit, Kategorie und Abteilung, Zeiten (erste Antwort, Lösung,
 * beim Dienstleister), Tickets mit Erinnerung/Eskalation. Die Mitarbeiter-Statistik
 * schaltet die Firma selbst ein (Spalte companies.ticket_staff_stats, Standard aus).
 *
 * Zeiten sind Kalenderzeit (ohne Abzug von Nacht und Wochenende).
 */

declare(strict_types=1);

const TICKET_STATS_PERIODS = ['30' => 'Letzte 30 Tage', 'month' => 'Dieser Monat', 'lastmonth' => 'Letzter Monat', '90' => 'Letzte 90 Tage',
    'year' => 'Dieses Jahr', 'lastyear' => 'Letztes Jahr', 'custom' => 'Eigener Zeitraum'];

/** Darf die Auswertung sehen: Firmen-Admins; der IT-Dienstleister nur mit Einsicht der Firma */
function tickets_stats_allowed(int $cid): bool
{
    $u = current_user();
    if (!$u) return false;
    if (is_staff_role($u['role'])) return admin_can('tickets', false) && tickets_staff_may_see($cid);
    return $u['role'] === 'firma_admin';
}

/** Zeitraum aus der Anfrage: [Schlüssel, von (Y-m-d), bis (Y-m-d)] */
function ticket_stats_period(): array
{
    $p = (string)($_GET['period'] ?? '30');
    if (!isset(TICKET_STATS_PERIODS[$p])) $p = '30';
    $today = date('Y-m-d');
    switch ($p) {
        case 'month': return [$p, date('Y-m-01'), $today];
        case 'lastmonth': return [$p, date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))];
        case '90': return [$p, date('Y-m-d', strtotime('-89 days')), $today];
        case 'year': return [$p, date('Y-01-01'), $today];
        case 'lastyear': return [$p, (date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'];
        case 'custom':
            $from = (string)($_GET['from'] ?? '');
            $to = (string)($_GET['to'] ?? '');
            $ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
            if (!$ok($from)) $from = date('Y-m-d', strtotime('-29 days'));
            if (!$ok($to)) $to = $today;
            if ($to < $from) [$from, $to] = [$to, $from];
            // höchstens zwei Jahre, damit die Seite schnell bleibt
            if (strtotime($to) - strtotime($from) > 731 * 86400) $from = date('Y-m-d', strtotime($to . ' -731 days'));
            return [$p, $from, $to];
        default: return ['30', date('Y-m-d', strtotime('-29 days')), $today];
    }
}

/** Dauer in Sekunden lesbar: „35 Min.“, „4 Std. 10 Min.“, „2 T. 3 Std.“ */
function ticket_stats_dur(?float $sec): string
{
    if ($sec === null) return '–';
    $m = (int)round($sec / 60);
    if ($m < 60) return t('{n} Min.', ['n' => max(0, $m)]);
    $h = intdiv($m, 60);
    if ($h < 24) return t('{h} Std. {m} Min.', ['h' => $h, 'm' => $m % 60]);
    return t('{d} T. {h} Std.', ['d' => intdiv($h, 24), 'h' => $h % 24]);
}

function ticket_stats_median(array $v): ?float
{
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/**
 * Kennzahlen je Ticket aus dem Verlauf: erste Antwort, erste Lösung, Zeit beim Dienstleister,
 * Erinnerungen/Eskalationen, wer gelöst hat. $tickets: Zeilen aus `tickets`, nach id indiziert.
 */
function ticket_stats_facts(array $tickets): array
{
    $facts = [];
    foreach ($tickets as $id => $tk) {
        $facts[$id] = ['first_response' => null, 'first_responder' => null, 'resolved_at' => null, 'resolved_by' => null,
            'provider_sec' => 0, 'handovers' => [], 'reminded' => false, 'escalated' => false, 'replies' => []];
    }
    if (!$facts) return [];
    $now = time();
    foreach (array_chunk(array_keys($facts), 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $open = [];   // laufende Übergabe je Ticket: [Start, Name]
        $first = [];  // erste Nachricht (Beschreibung) je Ticket schon gesehen
        foreach (rows("SELECT ticket_id, user_id, kind, body, status_from, status_to, created_at FROM ticket_messages
            WHERE ticket_id IN ($in) AND kind IN ('message', 'event') ORDER BY ticket_id, id") as $m) {
            $id = (int)$m['ticket_id'];
            $tk = $tickets[$id];
            $f = &$facts[$id];
            $ts = strtotime($m['created_at']);
            if ($m['kind'] === 'message') {
                if (!isset($first[$id])) { $first[$id] = true; unset($f); continue; }   // Beschreibung
                if ($m['user_id'] !== null && (int)$m['user_id'] !== (int)$tk['created_by']) {
                    if ($f['first_response'] === null) { $f['first_response'] = max(0, $ts - strtotime($tk['created_at'])); $f['first_responder'] = (int)$m['user_id']; }
                    $f['replies'][] = (int)$m['user_id'];
                }
                unset($f);
                continue;
            }
            $body = (string)$m['body'];
            if ($body === 'Erinnerung gesendet') $f['reminded'] = true;
            if (str_starts_with($body, 'Eskaliert an:')) $f['escalated'] = true;
            $to = (string)$m['status_to'];
            $from = (string)$m['status_from'];
            if ($to !== '' && in_array($to, TICKET_CLOSED, true) && $f['resolved_at'] === null) {
                $f['resolved_at'] = $m['created_at'];
                $f['resolved_by'] = $m['user_id'] !== null ? (int)$m['user_id'] : null;
            }
            if ($from === 'beim_dienstleister' && isset($open[$id])) {
                $dur = max(0, $ts - $open[$id][0]);
                $f['provider_sec'] += $dur;
                $f['handovers'][] = ['name' => $open[$id][1], 'sec' => $dur, 'open' => false];
                unset($open[$id]);
            }
            if ($to === 'beim_dienstleister') {
                $name = str_starts_with($body, 'Übergeben an: ') ? mb_substr($body, 14) : '–';
                $open[$id] = [$ts, $name === 'IT-Dienstleister (Portal-Betreiber)' ? t($name) : $name];
            }
            unset($f);
        }
        foreach ($open as $id => [$start, $name]) {
            $dur = max(0, $now - $start);
            $facts[$id]['provider_sec'] += $dur;
            $facts[$id]['handovers'][] = ['name' => $name, 'sec' => $dur, 'open' => true];
        }
    }
    return $facts;
}

/** Säulen „neu“ und „gelöst“ je Tag/Woche/Monat als SVG (Werte stehen auch in der Tabelle darunter) */
function ticket_stats_chart(array $buckets): string
{
    if (!$buckets) return '';
    $max = 1;
    foreach ($buckets as $b) $max = max($max, $b['new'], $b['solved']);
    $step = max(1, (int)ceil($max / 4));
    $top = $step * (int)ceil($max / $step);
    $n = count($buckets);
    $w = 760; $h = 220; $left = 34; $bottom = 26; $plotW = $w - $left - 8; $plotH = $h - $bottom - 10;
    $slot = $plotW / $n;
    $bw = max(2.0, min(18.0, ($slot - 6) / 2));
    $y = fn($v) => 10 + $plotH - $plotH * $v / $top;
    $svg = '<svg class="stats-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e(t('Neue und gelöste Tickets im Zeitraum')) . '">';
    for ($v = 0; $v <= $top; $v += $step) {
        $yy = round($y($v), 1);
        $svg .= '<line class="grid" x1="' . $left . '" x2="' . ($w - 8) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="axis" x="' . ($left - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . $v . '</text>';
    }
    $every = max(1, (int)ceil($n / 12));
    foreach (array_values($buckets) as $i => $b) {
        $cx = $left + $slot * ($i + .5);
        $tip = e($b['label'] . ': ' . t('{n} neu', ['n' => $b['new']]) . ', ' . t('{n} gelöst', ['n' => $b['solved']]));
        $svg .= '<g class="bar-group"><title>' . $tip . '</title><rect class="hit" x="' . round($cx - $slot / 2, 1) . '" y="10" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        foreach ([['new', -1], ['solved', 1]] as [$k, $side]) {
            if (!$b[$k]) continue;
            $x = $side < 0 ? $cx - $bw - 1 : $cx + 1;
            $yy = $y($b[$k]);
            $hh = 10 + $plotH - $yy;
            $r = min(4.0, $bw / 2, $hh);
            // oben abgerundet, unten auf der Grundlinie gerade
            $svg .= '<path class="bar ' . $k . '" d="M' . round($x, 1) . ',' . round(10 + $plotH, 1) . 'V' . round($yy + $r, 1)
                . 'Q' . round($x, 1) . ',' . round($yy, 1) . ' ' . round($x + $r, 1) . ',' . round($yy, 1)
                . 'H' . round($x + $bw - $r, 1) . 'Q' . round($x + $bw, 1) . ',' . round($yy, 1) . ' ' . round($x + $bw, 1) . ',' . round($yy + $r, 1)
                . 'V' . round(10 + $plotH, 1) . 'Z"/>';
        }
        $svg .= '</g>';
        if ($i % $every === 0) $svg .= '<text class="axis" x="' . round($cx, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e($b['short']) . '</text>';
    }
    $svg .= '<line class="base" x1="' . $left . '" x2="' . ($w - 8) . '" y1="' . (10 + $plotH) . '" y2="' . (10 + $plotH) . '"/></svg>';
    return $svg;
}

function page_ticket_stats(): void
{
    $cid = tickets_company_id();
    if (!tickets_stats_allowed($cid)) abort(403, t('Die Auswertung sehen die Administratoren der Firma.'));
    $co = tickets_company($cid);
    $staffStats = (int)($co['ticket_staff_stats'] ?? 0) === 1;
    [$period, $from, $to] = ticket_stats_period();
    $fromTs = $from . ' 00:00:00';
    $toTs = $to . ' 23:59:59';
    $queues = rows('SELECT id, name, active FROM ticket_queues WHERE company_id = ? ORDER BY active DESC, sort, name', [$cid]);
    $queueNames = array_column($queues, 'name', 'id');
    $qf = query_int('queue');
    if ($qf && !isset($queueNames[$qf])) $qf = 0;
    $qsql = $qf ? ' AND queue_id = ' . (int)$qf : '';

    // Tickets im Zeitraum angelegt oder (erstmals) gelöst
    $tickets = [];
    foreach (rows("SELECT * FROM tickets WHERE company_id = ? $qsql AND (created_at BETWEEN ? AND ?
            OR id IN (SELECT ticket_id FROM ticket_messages WHERE company_id = ? AND kind = 'event' AND status_to IN ('geloest', 'geschlossen') AND created_at BETWEEN ? AND ?))",
            [$cid, $fromTs, $toTs, $cid, $fromTs, $toTs]) as $tk) $tickets[(int)$tk['id']] = $tk;
    $facts = ticket_stats_facts($tickets);
    $created = array_filter($tickets, fn($tk) => $tk['created_at'] >= $fromTs && $tk['created_at'] <= $toTs);
    $solvedIds = array_keys(array_filter($facts, fn($f) => $f['resolved_at'] !== null && $f['resolved_at'] >= $fromTs && $f['resolved_at'] <= $toTs));

    // Stand jetzt (unabhängig vom Zeitraum)
    $openNow = rows("SELECT queue_id, status, reminder_level FROM tickets WHERE company_id = ? $qsql AND status NOT IN ('geloest', 'geschlossen')", [$cid]);

    // Gruppen nach Queue / Dringlichkeit / Kategorie
    $agg = function (callable $key) use ($tickets, $facts, $created, $solvedIds, $fromTs, $toTs) {
        $out = [];
        $add = function ($k) use (&$out) { $out[$k] ??= ['new' => 0, 'solved' => 0, 'fr' => [], 'res' => [], 'late' => 0, 'prov' => []]; };
        foreach ($created as $id => $tk) {
            $k = $key($tk); $add($k);
            $f = $facts[$id];
            $out[$k]['new']++;
            if ($f['first_response'] !== null) $out[$k]['fr'][] = $f['first_response'];
            if ($f['resolved_at'] !== null) $out[$k]['res'][] = strtotime($f['resolved_at']) - strtotime($tk['created_at']);
            if ($f['reminded'] || $f['escalated']) $out[$k]['late']++;
            if ($f['provider_sec']) $out[$k]['prov'][] = $f['provider_sec'];
        }
        foreach ($solvedIds as $id) { $k = $key($tickets[$id]); $add($k); $out[$k]['solved']++; }
        return $out;
    };
    $byQueue = $agg(fn($tk) => (int)($tk['queue_id'] ?? 0));
    $byPrio = $agg(fn($tk) => (string)$tk['priority']);
    $byCat = $agg(fn($tk) => (string)$tk['category']);
    $openByQueue = [];
    foreach ($openNow as $o) {
        $k = (int)($o['queue_id'] ?? 0);
        $openByQueue[$k] ??= ['open' => 0, 'provider' => 0, 'late' => 0];
        $openByQueue[$k]['open']++;
        if ($o['status'] === 'beim_dienstleister') $openByQueue[$k]['provider']++;
        if ((int)$o['reminder_level'] > 0) $openByQueue[$k]['late']++;
    }
    $all = $agg(fn($tk) => 'all')['all'] ?? ['new' => 0, 'solved' => 0, 'fr' => [], 'res' => [], 'late' => 0, 'prov' => []];

    // Abteilung des Erstellers (Gruppen = Abteilungen; mehrere möglich)
    $byDep = [];
    if ($created) {
        $uids = array_values(array_unique(array_filter(array_map(fn($tk) => (int)$tk['created_by'], $created))));
        $deps = [];
        if ($uids) foreach (rows('SELECT ud.user_id, d.name FROM user_departments ud JOIN departments d ON d.id = ud.department_id
            WHERE d.company_id = ? AND ud.user_id IN (' . implode(',', $uids) . ')', [$cid]) as $d) $deps[(int)$d['user_id']][] = $d['name'];
        foreach ($created as $tk) {
            foreach ($deps[(int)$tk['created_by']] ?? [t('ohne Abteilung')] as $name) $byDep[$name] = ($byDep[$name] ?? 0) + 1;
        }
        arsort($byDep);
    }

    // Dienstleister: Übergaben im Zeitraum (Tickets, die im Zeitraum angelegt wurden)
    $byProvider = [];
    foreach ($created as $id => $tk) foreach ($facts[$id]['handovers'] as $h) {
        $byProvider[$h['name']] ??= ['count' => 0, 'sec' => [], 'open' => 0];
        $byProvider[$h['name']]['count']++;
        $byProvider[$h['name']]['sec'][] = $h['sec'];
        if ($h['open']) $byProvider[$h['name']]['open']++;
    }
    ksort($byProvider);

    // Zu lange gelegen: Erinnerung oder Eskalation (angelegt im Zeitraum)
    $late = array_filter($created, fn($tk) => $facts[(int)$tk['id']]['reminded'] || $facts[(int)$tk['id']]['escalated']);
    uasort($late, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    // Mitarbeiter-Statistik (nur wenn die Firma sie eingeschaltet hat)
    $byStaff = [];
    if ($staffStats) {
        foreach ($created as $id => $tk) {
            $f = $facts[$id];
            foreach (array_count_values($f['replies']) as $uid => $n) { $byStaff[$uid] ??= ['replies' => 0, 'first' => 0, 'solved' => 0, 'res' => []]; $byStaff[$uid]['replies'] += $n; }
            if ($f['first_responder']) { $byStaff[$f['first_responder']] ??= ['replies' => 0, 'first' => 0, 'solved' => 0, 'res' => []]; $byStaff[$f['first_responder']]['first']++; }
        }
        foreach ($solvedIds as $id) {
            $uid = $facts[$id]['resolved_by'];
            if (!$uid || (int)$uid === (int)$tickets[$id]['created_by']) continue;   // selbst bestätigt zählt nicht
            $byStaff[$uid] ??= ['replies' => 0, 'first' => 0, 'solved' => 0, 'res' => []];
            $byStaff[$uid]['solved']++;
            $byStaff[$uid]['res'][] = strtotime($facts[$id]['resolved_at']) - strtotime($tickets[$id]['created_at']);
        }
        $names = $byStaff ? array_column(rows('SELECT id, name, role FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($byStaff))) . ')'), null, 'id') : [];
        foreach ($byStaff as $uid => &$s) {
            $s['name'] = $names[$uid]['name'] ?? t('Gelöschter Benutzer');
            $s['staff'] = is_staff_role((string)($names[$uid]['role'] ?? ''));
        }
        unset($s);
        uasort($byStaff, fn($a, $b) => [$b['solved'], $b['replies']] <=> [$a['solved'], $a['replies']]);
    }

    // CSV
    if (($_GET['export'] ?? '') === 'csv') {
        audit('ticket.stats_export', $from . ' – ' . $to . ($qf ? ' · ' . $queueNames[$qf] : ''), $cid);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tickets-' . preg_replace('/[^a-z0-9]+/i', '-', (string)$co['name']) . '-' . $from . '-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $head = [t('Ticket'), t('Angelegt'), t('Queue'), t('Kategorie'), t('Dringlichkeit'), t('Status'), t('Erste Antwort (Min.)'), t('Gelöst am'),
            t('Lösungszeit (Min.)'), t('Beim Dienstleister (Min.)'), t('Erinnert'), t('Eskaliert')];
        if ($staffStats) $head[] = t('Bearbeiter');
        fputcsv($out, $head, ';', '"', '');
        $assignees = $staffStats ? array_column(rows('SELECT id, name FROM users WHERE id IN (SELECT assigned_to FROM tickets WHERE company_id = ?)', [$cid]), 'name', 'id') : [];
        foreach ($created as $id => $tk) {
            $f = $facts[$id];
            $line = ['#' . $tk['number'], fmt_dt($tk['created_at']), $queueNames[(int)$tk['queue_id']] ?? '–', $tk['category'], t(TICKET_PRIORITIES[$tk['priority']] ?? $tk['priority']),
                t(TICKET_STATUSES[$tk['status']] ?? $tk['status']), $f['first_response'] !== null ? (string)round($f['first_response'] / 60) : '',
                $f['resolved_at'] ? fmt_dt($f['resolved_at']) : '', $f['resolved_at'] ? (string)round((strtotime($f['resolved_at']) - strtotime($tk['created_at'])) / 60) : '',
                $f['provider_sec'] ? (string)round($f['provider_sec'] / 60) : '', $f['reminded'] ? t('ja') : '', $f['escalated'] ? t('ja') : ''];
            if ($staffStats) $line[] = $assignees[(int)$tk['assigned_to']] ?? '';
            fputcsv($out, array_map('csv_cell_safe', $line), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    // Verlauf: Tage (bis 31 Tage), Wochen (bis ca. 6 Monate), sonst Monate
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $unit = $days <= 31 ? 'day' : ($days <= 190 ? 'week' : 'month');
    $bucketKey = fn(string $ts) => $unit === 'day' ? substr($ts, 0, 10) : ($unit === 'week' ? date('o-W', strtotime($ts)) : substr($ts, 0, 7));
    $buckets = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
        $k = $bucketKey(date('Y-m-d', $d));
        if (isset($buckets[$k])) continue;
        $buckets[$k] = ['new' => 0, 'solved' => 0,
            'label' => $unit === 'day' ? fmt_date(date('Y-m-d', $d)) : ($unit === 'week' ? t('KW {n}', ['n' => (int)date('W', $d)]) . ' ' . date('o', $d) : date('m/Y', $d)),
            'short' => $unit === 'day' ? date('d.m.', $d) : ($unit === 'week' ? t('KW {n}', ['n' => (int)date('W', $d)]) : date('m/y', $d))];
    }
    foreach ($created as $tk) { $k = $bucketKey($tk['created_at']); if (isset($buckets[$k])) $buckets[$k]['new']++; }
    foreach ($solvedIds as $id) { $k = $bucketKey($facts[$id]['resolved_at']); if (isset($buckets[$k])) $buckets[$k]['solved']++; }

    $view = compact('cid', 'co', 'staffStats', 'period', 'from', 'to', 'queues', 'queueNames', 'qf', 'all', 'solvedIds', 'openNow', 'openByQueue',
        'byQueue', 'byPrio', 'byCat', 'byDep', 'byProvider', 'late', 'byStaff', 'buckets', 'unit', 'facts');
    render_page(t('Auswertung') . ' · ' . tickets_app_name(), fn() => ticket_stats_view($view));
}

function ticket_stats_view(array $v): void
{
    extract($v);
    $params = ['period' => $period, 'queue' => $qf ?: null] + ($period === 'custom' ? ['from' => $from, 'to' => $to] : []);
    $params = array_filter($params, fn($x) => $x !== null);
    $cell = fn(array $r, string $k) => ticket_stats_dur(ticket_stats_median($r[$k]));
    $lateNow = count(array_filter($openNow, fn($o) => (int)$o['reminder_level'] > 0));
    echo company_switcher($cid, 'ticket_stats'); ?>
    <p><a href="<?= e(turl('tickets')) ?>">← <?= e(tickets_app_name()) ?></a></p>
    <form method="get" class="toolbar stats-filters">
        <input type="hidden" name="r" value="ticket_stats">
        <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
        <label><?= e(t('Zeitraum')) ?> <select name="period">
            <?php foreach (TICKET_STATS_PERIODS as $k => $l): ?><option value="<?= e($k) ?>" <?= $period === (string)$k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
        </select></label>
        <label><?= e(t('Von')) ?> <input type="date" name="from" value="<?= e($from) ?>"></label>
        <label><?= e(t('Bis')) ?> <input type="date" name="to" value="<?= e($to) ?>"></label>
        <label><?= e(t('Queue')) ?> <select name="queue">
            <option value=""><?= e(t('alle Queues')) ?></option>
            <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>" <?= $qf === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?><?= (int)$q['active'] ? '' : ' (' . e(t('inaktiv')) . ')' ?></option><?php endforeach; ?>
        </select></label>
        <button class="btn"><?= e(t('Anzeigen')) ?></button>
        <a class="btn" href="<?= e(turl('ticket_stats', $params + ['export' => 'csv'])) ?>"><?= e(t('CSV exportieren')) ?></a>
    </form>
    <p class="muted small"><?= e(t('Zeitraum {from} bis {to}. „Jetzt“ zeigt den aktuellen Stand. Zeiten sind Mediane in Kalenderzeit (inklusive Nacht und Wochenende).', ['from' => fmt_date($from), 'to' => fmt_date($to)])) ?></p>

    <div class="tiles stats-tiles">
        <div class="tile"><strong><?= (int)$all['new'] ?></strong><span><?= e(t('neue Tickets')) ?></span></div>
        <div class="tile"><strong><?= count($solvedIds) ?></strong><span><?= e(t('gelöst')) ?></span></div>
        <div class="tile"><strong><?= count($openNow) ?></strong><span><?= e(t('jetzt offen')) ?></span></div>
        <div class="tile"><strong><?= $lateNow ?></strong><span><?= e(t('jetzt zu lange offen')) ?></span></div>
        <div class="tile"><strong><?= e(ticket_stats_dur(ticket_stats_median($all['fr']))) ?></strong><span><?= e(t('bis zur ersten Antwort')) ?></span></div>
        <div class="tile"><strong><?= e(ticket_stats_dur(ticket_stats_median($all['res']))) ?></strong><span><?= e(t('bis zur Lösung')) ?></span></div>
    </div>

    <div class="card">
        <h2><?= e(t('Neue und gelöste Tickets')) ?></h2>
        <p class="legend"><span class="key new"></span> <?= e(t('neu')) ?> <span class="key solved"></span> <?= e(t('gelöst')) ?>
            <span class="muted small">· <?= e($unit === 'day' ? t('je Tag') : ($unit === 'week' ? t('je Woche') : t('je Monat'))) ?></span></p>
        <?= ticket_stats_chart($buckets) ?>
        <details><summary class="small"><?= e(t('Als Tabelle')) ?></summary>
            <table class="table small"><thead><tr><th><?= e(t('Zeitraum')) ?></th><th class="num"><?= e(t('neu')) ?></th><th class="num"><?= e(t('gelöst')) ?></th></tr></thead><tbody>
            <?php foreach ($buckets as $b): ?><tr><td><?= e($b['label']) ?></td><td class="num"><?= (int)$b['new'] ?></td><td class="num"><?= (int)$b['solved'] ?></td></tr><?php endforeach; ?>
            </tbody></table></details>
    </div>

    <div class="card">
        <h2><?= e(t('Je Queue')) ?></h2>
        <table class="table"><thead><tr><th><?= e(t('Queue')) ?></th><th class="num"><?= e(t('neu')) ?></th><th class="num"><?= e(t('gelöst')) ?></th>
            <th class="num"><?= e(t('jetzt offen')) ?></th><th class="num"><?= e(t('davon beim Dienstleister')) ?></th><th class="num"><?= e(t('erste Antwort')) ?></th>
            <th class="num"><?= e(t('Lösung')) ?></th><th class="num"><?= e(t('erinnert/eskaliert')) ?></th></tr></thead><tbody>
        <?php
        $keys = array_unique(array_merge(array_keys($byQueue), array_keys($openByQueue)));
        usort($keys, fn($a, $b) => strcmp($queueNames[$a] ?? '~', $queueNames[$b] ?? '~'));
        if (!$keys): ?><tr><td colspan="8" class="muted"><?= e(t('Keine Tickets im Zeitraum.')) ?></td></tr><?php endif;
        foreach ($keys as $k): $r = $byQueue[$k] ?? ['new' => 0, 'solved' => 0, 'fr' => [], 'res' => [], 'late' => 0]; $o = $openByQueue[$k] ?? ['open' => 0, 'provider' => 0]; ?>
            <tr><td><?= e($queueNames[$k] ?? t('ohne Queue')) ?></td><td class="num"><?= $r['new'] ?></td><td class="num"><?= $r['solved'] ?></td>
                <td class="num"><?= $o['open'] ?></td><td class="num"><?= $o['provider'] ?></td><td class="num"><?= e($cell($r, 'fr')) ?></td>
                <td class="num"><?= e($cell($r, 'res')) ?></td><td class="num"><?= $r['late'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>

    <div class="grid2">
        <div class="card">
            <h2><?= e(t('Nach Dringlichkeit')) ?></h2>
            <table class="table"><thead><tr><th><?= e(t('Dringlichkeit')) ?></th><th class="num"><?= e(t('neu')) ?></th><th class="num"><?= e(t('erste Antwort')) ?></th><th class="num"><?= e(t('Lösung')) ?></th></tr></thead><tbody>
            <?php foreach (array_reverse(TICKET_PRIORITIES, true) as $p => $label): if (!isset($byPrio[$p])) continue; $r = $byPrio[$p]; ?>
                <tr><td><?= e(t($label)) ?></td><td class="num"><?= $r['new'] ?></td><td class="num"><?= e($cell($r, 'fr')) ?></td><td class="num"><?= e($cell($r, 'res')) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byPrio): ?><tr><td colspan="4" class="muted"><?= e(t('Keine Tickets im Zeitraum.')) ?></td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <div class="card">
            <h2><?= e(t('Nach Kategorie')) ?></h2>
            <table class="table"><thead><tr><th><?= e(t('Kategorie')) ?></th><th class="num"><?= e(t('neu')) ?></th><th class="num"><?= e(t('Lösung')) ?></th></tr></thead><tbody>
            <?php uasort($byCat, fn($a, $b) => $b['new'] <=> $a['new']);
            foreach ($byCat as $c => $r): if (!$r['new']) continue; ?>
                <tr><td><?= e($c !== '' ? $c : t('ohne Kategorie')) ?></td><td class="num"><?= $r['new'] ?></td><td class="num"><?= e($cell($r, 'res')) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$all['new']): ?><tr><td colspan="3" class="muted"><?= e(t('Keine Tickets im Zeitraum.')) ?></td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>

    <div class="grid2">
        <div class="card">
            <h2><?= e(t('Je Abteilung (Ersteller)')) ?></h2>
            <table class="table"><thead><tr><th><?= e(t('Abteilung')) ?></th><th class="num"><?= e(t('neue Tickets')) ?></th></tr></thead><tbody>
            <?php foreach ($byDep as $name => $n): ?><tr><td><?= e($name) ?></td><td class="num"><?= (int)$n ?></td></tr><?php endforeach; ?>
            <?php if (!$byDep): ?><tr><td colspan="2" class="muted"><?= e(t('Keine Tickets im Zeitraum.')) ?></td></tr><?php endif; ?>
            </tbody></table>
            <p class="muted small"><?= e(t('Wer mehreren Abteilungen angehört, zählt bei jeder.')) ?></p>
        </div>
        <div class="card">
            <h2><?= e(t('Beim Dienstleister')) ?></h2>
            <table class="table"><thead><tr><th><?= e(t('Dienstleister')) ?></th><th class="num"><?= e(t('Übergaben')) ?></th><th class="num"><?= e(t('Dauer (Median)')) ?></th><th class="num"><?= e(t('noch offen')) ?></th></tr></thead><tbody>
            <?php foreach ($byProvider as $name => $r): ?>
                <tr><td><?= e($name) ?></td><td class="num"><?= $r['count'] ?></td><td class="num"><?= e(ticket_stats_dur(ticket_stats_median($r['sec']))) ?></td><td class="num"><?= $r['open'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byProvider): ?><tr><td colspan="4" class="muted"><?= e(t('Keine Übergaben im Zeitraum.')) ?></td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>

    <div class="card">
        <h2><?= e(t('Zu lange gelegen')) ?></h2>
        <p class="muted small"><?= e(t('Tickets aus dem Zeitraum, bei denen eine Erinnerung oder Eskalation ausgelöst wurde.')) ?></p>
        <table class="table"><thead><tr><th><?= e(t('Ticket')) ?></th><th><?= e(t('Queue')) ?></th><th><?= e(t('Dringlichkeit')) ?></th><th><?= e(t('Angelegt')) ?></th>
            <th><?= e(t('Status')) ?></th><th><?= e(t('Auslöser')) ?></th></tr></thead><tbody>
        <?php foreach (array_slice($late, 0, 100, true) as $id => $tk): $f = $facts[$id]; ?>
            <tr><td><a href="<?= e(turl('ticket', ['id' => $id])) ?>">#<?= e($tk['number']) ?> <?= e($tk['subject']) ?></a></td>
                <td><?= e($queueNames[(int)$tk['queue_id']] ?? '–') ?></td><td><?= e(t(TICKET_PRIORITIES[$tk['priority']] ?? $tk['priority'])) ?></td>
                <td><?= fmt_dt($tk['created_at']) ?></td><td><?= e(t(TICKET_STATUSES[$tk['status']] ?? $tk['status'])) ?></td>
                <td><?= $f['escalated'] ? '<span class="tag danger">' . e(t('eskaliert')) . '</span>' : '<span class="tag warn">' . e(t('erinnert')) . '</span>' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$late): ?><tr><td colspan="6" class="muted"><?= e(t('Keine – alle Tickets wurden rechtzeitig bearbeitet.')) ?></td></tr><?php endif; ?>
        </tbody></table>
        <?php if (count($late) > 100): ?><p class="muted small"><?= e(t('Die ersten 100 von {n}; alle stehen im CSV-Export.', ['n' => count($late)])) ?></p><?php endif; ?>
    </div>

    <div class="card">
        <h2><?= e(t('Mitarbeiter-Statistik')) ?></h2>
        <?php if (!$staffStats): ?>
            <p class="muted"><?= e(t('Die Mitarbeiter-Statistik ist ausgeschaltet. Die Auswertung zeigt deshalb nur Zahlen je Queue, Dringlichkeit, Kategorie und Abteilung.')) ?>
            <?php if (!is_staff_role(current_user()['role'])): ?> <a href="<?= e(turl('ticket_settings')) ?>"><?= e(t('Einschalten unter Queues & Einstellungen')) ?></a><?php endif; ?></p>
        <?php else: ?>
            <table class="table"><thead><tr><th><?= e(t('Bearbeiter')) ?></th><th class="num"><?= e(t('Antworten')) ?></th><th class="num"><?= e(t('erste Antworten')) ?></th>
                <th class="num"><?= e(t('gelöst')) ?></th><th class="num"><?= e(t('Lösungszeit (Median)')) ?></th></tr></thead><tbody>
            <?php foreach ($byStaff as $s): ?>
                <tr><td><?= e($s['name']) ?><?= $s['staff'] ? ' <span class="tag">' . e(t('IT-Service')) . '</span>' : '' ?></td><td class="num"><?= $s['replies'] ?></td>
                    <td class="num"><?= $s['first'] ?></td><td class="num"><?= $s['solved'] ?></td><td class="num"><?= e(ticket_stats_dur(ticket_stats_median($s['res']))) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byStaff): ?><tr><td colspan="5" class="muted"><?= e(t('Keine Bearbeitungen im Zeitraum.')) ?></td></tr><?php endif; ?>
            </tbody></table>
            <p class="muted small"><?= e(t('Antworten und erste Antworten zählen für Tickets, die im Zeitraum angelegt wurden; „gelöst“ für Tickets, die im Zeitraum gelöst wurden. Wer ein Ticket nur als Ersteller bestätigt, zählt nicht.')) ?></p>
        <?php endif; ?>
    </div>
<?php }
