(function () {
    'use strict';

    const cfg = window.APP_SESSION_CONFIG || null;

    if (!cfg || !cfg.authenticated) {
        return;
    }

    window.APP_SESSION_MONITOR_LOADED = true;

    let serverOffsetMs = (Number(cfg.serverNow || 0) * 1000) - Date.now();
    let expiresAtMs = Number(cfg.expiresAt || 0) * 1000;
    let maxExpiresAtMs = Number(cfg.maxExpiresAt || 0) * 1000;

    const warningMs = Math.max(0, Number(cfg.warningSeconds || 0) * 1000);
    const heartbeatMs = Math.max(15000, Number(cfg.heartbeatSeconds || 60) * 1000);

    let lastHeartbeatAt = 0;
    let heartbeatPending = false;
    let activityPending = false;
    let warningOpen = false;
    let redirecting = false;
    let maximumWarningAcknowledged = false;

    function nowServerMs() {
        return Date.now() + serverOffsetMs;
    }

    function effectiveExpiryMs() {
        if (maxExpiresAtMs > 0) {
            return Math.min(expiresAtMs, maxExpiresAtMs);
        }

        return expiresAtMs;
    }

    function limitedByMaximum() {
        return maxExpiresAtMs > 0 && maxExpiresAtMs <= expiresAtMs;
    }

    function formatRemaining(milliseconds) {
        const totalSeconds = Math.max(0, Math.ceil(milliseconds / 1000));
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${minutes}:${String(seconds).padStart(2, '0')}`;
    }

    function resolveUrl(url) {
        try {
            return new URL(String(url || ''), window.location.href).href;
        } catch (error) {
            return String(url || '');
        }
    }

    function redirectToLogin(reason) {
        if (redirecting) {
            return;
        }

        redirecting = true;

        let loginUrl = String(cfg.loginUrl || 'login.php?expired=inactivity');
        if (reason === 'maximum') {
            loginUrl = loginUrl.replace('expired=inactivity', 'expired=maximum');
        }

        window.location.replace(resolveUrl(loginUrl));
    }

    async function keepAlive(force) {
        const now = Date.now();

        if (heartbeatPending) {
            return false;
        }

        if (!force && !activityPending) {
            return true;
        }

        if (!force && lastHeartbeatAt > 0 && (now - lastHeartbeatAt) < heartbeatMs) {
            return true;
        }

        heartbeatPending = true;
        lastHeartbeatAt = now;

        try {
            const response = await fetch(resolveUrl(cfg.keepAliveUrl || 'mantener_sesion.php'), {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Session-Activity': '1'
                }
            });

            const raw = await response.text();
            let data = {};

            try {
                data = raw ? JSON.parse(raw) : {};
            } catch (error) {
                data = {};
            }

            if (response.status === 401 || data.expired === true || data.success === false) {
                redirectToLogin(data.reason || 'inactivity');
                return false;
            }

            if (!response.ok || data.success !== true) {
                return false;
            }

            activityPending = false;

            if (Number(data.server_now || 0) > 0) {
                serverOffsetMs = (Number(data.server_now) * 1000) - Date.now();
            }

            if (Number(data.expires_at || 0) > 0) {
                expiresAtMs = Number(data.expires_at) * 1000;
            }

            maxExpiresAtMs = Number(data.max_expires_at || 0) * 1000;
            return true;
        } catch (error) {
            return false;
        } finally {
            heartbeatPending = false;
        }
    }

    function ensureSweetAlert() {
        if (window.Swal) {
            return Promise.resolve(window.Swal);
        }

        return new Promise(function (resolve, reject) {
            const existing = document.querySelector('script[data-session-swal="1"]');

            if (existing) {
                existing.addEventListener('load', function () {
                    resolve(window.Swal);
                }, { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
            script.async = true;
            script.dataset.sessionSwal = '1';
            script.onload = function () {
                resolve(window.Swal);
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    async function showWarning() {
        if (warningOpen || redirecting || cfg.warningEnabled !== true) {
            return;
        }

        if (limitedByMaximum() && maximumWarningAcknowledged) {
            return;
        }

        warningOpen = true;

        const maxLimited = limitedByMaximum();
        const title = maxLimited
            ? 'Duración máxima por terminar'
            : 'Sesión por expirar';
        const description = maxLimited
            ? 'La duración máxima de esta sesión está por concluir. Guarda cualquier cambio pendiente.'
            : 'No se ha detectado actividad reciente. Puedes continuar trabajando o cerrar sesión.';

        try {
            await ensureSweetAlert();

            const result = await window.Swal.fire({
                icon: 'warning',
                title: title,
                html: `
                    <div style="font-size:.9rem;color:#475569;line-height:1.5;">
                        ${description}
                    </div>
                    <div id="sessionCountdown" style="margin:18px auto 4px;font-size:2rem;font-weight:900;color:#f97316;">
                        ${formatRemaining(effectiveExpiryMs() - nowServerMs())}
                    </div>
                    <small style="color:#94a3b8;">Tiempo restante</small>
                `,
                showCancelButton: true,
                confirmButtonText: maxLimited ? 'Entendido' : 'Seguir aquí',
                cancelButtonText: 'Cerrar sesión',
                confirmButtonColor: '#f97316',
                cancelButtonColor: '#64748b',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    const countdown = document.getElementById('sessionCountdown');
                    const updateCountdown = function () {
                        const remaining = effectiveExpiryMs() - nowServerMs();

                        if (countdown) {
                            countdown.textContent = formatRemaining(remaining);
                        }

                        if (remaining <= 0) {
                            redirectToLogin(limitedByMaximum() ? 'maximum' : 'inactivity');
                        }
                    };

                    updateCountdown();
                    window.__sessionCountdownInterval = window.setInterval(updateCountdown, 1000);
                },
                willClose: function () {
                    if (window.__sessionCountdownInterval) {
                        window.clearInterval(window.__sessionCountdownInterval);
                        window.__sessionCountdownInterval = null;
                    }
                }
            });

            if (result.isDismissed) {
                window.location.href = resolveUrl(cfg.logoutUrl || 'logout.php');
                return;
            }

            if (maxLimited && result.isConfirmed) {
                maximumWarningAcknowledged = true;
            }

            if (!maxLimited && result.isConfirmed) {
                activityPending = true;
                const renewed = await keepAlive(true);

                if (renewed) {
                    await window.Swal.fire({
                        icon: 'success',
                        title: 'Sesión renovada',
                        text: 'Puedes continuar trabajando.',
                        timer: 1300,
                        showConfirmButton: false,
                        timerProgressBar: true
                    });
                }
            }
        } catch (error) {
            const continueSession = maxLimited
                ? window.confirm(`${title}. ${description}`)
                : window.confirm(`${title}. ${description}\n\nPresiona Aceptar para continuar.`);

            if (!continueSession) {
                window.location.href = resolveUrl(cfg.logoutUrl || 'logout.php');
            } else if (!maxLimited) {
                activityPending = true;
                await keepAlive(true);
            }
        } finally {
            warningOpen = false;
        }
    }

    function registerActivity() {
        if (warningOpen || redirecting) {
            return;
        }

        activityPending = true;
        keepAlive(false);
    }

    ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(function (eventName) {
        document.addEventListener(eventName, registerActivity, {
            passive: eventName !== 'keydown',
            capture: true
        });
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            activityPending = true;
            keepAlive(false);
        }
    });

    function verifySessionClock() {
        if (redirecting) {
            return;
        }

        const remaining = effectiveExpiryMs() - nowServerMs();

        if (remaining <= 0) {
            redirectToLogin(limitedByMaximum() ? 'maximum' : 'inactivity');
            return;
        }

        if (cfg.warningEnabled === true && warningMs > 0 && remaining <= warningMs) {
            showWarning();
        }

        if (activityPending && (lastHeartbeatAt === 0 || (Date.now() - lastHeartbeatAt) >= heartbeatMs)) {
            keepAlive(false);
        }
    }

    verifySessionClock();
    window.setInterval(verifySessionClock, 1000);
})();
