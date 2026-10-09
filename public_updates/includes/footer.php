<?php
/**
 * PEPP Updates Public Portal — Footer Component
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    require_once __DIR__ . '/bootstrap.php';
}
?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <h4>PEPP Updates</h4>
                    <p>Official career alerts, entrance exam notifications, admission announcements, and academic updates powered by PEPP Learning.</p>
                </div>

                <div class="footer-col">
                    <h5>Quick Links</h5>
                    <ul>
                        <li><a href="/">Home</a></li>
                        <li><a href="/categories">All Categories</a></li>
                        <li><a href="/search">Search Updates</a></li>
                        <li><a href="/subscribe">Get WhatsApp Updates</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h5>Legal & Privacy</h5>
                    <ul>
                        <li><a href="/privacy">Privacy Policy</a></li>
                        <li><a href="/terms">Terms of Service</a></li>
                        <li><a href="https://pepplearning.in" target="_blank" rel="noopener noreferrer">PEPP Learning Website</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div>&copy; <?php echo date('Y'); ?> PEPP Learning. All rights reserved.</div>
                <div>PEPP Updates is an official notification portal of PEPP Learning.</div>
            </div>
        </div>
    </footer>

    <!-- Portal JavaScript -->
    <script src="/assets/js/main.js" defer></script>
</body>
</html>
