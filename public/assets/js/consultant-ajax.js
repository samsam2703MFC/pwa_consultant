/**
 * consultant-ajax.js
 * Wspólna biblioteka do lazy-loadingu sekcji przez proxy.
 *
 * Użycie w widoku:
 *   ConsultantAjax.load({
 *     endpoint: '/consultant/network/shops/summary',
 *     params:   { date: '2026-04-13' },
 *     skeleton: '#skeleton-id',
 *     target:   '#content-id',
 *     onSuccess: (data) => { ... },
 *     onError:   (msg)  => { ... },
 *   });
 */

const ConsultantAjax = (() => {

    const PROXY = (window.__consultantRoot || '') + '/api-proxy';

    function load({ endpoint, params = {}, skeleton, target, onSuccess, onError }) {
        const qs = new URLSearchParams({ endpoint, ...params }).toString();
        const url = `${PROXY}?${qs}`;

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        .then(res => {
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        })
        .then(json => {
            if (!json.success) throw new Error(json.error || 'API error');

            if (skeleton) {
                const el = typeof skeleton === 'string' ? document.querySelector(skeleton) : skeleton;
                if (el) el.style.display = 'none';
            }
            if (target) {
                const el = typeof target === 'string' ? document.querySelector(target) : target;
                if (el) el.style.display = '';
            }

            if (onSuccess) onSuccess(json.data);
        })
        .catch(err => {
            console.error('[ConsultantAjax] Error:', err);
            if (skeleton) {
                const el = typeof skeleton === 'string' ? document.querySelector(skeleton) : skeleton;
                if (el) el.innerHTML = errorHtml(err.message);
            }
            if (onError) onError(err.message);
        });
    }

    function loadAll(requests) {
        return Promise.all(requests.map(r => {
            const qs = new URLSearchParams({ endpoint: r.endpoint, ...(r.params || {}) }).toString();
            return fetch(`${PROXY}?${qs}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then(res => res.json());
        }));
    }

    function skeletonLines(lines = 4, height = '18px') {
        return Array.from({ length: lines }, (_, i) => `
            <div style="
                height:${height};
                border-radius:8px;
                background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);
                background-size:200% 100%;
                animation:shimmer 1.4s infinite;
                margin-bottom:10px;
                width:${i % 3 === 2 ? '60%' : '100%'};
            "></div>
        `).join('');
    }

    function skeletonCard(opts = {}) {
        const { lines = 4, lineHeight = '16px', title = true } = opts;
        return `
        <div style="background:#fff;border-radius:16px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:12px;">
            ${title ? `<div style="height:20px;width:40%;border-radius:8px;background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;margin-bottom:14px;"></div>` : ''}
            ${skeletonLines(lines, lineHeight)}
        </div>`;
    }

    function errorHtml(msg) {
        return `
        <div style="background:#fff0f0;border-radius:14px;padding:16px;text-align:center;color:#c0392b;font-size:.85rem;margin-bottom:12px;">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:1.4rem;display:block;margin-bottom:8px;"></i>
            Błąd ładowania danych.<br><small style="opacity:.7;">${msg}</small>
            <br><button onclick="location.reload()"
                style="margin-top:10px;background:#c0392b;color:#fff;border:none;border-radius:20px;padding:5px 16px;font-size:.78rem;cursor:pointer;">
                Odśwież
            </button>
        </div>`;
    }

    return { load, loadAll, skeletonLines, skeletonCard, errorHtml };
})();

