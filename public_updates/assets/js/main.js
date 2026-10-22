/**
 * PEPP Updates Public Portal - Lightweight JavaScript
 * Zero external dependencies. Accessible, fast, mobile-friendly.
 */
document.addEventListener('DOMContentLoaded', function() {
    // 1. Mobile Menu Toggle
    var menuToggle = document.getElementById('mobileMenuToggle');
    var navMenu = document.getElementById('primaryNav');

    if (menuToggle && navMenu) {
        menuToggle.addEventListener('click', function() {
            var expanded = menuToggle.getAttribute('aria-expanded') === 'true';
            menuToggle.setAttribute('aria-expanded', !expanded);
            navMenu.classList.toggle('is-open');
            navMenu.classList.toggle('nav-open');
            document.body.classList.toggle('menu-open');
        });

        // Close on link click inside menu
        navMenu.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                menuToggle.setAttribute('aria-expanded', 'false');
                navMenu.classList.remove('is-open');
                navMenu.classList.remove('nav-open');
                document.body.classList.remove('menu-open');
            });
        });
    }

    // 2. Copy Link Button Handler
    var copyButtons = document.querySelectorAll('[data-copy-link]');
    copyButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var url = btn.getAttribute('data-copy-link') || window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    showToast('Link copied to clipboard!');
                }).catch(function() {
                    fallbackCopyText(url);
                });
            } else {
                fallbackCopyText(url);
            }
        });
    });

    function fallbackCopyText(text) {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.top = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showToast('Link copied to clipboard!');
        } catch (err) {
            showToast('Press Ctrl+C to copy link');
        }
        document.body.removeChild(textArea);
    }

    function showToast(message) {
        var existing = document.getElementById('peppToast');
        if (existing) {
            existing.remove();
        }
        var toast = document.createElement('div');
        toast.id = 'peppToast';
        toast.className = 'pepp-toast';
        toast.setAttribute('role', 'alert');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.classList.add('visible');
        }, 10);
        setTimeout(function() {
            toast.classList.remove('visible');
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }

    // 3. Fallback for broken banner images
    document.querySelectorAll('.card-media img, .article-banner-wrap img, .post-card-banner img, .update-banner-wrapper img').forEach(function(img) {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            var parent = this.closest('.card-media') || this.closest('.article-banner-wrap') || this.closest('.post-card-banner') || this.closest('.update-banner-wrapper');
            if (parent) {
                parent.classList.add('banner-fallback');
                if (!parent.querySelector('.card-media-placeholder') && !parent.querySelector('.banner-placeholder-icon')) {
                    var icon = document.createElement('div');
                    icon.className = 'card-media-placeholder';
                    icon.innerHTML = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span>PEPP Updates</span>';
                    parent.appendChild(icon);
                }
            }
        });
    });

    // 4. Click interaction tracking (Section D Analytics)
    document.querySelectorAll('[data-track-click]').forEach(function(el) {
        el.addEventListener('click', function() {
            var actionName = el.getAttribute('data-track-click') || 'click';
            var postId = el.getAttribute('data-post-id') || '';
            var targetUrl = el.getAttribute('data-target-url') || el.getAttribute('href') || '';
            var btnName = el.getAttribute('data-btn-name') || el.textContent.trim();

            var payload = 'action=click&action_name=' + encodeURIComponent(actionName) +
                          '&post_id=' + encodeURIComponent(postId) +
                          '&target_url=' + encodeURIComponent(targetUrl) +
                          '&button_name=' + encodeURIComponent(btnName);

            if (navigator.sendBeacon) {
                var formData = new FormData();
                formData.append('action', 'click');
                formData.append('action_name', actionName);
                if (postId) formData.append('post_id', postId);
                formData.append('target_url', targetUrl);
                formData.append('button_name', btnName);
                navigator.sendBeacon('/track.php', formData);
            } else {
                fetch('/track.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload,
                    keepalive: true
                }).catch(function() {});
            }
        });
    });

    // 5. Voluntary Region / Geolocation Analytics (Section D Privacy-First)
    try {
        var geoChoice = localStorage.getItem('pepp_geo_choice');
        var isSingleUpdate = document.querySelector('.single-update') !== null || document.querySelector('.article-header') !== null;

        if (geoChoice === 'granted' && navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                sendLocationData('granted', pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
            }, function() {}, { timeout: 10000, maximumAge: 3600000 });
        } else if ((geoChoice === null || geoChoice === '') && navigator.geolocation) {
            // Show subtle non-intrusive prompt: 500ms on individual update page, 2000ms on other pages
            var promptDelay = isSingleUpdate ? 500 : 2000;
            setTimeout(function() {
                showLocationPrompt();
            }, promptDelay);
        }
    } catch (e) {}

    function sendLocationData(status, lat, lng, acc) {
        var data = 'action=location&status=' + encodeURIComponent(status) +
                   (lat ? ('&lat=' + encodeURIComponent(lat)) : '') +
                   (lng ? ('&lng=' + encodeURIComponent(lng)) : '') +
                   (acc ? ('&accuracy=' + encodeURIComponent(acc)) : '');

        if (navigator.sendBeacon) {
            var formData = new FormData();
            formData.append('action', 'location');
            formData.append('status', status);
            if (lat) formData.append('lat', lat);
            if (lng) formData.append('lng', lng);
            if (acc) formData.append('accuracy', acc);
            navigator.sendBeacon('/track.php', formData);
        } else {
            fetch('/track.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data,
                keepalive: true
            }).catch(function() {});
        }
    }

    function showLocationPrompt() {
        if (document.getElementById('peppLocationPrompt') || localStorage.getItem('pepp_geo_choice')) return;
        var banner = document.createElement('div');
        banner.id = 'peppLocationPrompt';
        banner.className = 'pepp-location-prompt';
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', 'Location Permission Prompt');
        banner.style.cssText = 'position:fixed;bottom:20px;right:20px;max-width:340px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.12);padding:14px 18px;z-index:9999;font-family:inherit;display:flex;flex-direction:column;gap:10px;animation:peppSlideUp 0.3s ease;';
        banner.innerHTML = '<div style="display:flex;gap:10px;align-items:flex-start;">' +
                           '<div style="font-size:1.2rem;line-height:1;">📍</div>' +
                           '<div style="font-size:0.83rem;color:#334155;line-height:1.45;">' +
                           '<strong style="color:#0f172a;">Local Exam & Admission Alerts</strong><br>' +
                           'Share approximate location for district-specific updates?' +
                           '</div>' +
                           '</div>' +
                           '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:2px;">' +
                           '<button type="button" id="locLaterBtn" style="background:transparent;border:none;color:#64748b;font-size:0.8rem;font-weight:600;padding:5px 10px;cursor:pointer;border-radius:6px;">Not now</button>' +
                           '<button type="button" id="locAllowBtn" style="background:#0284c7;color:#ffffff;border:none;border-radius:6px;font-size:0.8rem;font-weight:600;padding:5px 14px;cursor:pointer;">Allow</button>' +
                           '</div>';
        document.body.appendChild(banner);

        var laterBtn = document.getElementById('locLaterBtn');
        if (laterBtn) {
            laterBtn.addEventListener('click', function() {
                localStorage.setItem('pepp_geo_choice', 'denied');
                banner.remove();
                sendLocationData('denied', null, null, null);
            });
        }

        var allowBtn = document.getElementById('locAllowBtn');
        if (allowBtn) {
            allowBtn.addEventListener('click', function() {
                banner.remove();
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(pos) {
                        localStorage.setItem('pepp_geo_choice', 'granted');
                        sendLocationData('granted', pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
                        showToast('Location enabled for regional alerts!');
                    }, function(err) {
                        localStorage.setItem('pepp_geo_choice', 'denied');
                        sendLocationData('denied', null, null, null);
                    }, { timeout: 10000, maximumAge: 3600000 });
                } else {
                    localStorage.setItem('pepp_geo_choice', 'denied');
                }
            });
        }
    }

    // 6. PEPP Updates — Phase 3: Popup Ad Controller
    (function initPopupAd() {
        var modalRoot = document.getElementById('pepp-ad-modal-root');
        if (!modalRoot) return;

        // Never trigger popup for automated test runners / headless bots
        if (navigator.webdriver) return;

        var adId = modalRoot.getAttribute('data-ad-id');
        if (!adId) return;

        // Privacy-conscious session check: do not show repeatedly in the same session
        var sessionKey = 'pepp_ad_seen_' + adId;
        try {
            if (sessionStorage.getItem(sessionKey)) {
                return;
            }
        } catch (e) {}

        var rawDelay = parseInt(modalRoot.getAttribute('data-delay') || '15', 10);
        var targetDelaySec = Math.max(1, Math.min(600, isNaN(rawDelay) ? 15 : rawDelay));
        var targetDelayMs = targetDelaySec * 1000;

        var elapsedMs = 0;
        var lastTime = Date.now();
        var timerId = null;
        var isModalOpen = false;
        var lastFocusedElement = null;

        var dialogEl = modalRoot.querySelector('.pepp-ad-dialog');
        var formEl = document.getElementById('pepp-ad-form');
        var alertEl = document.getElementById('pepp-ad-alert');
        var submitBtn = document.getElementById('pepp-ad-submit-btn');

        function openModal() {
            if (isModalOpen) return;
            isModalOpen = true;
            lastFocusedElement = document.activeElement;

            modalRoot.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Accessibility: Focus first input or dialog container
            setTimeout(function() {
                var firstInput = modalRoot.querySelector('input:not([type="hidden"]), select, textarea, button');
                if (firstInput) {
                    firstInput.focus();
                } else if (dialogEl) {
                    dialogEl.focus();
                }
            }, 50);
        }

        function closeModal() {
            if (!isModalOpen) return;
            isModalOpen = false;
            modalRoot.style.display = 'none';
            document.body.style.overflow = '';

            try {
                sessionStorage.setItem(sessionKey, '1');
            } catch (e) {}

            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        // Visibility-Aware Delay Timer
        // Time only elapses while the tab/page is visible (document.hidden === false)
        timerId = setInterval(function() {
            var now = Date.now();
            var delta = now - lastTime;
            lastTime = now;

            if (!document.hidden) {
                elapsedMs += delta;
                if (elapsedMs >= targetDelayMs) {
                    clearInterval(timerId);
                    openModal();
                }
            }
        }, 200);

        // Click on close button or backdrop
        modalRoot.addEventListener('click', function(e) {
            var target = e.target;
            if (target && target.getAttribute('data-action') === 'close') {
                e.preventDefault();
                closeModal();
            }
        });

        // Keyboard accessibility: ESC key to close, TAB key to trap focus
        document.addEventListener('keydown', function(e) {
            if (!isModalOpen) return;

            if (e.key === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                closeModal();
                return;
            }

            if (e.key === 'Tab' || e.keyCode === 9) {
                var focusables = modalRoot.querySelectorAll(
                    'button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                if (focusables.length === 0) return;

                var first = focusables[0];
                var last = focusables[focusables.length - 1];

                if (e.shiftKey) {
                    if (document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    }
                } else {
                    if (document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }
        });

        // AJAX Form Submission
        if (formEl) {
            formEl.addEventListener('submit', function(e) {
                e.preventDefault();

                if (alertEl) {
                    alertEl.style.display = 'none';
                    alertEl.className = 'pepp-ad-alert';
                    alertEl.textContent = '';
                }

                // Client-side quick check
                var isValid = true;
                var requiredInputs = formEl.querySelectorAll('[required]');
                for (var i = 0; i < requiredInputs.length; i++) {
                    var inp = requiredInputs[i];
                    if (!inp.value || inp.value.trim() === '') {
                        isValid = false;
                        inp.focus();
                        if (alertEl) {
                            alertEl.className = 'pepp-ad-alert error';
                            alertEl.textContent = 'Please fill in all required fields.';
                            alertEl.style.display = 'block';
                        }
                        return;
                    }
                }

                // Submit button loading state
                if (submitBtn) {
                    submitBtn.disabled = true;
                    var spinner = submitBtn.querySelector('.spinner');
                    if (spinner) spinner.style.display = 'inline-block';
                }

                var formData = new FormData(formEl);

                fetch('/api/ad-enquiry.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(res) {
                    return res.json().then(function(data) {
                        return { status: res.status, ok: res.ok, data: data };
                    });
                })
                .then(function(result) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        var spinner = submitBtn.querySelector('.spinner');
                        if (spinner) spinner.style.display = 'none';
                    }

                    if (result.ok && result.data && result.data.ok) {
                        if (alertEl) {
                            alertEl.className = 'pepp-ad-alert success';
                            alertEl.textContent = result.data.message || 'Thank you! Your enquiry has been received.';
                            alertEl.style.display = 'block';
                        }
                        formEl.reset();

                        try {
                            sessionStorage.setItem(sessionKey, '1');
                        } catch (e) {}

                        // Auto-close dialog after 2.5 seconds
                        setTimeout(function() {
                            closeModal();
                        }, 2500);
                    } else {
                        var errMsg = (result.data && result.data.error) ? result.data.error : 'Submission failed. Please check your inputs.';
                        if (alertEl) {
                            alertEl.className = 'pepp-ad-alert error';
                            alertEl.textContent = errMsg;
                            alertEl.style.display = 'block';
                        }
                    }
                })
                .catch(function(err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        var spinner = submitBtn.querySelector('.spinner');
                        if (spinner) spinner.style.display = 'none';
                    }
                    if (alertEl) {
                        alertEl.className = 'pepp-ad-alert error';
                        alertEl.textContent = 'Network error. Please try again.';
                        alertEl.style.display = 'block';
                    }
                });
            });
        }
    })();
});
