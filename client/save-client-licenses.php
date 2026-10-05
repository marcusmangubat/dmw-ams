<?php
// save-client-licenses.php
// Saves the logged-in client's own professional license list (client_licenses),
// replacing whatever was there before with the new set posted from the
// "My Licenses" modal on jobs.php. This is OPTIONAL data — submitting an
// empty list is valid and simply means the applicant holds no license.
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
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

$licenseIds = $_POST['license_id'] ?? [];

if (!is_array($licenseIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid licenses payload.']);
    exit;
}

$licenseIdsToSave = [];
$seen = [];
foreach ($licenseIds as $rawId) {
    $licenseId = (int) $rawId;
    if ($licenseId <= 0) continue;
    if (isset($seen[$licenseId])) continue; // ignore duplicate picks of the same license
    $seen[$licenseId] = true;

    $licenseIdsToSave[] = $licenseId;
}

$txStarted = false;
try {
    $database->begin_transaction();
    $txStarted = true;

    // Verify every posted license_id actually exists in the master
    // professional_licenses list before inserting, so a tampered request
    // can't create orphaned rows.
    if ($licenseIdsToSave !== []) {
        $placeholders = implode(',', array_fill(0, count($licenseIdsToSave), '?'));
        $types = str_repeat('i', count($licenseIdsToSave));
        $checkStmt = $database->prepare("SELECT id FROM professional_licenses WHERE id IN ($placeholders)");
        $checkStmt->bind_param($types, ...$licenseIdsToSave);
        $checkStmt->execute();
        $validIds = array_column($checkStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $checkStmt->close();
        $validIds = array_map('intval', $validIds);
        $licenseIdsToSave = array_values(array_filter(
            $licenseIdsToSave,
            fn($id) => in_array($id, $validIds, true)
        ));
    }

    $del = $database->prepare("DELETE FROM client_licenses WHERE client_id = ?");
    $del->bind_param('i', $client_id);
    $del->execute();
    $del->close();

    // Empty $licenseIdsToSave is a valid, expected state here (applicant has
    // no license) — skip the insert loop entirely rather than treating it
    // as an error, since this field is optional.
    if ($licenseIdsToSave !== []) {
        $ins = $database->prepare("INSERT INTO client_licenses (client_id, license_id) VALUES (?, ?)");
        foreach ($licenseIdsToSave as $licenseId) {
            $ins->bind_param('ii', $client_id, $licenseId);
            if (!$ins->execute()) {
                throw new RuntimeException('Failed to save license: ' . $ins->error);
            }
        }
        $ins->close();
    }

    $database->commit();
    echo json_encode(['success' => true, 'message' => 'Licenses saved successfully.']);
} catch (Throwable $e) {
    if ($txStarted) {
        $database->rollback();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
