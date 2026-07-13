<?php
session_start();
require_once '../db.php';
require_once '../auth.php';

header('Content-Type: application/json');

// Must be a logged-in client
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$client_email = $_SESSION['user_email'];

// Fetch client id
$stmt = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Client record not found.']);
    exit;
}

$client_id = (int) $row['cid'];

// ── Sanitize & validate inputs ──────────────────────────────────────────────

function trim_input($val)
{
    return isset($val) ? trim($val) : null;
}

$citizenship             = trim_input($_POST['citizenship'] ?? null);
$civil_status            = trim_input($_POST['civil_status'] ?? null);
$religion                = trim_input($_POST['religion'] ?? null);
$height_cm               = trim_input($_POST['height_cm'] ?? null);
$weight_kg               = trim_input($_POST['weight_kg'] ?? null);
$educational_attainment  = trim_input($_POST['educational_attainment'] ?? null);
$degree_id               = trim_input($_POST['degree_id'] ?? null);
$course_degree           = trim_input($_POST['course_degree'] ?? null);
$school_name             = trim_input($_POST['school_name'] ?? null);
$school_address          = trim_input($_POST['school_address'] ?? null);
$year_graduated          = trim_input($_POST['year_graduated'] ?? null);
$work_experience_years   = trim_input($_POST['work_experience_years'] ?? null);
$last_employer           = trim_input($_POST['last_employer'] ?? null);
$last_position           = trim_input($_POST['last_position'] ?? null);

// Required fields
$required = [
    'citizenship'            => $citizenship,
    'civil_status'           => $civil_status,
    'height_cm'              => $height_cm,
    'weight_kg'              => $weight_kg,
    'educational_attainment' => $educational_attainment,
];

foreach ($required as $field => $val) {
    if ($val === null || $val === '') {
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

// Validate enums
$valid_civil = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
$valid_edu   = [
    'Elementary Graduate',
    'High School Graduate',
    'Senior High School Graduate',
    'Vocational/Technical',
    'College Undergraduate',
    'College Graduate',
    'Post Graduate'
];

if (!in_array($civil_status, $valid_civil, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid civil status.']);
    exit;
}
if (!in_array($educational_attainment, $valid_edu, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid educational attainment.']);
    exit;
}

// Validate degree_id, if provided, against degree_programs
if ($degree_id !== null && $degree_id !== '') {
    if (!ctype_digit((string) $degree_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid course/degree selection.']);
        exit;
    }
    $degree_id = (int) $degree_id;
    $dgCheck = $database->prepare("SELECT id FROM degree_programs WHERE id = ? LIMIT 1");
    $dgCheck->bind_param("i", $degree_id);
    $dgCheck->execute();
    $dgFound = $dgCheck->get_result()->fetch_assoc();
    $dgCheck->close();
    if (!$dgFound) {
        echo json_encode(['success' => false, 'message' => 'Invalid course/degree selection.']);
        exit;
    }
} else {
    $degree_id = null;
}

// Numeric checks
$height_cm = (float) $height_cm;
$weight_kg = (float) $weight_kg;

if ($height_cm <= 0 || $height_cm > 300) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid height.']);
    exit;
}
if ($weight_kg <= 0 || $weight_kg > 500) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid weight.']);
    exit;
}

$work_experience_years = ($work_experience_years !== null && $work_experience_years !== '') ? (int) $work_experience_years : null;
$year_graduated        = ($year_graduated !== null && $year_graduated !== '') ? (int) $year_graduated : null;

// Nullify empty strings
$course_degree  = ($course_degree  !== '') ? $course_degree  : null;
$school_name    = ($school_name    !== '') ? $school_name    : null;
$school_address = ($school_address !== '') ? $school_address : null;
$last_employer  = ($last_employer  !== '') ? $last_employer  : null;
$last_position  = ($last_position  !== '') ? $last_position  : null;
$religion       = ($religion       !== '') ? $religion       : null;

$now = date('Y-m-d H:i:s');

// ── Upsert client_profiles ───────────────────────────────────────────────────

// Check if profile row already exists
$check = $database->prepare("SELECT id FROM client_profiles WHERE client_id = ? LIMIT 1");
$check->bind_param("i", $client_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

try {
    if ($existing) {
        // UPDATE
        $stmt = $database->prepare("
            UPDATE client_profiles SET
                citizenship             = ?,
                civil_status            = ?,
                religion                = ?,
                height_cm               = ?,
                weight_kg               = ?,
                educational_attainment  = ?,
                degree_id               = ?,
                course_degree           = ?,
                school_name             = ?,
                school_address          = ?,
                year_graduated          = ?,
                work_experience_years   = ?,
                last_employer           = ?,
                last_position           = ?,
                profile_completed_at    = ?
            WHERE client_id = ?
        ");
        $stmt->bind_param(
            "sssddsisssiisssi",
            $citizenship,
            $civil_status,
            $religion,
            $height_cm,
            $weight_kg,
            $educational_attainment,
            $degree_id,
            $course_degree,
            $school_name,
            $school_address,
            $year_graduated,
            $work_experience_years,
            $last_employer,
            $last_position,
            $now,
            $client_id
        );
        $stmt->execute();
        $stmt->close();
    } else {
        // INSERT
        $stmt = $database->prepare("
            INSERT INTO client_profiles
                (client_id, citizenship, civil_status, religion, height_cm, weight_kg,
                 educational_attainment, degree_id, course_degree, school_name, school_address,
                 year_graduated, work_experience_years, last_employer, last_position,
                 profile_completed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isssddsisssiisss",
            $client_id,
            $citizenship,
            $civil_status,
            $religion,
            $height_cm,
            $weight_kg,
            $educational_attainment,
            $degree_id,
            $course_degree,
            $school_name,
            $school_address,
            $year_graduated,
            $work_experience_years,
            $last_employer,
            $last_position,
            $now
        );
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true, 'message' => 'Profile saved successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
