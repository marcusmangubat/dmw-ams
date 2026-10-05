<?php

/**
 * report.php  –  DMW RO IV-A Admin Dashboard
 * Printable / PDF-exportable view of whatever the admin currently has
 * filtered on dashboard.php (month, province, job type, destination
 * country). Lives next to dashboard.php and dashboard-api.php.
 */
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$admin_name = $_SESSION['admin_name'] ?? '';
if (!$admin_name) {
    $stmt = $database->prepare("SELECT full_name FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['user_email']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $admin_name = $row['full_name'] ?? 'Admin';
}

// These are only used to pre-render the filter summary before JS kicks in;
// the actual data always comes from dashboard-api.php so it can never
// drift from what's really in the database.
$qMonth   = trim($_GET['month'] ?? '');
$qProvince = trim($_GET['province'] ?? '');
$qJobType  = trim($_GET['job_type'] ?? '');
$qCountry  = trim($_GET['country'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Application Report – DMW RO IV-A</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700;9..40,800&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="icon" type="image/png" href="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png">

    <style>
        body {
            font-family: 'DM Sans', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            margin: 0;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .toolbar-title {
            font-weight: 700;
            font-size: 14px;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #0f172a;
            cursor: pointer;
        }

        .btn:hover {
            background: #f8fafc;
        }

        .btn-primary {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        .btn[disabled] {
            opacity: .55;
            cursor: not-allowed;
        }

        #report-sheet {
            max-width: 1000px;
            margin: 24px auto;
            background: #fff;
            padding: 32px 36px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
        }

        .rpt-head {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #1d4ed8;
            padding-bottom: 14px;
            margin-bottom: 14px;
        }

        .rpt-head img {
            height: 42px;
        }

        .rpt-head h1 {
            font-size: 15px;
            margin: 0;
        }

        .rpt-head p {
            font-size: 11px;
            color: #475569;
            margin: 2px 0 0;
        }

        .rpt-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 10px;
            margin-bottom: 16px;
            font-size: 11px;
            color: #334155;
        }

        .rpt-chip {
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 999px;
            padding: 3px 10px;
            font-weight: 600;
        }

        table.rpt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .rpt-table th {
            text-align: left;
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            padding: 8px 8px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .rpt-table td {
            padding: 7px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .rpt-table tr:nth-child(even) td {
            background: #fafbfc;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .rpt-footer {
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 10px;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
        }

        .empty-state,
        .loading-state {
            text-align: center;
            padding: 40px 0;
            color: #94a3b8;
            font-size: 12px;
        }

        @media print {
            body {
                background: #fff;
            }

            .toolbar {
                display: none;
            }

            #report-sheet {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
                border-radius: 0;
            }
        }
    </style>
</head>

<body>

    <div class="toolbar">
        <div class="toolbar-title">
            <i class="fas fa-file-lines" style="color:#1d4ed8;margin-right:6px"></i>
            Application Report
        </div>
        <div class="toolbar-actions">
            <button class="btn" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back</button>
            <button class="btn" id="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button class="btn btn-primary" id="btn-pdf" onclick="downloadPdf()"><i class="fas fa-file-arrow-down"></i> Download PDF</button>
        </div>
    </div>

    <div id="report-sheet">
        <div class="rpt-head">
            <img src="https://dmw.gov.ph/images/dmw_tahanan_ng_ofw_colored.png" alt="DMW">
            <div>
                <h1>Department of Migrant Workers &mdash; Regional Office IV-A</h1>
                <p>Application Report &mdash; generated by <?= htmlspecialchars($admin_name) ?></p>
            </div>
        </div>

        <div class="rpt-meta" id="rpt-meta">
            <span class="rpt-chip"><i class="fas fa-spinner fa-spin"></i> Loading filters&hellip;</span>
        </div>

        <div id="rpt-body">
            <div class="loading-state"><i class="fas fa-spinner fa-spin"></i> Loading applications&hellip;</div>
        </div>

        <div class="rpt-footer">
            <span id="rpt-count"></span>
            <span id="rpt-generated"></span>
        </div>
    </div>

    <script>
        const API = 'dashboard-api.php';

        const STATUS_META = {
            pending: {
                label: 'Pending',
                color: '#f59e0b'
            },
            unassign: {
                label: 'Unassigned',
                color: '#ef4444'
            },
            unassigned: {
                label: 'Unassigned',
                color: '#ef4444'
            },
            assign: {
                label: 'Assigned',
                color: '#3b82f6'
            },
            assigned: {
                label: 'Assigned',
                color: '#3b82f6'
            },
            under_evaluation: {
                label: 'Under Evaluation',
                color: '#6366f1'
            },
            for_compliance: {
                label: 'For Compliance',
                color: '#f97316'
            },
            resubmitted: {
                label: 'Resubmitted',
                color: '#8b5cf6'
            },
            approved: {
                label: 'Approved',
                color: '#10b981'
            },
            disapproved: {
                label: 'Disapproved',
                color: '#dc2626'
            },
        };

        const FILTER_LABELS = {
            month: 'Month',
            province: 'Province',
            job_type: 'Job Type',
            country: 'Destination'
        };

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

        function getFiltersFromUrl() {
            const p = new URLSearchParams(window.location.search);
            const f = {};
            ['month', 'province', 'job_type', 'country'].forEach(k => {
                const v = p.get(k);
                if (v) f[k] = v;
            });
            return f;
        }

        function fmtDate(s) {
            if (!s) return '';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d)) return s;
            return d.toLocaleDateString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, m => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [m]));
        }

        async function loadReport() {
            const filters = getFiltersFromUrl();
            let data;
            try {
                data = await api('report', filters);
            } catch (err) {
                document.getElementById('rpt-body').innerHTML =
                    `<div class="empty-state"><i class="fas fa-triangle-exclamation"></i> Couldn't load the report (${escapeHtml(err.message)}).</div>`;
                document.getElementById('btn-pdf').disabled = true;
                return;
            }

            // Filter summary chips
            const applied = Object.entries(data.filters || filters).filter(([, v]) => v);
            const meta = document.getElementById('rpt-meta');
            meta.innerHTML = applied.length ?
                applied.map(([k, v]) => `<span class="rpt-chip">${FILTER_LABELS[k] || k}: ${escapeHtml(v)}</span>`).join('') :
                '<span class="rpt-chip">All applications (no filters applied)</span>';

            // Table
            const body = document.getElementById('rpt-body');
            if (!data.rows || !data.rows.length) {
                body.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><br>No applications match these filters.</div>';
            } else {
                const rowsHtml = data.rows.map(r => {
                    const meta = STATUS_META[r.status] || {
                        label: r.status,
                        color: '#64748b'
                    };
                    return `
                <tr>
                    <td>${escapeHtml(r.applicant)}</td>
                    <td>${escapeHtml(r.province)}${r.city ? ', ' + escapeHtml(r.city) : ''}</td>
                    <td>${escapeHtml(r.position_title)}</td>
                    <td>${escapeHtml(r.employer_name)}</td>
                    <td>${escapeHtml(r.country)}</td>
                    <td><span class="status-pill" style="background:${meta.color}1A;color:${meta.color}">${escapeHtml(meta.label)}</span></td>
                    <td>${fmtDate(r.submitted_at)}</td>
                </tr>`;
                }).join('');

                body.innerHTML = `
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Province / City</th>
                        <th>Position</th>
                        <th>Employer</th>
                        <th>Destination</th>
                        <th>Status</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>`;
            }

            document.getElementById('rpt-count').textContent = `Total records: ${data.count ?? (data.rows ? data.rows.length : 0)}`;
            document.getElementById('rpt-generated').textContent = `Generated: ${fmtDate(data.generated_at)}`;
        }

        function downloadPdf() {
            const el = document.getElementById('report-sheet');
            const btn = document.getElementById('btn-pdf');
            btn.disabled = true;
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF&hellip;';

            html2pdf()
                .set({
                    margin: 10,
                    filename: `application-report-${new Date().toISOString().slice(0, 10)}.pdf`,
                    image: {
                        type: 'jpeg',
                        quality: 0.98
                    },
                    html2canvas: {
                        scale: 2,
                        useCORS: true
                    },
                    jsPDF: {
                        unit: 'mm',
                        format: 'a4',
                        orientation: 'landscape'
                    },
                })
                .from(el)
                .save()
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = original;
                });
        }

        loadReport();
    </script>

</body>

</html>