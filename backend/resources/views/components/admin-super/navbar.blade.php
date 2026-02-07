<header class="bg-gray-900 border-b border-gray-800 p-4 flex items-center justify-between shadow-lg">
    <div class="flex items-center">
        <!-- Mobile Sidebar Toggle -->
        <button id="sidebar-toggle" class="md:hidden mr-4 p-2 hover:bg-gray-800 rounded-lg transition-colors">
            <div class="w-6 h-0.5 bg-blue-400 mb-1.5 rounded-full"></div>
            <div class="w-6 h-0.5 bg-blue-400 mb-1.5 rounded-full"></div>
            <div class="w-6 h-0.5 bg-blue-400 rounded-full"></div>
        </button>
        
        <!-- Search Bar -->
        <div class="relative hidden md:block">
            <input
                type="text"
                placeholder="Cari di dashboard..."
                class="bg-gray-800 text-gray-200 rounded-lg py-2 px-4 w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <div class="absolute right-3 top-2.5 text-gray-400">🔍</div>
        </div>
    </div>

    <div class="flex items-center space-x-4">
        <!-- Notification Bell -->
        <button class="p-2 hover:bg-gray-800 rounded-lg relative">
            🔔
            @if(isset($notificationCount) && $notificationCount > 0)
            <span class="absolute top-1 right-1 bg-red-500 text-xs w-5 h-5 rounded-full flex items-center justify-center animate-pulse">
                {{ $notificationCount }}
            </span>
            @endif
        </button>
        
        <!-- Messages -->
        <button class="p-2 hover:bg-gray-800 rounded-lg relative">
            📧
            @if(isset($messageCount) && $messageCount > 0)
            <span class="absolute top-1 right-1 bg-blue-500 text-xs w-5 h-5 rounded-full flex items-center justify-center">
                {{ $messageCount }}
            </span>
            @endif
        </button>
        
        <!-- User Profile Dropdown -->
        <div class="flex items-center bg-gray-800 rounded-lg p-2 cursor-pointer hover:bg-gray-700 transition-colors" id="user-menu-toggle">
            <div class="w-8 h-8 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center font-bold mr-2">
                {{ strtoupper(substr(auth()->user()->name ?? 'SA', 0, 2)) }}
            </div>
            <div class="hidden md:block">
                <p class="text-sm font-medium">{{ auth()->user()->name ?? 'Super Admin' }}</p>
                <p class="text-xs text-gray-400">{{ auth()->user()->email ?? 'admin@absensiqrpro.com' }}</p>
            </div>
            <div class="ml-2 text-xs">▼</div>
        </div>
        
        <!-- User Dropdown Menu (Hidden by default) -->
        <div id="user-dropdown" class="hidden absolute right-4 top-16 bg-gray-800 border border-gray-700 rounded-lg shadow-xl w-48 z-50">
            <div class="p-2">
                <a href="{{ route('super-admin.profile') }}" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 rounded-lg">
                    👤 Profil Saya
                </a>
                <a href="{{ route('super-admin.settings.account') }}" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 rounded-lg">
                    ⚙️ Pengaturan Akun
                </a>
                <hr class="my-2 border-gray-700">
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-700 rounded-lg">
                        🚪 Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

@push('scripts')
<script>
    // User dropdown toggle
    document.addEventListener('DOMContentLoaded', function() {
        const userMenuToggle = document.querySelector('#user-menu-toggle');
        const userDropdown = document.querySelector('#user-dropdown');
        
        if (userMenuToggle && userDropdown) {
            userMenuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('hidden');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!userMenuToggle.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.classList.add('hidden');
                }
            });
        }
    });
</script>
@endpush
