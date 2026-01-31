import React from 'react';
import showToast from '../../../utils/toast';

export const BackupDatabase: React.FC = () => {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-6">Backup Database</h1>
      <div className="bg-white rounded-lg shadow p-6">
        <p className="text-gray-600 mb-4">
          Fitur backup database sedang dalam pengembangan.
        </p>
        <button
          className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"
          onClick={() => showToast.success('Fitur backup akan segera tersedia')}
        >
          Backup Database
        </button>
      </div>
    </div>
  );
};