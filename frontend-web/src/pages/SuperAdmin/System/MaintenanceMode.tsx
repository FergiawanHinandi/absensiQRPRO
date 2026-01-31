import React, { useState } from 'react';
import showToast from '../../../utils/toast';

export const MaintenanceMode: React.FC = () => {
  const [isMaintenanceMode, setIsMaintenanceMode] = useState(false);

  const toggleMaintenanceMode = () => {
    setIsMaintenanceMode(!isMaintenanceMode);
    showToast.success(`Maintenance mode ${!isMaintenanceMode ? 'diaktifkan' : 'dinonaktifkan'}`);
  };

  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-6">Maintenance Mode</h1>
      <div className="bg-white rounded-lg shadow p-6">
        <div className="mb-4">
          <p className="text-gray-600 mb-2">
            Status saat ini:
            <span className={`ml-2 px-2 py-1 rounded text-sm ${isMaintenanceMode ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'
              }`}>
              {isMaintenanceMode ? 'Maintenance Mode Aktif' : 'Normal'}
            </span>
          </p>
        </div>

        <button
          className={`px-4 py-2 rounded text-white ${isMaintenanceMode
              ? 'bg-green-500 hover:bg-green-600'
              : 'bg-red-500 hover:bg-red-600'
            }`}
          onClick={toggleMaintenanceMode}
        >
          {isMaintenanceMode ? 'Nonaktifkan' : 'Aktifkan'} Maintenance Mode
        </button>

        <p className="text-sm text-gray-500 mt-4">
          Maintenance mode akan memblokir akses untuk semua user kecuali Super Admin.
        </p>
      </div>
    </div>
  );
};