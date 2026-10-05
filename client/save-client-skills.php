<?php
// save-client-skills.php
// Saves the logged-in client's own skill list (client_skills), replacing
// whatever was there before with the new set posted from the "My Skills"
// modal on jobs.php.
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

$skillIds     = $_POST['skill_id'] ?? [];
$proficiencies = $_POST['proficiency_level'] ?? [];
$yearsList    = $_POST['years_experience'] ?? [];

if (!is_array($skillIds)) {
    echo json_encode(['success' => false, 'message' => 'Invalid skills payload.']);
    exit;
}

$validProficiency = ['Beginner', 'Intermediate', 'Advanced', 'Expert'];

$skillsToSave = [];
$seen = [];
foreach ($skillIds as $k => $rawId) {
    $skillId = (int) $rawId;
    if ($skillId <= 0) continue;
    if (isset($seen[$skillId])) continue; // ignore duplicate picks of the same skill
    $seen[$skillId] = true;

    $proficiency = trim((string) ($proficiencies[$k] ?? 'Intermediate'));
    if (!in_array($proficiency, $validProficiency, true)) {
        $proficiency = 'Intermediate';
    }

    $yearsRaw = trim((string) ($yearsList[$k] ?? ''));
    $years = ($yearsRaw === '') ? null : max(0, (int) $yearsRaw);

    $skillsToSave[] = [
        'skill_id' => $skillId,
        'proficiency_level' => $proficiency,
        'years_experience' => $years,
    ];
}

$txStarted = false;
try {
    $database->begin_transaction();
    $txStarted = true;

    // Verify every posted skill_id actually exists in the master skills list
    // before inserting, so a tampered request can't create orphaned rows.
    if ($skillsToSave !== []) {
        $ids = array_column($skillsToSave, 'skill_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $checkStmt = $database->prepare("SELECT id FROM skills WHERE id IN ($placeholders)");
        $checkStmt->bind_param($types, ...$ids);
        $checkStmt->execute();
        $validIds = array_column($checkStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $checkStmt->close();
        $validIds = array_map('intval', $validIds);
        $skillsToSave = array_values(array_filter(
            $skillsToSave,
            fn($s) => in_array($s['skill_id'], $validIds, true)
        ));
    }

    $del = $database->prepare("DELETE FROM client_skills WHERE client_id = ?");
    $del->bind_param('i', $client_id);
    $del->execute();
    $del->close();

    if ($skillsToSave !== []) {
        $ins = $database->prepare("INSERT INTO client_skills (client_id, skill_id, proficiency_level, years_experience) VALUES (?, ?, ?, ?)");
        foreach ($skillsToSave as $s) {
            $ins->bind_param('iisi', $client_id, $s['skill_id'], $s['proficiency_level'], $s['years_experience']);
            if (!$ins->execute()) {
                throw new RuntimeException('Failed to save skill: ' . $ins->error);
            }
        }
        $ins->close();
    }

    $database->commit();
    echo json_encode(['success' => true, 'message' => 'Skills saved successfully.']);
} catch (Throwable $e) {
    if ($txStarted) {
        $database->rollback();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
