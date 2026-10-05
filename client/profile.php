<?php
// client profile.php
session_start();
require_once '../db.php';
require_once '../auth.php';
date_default_timezone_set('Asia/Manila');

// Require client login
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$client_email = $_SESSION['user_email'];
$client_id    = null;
$errors       = [];
$success_msg  = '';

// CSRF token for the profile picture forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get client id
$stmt_c = $database->prepare("SELECT cid FROM clients WHERE email = ? LIMIT 1");
$stmt_c->bind_param("s", $client_email);
$stmt_c->execute();
$c_result = $stmt_c->get_result();

if ($c_result->num_rows === 1) {
    $client_id = (int)$c_result->fetch_assoc()['cid'];
}
$stmt_c->close();

if (!$client_id) {
    header('Location: ../login.php');
    exit;
}

$app_count = 0;
$stmt = $database->prepare("SELECT COUNT(*) as c FROM applications WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$app_count = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Helper to safely echo a value into an input
function val(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
}
// Static option lists
$civil_status_options = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
$education_options = [
    'Elementary Graduate',
    'High School Graduate',
    'Senior High School Graduate',
    'Vocational/Technical',
    'College Undergraduate',
    'College Graduate',
    'Post Graduate',
];
$employment_type_options = ['Full-time', 'Part-time', 'Contract'];
$classification_options = [
    'Household Service Worker',
    'Skilled Worker',
    'Semi-Skilled Worker',
    'Unskilled Worker',
    'Professional/Technical Worker',
    'Seafarer/Maritime Worker',
    'Government',
    'Other',
];
// Common country list for the preferred-country autocomplete (freeform text is still allowed)
$country_list = [
    'Afghanistan',
    'Australia',
    'Bahrain',
    'Bangladesh',
    'Brunei',
    'Cambodia',
    'Canada',
    'China',
    'Cyprus',
    'Denmark',
    'Federal Republic of Germany',
    'Finland',
    'France',
    'Greece',
    'Hong Kong',
    'Indonesia',
    'Ireland',
    'Israel',
    'Italy',
    'Japan',
    'Jordan',
    'Kuwait',
    'Lebanon',
    'Libya',
    'Macau',
    'Malaysia',
    'Maldives',
    'Malta',
    'Netherlands',
    'New Zealand',
    'Norway',
    'Oman',
    'Papua New Guinea',
    'Poland',
    'Portugal',
    'Qatar',
    'Republic of Korea (South Korea)',
    'Russia',
    'Saudi Arabia',
    'Singapore',
    'South Africa',
    'Spain',
    'Sweden',
    'Switzerland',
    'Taiwan',
    'Thailand',
    'Turkey',
    'United Arab Emirates',
    'United Kingdom',
    'United States of America',
    'Vietnam',
];

// Reference / lookup tables (fetched before POST handling so
// submitted values can be validated against real records)
$degree_programs = [];
$dp_res = $database->query("SELECT id, degree_name, degree_category FROM degree_programs ORDER BY degree_category, degree_name");
if ($dp_res) {
    while ($row = $dp_res->fetch_assoc()) $degree_programs[] = $row;
}

$job_categories = [];
$jc_res = $database->query("SELECT id, category_name FROM job_categories ORDER BY category_name");
if ($jc_res) {
    while ($row = $jc_res->fetch_assoc()) $job_categories[] = $row;
}

$job_subcategories = [];
$valid_subcategory_ids = [];
$js_res = $database->query("SELECT id, category_id, subcategory_name FROM job_subcategories ORDER BY subcategory_name");
if ($js_res) {
    while ($row = $js_res->fetch_assoc()) {
        $job_subcategories[] = $row;
        $valid_subcategory_ids[(int)$row['id']] = $row;
    }
}

// Salary range options + a lookup keyed by id (for validating a submitted id)
$salary_ranges = [];
$valid_salary_range_ids = [];
$sr_res = $database->query("SELECT id, range_label, min_salary, max_salary FROM salary_ranges ORDER BY sort_order ASC, min_salary ASC");
if ($sr_res) {
    while ($row = $sr_res->fetch_assoc()) {
        $salary_ranges[] = $row;
        $valid_salary_range_ids[(int)$row['id']] = $row;
    }
}

// Group subcategories by category, for the cascading dropdown (client-side)
$subcategories_by_category = [];
foreach ($job_subcategories as $sc) {
    $subcategories_by_category[(int)$sc['category_id']][] = [
        'id'   => (int)$sc['id'],
        'name' => $sc['subcategory_name'],
    ];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Section 0: Profile Picture Upload → clients.profile_picture ──
    if (isset($_POST['upload_picture'])) {

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $errors[] = 'Invalid form submission. Please refresh and try again.';
        }

        if (empty($errors)) {
            if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Please choose an image to upload.';
            } elseif ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'File upload failed. Please try again.';
            } else {
                $file = $_FILES['profile_picture'];

                if ($file['size'] > 2 * 1024 * 1024) {
                    $errors[] = 'Profile picture must not exceed 2MB.';
                }

                // Verify actual MIME type server-side — never trust $_FILES['type']
                $allowed_mimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                ];
                $finfo     = finfo_open(FILEINFO_MIME_TYPE);
                $real_mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowed_mimes[$real_mime])) {
                    $errors[] = 'Only JPG, PNG, or WEBP images are allowed.';
                }

                if (empty($errors) && @getimagesize($file['tmp_name']) === false) {
                    $errors[] = 'The uploaded file is not a valid image.';
                }

                if (empty($errors)) {
                    $ext          = $allowed_mimes[$real_mime];
                    $new_filename = $client_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

                    $upload_dir = __DIR__ . '/../uploads/profile_pictures/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $destination   = $upload_dir . $new_filename;
                    $relative_path = 'uploads/profile_pictures/' . $new_filename;

                    if (move_uploaded_file($file['tmp_name'], $destination)) {

                        // Grab old picture path so we can delete it after DB update succeeds
                        $stmt_old = $database->prepare("SELECT profile_picture FROM clients WHERE cid = ?");
                        $stmt_old->bind_param("i", $client_id);
                        $stmt_old->execute();
                        $old_picture = $stmt_old->get_result()->fetch_assoc()['profile_picture'] ?? null;
                        $stmt_old->close();

                        $stmt_up = $database->prepare("UPDATE clients SET profile_picture = ? WHERE cid = ?");
                        $stmt_up->bind_param("si", $relative_path, $client_id);

                        if ($stmt_up->execute()) {
                            $success_msg = 'Profile picture updated successfully.';

                            if (!empty($old_picture)) {
                                $old_full_path = __DIR__ . '/../' . $old_picture;
                                if (is_file($old_full_path)) {
                                    @unlink($old_full_path);
                                }
                            }
                        } else {
                            @unlink($destination); // rollback saved file if DB write failed
                            $errors[] = 'Something went wrong while saving your profile picture. Please try again.';
                        }
                        $stmt_up->close();
                    } else {
                        $errors[] = 'Could not save the uploaded file. Please try again.';
                    }
                }
            }
        }
    }

    //  Remove Profile Picture 
    if (isset($_POST['remove_picture'])) {

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $errors[] = 'Invalid form submission. Please refresh and try again.';
        }

        if (empty($errors)) {
            $stmt_old = $database->prepare("SELECT profile_picture FROM clients WHERE cid = ?");
            $stmt_old->bind_param("i", $client_id);
            $stmt_old->execute();
            $old_picture = $stmt_old->get_result()->fetch_assoc()['profile_picture'] ?? null;
            $stmt_old->close();

            $stmt_rm = $database->prepare("UPDATE clients SET profile_picture = NULL WHERE cid = ?");
            $stmt_rm->bind_param("i", $client_id);

            if ($stmt_rm->execute()) {
                if (!empty($old_picture)) {
                    $old_full_path = __DIR__ . '/../' . $old_picture;
                    if (is_file($old_full_path)) {
                        @unlink($old_full_path);
                    }
                }
                $success_msg = 'Profile picture removed.';
            } else {
                $errors[] = 'Something went wrong while removing your profile picture.';
            }
            $stmt_rm->close();
        }
    }

    // Section 1: Personal Information → clients table
    if (isset($_POST['save_personal'])) {

        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $suffix     = trim($_POST['suffix'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $barangay   = trim($_POST['barangay'] ?? '');
        $city       = trim($_POST['city_municipality'] ?? '');
        $province   = trim($_POST['province'] ?? '');
        $dob        = trim($_POST['dob'] ?? '');
        $gender     = trim($_POST['gender'] ?? '');

        if ($first_name === '') $errors[] = 'First name is required.';
        if ($last_name === '')  $errors[] = 'Last name is required.';
        if ($barangay === '')   $errors[] = 'Barangay is required.';
        if ($city === '')       $errors[] = 'City/Municipality is required.';
        if ($province === '')   $errors[] = 'Province is required.';
        if ($dob !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $dob);
            if (!$d || $d->format('Y-m-d') !== $dob) {
                $errors[] = 'Date of birth is invalid.';
            }
        }

        if (empty($errors)) {
            $stmt = $database->prepare("
                UPDATE clients SET
                    first_name = ?,
                    middle_name = ?,
                    last_name = ?,
                    suffix = ?,
                    phone = ?,
                    address = ?,
                    barangay = ?,
                    city_municipality = ?,
                    province = ?,
                    dob = ?,
                    gender = ?
                WHERE cid = ?
            ");
            $dob_param    = $dob === '' ? null : $dob;
            $gender_param = $gender === '' ? null : $gender;
            $stmt->bind_param(
                "sssssssssssi",
                $first_name,
                $middle_name,
                $last_name,
                $suffix,
                $phone,
                $address,
                $barangay,
                $city,
                $province,
                $dob_param,
                $gender_param,
                $client_id
            );

            if ($stmt->execute()) {
                $success_msg = 'Personal information updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your personal information. Please try again.';
            }
            $stmt->close();
        }
    }

    // Section 2: Applicant Profile → client_profiles table 
    if (isset($_POST['save_profile'])) {

        $citizenship  = trim($_POST['citizenship'] ?? '');
        $civil_status = trim($_POST['civil_status'] ?? '');
        $religion     = trim($_POST['religion'] ?? '');
        $height_cm    = trim($_POST['height_cm'] ?? '');
        $weight_kg    = trim($_POST['weight_kg'] ?? '');
        $education    = trim($_POST['educational_attainment'] ?? '');
        $degree_id    = trim($_POST['degree_id'] ?? '');
        $course       = trim($_POST['course_degree'] ?? '');
        $school_name  = trim($_POST['school_name'] ?? '');
        $school_addr  = trim($_POST['school_address'] ?? '');
        $year_grad    = trim($_POST['year_graduated'] ?? '');
        $work_years   = trim($_POST['work_experience_years'] ?? '');
        $last_employer = trim($_POST['last_employer'] ?? '');
        $last_position = trim($_POST['last_position'] ?? '');

        if ($citizenship === '')  $errors[] = 'Citizenship is required.';
        if ($civil_status === '') $errors[] = 'Civil status is required.';
        if ($education === '')    $errors[] = 'Educational attainment is required.';
        if ($civil_status !== '' && !in_array($civil_status, $civil_status_options, true)) {
            $errors[] = 'Invalid civil status selected.';
        }
        if ($education !== '' && !in_array($education, $education_options, true)) {
            $errors[] = 'Invalid educational attainment selected.';
        }
        if ($height_cm !== '' && (!is_numeric($height_cm) || (float)$height_cm <= 0)) {
            $errors[] = 'Height must be a valid positive number.';
        }
        if ($weight_kg !== '' && (!is_numeric($weight_kg) || (float)$weight_kg <= 0)) {
            $errors[] = 'Weight must be a valid positive number.';
        }
        if ($year_grad !== '' && (!ctype_digit($year_grad) || (int)$year_grad < 1950 || (int)$year_grad > (int)date('Y') + 1)) {
            $errors[] = 'Year graduated is invalid.';
        }
        if ($work_years !== '' && (!ctype_digit($work_years) || (int)$work_years < 0)) {
            $errors[] = 'Work experience years must be a valid non-negative number.';
        }
        if ($degree_id !== '' && !array_key_exists((int)$degree_id, array_column($degree_programs, null, 'id'))) {
            $errors[] = 'Invalid degree program selected.';
        }

        // Normalize empty strings to NULL for nullable numeric/enum/year fields
        $height_cm  = $height_cm === '' ? null : (float)$height_cm;
        $weight_kg  = $weight_kg === '' ? null : (float)$weight_kg;
        $year_grad  = $year_grad === '' ? null : (int)$year_grad;
        $work_years = $work_years === '' ? null : (int)$work_years;
        $degree_id  = $degree_id === '' ? null : (int)$degree_id;
        $civil_status = $civil_status === '' ? null : $civil_status;
        $education    = $education === '' ? null : $education;

        if (empty($errors)) {
            // client_id has a UNIQUE KEY, so this inserts a new profile row
            // the first time, or updates the existing one on subsequent saves.
            $stmt = $database->prepare("
                INSERT INTO client_profiles (
                    client_id, citizenship, civil_status, religion,
                    height_cm, weight_kg, educational_attainment, degree_id, course_degree,
                    school_name, school_address, year_graduated,
                    work_experience_years, last_employer, last_position,
                    profile_completed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    citizenship = VALUES(citizenship),
                    civil_status = VALUES(civil_status),
                    religion = VALUES(religion),
                    height_cm = VALUES(height_cm),
                    weight_kg = VALUES(weight_kg),
                    educational_attainment = VALUES(educational_attainment),
                    degree_id = VALUES(degree_id),
                    course_degree = VALUES(course_degree),
                    school_name = VALUES(school_name),
                    school_address = VALUES(school_address),
                    year_graduated = VALUES(year_graduated),
                    work_experience_years = VALUES(work_experience_years),
                    last_employer = VALUES(last_employer),
                    last_position = VALUES(last_position),
                    profile_completed_at = COALESCE(profile_completed_at, NOW())
            ");
            $stmt->bind_param(
                "isssddsisssiiss",
                $client_id,
                $citizenship,
                $civil_status,
                $religion,
                $height_cm,
                $weight_kg,
                $education,
                $degree_id,
                $course,
                $school_name,
                $school_addr,
                $year_grad,
                $work_years,
                $last_employer,
                $last_position
            );

            if ($stmt->execute()) {
                $success_msg = 'Applicant profile updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your profile information. Please try again.';
            }
            $stmt->close();
        }
    }

    // Section 3: Career & Salary Preferences → client_preferences table
    if (isset($_POST['save_preferences'])) {

        $preferred_classification = trim($_POST['preferred_classification'] ?? '');
        $salary_range_id_raw = trim($_POST['salary_range_id'] ?? '');
        $employment_type = trim($_POST['preferred_employment_type'] ?? 'Full-time');

        if ($preferred_classification === '') $errors[] = 'Preferred job classification is required.';
        if (!in_array($employment_type, $employment_type_options, true)) {
            $errors[] = 'Invalid employment type selected.';
        }

        // Validate the submitted salary range against the real lookup table
        // (salary is optional — an empty selection is allowed).
        $salary_range_id = null;
        if ($salary_range_id_raw !== '') {
            if (!ctype_digit($salary_range_id_raw) || !array_key_exists((int)$salary_range_id_raw, $valid_salary_range_ids)) {
                $errors[] = 'Please select a valid salary range.';
            } else {
                $salary_range_id = (int) $salary_range_id_raw;
            }
        }

        if (empty($errors)) {
            $stmt = $database->prepare("
                INSERT INTO client_preferences (
                    client_id, preferred_classification, salary_range_id,
                    preferred_employment_type
                ) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    preferred_classification = VALUES(preferred_classification),
                    salary_range_id = VALUES(salary_range_id),
                    preferred_employment_type = VALUES(preferred_employment_type)
            ");
            $stmt->bind_param(
                "isis",
                $client_id,
                $preferred_classification,
                $salary_range_id,
                $employment_type
            );

            if ($stmt->execute()) {
                $success_msg = 'Career & salary preferences updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your preferences. Please try again.';
            }
            $stmt->close();
        }
    }

    // Section 4: Preferred Countries → client_preferred_countries table
    if (isset($_POST['save_countries'])) {

        $countries_in  = $_POST['country'] ?? [];
        $priorities_in = $_POST['country_priority'] ?? [];

        $clean_countries = [];
        $seen = [];

        if (is_array($countries_in)) {
            foreach ($countries_in as $idx => $c) {
                $c = trim((string)$c);
                if ($c === '') continue;
                if (strlen($c) > 100) {
                    $errors[] = "Country name \"$c\" is too long.";
                    continue;
                }
                $key = mb_strtolower($c);
                if (isset($seen[$key])) continue; // skip duplicate entries
                $seen[$key] = true;

                $priority = isset($priorities_in[$idx]) ? (int)$priorities_in[$idx] : (count($clean_countries) + 1);
                if ($priority < 1) $priority = 1;

                $clean_countries[] = ['country' => $c, 'priority_order' => $priority];
            }
        }

        if (count($clean_countries) > 20) {
            $errors[] = 'You can list a maximum of 20 preferred countries.';
        }

        if (empty($errors)) {
            $database->begin_transaction();
            $ok = true;

            $del = $database->prepare("DELETE FROM client_preferred_countries WHERE client_id = ?");
            $del->bind_param("i", $client_id);
            if (!$del->execute()) $ok = false;
            $del->close();

            if ($ok && !empty($clean_countries)) {
                $ins = $database->prepare("INSERT INTO client_preferred_countries (client_id, country, priority_order) VALUES (?, ?, ?)");
                foreach ($clean_countries as $row) {
                    $ins->bind_param("isi", $client_id, $row['country'], $row['priority_order']);
                    if (!$ins->execute()) {
                        $ok = false;
                        break;
                    }
                }
                $ins->close();
            }

            if ($ok) {
                $database->commit();
                $success_msg = 'Preferred countries updated successfully.';
            } else {
                $database->rollback();
                $errors[] = 'Something went wrong while saving your preferred countries. Please try again.';
            }
        }
    }

    // Section 5: Preferred Job Positions → client_preferred_positions table
    if (isset($_POST['save_positions'])) {

        $subcats_in    = $_POST['subcategory_id'] ?? [];
        $priorities_in = $_POST['position_priority'] ?? [];

        $clean_positions = [];
        $seen = [];

        if (is_array($subcats_in)) {
            foreach ($subcats_in as $idx => $sid) {
                $sid = trim((string)$sid);
                if ($sid === '') continue;
                if (!ctype_digit($sid) || !array_key_exists((int)$sid, $valid_subcategory_ids)) {
                    $errors[] = 'One of the selected job positions is invalid.';
                    continue;
                }
                $sid = (int)$sid;
                if (isset($seen[$sid])) continue; // skip duplicate entries
                $seen[$sid] = true;

                $priority = isset($priorities_in[$idx]) ? (int)$priorities_in[$idx] : (count($clean_positions) + 1);
                if ($priority < 1) $priority = 1;

                $clean_positions[] = ['subcategory_id' => $sid, 'priority_order' => $priority];
            }
        }

        if (count($clean_positions) > 20) {
            $errors[] = 'You can list a maximum of 20 preferred job positions.';
        }

        if (empty($errors)) {
            $database->begin_transaction();
            $ok = true;

            $del = $database->prepare("DELETE FROM client_preferred_positions WHERE client_id = ?");
            $del->bind_param("i", $client_id);
            if (!$del->execute()) $ok = false;
            $del->close();

            if ($ok && !empty($clean_positions)) {
                $ins = $database->prepare("INSERT INTO client_preferred_positions (client_id, subcategory_id, priority_order) VALUES (?, ?, ?)");
                foreach ($clean_positions as $row) {
                    $ins->bind_param("iii", $client_id, $row['subcategory_id'], $row['priority_order']);
                    if (!$ins->execute()) {
                        $ok = false;
                        break;
                    }
                }
                $ins->close();
            }

            if ($ok) {
                $database->commit();
                $success_msg = 'Preferred job positions updated successfully.';
            } else {
                $database->rollback();
                $errors[] = 'Something went wrong while saving your preferred job positions. Please try again.';
            }
        }
    }
}

// Fetch current data (after any save, so the form reflects latest)
$stmt = $database->prepare("SELECT * FROM clients WHERE cid = ? LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $database->prepare("SELECT * FROM client_profiles WHERE client_id = ? LIMIT 1");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$profile_res = $stmt->get_result();
$profile = $profile_res->num_rows === 1 ? $profile_res->fetch_assoc() : [];
$stmt->close();

$stmt = $database->prepare("
    SELECT cp.*, sr.range_label AS salary_range_label
    FROM client_preferences cp
    LEFT JOIN salary_ranges sr ON sr.id = cp.salary_range_id
    WHERE cp.client_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$pref_res = $stmt->get_result();
$preferences = $pref_res->num_rows === 1 ? $pref_res->fetch_assoc() : [];
$stmt->close();

$stmt = $database->prepare("SELECT id, country, priority_order FROM client_preferred_countries WHERE client_id = ? ORDER BY priority_order ASC, id ASC");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$countries_res = $stmt->get_result();
$preferred_countries = [];
while ($row = $countries_res->fetch_assoc()) $preferred_countries[] = $row;
$stmt->close();

$stmt = $database->prepare("
    SELECT cpp.id, cpp.subcategory_id, cpp.priority_order,
           js.subcategory_name, js.category_id, jc.category_name
    FROM client_preferred_positions cpp
    JOIN job_subcategories js ON js.id = cpp.subcategory_id
    JOIN job_categories jc ON jc.id = js.category_id
    WHERE cpp.client_id = ?
    ORDER BY cpp.priority_order ASC, cpp.id ASC
");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$positions_res = $stmt->get_result();
$preferred_positions = [];
while ($row = $positions_res->fetch_assoc()) $preferred_positions[] = $row;
$stmt->close();

// Greeting avatar (same pattern as evaluator pages)
$full_name  = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
$first_name = $client['first_name'] ?? 'Client';

$parts    = explode(' ', $full_name);
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($full_name ?: 'C', 0, 2));

// Profile completeness meter (purely informational, not saved anywhere)
$completeness_fields = [
    $client['phone'] ?? null,
    $client['address'] ?? null,
    $client['dob'] ?? null,
    $client['gender'] ?? null,
    $profile['citizenship'] ?? null,
    $profile['civil_status'] ?? null,
    $profile['educational_attainment'] ?? null,
    $profile['school_name'] ?? null,
    $preferences['preferred_classification'] ?? null,
    !empty($preferred_countries) ? 1 : null,
    !empty($preferred_positions) ? 1 : null,
];
$filled_count = count(array_filter($completeness_fields, fn($v) => $v !== null && $v !== ''));
$completeness_pct = (int)round(($filled_count / count($completeness_fields)) * 100);

$has_picture = !empty($client['profile_picture']);
$picture_url = $has_picture ? '../' . $client['profile_picture'] : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Applicant Settings – DMW Client Portal</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        :root {
            --settings-emerald: #059669;
            --settings-amber: #d97706;
            --settings-violet: #7c3aed;
            --settings-rose: #e11d48;
        }

        .settings-shell {
            display: flex;
            flex-direction: column;
            gap: 18px;
            max-width: 980px;
            margin: 0 auto;
        }

        /*  Profile header w/ completeness meter  */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .profile-avatar-lg {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: #eef2ff;
            color: var(--indigo);
            font-size: 19px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .profile-header h3 {
            font-size: 16px;
            font-weight: 800;
            color: var(--text1);
            font-family: 'DM Sans', sans-serif;
        }

        .profile-header p {
            font-size: 11.5px;
            color: var(--text3);
            font-weight: 500;
            margin-top: 2px;
        }

        .completeness-wrap {
            margin-left: auto;
            min-width: 200px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .completeness-label {
            display: flex;
            justify-content: space-between;
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text2);
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .completeness-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: #eef1f6;
            overflow: hidden;
        }

        .completeness-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--indigo), var(--settings-violet));
            transition: width .4s ease;
        }

        @media (max-width: 640px) {
            .completeness-wrap {
                margin-left: 0;
                width: 100%;
            }
        }

        /*  Section nav (jump links)  */
        .section-nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            position: sticky;
            top: 8px;
            z-index: 5;
        }

        .section-nav a {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text2);
            text-decoration: none;
            transition: background .15s, color .15s;
            white-space: nowrap;
        }

        .section-nav a:hover {
            background: #f1f2fb;
            color: var(--indigo);
        }

        .section-nav a i {
            font-size: 11px;
        }

        /* ── Card / section layout ── */
        .card-section {
            scroll-margin-top: 70px;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .card-title-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .card-subtitle {
            font-size: 11px;
            color: var(--text3);
            font-weight: 500;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-top: 14px;
        }

        .form-grid.cols-3 {
            grid-template-columns: repeat(3, 1fr);
        }

        .form-grid .full {
            grid-column: 1 / -1;
        }

        .f-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .f-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text2);
        }

        .f-label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .f-input,
        .f-select,
        .f-textarea {
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            color: var(--text1);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
            width: 100%;
        }

        .f-textarea {
            resize: vertical;
            min-height: 64px;
        }

        .f-input:focus,
        .f-select:focus,
        .f-textarea:focus {
            border-color: var(--indigo);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, .12);
        }

        .f-input:disabled {
            background: #f1f5f9;
            color: var(--text3);
            cursor: not-allowed;
        }

        .f-hint {
            font-size: 10px;
            color: var(--text3);
            font-weight: 500;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        .btn-save {
            background: var(--indigo);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 11px 22px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background .15s, transform .15s;
        }

        .btn-save:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }

        .btn-save:active {
            transform: scale(.98);
        }

        .btn-ghost {
            background: transparent;
            color: var(--indigo);
            border: 1.5px dashed var(--indigo);
            border-radius: 10px;
            padding: 9px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background .15s;
        }

        .btn-ghost:hover {
            background: #eef2ff;
        }

        .btn-icon-remove {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-icon-remove:hover {
            background: #fee2e2;
        }

        .alert-box {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 4px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-error ul {
            margin: 4px 0 0 16px;
            padding: 0;
        }

        /*  Toggle switch (willing to relocate)  */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 12px;
            background: var(--surface);
        }

        .toggle-row .f-label {
            margin: 0;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #cbd5e1;
            border-radius: 999px;
            transition: background .2s;
        }

        .switch-slider::before {
            content: "";
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .25);
        }

        .switch input:checked+.switch-slider {
            background: var(--indigo);
        }

        .switch input:checked+.switch-slider::before {
            transform: translateX(18px);
        }

        /*  Dynamic repeatable rows (countries / positions)  */
        .dyn-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 14px;
        }

        .dyn-row {
            display: grid;
            grid-template-columns: 1fr 100px 40px;
            gap: 10px;
            align-items: center;
            background: #f8f9fd;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px;
        }

        .dyn-row.dyn-row-cascade {
            grid-template-columns: 1fr 1fr 100px 40px;
        }

        .dyn-row-rank {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .dyn-row-rank label {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text3);
            text-transform: uppercase;
        }

        .dyn-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 26px 16px;
            border: 1.5px dashed var(--border);
            border-radius: 12px;
            color: var(--text3);
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }

        .dyn-empty i {
            font-size: 20px;
            color: #c7cbe0;
        }

        @media (max-width: 720px) {
            .dyn-row {
                grid-template-columns: 1fr;
            }

            .dyn-row.dyn-row-cascade {
                grid-template-columns: 1fr;
            }

            .dyn-row-remove-wrap {
                display: flex;
                justify-content: flex-end;
            }
        }

        @media (max-width: 640px) {

            .form-grid,
            .form-grid.cols-3 {
                grid-template-columns: 1fr;
            }
        }

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
            animation-delay: .05s
        }

        .d2 {
            animation-delay: .1s
        }

        .d3 {
            animation-delay: .15s
        }

        .d4 {
            animation-delay: .2s
        }

        .d5 {
            animation-delay: .25s
        }

        .d6 {
            animation-delay: .3s
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;

        }

        .avatar-upload-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar-lg {
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .avatar-camera-btn {
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--indigo);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            border: 2px solid #fff;
            transition: background .15s;
        }

        .avatar-camera-btn:hover {
            background: #4338ca;
        }

        .avatar-remove-btn {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #dc2626;
            color: #fff;
            border: 2px solid #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            cursor: pointer;
            padding: 0;
        }

        .avatar-remove-btn:hover {
            background: #b91c1c;
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
            <div class="topbar-avatar" <?= $has_picture ? "style=\"background-image:url('" . val($picture_url) . "');background-size:cover;background-position:center;\"" : '' ?>>
                <?= $has_picture ? '' : val($initials) ?>
            </div>
        </div>
    </header>

    <!--SUBBAR -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Applicant Settings</h2>
                <p>Manage your personal information, applicant profile, and job preferences</p>
            </div>
        </div>
    </div>

    <!--MAIN CONTENT -->
    <div class="main">
        <div class="settings-shell">

            <?php if (!empty($success_msg)): ?>
                <div class="alert-box alert-success fade-in">
                    <i class="fas fa-circle-check" style="margin-top:1px"></i>
                    <span><?= val($success_msg) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert-box alert-error fade-in">
                    <i class="fas fa-triangle-exclamation" style="margin-top:1px"></i>
                    <div>
                        Please fix the following:
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?= val($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card fade-in d1">
                <div class="profile-header">
                    <div class="avatar-upload-wrap">
                        <div class="profile-avatar-lg" <?= $has_picture ? "style=\"background-image:url('" . val($picture_url) . "');\"" : '' ?>>
                            <?= $has_picture ? '' : val($initials) ?>
                        </div>

                        <form method="POST" action="profile.php" enctype="multipart/form-data" id="pictureForm">
                            <input type="hidden" name="csrf_token" value="<?= val($csrf_token) ?>">
                            <input type="hidden" name="upload_picture" value="1">
                            <label for="pictureInput" class="avatar-camera-btn" title="Change profile picture">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" name="profile_picture" id="pictureInput" accept="image/jpeg,image/png,image/webp" hidden>
                        </form>

                        <?php if ($has_picture): ?>
                            <form method="POST" action="profile.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= val($csrf_token) ?>">
                                <button type="submit" name="remove_picture" class="avatar-remove-btn" title="Remove profile picture"
                                    onclick="return confirm('Remove your profile picture?');">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3><?= val($full_name) ?></h3>
                        <p><?= val($client['email'] ?? '') ?></p>
                        <span class="f-hint">JPG, PNG, or WEBP · Max 2MB</span>
                    </div>
                    <div class="completeness-wrap">
                        <div class="completeness-label">
                            <span>Profile Completeness</span>
                            <span><?= $completeness_pct ?>%</span>
                        </div>
                        <div class="completeness-track">
                            <div class="completeness-fill" style="width: <?= $completeness_pct ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick section navigation -->
            <nav class="section-nav fade-in d1">
                <a href="#sec-personal"><i class="fas fa-id-card"></i> Personal</a>
                <a href="#sec-profile"><i class="fas fa-user-graduate"></i> Applicant Profile</a>
                <a href="#sec-preferences"><i class="fas fa-sliders"></i> Career & Salary</a>
                <a href="#sec-countries"><i class="fas fa-earth-asia"></i> Preferred Countries</a>
                <a href="#sec-positions"><i class="fas fa-briefcase"></i> Preferred Positions</a>
            </nav>

            <!--SECTION 1: PERSONAL INFORMATION -->
            <div class="card card-section fade-in d2" id="sec-personal">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--blue-lt)"></span>
                            Personal Information
                        </span>
                        <span class="card-subtitle">Core identity and contact details on file with DMW</span>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-grid">

                        <div class="f-group">
                            <label class="f-label">Email Address</label>
                            <input class="f-input" type="email" value="<?= val($client['email'] ?? '') ?>" disabled>
                            <span class="f-hint">Contact support to change your login email.</span>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Phone Number</label>
                            <input class="f-input" type="text" name="phone" value="<?= val($client['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
                        </div>

                        <div class="f-group">
                            <label class="f-label">First Name<span class="req">*</span></label>
                            <input class="f-input" type="text" name="first_name" value="<?= val($client['first_name'] ?? '') ?>" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Middle Name</label>
                            <input class="f-input" type="text" name="middle_name" value="<?= val($client['middle_name'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Last Name<span class="req">*</span></label>
                            <input class="f-input" type="text" name="last_name" value="<?= val($client['last_name'] ?? '') ?>" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Suffix</label>
                            <input class="f-input" type="text" name="suffix" value="<?= val($client['suffix'] ?? '') ?>" placeholder="Jr, Sr, III, etc.">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Date of Birth</label>
                            <input class="f-input" type="date" name="dob" value="<?= val($client['dob'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Gender</label>
                            <select class="f-select" name="gender">
                                <option value="">Select gender</option>
                                <?php foreach (['Male', 'Female'] as $g): ?>
                                    <option value="<?= val($g) ?>" <?= ($client['gender'] ?? '') === $g ? 'selected' : '' ?>>
                                        <?= val($g) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group full">
                            <label class="f-label">Street Address</label>
                            <input class="f-input" type="text" name="address" value="<?= val($client['address'] ?? '') ?>" placeholder="House no., Street">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Barangay<span class="req">*</span></label>
                            <input class="f-input" type="text" name="barangay" value="<?= val($client['barangay'] ?? '') ?>" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">City / Municipality<span class="req">*</span></label>
                            <input class="f-input" type="text" name="city_municipality" value="<?= val($client['city_municipality'] ?? '') ?>" required>
                        </div>

                        <div class="f-group full">
                            <label class="f-label">Province<span class="req">*</span></label>
                            <input class="f-input" type="text" name="province" value="<?= val($client['province'] ?? '') ?>" required>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_personal" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Personal Info
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 2: APPLICANT PROFILE-->
            <div class="card card-section fade-in d3" id="sec-profile">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            Applicant Profile
                        </span>
                        <span class="card-subtitle">Physical, civil, and educational background used for job matching</span>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-grid">

                        <div class="f-group">
                            <label class="f-label">Citizenship<span class="req">*</span></label>
                            <input class="f-input" type="text" name="citizenship" value="<?= val($profile['citizenship'] ?? '') ?>" placeholder="e.g. Filipino" required>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Civil Status<span class="req">*</span></label>
                            <select class="f-select" name="civil_status" required>
                                <option value="">Select civil status</option>
                                <?php foreach ($civil_status_options as $cs): ?>
                                    <option value="<?= val($cs) ?>" <?= ($profile['civil_status'] ?? '') === $cs ? 'selected' : '' ?>>
                                        <?= val($cs) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Religion</label>
                            <input class="f-input" type="text" name="religion" value="<?= val($profile['religion'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Height (cm)</label>
                            <input class="f-input" type="number" step="0.01" min="0" name="height_cm" value="<?= val($profile['height_cm'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Weight (kg)</label>
                            <input class="f-input" type="number" step="0.01" min="0" name="weight_kg" value="<?= val($profile['weight_kg'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Educational Attainment<span class="req">*</span></label>
                            <select class="f-select" name="educational_attainment" required>
                                <option value="">Select educational attainment</option>
                                <?php foreach ($education_options as $ed): ?>
                                    <option value="<?= val($ed) ?>" <?= ($profile['educational_attainment'] ?? '') === $ed ? 'selected' : '' ?>>
                                        <?= val($ed) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Degree Program</label>
                            <select class="f-select" name="degree_id">
                                <option value="">— Not listed / not applicable —</option>
                                <?php foreach ($degree_programs as $dp): ?>
                                    <option value="<?= val($dp['id']) ?>" <?= (string)($profile['degree_id'] ?? '') === (string)$dp['id'] ? 'selected' : '' ?>>
                                        <?= val($dp['degree_name']) ?><?= $dp['degree_category'] ? ' (' . val($dp['degree_category']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="f-hint">Pick the closest match, if applicable.</span>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Course / Degree (as written on diploma)</label>
                            <input class="f-input" type="text" name="course_degree" value="<?= val($profile['course_degree'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Year Graduated</label>
                            <input class="f-input" type="number" min="1950" max="<?= (int)date('Y') + 1 ?>" name="year_graduated" value="<?= val($profile['year_graduated'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">School Name</label>
                            <input class="f-input" type="text" name="school_name" value="<?= val($profile['school_name'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">School Address</label>
                            <input class="f-input" type="text" name="school_address" value="<?= val($profile['school_address'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Work Experience (years)</label>
                            <input class="f-input" type="number" min="0" name="work_experience_years" value="<?= val($profile['work_experience_years'] ?? '') ?>">
                        </div>

                        <div class="f-group">
                            <label class="f-label">Last Employer</label>
                            <input class="f-input" type="text" name="last_employer" value="<?= val($profile['last_employer'] ?? '') ?>">
                        </div>

                        <div class="f-group full">
                            <label class="f-label">Last Position</label>
                            <input class="f-input" type="text" name="last_position" value="<?= val($profile['last_position'] ?? '') ?>">
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_profile" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Applicant Profile
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 3: CAREER & SALARY PREFERENCES-->
            <div class="card card-section fade-in d4" id="sec-preferences">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-emerald)"></span>
                            Career & Salary Preferences
                        </span>
                        <span class="card-subtitle">How you'd like to be matched with job orders</span>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-grid cols-3">

                        <div class="f-group">
                            <label class="f-label">Preferred Job Classification<span class="req">*</span></label>
                            <select class="f-select" name="preferred_classification" required>
                                <option value="">Select classification</option>
                                <?php
                                $current_classification = $preferences['preferred_classification'] ?? '';
                                $classification_list = $classification_options;
                                if ($current_classification !== '' && !in_array($current_classification, $classification_list, true)) {
                                    array_splice($classification_list, count($classification_list) - 1, 0, [$current_classification]);
                                }
                                foreach ($classification_list as $cl):
                                ?>
                                    <option value="<?= val($cl) ?>" <?= $current_classification === $cl ? 'selected' : '' ?>>
                                        <?= val($cl) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Preferred Employment Type<span class="req">*</span></label>
                            <select class="f-select" name="preferred_employment_type" required>
                                <?php foreach ($employment_type_options as $et): ?>
                                    <option value="<?= val($et) ?>" <?= ($preferences['preferred_employment_type'] ?? 'Full-time') === $et ? 'selected' : '' ?>>
                                        <?= val($et) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Expected Salary Range</label>
                            <select class="f-select" name="salary_range_id">
                                <option value="">— Not specified —</option>
                                <?php foreach ($salary_ranges as $sr): ?>
                                    <option value="<?= val($sr['id']) ?>" <?= (string)($preferences['salary_range_id'] ?? '') === (string)$sr['id'] ? 'selected' : '' ?>>
                                        <?= val($sr['range_label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($preferences['salary_range_label'])): ?>
                                <span class="f-hint">
                                    <i class="fas fa-circle-check" style="color:var(--settings-emerald)"></i>
                                    Currently selected: <strong><?= val($preferences['salary_range_label']) ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="f-group">
                            <label class="f-label">Salary Currency</label>
                            <input class="f-input" type="text" value="PHP (Philippine Peso)" disabled readonly
                                style="opacity:.75; cursor:not-allowed;">
                            <small style="color:var(--text-muted, #888); display:block; margin-top:4px;">
                                All applicant salary expectations are recorded in Philippine Peso.
                            </small>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_preferences" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Preferences
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECTION 4: PREFERRED COUNTRIES-->
            <div class="card card-section fade-in d5" id="sec-countries">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-amber)"></span>
                            Preferred Countries
                        </span>
                        <span class="card-subtitle">Rank the countries you'd like to work in, in order of priority</span>
                    </div>
                </div>

                <datalist id="countryList">
                    <?php foreach ($country_list as $cn): ?>
                        <option value="<?= val($cn) ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <form method="POST" action="profile.php" id="countriesForm">
                    <div class="dyn-list" id="countriesList">
                        <?php if (empty($preferred_countries)): ?>
                            <div class="dyn-empty" id="countriesEmpty">
                                <i class="fas fa-earth-asia"></i>
                                No preferred countries added yet. Click "Add Country" to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($preferred_countries as $i => $pc): ?>
                                <div class="dyn-row" data-row>
                                    <div class="f-group">
                                        <label class="f-label">Country</label>
                                        <input class="f-input" type="text" name="country[]" list="countryList" value="<?= val($pc['country']) ?>" placeholder="e.g. Taiwan" required>
                                    </div>
                                    <div class="dyn-row-rank">
                                        <label>Priority</label>
                                        <input class="f-input" type="number" min="1" name="country_priority[]" value="<?= val($pc['priority_order']) ?>">
                                    </div>
                                    <div class="dyn-row-remove-wrap">
                                        <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions" style="justify-content: space-between; margin-top: 14px;">
                        <button type="button" class="btn-ghost" id="addCountryBtn">
                            <i class="fas fa-plus"></i> Add Country
                        </button>
                        <button type="submit" name="save_countries" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Preferred Countries
                        </button>
                    </div>
                </form>
            </div>

            <!--SECTION 5: PREFERRED JOB POSITIONS-->
            <div class="card card-section fade-in d6" id="sec-positions">
                <div class="card-head">
                    <div class="card-title-group">
                        <span class="card-title">
                            <span class="ctdot" style="background:var(--settings-rose)"></span>
                            Preferred Job Positions
                        </span>
                        <span class="card-subtitle">Choose a category, then the specific role you're interested in</span>
                    </div>
                </div>

                <form method="POST" action="profile.php" id="positionsForm">
                    <div class="dyn-list" id="positionsList">
                        <?php if (empty($preferred_positions)): ?>
                            <div class="dyn-empty" id="positionsEmpty">
                                <i class="fas fa-briefcase"></i>
                                No preferred job positions added yet. Click "Add Position" to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($preferred_positions as $pp): ?>
                                <div class="dyn-row dyn-row-cascade" data-row>
                                    <div class="f-group">
                                        <label class="f-label">Job Category</label>
                                        <select class="f-select" data-category-select>
                                            <?php foreach ($job_categories as $jc): ?>
                                                <option value="<?= val($jc['id']) ?>" <?= (int)$pp['category_id'] === (int)$jc['id'] ? 'selected' : '' ?>>
                                                    <?= val($jc['category_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="f-group">
                                        <label class="f-label">Specific Position</label>
                                        <select class="f-select" name="subcategory_id[]" data-subcategory-select data-selected="<?= val($pp['subcategory_id']) ?>" required></select>
                                    </div>
                                    <div class="dyn-row-rank">
                                        <label>Priority</label>
                                        <input class="f-input" type="number" min="1" name="position_priority[]" value="<?= val($pp['priority_order']) ?>">
                                    </div>
                                    <div class="dyn-row-remove-wrap">
                                        <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions" style="justify-content: space-between; margin-top: 14px;">
                        <button type="button" class="btn-ghost" id="addPositionBtn">
                            <i class="fas fa-plus"></i> Add Position
                        </button>
                        <button type="submit" name="save_positions" class="btn-save">
                            <i class="fas fa-floppy-disk"></i> Save Preferred Positions
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div><!-- /main -->


    <!-- ══════════════════════════════════════════
         FLOATING DOCK
    ══════════════════════════════════════════ -->
    <div class="dock-wrap">
        <nav class="dock">

            <a href="index.php" class="dk">
                <i class="fas fa-house"></i>
                <span class="dk-lbl">Home</span>
            </a>

            <a href="jobs.php" class="dk ">
                <i class="fas fa-briefcase"></i>
                <span class="dk-lbl">Jobs</span>
            </a>

            <div class="dk-div"></div>

            <a href="my-applications.php" class="dk ">
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

            <a href="profile.php" class="dk active">
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
        /*  Live date + time  */
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
    </script>

    <script>
        // Subcategories grouped by category id, rendered server-side into JSON
        // so the "Preferred Job Positions" category/position dropdowns can
        // cascade without any extra AJAX endpoints.
        const SUBCATS_BY_CATEGORY = <?= json_encode($subcategories_by_category, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        //  Generic empty-state toggler 
        function toggleEmptyState(listEl, emptyEl) {
            if (!emptyEl) return;
            emptyEl.style.display = listEl.querySelectorAll('[data-row]').length ? 'none' : 'flex';
        }

        //  Remove-row wiring (event delegation) 
        function wireRemoveButtons(listEl, emptyEl) {
            listEl.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-remove-row]');
                if (!btn) return;
                const row = btn.closest('[data-row]');
                if (row) row.remove();
                toggleEmptyState(listEl, emptyEl);
            });
        }

        // PREFERRED COUNTRIES — add row
        const countriesList = document.getElementById('countriesList');
        const countriesEmpty = document.getElementById('countriesEmpty');
        wireRemoveButtons(countriesList, countriesEmpty);

        document.getElementById('addCountryBtn').addEventListener('click', function() {
            const row = document.createElement('div');
            row.className = 'dyn-row';
            row.setAttribute('data-row', '');
            const nextPriority = countriesList.querySelectorAll('[data-row]').length + 1;
            row.innerHTML = `
                <div class="f-group">
                    <label class="f-label">Country</label>
                    <input class="f-input" type="text" name="country[]" list="countryList" placeholder="e.g. Taiwan" required>
                </div>
                <div class="dyn-row-rank">
                    <label>Priority</label>
                    <input class="f-input" type="number" min="1" name="country_priority[]" value="${nextPriority}">
                </div>
                <div class="dyn-row-remove-wrap">
                    <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>
            `;
            countriesList.appendChild(row);
            toggleEmptyState(countriesList, countriesEmpty);
            row.querySelector('input[type=text]').focus();
        });

        // PREFERRED JOB POSITIONS — cascading dropdowns
        const positionsList = document.getElementById('positionsList');
        const positionsEmpty = document.getElementById('positionsEmpty');
        wireRemoveButtons(positionsList, positionsEmpty);

        function populateSubcategorySelect(categorySelect, subcategorySelect, selectedId) {
            const catId = categorySelect.value;
            const options = SUBCATS_BY_CATEGORY[catId] || [];
            subcategorySelect.innerHTML = '';
            options.forEach(function(opt) {
                const optionEl = document.createElement('option');
                optionEl.value = opt.id;
                optionEl.textContent = opt.name;
                if (selectedId && String(selectedId) === String(opt.id)) {
                    optionEl.selected = true;
                }
                subcategorySelect.appendChild(optionEl);
            });
        }

        function wireCascade(row, selectedSubcategoryId) {
            const categorySelect = row.querySelector('[data-category-select]');
            const subcategorySelect = row.querySelector('[data-subcategory-select]');
            populateSubcategorySelect(categorySelect, subcategorySelect, selectedSubcategoryId);
            categorySelect.addEventListener('change', function() {
                populateSubcategorySelect(categorySelect, subcategorySelect, null);
            });
        }

        // Initialize cascades for rows already rendered from the database
        positionsList.querySelectorAll('[data-row]').forEach(function(row) {
            const subcategorySelect = row.querySelector('[data-subcategory-select]');
            const preselected = subcategorySelect.getAttribute('data-selected');
            wireCascade(row, preselected);
        });

        document.getElementById('addPositionBtn').addEventListener('click', function() {
            const row = document.createElement('div');
            row.className = 'dyn-row dyn-row-cascade';
            row.setAttribute('data-row', '');
            const nextPriority = positionsList.querySelectorAll('[data-row]').length + 1;

            const categoryOptionsHtml = <?= json_encode(implode('', array_map(fn($jc) => '<option value="' . htmlspecialchars((string)$jc['id'], ENT_QUOTES) . '">' . htmlspecialchars($jc['category_name'], ENT_QUOTES) . '</option>', $job_categories))) ?>;

            row.innerHTML = `
                <div class="f-group">
                    <label class="f-label">Job Category</label>
                    <select class="f-select" data-category-select>${categoryOptionsHtml}</select>
                </div>
                <div class="f-group">
                    <label class="f-label">Specific Position</label>
                    <select class="f-select" name="subcategory_id[]" data-subcategory-select required></select>
                </div>
                <div class="dyn-row-rank">
                    <label>Priority</label>
                    <input class="f-input" type="number" min="1" name="position_priority[]" value="${nextPriority}">
                </div>
                <div class="dyn-row-remove-wrap">
                    <button type="button" class="btn-icon-remove" data-remove-row title="Remove">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>
            `;
            positionsList.appendChild(row);
            wireCascade(row, null);
            toggleEmptyState(positionsList, positionsEmpty);
        });

        // Require at least one non-empty row before submitting each dynamic form
        // (still allowed to save an intentionally empty list to clear it out).
        document.getElementById('countriesForm').addEventListener('submit', function() {
            countriesList.querySelectorAll('[data-row] input[type=text]').forEach(function(input) {
                if (input.value.trim() === '') {
                    input.closest('[data-row]').remove();
                }
            });
        });
        document.getElementById('pictureInput').addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                document.getElementById('pictureForm').submit();
            }
        });
    </script>

    <script type="module" src="../js/main.js"></script>

</body>

</html>