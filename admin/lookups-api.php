<?php

/**
 * lookups-api.php – Generic CRUD for admin-managed reference tables:
 *   job_categories, job_subcategories, degree_programs,
 *   professional_licenses, salary_ranges, skills
 *
 * Table/column names are NEVER taken directly from user input for SQL —
 * every request is matched against the $TABLES whitelist below.
 *
 * Actions:
 *   GET  ?action=list&table=X&q=search
 *   GET  ?action=check_delete&table=X&id=Y
 *   POST action=create  { table, data }
 *   POST action=update  { table, id, data }
 *   POST action=delete  { table, id }
 */

session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function respond($arr)
{
    echo json_encode($arr);
    exit;
}

function verify_csrf($input)
{
    if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
        respond(['success' => false, 'message' => 'Invalid session token. Please refresh the page.']);
    }
}

/**
 * Whitelist of manageable tables.
 * - id_col:      primary key column
 * - columns:     editable columns => ['type' => mysqli bind type]
 * - unique_col:  column with a UNIQUE constraint to pre-check duplicates (or null)
 * - search_cols: columns used for the search box
 * - dependents:  FK relationships to check before delete
 *      mode 'block'       -> refuse delete if rows exist (no cascade / RESTRICT in schema)
 *      mode 'cascade_warn'-> delete is allowed (ON DELETE CASCADE), but warn with a count
 */
$TABLES = [
    'job_categories' => [
        'id_col' => 'id',
        'columns' => ['category_name' => 's'],
        'unique_col' => 'category_name',
        'search_cols' => ['category_name'],
        'dependents' => [
            ['table' => 'job_subcategories', 'col' => 'category_id', 'mode' => 'cascade_warn', 'label' => 'subcategory(ies)'],
        ],
    ],
    'job_subcategories' => [
        'id_col' => 'id',
        'columns' => ['category_id' => 'i', 'subcategory_name' => 's'],
        'unique_col' => null,
        'search_cols' => ['subcategory_name'],
        'dependents' => [
            ['table' => 'rsf_job_orders', 'col' => 'subcategory_id', 'mode' => 'block', 'label' => 'active job order(s)'],
            ['table' => 'client_preferred_positions', 'col' => 'subcategory_id', 'mode' => 'cascade_warn', 'label' => 'client preference(s)'],
        ],
    ],
    'degree_programs' => [
        'id_col' => 'id',
        'columns' => ['degree_name' => 's', 'degree_category' => 's'],
        'unique_col' => 'degree_name',
        'search_cols' => ['degree_name', 'degree_category'],
        'dependents' => [
            ['table' => 'client_profiles', 'col' => 'degree_id', 'mode' => 'block', 'label' => 'client profile(s)'],
        ],
    ],
    'professional_licenses' => [
        'id_col' => 'id',
        'columns' => ['license_name' => 's', 'issuing_body' => 's'],
        'unique_col' => 'license_name',
        'search_cols' => ['license_name', 'issuing_body'],
        'dependents' => [
            ['table' => 'client_licenses', 'col' => 'license_id', 'mode' => 'cascade_warn', 'label' => 'client license record(s)'],
        ],
    ],
    'salary_ranges' => [
        'id_col' => 'id',
        'columns' => ['range_label' => 's', 'min_salary' => 'd', 'max_salary' => 'd', 'currency' => 's', 'sort_order' => 'i'],
        'unique_col' => 'range_label',
        'search_cols' => ['range_label'],
        'dependents' => [
            ['table' => 'client_preferences', 'col' => 'salary_range_id', 'mode' => 'block', 'label' => 'client preference(s)'],
        ],
    ],
    'skills' => [
        'id_col' => 'id',
        'columns' => ['skill_name' => 's', 'skill_category' => 's'],
        'unique_col' => 'skill_name',
        'search_cols' => ['skill_name', 'skill_category'],
        'dependents' => [
            ['table' => 'client_skills', 'col' => 'skill_id', 'mode' => 'cascade_warn', 'label' => 'client skill record(s)'],
            ['table' => 'rsf_required_skills', 'col' => 'skill_id', 'mode' => 'cascade_warn', 'label' => 'job order requirement(s)'],
        ],
    ],
];

function get_table_config($TABLES, $table)
{
    if (!isset($TABLES[$table])) {
        respond(['success' => false, 'message' => 'Invalid table']);
    }
    return $TABLES[$table];
}

$method = $_SERVER['REQUEST_METHOD'];

/* ───────────────────────── GET actions ───────────────────────── */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $table  = $_GET['table'] ?? '';
    $cfg    = get_table_config($TABLES, $table);

    if ($action === 'list') {
        $q = trim($_GET['q'] ?? '');
        $idCol = $cfg['id_col'];
        $cols = array_keys($cfg['columns']);

        if ($table === 'job_subcategories') {
            // join category name for display
            $sql = "SELECT js.*, jc.category_name FROM job_subcategories js
                    LEFT JOIN job_categories jc ON jc.id = js.category_id";
            if ($q !== '') {
                $sql .= " WHERE js.subcategory_name LIKE ? OR jc.category_name LIKE ?";
                $like = "%$q%";
                $stmt = $database->prepare($sql . " ORDER BY js.id DESC");
                $stmt->bind_param('ss', $like, $like);
            } else {
                $stmt = $database->prepare($sql . " ORDER BY js.id DESC");
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            respond(['success' => true, 'data' => $rows]);
        }

        $sql = "SELECT * FROM `$table`";
        $params = [];
        $types = '';
        if ($q !== '' && !empty($cfg['search_cols'])) {
            $likeParts = [];
            foreach ($cfg['search_cols'] as $sc) {
                $likeParts[] = "`$sc` LIKE ?";
                $params[] = "%$q%";
                $types .= 's';
            }
            $sql .= " WHERE " . implode(' OR ', $likeParts);
        }
        $sql .= " ORDER BY `$idCol` DESC";

        $stmt = $database->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // attach dependent counts for cascade-warn relations (used for list display, e.g. subcategory count)
        foreach ($cfg['dependents'] as $dep) {
            if ($dep['mode'] !== 'cascade_warn') continue;
            foreach ($rows as &$row) {
                $cstmt = $database->prepare("SELECT COUNT(*) AS c FROM `{$dep['table']}` WHERE `{$dep['col']}` = ?");
                $cstmt->bind_param('i', $row[$idCol]);
                $cstmt->execute();
                $row['dependent_count'] = (int) $cstmt->get_result()->fetch_assoc()['c'];
                $cstmt->close();
            }
            unset($row);
            break; // only need the first cascade dependent for the list "count" column
        }

        respond(['success' => true, 'data' => $rows]);
    }

    if ($action === 'check_delete') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) respond(['success' => false, 'message' => 'Missing id']);

        foreach ($cfg['dependents'] as $dep) {
            $cstmt = $database->prepare("SELECT COUNT(*) AS c FROM `{$dep['table']}` WHERE `{$dep['col']}` = ?");
            $cstmt->bind_param('i', $id);
            $cstmt->execute();
            $count = (int) $cstmt->get_result()->fetch_assoc()['c'];
            $cstmt->close();

            if ($count > 0 && $dep['mode'] === 'block') {
                respond([
                    'success' => true,
                    'blocked' => true,
                    'message' => "Cannot delete — used by {$count} {$dep['label']}."
                ]);
            }
            if ($count > 0 && $dep['mode'] === 'cascade_warn') {
                respond([
                    'success' => true,
                    'blocked' => false,
                    'warning' => "This will also remove {$count} related {$dep['label']}. This cannot be undone."
                ]);
            }
        }

        respond(['success' => true, 'blocked' => false, 'warning' => null]);
    }

    respond(['success' => false, 'message' => 'Unknown action']);
}

/* ───────────────────────── POST actions ──────────────────────── */
$input = json_decode(file_get_contents('php://input'), true) ?? [];
verify_csrf($input);

$action = $input['action'] ?? '';
$table  = $input['table'] ?? '';
$cfg    = get_table_config($TABLES, $table);
$idCol  = $cfg['id_col'];

if ($action === 'create' || $action === 'update') {
    $data = $input['data'] ?? [];
    $setParts = [];
    $types = '';
    $values = [];

    foreach ($cfg['columns'] as $col => $type) {
        if (!array_key_exists($col, $data)) {
            respond(['success' => false, 'message' => "Missing field: $col"]);
        }
        $val = $data[$col];

        if ($type === 'i') $val = ($val === '' ? null : (int) $val);
        if ($type === 'd') $val = ($val === '' ? null : (float) $val);
        if ($type === 's') $val = trim((string) $val);

        $setParts[] = "`$col` = ?";
        $types .= $type;
        $values[] = $val;
    }

    // uniqueness pre-check
    if ($cfg['unique_col']) {
        $uCol = $cfg['unique_col'];
        $uVal = $data[$uCol] ?? '';
        $sql = "SELECT `$idCol` FROM `$table` WHERE `$uCol` = ?";
        $params = [$uVal];
        $ptypes = 's';
        if ($action === 'update') {
            $sql .= " AND `$idCol` != ?";
            $params[] = (int) ($input['id'] ?? 0);
            $ptypes .= 'i';
        }
        $stmt = $database->prepare($sql);
        $stmt->bind_param($ptypes, ...$params);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $stmt->close();
            respond(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $uCol)) . ' already exists']);
        }
        $stmt->close();
    }

    // salary_ranges: sanity check max > min
    if ($table === 'salary_ranges') {
        $min = (float) ($data['min_salary'] ?? 0);
        $max = (float) ($data['max_salary'] ?? 0);
        if ($max <= $min) {
            respond(['success' => false, 'message' => 'Max salary must be greater than min salary']);
        }
    }

    if ($action === 'create') {
        $sql = "INSERT INTO `$table` (" . implode(',', array_map(fn($c) => "`$c`", array_keys($cfg['columns']))) . ")
                VALUES (" . implode(',', array_fill(0, count($cfg['columns']), '?')) . ")";
        $stmt = $database->prepare($sql);
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            respond(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
        }
        $newId = $stmt->insert_id;
        $stmt->close();
        respond(['success' => true, 'id' => $newId]);
    } else {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) respond(['success' => false, 'message' => 'Missing id']);

        $sql = "UPDATE `$table` SET " . implode(', ', $setParts) . " WHERE `$idCol` = ?";
        $types .= 'i';
        $values[] = $id;

        $stmt = $database->prepare($sql);
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            respond(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
        }
        $stmt->close();
        respond(['success' => true]);
    }
}

if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) respond(['success' => false, 'message' => 'Missing id']);

    // Re-verify block-mode dependents server-side (never trust the client check alone)
    foreach ($cfg['dependents'] as $dep) {
        if ($dep['mode'] !== 'block') continue;
        $cstmt = $database->prepare("SELECT COUNT(*) AS c FROM `{$dep['table']}` WHERE `{$dep['col']}` = ?");
        $cstmt->bind_param('i', $id);
        $cstmt->execute();
        $count = (int) $cstmt->get_result()->fetch_assoc()['c'];
        $cstmt->close();
        if ($count > 0) {
            respond(['success' => false, 'message' => "Cannot delete — used by {$count} {$dep['label']}."]);
        }
    }

    $stmt = $database->prepare("DELETE FROM `$table` WHERE `$idCol` = ?");
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        respond(['success' => false, 'message' => 'Delete failed: ' . $stmt->error]);
    }
    $stmt->close();
    respond(['success' => true]);
}

respond(['success' => false, 'message' => 'Unknown action']);
