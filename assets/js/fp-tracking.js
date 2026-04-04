/**
 * FP Marketing Tracking Layer — fp-tracking.js
 *
 * Automatic client-side event tracking. All events are pushed to
 * window.dataLayer so GTM can pick them up and route to GA4, Ads, Meta.
 *
 * Sections:
 *   1. Core helpers
 *   2. Contact clicks (phone, whatsapp, email, map, social, external links, file downloads)
 *   3. Scroll depth
 *   4. Video tracking (YouTube iframe, HTML5 video — only video_complete)
 *   5. WooCommerce client-side (add_to_cart AJAX)
 *   6. WordPress forms (CF7, Gravity Forms — contact_form_submit only)
 *   7. FP plugin DOM event bridge (FP-Forms, FP-Bio, FP-CTA-Bar)
 *   8. WooCommerce add_to_cart AJAX (jQuery bridge)
 */
(function () {
    'use strict';

    var cfg = (typeof fpTrackingConfig !== 'undefined') ? fpTrackingConfig : {};
    var debug = cfg.debug || false;

    // =========================================================================
    // 1. CORE HELPERS
    // =========================================================================

    function push(eventName, params) {
        var p = params || {};
        if (cfg.siteName && !p.affiliation) {
            p.affiliation = cfg.siteName;
        }
        if (!p.page_url && typeof window.location !== 'undefined' && window.location.href) {
            p.page_url = window.location.href;
        }
        var payload = Object.assign({ event: eventName }, p);
        window.dataLayer = window.dataLayer || [];
        if (debug) {
            console.log('[FP Tracking]', eventName, payload);
        }
        window.dataLayer.push(payload);
    }

    function getDomain(url) {
        try {
            return new URL(url).hostname;
        } catch (e) {
            return url;
        }
    }

    function isExternal(url) {
        try {
            return new URL(url).hostname !== window.location.hostname;
        } catch (e) {
            return false;
        }
    }

    function getClosest(el, selector) {
        if (el.closest) return el.closest(selector);
        while (el) {
            if (el.matches && el.matches(selector)) return el;
            el = el.parentElement;
        }
        return null;
    }

    // =========================================================================
    // 2. CONTACT CLICKS
    // =========================================================================

    document.addEventListener('click', function (e) {
        var el = getClosest(e.target, 'a[href]');
        if (!el) return;

        var href = el.getAttribute('href') || '';
        var text = el.textContent ? el.textContent.trim().substring(0, 100) : '';

        // --- Phone ---
        if (/^tel:/i.test(href)) {
            push('click_phone', {
                link_type:    'phone',
                phone_number: href.replace(/^tel:/i, ''),
                link_text:    text,
                link_url:     href,
            });
            return;
        }

        // --- WhatsApp ---
        if (/^https?:\/\/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)/i.test(href) || /^whatsapp:/i.test(href)) {
            var waNumber = href.replace(/.*wa\.me\/(\+?[\d]+).*/i, '$1')
                              .replace(/.*phone=(\+?[\d]+).*/i, '$1');
            push('click_whatsapp', {
                link_type:        'whatsapp',
                whatsapp_number:  waNumber,
                link_text:        text,
                link_url:         href,
            });
            return;
        }

        // --- Email ---
        if (/^mailto:/i.test(href)) {
            push('click_email', {
                link_type:      'email',
                email_address:  href.replace(/^mailto:/i, '').split('?')[0],
                link_text:      text,
                link_url:       href,
            });
            return;
        }

        // --- Google Maps / Apple Maps ---
        if (/maps\.(google|apple)\.com/i.test(href) || /^maps:/i.test(href) || /goo\.gl\/maps/i.test(href)) {
            push('click_map', {
                link_type:   'map',
                map_address: text,
                link_url:    href,
            });
            return;
        }

        // --- Social networks ---
        var socialPatterns = {
            facebook:  /facebook\.com|fb\.com/i,
            instagram: /instagram\.com/i,
            twitter:   /twitter\.com|x\.com/i,
            linkedin:  /linkedin\.com/i,
            youtube:   /youtube\.com|youtu\.be/i,
            tiktok:    /tiktok\.com/i,
            pinterest: /pinterest\.(com|it)/i,
            tripadvisor: /tripadvisor\.(com|it)/i,
        };
        for (var network in socialPatterns) {
            if (socialPatterns[network].test(href)) {
                push('click_social', {
                    link_type:      'social',
                    social_network: network,
                    link_text:      text,
                    link_url:       href,
                });
                return;
            }
        }

        // --- File downloads ---
        var fileExt = href.match(/\.([a-z0-9]+)(\?|#|$)/i);
        var downloadExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'csv', 'mp3', 'mp4'];
        if (fileExt && downloadExts.indexOf(fileExt[1].toLowerCase()) !== -1) {
            push('file_download', {
                file_name:      href.split('/').pop().split('?')[0],
                file_extension: fileExt[1].toLowerCase(),
                file_url:       href,
                link_text:      text,
            });
            return;
        }

        // --- External links ---
        if (isExternal(href) && href.indexOf('javascript:') !== 0) {
            push('click_external_link', {
                link_type:   'external',
                link_url:    href,
                link_domain: getDomain(href),
                link_text:   text,
            });
        }
    }, true); // capture phase for reliability

    // =========================================================================
    // 3. SCROLL DEPTH
    // =========================================================================

    (function () {
        var thresholds = (cfg.scrollDepths || [25, 50, 75, 90, 100]).slice().sort(function (a, b) { return a - b; });
        var fired = {};
        var ticking = false;

        function onScroll() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () {
                ticking = false;
                var scrolled = window.scrollY || window.pageYOffset;
                var docHeight = Math.max(
                    document.body.scrollHeight, document.documentElement.scrollHeight,
                    document.body.offsetHeight, document.documentElement.offsetHeight
                ) - window.innerHeight;

                if (docHeight <= 0) return;
                var percent = Math.round((scrolled / docHeight) * 100);

                thresholds.forEach(function (t) {
                    if (!fired[t] && percent >= t) {
                        fired[t] = true;
                        push('scroll_depth', {
                            percent_scrolled: t,
                            page_location:    window.location.href,
                        });
                    }
                });
            });
        }

        window.addEventListener('scroll', onScroll, { passive: true });
    })();

    // =========================================================================
    // 4. VIDEO TRACKING
    // =========================================================================

    // --- HTML5 <video> ---
    (function () {
        function attachVideo(video) {
            if (video._fpTracked) return;
            video._fpTracked = true;

            var title = video.getAttribute('title') || video.getAttribute('data-title') || video.src.split('/').pop() || 'video';
            var src   = video.currentSrc || video.src || '';

            video.addEventListener('ended', function () {
                push('video_complete', {
                    video_title:    title,
                    video_url:      src,
                    video_provider: 'html5',
                    video_duration: Math.round(video.duration) || 0,
                });
            });
        }

        // Attach to existing videos
        document.querySelectorAll('video').forEach(attachVideo);

        // Observe dynamically added videos
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (node) {
                        if (node.nodeType !== 1) return;
                        if (node.tagName === 'VIDEO') attachVideo(node);
                        node.querySelectorAll && node.querySelectorAll('video').forEach(attachVideo);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    })();

    // --- YouTube iframe (via postMessage API) ---
    (function () {
        var players = {};

        function onYTMessage(e) {
            if (!e.data || typeof e.data !== 'string') return;
            var data;
            try { data = JSON.parse(e.data); } catch (err) { return; }
            if (data.event !== 'infoDelivery' && data.event !== 'onStateChange') return;

            var info = data.info || {};
            var state = info.playerState;
            var id    = data.id || 'yt';

            if (!players[id]) players[id] = { fired: {}, title: info.title || 'YouTube Video' };
            var p = players[id];

            if (state === 0) { // ended
                push('video_complete', {
                    video_title:    p.title,
                    video_url:      'https://www.youtube.com/watch?v=' + (info.videoData && info.videoData.video_id || ''),
                    video_provider: 'youtube',
                    video_duration: Math.round(info.duration || 0),
                });
            }
        }

        window.addEventListener('message', onYTMessage);

        // Enable JS API on YouTube iframes
        document.querySelectorAll('iframe[src*="youtube.com"]').forEach(function (iframe) {
            var src = iframe.src;
            if (src.indexOf('enablejsapi') === -1) {
                iframe.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'enablejsapi=1';
            }
        });
    })();

    // =========================================================================
    // 5. WOOCOMMERCE CLIENT-SIDE
    // =========================================================================

    // WooCommerce AJAX add_to_cart success (native DOM event fallback)
    document.body && document.body.addEventListener('added_to_cart', function () {
        push('add_to_cart', { woo_ajax: true });
    });

    // =========================================================================
    // 6. WORDPRESS FORMS (client-side success)
    // =========================================================================

    // Contact Form 7 — fires wpcf7mailsent DOM event on success
    // generate_lead is fired server-side by WordPressIntegration (with event_id for deduplication).
    document.addEventListener('wpcf7mailsent', function (e) {
        var detail = e.detail || {};
        push('contact_form_submit', {
            form_id:   detail.contactFormId || detail.id || '',
            form_type: 'cf7',
        });
    });

    // Gravity Forms — fires gform_confirmation_loaded
    // generate_lead is fired server-side by WordPressIntegration (with event_id for deduplication).
    document.addEventListener('gform_confirmation_loaded', function (e) {
        var formId = e.detail && e.detail.formId ? e.detail.formId : '';
        push('contact_form_submit', { form_id: formId, form_type: 'gravity_forms' });
    });


    // =========================================================================
    // 7. FP PLUGIN DOM EVENT BRIDGE
    // =========================================================================

    // FP-Forms events
    document.addEventListener('fpFormSubmitSuccess', function (e) {
        var d = e.detail || {};
        // generate_lead is fired server-side by TrackingBridge (with event_id for deduplication).
        // Only push a lightweight UI confirmation event here to avoid duplicates in GTM.
        push('fp_form_submit_success', { form_id: d.formId, submission_id: d.submissionId });
    });
    document.addEventListener('fpFormSubmitError', function (e) {
        var d = e.detail || {};
        push('form_submit_error', { form_id: d.formId, error_message: d.message || '', error_type: d.errorType || 'server' });
    });
    document.addEventListener('fpFormStart', function (e) {
        var d = e.detail || {};
        push('form_start', { form_id: d.formId, form_title: d.formTitle });
    });
    document.addEventListener('fpFormStepComplete', function (e) {
        var d = e.detail || {};
        push('form_step_complete', { form_id: d.formId, step: d.step });
    });
    document.addEventListener('fpFormAbandon', function (e) {
        var d = e.detail || {};
        push('form_abandon', {
            form_id:            d.formId,
            form_title:         d.formTitle,
            time_spent_seconds: d.timeSpent       || 0,
            fields_filled:      d.fieldsFilledCount || 0,
        });
    });

    // FP-Bio-Standalone: link click
    document.addEventListener('fpBioLinkClick', function (e) {
        var d = e.detail || {};
        var p = {
            bio_link_label:    d.label       || '',
            bio_link_url:      d.url         || '',
            bio_link_category: d.category    || '',
        };
        if (d.eventId) {
            p.event_id = d.eventId;
        }
        push('bio_link_click', p);
    });

    // FP-CTA-Bar: link/bar click (category da data-fp-track-category quando «Traccia click» è attivo)
    document.addEventListener('fpCtaBarClick', function (e) {
        var d = e.detail || {};
        var rawName = d.eventName && String(d.eventName).trim();
        var evName = rawName && /^[a-z0-9_]{1,40}$/i.test(rawName) ? rawName.toLowerCase() : 'cta_bar_click';
        var p = {
            cta_label:     d.label    || '',
            cta_action:    d.action   || d.url || '',
            cta_url:       d.url      || '',
            cta_category:  d.category || '',
        };
        if (d.eventId) {
            p.event_id = d.eventId;
        }
        push(evName, p);
    });

    // Generic FP tracking bridge
    document.addEventListener('fpTrackingEvent', function (e) {
        var d = e.detail || {};
        if (d.event) push(d.event, d.params || {});
    });

    // =========================================================================
    // 8. WOOCOMMERCE ADD TO CART (AJAX — jQuery bridge)
    // =========================================================================

    if (window.jQuery) {
        jQuery(document.body).on('added_to_cart', function (e, fragments, cart_hash, button) {
            var productId   = button ? button.data('product_id') : '';
            var productName = button ? button.closest('.product').find('.woocommerce-loop-product__title').text() : '';
            push('add_to_cart', {
                items: [{
                    item_id:   String(productId),
                    item_name: productName,
                    quantity:  1,
                }],
            });
        });
    }

})();
