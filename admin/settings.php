<?php

/**
 * settings.php – DMW RO IV-A Admin Settings
 * Tabs: Profile, Security, Job Categories, Job Subcategories,
 *       Degree Programs, Professional Licenses, Salary Ranges, Skills
 */
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// CSRF token for all mutating requests on this page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper((substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1)));

// Dynamic badge count for the Apps dock item (matches dashboard.php)
$badge_stmt = $database->prepare("
    SELECT COUNT(*) as total
    FROM applications
    WHERE status IN ('pending', 'for_compliance', 'resubmitted')
");
$badge_stmt->execute();
$badge_result = $badge_stmt->get_result()->fetch_assoc();
$active_apps = $badge_result['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Settings – DMW RO IV-A Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <style>
        body {
            font-family: 'DM Sans', sans-serif;
        }

        .topbar-brand img {
            width: 42px;
            height: auto;
            display: block;
            background: transparent;
            filter: none;
        }

        .settings-shell {
            display: flex;
            gap: 20px;
            padding: 20px;
            padding-bottom: 110px;
            /* clears the floating dock, matches .main spacing in dashboard.php */
            max-width: 1280px;
            margin: 0 auto;
        }

        .settings-nav {
            width: 240px;
            flex-shrink: 0;
        }

        .settings-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: background .15s, color .15s;
            margin-bottom: 3px;
        }

        .settings-nav-item i {
            width: 16px;
            text-align: center;
            font-size: 12px;
        }

        .settings-nav-item:hover {
            background: #f1f5f9;
        }

        .settings-nav-item.active {
            background: #1d4ed8;
            color: #fff;
        }

        .settings-nav-group-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #94a3b8;
            margin: 16px 0 6px 14px;
        }

        .settings-panel {
            flex: 1;
            min-width: 0;
        }

        .s-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 16px;
        }

        .s-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 3px;
        }

        .s-card-sub {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 18px;
        }

        .s-field {
            margin-bottom: 14px;
        }

        .s-field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }

        .s-field input,
        .s-field select {
            width: 100%;
            font-size: 13px;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            outline: none;
            transition: border-color .15s;
        }

        .s-field input:focus,
        .s-field select:focus {
            border-color: #1d4ed8;
        }

        .s-field input[readonly] {
            background: #f8fafc;
            color: #64748b;
        }

        .s-hint {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .s-btn {
            font-size: 12px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: opacity .15s;
        }

        .s-btn:hover {
            opacity: .88;
        }

        .s-btn-primary {
            background: #1d4ed8;
            color: #fff;
        }

        .s-btn-danger {
            background: #dc2626;
            color: #fff;
        }

        .s-btn-outline {
            background: #fff;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .s-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table.s-table {
            width: 100%;
            min-width: 480px;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        table.s-table th {
            text-align: left;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
            padding: 8px 10px;
            border-bottom: 2px solid #f1f5f9;
        }

        table.s-table td {
            padding: 10px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        table.s-table tr:hover td {
            background: #f8fafc;
        }

        .s-badge {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .s-badge.ok {
            background: #dcfce7;
            color: #16a34a;
        }

        .s-badge.fail {
            background: #fee2e2;
            color: #dc2626;
        }

        .s-icon-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: #475569;
            margin-right: 4px;
        }

        .s-icon-btn:hover {
            background: #f1f5f9;
        }

        .s-icon-btn.danger:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        .s-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .s-search {
            font-size: 12.5px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            width: 220px;
        }

        .settings-tab-panel {
            display: none;
        }

        .settings-tab-panel.active {
            display: block;
        }

        .s-skeleton-row td {
            height: 38px;
        }

        /* Subbar breadcrumb pills — mirror the .filter-pill look, act as the
           tab switcher on mobile where the sidebar is hidden */
        .settings-crumb-pill {
            cursor: pointer;
            user-select: none;
            transition: background .15s, border-color .15s;
        }

        .settings-crumb-pill.active {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .settings-crumb-pill.active span,
        .settings-crumb-pill.active i {
            color: #fff !important;
        }

        /*  CHANGE: password visibility toggle + live requirements checklist styles
           (ported from create-account.php's .input-field / #eyeIcon / #password-requirements pattern,
           re-themed to match the s-field / s-hint styling already used on this page) */
        .s-pass-wrap {
            position: relative;
        }

        .s-pass-wrap input {
            padding-right: 38px;
        }

        .s-pass-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 12px;
            padding: 4px;
            line-height: 1;
        }

        .s-pass-toggle:hover {
            color: #475569;
        }

        .s-pass-requirements {
            margin-top: 8px;
            font-size: 11px;
            display: grid;
            gap: 3px;
        }

        .s-pass-requirements.hidden {
            display: none;
        }

        .s-pass-requirements .req-item {
            color: #94a3b8;
            transition: color .15s;
        }

        .s-pass-requirements .req-item.met {
            color: #16a34a;
        }

        /* ── Responsive breakpoints ─────────────────────────────────── */
        @media (max-width: 860px) {
            .settings-shell {
                flex-direction: column;
                padding: 14px;
                padding-bottom: 110px;
                gap: 14px;
            }

            /* Sidebar nav is redundant with the subbar pills on mobile */
            .settings-nav {
                display: none;
            }

            .s-card {
                padding: 16px;
            }

            .s-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .s-search {
                width: 100%;
            }

            .s-toolbar button[data-add] {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 640px) {
            .subbar-greeting h2 {
                font-size: 16px;
            }

            .subbar-greeting p {
                font-size: 11px;
            }

            .s-card {
                padding: 14px;
                border-radius: 12px;
            }

            .s-card-title {
                font-size: 14px;
            }

            table.s-table {
                font-size: 11.5px;
            }

            table.s-table th,
            table.s-table td {
                padding: 7px 8px;
            }

            .s-field input,
            .s-field select {
                font-size: 13px;
                padding: 8px 10px;
            }

            .s-btn {
                width: 100%;
                text-align: center;
            }

            #btn-logout-devices {
                width: 100%;
            }
        }

        @media (max-width: 420px) {
            .topbar-date {
                display: none;
            }

            /* s-table-wrap already scrolls horizontally at this width,
               which is safer than hiding columns since column count
               differs per lookup table */
        }
    </style>
</head>

<body>

    <!-- ── TOP BAR ──────────────────────────────────────────────────── -->
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
            <div class="tb-btn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!--  SUBBAR  -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2>Account &amp; System Settings</h2>
                <p id="subbar-section-label">Manage your profile, security, and shared reference data used across the system.</p>
            </div>
        </div>
        <div class="filter-scroll">
            <div class="filter-pill settings-crumb-pill active" data-tab="profile">
                <i class="fas fa-user" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Profile</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="security">
                <i class="fas fa-shield-halved" style="color:var(--ok,#10b981)"></i>
                <span style="font-weight:600;font-size:11px">Security</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="job_categories">
                <i class="fas fa-layer-group" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Job Categories</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="job_subcategories">
                <i class="fas fa-sitemap" style="color:var(--amber)"></i>
                <span style="font-weight:600;font-size:11px">Job Subcategories</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="degree_programs">
                <i class="fas fa-graduation-cap" style="color:var(--indigo)"></i>
                <span style="font-weight:600;font-size:11px">Degree Programs</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="professional_licenses">
                <i class="fas fa-id-card" style="color:var(--indigo)"></i>
                <span style="font-weight:600;font-size:11px">Licenses</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="salary_ranges">
                <i class="fas fa-sack-dollar" style="color:var(--gold)"></i>
                <span style="font-weight:600;font-size:11px">Salary Ranges</span>
            </div>
            <div class="filter-pill settings-crumb-pill" data-tab="skills">
                <i class="fas fa-screwdriver-wrench" style="color:var(--gold)"></i>
                <span style="font-weight:600;font-size:11px">Skills</span>
            </div>
        </div>
    </div>

    <!--  SETTINGS SHELL  -->
    <div class="settings-shell">

        <!-- Side nav (hidden on mobile — the subbar pills above act as the nav there) -->
        <nav class="settings-nav">
            <div class="s-card" style="padding:12px">
                <div class="settings-nav-group-label" style="margin-top:0">Account</div>
                <div class="settings-nav-item active" data-tab="profile"><i class="fas fa-user"></i> Profile</div>
                <div class="settings-nav-item" data-tab="security"><i class="fas fa-shield-halved"></i> Security</div>

                <div class="settings-nav-group-label">Reference Data</div>
                <div class="settings-nav-item" data-tab="job_categories"><i class="fas fa-layer-group"></i> Job Categories</div>
                <div class="settings-nav-item" data-tab="job_subcategories"><i class="fas fa-sitemap"></i> Job Subcategories</div>
                <div class="settings-nav-item" data-tab="degree_programs"><i class="fas fa-graduation-cap"></i> Degree Programs</div>
                <div class="settings-nav-item" data-tab="professional_licenses"><i class="fas fa-id-card"></i> Professional Licenses</div>
                <div class="settings-nav-item" data-tab="salary_ranges"><i class="fas fa-sack-dollar"></i> Salary Ranges</div>
                <div class="settings-nav-item" data-tab="skills"><i class="fas fa-screwdriver-wrench"></i> Skills</div>
            </div>
        </nav>

        <!-- Panels -->
        <div class="settings-panel">

            <!-- PROFILE -->
            <section class="settings-tab-panel active" id="panel-profile">
                <div class="s-card">
                    <div class="s-card-title">Profile</div>
                    <div class="s-card-sub">Update your personal account information.</div>
                    <form id="profile-form">
                        <div class="s-field">
                            <label>Full Name</label>
                            <input type="text" name="full_name" id="pf-full-name" maxlength="100" required>
                        </div>
                        <div class="s-field">
                            <label>Phone</label>
                            <input type="text" name="phone" id="pf-phone" maxlength="20">
                        </div>
                        <div class="s-field">
                            <label>Email Address</label>
                            <input type="email" name="email" id="pf-email" maxlength="100" required>
                            <div class="s-hint">Changing your email updates your login across the system. You'll need to log in again.</div>
                        </div>
                        <div class="s-field">
                            <label>Account Type</label>
                            <input type="text" id="pf-usertype" readonly>
                        </div>
                        <div class="s-field">
                            <label>Member Since</label>
                            <input type="text" id="pf-created" readonly>
                        </div>
                        <div class="s-field">
                            <label>Last Profile Update</label>
                            <input type="text" id="pf-updated" readonly>
                        </div>
                        <button type="submit" class="s-btn s-btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    </form>
                </div>
            </section>

            <!-- SECURITY -->
            <section class="settings-tab-panel" id="panel-security">
                <div class="s-card">
                    <div class="s-card-title">Change Password</div>
                    <div class="s-card-sub">Choose a strong password you don't use elsewhere.</div>
                    <form id="password-form">
                        <div class="s-field">
                            <label>Current Password</label>
                            <!--  CHANGE: wrapped in .s-pass-wrap + added eye-toggle button (same pattern as create-account.php) -->
                            <div class="s-pass-wrap">
                                <input type="password" name="current_password" id="sec-current-pass" required autocomplete="current-password">
                                <button type="button" class="s-pass-toggle" id="toggleCurrentPass" tabindex="-1">
                                    <i class="fas fa-eye" id="eyeCurrentPass"></i>
                                </button>
                            </div>
                        </div>
                        <div class="s-field">
                            <label>New Password</label>
                            <!--  CHANGE: wrapped in .s-pass-wrap + added eye-toggle button -->
                            <div class="s-pass-wrap">
                                <input type="password" name="new_password" id="sec-new-pass" minlength="8" required autocomplete="new-password">
                                <button type="button" class="s-pass-toggle" id="toggleNewPass" tabindex="-1">
                                    <i class="fas fa-eye" id="eyeNewPass"></i>
                                </button>
                            </div>
                            <div class="s-hint">Minimum 8 characters.</div>
                            <!--  CHANGE: added live password requirements checklist (same logic as create-account.php) -->
                            <div id="sec-password-requirements" class="s-pass-requirements hidden">
                                <div id="sec-req-length" class="req-item">• At least 8 characters</div>
                                <div id="sec-req-upper" class="req-item">• At least one uppercase letter</div>
                                <div id="sec-req-lower" class="req-item">• At least one lowercase letter</div>
                                <div id="sec-req-number" class="req-item">• At least one number</div>
                                <div id="sec-req-special" class="req-item">• At least one special character</div>
                            </div>
                        </div>
                        <div class="s-field">
                            <label>Confirm New Password</label>
                            <!--  CHANGE: wrapped in .s-pass-wrap + added eye-toggle button -->
                            <div class="s-pass-wrap">
                                <input type="password" name="confirm_password" id="sec-confirm-pass" minlength="8" required autocomplete="new-password">
                                <button type="button" class="s-pass-toggle" id="toggleConfirmPass" tabindex="-1">
                                    <i class="fas fa-eye" id="eyeConfirmPass"></i>
                                </button>
                            </div>
                            <!--  CHANGE: added live match indicator (same logic as create-account.php) -->
                            <div id="sec-password-match" class="s-hint" style="min-height:1.1em"></div>
                        </div>
                        <button type="submit" class="s-btn s-btn-primary"><i class="fas fa-key"></i> Update Password</button>
                    </form>
                </div>

                <div class="s-card">
                    <div class="s-card-title">Active Sessions</div>
                    <div class="s-card-sub">Sign out of this account on all other browsers/devices.</div>
                    <button class="s-btn s-btn-outline" id="btn-logout-devices"><i class="fas fa-right-from-bracket"></i> Log Out All Other Devices</button>
                </div>

                <div class="s-card">
                    <div class="s-card-title">Recent Login Activity</div>
                    <div class="s-card-sub">Last 15 login attempts on your account.</div>
                    <div class="s-table-wrap">
                        <table class="s-table">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>IP Address</th>
                                    <th>Device</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="login-activity-body">
                                <tr class="s-skeleton-row">
                                    <td colspan="4"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- GENERIC LOOKUP TABLE PANELS -->
            <?php
            $lookup_panels = [
                'job_categories'         => ['label' => 'Job Categories', 'desc' => 'Top-level job categories used to organize subcategories and positions.'],
                'job_subcategories'      => ['label' => 'Job Subcategories', 'desc' => 'Specific positions grouped under a job category.'],
                'degree_programs'        => ['label' => 'Degree Programs', 'desc' => 'Degree programs used in client education profiles.'],
                'professional_licenses'  => ['label' => 'Professional Licenses', 'desc' => 'PRC/TESDA/AMOSUP licenses clients can hold.'],
                'salary_ranges'          => ['label' => 'Salary Ranges', 'desc' => 'Predefined salary bands used in client preferences.'],
                'skills'                 => ['label' => 'Skills', 'desc' => 'Skills used in client profiles and job order requirements.'],
            ];
            foreach ($lookup_panels as $key => $meta):
            ?>
                <section class="settings-tab-panel" id="panel-<?= $key ?>">
                    <div class="s-card">
                        <div class="s-card-title"><?= htmlspecialchars($meta['label']) ?></div>
                        <div class="s-card-sub"><?= htmlspecialchars($meta['desc']) ?></div>
                        <div class="s-toolbar">
                            <input type="text" class="s-search" placeholder="Search..." data-lookup="<?= $key ?>">
                            <button class="s-btn s-btn-primary" data-add="<?= $key ?>"><i class="fas fa-plus"></i> Add New</button>
                        </div>
                        <div class="s-table-wrap">
                            <table class="s-table" id="table-<?= $key ?>">
                                <thead></thead>
                                <tbody>
                                    <tr class="s-skeleton-row">
                                        <td colspan="6"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

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
            <a href="job-orders.php" class="dk" data-tip="Job Orders">
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
            <a href="settings.php" class="dk active" data-tip="Settings">
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
        const CSRF = <?= json_encode($csrf_token) ?>;
        const SETTINGS_API = 'settings-api.php';
        const LOOKUPS_API = 'lookups-api.php';

        /* ── Live date + time (matches dashboard.php) ────────────────── */
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
        setInterval(updateLiveDateTime, 1000);

        /* ── Dock flyout (same as dashboard.php / applications.php) ──── */
        function toggleFlyout(e, id) {
            e.stopPropagation();
            const el = document.getElementById(id);
            const wasOpen = el.classList.contains('open');
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
            if (!wasOpen) el.classList.add('open');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.flyout').forEach(f => f.classList.remove('open'));
        });

        /* ── Tab switching (sidebar nav + subbar breadcrumb pills, kept in sync) ── */
        const navItems = document.querySelectorAll('.settings-nav-item');
        const crumbPills = document.querySelectorAll('.settings-crumb-pill');
        const panels = document.querySelectorAll('.settings-tab-panel');
        const loadedTabs = new Set();

        const SECTION_SUBTITLES = {
            profile: 'Manage your personal account information.',
            security: 'Change your password and review recent login activity.',
            job_categories: 'Top-level job categories used to organize subcategories and positions.',
            job_subcategories: 'Specific positions grouped under a job category.',
            degree_programs: 'Degree programs used in client education profiles.',
            professional_licenses: 'PRC/TESDA/AMOSUP licenses clients can hold.',
            salary_ranges: 'Predefined salary bands used in client preferences.',
            skills: 'Skills used in client profiles and job order requirements.',
        };

        function switchTab(tab) {
            navItems.forEach(i => i.classList.toggle('active', i.dataset.tab === tab));
            crumbPills.forEach(i => i.classList.toggle('active', i.dataset.tab === tab));
            panels.forEach(p => p.classList.remove('active'));
            document.getElementById('panel-' + tab).classList.add('active');
            const label = document.getElementById('subbar-section-label');
            if (label && SECTION_SUBTITLES[tab]) label.textContent = SECTION_SUBTITLES[tab];
            loadTab(tab);
        }

        navItems.forEach(item => item.addEventListener('click', () => switchTab(item.dataset.tab)));
        crumbPills.forEach(item => item.addEventListener('click', () => switchTab(item.dataset.tab)));

        function loadTab(tab) {
            if (tab === 'profile') return loadProfile();
            if (tab === 'security') return loadSecurity();
            if (LOOKUP_TABLES[tab]) return loadLookupTable(tab);
        }

        /* ── Helpers ───────────────────────────────────────────────── */
        function post(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ...body,
                    csrf_token: CSRF
                })
            }).then(r => r.json());
        }

        function toast(icon, title) {
            Swal.fire({
                icon,
                title,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2500
            });
        }

        /* ── PROFILE ───────────────────────────────────────────────── */
        function loadProfile() {
            if (loadedTabs.has('profile')) return;
            fetch(SETTINGS_API + '?action=get_profile').then(r => r.json()).then(d => {
                if (!d.success) return toast('error', d.message || 'Failed to load profile');
                document.getElementById('pf-full-name').value = d.data.full_name || '';
                document.getElementById('pf-phone').value = d.data.phone || '';
                document.getElementById('pf-email').value = d.data.email || '';
                document.getElementById('pf-usertype').value = (d.data.usertype || '').toUpperCase();
                document.getElementById('pf-created').value = d.data.created_at || '—';
                document.getElementById('pf-updated').value = d.data.updated_at || '—';
                loadedTabs.add('profile');
            });
        }

        document.getElementById('profile-form').addEventListener('submit', e => {
            e.preventDefault();
            const payload = {
                action: 'update_profile',
                full_name: document.getElementById('pf-full-name').value.trim(),
                phone: document.getElementById('pf-phone').value.trim(),
                email: document.getElementById('pf-email').value.trim()
            };
            post(SETTINGS_API, payload).then(d => {
                if (d.success) {
                    toast('success', d.email_changed ? 'Profile updated. Please log in again.' : 'Profile updated');
                    if (d.email_changed) setTimeout(() => window.location.href = '../login.php', 1800);
                    else {
                        document.getElementById('pf-updated').value = d.updated_at;
                    }
                } else {
                    toast('error', d.message || 'Update failed');
                }
            });
        });

        /* ── SECURITY ──────────────────────────────────────────────── */
        function loadSecurity() {
            fetch(SETTINGS_API + '?action=login_activity').then(r => r.json()).then(d => {
                const body = document.getElementById('login-activity-body');
                body.innerHTML = '';
                if (!d.success || !d.data.length) {
                    body.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:16px">No login history found</td></tr>';
                    return;
                }
                d.data.forEach(row => {
                    const badge = row.success == 1 ?
                        '<span class="s-badge ok">Success</span>' :
                        '<span class="s-badge fail">Failed</span>';
                    body.innerHTML += `<tr>
                        <td>${row.attempted_at}</td>
                        <td>${row.ip_address}</td>
                        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${row.user_agent || '—'}</td>
                        <td>${badge}</td>
                    </tr>`;
                });
            });
        }

        document.getElementById('password-form').addEventListener('submit', e => {
            e.preventDefault();
            const f = e.target;
            const newPass = f.new_password.value,
                confirmPass = f.confirm_password.value;
            if (newPass !== confirmPass) return toast('error', 'New passwords do not match');
            post(SETTINGS_API, {
                action: 'change_password',
                current_password: f.current_password.value,
                new_password: newPass
            }).then(d => {
                if (d.success) {
                    toast('success', 'Password updated');
                    f.reset();
                    //  CHANGE: reset the live checklist/match UI state after a successful update
                    document.getElementById('sec-password-requirements').classList.add('hidden');
                    document.getElementById('sec-password-match').textContent = '';
                } else toast('error', d.message || 'Failed to update password');
            });
        });

        //  CHANGE: Password visibility toggle + live requirements checklist for the
        // Security tab's Change Password form (same logic/behavior as create-account.php)
        (function() {
            function makeToggle(btnId, iconId, inputId) {
                const btn = document.getElementById(btnId);
                if (!btn) return;
                btn.addEventListener('click', function() {
                    const inp = document.getElementById(inputId);
                    const icon = document.getElementById(iconId);
                    if (inp.type === 'password') {
                        inp.type = 'text';
                        icon.classList.replace('fa-eye', 'fa-eye-slash');
                    } else {
                        inp.type = 'password';
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                });
            }
            makeToggle('toggleCurrentPass', 'eyeCurrentPass', 'sec-current-pass');
            makeToggle('toggleNewPass', 'eyeNewPass', 'sec-new-pass');
            makeToggle('toggleConfirmPass', 'eyeConfirmPass', 'sec-confirm-pass');

            const pwdInput = document.getElementById('sec-new-pass');
            const confirmInput = document.getElementById('sec-confirm-pass');
            const requirementsBox = document.getElementById('sec-password-requirements');
            const matchMsg = document.getElementById('sec-password-match');

            if (!pwdInput || !confirmInput) return;

            const reqElements = {
                length: document.getElementById('sec-req-length'),
                upper: document.getElementById('sec-req-upper'),
                lower: document.getElementById('sec-req-lower'),
                number: document.getElementById('sec-req-number'),
                special: document.getElementById('sec-req-special')
            };

            function updateRequirements(value) {
                const checks = {
                    length: value.length >= 8,
                    upper: /[A-Z]/.test(value),
                    lower: /[a-z]/.test(value),
                    number: /[0-9]/.test(value),
                    special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?`~]/.test(value)
                };
                for (const [key, ok] of Object.entries(checks)) {
                    reqElements[key].classList.toggle('met', ok);
                }
                requirementsBox.classList.remove('hidden');
            }

            function checkMatch() {
                if (!confirmInput.value) {
                    matchMsg.textContent = '';
                    return;
                }
                matchMsg.innerHTML = pwdInput.value === confirmInput.value ?
                    '<span style="color:#16a34a">✓ Passwords match</span>' :
                    '<span style="color:#dc2626">✗ Passwords do not match</span>';
            }

            pwdInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));
            confirmInput.addEventListener('focus', () => requirementsBox.classList.remove('hidden'));

            [pwdInput, confirmInput].forEach(input => {
                input.addEventListener('blur', () => {
                    setTimeout(() => {
                        if (document.activeElement !== pwdInput && document.activeElement !== confirmInput) {
                            requirementsBox.classList.add('hidden');
                        }
                    }, 100);
                });
            });

            pwdInput.addEventListener('input', () => {
                updateRequirements(pwdInput.value);
                checkMatch();
            });
            confirmInput.addEventListener('input', checkMatch);
        })();

        document.getElementById('btn-logout-devices').addEventListener('click', () => {
            Swal.fire({
                title: 'Log out all other devices?',
                text: 'You will remain logged in here, but "Remember Me" sessions elsewhere will be cleared.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Log Out Others'
            }).then(res => {
                if (!res.isConfirmed) return;
                post(SETTINGS_API, {
                    action: 'logout_all_devices'
                }).then(d => {
                    if (d.success) toast('success', 'Other sessions logged out');
                    else toast('error', d.message || 'Failed');
                });
            });
        });

        /* ── LOOKUP TABLES (generic CRUD) ─────────────────────────── */
        const LOOKUP_TABLES = {
            job_categories: {
                cols: [{
                    key: 'category_name',
                    label: 'Category Name',
                    type: 'text',
                    required: true
                }],
                extraCols: [{
                    label: 'Subcategories',
                    render: r => r.dependent_count ?? 0
                }]
            },
            job_subcategories: {
                cols: [{
                        key: 'category_id',
                        label: 'Parent Category',
                        type: 'select',
                        source: 'job_categories',
                        optionValue: 'id',
                        optionLabel: 'category_name',
                        required: true
                    },
                    {
                        key: 'subcategory_name',
                        label: 'Subcategory Name',
                        type: 'text',
                        required: true
                    }
                ],
                extraCols: [{
                    label: 'Category',
                    render: r => r.category_name ?? '—'
                }]
            },
            degree_programs: {
                cols: [{
                        key: 'degree_name',
                        label: 'Degree Name',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'degree_category',
                        label: 'Category',
                        type: 'text',
                        required: false
                    }
                ]
            },
            professional_licenses: {
                cols: [{
                        key: 'license_name',
                        label: 'License Name',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'issuing_body',
                        label: 'Issuing Body',
                        type: 'text',
                        required: false
                    }
                ]
            },
            salary_ranges: {
                cols: [{
                        key: 'range_label',
                        label: 'Range Label',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'min_salary',
                        label: 'Min Salary',
                        type: 'number',
                        required: true
                    },
                    {
                        key: 'max_salary',
                        label: 'Max Salary',
                        type: 'number',
                        required: true
                    },
                    {
                        key: 'currency',
                        label: 'Currency',
                        type: 'text',
                        required: false
                    },
                    {
                        key: 'sort_order',
                        label: 'Sort Order',
                        type: 'number',
                        required: false
                    }
                ]
            },
            skills: {
                cols: [{
                        key: 'skill_name',
                        label: 'Skill Name',
                        type: 'text',
                        required: true
                    },
                    {
                        key: 'skill_category',
                        label: 'Category',
                        type: 'text',
                        required: false
                    }
                ]
            }
        };

        const lookupCache = {}; // table -> rows, used for dropdown sources

        function loadLookupTable(table, search = '') {
            const cfg = LOOKUP_TABLES[table];
            const thead = document.querySelector(`#table-${table} thead`);
            const tbody = document.querySelector(`#table-${table} tbody`);

            // build header once
            if (!thead.innerHTML) {
                let h = '<tr>';
                cfg.cols.forEach(c => h += `<th>${c.label}</th>`);
                (cfg.extraCols || []).forEach(c => h += `<th>${c.label}</th>`);
                h += '<th style="width:90px">Actions</th></tr>';
                thead.innerHTML = h;
            }

            fetch(`${LOOKUPS_API}?action=list&table=${table}&q=${encodeURIComponent(search)}`)
                .then(r => r.json())
                .then(d => {
                    if (!d.success) {
                        tbody.innerHTML = `<tr><td colspan="10">${d.message}</td></tr>`;
                        return;
                    }
                    lookupCache[table] = d.data;
                    tbody.innerHTML = '';
                    if (!d.data.length) {
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:16px">No records found</td></tr>`;
                        return;
                    }
                    d.data.forEach(row => {
                        let rowHtml = '<tr>';
                        cfg.cols.forEach(c => {
                            let val = row[c.key] ?? '';
                            if (c.type === 'select' && c.key === 'category_id') val = row.category_name ?? val;
                            rowHtml += `<td>${val}</td>`;
                        });
                        (cfg.extraCols || []).forEach(c => rowHtml += `<td>${c.render(row)}</td>`);
                        rowHtml += `<td>
                            <button class="s-icon-btn" data-edit="${table}" data-id="${row.id}"><i class="fas fa-pen"></i></button>
                            <button class="s-icon-btn danger" data-del="${table}" data-id="${row.id}"><i class="fas fa-trash"></i></button>
                        </td></tr>`;
                        tbody.insertAdjacentHTML('beforeend', rowHtml);
                    });
                });
        }

        // search debounce per table
        document.querySelectorAll('.s-search').forEach(input => {
            let timer;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => loadLookupTable(input.dataset.lookup, input.value.trim()), 350);
            });
        });

        // Add button
        document.querySelectorAll('[data-add]').forEach(btn => {
            btn.addEventListener('click', () => openLookupModal(btn.dataset.add, null));
        });

        // Delegated edit/delete
        document.getElementById('page-root') || document.body.addEventListener('click', e => {
            const editBtn = e.target.closest('[data-edit]');
            const delBtn = e.target.closest('[data-del]');
            if (editBtn) {
                const table = editBtn.dataset.edit,
                    id = editBtn.dataset.id;
                const row = (lookupCache[table] || []).find(r => String(r.id) === String(id));
                openLookupModal(table, row);
            }
            if (delBtn) {
                confirmDeleteLookup(delBtn.dataset.del, delBtn.dataset.id);
            }
        });

        async function openLookupModal(table, row) {
            const cfg = LOOKUP_TABLES[table];
            const isEdit = !!row;

            // preload select sources if needed
            for (const c of cfg.cols) {
                if (c.type === 'select' && !lookupCache[c.source]) {
                    const r = await fetch(`${LOOKUPS_API}?action=list&table=${c.source}`).then(x => x.json());
                    lookupCache[c.source] = r.data || [];
                }
            }

            let html = '<div style="text-align:left">';
            cfg.cols.forEach(c => {
                const val = row ? (row[c.key] ?? '') : '';
                if (c.type === 'select') {
                    let opts = (lookupCache[c.source] || []).map(o =>
                        `<option value="${o[c.optionValue]}" ${String(o[c.optionValue]) === String(val) ? 'selected' : ''}>${o[c.optionLabel]}</option>`
                    ).join('');
                    html += `<label style="font-size:12px;font-weight:600;display:block;margin:10px 0 4px">${c.label}</label>
                        <select id="modal-${c.key}" class="swal2-select" style="width:100%;display:block">${opts}</select>`;
                } else {
                    html += `<label style="font-size:12px;font-weight:600;display:block;margin:10px 0 4px">${c.label}</label>
                        <input id="modal-${c.key}" type="${c.type}" class="swal2-input" style="width:100%;margin:0" value="${val}">`;
                }
            });
            html += '</div>';

            const res = await Swal.fire({
                title: isEdit ? 'Edit Record' : 'Add New Record',
                html,
                showCancelButton: true,
                confirmButtonText: isEdit ? 'Save Changes' : 'Add',
                preConfirm: () => {
                    const payload = {};
                    for (const c of cfg.cols) {
                        const el = document.getElementById(`modal-${c.key}`);
                        const v = el.value.trim();
                        if (c.required && !v) {
                            Swal.showValidationMessage(`${c.label} is required`);
                            return false;
                        }
                        payload[c.key] = v;
                    }
                    return payload;
                }
            });

            if (!res.isConfirmed) return;

            const action = isEdit ? 'update' : 'create';
            const body = {
                action,
                table,
                data: res.value
            };
            if (isEdit) body.id = row.id;

            post(LOOKUPS_API, body).then(d => {
                if (d.success) {
                    toast('success', isEdit ? 'Record updated' : 'Record added');
                    loadLookupTable(table);
                } else {
                    Swal.fire('Error', d.message || 'Operation failed', 'error');
                }
            });
        }

        function confirmDeleteLookup(table, id) {
            // Step 1: check dependents
            fetch(`${LOOKUPS_API}?action=check_delete&table=${table}&id=${id}`)
                .then(r => r.json())
                .then(check => {
                    if (!check.success) return toast('error', check.message || 'Check failed');

                    if (check.blocked) {
                        Swal.fire('Cannot Delete', check.message, 'error');
                        return;
                    }

                    const warnText = check.warning ? check.warning : 'This action cannot be undone.';
                    Swal.fire({
                        title: 'Delete this record?',
                        text: warnText,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: 'Delete'
                    }).then(res => {
                        if (!res.isConfirmed) return;
                        post(LOOKUPS_API, {
                            action: 'delete',
                            table,
                            id
                        }).then(d => {
                            if (d.success) {
                                toast('success', 'Record deleted');
                                loadLookupTable(table);
                            } else Swal.fire('Error', d.message || 'Delete failed', 'error');
                        });
                    });
                });
        }

        /* Init */
        loadProfile();
    </script>

    <script type="module" src="../js/main.js"></script>
    <?php include '../includes/login_toast.php'; ?>
</body>

</html>