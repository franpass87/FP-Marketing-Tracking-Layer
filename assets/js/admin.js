/**
 * FP Marketing Tracking Layer — Admin UI
 * Copy-to-clipboard e utilità
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Card collapsible
        document.querySelectorAll('.fptracking-card-collapsible').forEach(function (card) {
            var header = card.querySelector('.fptracking-card-header');
            var body = card.querySelector('.fptracking-card-body');
            if (!header || !body) return;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fptracking-card-toggle';
            btn.setAttribute('aria-label', (typeof fpTrackingAdmin !== 'undefined' && fpTrackingAdmin.collapse) ? fpTrackingAdmin.collapse : 'Comprimi');
            btn.setAttribute('aria-expanded', 'true');
            btn.innerHTML = '<span class="dashicons dashicons-arrow-up-alt2"></span>';
            header.appendChild(btn);

            btn.addEventListener('click', function () {
                var collapsed = card.classList.toggle('is-collapsed');
                var labels = (typeof fpTrackingAdmin !== 'undefined') ? fpTrackingAdmin : { collapse: 'Comprimi', expand: 'Espandi' };
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                btn.setAttribute('aria-label', collapsed ? labels.expand : labels.collapse);
                btn.querySelector('.dashicons').className = 'dashicons dashicons-' + (collapsed ? 'arrow-down-alt2' : 'arrow-up-alt2');
            });
        });

        // Brevo: carica liste e test connessione
        var loadListsBtn = document.getElementById('fp-tracking-load-brevo-lists');
        var testBrevoBtn = document.getElementById('fp-tracking-test-brevo');
        var listsContainer = document.getElementById('fp-tracking-brevo-lists-container');
        var testResult = document.getElementById('fp-tracking-brevo-test-result');
        var fpAdmin = (typeof fpTrackingAdmin !== 'undefined') ? fpTrackingAdmin : { ajaxUrl: '', nonce: '' };

        if (loadListsBtn && listsContainer && fpAdmin.ajaxUrl) {
            loadListsBtn.addEventListener('click', function () {
                var orig = loadListsBtn.innerHTML;
                loadListsBtn.disabled = true;
                loadListsBtn.innerHTML = '<span class="dashicons dashicons-update spin"></span> ' + (fpAdmin.loading || 'Caricamento...');
                listsContainer.innerHTML = '';

                fetch(fpAdmin.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=fp_tracking_load_brevo_lists&nonce=' + encodeURIComponent(fpAdmin.nonce || ''),
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    loadListsBtn.disabled = false;
                    loadListsBtn.innerHTML = orig;
                    if (res.success && res.data && res.data.lists) {
                        var html = '<div class="notice notice-info inline" style="margin:0;padding:8px 12px;"><strong>' + (fpAdmin.listsLabel || 'Liste disponibili') + ':</strong><ul style="margin:8px 0 0 20px">';
                        res.data.lists.forEach(function (list) {
                            html += '<li><code>' + list.id + '</code> - ' + (list.name || '') + ' (' + (list.total_subscribers || 0) + ' contatti)</li>';
                        });
                        html += '</ul></div>';
                        listsContainer.innerHTML = html;
                    } else {
                        listsContainer.innerHTML = '<div class="notice notice-error inline" style="margin:0;padding:8px 12px;">' + (res.data && res.data.message ? res.data.message : 'Errore') + '</div>';
                    }
                })
                .catch(function () {
                    loadListsBtn.disabled = false;
                    loadListsBtn.innerHTML = orig;
                    listsContainer.innerHTML = '<div class="notice notice-error inline" style="margin:0;padding:8px 12px;">Errore di connessione</div>';
                });
            });
        }

        if (testBrevoBtn && testResult && fpAdmin.ajaxUrl) {
            testBrevoBtn.addEventListener('click', function () {
                var orig = testBrevoBtn.innerHTML;
                testBrevoBtn.disabled = true;
                testBrevoBtn.innerHTML = '<span class="dashicons dashicons-update spin"></span> Testing...';
                testResult.innerHTML = '';

                fetch(fpAdmin.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=fp_tracking_test_brevo&nonce=' + encodeURIComponent(fpAdmin.nonce || ''),
                })
                .then(function (r) {
                    return r.text().then(function (text) {
                        var payload = null;
                        try {
                            payload = JSON.parse(text);
                        } catch (e) {
                            payload = { success: false, data: { message: text && text.trim() !== '' ? text : 'Risposta non valida dal server' } };
                        }
                        return payload;
                    });
                })
                .then(function (res) {
                    testBrevoBtn.disabled = false;
                    testBrevoBtn.innerHTML = orig;
                    if (res.success) {
                        testResult.innerHTML = '<div class="notice notice-success inline" style="margin:0;padding:8px 12px;"><span class="dashicons dashicons-yes-alt"></span> ' + (res.data.message || 'OK') + '</div>';
                    } else {
                        testResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;padding:8px 12px;">' + (res.data && res.data.message ? res.data.message : 'Errore durante il test Brevo') + '</div>';
                    }
                })
                .catch(function () {
                    testBrevoBtn.disabled = false;
                    testBrevoBtn.innerHTML = orig;
                    testResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;padding:8px 12px;">Errore di connessione</div>';
                });
            });
        }

        // Copy buttons
        document.querySelectorAll('.fptracking-btn-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = this.getAttribute('data-fptracking-copy-for');
                var input = document.getElementById(targetId);
                var feedback = this.querySelector('.fptracking-copy-feedback');

                if (!input || !input.value) {
                    return;
                }

                var value = input.value;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(function () {
                        showCopied(feedback);
                    }).catch(function () {
                        fallbackCopy(value, feedback);
                    });
                } else {
                    fallbackCopy(value, feedback);
                }
            });
        });
    });

    function showCopied(feedback) {
        if (!feedback) return;
        feedback.textContent = 'Copiato!';
        feedback.classList.add('is-visible');
        setTimeout(function () {
            feedback.textContent = '';
            feedback.classList.remove('is-visible');
        }, 1500);
    }

    function fallbackCopy(text, feedback) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopied(feedback);
        } catch (e) {
            if (feedback) feedback.textContent = 'Errore';
        }
        document.body.removeChild(ta);
    }
})();
