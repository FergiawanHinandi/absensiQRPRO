import React, { useEffect, useState } from 'react';
import { LayoutTemplate, Plus, Trash2, Clock, Book, AlertCircle } from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface ScheduleStructure {
    periods: Array<{
        name: string;
        start: string;
        end: string;
        is_break: boolean;
    }>;
}

interface Template {
    id: number;
    name: string;
    description: string;
    schedule_structure: ScheduleStructure;
    is_active: boolean;
}

// Default template structure for new items
const DEFAULT_STRUCTURE: ScheduleStructure = {
    periods: [
        { name: 'Upacara / Wali Kelas', start: '07:00', end: '07:45', is_break: false },
        { name: 'Jam Ke-1', start: '07:45', end: '08:25', is_break: false },
        { name: 'Jam Ke-2', start: '08:25', end: '09:05', is_break: false },
        { name: 'Istirahat', start: '09:05', end: '09:20', is_break: true },
        { name: 'Jam Ke-3', start: '09:20', end: '10:00', is_break: false },
    ]
};

export const ScheduleTemplate: React.FC = () => {
    const [templates, setTemplates] = useState<Template[]>([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);

    const [form, setForm] = useState({
        name: '',
        description: '',
        structure_json: JSON.stringify(DEFAULT_STRUCTURE, null, 2)
    });

    useEffect(() => {
        fetchTemplates();
    }, []);

    const fetchTemplates = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/config/templates');
            if (response.data.success) {
                setTemplates(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch templates:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            let structure;
            try {
                structure = JSON.parse(form.structure_json);
            } catch (err) {
                showToast.error('Invalid JSON Structure');
                return;
            }

            await apiClient.post('/super-admin/config/templates', {
                name: form.name,
                description: form.description,
                schedule_structure: structure
            });

            showToast.success('Template created successfully');
            setShowModal(false);
            setForm({ name: '', description: '', structure_json: JSON.stringify(DEFAULT_STRUCTURE, null, 2) });
            fetchTemplates();
        } catch (error: any) {
            showToast.error(error.response?.data?.message || 'Failed to create template');
        }
    };

    const handleDelete = async (id: number) => {
        if (!confirm('Are you sure you want to delete this template?')) return;
        try {
            await apiClient.delete(`/super-admin/config/templates/${id}`);
            fetchTemplates();
        } catch (error) {
            console.error('Failed to delete template:', error);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="flex justify-between items-start mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">Schedule Templates</h1>
                    <p className="text-slate-600">Master template struktur jadwal untuk berbagai tipe sekolah</p>
                </div>
                <button
                    onClick={() => setShowModal(true)}
                    className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                >
                    <Plus className="w-5 h-5" />
                    <span>New Template</span>
                </button>
            </div>

            {loading ? (
                <div className="flex justify-center py-12">
                    <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    {templates.map(template => (
                        <div key={template.id} className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col">
                            <div className="p-5 border-b border-slate-100 flex justify-between items-start">
                                <div>
                                    <h3 className="font-bold text-slate-800 text-lg mb-1">{template.name}</h3>
                                    <p className="text-sm text-slate-500 line-clamp-2">{template.description}</p>
                                </div>
                                <div className="p-2 bg-blue-50 text-blue-600 rounded-lg">
                                    <LayoutTemplate className="w-5 h-5" />
                                </div>
                            </div>

                            <div className="p-5 flex-1 bg-slate-50/50">
                                <h4 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Timeline Preview</h4>
                                <div className="space-y-3 relative before:absolute before:left-[4.5rem] before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-200">
                                    {template.schedule_structure.periods.slice(0, 5).map((period, idx) => (
                                        <div key={idx} className="flex items-center relative z-10">
                                            <div className="w-16 text-xs text-slate-500 font-mono text-right pr-4">
                                                {period.start}
                                            </div>
                                            <div className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-medium border w-full
                                                ${period.is_break
                                                    ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                    : 'bg-white text-slate-700 border-slate-200 shadow-sm'}`}
                                            >
                                                {period.is_break ? <Clock className="w-3 h-3" /> : <Book className="w-3 h-3" />}
                                                <span className="truncate">{period.name}</span>
                                            </div>
                                        </div>
                                    ))}
                                    {template.schedule_structure.periods.length > 5 && (
                                        <div className="text-center text-xs text-slate-400 mt-2 italic pl-16">
                                            + {template.schedule_structure.periods.length - 5} more periods
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="p-4 border-t border-slate-100 flex justify-end">
                                <button
                                    onClick={() => handleDelete(template.id)}
                                    className="flex items-center gap-1 text-red-600 hover:text-red-700 text-sm font-medium px-3 py-1.5 hover:bg-red-50 rounded-md transition-colors"
                                >
                                    <Trash2 className="w-4 h-4" /> Delete
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Modal */}
            {showModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                        <div className="p-6 border-b border-slate-200">
                            <h2 className="text-xl font-bold text-slate-900">Create Schedule Template</h2>
                        </div>
                        <form onSubmit={handleSubmit} className="p-6 space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Template Name</label>
                                <input
                                    type="text"
                                    required
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    placeholder="e.g. Full Day School - Elementary"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Description</label>
                                <textarea
                                    required
                                    rows={2}
                                    value={form.description}
                                    onChange={(e) => setForm({ ...form, description: e.target.value })}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    placeholder="Brief description of this template structure..."
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1 flex justify-between">
                                    <span>Structure (JSON)</span>
                                    <span className="text-xs text-blue-600 cursor-pointer hover:underline" onClick={() => setForm({ ...form, structure_json: JSON.stringify(DEFAULT_STRUCTURE, null, 2) })}>Reset Default</span>
                                </label>
                                <textarea
                                    required
                                    rows={10}
                                    value={form.structure_json}
                                    onChange={(e) => setForm({ ...form, structure_json: e.target.value })}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-xs bg-slate-50"
                                />
                                <div className="mt-2 text-xs text-slate-500 flex items-start gap-1">
                                    <AlertCircle className="w-3 h-3 mt-0.5 flex-shrink-0" />
                                    <p>Ensure valid JSON format. Must contain "periods" array with name, start, end, and is_break fields.</p>
                                </div>
                            </div>

                            <div className="flex justify-end gap-3 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setShowModal(false)}
                                    className="px-4 py-2 text-slate-700 hover:bg-slate-100 rounded-lg"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                                >
                                    Create Template
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};
