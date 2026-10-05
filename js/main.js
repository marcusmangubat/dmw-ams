import { logout } from './logout.js';
window.logout = logout;

(function initClientNotifications() {
    const bellIcon = document.querySelector('.topbar-right .tb-btn .fa-bell');
    if (!bellIcon) return;
    const bellButton = bellIcon.closest('.tb-btn');
    if (!bellButton) return;

    const notifDot = bellButton.querySelector('.notif-dot');
    const apiUrl = 'notifications_api.php';

    const style = document.createElement('style');
    style.textContent = `
        .notif-modal-overlay {
            position: fixed;
            inset: 0;
            background: transparent;
            display: none;
            z-index: 9999;
        }
        .notif-modal {
            position: fixed;
            width: min(380px, calc(100vw - 24px));
            max-height: 70vh;
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(2, 6, 23, .22);
            overflow: hidden;
            transform-origin: top right;
        }
        .notif-modal::before {
            content: '';
            position: absolute;
            top: -7px;
            right: 22px;
            width: 14px;
            height: 14px;
            background: #fff;
            border-left: 1px solid #e2e8f0;
            border-top: 1px solid #e2e8f0;
            transform: rotate(45deg);
        }
        .notif-modal-overlay.notif-show .notif-modal {
            animation: notifModalPop .22s cubic-bezier(.34, 1.56, .64, 1);
        }
        @keyframes notifModalPop {
            0% {
                opacity: 0;
                transform: translateY(-8px) scale(0.92);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        .notif-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            position: relative;
            z-index: 1;
        }
        .notif-modal-head h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }
        .notif-actions {
            display: flex;
            gap: 6px;
        }
        .notif-btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 10px;
            cursor: pointer;
        }
        .notif-list {
            overflow: auto;
            padding: 8px;
            background: #fff;
            position: relative;
            z-index: 1;
        }
        .notif-item {
            border: 1px solid #e2e8f0;
            border-radius: 11px;
            padding: 10px 11px;
            margin-bottom: 8px;
            background: #fff;
        }
        .notif-item.unread {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .notif-title {
            font-size: 12px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .notif-message {
            font-size: 12px;
            color: #334155;
            line-height: 1.5;
            margin-bottom: 6px;
            white-space: pre-wrap;
        }
        .notif-meta {
            font-size: 11px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
        }
        .notif-empty {
            text-align: center;
            color: #64748b;
            font-size: 12px;
            padding: 26px 12px;
        }
        .notif-login-pop {
            position: fixed;
            z-index: 9998;
            min-width: 220px;
            max-width: 320px;
            background: #0f172a;
            color: #fff;
            border-radius: 10px;
            padding: 10px 12px;
            box-shadow: 0 12px 24px rgba(2, 6, 23, .35);
            font-size: 12px;
            line-height: 1.45;
            animation: notifPopIn .18s ease;
        }
        .notif-login-pop strong {
            display: block;
            margin-bottom: 2px;
            font-size: 12.5px;
        }
        @keyframes notifPopIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
    `;
    document.head.appendChild(style);

    const overlay = document.createElement('div');
    overlay.className = 'notif-modal-overlay';
    overlay.innerHTML = `
        <div class="notif-modal">
            <div class="notif-modal-head">
                <h3><i class="fas fa-bell"></i> Notifications</h3>
                <div class="notif-actions">
                    <button type="button" class="notif-btn" id="notifMarkAllBtn">Mark all as read</button>
                    <button type="button" class="notif-btn" id="notifCloseBtn">Close</button>
                </div>
            </div>
            <div class="notif-list" id="notifList"></div>
        </div>
    `;
    document.body.appendChild(overlay);

    const notifList = overlay.querySelector('#notifList');
    const closeBtn = overlay.querySelector('#notifCloseBtn');
    const markAllBtn = overlay.querySelector('#notifMarkAllBtn');

    /* Position the dropdown just under the bell, right-aligned to it */
    function positionModal() {
        const rect = bellButton.getBoundingClientRect();
        const modal = overlay.querySelector('.notif-modal');
        const gap = 12; // space between bell and modal, room for the arrow
        const top = rect.bottom + gap;
        let right = window.innerWidth - rect.right - 8; // nudge left of the bell's right edge
        if (right < 8) right = 8;
        modal.style.top = `${top}px`;
        modal.style.right = `${right}px`;
        modal.style.left = 'auto';
    }

    window.addEventListener('resize', () => {
        if (overlay.style.display === 'block') positionModal();
    });

    function formatDate(dt) {
        const d = new Date(dt);
        if (Number.isNaN(d.getTime())) return '';
        return d.toLocaleString('en-PH', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }
    function timeAgo(dt) {
        const date = new Date(dt);
        if (Number.isNaN(date.getTime())) return '';

        const diffSec = Math.floor((Date.now() - date.getTime()) / 1000);

        if (diffSec < 60) return 'Just now';

        const diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) return `${diffMin} minute${diffMin > 1 ? 's' : ''} ago`;

        const diffHour = Math.floor(diffMin / 60);
        if (diffHour < 24) return `${diffHour} hour${diffHour > 1 ? 's' : ''} ago`;

        const diffDay = Math.floor(diffHour / 24);
        if (diffDay < 7) return `${diffDay} day${diffDay > 1 ? 's' : ''} ago`;

        return formatDate(dt); // 7+ days old — show full date instead
    }

    function setDot(count) {
        if (!notifDot) return;
        notifDot.style.display = count > 0 ? 'block' : 'none';
    }

    function renderNotifications(items) {
        if (!notifList) return;
        if (!items || items.length === 0) {
            notifList.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
            return;
        }
        notifList.innerHTML = items.map((n) => {
            const unreadClass = Number(n.is_read) === 0 ? 'unread' : '';

            // Client notifications carry position_title/worksite_country.
            // Evaluator notifications carry first_name/last_name.
            let appInfo;
            if (n.position_title) {
                appInfo = n.worksite_country
                    ? `Application for ${n.position_title} in ${n.worksite_country}`
                    : `Application for ${n.position_title}`;
            } else if (n.first_name || n.last_name) {
                const fullName = [n.first_name, n.last_name].filter(Boolean).join(' ');
                appInfo = `Applicant: ${fullName}`;
            } else {
                appInfo = 'General';
            }

            return `
                <div class="notif-item ${unreadClass}" data-id="${n.id}">
                    <div class="notif-title">${escapeHtml(n.title || 'Notification')}</div>
                    <div class="notif-message">${escapeHtml(n.message || '')}</div>
                    <div class="notif-meta">
                        <span>${escapeHtml(appInfo)}</span>
                        <span title="${escapeHtml(formatDate(n.created_at))}">${escapeHtml(timeAgo(n.created_at))}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function escapeHtml(s) {
        return String(s)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    async function api(action = 'list', payload = null) {
        const url = `${apiUrl}?action=${encodeURIComponent(action)}`;
        const options = {
            method: payload ? 'POST' : 'GET',
            credentials: 'same-origin',
        };
        if (payload) {
            const fd = new FormData();
            Object.entries(payload).forEach(([k, v]) => fd.append(k, String(v)));
            options.body = fd;
        }
        const res = await fetch(url, options);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Notification request failed.');
        return data;
    }

    async function refreshNotifications() {
        try {
            const data = await api('list');
            renderNotifications(data.notifications || []);
            setDot(Number(data.unread_count || 0));
            return data;
        } catch (e) {
            if (notifList) {
                notifList.innerHTML = `<div class="notif-empty">${escapeHtml(e.message || 'Failed to load notifications.')}</div>`;
            }
            setDot(0);
            return null;
        }
    }

    function showModal() {
        overlay.style.display = 'block';
        positionModal();
        // restart animation each time it opens
        overlay.classList.remove('notif-show');
        void overlay.offsetWidth; // force reflow so the animation replays
        overlay.classList.add('notif-show');
    }

    function hideModal() {
        overlay.style.display = 'none';
        overlay.classList.remove('notif-show');
    }

    function showLoginPopup(message) {
        const rect = bellButton.getBoundingClientRect();
        const pop = document.createElement('div');
        pop.className = 'notif-login-pop';
        pop.innerHTML = message;
        pop.style.top = `${Math.max(8, rect.bottom + 8)}px`;
        pop.style.right = `${Math.max(8, window.innerWidth - rect.right)}px`;
        document.body.appendChild(pop);
        setTimeout(() => {
            pop.remove();
        }, 4500);
    }

    bellButton.addEventListener('click', async () => {
        showModal();
        const data = await refreshNotifications();
        if (data && Number(data.unread_count || 0) > 0) {
            try {
                await api('mark_all_read', {});
                await refreshNotifications();
            } catch (e) {
                // Ignore mark-read failure to keep modal usable.
            }
        }
    });

    closeBtn?.addEventListener('click', hideModal);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) hideModal();
    });
    markAllBtn?.addEventListener('click', async () => {
        try {
            await api('mark_all_read', {});
            await refreshNotifications();
        } catch (e) {
            // ignore
        }
    });

    refreshNotifications().then((data) => {
        const popupKey = 'clientNotifLoginPopupShown';
        if (!data || sessionStorage.getItem(popupKey) === '1') return;

        const total = (data.notifications || []).length;
        const unread = Number(data.unread_count || 0);
        const latest = data.notifications && data.notifications[0] ? data.notifications[0].title : '';
        if (total > 0) {
            const text = unread > 0
                ? `<strong>${unread} new notification${unread > 1 ? 's' : ''}</strong>${escapeHtml(latest || 'You have new updates.')}`
                : `<strong>Notifications</strong>${escapeHtml(latest || 'No new notifications.')}`;
            showLoginPopup(text);
            sessionStorage.setItem(popupKey, '1');
        }
    });
})();