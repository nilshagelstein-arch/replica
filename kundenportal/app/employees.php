<?php
/**
 * Mitarbeiterliste je Firma (App „On- & Offboarding“).
 * Wird automatisch aus den Aufträgen gepflegt und kann von Hand oder per CSV ergänzt werden.
 */

const EMPLOYEE_STATUSES = [
    'geplant'       => 'Eintritt geplant',
    'aktiv'         => 'Aktiv',
    'stillgelegt'   => 'Stillgelegt',
    'ausgeschieden' => 'Ausgeschieden',
];

const EMPLOYEE_FIELDS = ['salutation', 'first_name', 'last_name', 'employee_no', 'department_id', 'position_id', 'department_name',
    'position_name', 'email', 'phone', 'location', 'manager_name', 'entry_date', 'exit_date', 'status', 'notes'];

function employee_status_badge(string $status): string
{
    $cls = ['geplant' => 'st-eingereicht', 'aktiv' => 'st-freigegeben', 'stillgelegt' => 'st-rueckfrage', 'ausgeschieden' => 'st-storniert'][$status] ?? '';
    return '<span class="badge ' . $cls . '">' . e(EMPLOYEE_STATUSES[$status] ?? $status) . '</span>';
}

function employee_find(int $companyId, string $employeeNo): ?array
{
    return row('SELECT * FROM employees WHERE company_id = ? AND employee_no = ?', [$companyId, $employeeNo]);
}

function load_employee(int $id): array
{
    $e = row('SELECT * FROM employees WHERE id = ?', [$id]);
    if (!$e) abort(404);
    require_company_access((int)$e['company_id']);
    return $e;
}

/** Hält die Mitarbeiterliste nach einer Änderung an einem Auftrag aktuell */
function employee_sync_request(int $requestId): void
{
    $r = row('SELECT * FROM requests WHERE id = ?', [$requestId]);
    if (!$r || trim((string)$r['employee_no']) === '') return;
    $cid = (int)$r['company_id'];
    $e = employee_find($cid, $r['employee_no']);

    if ($r['status'] === 'storniert') {
        // stornierter Eintritt: nur „geplant“-Einträge aus genau diesem Auftrag entfernen
        if ($r['type'] === 'onboarding' && $e && $e['status'] === 'geplant' && (int)$e['last_request_id'] === $requestId) {
            q('DELETE FROM employees WHERE id = ?', [$e['id']]);
        }
        return;
    }

    $base = ['salutation' => $r['salutation'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
        'department_id' => $r['department_id'] ?: null, 'position_id' => $r['position_id'] ?: null,
        'department_name' => $r['department_name'], 'position_name' => $r['position_name']];
    foreach (['location', 'manager_name', 'phone'] as $f) {
        if (trim((string)($r[$f] ?? '')) !== '') $base[$f] = $r[$f];
    }
    if ($r['type'] === 'onboarding' && trim((string)$r['email_wish']) !== '' && (!$e || $e['email'] === '')) $base['email'] = $r['email_wish'];

    if ($r['status'] === 'freigegeben') {
        $map = ['onboarding' => 'aktiv', 'offboarding' => 'ausgeschieden', 'stilllegung' => 'stillgelegt', 'reaktivierung' => 'aktiv'];
        $base['status'] = $map[$r['type']] ?? 'aktiv';
        if ($r['type'] === 'onboarding') { $base['entry_date'] = $r['entry_date']; $base['exit_date'] = ''; }
        if ($r['type'] === 'offboarding') $base['exit_date'] = $r['entry_date'];
        if ($r['type'] === 'reaktivierung') $base['exit_date'] = '';
    } elseif ($r['type'] === 'onboarding') {
        // offener Eintritt: als „geplant“ führen, bestehende aktive Einträge nicht anfassen
        if ($e && $e['status'] !== 'geplant') return;
        $base['status'] = 'geplant';
        $base['entry_date'] = $r['entry_date'];
    } else {
        return; // offene Folgeaufträge ändern den Status erst beim Abschluss
    }

    $base['last_request_id'] = $requestId;
    $base['updated_at'] = now();
    if ($e) {
        update('employees', (int)$e['id'], $base);
    } else {
        insert('employees', $base + ['company_id' => $cid, 'employee_no' => $r['employee_no'], 'email' => $base['email'] ?? '',
            'phone' => $base['phone'] ?? '', 'location' => $base['location'] ?? '', 'manager_name' => $base['manager_name'] ?? '',
            'entry_date' => $base['entry_date'] ?? '', 'exit_date' => $base['exit_date'] ?? '', 'notes' => '', 'created_at' => now()]);
    }
}

/** Einmalig: Liste aus allen bisherigen Aufträgen aufbauen */
function employees_ensure_backfill(): void
{
    if (setting_get('employees_backfilled') === '1') return;
    foreach (array_column(rows("SELECT id FROM requests ORDER BY id"), 'id') as $rid) employee_sync_request((int)$rid);
    setting_set('employees_backfilled', '1');
}

/** Offene Aufträge je Mitarbeiternummer */
function employee_open_requests(int $companyId): array
{
    $out = [];
    $in = "'" . implode("','", OPEN_STATUSES) . "'";
    foreach (rows("SELECT id, type, status, employee_no FROM requests WHERE company_id = ? AND status IN ($in) ORDER BY id", [$companyId]) as $r) {
        $out[$r['employee_no']][] = $r;
    }
    return $out;
}

/** Neuester abgeschlossener Auftrag eines Mitarbeiters (für die Übernahme der Ausstattung) */
function employee_latest_request(array $e): ?array
{
    return row("SELECT * FROM requests WHERE company_id = ? AND employee_no = ? AND status = 'freigegeben' ORDER BY completed_at DESC, id DESC LIMIT 1",
        [$e['company_id'], $e['employee_no']]);
}

/** Welche Folgeaufträge zum Status passen */
function employee_actions(array $e): array
{
    return match ($e['status']) {
        'aktiv'         => ['offboarding', 'stilllegung'],
        'stillgelegt'   => ['reaktivierung', 'offboarding'],
        default         => [],
    };
}
