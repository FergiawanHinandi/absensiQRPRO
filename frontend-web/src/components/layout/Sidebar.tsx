import React, { useState, useEffect } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { useMaintenanceStore } from '../../store/maintenanceStore';
import { MENUS } from '../../config/navigation';
import type { RoleType, MenuItem } from '../../config/navigation';
import { X, ChevronDown, ChevronRight, Search, Star, Pin } from 'lucide-react';
import { apiClient } from '../../lib/api';
import type { ThemeConfig } from '../../config/dashboardThemes';

interface SidebarProps {
    isOpen: boolean;
    toggleSidebar: () => void;
    theme: ThemeConfig;
}

interface TeacherProfile {
    is_homeroom_teacher: boolean;
    homeroom_class?: {
        id: number;
        name: string;
    };
    subjects: Array<{
        id: number;
        name: string;
    }>;
}

const Sidebar: React.FC<SidebarProps> = ({ isOpen, toggleSidebar, theme }) => {
    const { user } = useAuthStore();
    const { isMaintenance } = useMaintenanceStore();
    const role = user?.role_type as RoleType;
    const isSuperAdmin = role === 'super_admin' || user?.email === 'super@admin.com';
    const isLocked = isMaintenance && !isSuperAdmin;

    const [expandedMenus, setExpandedMenus] = useState<string[]>([]);
    const [teacherProfile, setTeacherProfile] = useState<TeacherProfile | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [pinnedPaths, setPinnedPaths] = useState<string[]>([]);

    // Load pinned menus from localStorage
    useEffect(() => {
        const savedPinned = localStorage.getItem(`pinned_menus_${user?.id}`);
        if (savedPinned) {
            setPinnedPaths(JSON.parse(savedPinned));
        }
    }, [user?.id]);

    const togglePin = (path: string, e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();

        const newPinned = pinnedPaths.includes(path)
            ? pinnedPaths.filter(p => p !== path)
            : [...pinnedPaths, path];

        setPinnedPaths(newPinned);
        localStorage.setItem(`pinned_menus_${user?.id}`, JSON.stringify(newPinned));
    };

    // Fetch teacher profile if user is a teacher
    useEffect(() => {
        if (role === 'teacher' && !isLocked) {
            fetchTeacherProfile();
        }
    }, [role, isLocked]);


    const fetchTeacherProfile = async () => {
        try {
            const response = await apiClient.get('/teacher/profile');
            if (response.data.success) {
                setTeacherProfile(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch teacher profile:', error);
        }
    };

    // Get menus based on role and teacher profile
    const getMenus = (): MenuItem[] => {
        let baseMenus = MENUS[role] || [];

        // For teachers, conditionally add homeroom menu
        if (role === 'teacher' && teacherProfile) {
            if (teacherProfile.is_homeroom_teacher) {
                // Use homeroom_teacher menus
                baseMenus = MENUS['homeroom_teacher'] || baseMenus;
            }
        }

        return baseMenus;
    };

    const menus = getMenus();

    // Filter menus based on search query
    const filteredMenus = React.useMemo(() => {
        if (!searchQuery) return menus;

        const lowerQuery = searchQuery.toLowerCase();

        return menus.reduce<MenuItem[]>((acc, menu) => {
            // Check if menu matches
            const matchMenu = menu.label.toLowerCase().includes(lowerQuery);

            // Check if any child matches
            const matchingChildren = menu.children?.filter(child =>
                child.label.toLowerCase().includes(lowerQuery)
            );

            if (matchMenu || (matchingChildren && matchingChildren.length > 0)) {
                let newMenu = { ...menu };

                // If parent doesn't match but children do, show only matching children
                if (matchingChildren && matchingChildren.length > 0 && !matchMenu) {
                    newMenu.children = matchingChildren;
                }

                acc.push(newMenu);
            }
            return acc;
        }, []);
    }, [menus, searchQuery]);

    // Auto expand on search
    useEffect(() => {
        if (searchQuery) {
            const newExpanded = filteredMenus
                .filter(m => m.children && m.children.length > 0)
                .map(m => m.label);
            setExpandedMenus(prev => Array.from(new Set([...prev, ...newExpanded])));
        }
    }, [searchQuery, filteredMenus]);

    const toggleSubmenu = (label: string) => {
        if (isLocked) return;
        setExpandedMenus(prev =>
            prev.includes(label)
                ? prev.filter(item => item !== label)
                : [...prev, label]
        );
    };

    const renderMenuItem = (menu: MenuItem, isPinnedSection = false) => {
        const hasChildren = menu.children && menu.children.length > 0;
        const isExpanded = expandedMenus.includes(menu.label);
        const Icon = menu.icon;
        const isPinned = pinnedPaths.includes(menu.path);

        if (hasChildren && !isPinnedSection) {
            return (
                <div key={menu.label} className="mb-1">
                    <button
                        onClick={() => toggleSubmenu(menu.label)}
                        className={`w-full flex items-center justify-between px-4 py-3 text-sm font-medium ${theme.borderRadius} transition-colors group ${isLocked ? 'opacity-50 cursor-not-allowed' : ''} ${isExpanded ? theme.colors.primaryLight : `text-slate-700 hover:bg-slate-100`}`}
                        disabled={isLocked}
                    >
                        <div className="flex items-center gap-3">
                            <Icon className={`w-5 h-5 transition-colors ${isExpanded ? '' : 'text-slate-500 group-hover:text-current'}`} />
                            <span>{menu.label}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            {isLocked && <div className="p-1"><span role="img" aria-label="locked">🔒</span></div>}
                            {menu.badge && (
                                <span className={`px-2 py-0.5 text-xs font-semibold rounded-full ${theme.colors.primaryLight}`}>
                                    {menu.badge}
                                </span>
                            )}
                            {isExpanded ? (
                                <ChevronDown className="w-4 h-4 text-slate-400" />
                            ) : (
                                <ChevronRight className="w-4 h-4 text-slate-400" />
                            )}
                        </div>
                    </button>

                    {isExpanded && !isLocked && (
                        <div className={`mt-1 ml-4 pl-4 border-l-2 ${theme.colors.sidebarBorder} space-y-1`}>
                            {menu.children!.map((child) => {
                                const ChildIcon = child.icon;
                                const isChildPinned = pinnedPaths.includes(child.path);
                                return (
                                    <div key={child.path} className="relative group/item">
                                        <NavLink
                                            to={child.path}
                                            className={({ isActive }) =>
                                                `flex items-center gap-3 px-4 py-2 text-sm ${theme.borderRadius} transition-colors ${isActive
                                                    ? theme.colors.primaryLight + ' font-medium'
                                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                                                }`
                                            }
                                        >
                                            <ChildIcon className="w-4 h-4" />
                                            <span>{child.label}</span>
                                        </NavLink>
                                        <button
                                            onClick={(e) => togglePin(child.path, e)}
                                            className={`absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-full hover:bg-slate-200 transition-opacity ${isChildPinned ? 'text-yellow-500 opacity-100' : 'text-slate-400 opacity-0 group-hover/item:opacity-100'}`}
                                            title={isChildPinned ? "Unpin" : "Pin"}
                                        >
                                            {isChildPinned ? <Star className="w-3 h-3 fill-current" /> : <Pin className="w-3 h-3" />}
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            );
        }

        return (
            <div key={menu.path} className="mb-1 relative group/item">
                {isLocked && (
                    <div className="absolute inset-0 z-10 bg-white/50 cursor-not-allowed flex items-center justify-end px-4">
                        <span role="img" aria-label="locked" className="text-sm">🔒</span>
                    </div>
                )}
                <NavLink
                    to={isLocked ? '#' : menu.path}
                    className={({ isActive }) =>
                        `flex items-center gap-3 px-4 py-3 text-sm font-medium ${theme.borderRadius} transition-all duration-200 ${isActive && !isLocked && !isPinnedSection
                            ? `bg-gradient-to-r ${theme.colors.primaryGradient} text-white shadow-lg`
                            : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                        } ${isLocked ? 'pointer-events-none opacity-50' : ''}`
                    }
                >
                    <Icon className={`w-5 h-5 ${isPinnedSection ? '' : ''}`} />
                    <span>{menu.label}</span>
                    {menu.badge && !isPinnedSection && (
                        <span className="ml-auto px-2 py-0.5 text-xs font-semibold bg-white/20 text-white rounded-full">
                            {menu.badge}
                        </span>
                    )}
                </NavLink>
                {!isLocked && !isPinnedSection && (
                    <button
                        onClick={(e) => togglePin(menu.path, e)}
                        className={`absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full hover:bg-slate-200/50 transition-opacity ${isPinned ? 'text-yellow-500 opacity-100' : 'text-slate-400 opacity-0 group-hover/item:opacity-100'}`}
                        title={isPinned ? "Unpin" : "Pin"}
                    >
                        {isPinned ? <Star className="w-3.5 h-3.5 fill-current" /> : <Pin className="w-3.5 h-3.5" />}
                    </button>
                )}
                {isPinnedSection && (
                    <button
                        onClick={(e) => togglePin(menu.path, e)}
                        className={`absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full hover:bg-slate-200 text-slate-400 hover:text-red-500`}
                        title="Unpin"
                    >
                        <X className="w-3.5 h-3.5" />
                    </button>
                )}
            </div>
        );
    };

    return (
        <>
            {/* Mobile Overlay */}
            <div
                className={`fixed inset-0 bg-slate-900 bg-opacity-50 z-40 lg:hidden transition-opacity duration-300 ${isOpen ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'
                    }`}
                onClick={toggleSidebar}
            />

            {/* Sidebar Container */}
            <div
                className={`fixed top-16 bottom-0 left-0 z-40 w-72 ${theme.colors.sidebarBg} shadow-xl border-r ${theme.colors.sidebarBorder} transform transition-transform duration-300 lg:translate-x-0 ${isOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
            >
                {/* Search Box */}
                <div className={`p-4 border-b ${theme.colors.sidebarBorder}`}>
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari menu..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className={`w-full pl-9 pr-4 py-2 text-sm border ${theme.colors.sidebarBorder} ${theme.borderRadius} focus:outline-none focus:ring-2 focus:ring-opacity-50 transition-all placeholder:text-slate-400 bg-white/50`}
                            style={{ '--tw-ring-color': theme.colors.primary } as React.CSSProperties}
                        />
                    </div>
                </div>

                {/* Menu List */}
                <div className="overflow-y-auto h-[calc(100vh-8rem)] p-4 space-y-4 custom-scrollbar">
                    {/* Pinned Section */}
                    {pinnedPaths.length > 0 && (
                        <div className="mb-6">
                            <h3 className="px-4 text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-2">
                                <Star className="w-3 h-3 text-yellow-500 fill-yellow-500" />
                                Pinned
                            </h3>
                            <div className="space-y-1">
                                {menus.flatMap(m => {
                                    if (pinnedPaths.includes(m.path)) return [renderMenuItem(m, true)];
                                    if (m.children) {
                                        return m.children
                                            .filter(c => pinnedPaths.includes(c.path))
                                            .map(c => {
                                                const Icon = c.icon;
                                                return (
                                                    <div key={c.path} className="relative group/item mb-1">
                                                        <NavLink
                                                            to={c.path}
                                                            className={({ isActive }) =>
                                                                `flex items-center gap-3 px-4 py-2 text-sm ${theme.borderRadius} transition-colors ${isActive
                                                                    ? theme.colors.primaryLight + ' font-medium'
                                                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                                                                }`
                                                            }
                                                        >
                                                            <Icon className="w-4 h-4" />
                                                            <span>{c.label}</span>
                                                        </NavLink>
                                                        <button
                                                            onClick={(e) => togglePin(c.path, e)}
                                                            className="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-full hover:bg-slate-200 text-slate-400 hover:text-red-500"
                                                            title="Unpin"
                                                        >
                                                            <X className="w-3 h-3" />
                                                        </button>
                                                    </div>
                                                );
                                            });
                                    }
                                    return [];
                                })}
                            </div>
                            <div className={`mt-4 border-b ${theme.colors.sidebarBorder}`} />
                        </div>
                    )}

                    {/* Regular Menu */}
                    <div className="space-y-1">
                        {filteredMenus.map((menu, index) => {
                            const showSection = menu.section && (index === 0 || menu.section !== filteredMenus[index - 1].section);
                            return (
                                <React.Fragment key={menu.label}>
                                    {showSection && (
                                        <div className="px-4 mt-6 mb-2 first:mt-2">
                                            <p className={`text-xs font-bold uppercase tracking-wider opacity-60 ${theme.colors.sidebarText}`}>
                                                {menu.section}
                                            </p>
                                        </div>
                                    )}
                                    {renderMenuItem(menu)}
                                </React.Fragment>
                            );
                        })}
                    </div>

                    {/* Empty Search Result */}
                    {filteredMenus.length === 0 && (
                        <div className="text-center py-8 text-slate-500">
                            <p className="text-sm">Menu tidak ditemukan</p>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
};

export default Sidebar;