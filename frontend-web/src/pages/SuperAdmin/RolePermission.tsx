import React, { useEffect, useState } from 'react';
import { Shield, Lock, CheckCircle, XCircle, ChevronDown, ChevronUp } from 'lucide-react';
import { apiClient } from '../../lib/api';

interface Permission {
    id: number;
    name: string;
    description?: string;
}

interface Role {
    id: number;
    name: string;
    guard_name: string;
    permissions: Permission[];
}

export const RolePermission: React.FC = () => {
    const [roles, setRoles] = useState<Role[]>([]);
    const [permissionsGrouped, setPermissionsGrouped] = useState<Record<string, Permission[]>>({});
    const [loading, setLoading] = useState(true);
    const [expandedRole, setExpandedRole] = useState<number | null>(null);

    useEffect(() => {
        fetchRoles();
    }, []);

    const fetchRoles = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/security/roles');
            if (response.data.success) {
                setRoles(response.data.data.roles);
                setPermissionsGrouped(response.data.data.permissions_grouped);
            }
        } catch (error) {
            console.error('Failed to fetch roles:', error);
        } finally {
            setLoading(false);
        }
    };

    const toggleExpand = (roleId: number) => {
        setExpandedRole(expandedRole === roleId ? null : roleId);
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Role & Permission Management</h1>
                <p className="text-slate-600">Kelola hak akses dan perizinan untuk setiap peran pengguna</p>
            </div>

            {loading ? (
                <div className="flex justify-center py-12">
                    <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                </div>
            ) : (
                <div className="grid gap-6">
                    {roles.map((role) => (
                        <div key={role.id} className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                            <div
                                className="p-6 flex items-center justify-between cursor-pointer hover:bg-slate-50 transition-colors"
                                onClick={() => toggleExpand(role.id)}
                            >
                                <div className="flex items-center gap-4">
                                    <div className={`p-3 rounded-lg ${role.name === 'super_admin' ? 'bg-purple-100 text-purple-600' : 'bg-blue-100 text-blue-600'}`}>
                                        <Shield className="w-6 h-6" />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-bold text-slate-900 capitalize">{role.name.replace('_', ' ')}</h3>
                                        <p className="text-sm text-slate-500">{role.permissions.length} Permissions Assigned</p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {role.name === 'super_admin' && (
                                        <span className="px-3 py-1 bg-purple-100 text-purple-700 text-xs font-bold rounded-full uppercase tracking-wider">
                                            Full Access
                                        </span>
                                    )}
                                    {expandedRole === role.id ? <ChevronUp className="text-slate-400" /> : <ChevronDown className="text-slate-400" />}
                                </div>
                            </div>

                            {expandedRole === role.id && (
                                <div className="border-t border-slate-100 p-6 bg-slate-50">
                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                        {Object.entries(permissionsGrouped).map(([module, perms]) => (
                                            <div key={module} className="bg-white p-4 rounded-lg border border-slate-200">
                                                <h4 className="font-semibold text-slate-800 mb-3 capitalize flex items-center gap-2">
                                                    <Lock className="w-4 h-4 text-slate-400" />
                                                    {module}
                                                </h4>
                                                <div className="space-y-2">
                                                    {perms.map((perm) => {
                                                        const hasPermission = role.permissions.some(p => p.id === perm.id);
                                                        return (
                                                            <div key={perm.id} className="flex items-center justify-between text-sm">
                                                                <span className={hasPermission ? 'text-slate-700' : 'text-slate-400'}>
                                                                    {perm.name}
                                                                </span>
                                                                {hasPermission ? (
                                                                    <CheckCircle className="w-4 h-4 text-green-500" />
                                                                ) : (
                                                                    <XCircle className="w-4 h-4 text-slate-200" />
                                                                )}
                                                            </div>
                                                        )
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};
