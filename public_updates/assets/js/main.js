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
    document.querySelectorAll('.post-card-banner img, .update-banner-wrapper img').forEach(function(img) {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            var parent = this.closest('.post-card-banner') || this.closest('.update-banner-wrapper');
            if (parent) {
                parent.classList.add('banner-fallback');
                if (!parent.querySelector('.banner-placeholder-icon')) {
                    var icon = document.createElement('div');
                    icon.className = 'banner-placeholder-icon';
                    icon.innerHTML = '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
                    parent.appendChild(icon);
                }
            }
        });
    });
});
