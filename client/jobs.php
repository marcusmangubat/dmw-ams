<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

// Fetch client ID
$client_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();


if (!$client) {
    header('Location: ../login.php');
    exit;
}
$client_id = (int) $client['cid'];
// Fetch client profile + dob/gender for qualification matching
$clientData = null;
$stmt = $database->prepare("
    SELECT c.dob, c.gender,
           cp.civil_status, cp.height_cm, cp.weight_kg, cp.educational_attainment
    FROM clients c
    LEFT JOIN client_profiles cp ON cp.client_id = c.cid
    WHERE c.cid = ?
    LIMIT 1
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$clientData = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate age from dob
$clientAge = null;
if (!empty($clientData['dob'])) {
    $clientAge = (int) date_diff(date_create($clientData['dob']), date_create('today'))->y;
}
$app_count = 0;
$stmt = $database->prepare("SELECT COUNT(*) as c FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$app_count = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Job preference gate ──────────────────────────────────────────────────
// Applicants must set their job preferences once before browsing the job list.
// Existence of a client_preferences row = preferences have been set.
$stmt = $database->prepare("
    SELECT preferred_classification, min_salary_expected, salary_currency,
           preferred_employment_type, willing_to_relocate
    FROM client_preferences
    WHERE client_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$clientPrefs = $stmt->get_result()->fetch_assoc();
$stmt->close();

$has_preferences = (bool) $clientPrefs;
$show_pref_modal  = !$has_preferences;

$prefCountries = [];
$prefPositions = [];
if ($has_preferences) {
    $stmt = $database->prepare("
        SELECT country, priority_order
        FROM client_preferred_countries
        WHERE client_id = ?
        ORDER BY priority_order ASC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $prefCountries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $database->prepare("
        SELECT cpp.subcategory_id, cpp.priority_order, js.subcategory_name
        FROM client_preferred_positions cpp
        JOIN job_subcategories js ON js.id = cpp.subcategory_id
        WHERE cpp.client_id = ?
        ORDER BY cpp.priority_order ASC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $prefPositions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Categories/subcategories for the preference modal's position picker
$prefCategories = [];
if ($show_pref_modal) {
    $catResult = $database->query("SELECT id, category_name FROM job_categories ORDER BY category_name ASC");
    $categories = $catResult ? $catResult->fetch_all(MYSQLI_ASSOC) : [];

    $subResult = $database->query("SELECT id, category_id, subcategory_name FROM job_subcategories ORDER BY subcategory_name ASC");
    $subcategories = $subResult ? $subResult->fetch_all(MYSQLI_ASSOC) : [];

    foreach ($categories as $cat) {
        $prefCategories[] = [
            'id'   => (int) $cat['id'],
            'name' => $cat['category_name'],
            'subs' => array_values(array_filter($subcategories, fn($s) => (int)$s['category_id'] === (int)$cat['id'])),
        ];
    }
}



// Fetch available job orders (include NULL deadline = open until filled)
$query = "
    SELECT * 
    FROM rsf_job_orders 
    WHERE (deadline IS NULL OR deadline >= CURDATE())
    ORDER BY created_at DESC
";
$jobOrders = [];
$result = @$database->query($query);
if ($result) $jobOrders = $result->fetch_all(MYSQLI_ASSOC);

// Fetch structured qualifications per job
$qualsByRsf = [];
$qResult = $database->query("SELECT rsf_id, qualification_type, qualification_value FROM rsf_qualifications ORDER BY id ASC");
if ($qResult) {
    while ($qRow = $qResult->fetch_assoc()) {
        $rid = (int)$qRow['rsf_id'];
        $qualsByRsf[$rid][] = $qRow;
    }
}

// Education rank for comparison
const EDU_RANK = [
    'Elementary Graduate'          => 1,
    'High School Graduate'         => 2,
    'Senior High School Graduate'  => 3,
    'Vocational/Technical'         => 4,
    'College Undergraduate'        => 5,
    'College Graduate'             => 6,
    'Post Graduate'                => 7,
];

function checkQualifications(array $clientData, int $clientAge = null, array $quals): array
{
    $fails = [];

    foreach ($quals as $q) {
        $type  = $q['qualification_type'];
        $value = $q['qualification_value'];

        switch ($type) {
            case 'min_age':
                if ($clientAge !== null && $clientAge < (int)$value)
                    $fails[] = "Must be at least $value years old (you are $clientAge)";
                break;

            case 'max_age':
                if ($clientAge !== null && $clientAge > (int)$value)
                    $fails[] = "Must not be older than $value years (you are $clientAge)";
                break;

            case 'gender':
                if (
                    !empty($clientData['gender']) && strtolower($value) !== 'any'
                    && strtolower($clientData['gender']) !== strtolower($value)
                )
                    $fails[] = "Must be $value";
                break;

            case 'min_height_cm':
                if (!empty($clientData['height_cm']) && (float)$clientData['height_cm'] < (float)$value)
                    $fails[] = "Minimum height is {$value} cm (your height: {$clientData['height_cm']} cm)";
                break;

            case 'max_height_cm':
                if (!empty($clientData['height_cm']) && (float)$clientData['height_cm'] > (float)$value)
                    $fails[] = "Maximum height is {$value} cm (your height: {$clientData['height_cm']} cm)";
                break;

            case 'min_weight_kg':
                if (!empty($clientData['weight_kg']) && (float)$clientData['weight_kg'] < (float)$value)
                    $fails[] = "Minimum weight is {$value} kg (your weight: {$clientData['weight_kg']} kg)";
                break;

            case 'max_weight_kg':
                if (!empty($clientData['weight_kg']) && (float)$clientData['weight_kg'] > (float)$value)
                    $fails[] = "Maximum weight is {$value} kg (your weight: {$clientData['weight_kg']} kg)";
                break;

            case 'min_education':
                $required = EDU_RANK[$value] ?? 0;
                $clientEdu = EDU_RANK[$clientData['educational_attainment'] ?? ''] ?? 0;
                if ($clientEdu > 0 && $clientEdu < $required)
                    $fails[] = "Minimum education required: $value";
                break;

            case 'civil_status':
                if (
                    !empty($clientData['civil_status']) && strtolower($value) !== 'any'
                    && strtolower($clientData['civil_status']) !== strtolower($value)
                )
                    $fails[] = "Must be $value";
                break;
        }
    }

    return [
        'eligible' => empty($fails),
        'reasons'  => $fails,
    ];
}

/**
 * Weighted preference-match score (0-100) for a job order against the
 * client's saved preferences. Components with no comparable data on
 * either side are excluded from the denominator so missing data never
 * unfairly penalizes a job.
 *
 * Weights: preferred country 35, preferred position 30, classification 15, salary 20.
 */
function computeMatchScore(array $job, ?array $prefs, array $prefCountries, array $prefPositions): int
{
    $earned = 0.0;
    $max    = 0.0;

    // Preferred country (ranked — priority 1 scores highest)
    if (!empty($prefCountries)) {
        $max += 35;
        foreach ($prefCountries as $pc) {
            if (!empty($job['worksite_country']) && strcasecmp($job['worksite_country'], $pc['country']) === 0) {
                $rank = (int) $pc['priority_order'];
                $earned += max(35 - ($rank - 1) * 12, 10);
                break;
            }
        }
    }

    // Preferred position/subcategory (ranked). Falls back to a fuzzy title
    // match (partial credit) when the job order hasn't been tagged with a
    // subcategory_id yet.
    if (!empty($prefPositions)) {
        $max += 30;
        $matched = false;
        foreach ($prefPositions as $pp) {
            if (!empty($job['subcategory_id']) && (int) $job['subcategory_id'] === (int) $pp['subcategory_id']) {
                $rank = (int) $pp['priority_order'];
                $earned += max(30 - ($rank - 1) * 10, 10);
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            foreach ($prefPositions as $pp) {
                if (
                    !empty($pp['subcategory_name']) && !empty($job['position_title'])
                    && stripos($job['position_title'], $pp['subcategory_name']) !== false
                ) {
                    $rank = (int) $pp['priority_order'];
                    $earned += max(30 - ($rank - 1) * 10, 10) * 0.6;
                    break;
                }
            }
        }
    }

    // Classification (Government/Private) — "Any" always matches
    if (!empty($prefs['preferred_classification'])) {
        $max += 15;
        if (
            strcasecmp($prefs['preferred_classification'], 'Any') === 0 ||
            strcasecmp($prefs['preferred_classification'], $job['classification'] ?? '') === 0
        ) {
            $earned += 15;
        }
    }

    // Minimum expected salary vs job's posted range (only when the job discloses salary)
    if (
        !empty($prefs['min_salary_expected']) && (float) $prefs['min_salary_expected'] > 0
        && (!empty($job['salary_min']) || !empty($job['salary_max']))
    ) {
        $max += 20;
        $want   = (float) $prefs['min_salary_expected'];
        $jobMax = (float) ($job['salary_max'] ?? $job['salary_min']);
        $jobMin = (float) ($job['salary_min'] ?? $job['salary_max']);
        if ($jobMax >= $want) {
            $earned += 20;
        } elseif ($jobMin >= $want * 0.8) {
            $earned += 10;
        }
    }

    if ($max <= 0) {
        return 50; // no comparable data at all — neutral score
    }

    return (int) round(min(100, max(0, ($earned / $max) * 100)));
}

// Attach preference-match score to each job order, then rank best matches first
foreach ($jobOrders as &$job) {
    $job['match_score'] = $has_preferences
        ? computeMatchScore($job, $clientPrefs, $prefCountries, $prefPositions)
        : null;
}
unset($job);

if ($has_preferences) {
    usort($jobOrders, function ($a, $b) {
        if ($a['match_score'] === $b['match_score']) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        }
        return $b['match_score'] <=> $a['match_score'];
    });
}

// Fetch RSF IDs the client has already applied to
$appliedRsfIds = [];
$stmt = $database->prepare("SELECT rsf_id FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$appliedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($appliedRows as $row) {
    $appliedRsfIds[] = (int) $row['rsf_id'];
}

// Get unique countries for filter
$countries = array_unique(array_filter(array_column($jobOrders, 'worksite_country')));
sort($countries);

// Curated list of common OFW destination countries, merged with whatever
// is already posted in open job orders, for the preference modal's picker.
$prefCountryOptions = [];
if ($show_pref_modal) {
    $commonCountries = [
        'Taiwan',
        'Japan',
        'South Korea',
        'Hong Kong',
        'Singapore',
        'Malaysia',
        'Saudi Arabia',
        'United Arab Emirates',
        'Qatar',
        'Kuwait',
        'Bahrain',
        'Oman',
        'Israel',
        'Federal Republic of Germany',
        'United Kingdom',
        'Italy',
        'Canada',
        'United States of America',
        'Australia',
        'New Zealand',
    ];
    $prefCountryOptions = array_values(array_unique(array_merge($commonCountries, $countries)));
    sort($prefCountryOptions);
}

// Avatar initials from session name
$full_name = $_SESSION['full_name'] ?? 'Client';
$parts     = explode(' ', trim($full_name));
$initials  = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($full_name, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Job Opportunities – DMW Applicant Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <!-- Shared dashboard stylesheet -->
    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        /* ── Page content area ── */
        .main {
            padding: 14px 14px 0;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ── Section label ── */
        .sec-lbl {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--text3);
            margin-bottom: 8px;
        }

        /* ── Filter bar ── */
        .filter-bar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        @media (min-width: 640px) {
            .filter-bar {
                flex-direction: row;
                align-items: center;
            }
        }

        .filter-search-wrap {
            flex: 1;
            position: relative;
        }

        .filter-search-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text3);
            font-size: 12px;
            pointer-events: none;
        }

        .filter-input {
            width: 100%;
            padding: 9px 12px 9px 34px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: var(--text1);
            background: var(--surface);
            outline: none;
            transition: border-color .2s;
        }

        .filter-input:focus {
            border-color: var(--blue-lt);
            background: #fff;
        }

        .filter-input::placeholder {
            color: var(--text3);
        }

        .filter-select {
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: var(--text2);
            background: var(--surface);
            outline: none;
            cursor: pointer;
            transition: border-color .2s;
        }

        .filter-select:focus {
            border-color: var(--blue-lt);
        }

        .filter-btn {
            padding: 9px 20px;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s;
            white-space: nowrap;
        }

        .filter-btn:hover {
            background: #1e40af;
        }

        /* ── Job cards grid ── */
        .jobs-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        @media (min-width: 640px) {
            .jobs-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .jobs-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* ── Job card ── */
        .job-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            transition: transform .2s, box-shadow .2s;
            position: relative;
        }

        .job-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--blue-lt);
            border-radius: 14px 14px 0 0;
        }

        .job-card.gov::before {
            background: var(--green);
        }

        .job-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 30px rgba(11, 31, 75, .10);
        }

        .job-card:active {
            transform: scale(.98);
        }

        /* Dim cards the client doesn't qualify for */
        .job-card.not-eligible {
            opacity: 0.6;
        }

        .job-card.not-eligible:hover {
            opacity: 1;
        }

        .jc-body {
            padding: 16px;
        }

        .jc-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
        }

        .jc-title {
            font-family: 'DM Serif Display', serif;
            font-size: 15px;
            color: var(--text1);
            line-height: 1.3;
        }

        .jc-employer {
            font-size: 11.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 3px;
        }

        .jc-badge {
            font-size: 9.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
            flex-shrink: 0;
            letter-spacing: .03em;
        }

        .badge-gov {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-prv {
            background: #eff6ff;
            color: #1e40af;
        }

        .jc-details {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 14px;
        }

        .jc-detail-row {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12px;
            color: var(--text2);
            font-weight: 500;
        }

        .jc-detail-row i {
            width: 16px;
            text-align: center;
            color: var(--text3);
            font-size: 11px;
            flex-shrink: 0;
        }

        .jc-detail-row strong {
            color: var(--text1);
            font-weight: 700;
        }

        .jc-actions {
            display: flex;
            gap: 8px;
        }

        .jc-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 10px;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: background .18s, transform .15s;
        }

        .jc-btn:active {
            transform: scale(.97);
        }

        .btn-apply {
            background: var(--blue);
            color: #fff;
        }

        .btn-apply:hover {
            background: #1e40af;
        }

        /* ── Already applied state ── */
        .btn-applied {
            background: #f1f5f9;
            color: #64748b;
            cursor: pointer;
        }

        .applied-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .03em;
            padding: 3px 9px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1e40af;
            white-space: nowrap;
        }

        /* ── Empty state ── */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 36px;
            color: var(--border);
            display: block;
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 14px;
            font-weight: 600;
            color: var(--text3);
        }

        .empty-state span {
            font-size: 12px;
            color: var(--text3);
            opacity: .7;
        }

        /* ── Fade-in ── */
        .fade-in {
            opacity: 0;
            transform: translateY(10px);
            animation: fi2 .4s ease forwards;
        }

        @keyframes fi2 {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .d1 {
            animation-delay: .05s;
        }

        .d2 {
            animation-delay: .1s;
        }

        .d3 {
            animation-delay: .15s;
        }

        /* ── Pagination ── */
        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 8px 0 18px;
        }

        .pagination-btn {
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text2);
            border-radius: 10px;
            padding: 8px 14px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s, color .2s, border-color .2s;
        }

        .pagination-btn:hover:not(:disabled) {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }

        .pagination-btn:disabled {
            cursor: not-allowed;
            opacity: .5;
        }

        .pagination-info {
            font-size: 12px;
            font-weight: 600;
            color: var(--text3);
            min-width: 90px;
            text-align: center;
        }

        .pagination-pages {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pagination-page-btn {
            min-width: 34px;
            height: 34px;
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text2);
            border-radius: 9px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s, color .2s, border-color .2s;
        }

        .pagination-page-btn:hover {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }

        .pagination-page-btn.active {
            background: #1d4ed8;
            color: #fff;
            border-color: #1d4ed8;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;

        }

        /* ── Modals (job preference gate) ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(11, 31, 75, .6);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 600;
            padding: 16px;
        }

        .modal-box {
            background: #fff;
            border-radius: 18px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 60px rgba(11, 31, 75, .3);
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: #fff;
            border-radius: 18px 18px 0 0;
            z-index: 2;
        }

        .modal-head h3 {
            font-family: 'DM Serif Display', serif;
            font-size: 16px;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .modal-body {
            padding: 18px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid var(--border);
            background: var(--surface);
            border-radius: 0 0 18px 18px;
        }

        .modal-btn {
            padding: 9px 20px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: 'DM Sans', sans-serif;
            transition: opacity .18s;
        }

        .modal-btn:hover {
            opacity: .88;
        }

        .modal-btn-secondary {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text2);
        }

        .modal-btn-primary {
            background: var(--blue);
            color: #fff;
        }

        .modal-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        /* Step indicator */
        .profile-steps {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 22px;
        }

        .step-node {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            flex-shrink: 0;
            transition: background .2s, color .2s;
        }

        .step-node.done {
            background: var(--blue);
            color: #fff;
        }

        .step-node.active {
            background: var(--navy);
            color: #fff;
        }

        .step-node.idle {
            background: var(--border);
            color: var(--text3);
        }

        .step-line {
            flex: 1;
            height: 2px;
            background: var(--border);
            transition: background .2s;
        }

        .step-line.done {
            background: var(--blue);
        }

        .step-panel {
            display: none;
        }

        .step-panel.active {
            display: block;
        }

        .pf-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .pf-full {
            grid-column: 1 / -1;
        }

        @media(max-width:500px) {
            .pf-grid {
                grid-template-columns: 1fr;
            }
        }

        .pf-field label {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text2);
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .pf-field input,
        .pf-field select {
            width: 100%;
            padding: 9px 11px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: var(--text1);
            background: #fff;
            transition: border-color .15s;
            box-sizing: border-box;
        }

        .pf-field input:focus,
        .pf-field select:focus {
            outline: none;
            border-color: var(--blue-lt);
        }

        .pf-field .req {
            color: #ef4444;
        }

        .pf-section-heading {
            font-family: 'DM Serif Display', serif;
            font-size: 15px;
            color: var(--navy);
            margin-bottom: 14px;
        }

        .pf-subtitle {
            font-size: 11.5px;
            color: var(--text3);
            margin-bottom: 18px;
            line-height: 1.55;
        }

        /* Chip picker (preferred countries / positions) */
        .chip-add-row {
            display: flex;
            gap: 8px;
        }

        .chip-add-row select {
            flex: 1;
        }

        .chip-add-btn {
            padding: 9px 14px;
            border-radius: 9px;
            border: 1.5px solid var(--blue);
            background: #eff6ff;
            color: var(--blue);
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }

        .chip-add-btn:hover {
            background: #dbeafe;
        }

        .chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            min-height: 34px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--navy);
            color: #fff;
            padding: 6px 8px 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .chip .chip-rank {
            background: rgba(255, 255, 255, .2);
            border-radius: 999px;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
        }

        .chip .chip-remove {
            cursor: pointer;
            opacity: .75;
            font-size: 11px;
        }

        .chip .chip-remove:hover {
            opacity: 1;
        }

        /* Lock banner shown while preferences are unset */
        .pref-lock-banner {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .pref-lock-banner i {
            color: var(--blue);
            font-size: 18px;
            margin-top: 2px;
        }

        .pref-lock-banner h4 {
            font-size: 12.5px;
            font-weight: 800;
            color: var(--navy);
        }

        .pref-lock-banner p {
            font-size: 11.5px;
            color: var(--text2);
            margin-top: 3px;
            line-height: 1.5;
        }
    </style>
</head>

<body>

    <!-- ══════════════════════════════════════
         TOPBAR
    ══════════════════════════════════════ -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; APPLICANT PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- ══════════════════════════════════════
         SUBBAR
    ══════════════════════════════════════ -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Job Opportunities (RSF)</h2>
                <p>(<?= count($jobOrders) ?>) open position<?= count($jobOrders) !== 1 ? 's' : '' ?> available<?= $has_preferences ? ' · sorted by best match to your preferences' : '' ?></p>
            </div>
        </div>

        <!-- Scrollable filter pills -->
        <div class="filter-scroll">
            <div class="filter-pill">
                <i class="fas fa-briefcase"></i>
                All Jobs
            </div>
            <div class="filter-pill">
                <i class="fas fa-building-columns"></i>
                Government
            </div>
            <!--<div class="filter-pill">
                <i class="fas fa-building"></i>
                Private
            </div>-->
            <?php foreach ($countries as $c): ?>
                <div class="filter-pill" onclick="filterByCountry('<?= htmlspecialchars($c, ENT_QUOTES) ?>')">
                    <i class="fas fa-location-dot"></i>
                    <?= htmlspecialchars($c) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════ -->
    <div class="main">

        <?php if ($show_pref_modal): ?>
            <div class="pref-lock-banner fade-in">
                <i class="fas fa-lock"></i>
                <div>
                    <h4>Set Your Job Preferences to Unlock Listings</h4>
                    <p>Tell us your preferred classification, destination countries, and positions so we can show you the best-matching job orders first.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search & Filter bar -->
        <div class="filter-bar fade-in d1">
            <div class="filter-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" class="filter-input"
                    placeholder="Search position, employer, country, RSF number…">
            </div>
            <select id="countryFilter" class="filter-select">
                <option value="">All Countries</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="filter-btn" onclick="applyFilters()">
                <i class="fas fa-search"></i> Search
            </button>
            <button class="filter-btn" id="eligibleToggle" onclick="toggleEligibleOnly()"
                style="background:var(--surface);color:var(--text2);border:1px solid var(--border);">
                <i class="fas fa-filter"></i> Eligible Only
            </button>
        </div>

        <!-- Job Cards -->
        <div class="jobs-grid fade-in d2" id="jobList">

            <?php if (empty($jobOrders)): ?>
                <div class="empty-state">
                    <i class="fas fa-briefcase"></i>
                    <p>No open job opportunities at the moment.</p>
                    <span>Please check back later or follow DMW announcements.</span>
                </div>
            <?php else: ?>
                <?php foreach ($jobOrders as $job):
                    $isGov     = ($job['classification'] === 'Government');
                    $hasApplied = in_array((int)$job['id'], $appliedRsfIds, true);
                    $slotsLeft = max(0, (int) ($job['slots_available'] ?? 0));
                    $isFull = $slotsLeft <= 0;
                    // Check qualification match
                    $jobQuals = $qualsByRsf[(int)$job['id']] ?? [];
                    $qualCheck = (!empty($jobQuals) && $clientData)
                        ? checkQualifications($clientData, $clientAge, $jobQuals)
                        : ['eligible' => true, 'reasons' => []]; // no structured quals = no restriction
                    $hasQuals = !empty($jobQuals);
                ?>
                    <div class="job-card <?= $isGov ? 'gov' : '' ?>"
                        data-eligible="<?= $qualCheck['eligible'] ? '1' : '0' ?>"
                        data-has-quals="<?= $hasQuals ? '1' : '0' ?>"
                        data-reasons="<?= htmlspecialchars(implode('|', $qualCheck['reasons'])) ?>">
                        <div class="jc-body">

                            <div class="jc-top">
                                <div>
                                    <div class="jc-title"><?= htmlspecialchars($job['position_title']) ?></div>
                                    <div class="jc-employer"><?= htmlspecialchars($job['employer_name']) ?></div>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
                                    <span class="jc-badge <?= $isGov ? 'badge-gov' : 'badge-prv' ?>">
                                        <?= htmlspecialchars($job['classification']) ?>
                                    </span>
                                    <?php if ($has_preferences && $job['match_score'] !== null):
                                        $score = (int) $job['match_score'];
                                        if ($score >= 70) {
                                            $scoreBg = '#d1fae5';
                                            $scoreFg = '#065f46';
                                        } elseif ($score >= 40) {
                                            $scoreBg = '#fef9c3';
                                            $scoreFg = '#854d0e';
                                        } else {
                                            $scoreBg = '#f3f4f6';
                                            $scoreFg = '#4b5563';
                                        }
                                    ?>
                                        <span class="applied-badge" style="background:<?= $scoreBg ?>;color:<?= $scoreFg ?>;" title="Based on your saved job preferences">
                                            <i class="fas fa-star"></i> <?= $score ?>% Match
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($hasApplied): ?>
                                        <span class="applied-badge">
                                            <i class="fas fa-circle-check"></i> Applied
                                        </span>
                                    <?php elseif ($isFull): ?>
                                        <span class="applied-badge" style="background:#fef2f2;color:#b91c1c;">
                                            <i class="fas fa-ban"></i> No Slots
                                        </span>
                                    <?php elseif ($hasQuals && $qualCheck['eligible']): ?>
                                        <span class="applied-badge" style="background:#d1fae5;color:#065f46;">
                                            <i class="fas fa-circle-check"></i> You Qualify
                                        </span>
                                    <?php elseif ($hasQuals && !$qualCheck['eligible']): ?>
                                        <span class="applied-badge" style="background:#fffbeb;color:#92400e;"
                                            title="<?= htmlspecialchars(implode(' · ', $qualCheck['reasons'])) ?>">
                                            <i class="fas fa-triangle-exclamation"></i> May Not Qualify
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="jc-details">
                                <div class="jc-detail-row">
                                    <i class="fas fa-location-dot"></i>
                                    <span><?= htmlspecialchars($job['worksite_country'] ?: 'Not specified') ?></span>
                                </div>
                                <div class="jc-detail-row">
                                    <i class="fas fa-users"></i>
                                    <span><strong><?= number_format($slotsLeft) ?></strong> slot<?= $slotsLeft !== 1 ? 's' : '' ?> available</span>
                                </div>
                                <div class="jc-detail-row">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span>Deadline: <strong><?= $job['deadline'] ? date('M d, Y', strtotime($job['deadline'])) : 'Open' ?></strong></span>
                                </div>
                                <?php if (!empty($job['rsf_number'])): ?>
                                    <div class="jc-detail-row">
                                        <i class="fas fa-hashtag"></i>
                                        <span><?= htmlspecialchars($job['rsf_number']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="jc-actions">
                                <?php if ($hasApplied): ?>
                                    <button type="button" class="jc-btn btn-applied" onclick="alreadyAppliedForJob()" title="You have already applied for this position">
                                        <i class="fas fa-circle-check"></i> Already Applied
                                    </button>
                                <?php elseif ($isFull): ?>
                                    <button type="button" class="jc-btn btn-applied" onclick="noSlotsAvailable()" title="No available slots for this job">
                                        <i class="fas fa-ban"></i> No Slots Available
                                    </button>
                                <?php else: ?>
                                    <!-- Not yet applied: normal Apply button -->
                                    <button class="jc-btn btn-apply" onclick="applyForJob(<?= (int)$job['id'] ?>)">
                                        <i class="fas fa-paper-plane"></i> Apply Now
                                    </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

        <div class="pagination-wrap fade-in d3" id="paginationWrap" style="display:none;">
            <button type="button" class="pagination-btn" id="prevPageBtn" onclick="changePage(-1)">
                <i class="fas fa-angle-left"></i> Previous
            </button>
            <div class="pagination-pages" id="pageNumbers"></div>
            <span class="pagination-info" id="pageInfo">Page 1 of 1</span>
            <button type="button" class="pagination-btn" id="nextPageBtn" onclick="changePage(1)">
                Next <i class="fas fa-angle-right"></i>
            </button>
        </div>

    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk ">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="jobs.php" class="dk active">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>

            <div class="dk-div"></div>

            <a href="my-applications.php" class="dk">
                <i class="fas fa-file-lines"></i>
                <span class="dk-lbl">Applications</span>
                <?php if ($app_count > 0): ?>
                    <span class="dk-badge"><?= $app_count > 99 ? '99+' : $app_count ?></span>
                <?php endif; ?>
            </a>

            <a href="notifications.php" class="dk">
                <i class="fas fa-bell"></i>
                <span class="dk-lbl">Alerts</span>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <div class="dk" onclick="logout('Applicant')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <?php if ($show_pref_modal): ?>
        <div id="prefModal" class="modal-overlay">
            <div class="modal-box">
                <div class="modal-head">
                    <h3>
                        <span style="width:32px;height:32px;border-radius:9px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:var(--blue);font-size:14px;flex-shrink:0;">
                            <i class="fas fa-sliders"></i>
                        </span>
                        Set Your Job Preferences
                    </h3>
                    <!-- No close button — must complete before browsing -->
                </div>
                <div class="modal-body">

                    <div class="profile-steps" id="prefStepIndicator">
                        <div class="step-node active" id="pn1">1</div>
                        <div class="step-line" id="pl1"></div>
                        <div class="step-node idle" id="pn2">2</div>
                    </div>

                    <form id="prefForm">

                        <!-- ── STEP 1: Job & Salary Preferences ── -->
                        <div class="step-panel active" id="prefStepPanel1">
                            <p class="pf-section-heading">Job &amp; Salary Preferences</p>
                            <p class="pf-subtitle">This helps us rank job orders by how well they match what you're looking for. It doesn't limit which jobs you can apply to.</p>
                            <div class="pf-grid">
                                <div class="pf-field">
                                    <label>Preferred Classification <span class="req">*</span></label>
                                    <select name="preferred_classification" required>
                                        <option value="">Select…</option>
                                        <option value="Government">Government</option>
                                        <option value="Private">Private</option>
                                        <option value="Any">No preference / Any</option>
                                    </select>
                                </div>
                                <div class="pf-field">
                                    <label>Preferred Employment Type <span class="req">*</span></label>
                                    <select name="preferred_employment_type" required>
                                        <option value="">Select…</option>
                                        <option value="Full-time">Full-time</option>
                                        <option value="Part-time">Part-time</option>
                                        <option value="Contract">Contract</option>
                                    </select>
                                </div>
                                <div class="pf-field">
                                    <label>Minimum Expected Salary</label>
                                    <input type="number" name="min_salary_expected" placeholder="e.g. 20000" min="0" step="0.01">
                                </div>
                                <div class="pf-field">
                                    <label>Currency</label>
                                    <select name="salary_currency">
                                        <option value="PHP">PHP</option>
                                        <option value="USD">USD</option>
                                        <option value="TWD">TWD</option>
                                        <option value="JPY">JPY</option>
                                        <option value="SAR">SAR</option>
                                        <option value="AED">AED</option>
                                        <option value="EUR">EUR</option>
                                    </select>
                                </div>
                                <div class="pf-field pf-full">
                                    <label>Willing to Relocate Abroad <span class="req">*</span></label>
                                    <select name="willing_to_relocate" required>
                                        <option value="">Select…</option>
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ── STEP 2: Preferred Countries & Positions ── -->
                        <div class="step-panel" id="prefStepPanel2">
                            <p class="pf-section-heading">Preferred Destinations &amp; Positions</p>
                            <p class="pf-subtitle">Add up to 3 of each, in order of preference. The first one you add is your top priority.</p>

                            <div class="pf-field pf-full">
                                <label>Preferred Countries <span class="req">*</span></label>
                                <div class="chip-add-row">
                                    <select id="countrySelect">
                                        <option value="">Select a country…</option>
                                        <?php foreach ($prefCountryOptions as $c): ?>
                                            <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="chip-add-btn" id="addCountryBtn">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                                <div class="chip-list" id="countryChips"></div>
                            </div>

                            <div class="pf-field pf-full" style="margin-top:16px;">
                                <label>Preferred Positions <span class="req">*</span></label>
                                <div class="chip-add-row">
                                    <select id="positionSelect">
                                        <option value="">Select a position…</option>
                                        <?php foreach ($prefCategories as $cat): if (empty($cat['subs'])) continue; ?>
                                            <optgroup label="<?= htmlspecialchars($cat['name']) ?>">
                                                <?php foreach ($cat['subs'] as $sub): ?>
                                                    <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['subcategory_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="chip-add-btn" id="addPositionBtn">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                                <div class="chip-list" id="positionChips"></div>
                            </div>

                            <!-- Hidden inputs populated from chips right before submit -->
                            <div id="prefHiddenInputs"></div>
                        </div>

                    </form><!-- /prefForm -->

                    <div id="prefError" style="display:none;background:#fee2e2;border:1px solid #fecaca;border-radius:9px;padding:10px 13px;margin-top:12px;font-size:12px;font-weight:600;color:#991b1b;"></div>

                </div><!-- /modal-body -->
                <div class="modal-footer" id="prefModalFooter">
                    <button class="modal-btn modal-btn-secondary" id="prefBackBtn" style="display:none;" onclick="prefStep(-1)">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button class="modal-btn modal-btn-primary" id="prefNextBtn" onclick="prefStep(1)">
                        Next &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const TOTAL_STEPS = 2;
                const MAX_ITEMS = 3;
                let currentStep = 1;
                let countryChips = []; // ordered array of country strings
                let positionChips = []; // ordered array of {id, name}

                function updateStepUI() {
                    for (let i = 1; i <= TOTAL_STEPS; i++) {
                        document.getElementById('prefStepPanel' + i).classList.toggle('active', i === currentStep);
                        const node = document.getElementById('pn' + i);
                        if (i < currentStep) node.className = 'step-node done';
                        else if (i === currentStep) node.className = 'step-node active';
                        else node.className = 'step-node idle';
                    }
                    document.getElementById('pl1').className = 'step-line' + (currentStep > 1 ? ' done' : '');
                    document.getElementById('prefBackBtn').style.display = currentStep > 1 ? '' : 'none';
                    const nextBtn = document.getElementById('prefNextBtn');
                    nextBtn.innerHTML = currentStep === TOTAL_STEPS ?
                        '<i class="fas fa-check"></i> Save Preferences' :
                        'Next &nbsp;<i class="fas fa-arrow-right"></i>';
                }

                function showError(msg) {
                    const el = document.getElementById('prefError');
                    el.textContent = msg;
                    el.style.display = 'block';
                }

                function hideError() {
                    document.getElementById('prefError').style.display = 'none';
                }

                function validateStep(step) {
                    if (step === 1) {
                        const panel = document.getElementById('prefStepPanel1');
                        const required = panel.querySelectorAll('[required]');
                        for (const el of required) {
                            if (!el.value.trim()) {
                                el.focus();
                                showError('Please fill in all required fields (marked with *).');
                                return false;
                            }
                        }
                    }
                    if (step === 2) {
                        if (countryChips.length < 1) {
                            showError('Please add at least one preferred country.');
                            return false;
                        }
                        if (positionChips.length < 1) {
                            showError('Please add at least one preferred position.');
                            return false;
                        }
                    }
                    hideError();
                    return true;
                }

                function renderChips() {
                    const cWrap = document.getElementById('countryChips');
                    cWrap.innerHTML = '';
                    countryChips.forEach((country, idx) => {
                        const chip = document.createElement('span');
                        chip.className = 'chip';
                        chip.innerHTML = `<span class="chip-rank">${idx + 1}</span> ${country} <i class="fas fa-xmark chip-remove" data-idx="${idx}"></i>`;
                        chip.querySelector('.chip-remove').addEventListener('click', () => {
                            countryChips.splice(idx, 1);
                            renderChips();
                        });
                        cWrap.appendChild(chip);
                    });

                    const pWrap = document.getElementById('positionChips');
                    pWrap.innerHTML = '';
                    positionChips.forEach((pos, idx) => {
                        const chip = document.createElement('span');
                        chip.className = 'chip';
                        chip.innerHTML = `<span class="chip-rank">${idx + 1}</span> ${pos.name} <i class="fas fa-xmark chip-remove" data-idx="${idx}"></i>`;
                        chip.querySelector('.chip-remove').addEventListener('click', () => {
                            positionChips.splice(idx, 1);
                            renderChips();
                        });
                        pWrap.appendChild(chip);
                    });
                }

                document.getElementById('addCountryBtn').addEventListener('click', () => {
                    const sel = document.getElementById('countrySelect');
                    const val = sel.value;
                    if (!val) return;
                    if (countryChips.includes(val)) {
                        showError('That country is already added.');
                        return;
                    }
                    if (countryChips.length >= MAX_ITEMS) {
                        showError('You can add up to 3 preferred countries.');
                        return;
                    }
                    countryChips.push(val);
                    sel.value = '';
                    hideError();
                    renderChips();
                });

                document.getElementById('addPositionBtn').addEventListener('click', () => {
                    const sel = document.getElementById('positionSelect');
                    const val = sel.value;
                    if (!val) return;
                    if (positionChips.some(p => p.id === val)) {
                        showError('That position is already added.');
                        return;
                    }
                    if (positionChips.length >= MAX_ITEMS) {
                        showError('You can add up to 3 preferred positions.');
                        return;
                    }
                    positionChips.push({
                        id: val,
                        name: sel.options[sel.selectedIndex].text
                    });
                    sel.value = '';
                    hideError();
                    renderChips();
                });

                window.prefStep = function(dir) {
                    if (dir === 1) {
                        if (!validateStep(currentStep)) return;
                        if (currentStep === TOTAL_STEPS) {
                            submitPreferences();
                            return;
                        }
                        currentStep++;
                    } else {
                        currentStep = Math.max(1, currentStep - 1);
                    }
                    updateStepUI();
                };

                function submitPreferences() {
                    const btn = document.getElementById('prefNextBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

                    const formData = new FormData(document.getElementById('prefForm'));
                    countryChips.forEach(c => formData.append('countries[]', c));
                    positionChips.forEach(p => formData.append('positions[]', p.id));

                    fetch('save-job-preferences.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('prefModal').style.display = 'none';
                                window.location.replace('jobs.php');
                            } else {
                                showError(data.message || 'Something went wrong. Please try again.');
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fas fa-check"></i> Save Preferences';
                            }
                        })
                        .catch(() => {
                            showError('Network error. Please check your connection and try again.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Save Preferences';
                        });
                }

                updateStepUI();

                // Prevent closing modal on backdrop click
                document.getElementById('prefModal').addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            })();
        </script>
    <?php endif; ?>

    <script>
        /* Live date */
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        function alreadyAppliedForJob() {
            Swal.fire({
                icon: 'info',
                title: 'Already applied',
                text: 'You have already submitted an application for this job order. Duplicate applications are not allowed.',
                confirmButtonColor: '#1d4ed8'
            });
        }

        function noSlotsAvailable() {
            Swal.fire({
                icon: 'warning',
                title: 'No slots available',
                text: 'This job order has already reached its maximum number of applicants.',
                confirmButtonColor: '#1d4ed8'
            });
        }

        /* Apply for job (client-only page — session enforces login) */
        function applyForJob(id) {
            Swal.fire({
                title: 'Apply for this position?',
                text: 'You will start the application workflow. Please have your information ready.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1d4ed8',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Apply',
                cancelButtonText: 'Cancel'
            }).then(r => {
                if (r.isConfirmed) {
                    window.location.href = 'my-applications.php?job_id=' + encodeURIComponent(id) + '&step=1&guide=apply';
                }
            });
        }

        /* Filter by country pill click */
        function filterByCountry(country) {
            document.getElementById('countryFilter').value = country;
            applyFilters();
        }

        /* Search + filter */
        const JOBS_PER_PAGE = 5;
        let currentPage = 1;

        let eligibleOnly = false;

        function toggleEligibleOnly() {
            eligibleOnly = !eligibleOnly;
            const btn = document.getElementById('eligibleToggle');
            if (eligibleOnly) {
                btn.style.background = '#1d4ed8';
                btn.style.color = '#fff';
                btn.style.borderColor = '#1d4ed8';
            } else {
                btn.style.background = 'var(--surface)';
                btn.style.color = 'var(--text2)';
                btn.style.borderColor = 'var(--border)';
            }
            applyFilters();
        }

        function getFilteredCards() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const country = document.getElementById('countryFilter').value.toLowerCase().trim();
            const cards = Array.from(document.querySelectorAll('#jobList .job-card'));

            return cards.filter(card => {
                const text = card.textContent.toLowerCase();
                const matchesSearch = !search || text.includes(search);
                const matchesCountry = !country || text.includes(country);
                const isEligible = card.dataset.eligible === '1' || card.dataset.hasQuals === '0';
                const matchesEligible = !eligibleOnly || isEligible;

                // Apply dim class
                const hasQuals = card.dataset.hasQuals === '1';
                const notEligible = hasQuals && card.dataset.eligible === '0';
                card.classList.toggle('not-eligible', notEligible);

                return matchesSearch && matchesCountry && matchesEligible;
            });
        }

        function renderJobsPage() {
            const allCards = Array.from(document.querySelectorAll('#jobList .job-card'));
            const filteredCards = getFilteredCards();
            const totalPages = Math.max(1, Math.ceil(filteredCards.length / JOBS_PER_PAGE));

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            allCards.forEach(card => {
                card.style.display = 'none';
            });

            const start = (currentPage - 1) * JOBS_PER_PAGE;
            const end = start + JOBS_PER_PAGE;
            filteredCards.slice(start, end).forEach(card => {
                card.style.display = '';
            });

            const paginationWrap = document.getElementById('paginationWrap');
            const pageNumbers = document.getElementById('pageNumbers');
            const pageInfo = document.getElementById('pageInfo');
            const prevPageBtn = document.getElementById('prevPageBtn');
            const nextPageBtn = document.getElementById('nextPageBtn');

            if (!pageInfo || !prevPageBtn || !nextPageBtn || !paginationWrap || !pageNumbers) return;

            if (filteredCards.length === 0) {
                paginationWrap.style.display = 'none';
                pageNumbers.innerHTML = '';
                pageInfo.textContent = 'Page 0 of 0';
                prevPageBtn.disabled = true;
                nextPageBtn.disabled = true;
                return;
            }

            paginationWrap.style.display = '';
            pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
            prevPageBtn.disabled = currentPage <= 1;
            nextPageBtn.disabled = currentPage >= totalPages;
            renderPageButtons(totalPages);
        }

        function renderPageButtons(totalPages) {
            const pageNumbers = document.getElementById('pageNumbers');
            if (!pageNumbers) return;

            pageNumbers.innerHTML = '';
            for (let page = 1; page <= totalPages; page += 1) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `pagination-page-btn${page === currentPage ? ' active' : ''}`;
                btn.textContent = String(page);
                btn.addEventListener('click', () => {
                    currentPage = page;
                    renderJobsPage();
                });
                pageNumbers.appendChild(btn);
            }
        }

        function applyFilters() {
            currentPage = 1;
            renderJobsPage();
        }

        function changePage(direction) {
            currentPage += direction;
            renderJobsPage();
        }

        /* Live search */
        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('countryFilter').addEventListener('change', applyFilters);
        renderJobsPage();
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>