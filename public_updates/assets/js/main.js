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
        });
    });
});
