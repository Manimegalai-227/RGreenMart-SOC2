<!-- Mobile Toggle Button -->
<button id="menuBtn" class="md:hidden fixed top-4 left-4 z-50 bg-indigo-800 text-white p-2 rounded focus:outline-none">
    ☰
</button>

<!-- Sidebar -->
<aside id="sidebar" class="w-64 bg-indigo-800 text-white min-h-screen p-6 fixed inset-y-0 left-0 transform -translate-x-full md:translate-x-0 transition-transform duration-300 md:relative md:translate-x-0">
    <h1 class="text-2xl font-bold mb-8">Admin Dashboard</h1>

    <nav class="space-y-4">
        <a href="bundleupload.php" class="block p-3 rounded hover:bg-indigo-700 transition-colors">Bundle Upload</a>
        <a href="Profile.php" class="block p-3 rounded hover:bg-indigo-700 transition-colors">Users</a>

                <!-- ── Items Group ── -->
        <div id="itemssGroup">
            <button onclick="toggleMenu('itemsSubmenu','itemsChevron')"
                    id="itemsMenuBtn"
                    class="w-full flex items-center justify-between p-3 rounded hover:bg-indigo-700 transition-colors text-left">
                <span class="flex items-center gap-2">
                    Items
                </span>
                <svg id="itemsChevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div id="itemsSubmenu" class="hidden ml-3 mt-1 space-y-1 border-l-2 border-indigo-600 pl-3">
                <a href="Manageitems.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Manage Items
                </a>
                <a href="Additems.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Add Items
                </a>
                <a href="manage_brands.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Brands
                </a>
                <a href="manage_categories.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Categories
                </a>
            </div>
        </div>

                <!-- ── Orders Group ── -->
        <div id="OrdersGroup">
            <button onclick="toggleMenu('ordersSubmenu','ordersChevron')"
                    id="ordersMenuBtn"
                    class="w-full flex items-center justify-between p-3 rounded hover:bg-indigo-700 transition-colors text-left">
                <span class="flex items-center gap-2">
                    Orders
                </span>
                <svg id="ordersChevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div id="ordersSubmenu" class="hidden ml-3 mt-1 space-y-1 border-l-2 border-indigo-600 pl-3">
                <a href="order.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Orders
                </a>
                <a href="admin_create_order.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Create Order
                </a>
            </div>
        </div>

        <!-- ── Settings Group ── -->
        <div id="settingsGroup">
            <button onclick="toggleMenu('settingsSubmenu','settingsChevron')"
                    id="settingsMenuBtn"
                    class="w-full flex items-center justify-between p-3 rounded hover:bg-indigo-700 transition-colors text-left">
                <span class="flex items-center gap-2">
                    Settings
                </span>
                <svg id="settingsChevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div id="settingsSubmenu" class="hidden ml-3 mt-1 space-y-1 border-l-2 border-indigo-600 pl-3">
                <a href="Setting.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    General Settings
                </a>
                <a href="Shipping.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Shipping Settings
                </a>
                <a href="carousel_manager.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Carousel
                </a>
                <a href="popup_manager.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Popup Image
                </a>
                <!-- ── NEW: Payment Settings ── -->
                <a href="payment_settings.php"
                   class="settings-sub-link flex items-center gap-2 p-2 rounded hover:bg-indigo-700 transition-colors text-sm text-indigo-200">
                    Payment Settings
                </a>
            </div>
        </div>

        <form method="POST" action="logout.php" class="mt-4">
            <button type="submit"
                    style="background: linear-gradient(135deg, #e91e63, #6a1b9a);"
                    class="w-full text-white p-3 rounded transition-all hover:opacity-90">
                Logout
            </button>
        </form>
    </nav>
</aside>

<script>
function toggleMenu(submenuId, chevronId, forceOpen) {
    const submenu  = document.getElementById(submenuId);
    const chevron  = document.getElementById(chevronId);

    const isHidden = submenu.classList.contains('hidden');
    const open     = forceOpen !== undefined ? forceOpen : isHidden;

    submenu.classList.toggle('hidden', !open);
    chevron.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
}
</script>


<style>
    @media (max-width: 768px) {
        .admin-main { margin-left: 0 !important; }
    }
</style>