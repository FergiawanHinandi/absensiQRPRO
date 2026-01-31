import { useState } from 'react';
import LocationHeatmap from '../components/LocationHeatmap';
import { useHeatmapAnomalies } from '../hooks/useTeacherHeatmap';
import { useAuthStore } from '../../auth/stores/useAuthStore';

const TeacherHeatmapPage = () => {
  const { user } = useAuthStore();
  const [showAnomalyReport, setShowAnomalyReport] = useState(false);

  // Get anomaly data for the report
  const { data: anomalyData } = useHeatmapAnomalies({
    range: '7d',
    school_id: user?.role_type === 'super_admin' ? undefined : user?.school_id,
  });

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            Teacher Location Heatmap
          </h1>
          <p className="text-gray-600 mt-1">
            Visualize where attendance scans were performed inside or around the school
          </p>
        </div>

        <div className="flex items-center space-x-3">
          {anomalyData && anomalyData.stats.total_outside_zone > 0 && (
            <button
              onClick={() => setShowAnomalyReport(!showAnomalyReport)}
              className="flex items-center px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition"
            >
              <span className="mr-2">⚠️</span>
              {anomalyData.stats.total_outside_zone} Anomalies Detected
            </button>
          )}
        </div>
      </div>

      {/* Anomaly Alert Banner */}
      {anomalyData && anomalyData.stats.total_outside_zone > 0 && showAnomalyReport && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <div className="flex items-start">
            <div className="flex-shrink-0">
              <svg
                className="h-5 w-5 text-red-400"
                viewBox="0 0 20 20"
                fill="currentColor"
              >
                <path
                  fillRule="evenodd"
                  d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                  clipRule="evenodd"
                />
              </svg>
            </div>
            <div className="ml-3 flex-1">
              <h3 className="text-sm font-medium text-red-800">
                Outside Zone Scans Detected
              </h3>
              <div className="mt-2 text-sm text-red-700">
                <p>
                  <strong>{anomalyData.stats.total_outside_zone}</strong> scan clusters 
                  were detected outside the allowed school zone in the last 7 days, 
                  involving <strong>{anomalyData.stats.affected_teachers}</strong> teacher(s).
                </p>
                <p className="mt-2">
                  Click on the red markers on the map to investigate each location.
                </p>
              </div>
              <div className="mt-4">
                <div className="-mx-2 -my-1.5 flex">
                  <button
                    onClick={() => setShowAnomalyReport(false)}
                    className="px-3 py-2 rounded-md text-sm font-medium text-red-800 hover:bg-red-100"
                  >
                    Dismiss
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Legend */}
      <div className="bg-white rounded-lg shadow p-4">
        <h3 className="text-sm font-semibold text-gray-700 mb-3">Map Legend</h3>
        <div className="flex flex-wrap gap-6 text-sm">
          <div className="flex items-center">
            <div className="w-4 h-4 rounded-full bg-gradient-to-r from-blue-500 via-green-500 to-red-500 mr-2" />
            <span className="text-gray-600">Heatmap intensity (blue=low, red=high)</span>
          </div>
          <div className="flex items-center">
            <div className="w-4 h-4 rounded-full border-2 border-dashed border-blue-500 mr-2" />
            <span className="text-gray-600">School allowed zone (50m radius)</span>
          </div>
          <div className="flex items-center">
            <div className="w-4 h-4 bg-red-500 mr-2" style={{ 
              clipPath: 'polygon(50% 0%, 100% 100%, 0% 100%)' 
            }} />
            <span className="text-gray-600">Outside zone anomaly marker</span>
          </div>
        </div>
      </div>

      {/* Main Heatmap Component */}
      <LocationHeatmap 
        schoolId={user?.role_type === 'super_admin' ? undefined : user?.school_id} 
      />

      {/* Usage Instructions */}
      <div className="bg-gray-50 rounded-lg p-4">
        <h3 className="text-sm font-semibold text-gray-700 mb-2">How to Use</h3>
        <ul className="text-sm text-gray-600 space-y-1 list-disc list-inside">
          <li>Use the sidebar to filter by specific teacher</li>
          <li>Change date range using the dropdown (7/14/30 days)</li>
          <li>Toggle heatmap visualization on/off with the checkbox</li>
          <li>Click on red anomaly markers to see scan details</li>
          <li>Teachers with ⚠️ badge have scans outside the allowed zone</li>
          <li>The dashed blue circle shows the school's allowed scanning zone</li>
        </ul>
      </div>
    </div>
  );
};

export default TeacherHeatmapPage;
