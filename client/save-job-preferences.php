<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$client_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    echo json_encode(['success' => false, 'message' => 'Client not found.']);
    exit;
}
$client_id = (int) $client['cid'];

//  Input 
$classification   = trim($_POST['preferred_classification'] ?? '');
$employmentType   = trim($_POST['preferred_employment_type'] ?? '');
$salaryRangeIdRaw = $_POST['salary_range_id'] ?? '';
$countriesInput   = $_POST['countries'] ?? [];
$positionsInput   = $_POST['positions'] ?? [];

//  Validation 
$validClassifications = ['Government', 'Private', 'Any'];
$validEmploymentTypes = ['Full-time', 'Part-time', 'Contract'];

if (!in_array($classification, $validClassifications, true)) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid job classification.']);
    exit;
}
if (!in_array($employmentType, $validEmploymentTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid employment type.']);
    exit;
}
if (!is_array($countriesInput) || count($countriesInput) < 1) {
    echo json_encode(['success' => false, 'message' => 'Please add at least one preferred country.']);
    exit;
}
if (!is_array($positionsInput) || count($positionsInput) < 1) {
    echo json_encode(['success' => false, 'message' => 'Please add at least one preferred position.']);
    exit;
}

$countries = array_slice(array_values(array_filter(array_map('trim', $countriesInput))), 0, 3);
$positions = array_slice(array_values(array_filter(array_map('intval', $positionsInput))), 0, 3);

if (empty($countries) || empty($positions)) {
    echo json_encode(['success' => false, 'message' => 'Please add at least one preferred country and position.']);
    exit;
}

$salaryRangeId = null;
if ($salaryRangeIdRaw !== '' && ctype_digit((string) $salaryRangeIdRaw)) {
    $srStmt = $database->prepare("SELECT id FROM salary_ranges WHERE id = ? LIMIT 1");
    $srStmt->bind_param("i", $salaryRangeIdRaw);
    $srStmt->execute();
    $srRow = $srStmt->get_result()->fetch_assoc();
    $srStmt->close();
    if ($srRow) {
        $salaryRangeId = (int) $srRow['id'];
    }
}

// Verify submitted subcategory_ids actually exist to avoid orphaned rows
if (!empty($positions)) {
    $placeholders = implode(',', array_fill(0, count($positions), '?'));
    $types = str_repeat('i', count($positions));
    $checkStmt = $database->prepare("SELECT id FROM job_subcategories WHERE id IN ($placeholders)");
    $checkStmt->bind_param($types, ...$positions);
    $checkStmt->execute();
    $validIds = array_column($checkStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    $checkStmt->close();
    $positions = array_values(array_intersect($positions, array_map('intval', $validIds)));
}
if (empty($positions)) {
    echo json_encode(['success' => false, 'message' => 'Selected position(s) are invalid. Please try again.']);
    exit;
}

//  Persist 
$database->begin_transaction();
try {
    $stmt = $database->prepare("SELECT id FROM client_preferences WHERE client_id = ? LIMIT 1");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $database->prepare("
            UPDATE client_preferences
            SET preferred_classification = ?, salary_range_id = ?, preferred_employment_type = ?
            WHERE client_id = ?
        ");
        $stmt->bind_param("sisi", $classification, $salaryRangeId, $employmentType, $client_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $database->prepare("
            INSERT INTO client_preferences
                (client_id, preferred_classification, salary_range_id, preferred_employment_type)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isis", $client_id, $classification, $salaryRangeId, $employmentType);
        $stmt->execute();
        $stmt->close();
    }

    // Replace preferred countries
    $del = $database->prepare("DELETE FROM client_preferred_countries WHERE client_id = ?");
    $del->bind_param("i", $client_id);
    $del->execute();
    $del->close();

    $insC = $database->prepare("INSERT INTO client_preferred_countries (client_id, country, priority_order) VALUES (?, ?, ?)");
    foreach ($countries as $i => $country) {
        $priority = $i + 1;
        $insC->bind_param("isi", $client_id, $country, $priority);
        $insC->execute();
    }
    $insC->close();

    // Replace preferred positions
    $del2 = $database->prepare("DELETE FROM client_preferred_positions WHERE client_id = ?");
    $del2->bind_param("i", $client_id);
    $del2->execute();
    $del2->close();

    $insP = $database->prepare("INSERT INTO client_preferred_positions (client_id, subcategory_id, priority_order) VALUES (?, ?, ?)");
    foreach ($positions as $i => $subId) {
        $priority = $i + 1;
        $insP->bind_param("iii", $client_id, $subId, $priority);
        $insP->execute();
    }
    $insP->close();

    $database->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    $database->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong while saving your preferences. Please try again.']);
}
