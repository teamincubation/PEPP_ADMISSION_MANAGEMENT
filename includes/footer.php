<?php
function renderFooter() {
?>
            </div>
        </main>
    </div>
    
    <footer class="admin-footer">
        <div class="footer-content">
            <div class="footer-left">
                <span>Website designed by <strong>Adnan Vellicheri</strong> | Copyright <strong>labinc education Pvt Ltd</strong></span>
            </div>
            <div class="footer-right">
                <span>Crafted in India with <i class="fas fa-heart text-red-500"></i></span>
            </div>
        </div>
    </footer>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.admin-layout').classList.toggle('sidebar-collapsed');
        }
        
        // Auto-hide notifications after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>
<?php
}
?>
