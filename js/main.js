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
            background: rgba(2, 6, 23, .45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }
        .notif-modal {
            width: min(560px, 100%);
            max-height: 78vh;
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(2, 6, 23, .22);
            overflow: hidden;
        }
        .notif-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
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
            const appInfo = n.application_id ? `Application #${n.application_id}` : (n.type || 'General');
            return `
                <div class="notif-item ${unreadClass}" data-id="${n.id}">
                    <div class="notif-title">${escapeHtml(n.title || 'Notification')}</div>
                    <div class="notif-message">${escapeHtml(n.message || '')}</div>
                    <div class="notif-meta">
                        <span>${escapeHtml(appInfo)}</span>
                        <span>${escapeHtml(formatDate(n.created_at))}</span>
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
        overlay.style.display = 'flex';
    }

    function hideModal() {
        overlay.style.display = 'none';
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