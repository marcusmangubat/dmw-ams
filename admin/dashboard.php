<?php

/**
 * dashboard.php  –  DMW RO IV-A Admin Dashboard
 * Matches project structure from applications.php
 */
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// Admin name from session or DB
$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

// Avatar initials
$parts    = explode(' ', trim($admin_name));
$initials = strtoupper(
    (substr($parts[0] ?? '', 0, 1)) . (substr($parts[count($parts) - 1] ?? '', 0, 1))
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <style>
        /* Skeleton loaders */
        .skeleton {
            background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.4s infinite;
            border-radius: 6px;
        }

        @keyframes shimmer {
            0% {
                background-position: 200% 0
            }

            100% {
                background-position: -200% 0
            }
        }

        .kpi-value.loading {
            height: 36px;
            width: 80px;
            display: inline-block;
        }

        .kpi-sub.loading {
            height: 14px;
            width: 120px;
            display: inline-block;
            margin-top: 4px;
        }

        /* Notification dropdown */
        #notif-panel {
            position: fixed;
            top: 56px;
            right: 12px;
            width: 340px;
            max-height: 480px;
            overflow-y: auto;
            background: var(--surface, #fff);
            border: 1px solid var(--bdr, #e2e8f0);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .14);
            z-index: 999;
            display: none;
        }

        #notif-panel.open {
            display: block;
        }

        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--bdr, #f1f5f9);
            display: flex;
            gap: 10px;
            align-items: flex-start;
            cursor: pointer;
            transition: background .15s;
        }

        .notif-item:hover {
            background: var(--surface2, #f8fafc);
        }

        .notif-item.unread {
            background: rgba(29, 78, 216, .04);
        }

        .notif-dot-sm {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #3b82f6;
            margin-top: 4px;
            flex-shrink: 0;
        }

        .notif-dot-sm.read {
            background: transparent;
            border: 1.5px solid #cbd5e1;
        }

        .notif-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text1, #0f172a);
        }

        .notif-msg {
            font-size: 11px;
            color: var(--text2, #475569);
            margin-top: 2px;
        }

        .notif-time {
            font-size: 10px;
            color: var(--text3, #94a3b8);
            margin-top: 3px;
        }

        #job-list {
            max-height: 220px;
            /* fits ~3 job-item cards */
            overflow-y: auto;
            padding-right: 4px;
            /* room for scrollbar so content doesn't shift */
        }

        #job-list::-webkit-scrollbar {
            width: 5px;
        }

        #job-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        #job-list::-webkit-scrollbar-track {
            background: transparent;
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

            <!-- Notification bell -->
            <div class="tb-btn" id="notif-btn" onclick="toggleNotifPanel(event)" style="position:relative">
                <i class="fas fa-bell"></i>
                <span class="notif-dot" id="notif-badge" style="display:none"></span>
            </div>

            <div class="tb-btn"><i class="fas fa-magnifying-glass"></i></div>
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- Notification dropdown panel 
    <div id="notif-panel">
        <div style="padding:12px 16px;border-bottom:1px solid var(--bdr,#e2e8f0);display:flex;align-items:center;justify-content:space-between;">
            <span style="font-weight:700;font-size:13px">Notifications</span>
            <button onclick="markAllRead()" style="font-size:11px;color:#3b82f6;background:none;border:none;cursor:pointer">Mark all read</button>
        </div>
        <div id="notif-list">
            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px">Loading…</div>
        </div>
    </div>-->

    <!-- ── SUBBAR ────────────────────────────────────────────────────── -->
    <div class="subbar">
        <div class="subbar-top">
            <div class="subbar-greeting">
                <h2><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($admin_name) ?></h2>
                <p>Current status of online applications</p>
            </div>
        </div>
        <div class="filter-scroll">
            <label class="filter-pill">
                <i class="fas fa-calendar-alt" style="color:var(--blue-lt)"></i>
                <select id="filter-month">
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        $val = date('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                        $lbl = date('M Y', mktime(0, 0, 0, $m, 1, (int)date('Y')));
                        $sel = ($m === (int)date('m')) ? 'selected' : '';
                        echo "<option value=\"$val\" $sel>$lbl</option>\n";
                    }
                    ?>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-map-marker-alt" style="color:var(--ok,#10b981);font-size:10px"></i>
                <select class="bg-transparent border-none outline-none font-semibold text-xs text-inherit cursor-pointer" id="filter-province">
                    <option value="">CALABARZON</option>
                    <option value="Laguna">Laguna</option>
                    <option value="Batangas">Batangas</option>
                    <option value="Cavite">Cavite</option>
                    <option value="Rizal">Rizal</option>
                    <option value="Quezon">Quezon</option>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-briefcase" style="color:var(--amber)"></i>
                <select id="filter-job">
                    <option value="">All Job Types</option>
                </select>
            </label>
            <label class="filter-pill">
                <i class="fas fa-globe" style="color:var(--indigo)"></i>
                <select id="filter-country">
                    <option value="">All Destinations</option>
                </select>
            </label>
            <button type="button" class="filter-pill" id="print-export-btn" onclick="openReport()" style="cursor:pointer;border:none">
                <i class="fas fa-print" style="color:var(--blue-lt)"></i>
                <span style="font-weight:600;font-size:11px">Print / Export PDF</span>
            </button>
        </div>
    </div>

    <!-- ── MAIN ──────────────────────────────────────────────────────── -->
    <div class="main">

        <!-- KPI Cards -->
        <div class="z-stats">
            <div class="kpi-card c-blue fade-in d1">
                <div class="kpi-top">
                    <div class="kpi-label">Total Apps</div>
                    <div class="kpi-icon c-blue"><i class="fas fa-folder-open"></i></div>
                </div>
                <div class="kpi-value c-blue skeleton loading" id="kpi-total"></div>
                <div class="kpi-sub skeleton loading" id="kpi-total-sub"></div>
            </div>

            <div class="kpi-card c-amber fade-in d2">
                <div class="kpi-top">
                    <div class="kpi-label">Pending</div>
                    <div class="kpi-icon c-amber"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="kpi-value c-amber skeleton loading" id="kpi-pending"></div>
                <div class="kpi-sub skeleton loading" id="kpi-pending-sub"></div>
            </div>

            <div class="kpi-card c-indigo fade-in d3">
                <div class="kpi-top">
                    <div class="kpi-label">Under Eval.</div>
                    <div class="kpi-icon c-indigo"><i class="fas fa-magnifying-glass"></i></div>
                </div>
                <div class="kpi-value c-indigo skeleton loading" id="kpi-eval"></div>
                <div class="kpi-sub skeleton loading" id="kpi-eval-sub"></div>
            </div>

            <div class="kpi-card c-green fade-in d4">
                <div class="kpi-top">
                    <div class="kpi-label">Approved</div>
                    <div class="kpi-icon c-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="kpi-value c-green skeleton loading" id="kpi-approved"></div>
                <div class="kpi-sub skeleton loading" id="kpi-approved-sub"></div>
            </div>
        </div>

        <!-- Monthly chart + Status + Job Orders -->
        <div class="z-mid fade-in d4">

            <div class="card">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--blue-lt)"></span>
                        Monthly Application Volume
                    </div>
                    <a href="applications.php" class="card-link">
                        All <i class="fas fa-arrow-right" style="font-size:9px"></i>
                    </a>
                </div>
                <canvas id="chartMonthly"></canvas>
            </div>

            <div style="display:flex;flex-direction:column;gap:12px">

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--indigo)"></span>
                            Status Breakdown
                        </div>
                        <a href="applications.php" class="card-link" style="font-size:10px">View all</a>
                    </div>
                    <div id="status-list">
                        <div class="skeleton" style="height:12px;margin:6px 0;width:100%"></div>
                        <div class="skeleton" style="height:12px;margin:6px 0;width:85%"></div>
                        <div class="skeleton" style="height:12px;margin:6px 0;width:70%"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <span class="ctdot" style="background:var(--gold)"></span>
                            Active Job Orders
                        </div>
                        <a href="job-orders.php" class="card-link">
                            RSF <i class="fas fa-arrow-right" style="font-size:9px"></i>
                        </a>
                    </div>
                    <div id="job-list">
                        <div class="skeleton" style="height:52px;margin:6px 0;border-radius:8px"></div>
                        <div class="skeleton" style="height:52px;margin:6px 0;border-radius:8px"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Destination Doughnut -->
        <div class="z-bottom fade-in d5">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">
                        <span class="ctdot" style="background:var(--amber)"></span>
                        Destination Countries
                    </div>
                </div>
                <div class="pie-wrap">
                    <div class="pie-canvas-wrap">
                        <canvas id="chartPie" width="160" height="160"></canvas>
                    </div>
                    <div id="pie-legend" style="flex:1"></div>
                </div>
            </div>
        </div>

    </div><!-- /main -->


    <!-- FLOATING DOCK -->
    <div class="dock-wrap">
        <nav class="dock">
            <a href="dashboard.php" class="dk active" data-tip="Dashboard">
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
            <a href="user.php" class="dk" data-tip="Evaluators">
                <i class="fas fa-users-gear"></i>
                <span class="dk-lbl">Evaluators</span>
            </a>
            <a href="assignments.html" class="dk" data-tip="Assignments">
                <i class="fas fa-list-check"></i>
                <span class="dk-lbl">Assign</span>
            </a>
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


    <!-- ── JAVASCRIPT ────────────────────────────────────────────────── -->
    <script>
        /* API endpoint — same folder as dashboard.php */
        const API = 'dashboard-api.php';

        let chartMonthly = null;
        let chartPie = null;

        /* Generic fetch helper — pass extra params as an object to filter */
        function api(action, params = {}) {
            const qs = new URLSearchParams({
                action,
                ...params
            }).toString();
            return fetch(`${API}?${qs}`).then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            });
        }

        /* Read the current state of the subbar filter pills */
        function getFilters() {
            const f = {};
            const month = document.getElementById('filter-month')?.value;
            const province = document.getElementById('filter-province')?.value;
            const job = document.getElementById('filter-job')?.value;
            const country = document.getElementById('filter-country')?.value;
            if (month) f.month = month;
            if (province) f.province = province;
            if (job) f.job_type = job;
            if (country) f.country = country;
            return f;
        }

        /* Open the printable / PDF report using the admin's current filters */
        function openReport() {
            const qs = new URLSearchParams(getFilters()).toString();
            window.open(`report.php${qs ? '?' + qs : ''}`, '_blank');
        }

        function removeSkeleton(el) {
            if (el) el.classList.remove('skeleton', 'loading');
        }

        function fmtPct(pct) {
            if (pct === null || pct === undefined) return '';
            const sign = pct >= 0 ? '▲' : '▼';
            const col = pct >= 0 ? '#10b981' : '#ef4444';
            return `<b style="color:${col}">${sign} ${Math.abs(pct)}%</b> vs last mo.`;
        }

        /* ── Live date ────────────────────────────────────────────────── */
        document.getElementById('live-date').textContent =
            new Date().toLocaleDateString('en-PH', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).toUpperCase();

        /* ── 0. Populate filter pills (job types + destinations), once ─── */
        async function loadFilterOptions() {
            const [jobTypes, dest] = await Promise.all([
                api('job_types'),
                api('destinations'), // unfiltered, gives the full country list
            ]);

            const jobSel = document.getElementById('filter-job');
            jobSel.innerHTML = '<option value="">All Job Types</option>';
            jobTypes.forEach(t => jobSel.innerHTML += `<option value="${t}">${t}</option>`);

            const countrySel = document.getElementById('filter-country');
            countrySel.innerHTML = '<option value="">All Destinations</option>';
            dest.labels.forEach(c => countrySel.innerHTML += `<option value="${c}">${c}</option>`);
        }

        /* ── Wire up filter pills: any change reloads the filtered views ── */
        function bindFilterPills() {
            ['filter-month', 'filter-province', 'filter-job', 'filter-country'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', refreshFilteredViews);
            });
        }

        function refreshFilteredViews() {
            Promise.all([
                loadStats(),
                loadMonthly(),
                loadStatus(),
                loadJobOrders(),
                loadPie(),
            ]).catch(err => console.error('Dashboard filter error:', err));
        }

        /* ── 1. KPI Stats ─────────────────────────────────────────────── */
        async function loadStats() {
            const d = await api('stats', getFilters());

            const tv = document.getElementById('kpi-total');
            tv.textContent = d.total.toLocaleString();
            removeSkeleton(tv);
            const ts = document.getElementById('kpi-total-sub');
            ts.innerHTML = d.change_pct !== null ? fmtPct(d.change_pct) : 'All time';
            removeSkeleton(ts);

            const pv = document.getElementById('kpi-pending');
            pv.textContent = d.pending.toLocaleString();
            removeSkeleton(pv);
            const ps = document.getElementById('kpi-pending-sub');
            ps.innerHTML = d.pending > 0 ?
                '<span style="color:#f59e0b">Needs attention</span>' :
                '<span style="color:#10b981">All clear</span>';
            removeSkeleton(ps);

            const ev = document.getElementById('kpi-eval');
            ev.textContent = d.under_evaluation.toLocaleString();
            removeSkeleton(ev);
            const es = document.getElementById('kpi-eval-sub');
            es.textContent = d.avg_eval_days ? `Avg. ${d.avg_eval_days} days` : 'In progress';
            removeSkeleton(es);

            const av = document.getElementById('kpi-approved');
            av.textContent = d.approved.toLocaleString();
            removeSkeleton(av);
            const as = document.getElementById('kpi-approved-sub');
            as.innerHTML = d.approved_change !== null ? fmtPct(d.approved_change) : '';
            removeSkeleton(as);

            /* Dock badge — show unassigned count so admin knows what needs action */
            const unassign = d.unassign ?? 0;
            if (unassign > 0) {
                const b = document.getElementById('dock-badge');
                b.textContent = unassign;
                b.style.display = '';
            }
        }

        /* ── 2. Monthly bar chart ─────────────────────────────────────── */
        async function loadMonthly() {
            const d = await api('monthly', getFilters());

            if (chartMonthly) {
                chartMonthly.destroy();
                chartMonthly = null;
            }

            chartMonthly = new Chart(document.getElementById('chartMonthly'), {
                type: 'bar',
                data: {
                    labels: d.labels,
                    datasets: [{
                            label: 'Applications',
                            data: d.submitted,
                            backgroundColor: 'rgba(29,78,216,.12)',
                            borderColor: '#1d4ed8',
                            borderWidth: 2,
                            borderRadius: 6,
                            borderSkipped: false
                        },
                        {
                            label: 'Approved',
                            data: d.approved,
                            backgroundColor: 'rgba(5,150,105,.12)',
                            borderColor: '#059669',
                            borderWidth: 2,
                            borderRadius: 6,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    family: 'DM Sans',
                                    size: 11
                                },
                                boxWidth: 10,
                                padding: 12
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: 'DM Sans',
                                    size: 10
                                },
                                maxRotation: 0
                            }
                        },
                        y: {
                            grid: {
                                color: '#f1f5f9'
                            },
                            ticks: {
                                font: {
                                    family: 'DM Sans',
                                    size: 10
                                }
                            }
                        }
                    }
                }
            });
        }

        /* ── 3. Status breakdown bars ─────────────────────────────────── */
        async function loadStatus() {
            const items = await api('status', getFilters());
            const maxCnt = Math.max(...items.map(s => s.count), 1);
            const el = document.getElementById('status-list');
            el.innerHTML = '';

            items.forEach(s => {
                const pct = Math.round(s.count / maxCnt * 100);
                el.innerHTML += `
        <div class="s-row">
            <span class="s-dot"  style="background:${s.color}"></span>
            <span class="s-name">${s.label}</span>
            <span class="s-num"  style="color:${s.color}">${s.count}</span>
            <div  class="s-bar-wrap">
                <div class="s-bar-fill" style="width:${pct}%;background:${s.color}"></div>
            </div>
        </div>`;
            });
        }

        /* ── 4. Active job orders ─────────────────────────────────────── */
        const JOB_GRADIENTS = [
            ['#059669', '#34d399'],
            ['#1d4ed8', '#60a5fa'],
            ['#d97706', '#fbbf24'],
            ['#6366f1', '#a78bfa'],
            ['#dc2626', '#f87171'],
        ];

        async function loadJobOrders() {
            const jobs = await api('job_orders', getFilters());
            const el = document.getElementById('job-list');
            el.innerHTML = '';

            if (!jobs.length) {
                el.innerHTML = '<p style="font-size:12px;color:#94a3b8;text-align:center;padding:16px">No active job orders</p>';
                return;
            }

            jobs.forEach((j, i) => {
                const [c1, c2] = JOB_GRADIENTS[i % JOB_GRADIENTS.length];
                const numColor = j.fill_pct > 80 ? '#dc2626' : (j.fill_pct > 50 ? '#d97706' : c1);
                const daysLabel = j.days_left <= 0 ?
                    '<span style="color:#dc2626;font-weight:700">Expired</span>' :
                    j.days_left <= 3 ?
                    `<span style="color:#ef4444;font-weight:700">${j.days_left}d left!</span>` :
                    `${j.days_left}d left`;

                el.innerHTML += `
        <div class="job-item">
            <div class="job-head">
                <div>
                    <div class="job-name">${j.rsf_number}</div>
                    <div class="job-sub">${j.title} &mdash; ${j.country}</div>
                    <div style="font-size:9px;color:#94a3b8;margin-top:2px">${daysLabel}</div>
                </div>
                <div class="job-count" style="color:${numColor}">
                    ${j.applied}<span style="color:var(--text3);font-weight:500;font-size:10px">/${j.slots}</span>
                </div>
            </div>
            <div class="job-track">
                <div class="job-fill" style="width:${j.fill_pct}%;background:linear-gradient(90deg,${c1},${c2})"></div>
            </div>
        </div>`;
            });
        }

        /* ── 5. Destination doughnut ──────────────────────────────────── */
        async function loadPie() {
            const d = await api('destinations', getFilters());

            if (chartPie) {
                chartPie.destroy();
                chartPie = null;
            }

            if (!d.labels.length) {
                document.getElementById('chartPie').parentElement.innerHTML =
                    '<p style="font-size:12px;color:#94a3b8;padding:24px;text-align:center">No data yet</p>';
                return;
            }

            chartPie = new Chart(document.getElementById('chartPie'), {
                type: 'doughnut',
                data: {
                    labels: d.labels,
                    datasets: [{
                        data: d.data,
                        backgroundColor: d.colors,
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverOffset: 5
                    }]
                },
                options: {
                    cutout: '62%',
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            const leg = document.getElementById('pie-legend');
            const total = d.data.reduce((a, b) => a + b, 0) || 1;
            leg.innerHTML = '';
            d.labels.forEach((l, i) => {
                const pct = Math.round(d.data[i] / total * 100);
                leg.innerHTML += `
        <div class="p-row">
            <span class="p-sw"  style="background:${d.colors[i]}"></span>
            <span class="p-lbl">${l}</span>
            <span class="p-pct">${pct}%</span>
        </div>`;
            });
        }

        /* ── 6. Notifications ─────────────────────────────────────────── */
        async function loadNotifications() {
            const d = await api('notifications');
            const badge = document.getElementById('notif-badge');
            const list = document.getElementById('notif-list');

            if (d.unread > 0) badge.style.display = '';

            if (!d.items.length) {
                list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px">No notifications</div>';
                return;
            }

            list.innerHTML = d.items.map(n => `
    <div class="notif-item ${n.is_read ? '' : 'unread'}" onclick="openApp(${n.application_id ?? 'null'})">
        <span class="notif-dot-sm ${n.is_read ? 'read' : ''}"></span>
        <div>
            <div class="notif-title">${n.title}</div>
            <div class="notif-msg">${n.message}</div>
            <div class="notif-time">${n.time_ago}</div>
        </div>
    </div>`).join('');
        }

        function toggleNotifPanel(e) {
            e.stopPropagation();
            document.getElementById('notif-panel').classList.toggle('open');
        }

        function openApp(id) {
            if (id) location.href = `applications.php?id=${id}`;
        }

        function markAllRead() {
            fetch(`${API}?action=mark_read`, {
                method: 'POST'
            });
            document.querySelectorAll('.notif-item').forEach(el => el.classList.remove('unread'));
            document.getElementById('notif-badge').style.display = 'none';
        }

        document.addEventListener('click', e => {
            const panel = document.getElementById('notif-panel');
            if (!panel.contains(e.target) && e.target.id !== 'notif-btn') {
                panel.classList.remove('open');
            }
        });

        /* ── Dock flyout (same as applications.php) ───────────────────── */
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

        /* ── Init: load everything in parallel ───────────────────────── */
        bindFilterPills();
        Promise.all([
            loadFilterOptions(),
            loadStats(),
            loadMonthly(),
            loadStatus(),
            loadJobOrders(),
            loadPie(),
            loadNotifications(),
        ]).catch(err => console.error('Dashboard error:', err));

        /* Auto-refresh KPIs + notifications every 60 s */
        setInterval(() => {
            loadStats();
            loadStatus();
            loadNotifications();
        }, 60_000);
    </script>

    <script type="module" src="../js/main.js"></script>
    <?php include '../includes/login_toast.php'; ?>
</body>

</html>