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
    SELECT preferred_classification, min_salary_expected,
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
 * Formats a ranked preference list for display, e.g. "Taiwan (#1), Japan (#2)".
 */
function formatPrefList(array $items, string $key): string
{
    $parts = [];
    foreach ($items as $item) {
        $parts[] = ($item[$key] ?? '') . ' (#' . (int) $item['priority_order'] . ')';
    }
    return implode(', ', $parts);
}

/**
 * Weighted preference-match score (0-100) for a job order against the
 * client's saved preferences, WITH a full per-component breakdown so the
 * applicant can see exactly why they got the score they did.
 *
 * Components with no comparable data on either side are excluded from the
 * denominator (marked applicable=false) so missing data never unfairly
 * penalizes a job — but they're still returned so the reason is visible.
 *
 * Weights: preferred country 35, preferred position 30, classification 15, salary 20.
 */
function computeMatchDetails(array $job, ?array $prefs, array $prefCountries, array $prefPositions): array
{
    $components  = [];
    $earnedTotal = 0.0;
    $maxTotal    = 0.0;

    // ── Preferred country (ranked — priority 1 scores highest) ──
    $countryMax     = 35;
    $countryEarned  = 0.0;
    $countryMatched = false;
    $countryDetail  = '';
    $countryApplic  = !empty($prefCountries);
    if ($countryApplic) {
        foreach ($prefCountries as $pc) {
            if (!empty($job['worksite_country']) && strcasecmp($job['worksite_country'], $pc['country']) === 0) {
                $rank = (int) $pc['priority_order'];
                $countryEarned  = max(35 - ($rank - 1) * 12, 10);
                $countryMatched = true;
                $countryDetail  = "Matched your #{$rank} preferred country ({$pc['country']}).";
                break;
            }
        }
        if (!$countryMatched) {
            $jobCountry    = $job['worksite_country'] ?: 'an unspecified location';
            $countryDetail = "This job is in {$jobCountry}, which isn't among your preferred countries (" . formatPrefList($prefCountries, 'country') . ").";
        }
        $earnedTotal += $countryEarned;
        $maxTotal    += $countryMax;
    } else {
        $countryDetail = "You haven't set any preferred countries.";
    }
    $components[] = [
        'label'      => 'Preferred Country',
        'earned'     => round($countryEarned, 1),
        'max'        => $countryMax,
        'matched'    => $countryMatched,
        'applicable' => $countryApplic,
        'detail'     => $countryDetail,
    ];

    // ── Preferred position/subcategory (ranked, with fuzzy-title fallback) ──
    $positionMax     = 30;
    $positionEarned  = 0.0;
    $positionMatched = false;
    $positionApplic  = !empty($prefPositions);
    if ($positionApplic) {
        foreach ($prefPositions as $pp) {
            if (!empty($job['subcategory_id']) && (int) $job['subcategory_id'] === (int) $pp['subcategory_id']) {
                $rank = (int) $pp['priority_order'];
                $positionEarned  = max(30 - ($rank - 1) * 10, 10);
                $positionMatched = true;
                $positionDetail  = "Matched your #{$rank} preferred position ({$pp['subcategory_name']}).";
                break;
            }
        }
        if (!$positionMatched) {
            foreach ($prefPositions as $pp) {
                if (
                    !empty($pp['subcategory_name']) && !empty($job['position_title'])
                    && stripos($job['position_title'], $pp['subcategory_name']) !== false
                ) {
                    $rank = (int) $pp['priority_order'];
                    $positionEarned  = max(30 - ($rank - 1) * 10, 10) * 0.6;
                    $positionMatched = true;
                    $positionDetail  = "Your preferred position \"{$pp['subcategory_name']}\" appears in this job's title — partial credit, since this job order hasn't been tagged with a structured position category yet.";
                    break;
                }
            }
        }
        if (!$positionMatched) {
            $jobTitle       = $job['position_title'] ?: 'this position';
            $positionDetail = "\"{$jobTitle}\" doesn't match any of your preferred positions (" . formatPrefList($prefPositions, 'subcategory_name') . ").";
        }
        $earnedTotal += $positionEarned;
        $maxTotal    += $positionMax;
    } else {
        $positionDetail = "You haven't set any preferred positions.";
    }
    // Ensure these variables are defined to avoid "undefined variable" notices
    $positionEarned  = $positionEarned  ?? 0.0;
    $positionMax     = $positionMax     ?? 0;
    $positionMatched = $positionMatched ?? false;
    $positionApplic  = $positionApplic  ?? false;
    $positionDetail  = $positionDetail  ?? "You haven't set any preferred positions.";

    $components[] = [
        'label'      => 'Preferred Position',
        'earned'     => round($positionEarned, 1),
        'max'        => $positionMax,
        'matched'    => $positionMatched,
        'applicable' => $positionApplic,
        'detail'     => $positionDetail,
    ];

    // ── Classification (Government/Private) — "Any" always matches ──
    $classMax     = 15;
    $classEarned  = 0.0;
    $classMatched = false;
    $classApplic  = !empty($prefs['preferred_classification']);
    if ($classApplic) {
        if (strcasecmp($prefs['preferred_classification'], 'Any') === 0) {
            $classEarned  = 15;
            $classMatched = true;
            $classDetail  = 'You selected "No preference / Any" for classification, so this always matches.';
        } elseif (strcasecmp($prefs['preferred_classification'], $job['classification'] ?? '') === 0) {
            $classEarned  = 15;
            $classMatched = true;
            $classDetail  = "This is a {$job['classification']} job, matching your preference.";
        } else {
            $jobClass    = $job['classification'] ?: 'unspecified';
            $classDetail = "You prefer {$prefs['preferred_classification']} jobs; this is a {$jobClass} job.";
        }
        $earnedTotal += $classEarned;
        $maxTotal    += $classMax;
    } else {
        $classDetail = "You haven't set a preferred classification.";
    }
    $components[] = [
        'label'      => 'Classification',
        'earned'     => $classEarned,
        'max'        => $classMax,
        'matched'    => $classMatched,
        'applicable' => $classApplic,
        'detail'     => $classDetail,
    ];

    // ── Minimum expected salary (always PHP) vs job's posted range ──
    // Applicants only ever enter a PHP figure. Job orders are posted in the
    // employer's original currency (salary_min/salary_max/salary_currency),
    // so we compare against the pre-converted salary_min_php/salary_max_php
    // columns instead — never against the raw foreign-currency figures.
    // ── Salary Match (PHP) ──
    $salaryMax     = 20;
    $salaryEarned  = 0.0;
    $salaryMatched = false;
    $prefHasSalary = !empty($prefs['min_salary_expected']) && (float)$prefs['min_salary_expected'] > 0;
    $jobHasSalary  = !empty($job['salary_min_php']) || !empty($job['salary_max_php']);
    $salaryApplic  = $prefHasSalary && $jobHasSalary;

    if ($salaryApplic) {
        $clientMin = (float)$prefs['min_salary_expected'];
        $jobMinPhp = (float)($job['salary_min_php'] ?? 0);
        $jobMaxPhp = (float)($job['salary_max_php'] ?? $jobMinPhp);

        $origCurrency = $job['salary_currency'] ?: 'PHP';
        $origMax      = $job['salary_max'] ?? $job['salary_min'];
        $origSuffix   = ($origCurrency !== 'PHP' && $origMax)
            ? " ({$origCurrency} " . number_format($origMax) . ")"
            : '';

        if ($jobMaxPhp >= $clientMin) {
            $salaryEarned = 20;
            $salaryMatched = true;
            $salaryDetail = "This job pays up to ₱" . number_format($jobMaxPhp) . "{$origSuffix} — meets or exceeds your ₱" . number_format($clientMin) . " expectation.";
        } elseif ($jobMinPhp >= $clientMin * 0.75) {
            $salaryEarned = 12;
            $salaryMatched = true;
            $salaryDetail = "This job's range is a bit below your expectation but still close (₱" . number_format($jobMinPhp) . "–" . number_format($jobMaxPhp) . ").";
        } else {
            $salaryDetail = "This job's maximum (₱" . number_format($jobMaxPhp) . ") is below your minimum expectation of ₱" . number_format($clientMin) . ".";
        }

        $earnedTotal += $salaryEarned;
        $maxTotal    += $salaryMax;
    } else if (!$prefHasSalary) {
        $salaryDetail = "You have not set a minimum salary expectation.";
    } else {
        $salaryDetail = "This job order does not have salary information.";
    }

    $components[] = [
        'label'      => 'Expected Salary',
        'earned'     => $salaryEarned,
        'max'        => $salaryMax,
        'matched'    => $salaryMatched,
        'applicable' => $salaryApplic,
        'detail'     => $salaryDetail,
    ];

    $score = $maxTotal > 0
        ? (int) round(min(100, max(0, ($earnedTotal / $maxTotal) * 100)))
        : 50; // no comparable data at all — neutral score

    return [
        'score'      => $score,
        'earned'     => round($earnedTotal, 1),
        'max'        => $maxTotal,
        'components' => $components,
    ];
}

// Attach preference-match score (+ full breakdown) to each job order, then
// rank best matches first. $matchBreakdowns is exposed to JS further down
// so the applicant can see proof of how their score was calculated.
$matchBreakdowns = [];
foreach ($jobOrders as &$job) {
    if ($has_preferences) {
        $details = computeMatchDetails($job, $clientPrefs, $prefCountries, $prefPositions);
        $job['match_score'] = $details['score'];
        $matchBreakdowns[(int) $job['id']] = $details;
    } else {
        $job['match_score'] = null;
    }
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

    <!-- Shared dashboard stylesheet (defines the --blue / --navy / --card / --border / --text* CSS vars used below) -->
    <link rel="stylesheet" href="../css/dashboard.css">

    <!-- Tailwind (utility classes replace the old page-local <style> block) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* Only what Tailwind's CDN build genuinely can't express statically:
           the card fade-in keyframes (utility classes reference this name). */
        @keyframes fi2 {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }
    </style>
</head>

<body>

    <!--TOPBAR -->
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
    <div class="max-w-[1400px] mx-auto px-3.5 pt-3.5 pb-0">

        <?php if ($show_pref_modal): ?>
            <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] flex items-start gap-3 bg-[#eff6ff] border border-[#bfdbfe] rounded-xl py-3.5 px-4 mb-3">
                <i class="fas fa-lock text-[var(--blue)] text-lg mt-0.5"></i>
                <div>
                    <h4 class="text-[12.5px] font-extrabold text-[var(--navy)]">Set Your Job Preferences to Unlock Listings</h4>
                    <p class="text-[11.5px] text-[var(--text2)] mt-[3px] leading-relaxed">Tell us your preferred classification, destination countries, and positions so we can show you the best-matching job orders first.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search & Filter bar -->
        <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.05s] bg-[var(--card)] border border-[var(--border)] rounded-[14px] p-3.5 mb-3.5 flex flex-col gap-2.5 sm:flex-row sm:items-center">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-xs pointer-events-none"></i>
                <input type="text" id="searchInput"
                    class="w-full py-[9px] pr-3 pl-[34px] border border-[var(--border)] rounded-[10px] text-[13px] font-medium text-[var(--text1)] bg-[var(--surface)] outline-none transition-colors focus:border-[var(--blue-lt)] focus:bg-white placeholder:text-[var(--text3)]"
                    placeholder="Search position, employer, country, RSF number…">
            </div>
            <select id="countryFilter"
                class="py-[9px] px-3 border border-[var(--border)] rounded-[10px] text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] outline-none cursor-pointer transition-colors focus:border-[var(--blue-lt)]">
                <option value="">All Countries</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="applyFilters()"
                class="py-[9px] px-5 bg-[var(--blue)] text-white border-none rounded-[10px] text-[13px] font-bold cursor-pointer transition-colors hover:bg-[#1e40af] whitespace-nowrap">
                <i class="fas fa-search"></i> Search
            </button>
        </div>

        <!-- Job Cards -->
        <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.1s] grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-4" id="jobList">

            <?php if (empty($jobOrders)): ?>
                <div class="py-[60px] px-5 text-center col-span-full">
                    <i class="fas fa-briefcase text-4xl text-[var(--border)] block mb-3"></i>
                    <p class="text-sm font-semibold text-[var(--text3)]">No open job opportunities at the moment.</p>
                    <span class="text-xs text-[var(--text3)] opacity-70">Please check back later or follow DMW announcements.</span>
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
                    $topStripe = $isGov ? 'before:bg-[var(--green)]' : 'before:bg-[var(--blue-lt)]';
                ?>
                    <div class="relative bg-[var(--card)] border border-[var(--border)] rounded-[14px] overflow-hidden transition-all hover:-translate-y-1 hover:shadow-[0_14px_30px_rgba(11,31,75,0.10)] active:scale-[.98] before:content-[''] before:absolute before:top-0 before:left-0 before:right-0 before:h-[3px] before:rounded-t-[14px] <?= $topStripe ?>"
                        data-eligible="<?= $qualCheck['eligible'] ? '1' : '0' ?>"
                        data-has-quals="<?= $hasQuals ? '1' : '0' ?>"
                        data-reasons="<?= htmlspecialchars(implode('|', $qualCheck['reasons'])) ?>">
                        <div class="p-4">

                            <div class="flex justify-between items-start gap-2.5 mb-3">
                                <div>
                                    <div class="font-['DM_Serif_Display'] text-[15px] text-[var(--text1)] leading-[1.3]"><?= htmlspecialchars($job['position_title']) ?></div>
                                    <div class="text-[11.5px] text-[var(--text3)] font-medium mt-[3px]"><?= htmlspecialchars($job['employer_name']) ?></div>
                                </div>
                                <div class="flex flex-col items-end gap-[5px]">
                                    <span class="text-[9.5px] font-bold py-1 px-2.5 rounded-full whitespace-nowrap flex-shrink-0 tracking-[.03em] <?= $isGov ? 'bg-[#d1fae5] text-[#065f46]' : 'bg-[#eff6ff] text-[#1e40af]' ?>">
                                        <?= htmlspecialchars($job['classification']) ?>
                                    </span>
                                    <?php if ($has_preferences && $job['match_score'] !== null):
                                        $score = (int) $job['match_score'];
                                        if ($score >= 70) {
                                            $scoreClasses = 'bg-[#d1fae5] text-[#065f46]';
                                        } elseif ($score >= 40) {
                                            $scoreClasses = 'bg-[#fef9c3] text-[#854d0e]';
                                        } else {
                                            $scoreClasses = 'bg-[#f3f4f6] text-[#4b5563]';
                                        }
                                    ?>
                                        <span class="inline-flex items-center gap-[5px] text-[9.5px] font-bold tracking-[.03em] py-[3px] px-2.5 rounded-full whitespace-nowrap cursor-pointer <?= $scoreClasses ?>" onclick="showMatchBreakdown(<?= (int) $job['id'] ?>)" title="Click to see why you got this score">
                                            <i class="fas fa-star"></i> <?= $score ?>% Match <i class="fas fa-circle-info ml-[2px] opacity-70"></i>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($hasApplied): ?>
                                        <span class="inline-flex items-center gap-[5px] text-[9.5px] font-bold tracking-[.03em] py-[3px] px-2.5 rounded-full whitespace-nowrap bg-[#eff6ff] text-[#1e40af]">
                                            <i class="fas fa-circle-check"></i> Applied
                                        </span>
                                    <?php elseif ($isFull): ?>
                                        <span class="inline-flex items-center gap-[5px] text-[9.5px] font-bold tracking-[.03em] py-[3px] px-2.5 rounded-full whitespace-nowrap bg-[#fef2f2] text-[#b91c1c]">
                                            <i class="fas fa-ban"></i> No Slots
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex flex-col gap-[7px] mb-3.5">
                                <div class="flex items-center gap-[9px] text-xs text-[var(--text2)] font-medium">
                                    <i class="fas fa-location-dot w-4 text-center text-[var(--text3)] text-[11px] flex-shrink-0"></i>
                                    <span><?= htmlspecialchars($job['worksite_country'] ?: 'Not specified') ?></span>
                                </div>
                                <div class="flex items-center gap-[9px] text-xs text-[var(--text2)] font-medium">
                                    <i class="fas fa-users w-4 text-center text-[var(--text3)] text-[11px] flex-shrink-0"></i>
                                    <span><strong class="text-[var(--text1)] font-bold"><?= number_format($slotsLeft) ?></strong> slot<?= $slotsLeft !== 1 ? 's' : '' ?> available</span>
                                </div>
                                <div class="flex items-center gap-[9px] text-xs text-[var(--text2)] font-medium">
                                    <i class="fas fa-calendar-alt w-4 text-center text-[var(--text3)] text-[11px] flex-shrink-0"></i>
                                    <span>Deadline: <strong class="text-[var(--text1)] font-bold"><?= $job['deadline'] ? date('M d, Y', strtotime($job['deadline'])) : 'Open' ?></strong></span>
                                </div>
                                <?php if (!empty($job['rsf_number'])): ?>
                                    <div class="flex items-center gap-[9px] text-xs text-[var(--text2)] font-medium">
                                        <i class="fas fa-hashtag w-4 text-center text-[var(--text3)] text-[11px] flex-shrink-0"></i>
                                        <span><?= htmlspecialchars($job['rsf_number']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="flex gap-2">
                                <?php if ($hasApplied): ?>
                                    <button type="button" class="flex-1 flex items-center justify-center gap-1.5 py-[9px] px-2.5 rounded-[10px] text-xs font-bold cursor-pointer border-none transition-all active:scale-[.97] bg-[#f1f5f9] text-[#64748b]" onclick="alreadyAppliedForJob()" title="You have already applied for this position">
                                        <i class="fas fa-circle-check"></i> Already Applied
                                    </button>
                                <?php elseif ($isFull): ?>
                                    <button type="button" class="flex-1 flex items-center justify-center gap-1.5 py-[9px] px-2.5 rounded-[10px] text-xs font-bold cursor-pointer border-none transition-all active:scale-[.97] bg-[#f1f5f9] text-[#64748b]" onclick="noSlotsAvailable()" title="No available slots for this job">
                                        <i class="fas fa-ban"></i> No Slots Available
                                    </button>
                                <?php else: ?>
                                    <!-- Not yet applied: normal Apply button -->
                                    <button class="flex-1 flex items-center justify-center gap-1.5 py-[9px] px-2.5 rounded-[10px] text-xs font-bold cursor-pointer border-none transition-all active:scale-[.97] bg-[var(--blue)] text-white hover:bg-[#1e40af]" onclick="applyForJob(<?= (int)$job['id'] ?>)">
                                        <i class="fas fa-paper-plane"></i> Apply Now
                                    </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

        <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.15s] flex items-center justify-center gap-2.5 my-2 mb-[18px] hidden" id="paginationWrap">
            <button type="button" id="prevPageBtn" onclick="changePage(-1)"
                class="border border-[var(--border)] bg-[var(--card)] text-[var(--text2)] rounded-[10px] py-2 px-3.5 text-xs font-bold cursor-pointer transition-colors [&:not(:disabled):hover]:bg-[#eff6ff] [&:not(:disabled):hover]:border-[#bfdbfe] [&:not(:disabled):hover]:text-[#1e40af] disabled:cursor-not-allowed disabled:opacity-50">
                <i class="fas fa-angle-left"></i> Previous
            </button>
            <div class="flex items-center gap-1.5" id="pageNumbers"></div>
            <span class="text-xs font-semibold text-[var(--text3)] min-w-[90px] text-center" id="pageInfo">Page 1 of 1</span>
            <button type="button" id="nextPageBtn" onclick="changePage(1)"
                class="border border-[var(--border)] bg-[var(--card)] text-[var(--text2)] rounded-[10px] py-2 px-3.5 text-xs font-bold cursor-pointer transition-colors [&:not(:disabled):hover]:bg-[#eff6ff] [&:not(:disabled):hover]:border-[#bfdbfe] [&:not(:disabled):hover]:text-[#1e40af] disabled:cursor-not-allowed disabled:opacity-50">
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
        <div id="prefModal" class="fixed inset-0 bg-[rgba(11,31,75,0.6)] backdrop-blur-sm flex items-center justify-center z-[600] p-4">
            <div class="bg-white rounded-[18px] w-full max-w-[600px] max-h-[90vh] overflow-y-auto shadow-[0_24px_60px_rgba(11,31,75,0.3)]">
                <div class="flex items-center justify-between py-4 px-[18px] border-b border-[var(--border)] sticky top-0 bg-white rounded-t-[18px] z-[2]">
                    <h3 class="font-['DM_Serif_Display'] text-base text-[var(--navy)] flex items-center gap-[9px]">
                        <span class="w-8 h-8 rounded-[9px] bg-[#eff6ff] flex items-center justify-center text-[var(--blue)] text-sm flex-shrink-0">
                            <i class="fas fa-sliders"></i>
                        </span>
                        Set Your Job Preferences
                    </h3>
                    <!-- No close button — must complete before browsing -->
                </div>
                <div class="p-[18px]">

                    <div class="flex items-center gap-0 mb-[22px]" id="prefStepIndicator">
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--navy)] text-white" id="pn1">1</div>
                        <div class="flex-1 h-0.5 bg-[var(--border)] transition-colors" id="pl1"></div>
                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--border)] text-[var(--text3)]" id="pn2">2</div>
                    </div>

                    <form id="prefForm">

                        <!-- ── STEP 1: Job & Salary Preferences ── -->
                        <div class="block" id="prefStepPanel1">
                            <p class="font-['DM_Serif_Display'] text-[15px] text-[var(--navy)] mb-3.5">Job &amp; Salary Preferences</p>
                            <p class="text-[11.5px] text-[var(--text3)] mb-[18px] leading-relaxed">This helps us rank job orders by how well they match what you're looking for. It doesn't limit which jobs you can apply to.</p>
                            <div class="grid grid-cols-1 min-[500px]:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Classification <span class="text-red-500">*</span></label>
                                    <select name="preferred_classification" required
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select…</option>
                                        <option value="Government">Government</option>
                                        <option value="Private">Private</option>
                                        <option value="Any">No preference / Any</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Employment Type <span class="text-red-500">*</span></label>
                                    <select name="preferred_employment_type" required
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select…</option>
                                        <option value="Full-time">Full-time</option>
                                        <option value="Part-time">Part-time</option>
                                        <option value="Contract">Contract</option>
                                    </select>
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Minimum Expected Salary (₱ PHP)</label>
                                    <input type="number" name="min_salary_expected" placeholder="e.g. 20000" min="0" step="0.01"
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                    <p class="text-[11.5px] text-[var(--text3)] mt-1 leading-relaxed">Enter this in Philippine Peso. Job orders posted in other currencies are automatically converted to PHP for comparison.</p>
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Willing to Relocate Abroad <span class="text-red-500">*</span></label>
                                    <select name="willing_to_relocate" required
                                        class="w-full py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select…</option>
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ── STEP 2: Preferred Countries & Positions ── -->
                        <div class="hidden" id="prefStepPanel2">
                            <p class="font-['DM_Serif_Display'] text-[15px] text-[var(--navy)] mb-3.5">Preferred Destinations &amp; Positions</p>
                            <p class="text-[11.5px] text-[var(--text3)] mb-[18px] leading-relaxed">Add up to 3 of each, in order of preference. The first one you add is your top priority.</p>

                            <div class="col-span-full">
                                <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Countries <span class="text-red-500">*</span></label>
                                <div class="flex gap-2">
                                    <select id="countrySelect"
                                        class="flex-1 py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select a country…</option>
                                        <?php foreach ($prefCountryOptions as $c): ?>
                                            <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="addCountryBtn"
                                        class="py-[9px] px-[14px] rounded-[9px] border-[1.5px] border-[var(--blue)] bg-[#eff6ff] text-[var(--blue)] text-[12.5px] font-bold cursor-pointer whitespace-nowrap transition-colors hover:bg-[#dbeafe]">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-[10px] min-h-[34px]" id="countryChips"></div>
                            </div>

                            <div class="col-span-full mt-4">
                                <label class="block text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Positions <span class="text-red-500">*</span></label>
                                <div class="flex gap-2">
                                    <select id="positionSelect"
                                        class="flex-1 py-[9px] px-[11px] border-[1.5px] border-[var(--border)] rounded-[9px] text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select a position…</option>
                                        <?php foreach ($prefCategories as $cat): if (empty($cat['subs'])) continue; ?>
                                            <optgroup label="<?= htmlspecialchars($cat['name']) ?>">
                                                <?php foreach ($cat['subs'] as $sub): ?>
                                                    <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['subcategory_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="addPositionBtn"
                                        class="py-[9px] px-[14px] rounded-[9px] border-[1.5px] border-[var(--blue)] bg-[#eff6ff] text-[var(--blue)] text-[12.5px] font-bold cursor-pointer whitespace-nowrap transition-colors hover:bg-[#dbeafe]">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-[10px] min-h-[34px]" id="positionChips"></div>
                            </div>

                            <!-- Hidden inputs populated from chips right before submit -->
                            <div id="prefHiddenInputs"></div>
                        </div>

                    </form><!-- /prefForm -->

                    <div id="prefError" class="hidden bg-red-100 border border-red-200 rounded-[9px] py-[10px] px-[13px] mt-3 text-xs font-semibold text-red-800"></div>

                </div><!-- /modal-body -->
                <div class="flex justify-end gap-2 py-[14px] px-[18px] border-t border-[var(--border)] bg-[var(--surface)] rounded-b-[18px]" id="prefModalFooter">
                    <button id="prefBackBtn" style="display:none;" onclick="prefStep(-1)"
                        class="py-[9px] px-5 rounded-[10px] text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--surface)] border border-[var(--border)] text-[var(--text2)] disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button id="prefNextBtn" onclick="prefStep(1)"
                        class="py-[9px] px-5 rounded-[10px] text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--blue)] text-white disabled:opacity-50 disabled:cursor-not-allowed">
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

                // Tailwind class sets for the JS-driven step indicator / chips / pagination
                const STEP_NODE_BASE = 'w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-extrabold flex-shrink-0 transition-colors';
                const STEP_NODE_DONE = STEP_NODE_BASE + ' bg-[var(--blue)] text-white';
                const STEP_NODE_ACTIVE = STEP_NODE_BASE + ' bg-[var(--navy)] text-white';
                const STEP_NODE_IDLE = STEP_NODE_BASE + ' bg-[var(--border)] text-[var(--text3)]';
                const STEP_LINE_BASE = 'flex-1 h-0.5 bg-[var(--border)] transition-colors';
                const STEP_LINE_DONE = 'flex-1 h-0.5 bg-[var(--blue)] transition-colors';

                function updateStepUI() {
                    for (let i = 1; i <= TOTAL_STEPS; i++) {
                        document.getElementById('prefStepPanel' + i).classList.toggle('hidden', i !== currentStep);
                        const node = document.getElementById('pn' + i);
                        if (i < currentStep) node.className = STEP_NODE_DONE;
                        else if (i === currentStep) node.className = STEP_NODE_ACTIVE;
                        else node.className = STEP_NODE_IDLE;
                    }
                    document.getElementById('pl1').className = currentStep > 1 ? STEP_LINE_DONE : STEP_LINE_BASE;
                    document.getElementById('prefBackBtn').style.display = currentStep > 1 ? '' : 'none';
                    const nextBtn = document.getElementById('prefNextBtn');
                    nextBtn.innerHTML = currentStep === TOTAL_STEPS ?
                        '<i class="fas fa-check"></i> Save Preferences' :
                        'Next &nbsp;<i class="fas fa-arrow-right"></i>';
                }

                function showError(msg) {
                    const el = document.getElementById('prefError');
                    el.textContent = msg;
                    el.classList.remove('hidden');
                }

                function hideError() {
                    document.getElementById('prefError').classList.add('hidden');
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
                        chip.className = 'inline-flex items-center gap-[7px] bg-[var(--navy)] text-white py-[6px] pr-[6px] pl-3 rounded-full text-xs font-semibold';
                        chip.innerHTML = `<span class="bg-white/20 rounded-full w-[18px] h-[18px] flex items-center justify-center text-[10px] font-extrabold">${idx + 1}</span> ${country} <i class="fas fa-xmark cursor-pointer opacity-75 hover:opacity-100 text-[11px]" data-idx="${idx}"></i>`;
                        chip.querySelector('.fa-xmark').addEventListener('click', () => {
                            countryChips.splice(idx, 1);
                            renderChips();
                        });
                        cWrap.appendChild(chip);
                    });

                    const pWrap = document.getElementById('positionChips');
                    pWrap.innerHTML = '';
                    positionChips.forEach((pos, idx) => {
                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-[7px] bg-[var(--navy)] text-white py-[6px] pr-[6px] pl-3 rounded-full text-xs font-semibold';
                        chip.innerHTML = `<span class="bg-white/20 rounded-full w-[18px] h-[18px] flex items-center justify-center text-[10px] font-extrabold">${idx + 1}</span> ${pos.name} <i class="fas fa-xmark cursor-pointer opacity-75 hover:opacity-100 text-[11px]" data-idx="${idx}"></i>`;
                        chip.querySelector('.fa-xmark').addEventListener('click', () => {
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

        /* Preference-match score breakdown — proof of how each job's % was calculated */
        <?php if ($has_preferences): ?>
            const MATCH_BREAKDOWNS = <?= json_encode($matchBreakdowns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

            function showMatchBreakdown(jobId) {
                const data = MATCH_BREAKDOWNS[jobId];
                if (!data) return;

                const rows = data.components.map(c => {
                    let icon, iconColor;
                    if (!c.applicable) {
                        icon = '−';
                        iconColor = '#9ca3af';
                    } else if (c.matched) {
                        icon = '✓';
                        iconColor = '#059669';
                    } else {
                        icon = '✕';
                        iconColor = '#dc2626';
                    }
                    return `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="color:${iconColor};font-weight:800;width:16px;text-align:center;">${icon}</span>
                            <span style="font-weight:700;font-size:12.5px;color:#1f2937;">${c.label}</span>
                        </div>
                        <span style="font-size:12px;font-weight:700;color:#4b5563;">${c.applicable ? c.earned + '/' + c.max + ' pts' : 'not scored'}</span>
                    </div>
                    <div style="font-size:11.5px;color:#6b7280;line-height:1.5;padding:2px 0 8px 24px;">${c.detail}</div>
                `;
                }).join('');

                Swal.fire({
                    title: `${data.score}% Match`,
                    html: `
                    <div style="text-align:left;">
                        ${rows}
                        <p style="margin-top:10px;font-size:11px;color:#9ca3af;line-height:1.5;">
                            Score = (points earned ÷ points scored) × 100. Components marked "not scored" have no comparable data yet (e.g. salary not disclosed) and are excluded so they don't count against you.
                        </p>
                    </div>
                `,
                    confirmButtonColor: '#1d4ed8',
                    confirmButtonText: 'Got it',
                    width: 440
                });
            }
        <?php endif; ?>

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
        const JOBS_PER_PAGE = 6;
        let currentPage = 1;

        // Tailwind class sets for JS-generated pagination number buttons
        const PAGE_BTN_BASE = 'min-w-[34px] h-[34px] border border-[var(--border)] bg-[var(--card)] text-[var(--text2)] rounded-[9px] text-xs font-bold cursor-pointer transition-colors hover:bg-[#eff6ff] hover:border-[#bfdbfe] hover:text-[#1e40af]';
        const PAGE_BTN_ACTIVE = 'min-w-[34px] h-[34px] border border-[#1d4ed8] bg-[#1d4ed8] text-white rounded-[9px] text-xs font-bold cursor-pointer transition-colors';

        function getFilteredCards() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const country = document.getElementById('countryFilter').value.toLowerCase().trim();
            const cards = Array.from(document.querySelectorAll('#jobList > div'));

            return cards.filter(card => {
                const text = card.textContent.toLowerCase();
                const matchesSearch = !search || text.includes(search);
                const matchesCountry = !country || text.includes(country);

                return matchesSearch && matchesCountry;
            });
        }

        function renderJobsPage() {
            const allCards = Array.from(document.querySelectorAll('#jobList > div'));
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
                paginationWrap.classList.add('hidden');
                pageNumbers.innerHTML = '';
                pageInfo.textContent = 'Page 0 of 0';
                prevPageBtn.disabled = true;
                nextPageBtn.disabled = true;
                return;
            }

            paginationWrap.classList.remove('hidden');
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
                btn.className = page === currentPage ? PAGE_BTN_ACTIVE : PAGE_BTN_BASE;
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