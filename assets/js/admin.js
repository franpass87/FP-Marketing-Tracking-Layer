/**
 * FP Marketing Tracking Layer — Admin UI
 * Copy-to-clipboard e utilità
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
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
