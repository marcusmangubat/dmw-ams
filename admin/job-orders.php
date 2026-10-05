<?php
//job-orders.php
session_start();
require_once '../db.php';
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$status_filter = $_GET['status'] ?? '';

/**
 * @return array<int, array{document_type:string,document_name:string,is_required:int,description:string}>
 */
function parsePostedRequirements(): array
{
    $types = $_POST['req_document_type'] ?? [];
    $names = $_POST['req_document_name'] ?? [];
    $descs = $_POST['req_description'] ?? [];
    $flags = $_POST['req_is_required'] ?? [];

    if (!is_array($types) || !is_array($names)) {
        return [];
    }

    $requirements = [];
    $seenTypes = [];
    foreach ($types as $k => $rawTypeVal) {
        $rawType = trim((string) $rawTypeVal);
        $name = trim((string) ($names[$k] ?? ''));
        $description = trim((string) ($descs[$k] ?? ''));
        $isRequired = isset($flags[$k]) ? 1 : 0;

        if ($rawType === '' && $name === '' && $description === '') {
            continue;
        }

        $type = strtolower(preg_replace('/[^a-z0-9_]/', '_', $rawType));
        $type = trim((string) preg_replace('/_+/', '_', $type), '_');
        if ($type === '' || $name === '') {
            throw new RuntimeException('Each requirement needs a document type and document name.');
        }
        if (isset($seenTypes[$type])) {
            throw new RuntimeException('Duplicate requirement type: ' . $type . '. Each type must be unique per job order.');
        }
        $seenTypes[$type] = true;

        $requirements[] = [
            'document_type' => $type,
            'document_name' => $name,
            'is_required' => $isRequired,
            'description' => $description,
        ];
    }

    return $requirements;
}

/**
 * @return array<int, array{question_text:string,is_required:int,order_no:int|null}>
 */
function parsePostedQuestions(): array
{
    $texts = $_POST['job_question_text'] ?? [];
    $flags = $_POST['job_question_required'] ?? [];
    $orders = $_POST['job_question_order'] ?? [];

    if (!is_array($texts)) {
        return [];
    }

    $questions = [];
    foreach ($texts as $k => $rawText) {
        $text = trim((string) $rawText);
        $isRequired = isset($flags[$k]) ? 1 : 0;
        $orderRaw = trim((string) ($orders[$k] ?? ''));
        $orderNo = ($orderRaw === '') ? null : (int) $orderRaw;

        if ($text === '') {
            continue;
        }
        $questions[] = [
            'question_text' => $text,
            'is_required' => $isRequired,
            'order_no' => $orderNo,
        ];
    }

    return $questions;
}

const QUAL_TYPES = [
    'min_age',
    'max_age',
    'gender',
    'min_height_cm',
    'max_height_cm',
    'min_weight_kg',
    'max_weight_kg',
    'min_education',
    'civil_status',
    'required_degree',
    'required_license'
];

function parsePostedQualifications(): array
{
    $types  = $_POST['qual_type']  ?? [];
    $values = $_POST['qual_value'] ?? [];

    if (!is_array($types) || !is_array($values)) return [];

    $qualifications = [];
    $seen = [];
    foreach ($types as $k => $rawType) {
        $type  = trim((string) $rawType);
        $value = trim((string) ($values[$k] ?? ''));

        if ($type === '' || $value === '') continue;
        if (!in_array($type, QUAL_TYPES, true))
            throw new RuntimeException("Invalid qualification type: $type");

        // required_degree and required_license can both be added multiple times
        // per job order (e.g. accept "BS Agriculture" OR "BS Agricultural Engineering",
        // or "PRC Nurse License" OR "PRC Midwife License"), so both are exempt
        // from the one-per-type rule enforced for everything else.
        if ($type !== 'required_degree' && $type !== 'required_license') {
            if (isset($seen[$type]))
                throw new RuntimeException("Duplicate qualification type: $type");
            $seen[$type] = true;
        } else {
            $multiKey = $type . '|' . strtolower($value);
            if (isset($seen[$multiKey])) continue; // skip exact duplicate
            $seen[$multiKey] = true;
        }

        $qualifications[] = ['qualification_type' => $type, 'qualification_value' => $value];
    }

    return $qualifications;
}

/**
 * @return array<int, array{skill_id:int, importance:string, min_proficiency:string}>
 */
function parsePostedSkills(): array
{
    $skillIds = $_POST['skill_id'] ?? [];
    $importances = $_POST['skill_importance'] ?? [];
    $minProfs = $_POST['skill_min_proficiency'] ?? [];

    if (!is_array($skillIds)) return [];

    $validImportance = ['Required', 'Preferred'];
    $validProficiency = ['Beginner', 'Intermediate', 'Advanced', 'Expert'];

    $skills = [];
    $seen = [];
    foreach ($skillIds as $k => $rawId) {
        $skillId = (int) $rawId;
        if ($skillId <= 0) continue;
        if (isset($seen[$skillId])) continue; // skip picking the same skill twice
        $seen[$skillId] = true;

        $importance = trim((string) ($importances[$k] ?? 'Required'));
        if (!in_array($importance, $validImportance, true)) $importance = 'Required';

        $minProficiency = trim((string) ($minProfs[$k] ?? 'Beginner'));
        if (!in_array($minProficiency, $validProficiency, true)) $minProficiency = 'Beginner';

        $skills[] = [
            'skill_id' => $skillId,
            'importance' => $importance,
            'min_proficiency' => $minProficiency,
        ];
    }

    return $skills;
}

/**
 * Look up the cached PHP-equivalent value for one unit of $currencyCode
 * from the exchange_rates table (refreshed daily by the cron job).
 * Returns null if the currency isn't cached yet (e.g. rate cron hasn't
 * run since this currency was first used) so callers can leave the
 * salary_*_php columns null instead of silently storing a wrong 0.
 */
function getExchangeRate(mysqli $database, string $currencyCode): ?float
{
    $currencyCode = strtoupper(trim($currencyCode));

    if ($currencyCode === 'PHP') {
        return 1.0;
    }

    $stmt = $database->prepare("SELECT exchange_rate FROM exchange_rates WHERE currency_code = ?");
    $stmt->bind_param('s', $currencyCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (float)$row['exchange_rate'] : null;
}
/**
 * Convert a salary amount in $currencyCode to its PHP equivalent using
 * the cached daily rate. Returns null if either the amount or the rate
 * is unavailable, so the caller can store NULL rather than 0.
 */
function convertToPhp(mysqli $database, ?float $amount, string $currencyCode): ?float
{
    if ($amount === null || $amount < 0) {
        return null;
    }

    $rate = getExchangeRate($database, $currencyCode);

    if ($rate === null) {
        // Optional: Auto refresh if rate is missing
        // updateExchangeRates($database);
        return null;
    }

    return round($amount * $rate, 2);
}

/**
 * Fetch latest exchange rates from API and update database
 */
function updateExchangeRates(mysqli $db): bool
{
    $apiUrl = "https://open.er-api.com/v6/latest/PHP";

    $json = @file_get_contents($apiUrl);
    if ($json === false) {
        error_log("Failed to fetch exchange rates");
        return false;
    }

    $data = json_decode($json, true);
    if (($data['result'] ?? '') !== 'success') {
        error_log("Exchange API error");
        return false;
    }

    $rates = $data['rates'] ?? [];
    if (empty($rates)) return false;

    $db->begin_transaction();
    try {
        $db->query("TRUNCATE TABLE exchange_rates");

        $stmt = $db->prepare("INSERT INTO exchange_rates (currency_code, exchange_rate) VALUES (?, ?)");

        foreach ($rates as $currency => $rateToPhp) {
            $exchangeRate = ($currency === 'PHP') ? 1.0 : round(1 / $rateToPhp, 8);
            $stmt->bind_param('sd', $currency, $exchangeRate);
            $stmt->execute();
        }

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollback();
        error_log("Exchange rate update failed: " . $e->getMessage());
        return false;
    }
}

//Re-calculate PHP salaries for ALL existing job orders
function updateAllJobOrderPhpSalaries(mysqli $db): bool
{
    $result = $db->query("SELECT id, salary_min, salary_max, salary_currency 
                          FROM rsf_job_orders 
                          WHERE salary_currency IS NOT NULL");

    if (!$result) return false;

    $success = true;
    $db->begin_transaction();

    try {
        while ($row = $result->fetch_assoc()) {
            $min_php = convertToPhp($db, (float)$row['salary_min'], $row['salary_currency']);
            $max_php = convertToPhp($db, (float)$row['salary_max'], $row['salary_currency']);

            $stmt = $db->prepare("UPDATE rsf_job_orders 
                                  SET salary_min_php = ?, salary_max_php = ? 
                                  WHERE id = ?");
            $stmt->bind_param('ddi', $min_php, $max_php, $row['id']);
            if (!$stmt->execute()) {
                $success = false;
            }
        }

        $db->commit();
        return $success;
    } catch (Exception $e) {
        $db->rollback();
        error_log("Failed to update job order PHP salaries: " . $e->getMessage());
        return false;
    }
}

function generateNextRsfNumber(mysqli $database): string
{
    $yy   = date('y');   // "26"
    $yyyy = date('Y');   // "2026"

    $likePattern = "DMW-RSF-{$yy}%-{$yyyy}";
    $stmt = $database->prepare("SELECT rsf_number FROM rsf_job_orders WHERE rsf_number LIKE ?");
    $stmt->bind_param('s', $likePattern);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $maxSeq = 0;
    $regex = '/^DMW-RSF-' . preg_quote($yy, '/') . '(\d{4})-' . preg_quote($yyyy, '/') . '$/';
    foreach ($rows as $row) {
        if (preg_match($regex, (string) $row['rsf_number'], $m)) {
            $seq = (int) $m[1];
            if ($seq > $maxSeq) {
                $maxSeq = $seq;
            }
        }
    }

    $nextSeq = $maxSeq + 1;
    return sprintf('DMW-RSF-%s%04d-%s', $yy, $nextSeq, $yyyy);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    // ── NEW: Get applicants for a specific RSF job order ──────────────────────
    if ($_POST['action'] === 'get_applicants') {
        try {
            $rsf_id = (int)($_POST['rsf_id'] ?? 0);
            if ($rsf_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid job order ID.']);
                exit;
            }

            // Fetch all applicants for this job order, with client info, status,
            // and the assigned evaluator (if any)
            $sql = "
                    SELECT
                        a.id                AS application_id,
                        a.status,
                        a.submitted_at,
                        a.notes,
                        c.cid,
                        CONCAT(c.first_name, ' ',
                            COALESCE(CONCAT(c.middle_name, ' '), ''),
                            c.last_name,
                            COALESCE(CONCAT(' ', c.suffix), '')) AS client_name,
                        c.email             AS client_email,
                        c.phone             AS client_phone,
                        e.eid               AS evaluator_id,
                        e.full_name         AS evaluator_name,
                        e.email             AS evaluator_email
                    FROM applications a
                    INNER JOIN clients c ON c.cid = a.client_id
                    LEFT JOIN evaluators e ON e.eid = a.current_evaluator_id
                    WHERE a.rsf_id = ?
                    ORDER BY a.submitted_at DESC
                ";
            $stmt = $database->prepare($sql);
            $stmt->bind_param('i', $rsf_id);
            $stmt->execute();
            $applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Also fetch all active evaluators for the assign dropdown
            $eval_stmt = $database->query("SELECT eid, full_name, email FROM evaluators ORDER BY full_name ASC");
            $evaluators = $eval_stmt->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'status'     => 'success',
                'applicants' => $applicants,
                'evaluators' => $evaluators,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── NEW: Preview next auto-generated RSF number ───────────────────────────
    if ($_POST['action'] === 'peek_rsf_number') {
        try {
            echo json_encode([
                'status'     => 'success',
                'rsf_number' => generateNextRsfNumber($database),
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Refresh exchange rates from API + update all existing salaries
    if ($_POST['action'] === 'refresh_rates') {
        try {
            $rateSuccess = updateExchangeRates($database);
            $salarySuccess = updateAllJobOrderPhpSalaries($database);

            echo json_encode([
                'status' => ($rateSuccess && $salarySuccess) ? 'success' : 'partial',
                'message' => 'Exchange rates updated. ' .
                    ($salarySuccess ? 'All job order PHP salaries have been recalculated.' : 'Some salaries could not be updated.')
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── NEW: Assign evaluator to an application ───────────────────────────────
    if ($_POST['action'] === 'assign_evaluator') {
        try {
            $application_id = (int)($_POST['application_id'] ?? 0);
            $evaluator_id   = (int)($_POST['evaluator_id']   ?? 0);

            if ($application_id <= 0 || $evaluator_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid application or evaluator ID.']);
                exit;
            }

            // Deactivate any existing active assignment for this application
            $deact = $database->prepare("UPDATE assignments SET status = 'inactive' WHERE application_id = ? AND status = 'active'");
            $deact->bind_param('i', $application_id);
            $deact->execute();

            // Insert new assignment
            $ins = $database->prepare("INSERT INTO assignments (application_id, evaluator_id, status) VALUES (?, ?, 'active')");
            $ins->bind_param('ii', $application_id, $evaluator_id);
            $ins->execute();

            // Update application status to 'assigned'
            $upd = $database->prepare("UPDATE applications SET status = 'assigned', updated_at = NOW() WHERE id = ?");
            $upd->bind_param('i', $application_id);
            $upd->execute();

            // Lock the case to this evaluator (single-owner model — this is
            // what evaluator/view-application.php checks before letting
            // anyone claim or open the case).
            $lock = $database->prepare("
                UPDATE applications
                SET current_evaluator_id = ?, handling_party = 'evaluator', locked_at = NOW()
                WHERE id = ?
            ");
            $lock->bind_param('ii', $evaluator_id, $application_id);
            $lock->execute();

            // Log status history
            $changed_by = $_SESSION['email'] ?? 'admin';
            $hist = $database->prepare("INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason) VALUES (?, 'unassign', 'assigned', ?, 'Evaluator assigned by admin')");
            $hist->bind_param('is', $application_id, $changed_by);
            $hist->execute();

            // Return the evaluator name so JS can update the UI without a full reload
            $ev = $database->prepare("SELECT full_name, email FROM evaluators WHERE eid = ?");
            $ev->bind_param('i', $evaluator_id);
            $ev->execute();
            $evrow = $ev->get_result()->fetch_assoc();

            echo json_encode([
                'status'         => 'success',
                'message'        => 'Evaluator assigned successfully.',
                'evaluator_name' => $evrow['full_name'] ?? '',
                'evaluator_email' => $evrow['email'] ?? '',
            ]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ── EXISTING: Add ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'add') {
        $txStarted = false;
        try {
            $rsf_number      = generateNextRsfNumber($database);
            $employer_name   = trim($_POST['employer_name'] ?? '');
            $worksite_country = trim($_POST['worksite_country'] ?? '');
            $classification  = trim($_POST['classification'] ?? '');
            $position_title  = trim($_POST['position_title'] ?? '');
            $subcategory_id  = !empty($_POST['subcategory_id']) ? (int) $_POST['subcategory_id'] : null;
            $slots_available = (int)($_POST['slots_available'] ?? 0);
            $qualifications  = trim($_POST['qualifications'] ?? '');
            $deadline        = !empty($_POST['deadline']) ? trim($_POST['deadline']) : null;
            $pdf_path = null;
            $requirements = parsePostedRequirements();
            $questions = parsePostedQuestions();
            $qualifications_structured = parsePostedQualifications();
            $skills_structured = parsePostedSkills();

            // ── Salary (validated) ──
            $salary_min      = ($_POST['salary_min'] ?? '') !== '' ? (float) $_POST['salary_min'] : null;
            $salary_max      = ($_POST['salary_max'] ?? '') !== '' ? (float) $_POST['salary_max'] : null;
            $salary_currency = strtoupper(trim($_POST['salary_currency'] ?? '')) ?: 'PHP';

            if ($salary_min !== null && $salary_min < 0) {
                echo json_encode(['status' => 'error', 'message' => 'Salary Min cannot be negative.']);
                exit;
            }
            if ($salary_max !== null && $salary_max < 0) {
                echo json_encode(['status' => 'error', 'message' => 'Salary Max cannot be negative.']);
                exit;
            }
            if ($salary_min !== null && $salary_max !== null && $salary_min > $salary_max) {
                echo json_encode(['status' => 'error', 'message' => 'Salary Min cannot be greater than Salary Max.']);
                exit;
            }
            if ($salary_currency !== 'PHP' && ($salary_min !== null || $salary_max !== null)) {
                if (getExchangeRate($database, $salary_currency) === null) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => "No cached exchange rate for \"$salary_currency\" yet. Click \"Refresh Rates\" first, or choose a different currency."
                    ]);
                    exit;
                }
            }

            $salary_min_php = convertToPhp($database, $salary_min, $salary_currency);
            $salary_max_php = convertToPhp($database, $salary_max, $salary_currency);
            if (isset($_FILES['rsf_pdf']) && $_FILES['rsf_pdf']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'rsf_pdfs' . DIRECTORY_SEPARATOR;
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $file_ext = strtolower(pathinfo($_FILES['rsf_pdf']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['pdf'])) {
                    $new_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rsf_number) . '_' . time() . '.' . $file_ext;
                    $target_file = $upload_dir . $new_filename;
                    if (move_uploaded_file($_FILES['rsf_pdf']['tmp_name'], $target_file)) {
                        $pdf_path = 'uploads/rsf_pdfs/' . $new_filename;
                    }
                }
            }

            $database->begin_transaction();
            $txStarted = true;
            $stmt = $database->prepare("INSERT INTO rsf_job_orders 
            (rsf_number, employer_name, worksite_country, classification, position_title, 
            subcategory_id, slots_available, qualifications, salary_min, salary_max, 
            salary_currency, salary_min_php, salary_max_php, deadline, pdf_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Database prepare failed: ' . $database->error]);
                exit;
            }
            $stmt->bind_param('sssssiisddsddss', $rsf_number, $employer_name, $worksite_country, $classification, $position_title, $subcategory_id, $slots_available, $qualifications, $salary_min, $salary_max, $salary_currency, $salary_min_php, $salary_max_php, $deadline, $pdf_path);
            $executed = $stmt->execute();
            if (!$executed && $database->errno === 1062) {
                // Extremely rare race: another insert grabbed this number first, regenerate once.
                $rsf_number = generateNextRsfNumber($database);
                $stmt->bind_param('sssssiisddsddss', $rsf_number, $employer_name, $worksite_country, $classification, $position_title, $subcategory_id, $slots_available, $qualifications, $salary_min, $salary_max, $salary_currency, $salary_min_php, $salary_max_php, $deadline, $pdf_path);
                $executed = $stmt->execute();
            }
            if ($executed) {
                $newId = (int) $database->insert_id;
                if ($requirements !== []) {
                    $reqStmt = $database->prepare("INSERT INTO rsf_required_documents (rsf_id, document_type, document_name, is_required, description) VALUES (?, ?, ?, ?, ?)");
                    foreach ($requirements as $req) {
                        $reqStmt->bind_param('issis', $newId, $req['document_type'], $req['document_name'], $req['is_required'], $req['description']);
                        if (!$reqStmt->execute()) {
                            throw new RuntimeException('Failed to save requirements: ' . $reqStmt->error);
                        }
                    }
                    $reqStmt->close();
                }
                if ($questions !== []) {
                    $qStmt = $database->prepare("INSERT INTO questions (question_text, is_required, order_no, rsf_id) VALUES (?, ?, ?, ?)");
                    foreach ($questions as $q) {
                        $qOrder = $q['order_no'];
                        $qStmt->bind_param('siii', $q['question_text'], $q['is_required'], $qOrder, $newId);
                        if (!$qStmt->execute()) {
                            throw new RuntimeException('Failed to save questions: ' . $qStmt->error);
                        }
                    }
                    $qStmt->close();
                }
                $qualDelStmt = $database->prepare("DELETE FROM rsf_qualifications WHERE rsf_id = ?");
                $qualDelStmt->bind_param('i', $newId);
                $qualDelStmt->execute();
                $qualDelStmt->close();
                if ($qualifications_structured !== []) {
                    $qualStmt = $database->prepare("INSERT INTO rsf_qualifications (rsf_id, qualification_type, qualification_value) VALUES (?, ?, ?)");
                    foreach ($qualifications_structured as $qual) {
                        $qualStmt->bind_param('iss', $newId, $qual['qualification_type'], $qual['qualification_value']);
                        if (!$qualStmt->execute())
                            throw new RuntimeException('Failed to save qualifications: ' . $qualStmt->error);
                    }
                    $qualStmt->close();
                }
                if ($skills_structured !== []) {
                    $skillStmt = $database->prepare("INSERT INTO rsf_required_skills (rsf_id, skill_id, importance, min_proficiency) VALUES (?, ?, ?, ?)");
                    foreach ($skills_structured as $sk) {
                        $skillStmt->bind_param('iiss', $newId, $sk['skill_id'], $sk['importance'], $sk['min_proficiency']);
                        if (!$skillStmt->execute())
                            throw new RuntimeException('Failed to save skills: ' . $skillStmt->error);
                    }
                    $skillStmt->close();
                }
                $database->commit();
                echo json_encode([
                    'status' => 'success',
                    'message' => "RSF Job Order created successfully! ({$rsf_number})",
                ]);
            } else {
                $database->rollback();
                echo json_encode(['status' => 'error', 'message' => 'Failed to save record. ' . $stmt->error]);
            }
        } catch (Throwable $e) {
            if ($txStarted) {
                $database->rollback();
            }
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── EXISTING: Edit ────────────────────────────────────────────────────────
    if ($_POST['action'] === 'edit') {
        $txStarted = false;
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid job order ID.']);
                exit;
            }

            // Check if applicants already answered this job's current questions.
            // If so, we must not delete/replace those questions later (FK constraint).
            $checkAns = $database->prepare("
                SELECT COUNT(*) AS cnt
                FROM application_answers aa
                INNER JOIN questions q ON q.id = aa.question_id
                WHERE q.rsf_id = ?
            ");
            $checkAns->bind_param('i', $id);
            $checkAns->execute();
            $ansRow = $checkAns->get_result()->fetch_assoc();
            $hasAnsweredQuestions = (int)($ansRow['cnt'] ?? 0) > 0;

            $pdf_path = null;
            $rsf_number = '';
            $stmt = $database->prepare("SELECT pdf_path, rsf_number FROM rsf_job_orders WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $pdf_path = $row['pdf_path'];
                $rsf_number = $row['rsf_number'];
            }
            $employer_name   = trim($_POST['employer_name'] ?? '');
            $worksite_country = trim($_POST['worksite_country'] ?? '');
            $classification  = trim($_POST['classification'] ?? '');
            $position_title  = trim($_POST['position_title'] ?? '');
            $subcategory_id  = !empty($_POST['subcategory_id']) ? (int) $_POST['subcategory_id'] : null;
            $slots_available = (int)($_POST['slots_available'] ?? 0);
            $qualifications  = trim($_POST['qualifications'] ?? '');
            $deadline        = !empty($_POST['deadline']) ? trim($_POST['deadline']) : null;
            $requirements = parsePostedRequirements();
            $questions = parsePostedQuestions();
            $qualifications_structured = parsePostedQualifications();
            $skills_structured = parsePostedSkills();

            // ── Salary (validated) ──
            $salary_min      = ($_POST['salary_min'] ?? '') !== '' ? (float) $_POST['salary_min'] : null;
            $salary_max      = ($_POST['salary_max'] ?? '') !== '' ? (float) $_POST['salary_max'] : null;
            $salary_currency = strtoupper(trim($_POST['salary_currency'] ?? '')) ?: 'PHP';

            if ($salary_min !== null && $salary_min < 0) {
                echo json_encode(['status' => 'error', 'message' => 'Salary Min cannot be negative.']);
                exit;
            }
            if ($salary_max !== null && $salary_max < 0) {
                echo json_encode(['status' => 'error', 'message' => 'Salary Max cannot be negative.']);
                exit;
            }
            if ($salary_min !== null && $salary_max !== null && $salary_min > $salary_max) {
                echo json_encode(['status' => 'error', 'message' => 'Salary Min cannot be greater than Salary Max.']);
                exit;
            }
            if ($salary_currency !== 'PHP' && ($salary_min !== null || $salary_max !== null)) {
                if (getExchangeRate($database, $salary_currency) === null) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => "No cached exchange rate for \"$salary_currency\" yet. Click \"Refresh Rates\" first, or choose a different currency."
                    ]);
                    exit;
                }
            }

            $salary_min_php = convertToPhp($database, $salary_min, $salary_currency);
            $salary_max_php = convertToPhp($database, $salary_max, $salary_currency);

            $pdf_path = null;
            $stmt = $database->prepare("SELECT pdf_path FROM rsf_job_orders WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) $pdf_path = $row['pdf_path'];

            if (isset($_FILES['rsf_pdf']) && $_FILES['rsf_pdf']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'rsf_pdfs' . DIRECTORY_SEPARATOR;
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $file_ext = strtolower(pathinfo($_FILES['rsf_pdf']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['pdf'])) {
                    $new_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rsf_number) . '_' . time() . '.' . $file_ext;
                    $target_file = $upload_dir . $new_filename;
                    if (move_uploaded_file($_FILES['rsf_pdf']['tmp_name'], $target_file)) {
                        $pdf_path = 'uploads/rsf_pdfs/' . $new_filename;
                    }
                }
            }

            $stmt = $database->prepare("UPDATE rsf_job_orders SET 
                rsf_number=?, employer_name=?, worksite_country=?, classification=?, 
                position_title=?, subcategory_id=?, slots_available=?, qualifications=?, 
                salary_min=?, salary_max=?, salary_currency=?, 
                salary_min_php=?, salary_max_php=?, deadline=?, pdf_path=? 
                WHERE id=?");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Database prepare failed: ' . $database->error]);
                exit;
            }
            $database->begin_transaction();
            $txStarted = true;
            $stmt->bind_param('sssssiisddsddssi', $rsf_number, $employer_name, $worksite_country, $classification, $position_title, $subcategory_id, $slots_available, $qualifications, $salary_min, $salary_max, $salary_currency, $salary_min_php, $salary_max_php, $deadline, $pdf_path, $id);
            if ($stmt->execute()) {
                $del = $database->prepare("DELETE FROM rsf_required_documents WHERE rsf_id = ?");
                $del->bind_param('i', $id);
                $del->execute();
                $del->close();

                if ($requirements !== []) {
                    $reqStmt = $database->prepare("INSERT INTO rsf_required_documents (rsf_id, document_type, document_name, is_required, description) VALUES (?, ?, ?, ?, ?)");
                    foreach ($requirements as $req) {
                        $reqStmt->bind_param('issis', $id, $req['document_type'], $req['document_name'], $req['is_required'], $req['description']);
                        if (!$reqStmt->execute()) {
                            throw new RuntimeException('Failed to update requirements: ' . $reqStmt->error);
                        }
                    }
                    $reqStmt->close();
                }
                if ($hasAnsweredQuestions) {
                    // Skip: applicants already answered one or more current questions.
                    // Deleting them would violate the application_answers FK constraint.
                } else {
                    $qDel = $database->prepare("DELETE FROM questions WHERE rsf_id = ?");
                    $qDel->bind_param('i', $id);
                    $qDel->execute();
                    $qDel->close();

                    if ($questions !== []) {
                        $qStmt = $database->prepare("INSERT INTO questions (question_text, is_required, order_no, rsf_id) VALUES (?, ?, ?, ?)");
                        foreach ($questions as $q) {
                            $qOrder = $q['order_no'];
                            $qStmt->bind_param('siii', $q['question_text'], $q['is_required'], $qOrder, $id);
                            if (!$qStmt->execute()) {
                                throw new RuntimeException('Failed to update questions: ' . $qStmt->error);
                            }
                        }
                        $qStmt->close();
                    }
                }
                $qualDelStmt = $database->prepare("DELETE FROM rsf_qualifications WHERE rsf_id = ?");
                $qualDelStmt->bind_param('i', $id);
                $qualDelStmt->execute();
                $qualDelStmt->close();
                if ($qualifications_structured !== []) {
                    $qualStmt = $database->prepare("INSERT INTO rsf_qualifications (rsf_id, qualification_type, qualification_value) VALUES (?, ?, ?)");
                    foreach ($qualifications_structured as $qual) {
                        $qualStmt->bind_param('iss', $id, $qual['qualification_type'], $qual['qualification_value']);
                        if (!$qualStmt->execute())
                            throw new RuntimeException('Failed to update qualifications: ' . $qualStmt->error);
                    }
                    $qualStmt->close();
                }
                $skillDelStmt = $database->prepare("DELETE FROM rsf_required_skills WHERE rsf_id = ?");
                $skillDelStmt->bind_param('i', $id);
                $skillDelStmt->execute();
                $skillDelStmt->close();
                if ($skills_structured !== []) {
                    $skillStmt = $database->prepare("INSERT INTO rsf_required_skills (rsf_id, skill_id, importance, min_proficiency) VALUES (?, ?, ?, ?)");
                    foreach ($skills_structured as $sk) {
                        $skillStmt->bind_param('iiss', $id, $sk['skill_id'], $sk['importance'], $sk['min_proficiency']);
                        if (!$skillStmt->execute())
                            throw new RuntimeException('Failed to update skills: ' . $skillStmt->error);
                    }
                    $skillStmt->close();
                }
                $database->commit();
                $message = 'RSF Job Order updated successfully!';
                if ($hasAnsweredQuestions) {
                    $message .= ' Note: application questions were not changed because applicants have already submitted answers to them.';
                }
                echo json_encode(['status' => 'success', 'message' => $message]);
            } else {
                $database->rollback();
                echo json_encode(['status' => 'error', 'message' => 'Failed to update. ' . $stmt->error]);
            }
        } catch (Throwable $e) {
            if ($txStarted) {
                $database->rollback();
            }
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── EXISTING: Delete ──────────────────────────────────────────────────────
    if ($_POST['action'] === 'delete') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid job order ID.']);
                exit;
            }
            $check = $database->prepare("SELECT COUNT(*) as cnt FROM applications WHERE rsf_id = ?");
            $check->bind_param('i', $id);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            if ($row['cnt'] > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete: This job order has applications.']);
                exit;
            }
            $stmt = $database->prepare("DELETE FROM rsf_job_orders WHERE id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                echo json_encode(['status' => 'success', 'message' => 'RSF Job Order deleted.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to delete or record not found.']);
            }
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Fetch all job orders for display (with applicant count)
$stmt = $database->query("
    SELECT r.*, COUNT(a.id) AS applicant_count
    FROM rsf_job_orders r
    LEFT JOIN applications a ON a.rsf_id = r.id
    GROUP BY r.id
    ORDER BY r.created_at DESC
");
$jobOrders = $stmt->fetch_all(MYSQLI_ASSOC);
$docsByRsf = [];
$docStmt = $database->query("SELECT rsf_id, document_type, document_name, is_required, description FROM rsf_required_documents ORDER BY id ASC");
if ($docStmt) {
    while ($d = $docStmt->fetch_assoc()) {
        $rid = (int) ($d['rsf_id'] ?? 0);
        if (!isset($docsByRsf[$rid])) {
            $docsByRsf[$rid] = [];
        }
        $docsByRsf[$rid][] = [
            'document_type' => (string) ($d['document_type'] ?? ''),
            'document_name' => (string) ($d['document_name'] ?? ''),
            'is_required' => (int) ($d['is_required'] ?? 1),
            'description' => (string) ($d['description'] ?? ''),
        ];
    }
}
$questionsByRsf = [];
$questionStmt = $database->query("SELECT id, rsf_id, question_text, is_required, order_no FROM questions WHERE rsf_id IS NOT NULL ORDER BY COALESCE(order_no, 999), id ASC");
if ($questionStmt) {
    while ($q = $questionStmt->fetch_assoc()) {
        $rid = (int) ($q['rsf_id'] ?? 0);
        if (!isset($questionsByRsf[$rid])) {
            $questionsByRsf[$rid] = [];
        }
        $questionsByRsf[$rid][] = [
            'id' => (int) ($q['id'] ?? 0),
            'question_text' => (string) ($q['question_text'] ?? ''),
            'is_required' => (int) ($q['is_required'] ?? 1),
            'order_no' => isset($q['order_no']) ? (int) $q['order_no'] : null,
        ];
    }
}

$qualsByRsf = [];
$qualStmt = $database->query("SELECT rsf_id, qualification_type, qualification_value FROM rsf_qualifications ORDER BY id ASC");
if ($qualStmt) {
    while ($q = $qualStmt->fetch_assoc()) {
        $rid = (int)($q['rsf_id'] ?? 0);
        if (!isset($qualsByRsf[$rid])) $qualsByRsf[$rid] = [];
        $qualsByRsf[$rid][] = [
            'qualification_type'  => (string)($q['qualification_type']  ?? ''),
            'qualification_value' => (string)($q['qualification_value'] ?? ''),
        ];
    }
}
$skillsByRsf = [];
$rsfSkillStmt = $database->query("
    SELECT rs.rsf_id, rs.skill_id, s.skill_name, rs.importance, rs.min_proficiency
    FROM rsf_required_skills rs
    INNER JOIN skills s ON s.id = rs.skill_id
    ORDER BY rs.id ASC
");
if ($rsfSkillStmt) {
    while ($rs = $rsfSkillStmt->fetch_assoc()) {
        $rid = (int) ($rs['rsf_id'] ?? 0);
        if (!isset($skillsByRsf[$rid])) $skillsByRsf[$rid] = [];
        $skillsByRsf[$rid][] = [
            'skill_id' => (int) $rs['skill_id'],
            'skill_name' => (string) $rs['skill_name'],
            'importance' => (string) $rs['importance'],
            'min_proficiency' => (string) $rs['min_proficiency'],
        ];
    }
}

$search = trim($_GET['search'] ?? '');
//$filter_class = trim($_GET['classification'] ?? '');
$filter_country = trim($_GET['country'] ?? '');

// ── NEW: Lookup data for Category/Subcategory + Degree dropdowns ──────────
$jobCategories = [];
$catStmt = $database->query("SELECT id, category_name FROM job_categories ORDER BY category_name ASC");
if ($catStmt) {
    while ($c = $catStmt->fetch_assoc()) {
        $jobCategories[] = ['id' => (int) $c['id'], 'category_name' => (string) $c['category_name']];
    }
}
$jobSubcategories = [];
$subcatStmt = $database->query("SELECT id, category_id, subcategory_name FROM job_subcategories ORDER BY subcategory_name ASC");
if ($subcatStmt) {
    while ($s = $subcatStmt->fetch_assoc()) {
        $jobSubcategories[] = [
            'id' => (int) $s['id'],
            'category_id' => (int) $s['category_id'],
            'subcategory_name' => (string) $s['subcategory_name'],
        ];
    }
}
$degreePrograms = [];
$degStmt = $database->query("SELECT id, degree_name FROM degree_programs ORDER BY degree_name ASC");
if ($degStmt) {
    while ($d = $degStmt->fetch_assoc()) {
        $degreePrograms[] = (string) $d['degree_name'];
    }
}

$professionalLicenses = [];
$licStmt = $database->query("SELECT license_name FROM professional_licenses ORDER BY license_name ASC");
if ($licStmt) {
    while ($l = $licStmt->fetch_assoc()) {
        $professionalLicenses[] = (string) $l['license_name'];
    }
}

$allSkills = [];
$skillsStmt = $database->query("SELECT id, skill_name, skill_category FROM skills ORDER BY skill_category ASC, skill_name ASC");
if ($skillsStmt) {
    while ($s = $skillsStmt->fetch_assoc()) {
        $allSkills[] = [
            'id' => (int) $s['id'],
            'skill_name' => (string) $s['skill_name'],
            'skill_category' => (string) ($s['skill_category'] ?? ''),
        ];
    }
}

$exchangeRates = ['PHP' => 1.0];
$rateStmt = $database->query("SELECT currency_code, exchange_rate FROM exchange_rates");
if ($rateStmt) {
    while ($r = $rateStmt->fetch_assoc()) {
        $exchangeRates[$r['currency_code']] = (float) $r['exchange_rate'];
    }
}

// Fetch all currencies for searchable dropdown
$allCurrencies = [];
$currencyStmt = $database->query("SELECT currency_code FROM exchange_rates ORDER BY currency_code ASC");
if ($currencyStmt) {
    while ($row = $currencyStmt->fetch_assoc()) {
        $allCurrencies[] = $row['currency_code'];
    }
}
if (empty($allCurrencies)) {
    $allCurrencies = ['PHP'];
}
?>

<!DOCTYPE html>
<html lang="en" class="bg-gray-50 min-h-screen">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Job Orders (RSF) DMW</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        /* ── Applicants Modal ── */
        #applicantsModal .modal-overlay {
            animation: fadeIn .18s ease;
        }

        #applicantsModal .modal-panel {
            animation: slideUp .22s cubic-bezier(.22, .68, 0, 1.2);
        }

        @keyframes fadeIn {
            from {
                opacity: 0
            }

            to {
                opacity: 1
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(24px)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        /* Status badge colours */
        .badge-pending {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-unassigned {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-assigned {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-under_evaluation {
            background: #ede9fe;
            color: #5b21b6;
        }

        .badge-for_compliance {
            background: #ffedd5;
            color: #9a3412;
        }

        .badge-approved {
            background: #dcfce7;
            color: #14532d;
        }

        .badge-disapproved {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Assign dropdown row */
        .assign-row select {
            min-width: 170px;
        }

        /* Spinner for loading state inside modal */
        .spin {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;

        }

        /* Table card */
        .jo-table-card {
            background: #fff;
            border-radius: 1.5rem;
            border: 1px solid #f1f1f4;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
            overflow: hidden;
        }

        .jo-table thead th {
            background: #fafafa;
            border-bottom: 1px solid #eef0f2;
        }

        .jo-table tbody tr {
            transition: background-color .12s ease;
        }

        .jo-table tbody tr:hover {
            background-color: #f9fafb;
        }

        .jo-table tbody tr:not(:last-child) td {
            border-bottom: 1px solid #f2f3f5;
        }

        .jo-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .01em;
        }

        .jo-deadline-ok {
            color: #475467;
        }

        .jo-deadline-soon {
            color: #b54708;
            font-weight: 600;
        }

        .jo-deadline-late {
            color: #b42318;
            font-weight: 600;
        }

        .jo-action-btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            transition: background-color .12s ease, color .12s ease;
        }

        .jo-action-btn:hover {
            background: #f2f4f7;
        }

        #addModal .modal-overlay,
        #editModal .modal-overlay {
            animation: fadeIn .18s ease;
        }

        #addModal .modal-panel,
        #editModal .modal-panel {
            animation: slideUp .22s cubic-bezier(.22, .68, 0, 1.2);
        }

        /* ── Repeatable-row selects (qualifications, skills) — visual match
           for the custom dropdown panels used elsewhere, without the JS
           panel overhead since these rows are rebuilt on every add/remove. ── */
        .qual-row select,
        .skill-row select {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%239ca3af%22%20d%3D%22M1%201l5%205%205-5%22%20stroke%3D%22%239ca3af%22%20stroke-width%3D%221.4%22%20fill%3D%22none%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 28px;
            transition: border-color .15s;
        }

        .qual-row select:hover,
        .skill-row select:hover {
            border-color: #d1d5db;
        }

        .qual-row select:focus,
        .skill-row select:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(217, 119, 6, .12);
        }

        .skill-row select:focus {
            box-shadow: 0 0 0 3px rgba(8, 145, 178, .12);
        }
    </style>
</head>

<body class="min-h-screen bg-gray-50">

    <!-- TOP BAR -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW" class="h-9">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;-&nbsp; ADMIN PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn"><i class="fas fa-bell"></i><span class="notif-dot"></span></div>
            <!-- <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
            <div class="topbar-avatar">AU</div>
        </div>
    </header>

    <!-- SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Job Orders (RSF)</h2>
                <p>(<?= count($jobOrders) ?>) Total job orders</p>
            </div>
        </div>
    </div>

    <div class="flex max-w-screen-2xl mx-auto">
        <main class="flex-1 min-w-0 px-6 py-8 overflow-auto">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-900"></h2>
                    <p class="inline-block bg-white text-gray-700 font-semibold text-[11px] sm:text-[13px] lg:text-[15px] mt-1 sm:mt-1.5 px-2.5 py-1.5 sm:px-3 sm:py-2 lg:px-4 rounded-lg sm:rounded-xl shadow-sm border border-gray-100 leading-relaxed" style="font-family: 'DM Sans', sans-serif;">
                        Manage Recruitment Specification Forms and available positions
                    </p>
                </div>

                <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
                    <!-- Refresh Rates Button -->
                    <button onclick="refreshExchangeRates()"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 text-xs sm:px-4 sm:py-2.5 sm:text-sm md:px-5 md:py-3 md:text-base rounded-xl md:rounded-2xl font-medium flex items-center gap-1.5 sm:gap-2 shadow-sm transition whitespace-nowrap">
                        <i class="fas fa-sync-alt text-[11px] sm:text-sm"></i>
                        <span class="hidden xs:inline sm:inline">Refresh Rates</span>
                    </button>

                    <!-- Add New Button -->
                    <button onclick="openAddModal()"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 text-xs sm:px-4 sm:py-2.5 sm:text-sm md:px-6 md:py-3 md:text-base rounded-xl md:rounded-2xl font-medium flex items-center gap-1.5 sm:gap-2 shadow-sm transition whitespace-nowrap">
                        <i class="fas fa-plus text-[11px] sm:text-sm"></i>
                        <span class="hidden xs:inline sm:inline">Add New RSF Job Order</span>
                        <span class="inline xs:hidden sm:hidden">Add RSF</span>
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-2xl sm:rounded-3xl shadow-sm border border-gray-100 p-3 sm:p-4 md:p-6 mb-6 md:mb-8">
                <div class="flex flex-col md:flex-row gap-2 sm:gap-3 md:gap-4">
                    <div class="flex-1 relative">
                        <input type="text" id="searchInput" placeholder="Search by RSF Number, Employer or Position..." value="<?= htmlspecialchars($search) ?>"
                            class="w-full pl-9 sm:pl-11 pr-3 sm:pr-4 py-2 sm:py-2.5 md:py-3 text-xs sm:text-sm md:text-base border border-gray-200 rounded-xl md:rounded-2xl focus:outline-none focus:border-blue-500">
                        <i class="fas fa-search absolute left-3 sm:left-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs sm:text-sm"></i>
                    </div>
                    <select id="filterCountry" class="px-3 py-2 sm:px-4 sm:py-2.5 md:px-5 md:py-3 text-xs sm:text-sm md:text-base border border-gray-200 rounded-xl md:rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="">All Countries</option>
                        <?php
                        $countryList = [];
                        foreach ($jobOrders as $j) {
                            $c = trim($j['worksite_country'] ?? '');
                            if ($c !== '') $countryList[$c] = true;
                        }
                        ksort($countryList);
                        foreach (array_keys($countryList) as $country):
                        ?>
                            <option value="<?= htmlspecialchars($country) ?>" <?= $filter_country === $country ? 'selected' : '' ?>><?= htmlspecialchars($country) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="applyFilters()" class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 sm:px-6 sm:py-2.5 md:px-8 md:py-3 text-xs sm:text-sm md:text-base rounded-xl md:rounded-2xl font-medium whitespace-nowrap">Filter</button>
                </div>
            </div>

            <!-- Table -->
            <p class="text-xs sm:text-sm text-gray-500 mb-3" id="jobsCountLine"></p>
            <div class="jo-table-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] sm:min-w-[900px] lg:min-w-[1200px] jo-table">
                        <thead>
                            <tr>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">RSF Number</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Employer</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Position</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-left text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Country</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Type</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Slots</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Reqs</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Applicants</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Deadline</th>
                                <th class="px-2.5 py-2.5 sm:px-4 sm:py-3 lg:px-6 lg:py-4 text-center text-[9px] sm:text-[10px] lg:text-[11px] font-bold uppercase tracking-tight sm:tracking-widest text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700">
                            <?php if (empty($jobOrders)): ?>
                                <tr>
                                    <td colspan="10" class="px-4 py-12 sm:px-8 sm:py-20 text-center text-gray-400 text-[11px] sm:text-sm">
                                        <i class="fas fa-inbox text-2xl sm:text-3xl mb-2 sm:mb-3 block text-gray-300"></i>
                                        No job orders yet. Click "Add New RSF Job Order" to create one.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr id="noResultsRow" class="hidden">
                                    <td colspan="10" class="px-4 py-10 sm:px-8 sm:py-16 text-center text-gray-400 text-[11px] sm:text-sm">No job orders match your filters.</td>
                                </tr>
                                <?php foreach ($jobOrders as $job): ?>
                                    <?php
                                    $reqCount = count($docsByRsf[(int) $job['id']] ?? []);
                                    $daysLeft = (strtotime($job['deadline']) - strtotime('today')) / 86400;
                                    $deadlineClass = $daysLeft < 0 ? 'jo-deadline-late' : ($daysLeft <= 7 ? 'jo-deadline-soon' : 'jo-deadline-ok');
                                    ?>
                                    <tr class="job-row"
                                        data-id="<?= (int)$job['id'] ?>"
                                        data-search="<?= htmlspecialchars(strtolower($job['rsf_number'] . ' ' . ($job['employer_name'] ?? '') . ' ' . ($job['position_title'] ?? '') . ' ' . ($job['worksite_country'] ?? ''))) ?>"
                                        data-country="<?= htmlspecialchars($job['worksite_country'] ?? '') ?>"
                                        data-job="<?= htmlspecialchars(json_encode($job), ENT_QUOTES, 'UTF-8') ?>"
                                        data-docs="<?= htmlspecialchars(json_encode($docsByRsf[(int) $job['id']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                                        data-questions="<?= htmlspecialchars(json_encode($questionsByRsf[(int) $job['id']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                                        data-quals="<?= htmlspecialchars(json_encode($qualsByRsf[(int) $job['id']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                                        data-skills="<?= htmlspecialchars(json_encode($skillsByRsf[(int) $job['id']] ?? []), ENT_QUOTES, 'UTF-8') ?>">
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 font-mono text-blue-700 font-medium whitespace-nowrap text-[10px] sm:text-[12px] lg:text-sm"><?= htmlspecialchars($job['rsf_number']) ?></td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 font-medium text-gray-900 text-[11px] sm:text-[12.5px] lg:text-sm"><?= htmlspecialchars($job['employer_name']) ?></td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-[11px] sm:text-[12.5px] lg:text-sm"><?= htmlspecialchars($job['position_title']) ?></td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-gray-500 text-[10px] sm:text-[11.5px] lg:text-sm"><?= htmlspecialchars($job['worksite_country'] ?? '-') ?></td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center">
                                            <span class="jo-pill <?= $job['classification'] === 'Government' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' ?> !px-2 !py-0.5 sm:!px-3 sm:!py-1 !text-[9px] sm:!text-[11px]">
                                                <?= htmlspecialchars($job['classification']) ?>
                                            </span>
                                        </td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center font-semibold text-gray-900 text-[11px] sm:text-[12.5px] lg:text-sm"><?= $job['slots_available'] ?></td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center">
                                            <?php if ($reqCount > 0): ?>
                                                <span class="jo-pill bg-violet-50 text-violet-700 !px-2 !py-0.5 sm:!px-3 sm:!py-1 !text-[9px] sm:!text-[11px]">
                                                    <i class="fas fa-list-check text-[7px] sm:text-[9px]"></i><?= (int) $reqCount ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-300 text-[10px] sm:text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center">
                                            <?php if ($job['applicant_count'] > 0): ?>
                                                <button onclick="openApplicantsModal(<?= (int)$job['id'] ?>, <?= htmlspecialchars(json_encode($job['rsf_number']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($job['position_title']), ENT_QUOTES) ?>)"
                                                    class="jo-pill bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition cursor-pointer !px-2 !py-0.5 sm:!px-3 sm:!py-1 !text-[9px] sm:!text-[11px]">
                                                    <i class="fas fa-users text-[7px] sm:text-[9px]"></i><?= (int)$job['applicant_count'] ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-300 text-[10px] sm:text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center text-[9.5px] sm:text-[11px] lg:text-xs <?= $deadlineClass ?> whitespace-nowrap"><?= date('M j, Y', strtotime($job['deadline'])) ?></td>
                                        <td class="px-2.5 py-2.5 sm:px-4 sm:py-3.5 lg:px-6 lg:py-5 text-center">
                                            <div class="flex items-center justify-center gap-0.5 sm:gap-1">
                                                <?php if (!empty($job['pdf_path'])): ?>
                                                    <a href="../<?= htmlspecialchars($job['pdf_path']) ?>" target="_blank" class="jo-action-btn text-green-600 !w-6 !h-6 sm:!w-8 sm:!h-8 !text-[11px] sm:!text-base" title="View PDF"><i class="fas fa-file-pdf"></i></a>
                                                <?php endif; ?>
                                                <button onclick="editJob(<?= $job['id'] ?>)" class="jo-action-btn text-blue-600 !w-6 !h-6 sm:!w-8 sm:!h-8 !text-[11px] sm:!text-base" title="Edit"><i class="fas fa-edit"></i></button>
                                                <button onclick="deleteJob(<?= $job['id'] ?>)" class="jo-action-btn text-red-500 !w-6 !h-6 sm:!w-8 sm:!h-8 !text-[11px] sm:!text-base" title="Delete"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         APPLICANTS MODAL (NEW)
    ═══════════════════════════════════════════════════════════════════════ -->
    <div id="applicantsModal" class="hidden fixed inset-0 z-[200] flex items-center justify-center">
        <!-- overlay -->
        <div class="modal-overlay absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeApplicantsModal()"></div>

        <!-- panel -->
        <div class="modal-panel relative bg-white rounded-3xl w-full max-w-5xl mx-4 max-h-[90vh] flex flex-col shadow-2xl">

            <!-- Header -->
            <div class="p-6 border-b flex items-start justify-between gap-4 flex-shrink-0">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-indigo-500 mb-1" id="appl-rsf-label">RSF</p>
                    <h3 class="text-xl font-bold text-gray-900" id="appl-position-label">Position Title</h3>
                </div>
                <button onclick="closeApplicantsModal()" class="text-gray-400 hover:text-gray-700 text-3xl leading-none flex-shrink-0">&times;</button>
            </div>

            <!-- Stats Cards -->
            <div class="px-6 py-4 border-b flex-shrink-0" id="appl-stats-bar"></div>

            <!-- Status Filter -->
            <div class="px-6 py-3 border-b flex-shrink-0">
                <select id="appl-status-filter" onchange="filterApplicantRows()"
                    class="w-full px-4 py-2.5 border border-gray-200 rounded-2xl text-sm text-gray-700 focus:outline-none focus:border-blue-500 bg-white">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="disapproved">Disapproved</option>
                    <option value="under_evaluation">Under evaluation</option>
                    <option value="for_compliance">For compliance</option>
                    <option value="assigned">Assigned</option>
                    <option value="unassigned">Unassigned</option>
                </select>
            </div>

            <!-- Body / table -->
            <div class="overflow-auto flex-1 p-6" id="appl-body">
                <div class="flex items-center justify-center h-32 text-gray-400">
                    <i class="fas fa-circle-notch spin text-2xl mr-3"></i> Loading applicants…
                </div>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 border-t flex-shrink-0 flex justify-end">
                <button onclick="closeApplicantsModal()" class="px-8 py-2.5 text-gray-600 hover:bg-gray-100 rounded-2xl font-medium text-sm">Close</button>
            </div>
        </div>
    </div>

    <!-- Add New Modal -->
    <div id="addModal" class="hidden fixed inset-0 z-[300] flex items-center justify-center">
        <div class="modal-overlay absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeAddModal()"></div>
        <div class="modal-panel relative bg-white rounded-3xl w-full max-w-4xl mx-4 max-h-[calc(100vh-120px)] overflow-auto">
            <div class="p-8 border-b flex items-center justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
                <h3 class="text-2xl font-semibold text-gray-900">Add New RSF Job Order</h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button>
            </div>
            <form id="addForm" class="p-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                <input type="hidden" name="action" value="add">

                <!-- Section: RSF Number (auto-generated) -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">RSF Number</label>
                    <input type="text" id="rsf_number_preview" readonly disabled
                        value="Generating…"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl bg-gray-50 text-gray-500 cursor-not-allowed">
                    <p class="text-xs text-gray-400 mt-1">Auto-generated on save (format: DMW-RSF-YYNNNN-YYYY).</p>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Employer & Worksite -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Employer Name <span class="text-red-500">*</span></label>
                    <input type="text" name="employer_name" required placeholder="e.g. ABC International Manpower Services"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Worksite Country <span class="text-red-500">*</span></label>
                    <input type="text" name="worksite_country" placeholder="e.g. Saudi Arabia, UAE, Qatar, Canada"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Classification & Position -->
                <!--<div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Classification</label>
                <select name="classification" class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                    <option value="">Select Classification</option>
                    <option value="Government">Government</option>
                    <option value="Private">Private</option>
                </select>
            </div>-->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Classification</label>
                    <select name="classification" class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="Government" selected>Government</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Position Title <span class="text-red-500">*</span></label>
                    <input type="text" name="position_title" required placeholder="e.g. Registered Nurse, Heavy Equipment Operator"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Job Category / Subcategory -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Job Category</label>
                    <select id="add_category_id" onchange="onCategoryChange('add')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="">Select category…</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Job Subcategory</label>
                    <select name="subcategory_id" id="add_subcategory_id"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="">Select category first…</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Used for applicant preference matching.</p>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Salary -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salary Min</label>
                    <input type="number" name="salary_min" id="add_salary_min" step="0.01" min="0" placeholder="e.g. 20000"
                        oninput="updatePhpPreview('add')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salary Max</label>
                    <input type="number" name="salary_max" id="add_salary_max" step="0.01" min="0" placeholder="e.g. 25000"
                        oninput="updatePhpPreview('add')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salary Currency</label>
                    <input type="text"
                        name="salary_currency"
                        id="edit_salary_currency"
                        list="currencyListEdit"
                        placeholder="Type to search (e.g. USD)"
                        onchange="updatePhpPreview('edit')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                    <datalist id="currencyListEdit"></datalist>
                    <p id="edit_salary_php_preview" class="text-xs text-gray-400 mt-1"></p>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Slots & Deadline -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slots Available <span class="text-red-500">*</span></label>
                    <input type="number" name="slots_available" required min="1" placeholder="e.g. 50"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Deadline <span class="text-red-500">*</span></label>
                    <input type="date" name="deadline" required
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Structured Qualifications -->
                <!--<div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Qualifications</label>
                <textarea name="qualifications" rows="4" placeholder="List required qualifications, experience, certifications…"
                    class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500"></textarea>
            </div>-->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload RSF PDF</label>
                    <input type="file" name="rsf_pdf" accept=".pdf"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800">Structured Qualifications</label>
                            <p class="text-xs text-gray-500 mt-0.5">Used for automatic applicant matching.</p>
                        </div>
                        <button type="button" onclick="addQualRow('addQualWrap')"
                            class="text-xs bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add qualifier
                        </button>
                    </div>
                    <div id="addQualWrap" class="space-y-2"></div>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Required Skills -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800">Required Skills</label>
                            <p class="text-xs text-gray-500 mt-0.5">Pick from the master skills list. Used for applicant skill matching.</p>
                        </div>
                        <button type="button" onclick="addSkillRow('addSkillsWrap')"
                            class="text-xs bg-cyan-600 hover:bg-cyan-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add skill
                        </button>
                    </div>
                    <div id="addSkillsWrap" class="space-y-2"></div>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: PDF Upload -->


                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Requirements -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <label class="block text-sm font-semibold text-gray-800">Required Requirements / Documents</label>
                        <button type="button" id="addRequirementRowBtn" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add requirement
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mb-3">Add the specific requirements for this job order. These are saved in the database per job.</p>
                    <div id="addRequirementsWrap" class="space-y-3"></div>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Application Questions -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <label class="block text-sm font-semibold text-gray-800">Job-specific Application Questions</label>
                        <button type="button" id="addQuestionRowBtn" class="text-xs bg-teal-600 hover:bg-teal-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add question
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mb-3">Only these questions will be shown for this specific job offer.</p>
                    <div id="addQuestionsWrap" class="space-y-3"></div>
                </div>

                <!-- Actions -->
                <div class="md:col-span-2 flex justify-end gap-4 pt-4">
                    <button type="button" onclick="closeAddModal()" class="px-8 py-3 text-gray-600 hover:bg-gray-100 rounded-2xl font-medium">Cancel</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-3 rounded-2xl font-medium">Save RSF Job Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="hidden fixed inset-0 z-[300] flex items-center justify-center">
        <div class="modal-overlay absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeEditModal()"></div>
        <div class="modal-panel relative bg-white rounded-3xl w-full max-w-4xl mx-4 max-h-[calc(100vh-120px)] overflow-auto">
            <div class="p-8 border-b flex items-center justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
                <h3 class="text-2xl font-semibold text-gray-900">Edit RSF Job Order</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button>
            </div>
            <form id="editForm" class="p-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">

                <!-- Section: RSF Number (auto-generated, read-only) -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">RSF Number</label>
                    <input type="text" id="edit_rsf_number" readonly disabled
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl bg-gray-50 text-gray-500 cursor-not-allowed">
                    <p class="text-xs text-gray-400 mt-1">Auto-generated on creation and cannot be changed.</p>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Employer & Worksite -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Employer Name <span class="text-red-500">*</span></label>
                    <input type="text" name="employer_name" id="edit_employer_name" required
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Worksite Country</label>
                    <input type="text" name="worksite_country" id="edit_worksite_country"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Classification & Position -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Classification</label>
                    <select name="classification" id="edit_classification" class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="">Select Classification</option>
                        <option value="Government">Government</option>
                        <!-- <option value="Private">Private</option>-->
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Position Title <span class="text-red-500">*</span></label>
                    <input type="text" name="position_title" id="edit_position_title" required
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Job Category / Subcategory -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Job Category</label>
                    <select id="edit_category_id" onchange="onCategoryChange('edit')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="">Select category…</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Job Subcategory</label>
                    <select name="subcategory_id" id="edit_subcategory_id"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                        <option value="">Select category first…</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Used for applicant preference matching.</p>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Salary -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salary Min</label>
                    <input type="number" name="salary_min" id="edit_salary_min" step="0.01" min="0"
                        oninput="updatePhpPreview('edit')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salary Max</label>
                    <input type="number" name="salary_max" id="edit_salary_max" step="0.01" min="0"
                        oninput="updatePhpPreview('edit')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salary Currency</label>
                    <input type="text"
                        name="salary_currency"
                        id="edit_salary_currency"
                        list="currencyListEdit"
                        placeholder="Type to search (e.g. USD)"
                        onchange="updatePhpPreview('edit')"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                    <datalist id="currencyListEdit"></datalist>
                    <p id="edit_salary_php_preview" class="text-xs text-gray-400 mt-1"></p>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Slots & Deadline -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slots Available <span class="text-red-500">*</span></label>
                    <input type="number" name="slots_available" id="edit_slots_available" required min="1"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Deadline <span class="text-red-500">*</span></label>
                    <input type="date" name="deadline" id="edit_deadline" required
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Qualifications (legacy free text) -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Qualifications</label>
                    <textarea name="qualifications" id="edit_qualifications" rows="4"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500"></textarea>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: PDF Upload -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload RSF PDF (leave empty to keep current)</label>
                    <input type="file" name="rsf_pdf" accept=".pdf"
                        class="w-full px-5 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-blue-500">
                </div>
                <!-- Section: Structured Qualifications -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800">Structured Qualifications</label>
                            <p class="text-xs text-gray-500 mt-0.5">Used for automatic applicant matching.</p>
                        </div>
                        <button type="button" onclick="addQualRow('editQualWrap')"
                            class="text-xs bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add qualifier
                        </button>
                    </div>
                    <div id="editQualWrap" class="space-y-2"></div>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Required Skills -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800">Required Skills</label>
                            <p class="text-xs text-gray-500 mt-0.5">Pick from the master skills list. Used for applicant skill matching.</p>
                        </div>
                        <button type="button" onclick="addSkillRow('editSkillsWrap')"
                            class="text-xs bg-cyan-600 hover:bg-cyan-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add skill
                        </button>
                    </div>
                    <div id="editSkillsWrap" class="space-y-2"></div>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Requirements -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <label class="block text-sm font-semibold text-gray-800">Required Requirements / Documents</label>
                        <button type="button" id="editAddRequirementRowBtn" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add requirement
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mb-3">Maintain the specific requirements for this selected job order.</p>
                    <div id="editRequirementsWrap" class="space-y-3"></div>
                </div>

                <hr class="md:col-span-2 border-gray-200 my-1">

                <!-- Section: Application Questions -->
                <div class="md:col-span-2 border border-gray-200 rounded-2xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <label class="block text-sm font-semibold text-gray-800">Job-specific Application Questions</label>
                        <button type="button" id="editAddQuestionRowBtn" class="text-xs bg-teal-600 hover:bg-teal-700 text-white px-3 py-2 rounded-xl">
                            <i class="fas fa-plus"></i> Add question
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mb-3">Maintain application questions for this selected job order.</p>
                    <div id="editQuestionsWrap" class="space-y-3"></div>
                </div>

                <!-- Actions -->
                <div class="md:col-span-2 flex justify-end gap-4 pt-4">
                    <button type="button" onclick="closeEditModal()" class="px-8 py-3 text-gray-600 hover:bg-gray-100 rounded-2xl font-medium">Cancel</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-3 rounded-2xl font-medium">Update RSF Job Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk" data-tip="Dashboard">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>
            <div class="dk" id="appsBtn" data-tip="Applications" onclick="toggleFlyout(event,'appsMenu')">
                <?php
                // Dynamic badge count
                $badge_stmt = $database->prepare("
                SELECT COUNT(*) as total 
                FROM applications 
                WHERE status IN ('pending', 'for_compliance', 'resubmitted')
            ");
                $badge_stmt->execute();
                $badge_result = $badge_stmt->get_result()->fetch_assoc();
                $active_apps = $badge_result['total'] ?? 0;
                ?>
                <span class="dk-badge"><?= $active_apps ?></span>
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Apps</span>
                <div class="flyout" id="appsMenu">
                    <div class="flyout-arr"></div>
                    <a href="applications.php" class="fi"><i class="fas fa-list" style="color:#60a5fa"></i>All Applications</a>
                    <a href="applications.php?status=pending" class="fi"><i class="fas fa-clock" style="color:#fbbf24"></i>Pending</a>
                    <a href="applications.php?status=unassign" class="fi"><i class="fas fa-user-slash" style="color:#f87171"></i>Unassign</a>
                    <a href="applications.php?status=assign" class="fi"><i class="fas fa-user-check" style="color:#60a5fa"></i>Assign</a>
                    <a href="applications.php?status=under_evaluation" class="fi"><i class="fas fa-search" style="color:#a78bfa"></i>Under Evaluation</a>
                    <a href="applications.php?status=for_compliance" class="fi"><i class="fas fa-triangle-exclamation" style="color:#fb923c"></i>For Compliance</a>
                    <a href="applications.php?status=approved" class="fi"><i class="fas fa-check-circle" style="color:#34d399"></i>Approved</a>
                    <a href="applications.php?status=disapproved" class="fi"><i class="fas fa-times-circle" style="color:#f87171"></i>Disapproved</a>
                </div>
            </div>
            <div class="dk-div"></div>
            <!--<a href="assignments.html" class="dk" data-tip="Assignments">
                <i class="fas fa-list-check"></i>
                <span class="dk-lbl">Assign</span>
            </a>-->
            <a href="job-orders.php" class="dk active" data-tip="Job Orders">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">RSF</span>
            </a>
            <a href="try.php" class="dk" data-tip="Reports">
                <i class="fas fa-chart-bar"></i>
                <span class="dk-lbl">Reports</span>
            </a>
            <a href="analytics.php" class="dk">
                <i class="fas fa-chart-bar text-blue-300 text-base leading-none"></i>
                <span class="dk-lbl">Analytics</span>
            </a>
            <a href="user.php" class="dk" data-tip="Evaluators">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Evaluators</span>
            </a>
            <div class="dk-div"></div>
            <a href="settings.html" class="dk" data-tip="Settings">
                <i class="fas fa-gear"></i>
                <span class="dk-lbl">Settings</span>
            </a>
            <div class="dk" data-tip="Logout" onclick="logout('Admin')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>
        </nav>
    </div>


    <script>
        // ── NEW: Lookup data injected from PHP for Category/Subcategory + Degree dropdowns ──
        const JOB_CATEGORIES = <?= json_encode($jobCategories) ?>;
        const JOB_SUBCATEGORIES = <?= json_encode($jobSubcategories) ?>;
        const DEGREE_PROGRAMS = <?= json_encode($degreePrograms) ?>;
        const PROFESSIONAL_LICENSES = <?= json_encode($professionalLicenses) ?>;
        const SKILLS_LIST = <?= json_encode($allSkills) ?>;
        // 1 unit of currency = X PHP, cached daily by the rate-refresh cron job.
        const EXCHANGE_RATES = <?= json_encode($exchangeRates) ?>;

        function updatePhpPreview(prefix) {
            const min = parseFloat(document.getElementById(`${prefix}_salary_min`)?.value);
            const max = parseFloat(document.getElementById(`${prefix}_salary_max`)?.value);
            const currency = document.getElementById(`${prefix}_salary_currency`)?.value;
            const preview = document.getElementById(`${prefix}_salary_php_preview`);
            if (!preview) return;

            if (currency === 'PHP') {
                preview.textContent = '';
                return;
            }

            const rate = EXCHANGE_RATES[currency];
            if (rate === undefined) {
                preview.textContent = 'No cached exchange rate yet for ' + currency + ' — will convert on next daily rate refresh.';
                return;
            }

            const parts = [];
            if (!isNaN(min)) parts.push('₱' + Math.round(min * rate).toLocaleString());
            if (!isNaN(max)) parts.push('₱' + Math.round(max * rate).toLocaleString());
            preview.textContent = parts.length ? `≈ ${parts.join(' – ')} (today's rate)` : '';
        }

        function populateCategorySelect(selectId) {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            let opts = '<option value="">Select category…</option>';
            JOB_CATEGORIES.forEach(c => {
                opts += `<option value="${c.id}">${c.category_name}</option>`;
            });
            sel.innerHTML = opts;
        }

        function populateSubcategorySelect(selectId, categoryId, selectedSubId) {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            const filtered = JOB_SUBCATEGORIES.filter(s => String(s.category_id) === String(categoryId));
            if (!categoryId || filtered.length === 0) {
                sel.innerHTML = '<option value="">Select category first…</option>';
            } else {
                let opts = '<option value="">Select subcategory…</option>';
                filtered.forEach(s => {
                    opts += `<option value="${s.id}" ${String(s.id) === String(selectedSubId) ? 'selected' : ''}>${s.subcategory_name}</option>`;
                });
                sel.innerHTML = opts;
            }
            // Keep the custom dropdown panel's option list/label in sync
            // with the options just rebuilt above.
            if (selectId === 'add_subcategory_id' && typeof addSubcategoryDD !== 'undefined') addSubcategoryDD.refresh();
            if (selectId === 'edit_subcategory_id' && typeof editSubcategoryDD !== 'undefined') editSubcategoryDD.refresh();
        }

        function onCategoryChange(prefix) {
            const categoryId = document.getElementById(`${prefix}_category_id`).value;
            populateSubcategorySelect(`${prefix}_subcategory_id`, categoryId, '');
        }

        // Given a subcategory_id, find which category it belongs to (used when editing)
        function categoryIdForSubcategory(subcategoryId) {
            const match = JOB_SUBCATEGORIES.find(s => String(s.id) === String(subcategoryId));
            return match ? match.category_id : '';
        }

        // Populate the (static) category dropdowns once on page load
        populateCategorySelect('add_category_id');
        populateCategorySelect('edit_category_id');

        /* ── Live date + time ────────────────────────────────────────────── */
        function updateLiveDateTime() {
            const el = document.getElementById('live-date');
            if (!el) return;

            const now = new Date();

            const datePart = now.toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

            const timePart = now.toLocaleTimeString('en-PH', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            }).toLowerCase();

            el.textContent = `${datePart} | ${timePart}`;
        }

        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000); // refresh every second

        //  Dock flyout  
        function toggleFlyout(e, id) {
            e.stopPropagation();
            const el = document.getElementById(id);
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', () => document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open')));

        //  Modal helpers 
        function openAddModal() {
            const form = document.getElementById('addForm');
            if (form) form.reset();
            setRequirementRows('addRequirementsWrap', []);
            setQuestionRows('addQuestionsWrap', []);
            setQualRows('addQualWrap', []);
            setSkillRows('addSkillsWrap', []);
            document.getElementById('addModal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
        }

        function openEditModal() {
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function requirementRowHTML(doc = {}) {
            const rowKey = String(doc.row_key || Date.now() + Math.floor(Math.random() * 10000));
            const type = escHtml(doc.document_type || '');
            const name = escHtml(doc.document_name || '');
            const description = escHtml(doc.description || '');
            const checked = Number(doc.is_required ?? 1) === 1 ? 'checked' : '';
            return `
                <div class="requirement-row grid grid-cols-1 md:grid-cols-12 gap-2 border border-gray-100 rounded-xl p-3 bg-gray-50">
                    <div class="md:col-span-3">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Type (slug)</label>
                        <input type="text" name="req_document_type[${rowKey}]" value="${type}" placeholder="e.g. passport, resume"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Document Name</label>
                        <input type="text" name="req_document_name[${rowKey}]" value="${name}" placeholder="e.g. Valid Passport"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Description (optional)</label>
                        <input type="text" name="req_description[${rowKey}]" value="${description}" placeholder="Extra details"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="md:col-span-1 flex md:flex-col items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-1 text-xs text-gray-700 mt-0 md:mt-6">
                            <input type="checkbox" name="req_is_required[${rowKey}]" value="1" ${checked}>
                            Required
                        </label>
                        <button type="button" class="remove-requirement-row text-red-600 hover:text-red-700 text-xs font-semibold">Remove</button>
                    </div>
                </div>`;
        }

        function questionRowHTML(q = {}) {
            const rowKey = String(q.row_key || Date.now() + Math.floor(Math.random() * 10000));
            const questionText = escHtml(q.question_text || '');
            const checked = Number(q.is_required ?? 1) === 1 ? 'checked' : '';
            const orderNo = (q.order_no ?? '') === null ? '' : escHtml(String(q.order_no ?? ''));
            return `
                <div class="question-row grid grid-cols-1 md:grid-cols-12 gap-2 border border-gray-100 rounded-xl p-3 bg-gray-50">
                    <div class="md:col-span-8">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Question text</label>
                        <input type="text" name="job_question_text[${rowKey}]" value="${questionText}" placeholder="Enter question for applicant"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Order #</label>
                        <input type="number" min="1" name="job_question_order[${rowKey}]" value="${orderNo}" placeholder="Auto"
                            class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="md:col-span-2 flex md:flex-col items-center justify-between gap-2">
                        <label class="inline-flex items-center gap-1 text-xs text-gray-700 mt-0 md:mt-6">
                            <input type="checkbox" name="job_question_required[${rowKey}]" value="1" ${checked}>
                            Required
                        </label>
                        <button type="button" class="remove-question-row text-red-600 hover:text-red-700 text-xs font-semibold">Remove</button>
                    </div>
                </div>`;
        }

        function bindRequirementRemoveButtons(scopeEl) {
            if (!scopeEl) return;
            scopeEl.querySelectorAll('.remove-requirement-row').forEach(btn => {
                if (btn.dataset.bound === '1') return;
                btn.dataset.bound = '1';
                btn.addEventListener('click', () => {
                    const row = btn.closest('.requirement-row');
                    if (row) row.remove();
                });
            });
        }

        function appendRequirementRow(containerId, doc = {}) {
            const wrap = document.getElementById(containerId);
            if (!wrap) return;
            wrap.insertAdjacentHTML('beforeend', requirementRowHTML(doc));
            bindRequirementRemoveButtons(wrap);
        }

        function setRequirementRows(containerId, docs = []) {
            const wrap = document.getElementById(containerId);
            if (!wrap) return;
            wrap.innerHTML = '';
            if (!Array.isArray(docs) || docs.length === 0) {
                appendRequirementRow(containerId, {
                    is_required: 1
                });
                return;
            }
            docs.forEach(doc => appendRequirementRow(containerId, doc));
        }

        function bindQuestionRemoveButtons(scopeEl) {
            if (!scopeEl) return;
            scopeEl.querySelectorAll('.remove-question-row').forEach(btn => {
                if (btn.dataset.bound === '1') return;
                btn.dataset.bound = '1';
                btn.addEventListener('click', () => {
                    const row = btn.closest('.question-row');
                    if (row) row.remove();
                });
            });
        }

        function appendQuestionRow(containerId, q = {}) {
            const wrap = document.getElementById(containerId);
            if (!wrap) return;
            wrap.insertAdjacentHTML('beforeend', questionRowHTML(q));
            bindQuestionRemoveButtons(wrap);
        }

        function setQuestionRows(containerId, qs = []) {
            const wrap = document.getElementById(containerId);
            if (!wrap) return;
            wrap.innerHTML = '';
            if (!Array.isArray(qs) || qs.length === 0) {
                appendQuestionRow(containerId, {
                    is_required: 1
                });
                return;
            }
            qs.forEach(q => appendQuestionRow(containerId, q));
        }

        document.getElementById('addRequirementRowBtn')?.addEventListener('click', () => appendRequirementRow('addRequirementsWrap', {
            is_required: 1
        }));
        document.getElementById('editAddRequirementRowBtn')?.addEventListener('click', () => appendRequirementRow('editRequirementsWrap', {
            is_required: 1
        }));
        document.getElementById('addQuestionRowBtn')?.addEventListener('click', () => appendQuestionRow('addQuestionsWrap', {
            is_required: 1
        }));
        document.getElementById('editAddQuestionRowBtn')?.addEventListener('click', () => appendQuestionRow('editQuestionsWrap', {
            is_required: 1
        }));
        setRequirementRows('addRequirementsWrap', []);
        setQuestionRows('addQuestionsWrap', []);

        // APPLICANTS MODAL
        // Status label + badge class map
        const STATUS_META = {
            pending: {
                label: 'Pending',
                cls: 'badge-pending'
            },
            unassigned: {
                label: 'Unassigned',
                cls: 'badge-unassigned'
            },
            assigned: {
                label: 'Assigned',
                cls: 'badge-assigned'
            },
            under_evaluation: {
                label: 'Under Evaluation',
                cls: 'badge-under_evaluation'
            },
            for_compliance: {
                label: 'For Compliance',
                cls: 'badge-for_compliance'
            },
            approved: {
                label: 'Approved',
                cls: 'badge-approved'
            },
            disapproved: {
                label: 'Disapproved',
                cls: 'badge-disapproved'
            },
        };

        function statusBadge(status) {
            const s = (status || 'pending').toLowerCase();
            const m = STATUS_META[s] || {
                label: status,
                cls: 'badge-pending'
            };
            return `<span class="inline-block px-3 py-0.5 rounded-full text-xs font-semibold ${m.cls}">${m.label}</span>`;
        }

        function normalizeApplicantStatus(status) {
            const s = String(status ?? '').toLowerCase().trim().replace(/\s+/g, '_');
            if (!s) return '';
            if (s === 'unassign') return 'unassigned';
            return s;
        }

        function filterApplicantRows() {
            const sel = document.getElementById('appl-status-filter');
            const selectedStatus = normalizeApplicantStatus(sel ? sel.value : '');
            const rows = document.querySelectorAll('#appl-body .appl-row');
            let visibleCount = 0;
            rows.forEach(row => {
                const rowStatus = normalizeApplicantStatus(row.getAttribute('data-status'));
                const show = !selectedStatus || rowStatus === selectedStatus;
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            const emptyMsg = document.getElementById('appl-filter-empty');
            if (!emptyMsg) return;

            if (!selectedStatus || visibleCount > 0) {
                emptyMsg.classList.add('hidden');
                return;
            }

            const statusLabel = STATUS_META[selectedStatus]?.label || selectedStatus.replace(/_/g, ' ');
            emptyMsg.textContent = `No ${statusLabel.toLowerCase()} applications for this job order.`;
            emptyMsg.classList.remove('hidden');
        }

        let _currentEvaluators = []; // cached from last fetch

        async function openApplicantsModal(rsfId, rsfNumber, positionTitle) {
            const modal = document.getElementById('applicantsModal');
            document.getElementById('appl-rsf-label').textContent = rsfNumber;
            document.getElementById('appl-position-label').textContent = positionTitle;
            document.getElementById('appl-stats-bar').innerHTML = '';
            document.getElementById('appl-status-filter').value = '';
            document.getElementById('appl-body').innerHTML = `
                <div class="flex items-center justify-center h-32 text-gray-400">
                    <i class="fas fa-circle-notch spin text-2xl mr-3"></i> Loading applicants…
                </div>`;
            modal.classList.remove('hidden');

            try {
                const fd = new FormData();
                fd.append('action', 'get_applicants');
                fd.append('rsf_id', rsfId);
                const res = await fetch('job-orders.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();

                if (data.status !== 'success') throw new Error(data.message || 'Failed to load.');
                _currentEvaluators = data.evaluators || [];
                renderApplicants(data.applicants, rsfId);
            } catch (err) {
                document.getElementById('appl-body').innerHTML =
                    `<div class="text-center py-16 text-red-500"><i class="fas fa-circle-exclamation mr-2"></i>${err.message}</div>`;
            }
        }

        function closeApplicantsModal() {
            document.getElementById('applicantsModal').classList.add('hidden');
        }

        function renderApplicants(applicants, rsfId) {
            const statsBar = document.getElementById('appl-stats-bar');
            const body = document.getElementById('appl-body');

            if (!applicants.length) {
                statsBar.innerHTML = '<span class="text-gray-400">No applicants yet.</span>';
                body.innerHTML = `<div class="text-center py-16 text-gray-400"><i class="fas fa-users-slash text-3xl mb-3 block"></i>No applicants for this job order.</div>`;
                return;
            }

            // Compute counts per status
            const counts = {};
            applicants.forEach(a => {
                const s = (a.status || 'pending').toLowerCase();
                counts[s] = (counts[s] || 0) + 1;
            });

            // Render stats bar
            const statChips = Object.entries(counts).map(([s, n]) => {
                const m = STATUS_META[s] || {
                    label: s
                };
                return `<span class="${STATUS_META[s]?.cls || ''} px-3 py-1 rounded-full text-xs font-semibold">${m.label}: ${n}</span>`;
            }).join('');
            statsBar.innerHTML = `<span class="text-gray-500 font-medium mr-2">Total: ${applicants.length}</span>${statChips}`;

            // Build evaluator <option> list once
            const evalOptions = _currentEvaluators.map(e =>
                `<option value="${e.eid}">${e.full_name}</option>`
            ).join('');

            // Render table
            const rows = applicants.map(a => {
                const isUnassigned = ['unassign', 'pending'].includes((a.status || '').toLowerCase());
                const assignCell = isUnassigned ?
                    `<div class="assign-row flex items-center gap-2">
                            <select id="sel-${a.application_id}" class="text-xs border border-gray-200 rounded-xl px-2 py-1.5 focus:outline-none focus:border-indigo-400">
                                <option value="">— Select evaluator —</option>
                                ${evalOptions}
                            </select>
                            <button onclick="assignEvaluator(${a.application_id}, ${rsfId})"
                                class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-xl font-medium transition whitespace-nowrap">
                                Assign
                            </button>
                       </div>` :
                    `<span class="text-gray-700 text-xs font-medium">
                            ${a.evaluator_name
                                ? `<i class="fas fa-user-check text-green-500 mr-1"></i>${a.evaluator_name}`
                                : '<span class="text-gray-400">—</span>'}
                       </span>`;

                return `
                    <tr class="hover:bg-gray-50 transition appl-row" id="appl-row-${a.application_id}" data-status="${normalizeApplicantStatus(a.status)}">
                        <!--<td class="px-4 py-4 text-xs text-gray-400 font-mono">#${a.application_id}</td>-->
                        <td class="px-4 py-4">
                            <div class="font-semibold text-gray-800 text-sm">${escHtml(a.client_name)}</div>
                            <div class="text-xs text-gray-400">${escHtml(a.client_email)}</div>
                        </td>
                        <td class="px-4 py-4 text-xs text-gray-500">${escHtml(a.client_phone || '—')}</td>
                        <td class="px-4 py-4">${statusBadge(a.status)}</td>
                        <td class="px-4 py-4">${assignCell}</td>
                        <td class="px-4 py-4 text-xs text-gray-400 whitespace-nowrap">${formatDate(a.submitted_at)}</td>
                    </tr>`;
            }).join('');

            body.innerHTML = `
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px] text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <!--<th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-400">ID</th>-->
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-400">Applicant</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-400">Phone</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-400">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-400">Evaluator / Assign</th>
                                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-widest text-gray-400">Submitted</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">${rows}</tbody>
                    </table>
                </div>
                <div id="appl-filter-empty" class="hidden text-center py-10 text-sm text-gray-500">
                    No matching applications for this job order.
                </div>`;

            filterApplicantRows();
        }

        async function assignEvaluator(applicationId, rsfId) {
            const sel = document.getElementById(`sel-${applicationId}`);
            const evaluatorId = sel ? sel.value : '';
            if (!evaluatorId) {
                Swal.fire({
                    title: 'Select Evaluator',
                    text: 'Please choose an evaluator from the dropdown.',
                    icon: 'warning',
                    confirmButtonColor: '#4f46e5'
                });
                return;
            }

            const btn = sel.nextElementSibling;
            const origText = btn.innerHTML;
            btn.innerHTML = `<i class="fas fa-circle-notch spin"></i>`;
            btn.disabled = true;

            try {
                const fd = new FormData();
                fd.append('action', 'assign_evaluator');
                fd.append('application_id', applicationId);
                fd.append('evaluator_id', evaluatorId);
                const res = await fetch('job-orders.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();

                if (data.status === 'success') {
                    // Update the row in the modal without full reload
                    const row = document.getElementById(`appl-row-${applicationId}`);
                    if (row) {
                        // Update status cell
                        row.querySelector('td:nth-child(4)').innerHTML = statusBadge('assigned');
                        row.setAttribute('data-status', 'assigned');
                        // Replace assign cell with evaluator name
                        row.querySelector('td:nth-child(5)').innerHTML =
                            `<span class="text-gray-700 text-xs font-medium">
                                <i class="fas fa-user-check text-green-500 mr-1"></i>${escHtml(data.evaluator_name)}
                             </span>`;
                    }
                    filterApplicantRows();
                    // Refresh stats bar by re-fetching (lightweight)
                    Swal.fire({
                        title: 'Assigned!',
                        text: data.message,
                        icon: 'success',
                        timer: 1800,
                        timerProgressBar: true,
                        showConfirmButton: false
                    });
                } else {
                    throw new Error(data.message || 'Assignment failed.');
                }
            } catch (err) {
                Swal.fire({
                    title: 'Error',
                    text: err.message,
                    icon: 'error',
                    confirmButtonColor: '#d33'
                });
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }

        /*  Utility helpers  */
        function escHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function formatDate(dt) {
            if (!dt) return '—';
            return new Date(dt).toLocaleDateString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        /*  Add Form */
        document.getElementById('addForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const origTxt = submitBtn.innerHTML;
            submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Saving…`;
            submitBtn.disabled = true;
            try {
                const res = await fetch('job-orders.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    Swal.fire({
                            title: 'Success!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonColor: '#1e40af',
                            timer: 2500,
                            timerProgressBar: true
                        })
                        .then(() => {
                            closeAddModal();
                            location.reload();
                        });
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: data.message || 'Failed to save.',
                        icon: 'error',
                        confirmButtonColor: '#d33'
                    });
                }
            } catch (err) {
                Swal.fire({
                    title: 'Oops…',
                    text: err.message || 'Something went wrong.',
                    icon: 'error'
                });
            } finally {
                submitBtn.innerHTML = origTxt;
                submitBtn.disabled = false;
            }
        });

        /*  Edit Form */
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const origTxt = submitBtn.innerHTML;
            submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Updating…`;
            submitBtn.disabled = true;
            try {
                const res = await fetch('job-orders.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    Swal.fire({
                            title: 'Updated!',
                            text: data.message,
                            icon: 'success',
                            timer: 2000,
                            timerProgressBar: true
                        })
                        .then(() => {
                            closeEditModal();
                            location.reload();
                        });
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: data.message || 'Failed to update.',
                        icon: 'error'
                    });
                }
            } catch (err) {
                Swal.fire({
                    title: 'Oops…',
                    text: err.message || 'Something went wrong.',
                    icon: 'error'
                });
            } finally {
                submitBtn.innerHTML = origTxt;
                submitBtn.disabled = false;
            }
        });

        function editJob(id) {
            const row = document.querySelector(`tr.job-row[data-id="${id}"]`);
            if (!row) return;
            const job = JSON.parse(row.getAttribute('data-job') || '{}');
            const docs = JSON.parse(row.getAttribute('data-docs') || '[]');
            const questions = JSON.parse(row.getAttribute('data-questions') || '[]');
            document.getElementById('editId').value = job.id;
            document.getElementById('edit_rsf_number').value = job.rsf_number || '';
            document.getElementById('edit_employer_name').value = job.employer_name || '';
            document.getElementById('edit_worksite_country').value = job.worksite_country || '';
            document.getElementById('edit_classification').value = job.classification || '';
            if (typeof editClassificationDD !== 'undefined') editClassificationDD.refresh();
            document.getElementById('edit_position_title').value = job.position_title || '';
            document.getElementById('edit_slots_available').value = job.slots_available || '';
            document.getElementById('edit_deadline').value = job.deadline || '';
            document.getElementById('edit_qualifications').value = job.qualifications || '';
            document.getElementById('edit_salary_min').value = job.salary_min || '';
            document.getElementById('edit_salary_max').value = job.salary_max || '';

            // Pre-fill current currency in the searchable input
            const editCurrencyInput = document.getElementById('edit_salary_currency');
            if (editCurrencyInput) {
                editCurrencyInput.value = (job.salary_currency || 'PHP').toUpperCase();
            }

            updatePhpPreview('edit');
            updatePhpPreview('edit');
            const editCategoryId = categoryIdForSubcategory(job.subcategory_id);
            document.getElementById('edit_category_id').value = editCategoryId;
            if (typeof editCategoryDD !== 'undefined') editCategoryDD.refresh();
            populateSubcategorySelect('edit_subcategory_id', editCategoryId, job.subcategory_id || '');
            document.querySelector('#editForm input[name="rsf_pdf"]').value = '';
            setRequirementRows('editRequirementsWrap', Array.isArray(docs) ? docs : []);
            setQuestionRows('editQuestionsWrap', Array.isArray(questions) ? questions : []);
            const quals = JSON.parse(row.getAttribute('data-quals') || '[]');
            setQualRows('editQualWrap', Array.isArray(quals) ? quals : []);
            const skills = JSON.parse(row.getAttribute('data-skills') || '[]');
            setSkillRows('editSkillsWrap', Array.isArray(skills) ? skills : []);
            openEditModal();
        }

        function deleteJob(id) {
            Swal.fire({
                    title: 'Delete Job Order?',
                    text: 'This action cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, delete it'
                })
                .then(async result => {
                    if (!result.isConfirmed) return;
                    try {
                        const fd = new FormData();
                        fd.append('action', 'delete');
                        fd.append('id', id);
                        const res = await fetch('job-orders.php', {
                            method: 'POST',
                            body: fd
                        });
                        const data = await res.json();
                        if (data.status === 'success') {
                            Swal.fire({
                                    title: 'Deleted!',
                                    text: data.message,
                                    icon: 'success',
                                    timer: 1500,
                                    timerProgressBar: true
                                })
                                .then(() => location.reload());
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.message || 'Failed to delete.',
                                icon: 'error'
                            });
                        }
                    } catch (e) {
                        Swal.fire({
                            title: 'Error',
                            text: 'Something went wrong.',
                            icon: 'error'
                        });
                    }
                });
        }

        /*  Client-side filter + pagination  */
        const JOBS_PER_PAGE = 10;
        let jobsPage = 1;
        let currentFilteredRows = [];

        function computeFilteredRows() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const country = document.getElementById('filterCountry').value;
            return Array.from(document.querySelectorAll('tr.job-row')).filter(row => {
                const matchSearch = !search || (row.getAttribute('data-search') || '').includes(search);
                const matchCountry = !country || (row.getAttribute('data-country') || '') === country;
                return matchSearch && matchCountry;
            });
        }

        function applyFilters() {
            currentFilteredRows = computeFilteredRows();
            jobsPage = 1;
            renderJobsPage();
        }

        function renderJobsPage() {
            document.querySelectorAll('tr.job-row').forEach(row => row.style.display = 'none');

            const total = currentFilteredRows.length;
            const totalPages = Math.max(1, Math.ceil(total / JOBS_PER_PAGE));
            if (jobsPage > totalPages) jobsPage = totalPages;

            const start = (jobsPage - 1) * JOBS_PER_PAGE;
            const pageRows = currentFilteredRows.slice(start, start + JOBS_PER_PAGE);
            pageRows.forEach(row => row.style.display = '');

            const nr = document.getElementById('noResultsRow');
            if (nr) nr.classList.toggle('hidden', total > 0);

            const countLine = document.getElementById('jobsCountLine');
            if (countLine) {
                if (total === 0) {
                    countLine.textContent = '0 job orders found';
                } else {
                    const from = start + 1;
                    const to = Math.min(start + JOBS_PER_PAGE, total);
                    countLine.textContent = `Showing ${from}–${to} of ${total} job order${total !== 1 ? 's' : ''} · Page ${jobsPage} of ${totalPages}`;
                }
            }

            renderJobsPagination(totalPages, jobsPage);
        }

        function goToJobsPage(p) {
            if (p < 1) return;
            jobsPage = p;
            renderJobsPage();
            document.querySelector('.jo-table-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        function renderJobsPagination(totalPages, page) {
            let wrap = document.getElementById('jobsPaginationWrap');
            if (totalPages <= 1) {
                if (wrap) wrap.remove();
                return;
            }

            let linksHtml = `<button type="button" onclick="goToJobsPage(${Math.max(1, page - 1)})"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-semibold transition ${page <= 1 ? 'pointer-events-none opacity-40' : 'bg-gray-100 hover:bg-gray-200 text-gray-600'}">
                <i class="fas fa-chevron-left"></i></button>`;

            for (let p = 1; p <= totalPages; p++) {
                linksHtml += `<button type="button" onclick="goToJobsPage(${p})"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-semibold transition ${p === page ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-600'}">${p}</button>`;
            }

            linksHtml += `<button type="button" onclick="goToJobsPage(${Math.min(totalPages, page + 1)})"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-semibold transition ${page >= totalPages ? 'pointer-events-none opacity-40' : 'bg-gray-100 hover:bg-gray-200 text-gray-600'}">
                <i class="fas fa-chevron-right"></i></button>`;

            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'jobsPaginationWrap';
                wrap.className = 'flex items-center justify-center gap-1.5 mt-5';
                document.querySelector('.jo-table-card').insertAdjacentElement('afterend', wrap);
            }
            wrap.innerHTML = linksHtml;
        }

        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('filterCountry').addEventListener('change', applyFilters);
        applyFilters();

        const QUAL_TYPE_LABELS = {
            min_age: {
                label: 'Minimum Age',
                unit: 'years',
                type: 'number',
                min: 18,
                max: 70
            },
            max_age: {
                label: 'Maximum Age',
                unit: 'years',
                type: 'number',
                min: 18,
                max: 70
            },
            gender: {
                label: 'Gender',
                unit: '',
                type: 'select',
                options: ['Male', 'Female', 'Any']
            },
            min_height_cm: {
                label: 'Minimum Height',
                unit: 'cm',
                type: 'number',
                min: 100,
                max: 250
            },
            max_height_cm: {
                label: 'Maximum Height',
                unit: 'cm',
                type: 'number',
                min: 100,
                max: 250
            },
            min_weight_kg: {
                label: 'Minimum Weight',
                unit: 'kg',
                type: 'number',
                min: 30,
                max: 200
            },
            max_weight_kg: {
                label: 'Maximum Weight',
                unit: 'kg',
                type: 'number',
                min: 30,
                max: 200
            },
            min_education: {
                label: 'Min. Education',
                unit: '',
                type: 'select',
                options: [
                    'Elementary Graduate', 'High School Graduate', 'Senior High School Graduate',
                    'Vocational/Technical', 'College Undergraduate', 'College Graduate', 'Post Graduate'
                ]
            },
            civil_status: {
                label: 'Civil Status',
                unit: '',
                type: 'select',
                options: [
                    'Single', 'Married', 'Widowed', 'Separated', 'Annulled', 'Any'
                ]
            },
            required_degree: {
                label: 'Required Degree',
                unit: '',
                type: 'select',
                options: DEGREE_PROGRAMS
            },
            required_license: {
                label: 'Professional License',
                unit: '',
                type: 'select',
                options: PROFESSIONAL_LICENSES
            },
        };

        function buildTypeSelect(selectedType) {
            let opts = '<option value="">Select type…</option>';
            for (const [k, v] of Object.entries(QUAL_TYPE_LABELS)) {
                opts += `<option value="${k}" ${selectedType === k ? 'selected' : ''}>${v.label}</option>`;
            }
            return `<select name="qual_type[]" onchange="onQualTypeChange(this)"
        class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-amber-500">${opts}</select>`;
        }

        function buildValueInput(type, value) {
            if (!type || !QUAL_TYPE_LABELS[type]) {
                return `<input type="text" name="qual_value[]" value="${value || ''}" placeholder="Value"
            class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-amber-500">`;
            }
            const meta = QUAL_TYPE_LABELS[type];
            if (meta.type === 'select') {
                let opts = meta.options.map(o => `<option ${o === value ? 'selected' : ''}>${o}</option>`).join('');
                return `<select name="qual_value[]"
            class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-amber-500">${opts}</select>`;
            }
            const unit = meta.unit ? `<span class="text-xs text-gray-400 ml-1">${meta.unit}</span>` : '';
            return `<div class="flex items-center gap-1 flex-1">
        <input type="number" name="qual_value[]" value="${value || ''}"
            min="${meta.min || 0}" max="${meta.max || 9999}" step="1"
            placeholder="e.g. ${meta.min || 0}"
            class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-amber-500">
        ${unit}
    </div>`;
        }

        function onQualTypeChange(sel) {
            const row = sel.closest('.qual-row');
            row.querySelector('.qual-value-wrap').innerHTML = buildValueInput(sel.value, '');
        }

        function appendQualRow(containerId, type = '', value = '') {
            const wrap = document.getElementById(containerId);
            const div = document.createElement('div');
            div.className = 'qual-row flex items-start gap-2';
            div.innerHTML = `
        ${buildTypeSelect(type)}
        <div class="qual-value-wrap flex-1">${buildValueInput(type, value)}</div>
        <button type="button" onclick="this.closest('.qual-row').remove()"
            class="mt-1 text-red-400 hover:text-red-600 px-2 py-2 rounded-xl hover:bg-red-50 flex-shrink-0">
            <i class="fas fa-trash text-xs"></i>
        </button>
    `;
            wrap.appendChild(div);
        }

        function addQualRow(containerId) {
            appendQualRow(containerId, '', '');
        }

        function setQualRows(containerId, quals) {
            const wrap = document.getElementById(containerId);
            if (!wrap) return;
            wrap.innerHTML = '';
            (quals || []).forEach(q => appendQualRow(containerId, q.qualification_type, q.qualification_value));
        }

        // ── Required Skills (multi-select via repeatable rows) ─────────────────
        function buildSkillSelect(selectedId) {
            let opts = '<option value="">Select skill…</option>';
            let lastCategory = null;
            SKILLS_LIST.forEach(s => {
                const category = s.skill_category || 'Other';
                if (category !== lastCategory) {
                    if (lastCategory !== null) opts += '</optgroup>';
                    opts += `<optgroup label="${category}">`;
                    lastCategory = category;
                }
                opts += `<option value="${s.id}" ${String(selectedId) === String(s.id) ? 'selected' : ''}>${s.skill_name}</option>`;
            });
            if (lastCategory !== null) opts += '</optgroup>';
            return `<select name="skill_id[]"
        class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-cyan-500">${opts}</select>`;
        }

        function buildImportanceSelect(selected) {
            const opts = ['Required', 'Preferred']
                .map(o => `<option ${o === selected ? 'selected' : ''}>${o}</option>`).join('');
            return `<select name="skill_importance[]"
        class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-cyan-500">${opts}</select>`;
        }

        function buildProficiencySelect(selected) {
            const opts = ['Beginner', 'Intermediate', 'Advanced', 'Expert']
                .map(o => `<option ${o === selected ? 'selected' : ''}>${o}</option>`).join('');
            return `<select name="skill_min_proficiency[]"
        class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-cyan-500">${opts}</select>`;
        }

        function appendSkillRow(containerId, skillId = '', importance = 'Required', minProficiency = 'Beginner') {
            const wrap = document.getElementById(containerId);
            const div = document.createElement('div');
            div.className = 'skill-row flex items-start gap-2';
            div.innerHTML = `
        ${buildSkillSelect(skillId)}
        ${buildImportanceSelect(importance)}
        ${buildProficiencySelect(minProficiency)}
        <button type="button" onclick="this.closest('.skill-row').remove()"
            class="mt-1 text-red-400 hover:text-red-600 px-2 py-2 rounded-xl hover:bg-red-50 flex-shrink-0">
            <i class="fas fa-trash text-xs"></i>
        </button>
    `;
            wrap.appendChild(div);
        }

        function addSkillRow(containerId) {
            appendSkillRow(containerId, '', 'Required', 'Beginner');
        }

        function setSkillRows(containerId, skills) {
            const wrap = document.getElementById(containerId);
            if (!wrap) return;
            wrap.innerHTML = '';
            (skills || []).forEach(s => appendSkillRow(containerId, s.skill_id, s.importance, s.min_proficiency));
        }

        function openAddModal() {
            const form = document.getElementById('addForm');
            if (form) form.reset();
            setRequirementRows('addRequirementsWrap', []);
            setQuestionRows('addQuestionsWrap', []);
            setQualRows('addQualWrap', []);
            setSkillRows('addSkillsWrap', []);
            const addPreview = document.getElementById('add_salary_php_preview');
            if (addPreview) addPreview.textContent = '';
            document.getElementById('add_category_id').value = '';
            if (typeof addCategoryDD !== 'undefined') addCategoryDD.refresh();
            if (typeof addClassificationDD !== 'undefined') addClassificationDD.refresh();
            populateSubcategorySelect('add_subcategory_id', '', '');
            document.getElementById('addModal').classList.remove('hidden');

            // Fetch the next auto-generated RSF number for preview
            const preview = document.getElementById('rsf_number_preview');
            if (preview) {
                preview.value = 'Generating…';
                const fd = new FormData();
                fd.append('action', 'peek_rsf_number');
                fetch('job-orders.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(data => {
                        preview.value = data.status === 'success' ? data.rsf_number : 'DMW-RSF-XXXXXX-XXXX';
                    })
                    .catch(() => {
                        preview.value = 'DMW-RSF-XXXXXX-XXXX';
                    });
            }
        }

        const AVAILABLE_CURRENCIES = <?= json_encode($allCurrencies) ?>;

        // Populate datalists
        function populateCurrencyDatalists() {
            const addList = document.getElementById('currencyList');
            const editList = document.getElementById('currencyListEdit');

            const options = AVAILABLE_CURRENCIES.map(code =>
                `<option value="${code}">${code}</option>`
            ).join('');

            if (addList) addList.innerHTML = options;
            if (editList) editList.innerHTML = options;
        }

        function setupCurrencyInputs() {
            const ids = ['add_salary_currency', 'edit_salary_currency'];
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('blur', function() {
                        this.value = this.value.toUpperCase().trim();
                        const prefix = this.id.includes('add') ? 'add' : 'edit';
                        updatePhpPreview(prefix);
                    });
                }
            });
        }

        async function refreshExchangeRates() {
            const btn = event.currentTarget;
            const original = btn.innerHTML;
            btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Updating...`;
            btn.disabled = true;

            try {
                const fd = new FormData();
                fd.append('action', 'refresh_rates');
                const res = await fetch('job-orders.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();

                if (data.status === 'success') {
                    Swal.fire({
                        title: 'Success!',
                        text: data.message,
                        icon: 'success',
                        timer: 2000
                    }).then(() => location.reload());
                } else {
                    throw new Error(data.message);
                }
            } catch (err) {
                Swal.fire('Error', err.message, 'error');
            } finally {
                btn.innerHTML = original;
                btn.disabled = false;
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            populateCurrencyDatalists();
            setupCurrencyInputs();
        });
    </script>

    <script>
        function enhanceAdminSelect(select, opts) {
            opts = opts || {};
            const includeEmpty = !!opts.includeEmpty;
            const placeholderOpt = select.querySelector('option[value=""]');
            const placeholder = placeholderOpt ? placeholderOpt.textContent : 'Select…';

            const wrapper = document.createElement('div');
            wrapper.className = 'relative w-full md:w-64';
            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.classList.add('hidden');
            select.tabIndex = -1;

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.className = 'w-full px-3 py-2 sm:px-4 sm:py-2.5 md:px-5 md:py-3 text-xs sm:text-sm md:text-base border border-gray-200 rounded-xl md:rounded-2xl focus:outline-none bg-white flex items-center justify-between gap-2 cursor-pointer transition-colors hover:border-blue-300';
            trigger.innerHTML = '<span class="trigger-label truncate text-gray-500"></span>' +
                '<i class="fas fa-chevron-down text-[11px] text-gray-400 transition-transform flex-shrink-0"></i>';
            wrapper.appendChild(trigger);

            const panel = document.createElement('div');
            panel.setAttribute('role', 'listbox');
            panel.className = 'hidden absolute left-0 right-0 mt-1.5 bg-white border border-gray-200 rounded-xl md:rounded-2xl shadow-[0_10px_30px_rgba(16,24,40,.12)] z-[60] overflow-hidden py-1 max-h-[260px] overflow-y-auto';
            wrapper.appendChild(panel);

            function buildRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.className = 'flex items-center justify-between gap-2 py-2 px-4 text-xs sm:text-sm text-gray-700 cursor-pointer hover:bg-gray-50 transition-colors';
                row.dataset.value = optionEl.value;
                const label = document.createElement('span');
                label.className = 'truncate';
                label.textContent = optionEl.textContent;
                row.appendChild(label);
                row.addEventListener('click', () => {
                    select.value = optionEl.value;
                    closePanel();
                    refresh();
                    select.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                });
                return row;
            }

            function renderList() {
                panel.innerHTML = '';
                Array.from(select.options).forEach(opt => {
                    if (opt.value === '' && !includeEmpty) return;
                    panel.appendChild(buildRow(opt));
                });
                markSelected();
            }

            function markSelected() {
                panel.querySelectorAll('[role="option"]').forEach(row => {
                    const selected = row.dataset.value === select.value;
                    row.classList.toggle('bg-blue-50', selected);
                    row.classList.toggle('text-blue-700', selected);
                    row.classList.toggle('font-semibold', selected);
                    let check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check text-[10px] text-blue-600 flex-shrink-0';
                        row.appendChild(icon);
                    } else if (!selected && check) {
                        check.remove();
                    }
                });
            }

            function refresh() {
                const opt = select.options[select.selectedIndex];
                const label = trigger.querySelector('.trigger-label');
                if (select.value === '' || !opt) {
                    label.textContent = placeholder;
                    label.classList.add('text-gray-500');
                    label.classList.remove('text-gray-900', 'font-medium');
                } else {
                    label.textContent = opt.textContent;
                    label.classList.remove('text-gray-500');
                    label.classList.add('text-gray-900', 'font-medium');
                }
                markSelected();
            }

            function openPanel() {
                document.querySelectorAll('[role="listbox"]').forEach(p => {
                    if (p !== panel) p.classList.add('hidden');
                });
                renderList();
                panel.classList.remove('hidden');
                trigger.classList.add('border-blue-400');
                trigger.setAttribute('aria-expanded', 'true');
                trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
            }

            function closePanel() {
                panel.classList.add('hidden');
                trigger.classList.remove('border-blue-400');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
            }

            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                panel.classList.contains('hidden') ? openPanel() : closePanel();
            });
            trigger.addEventListener('keydown', (e) => {
                if (['Enter', ' ', 'ArrowDown'].includes(e.key)) {
                    e.preventDefault();
                    openPanel();
                }
            });
            panel.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closePanel();
                    trigger.focus();
                }
            });
            panel.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', closePanel);

            refresh();
            return {
                refresh,
                closePanel
            };
        }

        // "All Countries" is a real, selectable reset option here — not a
        // placeholder — so includeEmpty keeps it in the list.
        enhanceAdminSelect(document.getElementById('filterCountry'), {
            includeEmpty: true
        });

        // ── RSF Add/Edit modal dropdowns — same floating-panel design ──
        const addCategoryDD = enhanceAdminSelect(document.getElementById('add_category_id'));
        const editCategoryDD = enhanceAdminSelect(document.getElementById('edit_category_id'));
        const addSubcategoryDD = enhanceAdminSelect(document.getElementById('add_subcategory_id'));
        const editSubcategoryDD = enhanceAdminSelect(document.getElementById('edit_subcategory_id'));
        const addClassificationDD = enhanceAdminSelect(document.querySelector('#addForm select[name="classification"]'));
        const editClassificationDD = enhanceAdminSelect(document.getElementById('edit_classification'));
    </script>
    <script type="module" src="../js/main.js"></script>
</body>

</html>