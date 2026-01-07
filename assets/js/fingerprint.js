/**
 * Silent Trust - Frontend Fingerprint Orchestrator
 * Operates invisibly to collect device and behavior data
 */
(function () {
    'use strict';

    // Device type detection
    function detectDevice() {
        const ua = navigator.userAgent;
        const width = window.screen.width;

        if (/mobile|android|iphone|ipod/i.test(ua) || width < 768) {
            return 'mobile';
        } else if (/tablet|ipad/i.test(ua) || (width >= 768 && width < 1024)) {
            return 'tablet';
        } else {
            return 'desktop';
        }
    }

    // Generate device cookie if not present
    function ensureDeviceCookie() {
        const cookieName = 'st_device_id';
        let deviceId = getCookie(cookieName);

        if (!deviceId) {
            deviceId = generateId();
            setCookie(cookieName, deviceId, 30); // 30 days
        }

        return deviceId;
    }

    // Cookie helpers
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
    }

    function setCookie(name, value, days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = `${name}=${value};expires=${date.toUTCString()};path=/;SameSite=Lax`;
    }

    function generateId() {
        return 'st_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
    }

    // Static fingerprint collection
    function collectStaticFingerprint() {
        return {
            user_agent: navigator.userAgent,
            screen_width: window.screen.width,
            screen_height: window.screen.height,
            screen_depth: window.screen.colorDepth,
            timezone_offset: new Date().getTimezoneOffset(),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            language: navigator.language,
            platform: navigator.platform,
            hardware_concurrency: navigator.hardwareConcurrency || 0,
            device_memory: navigator.deviceMemory || 0
        };
    }

    // URL Tracking (Cache-Safe with localStorage)
    function initURLTracking() {
        const currentURL = window.location.href;
        const landingKey = 'st_landing_url';
        const firstURLKey = 'st_first_url';  // NEW: First URL ever (persistent)
        const sessionKey = 'st_session_start';

        // ========== FIRST URL (Persistent - Never Reset) ==========
        let firstURL = localStorage.getItem(firstURLKey);
        if (!firstURL) {
            firstURL = currentURL;
            localStorage.setItem(firstURLKey, firstURL);
        }

        // ========== LANDING URL (Session - 30 minutes) ==========
        let landingURL = localStorage.getItem(landingKey);
        if (!landingURL) {
            landingURL = currentURL;
            localStorage.setItem(landingKey, landingURL);
            localStorage.setItem(sessionKey, Date.now().toString());
        }

        // Check if session expired (30 minutes)
        const sessionStart = parseInt(localStorage.getItem(sessionKey) || '0');
        const sessionAge = Date.now() - sessionStart;
        if (sessionAge > 30 * 60 * 1000) {
            // Reset landing URL for new session
            landingURL = currentURL;
            localStorage.setItem(landingKey, landingURL);
            localStorage.setItem(sessionKey, Date.now().toString());
            // NOTE: first_url is NEVER reset
        }

        return {
            landing_url: landingURL,
            first_url: firstURL,      // NEW: First URL ever visited
            lead_url: currentURL,     // NEW: Current page (where form is submitted)
            current_url: currentURL
        };
    }

    // Extract UTM parameters from URL
    function extractUTMParams() {
        const params = new URLSearchParams(window.location.search);
        return {
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || '',
            utm_term: params.get('utm_term') || '',
            utm_content: params.get('utm_content') || ''
        };
    }

    // Get session duration
    function getSessionDuration() {
        const sessionStart = parseInt(localStorage.getItem('st_session_start') || Date.now());
        return Math.floor((Date.now() - sessionStart) / 1000); // seconds
    }

    // Canvas fingerprint
    function getCanvasHash() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = 200;
            canvas.height = 50;

            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(0, 0, 100, 50);
            ctx.fillStyle = '#069';
            ctx.fillText('Silent Trust', 2, 15);

            const dataURL = canvas.toDataURL();
            return hashString(dataURL);
        } catch (e) {
            return '';
        }
    }

    // WebGL fingerprint
    function getWebGLHash() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

            if (!gl) return '';

            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            const vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL);
            const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);

            return hashString(vendor + renderer);
        } catch (e) {
            return '';
        }
    }

    // Simple hash function
    function hashString(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash).toString(16).padStart(16, '0');
    }

    // Behavior tracking
    class BehaviorTracker {
        constructor(form, deviceType) {
            this.form = form;
            this.deviceType = deviceType;
            this.formOpenTime = Date.now();
            this.fieldCount = form.querySelectorAll('input:not([type=hidden]), textarea, select').length;

            this.mouseEvents = [];
            this.touchEvents = [];
            this.keyEvents = [];
            this.focusEvents = [];

            this.setupListeners();
        }

        setupListeners() {
            if (this.deviceType === 'desktop') {
                this.form.addEventListener('mousemove', this.trackMouse.bind(this), { passive: true });
                this.form.addEventListener('keydown', this.trackKey.bind(this), { passive: true });
            } else {
                this.form.addEventListener('touchstart', this.trackTouch.bind(this), { passive: true });
                this.form.addEventListener('touchmove', this.trackTouch.bind(this), { passive: true });
            }

            this.form.addEventListener('focus', this.trackFocus.bind(this), true);
        }

        trackMouse(e) {
            if (this.mouseEvents.length < 50) {
                this.mouseEvents.push({ x: e.clientX, y: e.clientY, t: Date.now() });
            }
        }

        trackTouch(e) {
            if (this.touchEvents.length < 50 && e.touches.length > 0) {
                const touch = e.touches[0];
                this.touchEvents.push({ x: touch.clientX, y: touch.clientY, t: Date.now() });
            }
        }

        trackKey(e) {
            if (this.keyEvents.length < 100) {
                this.keyEvents.push({ key: e.key, t: Date.now() });
            }
        }

        trackFocus(e) {
            this.focusEvents.push({ target: e.target.name || e.target.id, t: Date.now() });
        }

        getData() {
            const totalTime = (Date.now() - this.formOpenTime) / 1000; // seconds
            const timePerField = this.fieldCount > 0 ? (totalTime * 1000) / this.fieldCount : 0; // ms

            return {
                field_count: this.fieldCount,
                total_time: totalTime,
                time_per_field: timePerField,
                mouse_events: this.mouseEvents.length,
                touch_events: this.touchEvents.length,
                key_count: this.keyEvents.length,
                focus_count: this.focusEvents.length,
                typing_speed: this.calculateTypingSpeed(),
                touch_speed: this.calculateTouchSpeed()
            };
        }

        calculateTypingSpeed() {
            if (this.keyEvents.length < 2) return 0;

            const timeSpan = (this.keyEvents[this.keyEvents.length - 1].t - this.keyEvents[0].t) / 1000;
            const charsPerSecond = this.keyEvents.length / timeSpan;
            return Math.round(charsPerSecond * 60 / 5); // Convert to WPM (assuming 5 chars per word)
        }

        calculateTouchSpeed() {
            if (this.touchEvents.length < 2) return 0;

            const timeSpan = (this.touchEvents[this.touchEvents.length - 1].t - this.touchEvents[0].t) / 1000;
            return this.touchEvents.length / timeSpan; // touches per second
        }
    }

    // Initialize on CF7 forms
    function initializeSilentTrust() {
        const deviceType = detectDevice();
        const deviceCookie = ensureDeviceCookie();
        const staticFingerprint = collectStaticFingerprint();

        console.log('[Silent Trust] Initializing...', { deviceType, deviceCookie });

        // Find all CF7 forms
        const forms = document.querySelectorAll('.wpcf7-form');
        console.log('[Silent Trust] Found ' + forms.length + ' CF7 forms');

        if (forms.length === 0) {
            console.warn('[Silent Trust] No CF7 forms found on page');
            return;
        }

        forms.forEach((form, index) => {
            console.log('[Silent Trust] Initializing form #' + index);
            const tracker = new BehaviorTracker(form, deviceType);

            // On submit, collect all data
            form.addEventListener('submit', function (e) {
                console.log('[Silent Trust] Form submit detected');
                const behaviorData = tracker.getData();
                const urlData = initURLTracking();
                const utmParams = extractUTMParams();

                // Generate fingerprint hash
                const fingerprintHash = hashString(
                    JSON.stringify(staticFingerprint) +
                    getCanvasHash() +
                    getWebGLHash()
                );

                const payload = {
                    device_type: deviceType,
                    device_cookie: deviceCookie,
                    fingerprint_hash: fingerprintHash,
                    canvas_hash: getCanvasHash(),
                    webgl_hash: getWebGLHash(),
                    ...staticFingerprint,
                    ...behaviorData,
                    // URL Tracking Data
                    page_url: urlData.current_url,
                    landing_url: urlData.landing_url,
                    first_url: urlData.first_url,        // NEW: First URL ever
                    lead_url: urlData.lead_url,          // NEW: URL where form submitted
                    referrer_url: document.referrer || '',
                    ...utmParams,
                    session_duration: getSessionDuration()
                };

                console.log('[Silent Trust] Payload generated:', payload);

                // Inject payload into hidden field
                let hiddenField = form.querySelector('input[name="st_payload"]');
                if (!hiddenField) {
                    console.warn('[Silent Trust] Hidden field not found, creating...');
                    hiddenField = document.createElement('input');
                    hiddenField.type = 'hidden';
                    hiddenField.name = 'st_payload';
                    form.appendChild(hiddenField);
                }
                hiddenField.value = JSON.stringify(payload);
                console.log('[Silent Trust] Payload injected successfully');
            });
        });
    }

    // Initialize after DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSilentTrust);
    } else {
        initializeSilentTrust();
    }
})();
