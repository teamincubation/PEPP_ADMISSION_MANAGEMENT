/**
 * PEPP Updates Public Portal — Lightweight JavaScript
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
        if (geoChoice === 'granted' && navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                sendLocationData('granted', pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
            }, function() {}, { timeout: 10000, maximumAge: 3600000 });
        } else if (geoChoice === null && navigator.geolocation) {
            // Show subtle non-intrusive prompt after 3 seconds of browsing
            setTimeout(function() {
                showLocationPrompt();
            }, 3000);
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
        banner.style.cssText = 'position:fixed;bottom:20px;right:20px;max-width:340px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.12);padding:14px 18px;z-index:9999;font-family:inherit;display:flex;flex-direction:column;gap:10px;animation:peppSlideUp 0.3s ease;';
        banner.innerHTML = '<div style="display:flex;gap:10px;align-items:flex-start;">' +
                           '<div style="font-size:1.2rem;line-height:1;">📍</div>' +
                           '<div style="font-size:0.83rem;color:#334155;line-height:1.45;">' +
                           '<strong>Local Exam & Admission Alerts</strong><br>' +
                           'Share approximate location for district-specific updates?' +
                           '</div>' +
                           '</div>' +
                           '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:2px;">' +
                           '<button type="button" id="locLaterBtn" style="background:transparent;border:none;color:#64748b;font-size:0.8rem;font-weight:600;padding:5px 10px;cursor:pointer;">Not now</button>' +
                           '<button type="button" id="locAllowBtn" style="background:#0284c7;color:#ffffff;border:none;border-radius:6px;font-size:0.8rem;font-weight:600;padding:5px 14px;cursor:pointer;">Allow</button>' +
                           '</div>';
        document.body.appendChild(banner);

        document.getElementById('locLaterBtn').addEventListener('click', function() {
            localStorage.setItem('pepp_geo_choice', 'dismissed');
            banner.remove();
            sendLocationData('denied', null, null, null);
        });

        document.getElementById('locAllowBtn').addEventListener('click', function() {
            banner.remove();
            navigator.geolocation.getCurrentPosition(function(pos) {
                localStorage.setItem('pepp_geo_choice', 'granted');
                sendLocationData('granted', pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
                showToast('Location enabled for regional alerts!');
            }, function(err) {
                localStorage.setItem('pepp_geo_choice', 'denied');
                sendLocationData('denied', null, null, null);
            }, { timeout: 8000, maximumAge: 3600000 });
        });
    }
});
