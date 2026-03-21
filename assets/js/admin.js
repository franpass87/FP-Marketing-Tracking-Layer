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
