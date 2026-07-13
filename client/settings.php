<?php
// client/profile.php – DMW Client Profile / Settings
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

// ────────────────────────────────────────────────
// Get client id
// ────────────────────────────────────────────────
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

// ────────────────────────────────────────────────
// Handle form submissions
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Section 1: Personal Information → clients table ──
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
                $dob,
                $gender,
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

    // ── Section 2: OFW Profile → client_profiles table ──
    if (isset($_POST['save_profile'])) {

        $citizenship  = trim($_POST['citizenship'] ?? '');
        $civil_status = trim($_POST['civil_status'] ?? '');
        $religion     = trim($_POST['religion'] ?? '');
        $height_cm    = trim($_POST['height_cm'] ?? '');
        $weight_kg    = trim($_POST['weight_kg'] ?? '');
        $education    = trim($_POST['educational_attainment'] ?? '');
        $course       = trim($_POST['course_degree'] ?? '');
        $school_name  = trim($_POST['school_name'] ?? '');
        $school_addr  = trim($_POST['school_address'] ?? '');
        $year_grad    = trim($_POST['year_graduated'] ?? '');
        $work_years   = trim($_POST['work_experience_years'] ?? '');
        $last_employer = trim($_POST['last_employer'] ?? '');
        $last_position = trim($_POST['last_position'] ?? '');

        // Normalize empty strings to NULL for nullable numeric/enum/year fields
        $height_cm  = $height_cm === '' ? null : (float)$height_cm;
        $weight_kg  = $weight_kg === '' ? null : (float)$weight_kg;
        $year_grad  = $year_grad === '' ? null : (int)$year_grad;
        $work_years = $work_years === '' ? null : (int)$work_years;
        $civil_status = $civil_status === '' ? null : $civil_status;
        $education    = $education === '' ? null : $education;

        if (empty($errors)) {
            // client_id has a UNIQUE KEY, so this inserts a new profile row
            // the first time, or updates the existing one on subsequent saves.
            $stmt = $database->prepare("
                INSERT INTO client_profiles (
                    client_id, citizenship, civil_status, religion,
                    height_cm, weight_kg, educational_attainment, course_degree,
                    school_name, school_address, year_graduated,
                    work_experience_years, last_employer, last_position,
                    profile_completed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    citizenship = VALUES(citizenship),
                    civil_status = VALUES(civil_status),
                    religion = VALUES(religion),
                    height_cm = VALUES(height_cm),
                    weight_kg = VALUES(weight_kg),
                    educational_attainment = VALUES(educational_attainment),
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
                "isssddssssiiss",
                $client_id,
                $citizenship,
                $civil_status,
                $religion,
                $height_cm,
                $weight_kg,
                $education,
                $course,
                $school_name,
                $school_addr,
                $year_grad,
                $work_years,
                $last_employer,
                $last_position
            );

            if ($stmt->execute()) {
                $success_msg = 'Profile information updated successfully.';
            } else {
                $errors[] = 'Something went wrong while saving your profile information. Please try again.';
            }
            $stmt->close();
        }
    }
}

// ────────────────────────────────────────────────
// Fetch current data (after any save, so the form reflects latest)
// ────────────────────────────────────────────────
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

// Greeting / avatar (same pattern as evaluator pages)
$full_name  = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
$first_name = $client['first_name'] ?? 'Client';

$parts    = explode(' ', $full_name);
$initials = count($parts) >= 2
    ? strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1))
    : strtoupper(substr($full_name ?: 'C', 0, 2));

// Helper to safely echo a value into an input
function val($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
}

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Profile Settings – DMW Client Portal</title>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Serif+Display&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="../css/dashboard.css">

    <style>
        /* ── Page-specific additions that extend dashboard.css ── */

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-top: 4px;
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
        .f-select {
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

        .f-input:focus,
        .f-select:focus {
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

        .alert-box {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 12.5px;
            font-weight: 600;
            margin-bottom: 16px;
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

        .profile-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }

        .profile-avatar-lg {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: #eef2ff;
            color: var(--indigo);
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .profile-header h3 {
            font-size: 15px;
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

        .card-section {
            margin-bottom: 18px;
        }

        @media (max-width: 640px) {
            .form-grid {
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
    </style>
</head>

<body>

    <!-- ══════════════════════════════════════════
         TOPBAR
    ══════════════════════════════════════════ -->
    <header class="topbar">
        <div class="topbar-brand">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div class="brand-divider"></div>
            <div class="brand-text">
                <h1>Department of Migrant Workers</h1>
                <p>REGIONAL OFFICE IV-A &nbsp;·&nbsp; CLIENT PORTAL</p>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-date" id="live-date"></span>
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar"><?= val($initials) ?></div>
        </div>
    </header>

    <!-- ══════════════════════════════════════════
         SUBBAR
    ══════════════════════════════════════════ -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Profile Settings</h2>
                <p>Manage your personal and applicant information</p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ -->
    <div class="main">

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

        <div class="profile-header fade-in d1">
            <div class="profile-avatar-lg"><?= val($initials) ?></div>
            <div>
                <h3><?= val($full_name) ?></h3>
                <p><?= val($client['email'] ?? '') ?></p>
            </div>
        </div>

        <!-- ────────────────────────────────────
             SECTION 1: PERSONAL INFORMATION
        ──────────────────────────────────── -->
        <div class="card card-section fade-in d2">
            <div class="card-head">
                <span class="card-title">
                    <span class="ctdot" style="background:var(--blue-lt)"></span>
                    Personal Information
                </span>
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

        <!-- ────────────────────────────────────
             SECTION 2: OFW PROFILE
        ──────────────────────────────────── -->
        <div class="card card-section fade-in d3">
            <div class="card-head">
                <span class="card-title">
                    <span class="ctdot" style="background:var(--indigo)"></span>
                    Applicant Profile
                </span>
            </div>

            <form method="POST" action="profile.php">
                <div class="form-grid">

                    <div class="f-group">
                        <label class="f-label">Citizenship</label>
                        <input class="f-input" type="text" name="citizenship" value="<?= val($profile['citizenship'] ?? '') ?>" placeholder="e.g. Filipino">
                    </div>

                    <div class="f-group">
                        <label class="f-label">Civil Status</label>
                        <select class="f-select" name="civil_status">
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
                        <label class="f-label">Educational Attainment</label>
                        <select class="f-select" name="educational_attainment">
                            <option value="">Select educational attainment</option>
                            <?php foreach ($education_options as $ed): ?>
                                <option value="<?= val($ed) ?>" <?= ($profile['educational_attainment'] ?? '') === $ed ? 'selected' : '' ?>>
                                    <?= val($ed) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="f-group">
                        <label class="f-label">Course / Degree</label>
                        <input class="f-input" type="text" name="course_degree" value="<?= val($profile['course_degree'] ?? '') ?>">
                    </div>

                    <div class="f-group">
                        <label class="f-label">Year Graduated</label>
                        <input class="f-input" type="number" min="1950" max="2100" name="year_graduated" value="<?= val($profile['year_graduated'] ?? '') ?>">
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
                        <i class="fas fa-floppy-disk"></i> Save Profile Info
                    </button>
                </div>
            </form>
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

            <a href="applications.php" class="dk">
                <i class="fas fa-list"></i>
                <span class="dk-lbl">Applications</span>
            </a>

            <div class="dk-div"></div>

            <a href="profile.php" class="dk active">
                <i class="fas fa-user-gear"></i>
                <span class="dk-lbl">Profile</span>
            </a>

            <a href="notifications.php" class="dk">
                <i class="fas fa-bell"></i>
                <span class="dk-lbl">Alerts</span>
            </a>

            <div class="dk-div"></div>

            <div class="dk" onclick="logout('Applicant')">
                <i class="fas fa-arrow-right-from-bracket" style="color:#f87171"></i>
                <span class="dk-lbl" style="color:#f87171">Logout</span>
            </div>

        </nav>
    </div>

    <script>
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();
    </script>
    <script type="module" src="../js/main.js"></script>

</body>

</html>