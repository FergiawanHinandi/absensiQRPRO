import React, { useEffect, useState } from 'react';
import { getToleranceSettings, updateToleranceSettings } from '../../services/adminService';
import { toast } from 'react-hot-toast';

const AdminAttendanceTolerance: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState({
        late_tolerance_minutes: 15,
        early_check_in_allowed: true,
    });

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        try {
            const data = await getToleranceSettings();
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
            await updateToleranceSettings(settings);
            toast.success('Tolerance settings updated successfully');
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
            <h1 className="text-2xl font-bold mb-6 text-gray-800">Time & Tolerance Configuration</h1>
            
            <div className="bg-white rounded-lg shadow p-6">
                <form onSubmit={handleSubmit} className="space-y-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Late Tolerance (Minutes)
                        </label>
                        <p className="text-sm text-gray-500 mb-2">
                            Number of minutes after class start time before a student is marked as "Late".
                        </p>
                        <input
                            type="number"
                            min="0"
                            max="120"
                            value={settings.late_tolerance_minutes}
                            onChange={(e) => setSettings({ ...settings, late_tolerance_minutes: parseInt(e.target.value) })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>

                    <div className="flex items-start">
                        <div className="flex items-center h-5">
                            <input
                                id="early_check_in_allowed"
                                type="checkbox"
                                checked={settings.early_check_in_allowed}
                                onChange={(e) => setSettings({ ...settings, early_check_in_allowed: e.target.checked })}
                                className="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded"
                            />
                        </div>
                        <div className="ml-3 text-sm">
                            <label htmlFor="early_check_in_allowed" className="font-medium text-gray-700">
                                Allow Early Check-in
                            </label>
                            <p className="text-gray-500">
                                If enabled, students can scan QR code before the class officially starts.
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

export default AdminAttendanceTolerance;
