import React, { useEffect, useState } from 'react';
import { Download, FileText, Database, Calendar, CreditCard, Clock } from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';

interface ExportOption {
    id: string;
    name: string;
    description: string;
    format: string;
    last_generated: string;
}

export const GlobalExportResults: React.FC = () => {
    const [options, setOptions] = useState<ExportOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [processing, setProcessing] = useState<string | null>(null);

    useEffect(() => {
        fetchOptions();
    }, []);

    const fetchOptions = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/reports/export-options');
            if (response.data.success) {
                setOptions(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch export options:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleExport = async (id: string) => {
        try {
            setProcessing(id);
            // Simulate processing delay
            await new Promise(resolve => setTimeout(resolve, 1500));

            const response = await apiClient.post('/super-admin/reports/export/trigger', { export_id: id });
            if (response.data.success) {
                showToast.success(response.data.message);
            }
        } catch (error: any) {
            showToast.error('Gagal memulai export: ' + (error.response?.data?.message || 'Error server'));
        } finally {
            setProcessing(null);
        }
    };

    const getIcon = (id: string) => {
        if (id.includes('attendance')) return <Calendar className="w-6 h-6 text-orange-500" />;
        if (id.includes('billing')) return <CreditCard className="w-6 h-6 text-purple-500" />;
        if (id.includes('users')) return <Database className="w-6 h-6 text-blue-500" />;
        return <FileText className="w-6 h-6 text-slate-500" />;
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Export Data Center</h1>
                <p className="text-slate-600">Unduh data global untuk keperluan analisis eksternal atau backup.</p>
            </div>

            {loading ? (
                <div className="flex justify-center py-12">
                    <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6">
                    {options.map((option) => (
                        <div key={option.id} className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col justify-between hover:shadow-md transition-shadow">
                            <div className="flex items-start gap-4 mb-4">
                                <div className="p-3 bg-slate-50 rounded-lg border border-slate-100">
                                    {getIcon(option.id)}
                                </div>
                                <div>
                                    <h3 className="font-bold text-slate-900 text-lg">{option.name}</h3>
                                    <p className="text-sm text-slate-500 mt-1 leading-relaxed">
                                        {option.description}
                                    </p>
                                    <div className="flex items-center gap-3 mt-3 text-xs text-slate-400">
                                        <span className="flex items-center gap-1">
                                            <FileText className="w-3 h-3" /> {option.format}
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <Clock className="w-3 h-3" /> Update: {new Date(option.last_generated).toLocaleString('id-ID')}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="mt-4 pt-4 border-t border-slate-50 flex justify-end">
                                <button
                                    onClick={() => handleExport(option.id)}
                                    disabled={processing === option.id}
                                    className={`flex items-center gap-2 px-4 py-2Rounded-lg font-medium transition-colors rounded-lg
                                        ${processing === option.id
                                            ? 'bg-blue-50 text-blue-400 cursor-wait'
                                            : 'bg-blue-600 text-white hover:bg-blue-700'}`}
                                >
                                    {processing === option.id ? (
                                        <>
                                            <div className="w-4 h-4 border-2 border-blue-400 border-t-transparent rounded-full animate-spin"></div>
                                            Processing...
                                        </>
                                    ) : (
                                        <>
                                            <Download className="w-4 h-4" />
                                            Generate Export
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div className="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4 flex gap-3 text-blue-800 text-sm">
                <Database className="w-5 h-5 flex-shrink-0" />
                <div>
                    <strong className="block mb-1">Informasi Keamanan Data</strong>
                    <p>
                        Semua file export berisi data sensitif. Pastikan Anda menyimpan file di lokasi aman.
                        Aktivitas export dicatat dalam Audit Log untuk keperluan keamanan.
                    </p>
                </div>
            </div>
        </div>
    );
};
