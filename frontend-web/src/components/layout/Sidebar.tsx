import React, { useState, useEffect } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { useMaintenanceStore } from '../../store/maintenanceStore';
import { MENUS } from '../../config/navigation';
import type { RoleType, MenuItem } from '../../config/navigation';
import { X, ChevronDown, ChevronRight, Search, Star, Pin } from 'lucide-react';
import { apiClient } from '../../lib/api';

interface SidebarProps {
    isOpen: boolean;
    toggleSidebar: () => void;
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

const Sidebar: React.FC<SidebarProps> = ({ isOpen, toggleSidebar }) => {
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

    // Get Pinned Menus
    const pinnedMenus = React.useMemo(() => {
        const pinned: MenuItem[] = [];
        const findPinned = (items: MenuItem[]) => {
            items.forEach(item => {
                if (pinnedPaths.includes(item.path)) {
                    pinned.push(item);
                }
                if (item.children) {
                    findPinned(item.children);
                }
            });
        };
        findPinned(menus);
        return pinned;
    }, [menus, pinnedPaths]);

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
                        className={`w-full flex items-center justify-between px-4 py-3 text-sm font-medium text-slate-700 rounded-lg hover:bg-slate-100 transition-colors group ${isLocked ? 'opacity-50 cursor-not-allowed' : ''}`}
                        disabled={isLocked}
                    >
                        <div className="flex items-center gap-3">
                            <Icon className="w-5 h-5 text-slate-500 group-hover:text-blue-600 transition-colors" />
                            <span className="group-hover:text-slate-900">{menu.label}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            {isLocked && <div className="p-1"><span role="img" aria-label="locked">🔒</span></div>}
                            {menu.badge && (
                                <span className="px-2 py-0.5 text-xs font-semibold bg-blue-100 text-blue-700 rounded-full">
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
                        <div className="mt-1 ml-4 pl-4 border-l-2 border-slate-200 space-y-1">
                            {menu.children!.map((child) => {
                                const ChildIcon = child.icon;
                                const isChildPinned = pinnedPaths.includes(child.path);
                                return (
                                    <div key={child.path} className="relative group/item">
                                        <NavLink
                                            to={child.path}
                                            className={({ isActive }) =>
                                                `flex items-center gap-3 px-4 py-2 text-sm rounded-lg transition-colors ${isActive
                                                    ? 'bg-blue-50 text-blue-700 font-medium'
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
                        `flex items-center gap-3 px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 ${isActive && !isLocked && !isPinnedSection
                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/30'
                            : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900'
                        } ${isLocked ? 'pointer-events-none opacity-50' : ''}`
                    }
                >
                    <Icon className={`w-5 h-5 ${isPinnedSection ? 'text-blue-600' : ''}`} />
                    <span>{menu.label}</span>
                    {menu.badge && !isPinnedSection && (
                        <span className="ml-auto px-2 py-0.5 text-xs font-semibold bg-blue-100 text-blue-700 rounded-full">
                            {menu.badge}
                        </span>
                    )}
                </NavLink>
                {!isLocked && !isPinnedSection && (
                    <button
                        onClick={(e) => togglePin(menu.path, e)}
                        className={`absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded-full hover:bg-slate-200 transition-opacity ${isPinned ? 'text-yellow-500 opacity-100' : 'text-slate-400 opacity-0 group-hover/item:opacity-100'}`}
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
                className={`fixed top-16 bottom-0 left-0 z-40 w-72 bg-white shadow-xl border-r border-slate-200 transform transition-transform duration-300 lg:translate-x-0 ${isOpen ? 'translate-x-0' : '-translate-x-full'
                    }`}
            >
                {/* Search Box */}
                <div className="p-4 border-b border-slate-100">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari menu..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all placeholder:text-slate-400 bg-slate-50"
                        />
                    </div>
                </div>

                {/* Menu Items */}
                <nav className="flex-1 px-4 py-4 space-y-1 overflow-y-auto h-[calc(100%-80px)] custom-scrollbar">
                    {/* Pinned Section */}
                    {pinnedMenus.length > 0 && !searchQuery && (
                        <div className="mb-4">
                            <div className="px-2 mb-2">
                                <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                                    <Star className="w-3 h-3 text-yellow-500 fill-current" />
                                    Quick Access
                                </p>
                            </div>
                            {pinnedMenus.map(menu => renderMenuItem(menu, true))}
                            <div className="h-px bg-slate-100 my-4 mx-2" />
                        </div>
                    )}

                    {filteredMenus.map((menu, index) => {
                        const showSection = menu.section && (index === 0 || menu.section !== filteredMenus[index - 1].section) && !searchQuery;

                        return (
                            <React.Fragment key={menu.path || menu.label}>
                                {showSection && (
                                    <div className="px-2 mt-6 mb-2 first:mt-2">
                                        <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                            {menu.section}
                                        </p>
                                    </div>
                                )}
                                {renderMenuItem(menu)}
                            </React.Fragment>
                        );
                    })}

                    {filteredMenus.length === 0 && (
                        <div className="text-center py-8">
                            <p className="text-sm text-slate-500">Menu tidak ditemukan</p>
                        </div>
                    )}

                    {/* Extra padding for bottom */}
                    <div className="h-8"></div>
                </nav>
            </div>
        </>
    );
};

export default Sidebar;