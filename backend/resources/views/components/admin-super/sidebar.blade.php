<div id="sidebar" class="fixed md:static z-40 h-full w-64 md:w-72 bg-gray-900 border-r border-gray-800 shadow-xl overflow-y-auto transition-transform duration-300 -translate-x-full md:translate-x-0">
    <!-- Header -->
    <div class="p-4 border-b border-gray-800">
        <div class="flex items-center space-x-2">
            <div class="bg-gradient-to-r from-blue-600 to-purple-700 w-10 h-10 rounded-xl flex items-center justify-center font-bold text-xl shadow-lg">
                A
            </div>
            <div>
                <h1 class="font-bold text-xl bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                    AbsensiQR Pro
                </h1>
                <p class="text-xs text-gray-400">Super Admin Dashboard</p>
            </div>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="mt-2 px-2 pb-20">
        <!-- Dashboard Utama -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.dashboard*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">📊</span>
                    <span class="font-medium">Dashboard Utama</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.dashboard*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.dashboard*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.dashboard.overview') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.dashboard.overview') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Ringkasan sistem global</span>
                </a>
                <a href="{{ route('super-admin.dashboard.school-stats') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.dashboard.school-stats') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Statistik sekolah aktif</span>
                </a>
                <a href="{{ route('super-admin.dashboard.activity') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.dashboard.activity') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Aktivitas terbaru</span>
                </a>
            </div>
        </div>

        <!-- Manajemen Sekolah -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.schools*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">🏫</span>
                    <span class="font-medium">Manajemen Sekolah</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.schools*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.schools*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.schools.list') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.schools.list') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Daftar Sekolah</span>
                </a>
                <a href="{{ route('super-admin.schools.activation') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.schools.activation') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Aktivasi Sekolah</span>
                </a>
                <a href="{{ route('super-admin.schools.packages') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.schools.packages') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Paket & Limit</span>
                </a>
            </div>
        </div>

        <!-- Manajemen Pengguna Global -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.users*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">👥</span>
                    <span class="font-medium">Manajemen Pengguna Global</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.users*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.users*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.users.superadmin') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.users.superadmin') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Super Admin</span>
                </a>
                <a href="{{ route('super-admin.users.support') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.users.support') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Support/Admin Internal</span>
                </a>
            </div>
        </div>

        <!-- Monitoring Sistem -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.monitoring*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">🔍</span>
                    <span class="font-medium">Monitoring Sistem</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.monitoring*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.monitoring*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.monitoring.health') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.monitoring.health') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>System Health</span>
                </a>
                <a href="{{ route('super-admin.monitoring.logs') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.monitoring.logs') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Error Logs</span>
                </a>
                <a href="{{ route('super-admin.monitoring.queue') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.monitoring.queue') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Queue Status</span>
                </a>
            </div>
        </div>

        <!-- Security Monitoring Global -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.security*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">🛡️</span>
                    <span class="font-medium">Security Monitoring Global</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.security*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.security*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.security.events') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.security.events') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Security Events</span>
                </a>
                <a href="{{ route('super-admin.security.suspicious') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.security.suspicious') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Suspicious Activity</span>
                </a>
                <a href="{{ route('super-admin.security.api-abuse') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.security.api-abuse') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>API Abuse Logs</span>
                </a>
            </div>
        </div>

        <!-- Billing & Subscription -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.billing*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">💳</span>
                    <span class="font-medium">Billing & Subscription</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.billing*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.billing*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.billing.packages') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.billing.packages') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Paket Langganan</span>
                </a>
                <a href="{{ route('super-admin.billing.invoices') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.billing.invoices') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Tagihan Sekolah</span>
                </a>
                <a href="{{ route('super-admin.billing.history') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.billing.history') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Riwayat Pembayaran</span>
                </a>
            </div>
        </div>

        <!-- Laporan Global -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.reports*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">📈</span>
                    <span class="font-medium">Laporan Global</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.reports*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.reports*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.reports.usage') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.reports.usage') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Statistik Penggunaan</span>
                </a>
                <a href="{{ route('super-admin.reports.attendance') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.reports.attendance') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Rekap Absensi Global</span>
                </a>
            </div>
        </div>

        <!-- Pengaturan Sistem -->
        <div class="mb-1">
            <button class="menu-toggle flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 {{ request()->routeIs('super-admin.settings*') ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500' : 'text-gray-300 hover:bg-gray-800' }}">
                <div class="flex items-center">
                    <span class="text-xl mr-3">⚙️</span>
                    <span class="font-medium">Pengaturan Sistem</span>
                </div>
                <span class="arrow transform transition-transform {{ request()->routeIs('super-admin.settings*') ? 'rotate-180' : '' }}">▼</span>
            </button>
            <div class="submenu ml-4 mt-1 mb-2 {{ request()->routeIs('super-admin.settings*') ? '' : 'hidden' }}">
                <a href="{{ route('super-admin.settings.flags') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.settings.flags') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Feature Flags</span>
                </a>
                <a href="{{ route('super-admin.settings.maintenance') }}" class="flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('super-admin.settings.maintenance') ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md' : 'text-gray-300 hover:bg-gray-800' }} text-sm">
                    <span class="mr-2">•</span>
                    <span>Maintenance Mode</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Footer Status -->
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-800 bg-gray-900">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <div class="bg-green-500 w-3 h-3 rounded-full mr-3 animate-pulse"></div>
                <span class="text-sm text-gray-300">Sistem Online</span>
            </div>
            <span class="text-xs text-gray-500 bg-gray-800 px-2 py-1 rounded">v2.5.1</span>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Menu accordion toggle
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggles = document.querySelectorAll('.menu-toggle');
        
        menuToggles.forEach(toggle => {
            toggle.addEventListener('click', function() {
                const submenu = this.nextElementSibling;
                const arrow = this.querySelector('.arrow');
                
                if (submenu && submenu.classList.contains('submenu')) {
                    submenu.classList.toggle('hidden');
                    arrow.classList.toggle('rotate-180');
                }
            });
        });
    });
</script>
@endpush
