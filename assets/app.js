/**
 * Guest Wi-Fi portal - front-end flow.
 *
 *   step 1  mobile number  -> api.php?action=send_otp
 *   step 2  OTP            -> api.php?action=verify_otp
 *   step 3  connected      -> optional POST of credentials to the firewall
 *
 * No build step and no dependencies: the file can be dropped onto a
 * firewall's own web root as-is.
 */
(function () {
    'use strict';

    var API = window.GW_API_BASE || 'api.php';

    var el = function (id) { return document.getElementById(id); };

    var ui = {
        alert:      el('alert'),
        ssidLine:   el('ssid-line'),
        formMobile: el('form-mobile'),
        formOtp:    el('form-otp'),
        mobile:     el('mobile'),
        otp:        el('otp'),
        btnSend:    el('btn-send'),
        btnVerify:  el('btn-verify'),
        btnResend:  el('btn-resend'),
        btnChange:  el('btn-change'),
        btnLogout:  el('btn-logout'),
        btnContinue: el('btn-continue'),
        masked:     el('masked-mobile'),
        otpExpiry:  el('otp-expiry'),
        factMobile: el('fact-mobile'),
        factExpiry: el('fact-expiry'),
        factLeft:   el('fact-remaining'),
        handoff:    el('handoff-form')
    };

    var state = {
        mobile: '',
        otpLength: 6,
        otpExpiresAt: 0,     // epoch seconds, client clock
        resendAt: 0,
        sessionLeft: 0,
        busy: false,
        handoffUrl: '#'
    };

    /* ------------------------------------------------------------ */
    /* Device identity                                               */
    /* ------------------------------------------------------------ */

    // Used only when the firewall does not pass the client MAC (local
    // testing, or a cross-origin page whose cookies we never receive).
    function deviceId() {
        var key = 'gw_device_id';
        var id;
        try {
            id = localStorage.getItem(key);
        } catch (e) {
            id = null;
        }
        if (!id || !/^[a-f0-9]{32}$/.test(id)) {
            var bytes = new Uint8Array(16);
            (window.crypto || window.msCrypto).getRandomValues(bytes);
            id = Array.prototype.map.call(bytes, function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('');
            try { localStorage.setItem(key, id); } catch (e) { /* private mode */ }
        }
        return id;
    }

    // Whatever the firewall appended to the landing URL (magic, usermac,
    // post, 4Tredir, ...). Captured once and replayed on every call so a
    // reload mid-flow does not lose the captive-portal context.
    var portalParams = (function () {
        var out = {};
        var query = window.location.search.replace(/^\?/, '');
        if (!query) { return out; }
        query.split('&').forEach(function (pair) {
            if (!pair) { return; }
            var bits = pair.split('=');
            var key = decodeURIComponent(bits[0] || '').trim();
            if (key && key !== 'action') {
                out[key] = decodeURIComponent((bits[1] || '').replace(/\+/g, ' '));
            }
        });
        return out;
    })();

    /* ------------------------------------------------------------ */
    /* Transport                                                     */
    /* ------------------------------------------------------------ */

    function api(action, data) {
        var body = {};
        Object.keys(portalParams).forEach(function (k) { body[k] = portalParams[k]; });
        Object.keys(data || {}).forEach(function (k) { body[k] = data[k]; });
        body.device_id = deviceId();

        return fetch(API + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            cache: 'no-store',
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return {
                        success: false,
                        code: 'bad_response',
                        message: 'The portal server returned an unexpected response.'
                    };
                }
            });
        }).catch(function () {
            return {
                success: false,
                code: 'network',
                message: 'Cannot reach the portal server. Check your Wi-Fi connection and try again.'
            };
        });
    }

    /* ------------------------------------------------------------ */
    /* View helpers                                                  */
    /* ------------------------------------------------------------ */

    function showStep(name) {
        ['mobile', 'otp', 'connected'].forEach(function (s) {
            el('step-' + s).classList.toggle('is-active', s === name);
        });
        if (name === 'mobile') { setTimeout(function () { ui.mobile.focus(); }, 60); }
        if (name === 'otp')    { setTimeout(function () { ui.otp.focus(); }, 60); }
    }

    function setAlert(message, kind) {
        ui.alert.textContent = message || '';
        ui.alert.className = 'alert' + (message ? ' alert-' + (kind || 'info') : '');
    }

    function setBusy(button, busy) {
        state.busy = busy;
        if (!button) { return; }
        button.disabled = busy;
        button.classList.toggle('is-busy', busy);
    }

    function mmss(seconds) {
        seconds = Math.max(0, Math.round(seconds));
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    // 'YYYY-MM-DD HH:MM:SS' -> 'HH:MM' without letting the browser guess
    // a timezone for a server-local timestamp.
    function clockOf(datetime) {
        var m = /(\d{2}):(\d{2})/.exec(datetime || '');
        return m ? m[1] + ':' + m[2] : '--:--';
    }

    /* ------------------------------------------------------------ */
    /* Countdowns                                                    */
    /* ------------------------------------------------------------ */

    setInterval(tick, 1000);

    function tick() {
        var now = Date.now() / 1000;

        if (state.otpExpiresAt) {
            var left = state.otpExpiresAt - now;
            if (left > 0) {
                ui.otpExpiry.textContent = 'Valid for ' + mmss(left) + '.';
            } else {
                ui.otpExpiry.textContent = 'This OTP has expired.';
                state.otpExpiresAt = 0;
                state.resendAt = 0;
            }
        }

        if (state.resendAt) {
            var wait = state.resendAt - now;
            if (wait > 0) {
                ui.btnResend.disabled = true;
                ui.btnResend.textContent = 'Resend in ' + mmss(wait);
            } else {
                state.resendAt = 0;
                ui.btnResend.disabled = false;
                ui.btnResend.textContent = 'Resend OTP';
            }
        }

        if (state.sessionLeft > 0) {
            state.sessionLeft -= 1;
            ui.factLeft.textContent = mmss(state.sessionLeft);
            if (state.sessionLeft === 0) {
                setAlert('Your session has ended. Verify your number again to reconnect.', 'warn');
                showStep('mobile');
            }
        }
    }

    function startResendCooldown(seconds) {
        state.resendAt = (Date.now() / 1000) + (seconds || 60);
    }

    /* ------------------------------------------------------------ */
    /* Connected view                                                */
    /* ------------------------------------------------------------ */

    function showConnected(session, handoff) {
        if (session) {
            ui.factMobile.textContent = session.mobile || '-';
            ui.factExpiry.textContent = clockOf(session.expires_at);
            state.sessionLeft = session.seconds_left || 0;
            ui.factLeft.textContent = mmss(state.sessionLeft);
        }

        state.otpExpiresAt = 0;
        state.resendAt = 0;
        showStep('connected');

        if (handoff && handoff.action === 'form_post') {
            setAlert('Opening your internet session...', 'info');
            submitHandoff(handoff);
            return;
        }

        if (handoff && handoff.url) {
            state.handoffUrl = handoff.url;
            ui.btnContinue.href = handoff.url;
        }
    }

    /**
     * FortiGate's external-portal flow: the browser itself must POST the
     * credentials back to the firewall, because the firewall recognises the
     * session by the client's own connection, not by ours.
     */
    function submitHandoff(handoff) {
        var form = ui.handoff;
        form.innerHTML = '';
        form.action = handoff.url;
        form.method = 'post';

        Object.keys(handoff.fields || {}).forEach(function (name) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = handoff.fields[name];
            form.appendChild(input);
        });

        setTimeout(function () { form.submit(); }, 700);
    }

    /* ------------------------------------------------------------ */
    /* Actions                                                       */
    /* ------------------------------------------------------------ */

    function sendOtp(resend) {
        var mobile = ui.mobile.value.replace(/\D/g, '');

        if (!/^[6-9][0-9]{9}$/.test(mobile)) {
            setAlert('Enter a valid 10-digit mobile number.', 'error');
            ui.mobile.focus();
            return;
        }

        var button = resend ? ui.btnResend : ui.btnSend;
        setBusy(button, true);
        setAlert('');

        api('send_otp', { mobile: mobile }).then(function (res) {
            setBusy(button, false);

            if (res.success) {
                state.mobile = mobile;
                state.otpLength = res.otp_length || 6;
                state.otpExpiresAt = (Date.now() / 1000) + (res.expires_in || 300);
                ui.otp.maxLength = state.otpLength;
                ui.otp.placeholder = new Array(state.otpLength + 1).join('-');
                ui.masked.textContent = '+91 ' + (res.masked_mobile || mobile);
                ui.otp.value = '';

                startResendCooldown(res.resend_after);
                showStep('otp');
                setAlert(res.message, 'ok');
                return;
            }

            if (res.code === 'already_connected') {
                showConnected(res.session, null);
                setAlert(res.message, 'info');
                return;
            }

            if (res.code === 'cooldown' && res.retry_after) {
                startResendCooldown(res.retry_after);
            }

            setAlert(res.message, 'error');
        });
    }

    function verifyOtp() {
        var otp = ui.otp.value.replace(/\D/g, '');

        if (otp.length !== state.otpLength) {
            setAlert('Enter the ' + state.otpLength + '-digit OTP from your SMS.', 'error');
            return;
        }

        setBusy(ui.btnVerify, true);
        setAlert('');

        api('verify_otp', { mobile: state.mobile, otp: otp }).then(function (res) {
            setBusy(ui.btnVerify, false);

            if (res.success) {
                showConnected(res.session, res.handoff);
                return;
            }

            ui.otp.value = '';
            ui.otp.focus();
            setAlert(res.message, 'error');

            // These leave nothing to retry on this screen.
            if (res.code === 'otp_expired' || res.code === 'no_pending_otp' ||
                res.code === 'too_many_attempts' || res.code === 'otp_already_used') {
                state.otpExpiresAt = 0;
                state.resendAt = 0;
                ui.btnResend.disabled = false;
                ui.btnResend.textContent = 'Resend OTP';
            }
        });
    }

    function logout() {
        setBusy(ui.btnLogout, true);

        api('logout', {}).then(function (res) {
            setBusy(ui.btnLogout, false);
            state.sessionLeft = 0;
            ui.mobile.value = '';
            ui.otp.value = '';
            showStep('mobile');
            setAlert(res.message || 'Disconnected.', 'info');
        });
    }

    /* ------------------------------------------------------------ */
    /* Wiring                                                        */
    /* ------------------------------------------------------------ */

    ui.mobile.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
    });

    ui.otp.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, state.otpLength);

        // Most phones fill the code in one go; verify without another tap.
        if (this.value.length === state.otpLength && !state.busy) {
            verifyOtp();
        }
    });

    ui.formMobile.addEventListener('submit', function (e) {
        e.preventDefault();
        sendOtp(false);
    });

    ui.formOtp.addEventListener('submit', function (e) {
        e.preventDefault();
        verifyOtp();
    });

    ui.btnResend.addEventListener('click', function () {
        if (!ui.btnResend.disabled) { sendOtp(true); }
    });

    ui.btnChange.addEventListener('click', function () {
        state.otpExpiresAt = 0;
        ui.otp.value = '';
        setAlert('');
        showStep('mobile');
    });

    ui.btnLogout.addEventListener('click', logout);

    ui.btnContinue.addEventListener('click', function (e) {
        if (state.handoffUrl === '#') { e.preventDefault(); }
    });

    /* ------------------------------------------------------------ */
    /* Boot                                                          */
    /* ------------------------------------------------------------ */

    api('init', {}).then(function (res) {
        if (!res.success) {
            setAlert(res.message, res.code === 'setup_required' ? 'warn' : 'error');
            return;
        }

        state.otpLength = res.otp_length || 6;
        ui.otp.maxLength = state.otpLength;
        ui.otp.placeholder = new Array(state.otpLength + 1).join('-');

        if (res.ssid) {
            ui.ssidLine.textContent = 'Connected to ' + res.ssid + ' · verify your mobile number to get online';
        }

        if (res.connected && res.session) {
            showConnected(res.session, null);
            setAlert('This device is already online.', 'ok');
        } else {
            ui.mobile.focus();
        }
    });

})();
