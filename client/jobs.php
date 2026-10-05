<?php
session_start();
require_once '../db.php';
require_once '../includes/matching.php';

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
$stmt = $database->prepare("SELECT cid, profile_picture FROM clients WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();
$client_id = (int) $client['cid'];
// Fetch client profile + dob/gender for qualification matching
$clientData = null;
$stmt = $database->prepare("
    SELECT c.dob, c.gender,
           cp.civil_status, cp.height_cm, cp.weight_kg, cp.educational_attainment,
           cp.degree_id, cp.course_degree, dp.degree_name AS profile_degree_name
    FROM clients c
    LEFT JOIN client_profiles cp ON cp.client_id = c.cid
    LEFT JOIN degree_programs dp ON dp.id = cp.degree_id
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

// Client's degree, as free text ("course_degree") and/or the linked
// degree_programs name — used to compare against rsf_qualifications'
// required_degree entries (which may list several acceptable degrees).
$clientDegrees = array_values(array_filter(array_unique([
    trim((string) ($clientData['profile_degree_name'] ?? '')),
    trim((string) ($clientData['course_degree'] ?? '')),
])));
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
    SELECT cp.preferred_classification, cp.salary_range_id,
           cp.preferred_employment_type,
           sr.min_salary, sr.max_salary, sr.range_label
    FROM client_preferences cp
    LEFT JOIN salary_ranges sr ON sr.id = cp.salary_range_id
    WHERE cp.client_id = ?
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

// Master skills list, for the "My Skills" picker
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

$skillsByCategory = [];
foreach ($allSkills as $sk) {
    $skillsByCategory[$sk['skill_category'] ?: 'Other'][] = $sk;
}

// Salary range options for the preference modal's dropdown
$salaryRanges = [];
$srResult = $database->query("SELECT id, range_label, min_salary, max_salary FROM salary_ranges ORDER BY sort_order ASC, min_salary ASC");
if ($srResult) $salaryRanges = $srResult->fetch_all(MYSQLI_ASSOC);

// The client's own skills (for pre-filling "My Skills" + for scoring)
$clientSkills = [];
$stmt = $database->prepare("
    SELECT cs.skill_id, s.skill_name, cs.proficiency_level, cs.years_experience
    FROM client_skills cs
    INNER JOIN skills s ON s.id = cs.skill_id
    WHERE cs.client_id = ?
    ORDER BY s.skill_name ASC
");

$stmt->bind_param("i", $client_id);
$stmt->execute();
$clientSkills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// skill_id => proficiency_level, for quick lookup during scoring
$clientSkillMap = [];
foreach ($clientSkills as $cs) {
    $clientSkillMap[(int) $cs['skill_id']] = $cs['proficiency_level'];
}

// Master professional licenses list, for the "My Licenses" picker
$allLicenses = [];
$licStmt = $database->query("SELECT id, license_name FROM professional_licenses ORDER BY license_name ASC");
if ($licStmt) {
    while ($l = $licStmt->fetch_assoc()) {
        $allLicenses[] = ['id' => (int) $l['id'], 'license_name' => (string) $l['license_name']];
    }
}

// The client's own held licenses (optional — may be empty)
$clientLicenses = [];
$stmt = $database->prepare("
    SELECT cl.license_id, pl.license_name
    FROM client_licenses cl
    INNER JOIN professional_licenses pl ON pl.id = cl.license_id
    WHERE cl.client_id = ?
    ORDER BY pl.license_name ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$clientLicenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Plain array of license names — used to compare against rsf_qualifications'
// required_license entries (which may list several acceptable licenses)
$clientLicenseNames = array_column($clientLicenses, 'license_name');

// Required/preferred skills per job order
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
        $skillsByRsf[$rid][] = [
            'skill_id' => (int) $rs['skill_id'],
            'skill_name' => (string) $rs['skill_name'],
            'importance' => (string) $rs['importance'],
            'min_proficiency' => (string) $rs['min_proficiency'],
        ];
    }
}

// Education rank for comparison


/*function checkQualifications(array $clientData, ?int $clientAge, array $quals, array $clientDegrees = [], array $clientLicenses = []): array
{
    $checks = [];
    $requiredDegrees = [];
    $requiredLicenses = [];
    $minHeight = null;
    $maxHeight = null;

    $addCheck = function (string $label, bool $met, string $detail) use (&$checks) {
        $checks[] = ['label' => $label, 'met' => $met, 'detail' => $detail];
    };

    foreach ($quals as $q) {
        $type  = $q['qualification_type'];
        $value = $q['qualification_value'];

        switch ($type) {
            case 'min_age':
                $met = $clientAge === null || $clientAge >= (int)$value;
                $addCheck('Minimum Age', $met, $clientAge === null
                    ? "Requires at least $value years old — your age isn't set in your profile."
                    : "Requires at least $value years old — you are $clientAge.");
                break;

            case 'max_age':
                $met = $clientAge === null || $clientAge <= (int)$value;
                $addCheck('Maximum Age', $met, $clientAge === null
                    ? "Must not exceed $value years old — your age isn't set in your profile."
                    : "Must not exceed $value years old — you are $clientAge.");
                break;

            case 'gender':
                $met = empty($clientData['gender']) || strtolower($value) === 'any'
                    || strtolower($clientData['gender']) === strtolower($value);
                $addCheck('Gender', $met, "Requires: $value" . (!empty($clientData['gender']) ? " (your profile: {$clientData['gender']})" : ' (not set in your profile)'));
                break;

            case 'min_height_cm':
                $minHeight = (float) $value;
                break;

            case 'max_height_cm':
                $maxHeight = (float) $value;
                break;
            case 'min_weight_kg':
                $met = empty($clientData['weight_kg']) || (float)$clientData['weight_kg'] >= (float)$value;
                $addCheck('Minimum Weight', $met, "Requires at least {$value} kg" . (!empty($clientData['weight_kg']) ? " (yours: {$clientData['weight_kg']} kg)" : ' (not set in your profile)'));
                break;

            case 'max_weight_kg':
                $met = empty($clientData['weight_kg']) || (float)$clientData['weight_kg'] <= (float)$value;
                $addCheck('Maximum Weight', $met, "Must not exceed {$value} kg" . (!empty($clientData['weight_kg']) ? " (yours: {$clientData['weight_kg']} kg)" : ' (not set in your profile)'));
                break;

            case 'min_education':
                $required  = EDU_RANK[$value] ?? 0;
                $clientEdu = EDU_RANK[$clientData['educational_attainment'] ?? ''] ?? 0;
                $met = $clientEdu === 0 || $clientEdu >= $required;
                $addCheck('Minimum Education', $met, "Requires: $value" . (!empty($clientData['educational_attainment']) ? " (yours: {$clientData['educational_attainment']})" : ' (not set in your profile)'));
                break;

            case 'civil_status':
                $met = empty($clientData['civil_status']) || strtolower($value) === 'any'
                    || strtolower($clientData['civil_status']) === strtolower($value);
                $addCheck('Civil Status', $met, "Requires: $value" . (!empty($clientData['civil_status']) ? " (yours: {$clientData['civil_status']})" : ' (not set in your profile)'));
                break;

            case 'required_degree':
                // Collected first; this job accepts ANY one of the listed degrees,
                // so it's evaluated once after the loop below.
                $requiredDegrees[] = $value;
                break;

            case 'required_license':
                // Same pattern as required_degree — this job accepts ANY one
                // of the listed licenses, evaluated once after the loop.
                $requiredLicenses[] = $value;
                break;
        }
    }

    if ($minHeight !== null || $maxHeight !== null) {
        $clientHeight   = $clientData['height_cm'] ?? null;
        $hasClientHeight = $clientHeight !== null && $clientHeight !== '';

        $rangeLabel = ($minHeight !== null && $maxHeight !== null)
            ? "{$minHeight}–{$maxHeight} cm"
            : ($minHeight !== null ? "at least {$minHeight} cm" : "at most {$maxHeight} cm");

        if (!$hasClientHeight) {
            $met = true; // height not set in profile — treated as unknown, not a fail
            $detail = "Requires {$rangeLabel} (not set in your profile).";
        } else {
            $ch = (float) $clientHeight;
            $meetsMin = $minHeight === null || $ch >= $minHeight;
            $meetsMax = $maxHeight === null || $ch <= $maxHeight;
            $met = $meetsMin && $meetsMax;
            $detail = "Requires {$rangeLabel} (yours: {$ch} cm).";
        }

        $addCheck('Height Requirement', $met, $detail);
    }

    if (!empty($requiredDegrees)) {
        $matchedDegree = false;
        foreach ($requiredDegrees as $rd) {
            foreach ($clientDegrees as $cd) {
                if ($cd !== '' && strcasecmp(trim($rd), trim($cd)) === 0) {
                    $matchedDegree = true;
                    break 2;
                }
            }
        }
        $degreeList = implode(' or ', $requiredDegrees);
        $addCheck('Required Degree', $matchedDegree, $matchedDegree
            ? "Your degree matches what this job accepts: $degreeList"
            : (empty($clientDegrees)
                ? "Requires a degree in: $degreeList (you haven't set your degree in your profile yet)"
                : "Requires a degree in: $degreeList (your profile shows: " . implode(' / ', $clientDegrees) . ")"));
    }

    if (!empty($requiredLicenses)) {
        $matchedLicense = false;
        foreach ($requiredLicenses as $rl) {
            foreach ($clientLicenses as $cl) {
                if ($cl !== '' && strcasecmp(trim($rl), trim($cl)) === 0) {
                    $matchedLicense = true;
                    break 2;
                }
            }
        }
        $licenseList = implode(' or ', $requiredLicenses);
        $addCheck('Required License', $matchedLicense, $matchedLicense
            ? "You hold a matching license: $licenseList"
            : (empty($clientLicenses)
                ? "Requires: $licenseList (you haven't added this license to your profile yet)"
                : "Requires: $licenseList (your profile shows: " . implode(' / ', $clientLicenses) . ")"));
    }

    $failed = array_values(array_filter($checks, fn($c) => !$c['met']));

    return [
        'eligible' => empty($failed),
        'checks'   => $checks,                          // full pass + fail list, for the checklist UI
        'reasons'  => array_column($failed, 'detail'),   // kept for backward compatibility
    ];
}*/

/**
 * Formats a ranked preference list for display, e.g. "Taiwan (#1), Japan (#2)".
 */
/*function formatPrefList(array $items, string $key): string
{
    $parts = [];
    foreach ($items as $item) {
        $parts[] = ($item[$key] ?? '') . ' (#' . (int) $item['priority_order'] . ')';
    }
    return implode(', ', $parts);
}*/

/**
 * Weighted preference-match score (0-100) for a job order against the
 * client's saved preferences, WITH a full per-component breakdown so the
 * applicant can see exactly why they got the score they did.
 *
 * Components with no comparable data on either side are excluded from the
 * denominator (marked applicable=false) so missing data never unfairly
 * penalizes a job — but they're still returned so the reason is visible.
 *
 * Weights: preferred country 30, preferred position 25, salary 20,
 * required skills 15, required degree 10.
 * (Classification is not scored — every job order here is "Government".)
 */
/*function computeMatchDetails(
    array $job,
    ?array $prefs,
    array $prefCountries,
    array $prefPositions,
    array $clientSkillMap,
    array $jobSkills,
    array $clientDegrees,
    array $jobRequiredDegrees
): array {
    $components  = [];
    $earnedTotal = 0.0;
    $maxTotal    = 0.0;

    // ── Preferred country (ranked — priority 1 scores highest) ──
    $countryMax     = 30;
    $countryEarned  = 0.0;
    $countryMatched = false;
    $countryDetail  = '';
    $countryApplic  = !empty($prefCountries);
    if ($countryApplic) {
        foreach ($prefCountries as $pc) {
            if (!empty($job['worksite_country']) && strcasecmp($job['worksite_country'], $pc['country']) === 0) {
                $rank = (int) $pc['priority_order'];
                $countryEarned  = max(30 - ($rank - 1) * 10, 10);
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
    $positionMax     = 25;
    $positionEarned  = 0.0;
    $positionMatched = false;
    $positionApplic  = !empty($prefPositions);
    if ($positionApplic) {
        foreach ($prefPositions as $pp) {
            if (!empty($job['subcategory_id']) && (int) $job['subcategory_id'] === (int) $pp['subcategory_id']) {
                $rank = (int) $pp['priority_order'];
                $positionEarned  = max(25 - ($rank - 1) * 8, 10);
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
                    $positionEarned  = max(25 - ($rank - 1) * 8, 10) * 0.6;
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

    // ── Required Skills (weighted: Required skills count double vs Preferred) ──
    $skillsMax     = 15;
    $skillsEarned  = 0.0;
    $skillsMatched = false;
    $skillsApplic  = !empty($jobSkills);
    $profRank = ['Beginner' => 1, 'Intermediate' => 2, 'Advanced' => 3, 'Expert' => 4];
    if ($skillsApplic) {
        $totalWeight  = 0;
        $earnedWeight = 0;
        $haveNames    = [];
        $missingNames = [];
        foreach ($jobSkills as $js) {
            $weight = (strcasecmp($js['importance'], 'Required') === 0) ? 2 : 1;
            $totalWeight += $weight;
            $clientProf = $clientSkillMap[(int) $js['skill_id']] ?? null;
            if ($clientProf !== null && ($profRank[$clientProf] ?? 1) >= ($profRank[$js['min_proficiency']] ?? 1)) {
                $earnedWeight += $weight;
                $haveNames[]   = $js['skill_name'];
            } else {
                $missingNames[] = $clientProf !== null
                    ? "{$js['skill_name']} (needs {$js['min_proficiency']}, you have {$clientProf})"
                    : $js['skill_name'];
            }
        }
        $ratio        = $totalWeight > 0 ? $earnedWeight / $totalWeight : 0;
        $skillsEarned = round($skillsMax * $ratio, 1);
        $skillsMatched = $ratio >= 0.999;
        if (!empty($haveNames) && !empty($missingNames)) {
            $skillsDetail = "You have: " . implode(', ', $haveNames) . ". Still missing: " . implode(', ', $missingNames) . ".";
        } elseif (!empty($haveNames)) {
            $skillsDetail = "You already have all the listed skills: " . implode(', ', $haveNames) . ".";
        } else {
            $skillsDetail = "This job is looking for: " . implode(', ', array_column($jobSkills, 'skill_name')) . " — none of these are on your profile yet.";
        }
        $earnedTotal += $skillsEarned;
        $maxTotal    += $skillsMax;
    } else {
        $skillsDetail = "This job order doesn't have specific skill requirements listed.";
    }
    $components[] = [
        'label'      => 'Required Skills',
        'earned'     => $skillsEarned,
        'max'        => $skillsMax,
        'matched'    => $skillsMatched,
        'applicable' => $skillsApplic,
        'detail'     => $skillsDetail,
    ];

    // ── Required Degree (job accepts ANY one of the listed degrees) ──
    $degreeMax     = 10;
    $degreeEarned  = 0.0;
    $degreeMatched = false;
    $degreeApplic  = !empty($jobRequiredDegrees);
    if ($degreeApplic) {
        foreach ($jobRequiredDegrees as $rd) {
            foreach ($clientDegrees as $cd) {
                if ($cd !== '' && strcasecmp(trim($rd), trim($cd)) === 0) {
                    $degreeMatched = true;
                    break 2;
                }
            }
        }
        $degreeList = implode(' or ', $jobRequiredDegrees);
        if ($degreeMatched) {
            $degreeEarned = $degreeMax;
            $degreeDetail = "Your degree matches what this job accepts ({$degreeList}).";
        } else {
            $degreeDetail = empty($clientDegrees)
                ? "This job requires a degree in: {$degreeList} — you haven't set your degree in your profile yet."
                : "This job requires a degree in: {$degreeList}; your profile shows " . implode(' / ', $clientDegrees) . ".";
        }
        $earnedTotal += $degreeEarned;
        $maxTotal    += $degreeMax;
    } else {
        $degreeDetail = "This job doesn't require a specific degree.";
    }
    $components[] = [
        'label'      => 'Required Degree',
        'earned'     => $degreeEarned,
        'max'        => $degreeMax,
        'matched'    => $degreeMatched,
        'applicable' => $degreeApplic,
        'detail'     => $degreeDetail,
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
    $prefHasSalary = !empty($prefs['salary_range_id']) && !empty($prefs['min_salary']);
    $jobHasSalary  = !empty($job['salary_min_php']) || !empty($job['salary_max_php']);
    $salaryApplic  = $prefHasSalary && $jobHasSalary;

    if ($salaryApplic) {
        $clientMin = (float)$prefs['min_salary'];
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
}*/

// Attach preference-match score (+ full breakdown) to each job order, then
// rank best matches first. $matchBreakdowns is exposed to JS further down
// so the applicant can see proof of how their score was calculated.
$matchBreakdowns = [];
$qualificationChecks = [];
foreach ($jobOrders as &$job) {
    $jobId = (int) $job['id'];
    $jobQuals = $qualsByRsf[$jobId] ?? [];

    // Qualification checklist is computed once here, for every job order —
    // regardless of whether preferences are set — and reused by both the
    // card badge and the render loop below (no duplicate computation).
    $qualCheck = (!empty($jobQuals) && $clientData)
        ? checkQualifications($clientData, $clientAge, $jobQuals, $clientDegrees, $clientLicenseNames, 'second')
        : ['eligible' => true, 'checks' => [], 'reasons' => []];
    $job['qual_eligible'] = $qualCheck['eligible'];
    $job['has_quals']     = !empty($jobQuals);
    $qualificationChecks[$jobId] = $qualCheck['checks'];

    if ($has_preferences) {
        $jobSkills = $skillsByRsf[$jobId] ?? [];
        $jobRequiredDegrees = array_values(array_map(
            fn($q) => $q['qualification_value'],
            array_filter($jobQuals, fn($q) => $q['qualification_type'] === 'required_degree')
        ));
        $details = computeMatchDetails(
            $job,
            $clientPrefs,
            $prefCountries,
            $prefPositions,
            $clientSkillMap,
            $jobSkills,
            $clientDegrees,
            $jobRequiredDegrees,
            'second'
        );
        $job['match_score'] = $details['score'];
        $matchBreakdowns[$jobId] = $details;
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

if ($has_preferences) {
    $jobOrders = array_values(array_filter(
        $jobOrders,
        fn($job) => $job['match_score'] !== null && $job['match_score'] >= 0 // only show jobs with a score of 60 or higher
    ));
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
// Avatar initials from session name
$full_name = $_SESSION['full_name'] ?? 'Client';
$parts     = explode(' ', trim($full_name));
$initials  = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($full_name, 0, 2));

$has_picture = !empty($client['profile_picture']);
$picture_url = $has_picture ? '../' . $client['profile_picture'] : null;
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

        /* ══════ Small-screen compaction ══════
           Shrinks the shared topbar/subbar chrome (defined in dashboard.css,
           not editable here) on narrow viewports so the page reads as a
           compact mobile layout instead of a shrunken desktop one. */
        @media (max-width: 640px) {
            .topbar {
                padding: 8px 12px;
            }

            .topbar-brand img {
                width: 32px;
            }

            .topbar-brand h1 {
                font-size: 12.5px;
            }

            .topbar-brand p {
                font-size: 8.5px;
            }

            .topbar-date {
                display: none;
            }

            .tb-btn {
                width: 30px;
                height: 30px;
                font-size: 12px;
            }

            .topbar-avatar {
                width: 30px;
                height: 30px;
                font-size: 11px;
            }

            .subbar {
                padding: 10px 12px 8px;
            }

            .subbar-greeting h2 {
                font-size: 15px;
            }

            .subbar-greeting p {
                font-size: 10.5px;
            }

            .filter-pill {
                font-size: 10.5px;
                padding: 6px 10px;
            }

            .dock {
                padding: 6px 8px;
            }

            .dk {
                font-size: 9px;
            }

            .dk i {
                font-size: 15px;
            }
        }

        @media (max-width: 400px) {
            .topbar-brand p {
                display: none;
            }
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
            <!--<div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>-->
            <div class="topbar-avatar" <?= $has_picture ? "style=\"background-image:url('" . htmlspecialchars($picture_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                <?= $has_picture ? '' : htmlspecialchars($initials) ?>
            </div>
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
    <div class="max-w-[1400px] mx-auto px-2.5 sm:px-3.5 pt-2.5 sm:pt-3.5 pb-0">

        <?php if ($show_pref_modal): ?>
            <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] flex items-start gap-2 sm:gap-3 bg-[#eff6ff] border border-[#bfdbfe] rounded-xl py-2.5 sm:py-3.5 px-3 sm:px-4 mb-2.5 sm:mb-3">
                <i class="fas fa-lock text-[var(--blue)] text-base sm:text-lg mt-0.5"></i>
                <div>
                    <h4 class="text-[11px] sm:text-[12.5px] font-extrabold text-[var(--navy)]">Set Your Job Preferences to Unlock Listings</h4>
                    <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text2)] mt-[3px] leading-relaxed">Tell us your preferred classification, destination countries, and positions so we can show you the best-matching job orders first.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search & Filter bar -->
        <div class="relative z-30 opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.05s] bg-[var(--card)] border border-[var(--border)] rounded-xl sm:rounded-[14px] p-2.5 sm:p-3.5 mb-2.5 sm:mb-3.5 flex flex-col gap-2 sm:gap-2.5 sm:flex-row sm:items-center">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-2.5 sm:left-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-[11px] sm:text-xs pointer-events-none"></i>
                <input type="text" id="searchInput"
                    class="w-full py-2 sm:py-[9px] pr-8 sm:pr-9 pl-8 sm:pl-[34px] border border-[var(--border)] rounded-lg sm:rounded-[10px] text-[12px] sm:text-[13px] font-medium text-[var(--text1)] bg-[var(--surface)] outline-none transition-colors focus:border-[var(--blue-lt)] focus:bg-white placeholder:text-[var(--text3)]"
                    placeholder="Search position, employer, country, RSF number…">
                <button type="button" id="searchClear"
                    class="absolute right-2.5 sm:right-3 top-1/2 -translate-y-1/2 text-[var(--text3)] text-[11px] sm:text-xs hidden hover:text-[var(--text1)]" title="Clear search">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <select id="countryFilter"
                class="py-2 sm:py-[9px] px-2.5 sm:px-3 border border-[var(--border)] rounded-lg sm:rounded-[10px] text-[12px] sm:text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] outline-none cursor-pointer transition-colors focus:border-[var(--blue-lt)]">
                <option value="">All Countries</option>
                <?php foreach ($countries as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- ══════ NEW: Structured Qualifications filter toggle ══════
                 Opt-in checkbox that hides jobs the client is not structurally
                 eligible for (per rsf_qualifications), wired into the same
                 applyFilters()/getFilteredCards() pipeline as search/country. -->
            <label class="flex items-center gap-1.5 sm:gap-2 py-2 sm:py-[9px] px-2.5 sm:px-3 border border-[var(--border)] rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-semibold text-[var(--text2)] bg-[var(--surface)] cursor-pointer select-none whitespace-nowrap">
                <input type="checkbox" id="eligibleOnlyToggle" class="w-3.5 h-3.5 sm:w-[15px] sm:h-[15px] accent-[var(--blue)] cursor-pointer">
                Show only jobs I qualify for
            </label>

            <?php if ($has_preferences): ?>
                <select id="matchTierFilter"
                    class="py-2 sm:py-[9px] px-2.5 sm:px-3 border border-[var(--border)] rounded-lg sm:rounded-[10px] text-[12px] sm:text-[13px] font-semibold text-[var(--text2)] bg-[var(--surface)] outline-none cursor-pointer transition-colors focus:border-[var(--blue-lt)]">
                    <option value="">All Match Levels</option>
                    <option value="strong">Strong Match (70%+)</option>
                    <option value="fair">Fair Match (40–69%)</option>
                    <option value="low">Low Match (&lt;40%)</option>
                </select>
            <?php endif; ?>

            <button onclick="applyFilters()"
                class="py-2 sm:py-[9px] px-4 sm:px-5 bg-[var(--blue)] text-white border-none rounded-lg sm:rounded-[10px] text-[12px] sm:text-[13px] font-bold cursor-pointer transition-colors hover:bg-[#1e40af] whitespace-nowrap">
                <i class="fas fa-search"></i> Search
            </button>

            <button type="button" onclick="showDetailsTipBanner()"
                class="py-2 sm:py-[9px] px-2.5 sm:px-3 border border-[var(--border)] bg-[var(--surface)] text-[var(--text3)] rounded-lg sm:rounded-[10px] text-[12px] sm:text-[13px] font-bold cursor-pointer transition-colors hover:bg-[#eff6ff] hover:border-[#bfdbfe] hover:text-[#1e40af] whitespace-nowrap flex-shrink-0"
                title="How does eligibility & match scoring work?"
                aria-label="Show help about eligibility and match scoring">
                <i class="fas fa-circle-question"></i>
            </button>
        </div>

        <?php if ($has_preferences): ?>
            <div class="flex flex-wrap items-center gap-x-3 sm:gap-x-4 gap-y-1 mb-2.5 sm:mb-3.5 -mt-1 text-[9.5px] sm:text-[10.5px] text-[var(--text3)] font-medium">
                <span class="font-bold text-[var(--text2)]">Match score:</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[#059669]"></span>70–100% Strong</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[#ca8a04]"></span>40–69% Fair</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[#9ca3af]"></span>Below 40% Low</span>
                <span class="opacity-75 hidden sm:inline">— reflects fit to your saved preferences, not eligibility.</span>
            </div>
        <?php endif; ?>

        <div id="detailsTipBanner" class="flex items-start gap-2 mb-2.5 sm:mb-3.5 -mt-1 py-1.5 sm:py-2 px-2.5 sm:px-3 bg-[#fffbeb] border border-[#fde68a] rounded-lg sm:rounded-[10px] text-[10px] sm:text-[11px] text-[#92400e] font-semibold">
            <i class="fas fa-circle-info flex-shrink-0 mt-0.5"></i>
            <span class="flex-1">Tap the badge (e.g. <span class="whitespace-nowrap"><i class="fas fa-star"></i> "Strong Match"</span> or <span class="whitespace-nowrap"><i class="fas fa-triangle-exclamation"></i> "Requirements Not Met"</span>) on any job card to see exactly which requirements you meet and how your preferences were scored.</span>
            <button type="button" onclick="dismissDetailsTipBanner()" class="flex-shrink-0 w-5 h-5 flex items-center justify-center rounded-full text-[#92400e] hover:bg-[#fde68a] transition-colors" title="Dismiss this tip" aria-label="Dismiss this tip">
                <i class="fas fa-xmark text-[11px]"></i>
            </button>
        </div>

        <!-- Job Cards -->
        <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.1s] grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5 sm:gap-3 mb-4" id="jobList">

            <?php if ($show_pref_modal): ?>
                <div class="py-10 sm:py-[60px] px-4 sm:px-5 text-center col-span-full">
                    <i class="fas fa-lock text-3xl sm:text-4xl text-[var(--border)] block mb-2.5 sm:mb-3"></i>
                    <p class="text-[13px] sm:text-sm font-semibold text-[var(--text3)]">Set your job preferences to view listings.</p>
                    <span class="text-[11px] sm:text-xs text-[var(--text3)] opacity-70">Job orders will appear here once your preferences are saved.</span>
                </div>
            <?php elseif (empty($jobOrders)): ?>
                <div class="py-10 sm:py-[60px] px-4 sm:px-5 text-center col-span-full">
                    <i class="fas fa-briefcase text-3xl sm:text-4xl text-[var(--border)] block mb-2.5 sm:mb-3"></i>
                    <p class="text-[13px] sm:text-sm font-semibold text-[var(--text3)]">No open job opportunities at the moment.</p>
                    <span class="text-[11px] sm:text-xs text-[var(--text3)] opacity-70">Please check back later or follow DMW announcements.</span>
                </div>
            <?php else: ?>
                <?php foreach ($jobOrders as $job):
                    $isGov     = ($job['classification'] === 'Government');
                    $hasApplied = in_array((int)$job['id'], $appliedRsfIds, true);
                    $slotsLeft = max(0, (int) ($job['slots_available'] ?? 0));
                    $isFull = $slotsLeft <= 0;
                    $qualEligible = $job['qual_eligible'];
                    $hasQuals     = $job['has_quals'];
                    $topStripe = $isGov ? 'before:bg-[var(--green)]' : 'before:bg-[var(--blue-lt)]';
                ?>
                    <div class="relative bg-[var(--card)] border border-[var(--border)] rounded-xl sm:rounded-[14px] overflow-hidden transition-all hover:-translate-y-1 hover:shadow-[0_14px_30px_rgba(11,31,75,0.10)] active:scale-[.98] before:content-[''] before:absolute before:top-0 before:left-0 before:right-0 before:h-[3px] before:rounded-t-xl sm:before:rounded-t-[14px] <?= $topStripe ?>"
                        data-job-id="<?= (int) $job['id'] ?>"
                        data-eligible="<?= $qualEligible ? '1' : '0' ?>"
                        data-has-quals="<?= $hasQuals ? '1' : '0' ?>"
                        data-match-score="<?= $job['match_score'] !== null ? (int) $job['match_score'] : '' ?>">
                        <div class="p-3 sm:p-4">

                            <div class="flex justify-between items-start gap-2 sm:gap-2.5 mb-2.5 sm:mb-3">
                                <div>
                                    <div class="font-['DM_Serif_Display'] text-[13.5px] sm:text-[15px] text-[var(--text1)] leading-[1.3]"><?= htmlspecialchars($job['position_title']) ?></div>
                                    <div class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] font-medium mt-[3px]"><?= htmlspecialchars($job['employer_name']) ?></div>
                                </div>
                                <div class="flex flex-col items-end gap-1 sm:gap-[5px]">
                                    <span class="text-[8.5px] sm:text-[9.5px] font-bold py-[3px] sm:py-1 px-2 sm:px-2.5 rounded-full whitespace-nowrap flex-shrink-0 tracking-[.03em] <?= $isGov ? 'bg-[#d1fae5] text-[#065f46]' : 'bg-[#eff6ff] text-[#1e40af]' ?>">
                                        <?= htmlspecialchars($job['classification']) ?>
                                    </span>
                                    <?php
                                    // ══════ UNIFIED "View Details" badge ══════
                                    // One entry point instead of two separate badges/modals. Priority:
                                    // failed requirements > preference match tier > passed requirements.
                                    // Always opens the same modal (showJobDetails), which shows whichever
                                    // sections apply — decoupled from eligibility, so a client who fails a
                                    // hard requirement can still see their preference fit for that job.
                                    $showDetailsBadge = $hasQuals || ($has_preferences && $job['match_score'] !== null);
                                    if ($showDetailsBadge):
                                        if ($hasQuals && !$qualEligible) {
                                            $badgeClasses = 'bg-[#fef2f2] text-[#b91c1c]';
                                            $badgeIcon = 'fa-triangle-exclamation';
                                            $badgeLabel = 'Requirements Not Met';
                                        } elseif ($has_preferences && $job['match_score'] !== null) {
                                            $score = (int) $job['match_score'];
                                            if ($score >= 70) {
                                                $badgeClasses = 'bg-[#d1fae5] text-[#065f46]';
                                                $badgeLabel = "Strong Match · {$score}%";
                                            } elseif ($score >= 40) {
                                                $badgeClasses = 'bg-[#fef9c3] text-[#854d0e]';
                                                $badgeLabel = "Fair Match · {$score}%";
                                            } else {
                                                $badgeClasses = 'bg-[#f3f4f6] text-[#4b5563]';
                                                $badgeLabel = "Low Match · {$score}%";
                                            }
                                            $badgeIcon = 'fa-star';
                                        } else {
                                            $badgeClasses = 'bg-[#d1fae5] text-[#065f46]';
                                            $badgeIcon = 'fa-circle-check';
                                            $badgeLabel = 'Requirements Met';
                                        }
                                    ?>
                                        <button type="button"
                                            class="inline-flex items-center gap-1 sm:gap-[5px] text-[8.5px] sm:text-[9.5px] font-bold tracking-[.03em] py-[2px] sm:py-[3px] px-2 sm:px-2.5 rounded-full whitespace-nowrap cursor-pointer border-none <?= $badgeClasses ?>"
                                            onclick="showJobDetails(<?= (int) $job['id'] ?>)"
                                            title="Click to see your full eligibility checklist and match breakdown"
                                            aria-label="View eligibility and match details for <?= htmlspecialchars($job['position_title'], ENT_QUOTES) ?>">
                                            <i class="fas <?= $badgeIcon ?>"></i> <?= htmlspecialchars($badgeLabel) ?> <i class="fas fa-circle-info ml-[2px] opacity-70"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 sm:gap-[5px] text-[8.5px] sm:text-[9.5px] font-semibold tracking-[.03em] py-[2px] sm:py-[3px] px-2 sm:px-2.5 rounded-full whitespace-nowrap bg-[#f3f4f6] text-[#6b7280]" title="This job order has no specific eligibility requirements posted, and preferences aren't set or scored for this job.">
                                            <i class="fas fa-circle-info"></i> No Special Requirements
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($hasApplied): ?>
                                        <span class="inline-flex items-center gap-1 sm:gap-[5px] text-[8.5px] sm:text-[9.5px] font-bold tracking-[.03em] py-[2px] sm:py-[3px] px-2 sm:px-2.5 rounded-full whitespace-nowrap bg-[#eff6ff] text-[#1e40af]">
                                            <i class="fas fa-circle-check"></i> Applied
                                        </span>
                                    <?php elseif ($isFull): ?>
                                        <span class="inline-flex items-center gap-1 sm:gap-[5px] text-[8.5px] sm:text-[9.5px] font-bold tracking-[.03em] py-[2px] sm:py-[3px] px-2 sm:px-2.5 rounded-full whitespace-nowrap bg-[#fef2f2] text-[#b91c1c]">
                                            <i class="fas fa-ban"></i> No Slots
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex flex-col gap-1.5 sm:gap-[7px] mb-2.5 sm:mb-3.5">
                                <div class="flex items-center gap-2 sm:gap-[9px] text-[11px] sm:text-xs text-[var(--text2)] font-medium">
                                    <i class="fas fa-location-dot w-4 text-center text-[var(--text3)] text-[10px] sm:text-[11px] flex-shrink-0"></i>
                                    <span><?= htmlspecialchars($job['worksite_country'] ?: 'Not specified') ?></span>
                                </div>
                                <div class="flex items-center gap-2 sm:gap-[9px] text-[11px] sm:text-xs text-[var(--text2)] font-medium">
                                    <i class="fas fa-users w-4 text-center text-[var(--text3)] text-[10px] sm:text-[11px] flex-shrink-0"></i>
                                    <span><strong class="text-[var(--text1)] font-bold"><?= number_format($slotsLeft) ?></strong> slot<?= $slotsLeft !== 1 ? 's' : '' ?> available</span>
                                </div>
                                <div class="flex items-center gap-2 sm:gap-[9px] text-[11px] sm:text-xs text-[var(--text2)] font-medium">
                                    <i class="fas fa-calendar-alt w-4 text-center text-[var(--text3)] text-[10px] sm:text-[11px] flex-shrink-0"></i>
                                    <span>Deadline: <strong class="text-[var(--text1)] font-bold"><?= $job['deadline'] ? date('M d, Y', strtotime($job['deadline'])) : 'Open' ?></strong></span>
                                </div>
                                <?php if (!empty($job['rsf_number'])): ?>
                                    <div class="flex items-center gap-2 sm:gap-[9px] text-[11px] sm:text-xs text-[var(--text2)] font-medium">
                                        <i class="fas fa-hashtag w-4 text-center text-[var(--text3)] text-[10px] sm:text-[11px] flex-shrink-0"></i>
                                        <span><?= htmlspecialchars($job['rsf_number']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="flex gap-2">
                                <?php if ($hasApplied): ?>
                                    <button type="button" class="flex-1 flex items-center justify-center gap-1.5 py-2 sm:py-[9px] px-2 sm:px-2.5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-xs font-bold cursor-pointer border-none transition-all active:scale-[.97] bg-[#f1f5f9] text-[#64748b]" onclick="alreadyAppliedForJob()" title="You have already applied for this position">
                                        <i class="fas fa-circle-check"></i> Already Applied
                                    </button>
                                <?php elseif ($isFull): ?>
                                    <button type="button" class="flex-1 flex items-center justify-center gap-1.5 py-2 sm:py-[9px] px-2 sm:px-2.5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-xs font-bold cursor-pointer border-none transition-all active:scale-[.97] bg-[#f1f5f9] text-[#64748b]" onclick="noSlotsAvailable()" title="No available slots for this job">
                                        <i class="fas fa-ban"></i> No Slots Available
                                    </button>
                                <?php else: ?>
                                    <!-- Not yet applied: normal Apply button. Eligibility flag passed through
                                         so applyForJob() can warn the client before they proceed, without
                                         hard-blocking — evaluators still make the final call. -->
                                    <button class="flex-1 flex items-center justify-center gap-1.5 py-2 sm:py-[9px] px-2 sm:px-2.5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-xs font-bold cursor-pointer border-none transition-all active:scale-[.97] bg-[var(--blue)] text-white hover:bg-[#1e40af]" onclick="applyForJob(<?= (int)$job['id'] ?>, <?= $qualEligible ? 'true' : 'false' ?>)">
                                        <i class="fas fa-paper-plane"></i> Apply Now
                                    </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

        <div class="opacity-0 translate-y-2.5 [animation:fi2_0.4s_ease_forwards] [animation-delay:.15s] flex items-center justify-center gap-1.5 sm:gap-2.5 my-2 mb-[18px] hidden flex-wrap" id="paginationWrap">
            <button type="button" id="prevPageBtn" onclick="changePage(-1)"
                class="border border-[var(--border)] bg-[var(--card)] text-[var(--text2)] rounded-lg sm:rounded-[10px] py-1.5 sm:py-2 px-2.5 sm:px-3.5 text-[11px] sm:text-xs font-bold cursor-pointer transition-colors [&:not(:disabled):hover]:bg-[#eff6ff] [&:not(:disabled):hover]:border-[#bfdbfe] [&:not(:disabled):hover]:text-[#1e40af] disabled:cursor-not-allowed disabled:opacity-50">
                <i class="fas fa-angle-left"></i> <span class="hidden sm:inline">Previous</span>
            </button>
            <div class="flex items-center gap-1 sm:gap-1.5" id="pageNumbers"></div>
            <span class="text-[11px] sm:text-xs font-semibold text-[var(--text3)] min-w-[80px] sm:min-w-[90px] text-center" id="pageInfo">Page 1 of 1</span>
            <button type="button" id="nextPageBtn" onclick="changePage(1)"
                class="border border-[var(--border)] bg-[var(--card)] text-[var(--text2)] rounded-lg sm:rounded-[10px] py-1.5 sm:py-2 px-2.5 sm:px-3.5 text-[11px] sm:text-xs font-bold cursor-pointer transition-colors [&:not(:disabled):hover]:bg-[#eff6ff] [&:not(:disabled):hover]:border-[#bfdbfe] [&:not(:disabled):hover]:text-[#1e40af] disabled:cursor-not-allowed disabled:opacity-50">
                <span class="hidden sm:inline">Next</span> <i class="fas fa-angle-right"></i>
            </button>
        </div>

    </div><!-- /main -->


    <!--FLOATING DOCK -->
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

            <div class="dk" onclick="openSkillsModal()">
                <i class="fas fa-star"></i>
                <span class="dk-lbl">My Skills</span>
            </div>

            <div class="dk" onclick="openLicensesModal()">
                <i class="fas fa-id-card"></i>
                <span class="dk-lbl">My Licenses</span>
            </div>

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

    <script>
        /* ============================================================
           CUSTOM SELECT — shared across the Job Preferences wizard,
           My Skills modal, and My Licenses modal so every dropdown
           looks and behaves the same. */
        function enhanceSelect(select, opts) {
            opts = opts || {};
            const searchable = opts.searchable !== false && select.options.length > 8;
            const placeholderOpt = select.querySelector('option[value=""]');
            const placeholder = opts.placeholder || (placeholderOpt ? placeholderOpt.textContent : 'Select…');

            const wrapper = document.createElement('div');
            let sizeClass = 'relative';
            if (select.classList.contains('flex-1')) sizeClass += ' flex-1 min-w-0';
            else if (select.classList.contains('w-full')) sizeClass += ' w-full';
            wrapper.className = sizeClass;

            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(select);
            select.classList.add('hidden');
            select.tabIndex = -1;

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.className = 'csel-trigger w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] bg-white flex items-center justify-between gap-2 cursor-pointer transition-colors hover:border-[var(--blue-lt)]';
            trigger.innerHTML = '<span class="csel-trigger-label truncate text-[var(--text3)]"></span>' +
                '<i class="fas fa-chevron-down text-[10px] text-[var(--text3)] transition-transform flex-shrink-0"></i>';
            wrapper.appendChild(trigger);

            // ══════ Panel is now attached to <body> (a "portal"), positioned
            // fixed via getBoundingClientRect(), so it's never clipped by any
            // ancestor's overflow:auto (e.g. the scrolling modal body). ══════
            const panel = document.createElement('div');
            panel.setAttribute('role', 'listbox');
            panel.className = 'csel-panel hidden fixed bg-white border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] shadow-[0_10px_30px_rgba(11,31,75,.16)] z-[9999] overflow-hidden';
            let searchInput = null;
            if (searchable) {
                panel.innerHTML =
                    '<div class="p-2 border-b border-[var(--border)]">' +
                    '  <div class="relative">' +
                    '    <i class="fas fa-magnifying-glass absolute left-2.5 top-1/2 -translate-y-1/2 text-[10px] text-[var(--text3)]"></i>' +
                    '    <input type="text" class="csel-search w-full py-1.5 pl-7 pr-2 border border-[var(--border)] rounded-md text-[11.5px] outline-none focus:border-[var(--blue-lt)]" placeholder="Search…">' +
                    '  </div>' +
                    '</div>' +
                    '<div class="csel-list max-h-[210px] overflow-y-auto py-1"></div>';
            } else {
                panel.innerHTML = '<div class="csel-list max-h-[210px] overflow-y-auto py-1"></div>';
            }
            document.body.appendChild(panel);
            const list = panel.querySelector('.csel-list');
            if (searchable) searchInput = panel.querySelector('.csel-search');

            function buildOptionRow(optionEl) {
                const row = document.createElement('div');
                row.setAttribute('role', 'option');
                row.className = 'csel-option flex items-center justify-between gap-2 py-[7px] px-3 text-[12px] sm:text-[12.5px] text-[var(--text1)] cursor-pointer hover:bg-[#f1f5fb] transition-colors';
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

            function renderList(filterText) {
                list.innerHTML = '';
                const filter = (filterText || '').trim().toLowerCase();
                let any = false;

                if (placeholderOpt && !select.required && (!filter || placeholder.toLowerCase().includes(filter))) {
                    any = true;
                    list.appendChild(buildOptionRow(placeholderOpt));
                }

                Array.from(select.children).forEach(child => {
                    if (child.tagName === 'OPTGROUP') {
                        const items = Array.from(child.children).filter(o => !o.hidden && (!filter || o.textContent.toLowerCase().includes(filter)));
                        if (!items.length) return;
                        any = true;
                        const groupLabel = document.createElement('div');
                        groupLabel.className = 'px-3 pt-2 pb-1 text-[9.5px] font-bold uppercase tracking-[.05em] text-[var(--text3)]';
                        groupLabel.textContent = child.label;
                        list.appendChild(groupLabel);
                        items.forEach(o => list.appendChild(buildOptionRow(o)));
                    } else if (child.tagName === 'OPTION') {
                        if (child.value === '') return;
                        if (child.hidden) return;
                        if (filter && !child.textContent.toLowerCase().includes(filter)) return;
                        any = true;
                        list.appendChild(buildOptionRow(child));
                    }
                });
                if (!any) {
                    const empty = document.createElement('div');
                    empty.className = 'py-4 text-center text-[11px] text-[var(--text3)]';
                    empty.textContent = 'No matches found.';
                    list.appendChild(empty);
                }
                markSelected();
            }

            function markSelected() {
                list.querySelectorAll('.csel-option').forEach(row => {
                    const selected = select.value !== '' && row.dataset.value === select.value;
                    row.classList.toggle('bg-[#eff6ff]', selected);
                    row.classList.toggle('text-[var(--navy)]', selected);
                    row.classList.toggle('font-bold', selected);
                    let check = row.querySelector('.fa-check');
                    if (selected && !check) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-check text-[10px] text-[var(--blue)] flex-shrink-0';
                        row.appendChild(icon);
                    } else if (!selected && check) {
                        check.remove();
                    }
                });
            }

            function refresh() {
                const opt = select.options[select.selectedIndex];
                const label = trigger.querySelector('.csel-trigger-label');
                if (select.value === '' || !opt) {
                    label.textContent = placeholder;
                    label.classList.add('text-[var(--text3)]');
                    label.classList.remove('text-[var(--text1)]', 'font-semibold');
                } else {
                    label.textContent = opt.textContent;
                    label.classList.remove('text-[var(--text3)]');
                    label.classList.add('text-[var(--text1)]', 'font-semibold');
                }
                markSelected();
            }

            // Position the (now body-level) panel against the trigger, flipping
            // upward automatically if there isn't enough room below.
            function positionPanel() {
                const rect = trigger.getBoundingClientRect();
                const panelHeight = panel.offsetHeight || 260; // estimate before first paint
                const spaceBelow = window.innerHeight - rect.bottom;
                const spaceAbove = rect.top;
                const openUp = opts.openUpward === true ?
                    spaceAbove > spaceBelow || spaceBelow < 160 :
                    spaceBelow < panelHeight && spaceAbove > spaceBelow;

                panel.style.left = rect.left + 'px';
                panel.style.width = rect.width + 'px';

                if (openUp) {
                    panel.style.top = 'auto';
                    panel.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
                } else {
                    panel.style.bottom = 'auto';
                    panel.style.top = (rect.bottom + 6) + 'px';
                }
            }

            function openPanel() {
                document.querySelectorAll('.csel-panel').forEach(p => {
                    if (p !== panel) p.classList.add('hidden');
                });
                panel.classList.remove('hidden');
                positionPanel();
                trigger.classList.add('border-[var(--blue-lt)]');
                trigger.setAttribute('aria-expanded', 'true');
                trigger.querySelector('.fa-chevron-down').classList.add('rotate-180');
                renderList('');
                positionPanel(); // re-measure now that content/height is real
                if (searchInput) {
                    searchInput.value = '';
                    setTimeout(() => searchInput.focus(), 10);
                }
                window.addEventListener('scroll', positionPanel, true);
                window.addEventListener('resize', positionPanel);
            }

            function closePanel() {
                panel.classList.add('hidden');
                trigger.classList.remove('border-[var(--blue-lt)]');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.querySelector('.fa-chevron-down').classList.remove('rotate-180');
                window.removeEventListener('scroll', positionPanel, true);
                window.removeEventListener('resize', positionPanel);
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
            if (searchInput) {
                searchInput.addEventListener('input', () => renderList(searchInput.value));
                searchInput.addEventListener('click', (e) => e.stopPropagation());
            }
            document.addEventListener('click', closePanel);

            refresh();
            return {
                refresh,
                closePanel
            };
        }
        window.enhanceSelect = enhanceSelect;
    </script>
    <?php if ($show_pref_modal): ?>
        <div id="prefModal" class="fixed inset-0 bg-[rgba(11,31,75,0.6)] backdrop-blur-sm flex items-center justify-center z-[600] p-3 sm:p-4">
            <div class="bg-white rounded-2xl sm:rounded-[18px] w-full max-w-[600px] max-h-[92vh] sm:max-h-[90vh] overflow-y-auto shadow-[0_24px_60px_rgba(11,31,75,0.3)]">
                <div class="flex items-center justify-between py-3 sm:py-4 px-3.5 sm:px-[18px] border-b border-[var(--border)] sticky top-0 bg-white rounded-t-2xl sm:rounded-t-[18px] z-[2]">
                    <h3 class="font-['DM_Serif_Display'] text-[14px] sm:text-base text-[var(--navy)] flex items-center gap-2 sm:gap-[9px]">
                        <span class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg sm:rounded-[9px] bg-[#eff6ff] flex items-center justify-center text-[var(--blue)] text-[13px] sm:text-sm flex-shrink-0">
                            <i class="fas fa-sliders"></i>
                        </span>
                        Set Your Job Preferences
                    </h3>
                    <!-- No close button — must complete before browsing -->
                </div>
                <div class="p-3.5 sm:p-[18px]">

                    <div class="flex items-center gap-0 mb-4 sm:mb-[22px]" id="prefStepIndicator">
                        <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-full flex items-center justify-center text-[10px] sm:text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--navy)] text-white" id="pn1">1</div>
                        <div class="flex-1 h-0.5 bg-[var(--border)] transition-colors" id="pl1"></div>
                        <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-full flex items-center justify-center text-[10px] sm:text-[11px] font-extrabold flex-shrink-0 transition-colors bg-[var(--border)] text-[var(--text3)]" id="pn2">2</div>
                    </div>

                    <form id="prefForm">
                        <!-- ── STEP 1: Job Preferences ── -->
                        <div class="block" id="prefStepPanel1">
                            <p class="font-['DM_Serif_Display'] text-[13.5px] sm:text-[15px] text-[var(--navy)] mb-2.5 sm:mb-3.5">Job &amp; Salary Preferences</p>
                            <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mb-3.5 sm:mb-[18px] leading-relaxed">This helps us rank job orders by how well they match what you're looking for. It doesn't limit which jobs you can apply to.</p>
                            <div class="grid grid-cols-1 min-[500px]:grid-cols-2 gap-2.5 sm:gap-3">
                                <div>
                                    <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Classification</label>
                                    <div class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] font-semibold text-[var(--text1)] bg-[var(--surface)] flex items-center gap-2">
                                        <i class="fas fa-building-columns text-[var(--green)]"></i>
                                        Government
                                    </div>
                                    <p class="text-[10px] sm:text-[11px] text-[var(--text3)] mt-1 leading-relaxed">
                                        Fixed to Government because all job orders currently posted through DMW are G-to-G hired positions — there are no private-sector listings to choose from.
                                    </p>
                                    <input type="hidden" name="preferred_classification" value="Government">
                                </div>
                                <div>
                                    <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Employment Type <span class="text-red-500">*</span></label>
                                    <select name="preferred_employment_type" required
                                        class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select…</option>
                                        <option value="Full-time">Full-time</option>
                                        <option value="Part-time">Part-time</option>
                                        <option value="Contract">Contract</option>
                                    </select>
                                </div>
                                <div class="col-span-full">
                                    <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Expected Salary Range (₱ PHP)</label>
                                    <select name="salary_range_id"
                                        class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select…</option>
                                        <?php foreach ($salaryRanges as $sr): ?>
                                            <option value="<?= (int)$sr['id'] ?>"><?= htmlspecialchars($sr['range_label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mt-1 leading-relaxed">Pick the range closest to what you're expecting. Job orders posted in other currencies are automatically converted to PHP for comparison.</p>
                                </div>
                            </div>

                            <p class="font-['DM_Serif_Display'] text-[13.5px] sm:text-[15px] text-[var(--navy)] mt-5 sm:mt-6 mb-2.5 sm:mb-3.5">Preferred Destinations &amp; Positions</p>
                            <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mb-3.5 sm:mb-[18px] leading-relaxed">Add as many as you'd like, in order of preference. The first one you add is your top priority.</p>

                            <div class="col-span-full">
                                <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Countries <span class="text-red-500">*</span></label>
                                <div class="flex gap-2">
                                    <select id="countrySelect"
                                        class="flex-1 py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select a country…</option>
                                        <?php foreach ($prefCountryOptions as $c): ?>
                                            <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-[10px] min-h-[34px]" id="countryChips"></div>
                            </div>

                            <div class="col-span-full mt-4">
                                <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Preferred Positions <span class="text-red-500">*</span></label>
                                <div class="flex gap-2">
                                    <select id="positionSelect"
                                        class="flex-1 py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select a position…</option>
                                        <?php foreach ($prefCategories as $cat): if (empty($cat['subs'])) continue; ?>
                                            <optgroup label="<?= htmlspecialchars($cat['name']) ?>">
                                                <?php foreach ($cat['subs'] as $sub): ?>
                                                    <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($sub['subcategory_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-2 mt-[10px] min-h-[34px]" id="positionChips"></div>
                            </div>

                            <!-- Hidden inputs populated from chips right before submit -->
                            <div id="prefHiddenInputs"></div>
                        </div>

                        <!-- STEP 2: Skills & Licenses -->
                        <div class="hidden" id="prefStepPanel2">
                            <p class="font-['DM_Serif_Display'] text-[13.5px] sm:text-[15px] text-[var(--navy)] mb-2.5 sm:mb-3.5">Applicant Skills</p>
                            <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mb-3.5 sm:mb-[18px] leading-relaxed">Add every skill you have. Job orders that need these skills will match you higher. Optional — you can skip this and add skills later.</p>

                            <div class="col-span-full">
                                <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Add a Skill</label>
                                <div class="grid grid-cols-1 min-[500px]:grid-cols-2 gap-2">
                                    <select id="wizSkillSelect"
                                        class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select a skill…</option>
                                        <?php foreach ($skillsByCategory as $catName => $catSkills): ?>
                                            <optgroup label="<?= htmlspecialchars($catName) ?>">
                                                <?php foreach ($catSkills as $sk): ?>
                                                    <option value="<?= (int)$sk['id'] ?>"><?= htmlspecialchars($sk['skill_name']) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="wizSkillProficiencySelect"
                                        class="py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="Beginner">Beginner</option>
                                        <option value="Intermediate" selected>Intermediate</option>
                                        <option value="Advanced">Advanced</option>
                                        <option value="Expert">Expert</option>
                                    </select>
                                </div>
                                <p class="text-[10px] sm:text-[11px] text-[var(--text3)] mt-1.5 leading-relaxed">Tip: set the proficiency level first if it's not "Intermediate," then pick the skill — it's added instantly.</p>
                                <div class="mt-2">
                                    <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Years of Experience (optional)</label>
                                    <input type="number" id="wizSkillYearsInput" min="0" max="50" placeholder="e.g. 2"
                                        class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 mt-3.5 min-h-[34px]" id="wizSkillChips"></div>

                            <p class="font-['DM_Serif_Display'] text-[13.5px] sm:text-[15px] text-[var(--navy)] mt-5 sm:mt-6 mb-2.5 sm:mb-3.5">My Licenses</p>
                            <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mb-3.5 sm:mb-[18px] leading-relaxed">If you hold a professional license (e.g. PRC), add it here. Optional — leave it blank if you don't have one.</p>
                            <div class="col-span-full">
                                <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Add a License</label>
                                <div class="flex gap-2">
                                    <select id="wizLicenseSelect"
                                        class="flex-1 py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                                        <option value="">Select a license…</option>
                                        <?php foreach ($allLicenses as $lic): ?>
                                            <option value="<?= (int)$lic['id'] ?>"><?= htmlspecialchars($lic['license_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 mt-3.5 min-h-[34px]" id="wizLicenseChips"></div>
                        </div>

                    </form><!-- /prefForm -->

                    <div id="prefError" class="hidden bg-red-100 border border-red-200 rounded-lg sm:rounded-[9px] py-2.5 sm:py-[10px] px-3 sm:px-[13px] mt-3 text-[11px] sm:text-xs font-semibold text-red-800"></div>

                </div><!-- /modal-body -->
                <div class="flex justify-end gap-2 py-3 sm:py-[14px] px-3.5 sm:px-[18px] border-t border-[var(--border)] bg-[var(--surface)] rounded-b-2xl sm:rounded-b-[18px]" id="prefModalFooter">
                    <button id="prefBackBtn" style="display:none;" onclick="prefStep(-1)"
                        class="py-2 sm:py-[9px] px-4 sm:px-5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--surface)] border border-[var(--border)] text-[var(--text2)] disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button id="prefNextBtn" onclick="prefStep(1)"
                        class="py-2 sm:py-[9px] px-4 sm:px-5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-bold cursor-pointer border-none transition-opacity hover:opacity-[.88] bg-[var(--blue)] text-white disabled:opacity-50 disabled:cursor-not-allowed">
                        Next &nbsp;<i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const TOTAL_STEPS = 2;
                let currentStep = 1;
                let countryChips = []; // ordered array of country strings
                let positionChips = []; // ordered array of {id, name}
                let wizSkillChips = <?= json_encode($clientSkills) ?>.map(s => ({
                    id: String(s.skill_id),
                    name: s.skill_name,
                    proficiency: s.proficiency_level || 'Intermediate',
                    years: s.years_experience ?? ''
                }));
                let wizLicenseChips = <?= json_encode($clientLicenses) ?>.map(l => ({
                    id: String(l.license_id),
                    name: l.license_name
                }));

                // Tailwind class sets for the JS-driven step indicator / chips / pagination
                const STEP_NODE_BASE = 'w-6 h-6 sm:w-7 sm:h-7 rounded-full flex items-center justify-center text-[10px] sm:text-[11px] font-extrabold flex-shrink-0 transition-colors';
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
                        if (countryChips.length < 1) {
                            showError('Please add at least one preferred country.');
                            return false;
                        }
                        if (positionChips.length < 1) {
                            showError('Please add at least one preferred position.');
                            return false;
                        }
                    }
                    // Step 2 (Skills & Licenses) is optional — no required validation.
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
                            const sel = document.getElementById('countrySelect');
                            const opt = Array.from(sel.options).find(o => o.value === country);
                            if (opt) opt.hidden = false; // bring it back into the dropdown
                            countryChips.splice(idx, 1);
                            customSelects.country.refresh();
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
                            const sel = document.getElementById('positionSelect');
                            const opt = Array.from(sel.options).find(o => o.value === pos.id);
                            if (opt) opt.hidden = false; // bring it back into the dropdown
                            positionChips.splice(idx, 1);
                            customSelects.position.refresh();
                            renderChips();
                        });
                        pWrap.appendChild(chip);
                    });
                }

                function renderWizSkillChips() {
                    const wrap = document.getElementById('wizSkillChips');
                    wrap.innerHTML = '';
                    wizSkillChips.forEach((s, idx) => {
                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-[7px] bg-[var(--navy)] text-white py-[6px] pr-[6px] pl-3 rounded-full text-xs font-semibold';
                        const yearsLabel = s.years !== '' && s.years !== null ? ` · ${s.years}y` : '';
                        chip.innerHTML = `${s.name} <span class="bg-white/20 rounded-full py-[2px] px-2 text-[10px] font-extrabold">${s.proficiency}${yearsLabel}</span> <i class="fas fa-xmark cursor-pointer opacity-75 hover:opacity-100 text-[11px]" data-idx="${idx}"></i>`;
                        chip.querySelector('.fa-xmark').addEventListener('click', () => {
                            const sel = document.getElementById('wizSkillSelect');
                            const opt = Array.from(sel.options).find(o => o.value === s.id);
                            if (opt) opt.hidden = false; // bring it back into the dropdown
                            wizSkillChips.splice(idx, 1);
                            customSelects.skill.refresh();
                            renderWizSkillChips();
                        });
                        wrap.appendChild(chip);
                    });
                }

                // Enhance every select in the modal. Keys map to the elements
                // used elsewhere in this script so we can call .refresh()
                // whenever the underlying <select> value is reset in code.
                const customSelects = {
                    employmentType: enhanceSelect(document.querySelector('[name="preferred_employment_type"]'), {
                        searchable: false
                    }),
                    salaryRange: enhanceSelect(document.querySelector('[name="salary_range_id"]'), {
                        searchable: false
                    }),
                    country: enhanceSelect(document.getElementById('countrySelect'), {
                        searchable: true,
                        openUpward: true
                    }),
                    position: enhanceSelect(document.getElementById('positionSelect'), {
                        searchable: true,
                        openUpward: true
                    }),
                    skill: enhanceSelect(document.getElementById('wizSkillSelect'), {
                        searchable: true
                    }),
                    proficiency: enhanceSelect(document.getElementById('wizSkillProficiencySelect'), {
                        searchable: false
                    }),
                    license: enhanceSelect(document.getElementById('wizLicenseSelect'), {
                        searchable: true,
                        openUpward: true
                    }),
                };
                /* ============================================================ */

                document.getElementById('wizSkillSelect').addEventListener('change', () => {
                    const sel = document.getElementById('wizSkillSelect');
                    const profSel = document.getElementById('wizSkillProficiencySelect');
                    const yearsInput = document.getElementById('wizSkillYearsInput');
                    const val = sel.value;
                    if (!val) return;

                    wizSkillChips.push({
                        id: val,
                        name: sel.options[sel.selectedIndex].text,
                        proficiency: profSel.value,
                        years: yearsInput.value === '' ? '' : parseInt(yearsInput.value, 10)
                    });
                    const opt = Array.from(sel.options).find(o => o.value === val);
                    if (opt) opt.hidden = true; // remove from the dropdown list to prevent duplicate selection

                    sel.value = '';
                    yearsInput.value = '';
                    customSelects.skill.refresh();
                    hideError();
                    renderWizSkillChips();
                });

                function renderWizLicenseChips() {
                    const wrap = document.getElementById('wizLicenseChips');
                    wrap.innerHTML = '';
                    wizLicenseChips.forEach((l, idx) => {
                        const chip = document.createElement('span');
                        chip.className = 'inline-flex items-center gap-[7px] bg-[var(--navy)] text-white py-[6px] pr-[6px] pl-3 rounded-full text-xs font-semibold';
                        chip.innerHTML = `${l.name} <i class="fas fa-xmark cursor-pointer opacity-75 hover:opacity-100 text-[11px]" data-idx="${idx}"></i>`;
                        chip.querySelector('.fa-xmark').addEventListener('click', () => {
                            const sel = document.getElementById('wizLicenseSelect');
                            const opt = Array.from(sel.options).find(o => o.value === l.id);
                            if (opt) opt.hidden = false; // bring it back into the dropdown
                            wizLicenseChips.splice(idx, 1);
                            customSelects.license.refresh();
                            renderWizLicenseChips();
                        });
                        wrap.appendChild(chip);
                    });
                }

                document.getElementById('wizLicenseSelect').addEventListener('change', () => {
                    const sel = document.getElementById('wizLicenseSelect');
                    const val = sel.value;
                    if (!val) return;

                    wizLicenseChips.push({
                        id: val,
                        name: sel.options[sel.selectedIndex].text
                    });
                    const opt = Array.from(sel.options).find(o => o.value === val);
                    if (opt) opt.hidden = true; // remove from the dropdown list to prevent duplicate selection

                    sel.value = '';
                    customSelects.license.refresh();
                    hideError();
                    renderWizLicenseChips();
                });

                document.getElementById('countrySelect').addEventListener('change', () => {
                    const sel = document.getElementById('countrySelect');
                    const val = sel.value;
                    if (!val) return;
                    countryChips.push(val);
                    const opt = Array.from(sel.options).find(o => o.value === val);
                    if (opt) opt.hidden = true; // remove from the dropdown list to prevent duplicate selection
                    sel.value = '';
                    customSelects.country.refresh();
                    hideError();
                    renderChips();
                });
                document.getElementById('positionSelect').addEventListener('change', () => {
                    const sel = document.getElementById('positionSelect');
                    const val = sel.value;
                    if (!val) return;
                    positionChips.push({
                        id: val,
                        name: sel.options[sel.selectedIndex].text
                    });
                    const opt = Array.from(sel.options).find(o => o.value === val);
                    if (opt) opt.hidden = true; // remove from the dropdown list to prevent duplicate selection
                    sel.value = '';
                    customSelects.position.refresh();
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

                    const skillsData = new FormData();
                    wizSkillChips.forEach(s => {
                        skillsData.append('skill_id[]', s.id);
                        skillsData.append('proficiency_level[]', s.proficiency);
                        skillsData.append('years_experience[]', s.years === '' ? '' : s.years);
                    });

                    const licensesData = new FormData();
                    wizLicenseChips.forEach(l => licensesData.append('license_id[]', l.id));

                    fetch('save-job-preferences.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success) throw new Error(data.message || 'Failed to save preferences.');
                            return fetch('save-client-skills.php', {
                                method: 'POST',
                                body: skillsData
                            });
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success) throw new Error(data.message || 'Failed to save skills.');
                            return fetch('save-client-licenses.php', {
                                method: 'POST',
                                body: licensesData
                            });
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success) throw new Error(data.message || 'Failed to save licenses.');
                            document.getElementById('prefModal').style.display = 'none';
                            window.location.replace('jobs.php');
                        })
                        .catch(err => {
                            showError(err.message || 'Something went wrong. Please try again.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Save Preferences';
                        });
                }

                // Hide options that are already on the applicant's saved skill/license list
                // so they can't be selected twice on load.
                (function hidePreselectedWizOptions() {
                    const skillSel = document.getElementById('wizSkillSelect');
                    wizSkillChips.forEach(s => {
                        const opt = Array.from(skillSel.options).find(o => o.value === s.id);
                        if (opt) opt.hidden = true;
                    });
                    const licenseSel = document.getElementById('wizLicenseSelect');
                    wizLicenseChips.forEach(l => {
                        const opt = Array.from(licenseSel.options).find(o => o.value === l.id);
                        if (opt) opt.hidden = true;
                    });
                })();

                updateStepUI();
                renderWizSkillChips();
                renderWizLicenseChips();

                // Prevent closing modal on backdrop click, but still close any open
                // custom-select dropdown (Country, Position, Employment Type, etc.)
                // when the applicant clicks elsewhere inside the form.
                document.getElementById('prefModal').addEventListener('click', function(e) {
                    e.stopPropagation();
                    Object.values(customSelects).forEach(ctl => ctl.closePanel());
                });
            })();
        </script>
    <?php endif; ?>

    <!-- ══════════════════════════════════════
         MY SKILLS MODAL
    ══════════════════════════════════════ -->
    <div id="skillsModal" class="fixed inset-0 bg-[rgba(11,31,75,0.6)] backdrop-blur-sm hidden items-center justify-center z-[600] p-3 sm:p-4">
        <div class="bg-white rounded-2xl sm:rounded-[18px] w-full max-w-[600px] max-h-[92vh] sm:max-h-[90vh] overflow-y-auto shadow-[0_24px_60px_rgba(11,31,75,0.3)]">
            <div class="flex items-center justify-between py-3 sm:py-4 px-3.5 sm:px-[18px] border-b border-[var(--border)] sticky top-0 bg-white rounded-t-2xl sm:rounded-t-[18px] z-[2]">
                <h3 class="font-['DM_Serif_Display'] text-[14px] sm:text-base text-[var(--navy)] flex items-center gap-2 sm:gap-[9px]">
                    <span class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg sm:rounded-[9px] bg-[#eff6ff] flex items-center justify-center text-[var(--blue)] text-[13px] sm:text-sm flex-shrink-0">
                        <i class="fas fa-star"></i>
                    </span>
                    My Skills
                </h3>
                <button type="button" onclick="closeSkillsModal()" class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center text-[var(--text3)] hover:bg-gray-100">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="p-3.5 sm:p-[18px]">
                <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mb-3.5 sm:mb-[18px] leading-relaxed">Add every skill you have. Job orders that need these skills will match you higher and show your gaps in the "% Match" breakdown.</p>

                <div class="col-span-full">
                    <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Add a Skill</label>
                    <div class="grid grid-cols-1 min-[500px]:grid-cols-[1fr_auto_auto] gap-2">
                        <select id="skillSelect"
                            class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                            <option value="">Select a skill…</option>
                            <?php foreach ($skillsByCategory as $catName => $catSkills): ?>
                                <optgroup label="<?= htmlspecialchars($catName) ?>">
                                    <?php foreach ($catSkills as $sk): ?>
                                        <option value="<?= (int)$sk['id'] ?>"><?= htmlspecialchars($sk['skill_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <select id="skillProficiencySelect"
                            class="py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                            <option value="Beginner">Beginner</option>
                            <option value="Intermediate" selected>Intermediate</option>
                            <option value="Advanced">Advanced</option>
                            <option value="Expert">Expert</option>
                        </select>
                        <button type="button" id="addSkillBtn"
                            class="py-2 sm:py-[9px] px-3 sm:px-[14px] rounded-lg sm:rounded-[9px] border-[1.5px] border-[var(--blue)] bg-[#eff6ff] text-[var(--blue)] text-[11px] sm:text-[12.5px] font-bold cursor-pointer whitespace-nowrap transition-colors hover:bg-[#dbeafe]">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                    <div class="mt-2">
                        <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Years of Experience (optional)</label>
                        <input type="number" id="skillYearsInput" min="0" max="50" placeholder="e.g. 2"
                            class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 mt-3.5 min-h-[34px]" id="skillChips"></div>

                <div id="skillsError" class="hidden bg-red-100 border border-red-200 rounded-lg sm:rounded-[9px] py-2.5 sm:py-[10px] px-3 sm:px-[13px] mt-3 text-[11px] sm:text-xs font-semibold text-red-800"></div>
            </div>
            <div class="flex justify-end gap-2 py-3 sm:py-[14px] px-3.5 sm:px-[18px] border-t border-[var(--border)] bg-[var(--surface)] rounded-b-2xl sm:rounded-b-[18px]">
                <button type="button" onclick="closeSkillsModal()"
                    class="py-2 sm:py-[9px] px-4 sm:px-5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-bold cursor-pointer border border-[var(--border)] bg-[var(--surface)] text-[var(--text2)] transition-opacity hover:opacity-[.88]">
                    Cancel
                </button>
                <button id="saveSkillsBtn" onclick="submitSkills()"
                    class="py-2 sm:py-[9px] px-4 sm:px-5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-bold cursor-pointer border-none bg-[var(--blue)] text-white transition-opacity hover:opacity-[.88] disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-check"></i> Save Skills
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         MY LICENSES MODAL
    ══════════════════════════════════════ -->
    <div id="licensesModal" class="fixed inset-0 bg-[rgba(11,31,75,0.6)] backdrop-blur-sm hidden items-center justify-center z-[600] p-3 sm:p-4">
        <div class="bg-white rounded-2xl sm:rounded-[18px] w-full max-w-[600px] max-h-[92vh] sm:max-h-[90vh] overflow-y-auto shadow-[0_24px_60px_rgba(11,31,75,0.3)]">
            <div class="flex items-center justify-between py-3 sm:py-4 px-3.5 sm:px-[18px] border-b border-[var(--border)] sticky top-0 bg-white rounded-t-2xl sm:rounded-t-[18px] z-[2]">
                <h3 class="font-['DM_Serif_Display'] text-[14px] sm:text-base text-[var(--navy)] flex items-center gap-2 sm:gap-[9px]">
                    <span class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg sm:rounded-[9px] bg-[#eff6ff] flex items-center justify-center text-[var(--blue)] text-[13px] sm:text-sm flex-shrink-0">
                        <i class="fas fa-id-card"></i>
                    </span>
                    My Professional Licenses
                </h3>
                <button type="button" onclick="closeLicensesModal()" class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center text-[var(--text3)] hover:bg-gray-100">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="p-3.5 sm:p-[18px]">
                <p class="text-[10.5px] sm:text-[11.5px] text-[var(--text3)] mb-3.5 sm:mb-[18px] leading-relaxed">If you hold a professional license (e.g. PRC), add it here. This is optional — leave it blank if you don't have one. Job orders that require a license will only show as eligible if it matches one you've added.</p>

                <div class="col-span-full">
                    <div class="col-span-full">
                        <label class="block text-[9.5px] sm:text-[10.5px] font-bold text-[var(--text2)] tracking-[.04em] uppercase mb-[5px]">Add a License</label>
                        <select id="licenseSelect"
                            class="w-full py-2 sm:py-[9px] px-2.5 sm:px-[11px] border-[1.5px] border-[var(--border)] rounded-lg sm:rounded-[9px] text-[12px] sm:text-[13px] text-[var(--text1)] bg-white transition-colors outline-none focus:border-[var(--blue-lt)]">
                            <option value="">Select a license…</option>
                            <?php foreach ($allLicenses as $lic): ?>
                                <option value="<?= (int)$lic['id'] ?>"><?= htmlspecialchars($lic['license_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] sm:text-[11px] text-[var(--text3)] mt-1.5 leading-relaxed">Selecting a license adds it instantly.</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 mt-3.5 min-h-[34px]" id="licenseChips"></div>

                <div id="licensesError" class="hidden bg-red-100 border border-red-200 rounded-lg sm:rounded-[9px] py-2.5 sm:py-[10px] px-3 sm:px-[13px] mt-3 text-[11px] sm:text-xs font-semibold text-red-800"></div>
            </div>
            <div class="flex justify-end gap-2 py-3 sm:py-[14px] px-3.5 sm:px-[18px] border-t border-[var(--border)] bg-[var(--surface)] rounded-b-2xl sm:rounded-b-[18px]">
                <button type="button" onclick="closeLicensesModal()"
                    class="py-2 sm:py-[9px] px-4 sm:px-5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-bold cursor-pointer border border-[var(--border)] bg-[var(--surface)] text-[var(--text2)] transition-opacity hover:opacity-[.88]">
                    Cancel
                </button>
                <button id="saveLicensesBtn" onclick="submitLicenses()"
                    class="py-2 sm:py-[9px] px-4 sm:px-5 rounded-lg sm:rounded-[10px] text-[11px] sm:text-[12.5px] font-bold cursor-pointer border-none bg-[var(--blue)] text-white transition-opacity hover:opacity-[.88] disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-check"></i> Save Licenses
                </button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const EXISTING_CLIENT_LICENSES = <?= json_encode($clientLicenses) ?>;
            let licenseChips = (EXISTING_CLIENT_LICENSES || []).map(l => ({
                id: String(l.license_id),
                name: l.license_name
            }));

            const licenseSelectCtl = enhanceSelect(document.getElementById('licenseSelect'), {
                searchable: true,
                openUpward: true
            });

            function showLicensesError(msg) {
                const el = document.getElementById('licensesError');
                el.textContent = msg;
                el.classList.remove('hidden');
            }

            function hideLicensesError() {
                document.getElementById('licensesError').classList.add('hidden');
            }

            function renderLicenseChips() {
                const wrap = document.getElementById('licenseChips');
                wrap.innerHTML = '';
                licenseChips.forEach((l, idx) => {
                    const chip = document.createElement('span');
                    chip.className = 'inline-flex items-center gap-[7px] bg-[var(--navy)] text-white py-[6px] pr-[6px] pl-3 rounded-full text-xs font-semibold';
                    chip.innerHTML = `${l.name} <i class="fas fa-xmark cursor-pointer opacity-75 hover:opacity-100 text-[11px]" data-idx="${idx}"></i>`;
                    chip.querySelector('.fa-xmark').addEventListener('click', () => {
                        // bring the removed license back into the dropdown
                        const sel = document.getElementById('licenseSelect');
                        const opt = Array.from(sel.options).find(o => o.value === l.id);
                        if (opt) opt.hidden = false;
                        licenseChips.splice(idx, 1);
                        licenseSelectCtl.refresh();
                        renderLicenseChips();
                    });
                    wrap.appendChild(chip);
                });
                if (licenseChips.length === 0) {
                    wrap.innerHTML = '<span class="text-[11.5px] text-[var(--text3)] italic">No licenses added — that\'s okay if you don\'t have one.</span>';
                }
            }

            // Selecting a license now adds it immediately — no separate "Add" click.
            document.getElementById('licenseSelect').addEventListener('change', () => {
                const sel = document.getElementById('licenseSelect');
                const val = sel.value;
                if (!val) return;
                if (licenseChips.some(l => l.id === val)) {
                    showLicensesError('That license is already on your list.');
                    sel.value = '';
                    licenseSelectCtl.refresh();
                    return;
                }
                licenseChips.push({
                    id: val,
                    name: sel.options[sel.selectedIndex].text
                });
                const opt = Array.from(sel.options).find(o => o.value === val);
                if (opt) opt.hidden = true; // remove from dropdown so it can't be picked twice

                sel.value = '';
                licenseSelectCtl.refresh();
                hideLicensesError();
                renderLicenseChips();
            });

            window.openLicensesModal = function() {
                // hide options for licenses the applicant already has, so they
                // don't show up in the dropdown again
                const sel = document.getElementById('licenseSelect');
                licenseChips.forEach(l => {
                    const opt = Array.from(sel.options).find(o => o.value === l.id);
                    if (opt) opt.hidden = true;
                });
                licenseSelectCtl.refresh();
                renderLicenseChips();
                hideLicensesError();
                const modal = document.getElementById('licensesModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            };

            window.closeLicensesModal = function() {
                const modal = document.getElementById('licensesModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            };

            window.submitLicenses = function() {
                const btn = document.getElementById('saveLicensesBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

                const formData = new FormData();
                licenseChips.forEach(l => formData.append('license_id[]', l.id));

                fetch('save-client-licenses.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            window.location.replace('jobs.php');
                        } else {
                            showLicensesError(data.message || 'Something went wrong. Please try again.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Save Licenses';
                        }
                    })
                    .catch(() => {
                        showLicensesError('Network error. Please check your connection and try again.');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i> Save Licenses';
                    });
            };

            document.getElementById('licensesModal').addEventListener('click', function(e) {
                if (e.target === this) closeLicensesModal();
            });
        })();
    </script>

    <script>
        (function() {
            const SKILLS_LIST = <?= json_encode($allSkills) ?>;
            const EXISTING_CLIENT_SKILLS = <?= json_encode($clientSkills) ?>;
            let skillChips = (EXISTING_CLIENT_SKILLS || []).map(s => ({
                id: String(s.skill_id),
                name: s.skill_name,
                proficiency: s.proficiency_level || 'Intermediate',
                years: s.years_experience ?? ''
            }));

            // ── enhance dropdowns (same look/behavior as the preferences wizard) ──
            const skillSelectCtl = enhanceSelect(document.getElementById('skillSelect'), {
                searchable: true
            });
            const skillProfCtl = enhanceSelect(document.getElementById('skillProficiencySelect'), {
                searchable: false
            });

            function showSkillsError(msg) {
                const el = document.getElementById('skillsError');
                el.textContent = msg;
                el.classList.remove('hidden');
            }

            function hideSkillsError() {
                document.getElementById('skillsError').classList.add('hidden');
            }

            function renderSkillChips() {
                const wrap = document.getElementById('skillChips');
                wrap.innerHTML = '';
                skillChips.forEach((s, idx) => {
                    const chip = document.createElement('span');
                    chip.className = 'inline-flex items-center gap-[7px] bg-[var(--navy)] text-white py-[6px] pr-[6px] pl-3 rounded-full text-xs font-semibold';
                    const yearsLabel = s.years !== '' && s.years !== null ? ` · ${s.years}y` : '';
                    chip.innerHTML = `${s.name} <span class="bg-white/20 rounded-full py-[2px] px-2 text-[10px] font-extrabold">${s.proficiency}${yearsLabel}</span> <i class="fas fa-xmark cursor-pointer opacity-75 hover:opacity-100 text-[11px]" data-idx="${idx}"></i>`;
                    chip.querySelector('.fa-xmark').addEventListener('click', () => {
                        skillChips.splice(idx, 1);
                        renderSkillChips();
                    });
                    wrap.appendChild(chip);
                });
            }

            document.getElementById('addSkillBtn').addEventListener('click', () => {
                const sel = document.getElementById('skillSelect');
                const profSel = document.getElementById('skillProficiencySelect');
                const yearsInput = document.getElementById('skillYearsInput');
                const val = sel.value;
                if (!val) return;
                if (skillChips.some(s => s.id === val)) {
                    showSkillsError('That skill is already on your list.');
                    return;
                }
                skillChips.push({
                    id: val,
                    name: sel.options[sel.selectedIndex].text,
                    proficiency: profSel.value,
                    years: yearsInput.value === '' ? '' : parseInt(yearsInput.value, 10)
                });
                sel.value = '';
                profSel.value = 'Intermediate';
                yearsInput.value = '';
                skillSelectCtl.refresh();
                skillProfCtl.refresh();
                hideSkillsError();
                renderSkillChips();
            });

            window.openSkillsModal = function() {
                renderSkillChips();
                hideSkillsError();
                const modal = document.getElementById('skillsModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            };

            window.closeSkillsModal = function() {
                const modal = document.getElementById('skillsModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            };

            window.submitSkills = function() {
                const btn = document.getElementById('saveSkillsBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

                const formData = new FormData();
                skillChips.forEach(s => {
                    formData.append('skill_id[]', s.id);
                    formData.append('proficiency_level[]', s.proficiency);
                    formData.append('years_experience[]', s.years === '' ? '' : s.years);
                });

                fetch('save-client-skills.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            window.location.replace('jobs.php');
                        } else {
                            showSkillsError(data.message || 'Something went wrong. Please try again.');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Save Skills';
                        }
                    })
                    .catch(() => {
                        showSkillsError('Network error. Please check your connection and try again.');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-check"></i> Save Skills';
                    });
            };

            document.getElementById('skillsModal').addEventListener('click', function(e) {
                if (e.target === this) closeSkillsModal();
            });
        })();
    </script>
    <script>
        /* Dismissible tip banner — remembered for the current browser session only.
           Can always be reopened via the "?" button next to Search, no re-login needed
           since this is purely client-side UI state (sessionStorage), not tied to auth. */
        (function() {
            const banner = document.getElementById('detailsTipBanner');
            if (!banner) return;
            if (sessionStorage.getItem('jobsDetailsTipDismissed') === '1') {
                banner.style.display = 'none';
            }
        })();

        function dismissDetailsTipBanner() {
            const banner = document.getElementById('detailsTipBanner');
            if (banner) banner.style.display = 'none';
            try {
                sessionStorage.setItem('jobsDetailsTipDismissed', '1');
            } catch (e) {}
        }

        function showDetailsTipBanner() {
            const banner = document.getElementById('detailsTipBanner');
            if (banner) {
                banner.style.display = '';
                banner.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }
            try {
                sessionStorage.removeItem('jobsDetailsTipDismissed');
            } catch (e) {}
        }

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

        /* Preference-match score breakdown — proof of how each job's % was calculated */
        window.QUALIFICATION_CHECKS = <?= json_encode($qualificationChecks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        <?php if ($has_preferences): ?>
            window.MATCH_BREAKDOWNS = <?= json_encode($matchBreakdowns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        <?php else: ?>
            window.MATCH_BREAKDOWNS = {};
        <?php endif; ?>

        function matchTierLabel(score) {
            if (score >= 70) return {
                label: 'Strong Match',
                color: '#059669'
            };
            if (score >= 40) return {
                label: 'Fair Match',
                color: '#a16207'
            };
            return {
                label: 'Low Match',
                color: '#6b7280'
            };
        }

        /* ══════ UNIFIED job details modal ══════
           Single entry point for both eligibility requirements and preference
           fit. Shown independently of each other — a client who fails a
           structured requirement can still see their preference match, and
           vice versa. Uses qualitative labels (Strong/Partial/No match)
           instead of raw point values to avoid exposing internal weighting. */
        function showJobDetails(jobId) {
            const checks = window.QUALIFICATION_CHECKS[jobId] || [];
            const match = window.MATCH_BREAKDOWNS[jobId] || null;

            let html = '<div style="text-align:left;">';

            html += '<p style="font-size:12px;font-weight:800;color:#1f2937;margin-bottom:6px;">Eligibility Requirements</p>';
            if (checks.length === 0) {
                html += '<p style="font-size:11.5px;color:#6b7280;margin-bottom:14px;">This job order has no specific eligibility requirements posted.</p>';
            } else {
                html += checks.map(c => `
                    <div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
                        <span style="color:${c.met ? '#059669' : '#dc2626'};font-weight:800;width:16px;text-align:center;flex-shrink:0;">${c.met ? '✓' : '✕'}</span>
                        <div>
                            <div style="font-size:12px;font-weight:700;color:#1f2937;">${c.label}</div>
                            <div style="font-size:11.5px;color:#6b7280;line-height:1.5;">${c.detail}</div>
                        </div>
                    </div>
                `).join('');
                html += '<p style="margin:10px 0 14px;font-size:11px;color:#9ca3af;line-height:1.5;">You may still apply even if some requirements aren\'t met — the evaluator makes the final decision.</p>';
            }

            if (match) {
                const tier = matchTierLabel(match.score);
                html += `<p style="font-size:12px;font-weight:800;color:#1f2937;margin-bottom:6px;">Preference Fit — <span style="color:${tier.color};">${tier.label} (${match.score}%)</span></p>`;
                html += match.components.map(c => {
                    let icon, iconColor, level;
                    if (!c.applicable) {
                        icon = '−';
                        iconColor = '#9ca3af';
                        level = 'Not scored';
                    } else {
                        const ratio = c.max > 0 ? c.earned / c.max : 0;
                        if (ratio >= 0.99) {
                            icon = '✓';
                            iconColor = '#059669';
                            level = 'Strong match';
                        } else if (ratio > 0) {
                            icon = '~';
                            iconColor = '#a16207';
                            level = 'Partial match';
                        } else {
                            icon = '✕';
                            iconColor = '#dc2626';
                            level = 'No match';
                        }
                    }
                    return `
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="color:${iconColor};font-weight:800;width:16px;text-align:center;">${icon}</span>
                                <span style="font-weight:700;font-size:12.5px;color:#1f2937;">${c.label}</span>
                            </div>
                            <span style="font-size:11px;font-weight:700;color:#6b7280;">${level}</span>
                        </div>
                        <div style="font-size:11.5px;color:#6b7280;line-height:1.5;padding:2px 0 8px 24px;">${c.detail}</div>
                    `;
                }).join('');
                html += '<p style="margin-top:10px;font-size:11px;color:#9ca3af;line-height:1.5;">This reflects how well the job matches your saved preferences — it does not affect your eligibility.</p>';
            }

            html += '</div>';

            Swal.fire({
                title: 'Job Fit Details',
                html,
                confirmButtonColor: '#1d4ed8',
                confirmButtonText: 'Got it',
                width: window.innerWidth < 480 ? '92vw' : 460
            });
        }

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

        /* Apply for job (client-only page — session enforces login).
           ══════ UPDATED: now accepts an `eligible` flag. If the client fails
           one or more structured qualifications, they get a warning step
           first (with reasons) before the normal "Apply?" confirm. This is
           a soft warning, not a hard block — the evaluator still decides. */
        function applyForJob(id, eligible) {
            if (eligible === false) {
                Swal.fire({
                    icon: 'warning',
                    title: 'You may not meet all requirements',
                    text: 'Based on the posted requirements for this job order, your profile does not meet one or more qualifications. You can still apply, but the evaluator may disapprove the application. Continue anyway?',
                    showCancelButton: true,
                    confirmButtonColor: '#1d4ed8',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Continue Anyway',
                    cancelButtonText: 'Cancel'
                }).then(r => {
                    if (r.isConfirmed) confirmApply(id);
                });
                return;
            }
            confirmApply(id);
        }

        function confirmApply(id) {
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
        const PAGE_BTN_BASE = 'min-w-[30px] h-[30px] sm:min-w-[34px] sm:h-[34px] border border-[var(--border)] bg-[var(--card)] text-[var(--text2)] rounded-lg sm:rounded-[9px] text-[11px] sm:text-xs font-bold cursor-pointer transition-colors hover:bg-[#eff6ff] hover:border-[#bfdbfe] hover:text-[#1e40af]';
        const PAGE_BTN_ACTIVE = 'min-w-[30px] h-[30px] sm:min-w-[34px] sm:h-[34px] border border-[#1d4ed8] bg-[#1d4ed8] text-white rounded-lg sm:rounded-[9px] text-[11px] sm:text-xs font-bold cursor-pointer transition-colors';

        function getFilteredCards() {
            const search = document.getElementById('searchInput').value.toLowerCase().trim();
            const country = document.getElementById('countryFilter').value.toLowerCase().trim();
            const eligibleOnly = document.getElementById('eligibleOnlyToggle').checked;
            const matchTierEl = document.getElementById('matchTierFilter');
            const matchTier = matchTierEl ? matchTierEl.value : '';
            const cards = Array.from(document.querySelectorAll('#jobList > div'));

            return cards.filter(card => {
                const text = card.textContent.toLowerCase();
                const matchesSearch = !search || text.includes(search);
                const matchesCountry = !country || text.includes(country);
                const matchesEligibility = !eligibleOnly || card.dataset.eligible === '1';

                let matchesTier = true;
                if (matchTier) {
                    const raw = card.dataset.matchScore;
                    const score = raw !== '' ? parseInt(raw, 10) : null;
                    if (score === null) matchesTier = false;
                    else if (matchTier === 'strong') matchesTier = score >= 70;
                    else if (matchTier === 'fair') matchesTier = score >= 40 && score < 70;
                    else if (matchTier === 'low') matchesTier = score < 40;
                }

                return matchesSearch && matchesCountry && matchesEligibility && matchesTier;
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
        const searchInputEl = document.getElementById('searchInput');
        const searchClearBtn = document.getElementById('searchClear');

        searchInputEl.addEventListener('input', () => {
            searchClearBtn.classList.toggle('hidden', !searchInputEl.value.trim());
            applyFilters();
        });
        searchClearBtn.addEventListener('click', () => {
            searchInputEl.value = '';
            searchClearBtn.classList.add('hidden');
            applyFilters();
            searchInputEl.focus();
        });
        /* Apply the same custom-dropdown styling used elsewhere (prefs wizard,
   skills/licenses modals) to the search-bar filters, for visual consistency. */
        function enhanceFilterSelect(id, placeholder) {
            const sel = document.getElementById(id);
            if (!sel) return null;
            const ctl = enhanceSelect(sel, {
                searchable: sel.options.length > 8,
                placeholder
            });
            sel.parentNode.classList.add('min-w-[150px]', 'sm:min-w-[170px]', 'flex-shrink-0');
            return ctl;
        }
        enhanceFilterSelect('countryFilter', 'All Countries');
        enhanceFilterSelect('matchTierFilter', 'All Match Levels');

        document.getElementById('countryFilter').addEventListener('change', applyFilters);
        document.getElementById('eligibleOnlyToggle').addEventListener('change', applyFilters);
        const matchTierFilterEl = document.getElementById('matchTierFilter');
        if (matchTierFilterEl) matchTierFilterEl.addEventListener('change', applyFilters);
        renderJobsPage();
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>