// admin/assets/js/admin.js
function initAdminUI() {
    // Theme Toggle
    const themeToggle = document.getElementById('themeToggle');
    const html = document.documentElement;

    if (themeToggle) {
        // Load saved theme
        const savedTheme = localStorage.getItem('admin-theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        updateThemeIcon(savedTheme);

        themeToggle.addEventListener('click', () => {
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';

            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('admin-theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }

    function updateThemeIcon(theme) {
        if (!themeToggle) return;
        const icon = themeToggle.querySelector('i');
        if (icon) {
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }
    }

    // Sidebar Toggle
    const toggleSidebar = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('adminSidebar');
    const mainContent = document.querySelector('.admin-main');

    if (toggleSidebar && sidebar && mainContent) {
        // Load saved state (only for desktop)
        if (window.innerWidth > 992) {
            const isCollapsed = localStorage.getItem('admin-sidebar-collapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('collapsed');
            }
        }

        toggleSidebar.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                // Mobile: toggle full drawer
                sidebar.classList.toggle('show');
            } else {
                // Desktop: toggle mini-sidebar
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('collapsed');
                // Save state
                localStorage.setItem('admin-sidebar-collapsed', sidebar.classList.contains('collapsed'));
            }
        });
    }

    // Mobile Sidebar Close
    const closeSidebar = document.getElementById('closeSidebar');
    if (closeSidebar && sidebar) {
        closeSidebar.addEventListener('click', () => {
            sidebar.classList.remove('show');
        });
    }

    // Dropdowns
    function setupDropdown(btnId, dropdownId) {
        const btn = document.getElementById(btnId);
        const dropdown = document.getElementById(dropdownId);

        if (btn && dropdown) {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                // Close other dropdowns
                document.querySelectorAll('.dropdown-content').forEach(d => {
                    if (d.id !== dropdownId) d.classList.remove('show');
                });
                dropdown.classList.toggle('show');
            });
        }
    }

    setupDropdown('notifBtn', 'notifDropdown');
    setupDropdown('profileBtn', 'profileDropdown');

    // Close dropdowns on click outside
    window.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
    });
}

// Initialize immediately if DOM already loaded, otherwise wait for DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminUI);
} else {
    initAdminUI();
}
