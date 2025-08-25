document.addEventListener('DOMContentLoaded', function() {
    // pestañas (si las hay)
    const tabs = document.querySelectorAll('.sc-editor-tabs li');
    const contents = document.querySelectorAll('.tab-content');
    if (tabs.length && contents.length) {
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                const id = 'tab-' + tab.dataset.tab;
                const el = document.getElementById(id);
                if (el) el.classList.add('active');
            });
        });
    }

    // Detección de hooks vía AJAX
    const detectBtn = document.getElementById('sc-detect-hooks');
    if (detectBtn) {
        detectBtn.addEventListener('click', function() {
            const phpCode = document.getElementById('php_code') ? document.getElementById('php_code').value : '';
            const nonce = document.getElementById('sc-detect-nonce') ? document.getElementById('sc-detect-nonce').value : '';
            const msgEl = document.getElementById('sc-detect-hooks-message');
            if (msgEl) msgEl.textContent = 'Detectando...';

            const ajaxUrl = (window.simply_code_ajax && window.simply_code_ajax.ajax_url) ? window.simply_code_ajax.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/');

            const form = new FormData();
            form.append('action', 'simply_code_detect_hooks');
            form.append('nonce', nonce);
            form.append('php_code', phpCode);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: form
            }).then(function(resp) {
                return resp.json();
            }).then(function(data) {
                if (!data) {
                    if (msgEl) msgEl.textContent = 'Respuesta inválida';
                    return;
                }
                if (data.success) {
                    const hooks = data.data.hooks || {};
                    const critical = data.data.critical_hooks || {};
                    const container = document.getElementById('sc-hooks-list');
                    if (container) {
                        container.innerHTML = '';
                        if (Object.keys(hooks).length === 0) {
                            container.innerHTML = '<p class="description">No se detectaron hooks.</p>';
                        } else {
                            for (const h in hooks) {
                                const info = hooks[h] || {};
                                const row = document.createElement('div');
                                row.className = 'sc-hook-row';
                                const html = '<strong>' + escapeHtml(h) + '</strong> <span class="description">' + escapeHtml(info.type || '') + '</span><br>' +
                                    'Prioridad: <input type="number" name="hook_priorities[' + escapeAttr(h) + ']" value="' + (info.priority || 10) + '" style="width:80px;">' +
                                    (critical[h] ? '<span style="color:#d9534f; margin-left:8px;">⚠️ Hook crítico</span>' : '');
                                row.innerHTML = html;
                                container.appendChild(row);
                            }
                        }
                    }
                    if (msgEl) msgEl.textContent = 'Hooks detectados';
                } else {
                    if (msgEl) msgEl.textContent = data.data && data.data.message ? data.data.message : 'Error al detectar hooks';
                }
            }).catch(function(err) {
                if (msgEl) msgEl.textContent = 'Error AJAX';
                console.error(err);
            });
        });
    }

    // helpers
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escapeAttr(str) {
        return escapeHtml(str).replace(/'/g, '&#39;');
    }
});
