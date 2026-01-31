import React, { useEffect, useState } from 'react';
import { Settings, ToggleLeft, ToggleRight, AlertTriangle } from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface FeatureFlag {
    id: number;
    key: string;
    label: string;
    description: string;
    is_enabled: boolean;
}

export const FeatureFlags: React.FC = () => {
    const [flags, setFlags] = useState<FeatureFlag[]>([]);
    const [loading, setLoading] = useState(true);
    const [updating, setUpdating] = useState<number | null>(null);

    useEffect(() => {
        fetchFlags();
    }, []);

    const fetchFlags = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/config/features');
            if (response.data.success) {
                setFlags(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch feature flags:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleToggle = async (id: number, currentState: boolean) => {
        try {
            setUpdating(id);
            // Optimistic update
            setFlags(prev => prev.map(f => f.id === id ? { ...f, is_enabled: !currentState } : f));

            await apiClient.patch(`/super-admin/config/features/${id}`, {
                is_enabled: !currentState
            });
        } catch (error) {
            console.error('Failed to toggle feature:', error);
            // Revert on failure
            setFlags(prev => prev.map(f => f.id === id ? { ...f, is_enabled: currentState } : f));
            showToast.error('Failed to update feature status');
        } finally {
            setUpdating(null);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Feature Flags</h1>
                <p className="text-slate-600">Kontrol ketersediaan fitur platform secara global</p>
            </div>

            {loading ? (
                <div className="flex justify-center py-12">
                    <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                </div>
            ) : (
                <div className="space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {flags.map(flag => (
                            <div key={flag.id} className={`p-6 rounded-xl border transition-all duration-200 
                                ${flag.is_enabled
                                    ? 'bg-white border-slate-200 shadow-sm'
                                    : 'bg-slate-50 border-slate-200 opacity-80'}`}
                            >
                                <div className="flex justify-between items-start mb-4">
                                    <div className="flex items-start gap-3">
                                        <div className={`p-2 rounded-lg mt-0.5 ${flag.is_enabled ? 'bg-blue-100 text-blue-600' : 'bg-slate-200 text-slate-500'}`}>
                                            <Settings className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <h3 className="font-bold text-slate-900 text-lg">{flag.label}</h3>
                                            <p className="font-mono text-xs text-slate-400 mt-0.5">{flag.key}</p>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => handleToggle(flag.id, flag.is_enabled)}
                                        disabled={updating === flag.id}
                                        className={`transition-colors focus:outline-none ${updating === flag.id ? 'opacity-50 cursor-wait' : ''}`}
                                    >
                                        {flag.is_enabled ? (
                                            <ToggleRight className="w-10 h-10 text-green-500 hover:text-green-600" />
                                        ) : (
                                            <ToggleLeft className="w-10 h-10 text-slate-400 hover:text-slate-500" />
                                        )}
                                    </button>
                                </div>
                                <p className="text-slate-600 text-sm mb-4 leading-relaxed">
                                    {flag.description}
                                </p>

                                <div className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium 
                                    ${flag.is_enabled ? 'bg-green-50 text-green-700' : 'bg-slate-200 text-slate-600'}`}
                                >
                                    <div className={`w-1.5 h-1.5 rounded-full ${flag.is_enabled ? 'bg-green-500' : 'bg-slate-500'}`}></div>
                                    {flag.is_enabled ? 'Active Feature' : 'Disabled'}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 flex gap-3 text-amber-800 text-sm">
                        <AlertTriangle className="w-5 h-5 flex-shrink-0" />
                        <div>
                            <p className="font-semibold mb-1">Perhatian</p>
                            <p>
                                Menonaktifkan fitur di sini akan menyembunyikan atau mematikan fungsi tersebut untuk <strong>SELURUH</strong> sekolah di platform ini. Gunakan dengan hati-hati terutama untuk fitur inti.
                            </p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
