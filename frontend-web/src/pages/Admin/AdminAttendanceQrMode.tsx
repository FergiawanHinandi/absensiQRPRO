import React, { useEffect, useState } from 'react';
import { getQrModeSettings, updateQrModeSettings } from '../../services/adminService';
import { toast } from 'react-hot-toast';

const AdminAttendanceQrMode: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState({
        qr_expiry_seconds: 30,
        qr_regeneration_cooldown: 5,
    });

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        try {
            const data = await getQrModeSettings();
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
            await updateQrModeSettings(settings);
            toast.success('QR Mode settings updated successfully');
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
            <h1 className="text-2xl font-bold mb-6 text-gray-800">QR Code Configuration</h1>
            
            <div className="bg-white rounded-lg shadow p-6">
                <form onSubmit={handleSubmit} className="space-y-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            QR Expiry (Seconds)
                        </label>
                        <p className="text-sm text-gray-500 mb-2">
                            How long a generated QR code remains valid before expiring.
                        </p>
                        <input
                            type="number"
                            min="5"
                            max="60"
                            value={settings.qr_expiry_seconds}
                            onChange={(e) => setSettings({ ...settings, qr_expiry_seconds: parseInt(e.target.value) })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">
                            Regeneration Cooldown (Seconds)
                        </label>
                        <p className="text-sm text-gray-500 mb-2">
                            Minimum time to wait before generating a new QR code (prevents spam).
                        </p>
                        <input
                            type="number"
                            min="0"
                            max="30"
                            value={settings.qr_regeneration_cooldown}
                            onChange={(e) => setSettings({ ...settings, qr_regeneration_cooldown: parseInt(e.target.value) })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                        />
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

export default AdminAttendanceQrMode;
