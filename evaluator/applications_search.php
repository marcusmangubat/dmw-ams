<?php
// evaluator/applications_search.php – JSON endpoint for the live search box
// on applications.php. Same auth/scoping rules as that page; returns only
// data (no HTML) so the front end can render results without a page reload.
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$evaluator_email = $_SESSION['user_email'];

$evaluator_id = null;
$stmt_ev = $database->prepare("SELECT eid FROM evaluators WHERE email = ? LIMIT 1");
$stmt_ev->bind_param("s", $evaluator_email);
$stmt_ev->execute();
$ev_result = $stmt_ev->get_result();
if ($ev_result->num_rows === 1) {
    $evaluator_id = (int) $ev_result->fetch_assoc()['eid'];
}
$stmt_ev->close();

$valid_statuses = ['available', 'assign', 'assigned', 'under_evaluation', 'for_compliance', 'approved', 'disapproved', 'resubmitted'];
$status_filter  = $_GET['status'] ?? '';
$status_filter  = in_array($status_filter, $valid_statuses, true) ? $status_filter : '';

// Search box — matches applicant name, email, position, or employer.
$search_filter = trim($_GET['q'] ?? '');

$applications = [];

if ($status_filter === 'available') {
    $sql = "
        SELECT a.id, a.status, a.updated_at,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email
        FROM applications a
        JOIN rsf_job_orders r ON a.rsf_id = r.id
        LEFT JOIN clients   c ON a.client_id = c.cid
        WHERE a.handling_party IS NULL
          AND a.status IN ('pending','assign','assigned','resubmitted')
    ";
    $params = [];
    $types  = "";

    if ($search_filter !== '') {
        $sql .= " AND (
            CONCAT(c.first_name, ' ', c.last_name) LIKE ?
            OR c.email LIKE ?
            OR r.position_title LIKE ?
            OR r.employer_name LIKE ?
            OR r.rsf_number LIKE ?
            OR r.worksite_country LIKE ?
        )";
        $like = '%' . $search_filter . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= "ssssss";
    }

    $sql .= " ORDER BY a.updated_at ASC";

    $stmt = $database->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($evaluator_id) {
    $sql = "
        SELECT a.id, a.status, a.updated_at, a.handling_party,
               r.rsf_number, r.position_title, r.worksite_country AS country, r.employer_name,
               CONCAT(c.first_name, ' ', c.last_name) AS applicant_name,
               c.email AS applicant_email
        FROM applications a
        JOIN rsf_job_orders  r ON a.rsf_id           = r.id
        LEFT JOIN clients    c ON a.client_id        = c.cid
        WHERE a.current_evaluator_id = ?
    ";
    $params = [$evaluator_id];
    $types  = "i";

    if ($status_filter === 'assign' || $status_filter === 'assigned') {
        $sql .= " AND a.status IN ('assign','assigned')";
    } elseif ($status_filter) {
        $sql .= " AND a.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    if ($search_filter !== '') {
        $sql .= " AND (
            CONCAT(c.first_name, ' ', c.last_name) LIKE ?
            OR c.email LIKE ?
            OR r.position_title LIKE ?
            OR r.employer_name LIKE ?
            OR r.rsf_number LIKE ?
            OR r.worksite_country LIKE ?
        )";
        $like = '%' . $search_filter . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
        $types .= "ssssss";
    }

    $sql .= " ORDER BY a.updated_at DESC";

    $stmt = $database->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Normalize / trim payload to just what the front end needs to render rows.
$out = array_map(function ($app) {
    return [
        'id'              => (int) $app['id'],
        'status'          => strtolower($app['status'] ?? 'unknown'),
        'updated_at'      => $app['updated_at'],
        'rsf_number'      => $app['rsf_number'],
        'position_title'  => $app['position_title'],
        'country'         => $app['country'],
        'employer_name'   => $app['employer_name'],
        'applicant_name'  => $app['applicant_name'],
        'applicant_email' => $app['applicant_email'],
    ];
}, $applications);

echo json_encode([
    'count'        => count($out),
    'applications' => $out,
]);
