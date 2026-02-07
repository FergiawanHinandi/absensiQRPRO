import React, { useEffect, useState } from 'react';
import { getOverrideSettings, updateOverrideSettings } from '../../services/adminService';
import { toast } from 'react-hot-toast';

const AdminAttendanceOverride: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState({
        allow_teacher_override: true,
        require_override_reason: true,
    });

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        try {
            const data = await getOverrideSettings();
            setSettings(data);
        } catch (error) {
            console.error('Failed to fetch settings', error);
            toast.error('Failed to load settings');
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        try {
            await updateOverrideSettings(settings);
            toast.success('Override settings updated successfully');
        } catch (error) {
            console.error('Failed to update settings', error);
            toast.error('Failed to update settings');
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return <div className="p-8 text-center">Loading settings...</div>;
    }

    return (
        <div className="max-w-4xl mx-auto py-8 px-4">
            <h1 className="text-2xl font-bold mb-6 text-gray-800">Teacher Override Configuration</h1>
            
            <div className="bg-white rounded-lg shadow p-6">
                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="flex items-start">
                        <div className="flex items-center h-5">
                            <input
                                id="allow_teacher_override"
                                type="checkbox"
                                checked={settings.allow_teacher_override}
                                onChange={(e) => setSettings({ ...settings, allow_teacher_override: e.target.checked })}
                                className="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded"
                            />
                        </div>
                        <div className="ml-3 text-sm">
                            <label htmlFor="allow_teacher_override" className="font-medium text-gray-700">
                                Allow Teacher Manual Override
                            </label>
                            <p className="text-gray-500">
                                If enabled, teachers can manually mark students as Present/Late/Absent even if QR scan fails.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-start">
                        <div className="flex items-center h-5">
                            <input
                                id="require_override_reason"
                                type="checkbox"
                                checked={settings.require_override_reason}
                                onChange={(e) => setSettings({ ...settings, require_override_reason: e.target.checked })}
                                className="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded"
                            />
                        </div>
                        <div className="ml-3 text-sm">
                            <label htmlFor="require_override_reason" className="font-medium text-gray-700">
                                Require Reason for Override
                            </label>
                            <p className="text-gray-500">
                                If enabled, teachers must provide a reason when manually changing attendance status.
                            </p>
                        </div>
                    </div>

                    <div className="pt-4 border-t border-gray-200">
                        <button
                            type="submit"
                            disabled={saving}
                            className={`px-4 py-2 text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 ${saving ? 'opacity-50 cursor-not-allowed' : ''}`}
                        >
                            {saving ? 'Saving...' : 'Save Changes'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

export default AdminAttendanceOverride;
