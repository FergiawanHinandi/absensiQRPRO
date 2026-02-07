export type ThemeConfig = {
    name: string;
    colors: {
        primary: string; // Main brand color (buttons, active states)
        primaryGradient: string; // Gradient for active items
        primaryLight: string; // Light background for badges/hover
        sidebarBg: string; // Sidebar background
        sidebarText: string;
        sidebarTextActive: string;
        sidebarBorder: string;
        navbarBg: string;
        navbarBorder: string;
        bg: string; // Main background
    };
    borderRadius: string; // 'rounded-lg', 'rounded-xl', 'rounded-2xl'
};

export const DASHBOARD_THEMES: Record<string, ThemeConfig> = {
    // Admin Sekolah: Clean Professional (Blue/White)
    admin: {
        name: 'Professional',
        colors: {
            primary: 'blue',
            primaryGradient: 'from-blue-600 to-blue-500',
            primaryLight: 'bg-blue-50 text-blue-700',
            sidebarBg: 'bg-white',
            sidebarText: 'text-slate-700',
            sidebarTextActive: 'text-white',
            sidebarBorder: 'border-slate-200',
            navbarBg: 'bg-white',
            navbarBorder: 'border-slate-200',
            bg: 'bg-slate-50'
        },
        borderRadius: 'rounded-lg'
    },
    // Guru: Calm & Focused (Teal/Green)
    teacher: {
        name: 'Calm',
        colors: {
            primary: 'teal',
            primaryGradient: 'from-teal-600 to-emerald-500',
            primaryLight: 'bg-teal-50 text-teal-700',
            sidebarBg: 'bg-emerald-50/50', // Subtle tint
            sidebarText: 'text-teal-900',
            sidebarTextActive: 'text-white',
            sidebarBorder: 'border-teal-100',
            navbarBg: 'bg-white/80 backdrop-blur-md',
            navbarBorder: 'border-teal-100',
            bg: 'bg-stone-50' // Warmer background for reading
        },
        borderRadius: 'rounded-xl'
    },
    // Siswa: Playful & Modern (Violet/Indigo)
    student: {
        name: 'Playful',
        colors: {
            primary: 'violet',
            primaryGradient: 'from-violet-600 to-fuchsia-500',
            primaryLight: 'bg-violet-50 text-violet-700',
            sidebarBg: 'bg-white',
            sidebarText: 'text-slate-700',
            sidebarTextActive: 'text-white',
            sidebarBorder: 'border-violet-100',
            navbarBg: 'bg-white',
            navbarBorder: 'border-violet-100',
            bg: 'bg-indigo-50/30'
        },
        borderRadius: 'rounded-2xl' // More rounded for modern feel
    },
    // Orang Tua: Warm & Trustworthy (Orange/Amber)
    parent: {
        name: 'Warm',
        colors: {
            primary: 'orange',
            primaryGradient: 'from-orange-500 to-amber-500',
            primaryLight: 'bg-orange-50 text-orange-700',
            sidebarBg: 'bg-orange-50/30',
            sidebarText: 'text-slate-800',
            sidebarTextActive: 'text-white',
            sidebarBorder: 'border-orange-100',
            navbarBg: 'bg-white',
            navbarBorder: 'border-orange-100',
            bg: 'bg-orange-50/10'
        },
        borderRadius: 'rounded-lg'
    },
    // Fallback/Default
    default: {
        name: 'Default',
        colors: {
            primary: 'blue',
            primaryGradient: 'from-blue-600 to-blue-500',
            primaryLight: 'bg-blue-50 text-blue-700',
            sidebarBg: 'bg-white',
            sidebarText: 'text-slate-700',
            sidebarTextActive: 'text-white',
            sidebarBorder: 'border-slate-200',
            navbarBg: 'bg-white',
            navbarBorder: 'border-slate-200',
            bg: 'bg-slate-50'
        },
        borderRadius: 'rounded-lg'
    }
};

export const getThemeByRole = (role?: string): ThemeConfig => {
    // Map backend roles to theme keys
    const roleMapping: Record<string, string> = {
        'school_admin': 'admin',
        'admin': 'admin',
        'teacher': 'teacher',
        'homeroom_teacher': 'teacher',
        'student': 'student',
        'parent': 'parent',
        'super_admin': 'admin' // Super admin has its own layout, this is fallback
    };

    const themeKey = role ? (roleMapping[role] || 'default') : 'default';
    return DASHBOARD_THEMES[themeKey] || DASHBOARD_THEMES['default'];
};
