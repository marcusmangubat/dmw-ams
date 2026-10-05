<?php

/**
 * Shared applicant-job matching logic.
 *
 * Used by client/jobs.php (applicant job browsing),
 * evaluator/view-application.php (evaluator review), and
 * admin/view-application.php (admin review) so all sides compute
 * eligibility/match identically, live, from current data.
 *
 * Every detail sentence supports a $perspective argument:
 *   'third'  (default) — "the applicant's ..." — used by staff-facing
 *             screens (evaluator/admin) and by computeApplicationFit().
 *   'second' — "your ..." — used by the applicant-facing portal
 *             (client/jobs.php) so the wording reads naturally when
 *             shown back to the applicant themselves.
 */

if (!defined('EDU_RANK')) {
    define('EDU_RANK', [
        'Elementary Graduate'          => 1,
        'High School Graduate'         => 2,
        'Senior High School Graduate'  => 3,
        'Vocational/Technical'         => 4,
        'College Undergraduate'        => 5,
        'College Graduate'             => 6,
        'Post Graduate'                => 7
    ]);
}

/**
 * Returns the set of pronoun/verb forms used to build detail sentences,
 * so the same underlying logic can speak about the applicant in either
 * third person (staff-facing) or second person (applicant-facing).
 */
function subjectPronouns(string $perspective = 'third'): array
{
    if ($perspective === 'second') {
        return [
            'subj'  => 'you',
            'Subj'  => 'You',
            'poss'  => 'your',
            'Poss'  => 'Your',
            'their' => 'your',
            'is'    => 'are',
            'has'   => 'have',
            'hasnt' => "haven't",
            'holds' => 'hold',
        ];
    }

    // default: third person, staff-facing wording (unchanged from before)
    return [
        'subj'  => 'applicant',
        'Subj'  => 'Applicant',
        'poss'  => "applicant's",
        'Poss'  => "Applicant's",
        'their' => 'their',
        'is'    => 'is',
        'has'   => 'has',
        'hasnt' => "hasn't",
        'holds' => 'holds',
    ];
}

function checkQualifications(array $clientData, ?int $clientAge, array $quals, array $clientDegrees = [], array $clientLicenses = [], string $perspective = 'third'): array
{
    $s = subjectPronouns($perspective);

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
                    ? "Requires at least $value years old — {$s['poss']} age isn't set in {$s['their']} profile."
                    : "Requires at least $value years old — {$s['subj']} {$s['is']} $clientAge.");
                break;

            case 'max_age':
                $met = $clientAge === null || $clientAge <= (int)$value;
                $addCheck('Maximum Age', $met, $clientAge === null
                    ? "Must not exceed $value years old — {$s['poss']} age isn't set in {$s['their']} profile."
                    : "Must not exceed $value years old — {$s['subj']} {$s['is']} $clientAge.");
                break;

            case 'gender':
                $met = empty($clientData['gender']) || strtolower($value) === 'any'
                    || strtolower($clientData['gender']) === strtolower($value);
                $addCheck('Gender', $met, "Requires: $value" . (!empty($clientData['gender']) ? " ({$s['poss']} profile: {$clientData['gender']})" : " (not set in {$s['poss']} profile)"));
                break;

            case 'min_height_cm':
                $minHeight = (float) $value;
                break;

            case 'max_height_cm':
                $maxHeight = (float) $value;
                break;
            case 'min_weight_kg':
                $met = empty($clientData['weight_kg']) || (float)$clientData['weight_kg'] >= (float)$value;
                $addCheck('Minimum Weight', $met, "Requires at least {$value} kg" . (!empty($clientData['weight_kg']) ? " ({$s['poss']}: {$clientData['weight_kg']} kg)" : " (not set in {$s['poss']} profile)"));
                break;

            case 'max_weight_kg':
                $met = empty($clientData['weight_kg']) || (float)$clientData['weight_kg'] <= (float)$value;
                $addCheck('Maximum Weight', $met, "Must not exceed {$value} kg" . (!empty($clientData['weight_kg']) ? " ({$s['poss']}: {$clientData['weight_kg']} kg)" : " (not set in {$s['poss']} profile)"));
                break;

            case 'min_education':
                $required  = EDU_RANK[$value] ?? 0;
                $clientEdu = EDU_RANK[$clientData['educational_attainment'] ?? ''] ?? 0;
                $met = $clientEdu === 0 || $clientEdu >= $required;
                $addCheck('Minimum Education', $met, "Requires: $value" . (!empty($clientData['educational_attainment']) ? " ({$s['poss']}: {$clientData['educational_attainment']})" : " (not set in {$s['poss']} profile)"));
                break;

            case 'civil_status':
                $met = empty($clientData['civil_status']) || strtolower($value) === 'any'
                    || strtolower($clientData['civil_status']) === strtolower($value);
                $addCheck('Civil Status', $met, "Requires: $value" . (!empty($clientData['civil_status']) ? " ({$s['poss']}: {$clientData['civil_status']})" : " (not set in {$s['poss']} profile)"));
                break;

            case 'required_degree':
                $requiredDegrees[] = $value;
                break;

            case 'required_license':
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
            $met = true;
            $detail = "Requires {$rangeLabel} (not set in {$s['poss']} profile).";
        } else {
            $ch = (float) $clientHeight;
            $meetsMin = $minHeight === null || $ch >= $minHeight;
            $meetsMax = $maxHeight === null || $ch <= $maxHeight;
            $met = $meetsMin && $meetsMax;
            $detail = "Requires {$rangeLabel} ({$s['poss']}: {$ch} cm).";
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
            ? "{$s['Poss']} degree matches what this job accepts: $degreeList"
            : (empty($clientDegrees)
                ? "Requires a degree in: $degreeList ({$s['subj']} {$s['hasnt']} set a degree in {$s['their']} profile)"
                : "Requires a degree in: $degreeList ({$s['poss']} profile shows: " . implode(' / ', $clientDegrees) . ")"));
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
            ? "{$s['Subj']} {$s['holds']} a matching license: $licenseList"
            : (empty($clientLicenses)
                ? "Requires: $licenseList ({$s['subj']} {$s['hasnt']} added this license to {$s['their']} profile)"
                : "Requires: $licenseList ({$s['poss']} profile shows: " . implode(' / ', $clientLicenses) . ")"));
    }

    $failed = array_values(array_filter($checks, fn($c) => !$c['met']));

    return [
        'eligible' => empty($failed),
        'checks'   => $checks,
        'reasons'  => array_column($failed, 'detail'),
    ];
}

function formatPrefList(array $items, string $key): string
{
    $parts = [];
    foreach ($items as $item) {
        $parts[] = ($item[$key] ?? '') . ' (#' . (int) $item['priority_order'] . ')';
    }
    return implode(', ', $parts);
}

/**
 * Weighted preference-match score (0-100) — see computeMatchDetails() usage
 * in client/jobs.php for full component documentation. Identical logic here
 * so evaluator and applicant always see the same score for the same data.
 */
function computeMatchDetails(
    array $job,
    ?array $prefs,
    array $prefCountries,
    array $prefPositions,
    array $clientSkillMap,
    array $jobSkills,
    array $clientDegrees,
    array $jobRequiredDegrees,
    string $perspective = 'third'
): array {
    $s = subjectPronouns($perspective);

    $components  = [];
    $earnedTotal = 0.0;
    $maxTotal    = 0.0;

    // ── Preferred country ──
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
                $countryDetail  = "Matched {$s['poss']} #{$rank} preferred country ({$pc['country']}).";
                break;
            }
        }
        if (!$countryMatched) {
            $jobCountry    = $job['worksite_country'] ?: 'an unspecified location';
            $countryDetail = "This job is in {$jobCountry}, which isn't among {$s['poss']} preferred countries (" . formatPrefList($prefCountries, 'country') . ").";
        }
        $earnedTotal += $countryEarned;
        $maxTotal    += $countryMax;
    } else {
        $countryDetail = "{$s['Subj']} {$s['hasnt']} set any preferred countries.";
    }
    $components[] = [
        'label' => 'Preferred Country',
        'earned' => round($countryEarned, 1),
        'max' => $countryMax,
        'matched' => $countryMatched,
        'applicable' => $countryApplic,
        'detail' => $countryDetail,
    ];

    // ── Preferred position ──
    $positionMax     = 25;
    $positionEarned  = 0.0;
    $positionMatched = false;
    $positionApplic  = !empty($prefPositions);
    $positionDetail  = "{$s['Subj']} {$s['hasnt']} set any preferred positions.";
    if ($positionApplic) {
        foreach ($prefPositions as $pp) {
            if (!empty($job['subcategory_id']) && (int) $job['subcategory_id'] === (int) $pp['subcategory_id']) {
                $rank = (int) $pp['priority_order'];
                $positionEarned  = max(25 - ($rank - 1) * 8, 10);
                $positionMatched = true;
                $positionDetail  = "Matched {$s['poss']} #{$rank} preferred position ({$pp['subcategory_name']}).";
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
                    $positionDetail  = "{$s['Poss']} preferred position \"{$pp['subcategory_name']}\" appears in this job's title — partial credit, since this job order hasn't been tagged with a structured position category yet.";
                    break;
                }
            }
        }
        if (!$positionMatched) {
            $jobTitle       = $job['position_title'] ?: 'this position';
            $positionDetail = "\"{$jobTitle}\" doesn't match any of {$s['poss']} preferred positions (" . formatPrefList($prefPositions, 'subcategory_name') . ").";
        }
        $earnedTotal += $positionEarned;
        $maxTotal    += $positionMax;
    }
    $components[] = [
        'label' => 'Preferred Position',
        'earned' => round($positionEarned, 1),
        'max' => $positionMax,
        'matched' => $positionMatched,
        'applicable' => $positionApplic,
        'detail' => $positionDetail,
    ];

    // ── Required Skills ──
    $skillsMax     = 15;
    $skillsEarned  = 0.0;
    $skillsMatched = false;
    $skillsApplic  = !empty($jobSkills);
    $skillsDetail  = "This job order doesn't have specific skill requirements listed.";
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
                    ? "{$js['skill_name']} (needs {$js['min_proficiency']}, {$s['subj']} {$s['has']} {$clientProf})"
                    : $js['skill_name'];
            }
        }
        $ratio        = $totalWeight > 0 ? $earnedWeight / $totalWeight : 0;
        $skillsEarned = round($skillsMax * $ratio, 1);
        $skillsMatched = $ratio >= 0.999;
        if (!empty($haveNames) && !empty($missingNames)) {
            $skillsDetail = "{$s['Subj']} {$s['has']}: " . implode(', ', $haveNames) . ". Still missing: " . implode(', ', $missingNames) . ".";
        } elseif (!empty($haveNames)) {
            $skillsDetail = "{$s['Subj']} already {$s['has']} all the listed skills: " . implode(', ', $haveNames) . ".";
        } else {
            $skillsDetail = "This job is looking for: " . implode(', ', array_column($jobSkills, 'skill_name')) . " — none of these are on {$s['poss']} profile yet.";
        }
        $earnedTotal += $skillsEarned;
        $maxTotal    += $skillsMax;
    }
    $components[] = [
        'label' => 'Required Skills',
        'earned' => $skillsEarned,
        'max' => $skillsMax,
        'matched' => $skillsMatched,
        'applicable' => $skillsApplic,
        'detail' => $skillsDetail,
    ];

    // ── Required Degree ──
    $degreeMax     = 10;
    $degreeEarned  = 0.0;
    $degreeMatched = false;
    $degreeApplic  = !empty($jobRequiredDegrees);
    $degreeDetail  = "This job doesn't require a specific degree.";
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
            $degreeDetail = "{$s['Poss']} degree matches what this job accepts ({$degreeList}).";
        } else {
            $degreeDetail = empty($clientDegrees)
                ? "This job requires a degree in: {$degreeList} — {$s['subj']} {$s['hasnt']} set a degree in {$s['their']} profile."
                : "This job requires a degree in: {$degreeList}; {$s['poss']} profile shows " . implode(' / ', $clientDegrees) . ".";
        }
        $earnedTotal += $degreeEarned;
        $maxTotal    += $degreeMax;
    }
    $components[] = [
        'label' => 'Required Degree',
        'earned' => $degreeEarned,
        'max' => $degreeMax,
        'matched' => $degreeMatched,
        'applicable' => $degreeApplic,
        'detail' => $degreeDetail,
    ];

    // ── Expected Salary (PHP) ──
    $salaryMax     = 20;
    $salaryEarned  = 0.0;
    $salaryMatched = false;
    $salaryDetail  = '';
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
            $salaryDetail = "This job pays up to ₱" . number_format($jobMaxPhp) . "{$origSuffix} — meets or exceeds {$s['poss']} ₱" . number_format($clientMin) . " expectation.";
        } elseif ($jobMinPhp >= $clientMin * 0.75) {
            $salaryEarned = 12;
            $salaryMatched = true;
            $salaryDetail = "This job's range is a bit below {$s['poss']} expectation but still close (₱" . number_format($jobMinPhp) . "–" . number_format($jobMaxPhp) . ").";
        } else {
            $salaryDetail = "This job's maximum (₱" . number_format($jobMaxPhp) . ") is below {$s['poss']} minimum expectation of ₱" . number_format($clientMin) . ".";
        }

        $earnedTotal += $salaryEarned;
        $maxTotal    += $salaryMax;
    } elseif (!$prefHasSalary) {
        $salaryDetail = "{$s['Subj']} {$s['has']} not set a minimum salary expectation.";
    } else {
        $salaryDetail = "This job order does not have salary information.";
    }

    $components[] = [
        'label' => 'Expected Salary',
        'earned' => $salaryEarned,
        'max' => $salaryMax,
        'matched' => $salaryMatched,
        'applicable' => $salaryApplic,
        'detail' => $salaryDetail,
    ];

    $score = $maxTotal > 0
        ? (int) round(min(100, max(0, ($earnedTotal / $maxTotal) * 100)))
        : 50;

    return [
        'score'      => $score,
        'earned'     => round($earnedTotal, 1),
        'max'        => $maxTotal,
        'components' => $components,
    ];
}

/**
 * Live-recompute applicant fit & eligibility for an application.
 * Returns the same data shape used by view-application.php on evaluator/admin.
 * Always uses third-person (staff-facing) wording — this function is only
 * called from evaluator/admin screens.
 */
function computeApplicationFit(mysqli $database, int $app_id): array
{
    $empty = [
        'client_id'       => 0,
        'rsf_id'          => 0,
        'hasQuals'        => false,
        'eligible'        => true,
        'qualChecks'      => [],
        'hasPreferences'  => false,
        'matchDetails'    => null,
    ];

    $stmt = $database->prepare("SELECT client_id, rsf_id FROM applications WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $appIds = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $client_id = (int) ($appIds['client_id'] ?? 0);
    $rsf_id    = (int) ($appIds['rsf_id'] ?? 0);

    if (!$client_id || !$rsf_id) {
        return $empty;
    }

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

    $clientAge = null;
    if (!empty($clientData['dob'])) {
        $clientAge = (int) date_diff(date_create($clientData['dob']), date_create('today'))->y;
    }

    $clientDegrees = array_values(array_filter(array_unique([
        trim((string) ($clientData['profile_degree_name'] ?? '')),
        trim((string) ($clientData['course_degree'] ?? '')),
    ])));

    $clientSkillMap = [];
    $stmt = $database->prepare("
        SELECT cs.skill_id, cs.proficiency_level
        FROM client_skills cs
        WHERE cs.client_id = ?
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $cs) {
        $clientSkillMap[(int) $cs['skill_id']] = $cs['proficiency_level'];
    }
    $stmt->close();

    $stmt = $database->prepare("
        SELECT pl.license_name
        FROM client_licenses cl
        INNER JOIN professional_licenses pl ON pl.id = cl.license_id
        WHERE cl.client_id = ?
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $clientLicenseNames = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'license_name');
    $stmt->close();

    $stmt = $database->prepare("SELECT qualification_type, qualification_value FROM rsf_qualifications WHERE rsf_id = ?");
    $stmt->bind_param("i", $rsf_id);
    $stmt->execute();
    $jobQuals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $hasQuals = !empty($jobQuals);

    $qualCheck = ($hasQuals && $clientData)
        ? checkQualifications($clientData, $clientAge, $jobQuals, $clientDegrees, $clientLicenseNames)
        : ['eligible' => true, 'checks' => [], 'reasons' => []];

    $stmt = $database->prepare("
        SELECT cp.preferred_classification, cp.salary_range_id, cp.preferred_employment_type,
               sr.min_salary, sr.max_salary, sr.range_label
        FROM client_preferences cp
        LEFT JOIN salary_ranges sr ON sr.id = cp.salary_range_id
        WHERE cp.client_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $clientPrefs    = $stmt->get_result()->fetch_assoc();
    $hasPreferences = (bool) $clientPrefs;
    $stmt->close();

    $matchDetails = null;
    if ($hasPreferences) {
        $stmt = $database->prepare("SELECT country, priority_order FROM client_preferred_countries WHERE client_id = ? ORDER BY priority_order ASC");
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

        $stmt = $database->prepare("SELECT * FROM rsf_job_orders WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $rsf_id);
        $stmt->execute();
        $jobRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $database->prepare("
            SELECT rs.skill_id, s.skill_name, rs.importance, rs.min_proficiency
            FROM rsf_required_skills rs
            INNER JOIN skills s ON s.id = rs.skill_id
            WHERE rs.rsf_id = ?
        ");
        $stmt->bind_param("i", $rsf_id);
        $stmt->execute();
        $jobSkills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $jobRequiredDegrees = array_values(array_map(
            fn($q) => $q['qualification_value'],
            array_filter($jobQuals, fn($q) => $q['qualification_type'] === 'required_degree')
        ));

        $matchDetails = computeMatchDetails(
            $jobRow,
            $clientPrefs,
            $prefCountries,
            $prefPositions,
            $clientSkillMap,
            $jobSkills,
            $clientDegrees,
            $jobRequiredDegrees
        );
    }

    return [
        'client_id'      => $client_id,
        'rsf_id'         => $rsf_id,
        'hasQuals'       => $hasQuals,
        'eligible'       => $qualCheck['eligible'],
        'qualChecks'     => $qualCheck['checks'],
        'hasPreferences' => $hasPreferences,
        'matchDetails'   => $matchDetails,
    ];
}
