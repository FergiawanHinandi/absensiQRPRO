import { useEffect, useRef, useState, useMemo } from 'react';
import { MapContainer, TileLayer, Circle, Marker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.heat';
import { 
  useHeatmapData, 
  useClusterDetails, 
  useTeacherSummary,
  type HeatmapParams, 
  type HeatmapPoint 
} from '../hooks/useTeacherHeatmap';

// Fix default marker icon issue with Leaflet + React
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl;
L.Icon.Default.mergeOptions({
  iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon-2x.png',
  iconUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-icon.png',
  shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
});

// Custom anomaly marker icon (red)
const anomalyIcon = new L.Icon({
  iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
  shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
});

// ============================
// HeatmapLayer Component
// ============================

interface HeatmapLayerProps {
  points: HeatmapPoint[];
  showHeatmap: boolean;
}

const HeatmapLayer = ({ points, showHeatmap }: HeatmapLayerProps) => {
  const map = useMap();
  const heatLayerRef = useRef<L.HeatLayer | null>(null);

  useEffect(() => {
    if (!showHeatmap) {
      if (heatLayerRef.current) {
        map.removeLayer(heatLayerRef.current);
        heatLayerRef.current = null;
      }
      return;
    }

    // Convert points to heat layer format [lat, lng, intensity]
    const heatData: [number, number, number][] = points.map((p) => [
      p.lat,
      p.lng,
      Math.min(p.count / 10, 1), // Normalize intensity
    ]);

    if (heatLayerRef.current) {
      map.removeLayer(heatLayerRef.current);
    }

    heatLayerRef.current = L.heatLayer(heatData, {
      radius: 25,
      blur: 15,
      maxZoom: 18,
      max: 1.0,
      gradient: {
        0.0: 'blue',
        0.25: 'cyan',
        0.5: 'lime',
        0.75: 'yellow',
        1.0: 'red',
      },
    }).addTo(map);

    return () => {
      if (heatLayerRef.current) {
        map.removeLayer(heatLayerRef.current);
        heatLayerRef.current = null;
      }
    };
  }, [map, points, showHeatmap]);

  return null;
};

// ============================
// MapController Component
// ============================

interface MapControllerProps {
  center: [number, number];
  zoom: number;
}

const MapController = ({ center, zoom }: MapControllerProps) => {
  const map = useMap();

  useEffect(() => {
    map.setView(center, zoom);
  }, [map, center, zoom]);

  return null;
};

// ============================
// ClusterDetailPanel Component
// ============================

interface ClusterDetailPanelProps {
  lat: number;
  lng: number;
  params: Omit<HeatmapParams, 'teacher_id'>;
  onClose: () => void;
}

const ClusterDetailPanel = ({ lat, lng, params, onClose }: ClusterDetailPanelProps) => {
  const { data, isLoading } = useClusterDetails(lat, lng, params);

  return (
    <div className="absolute top-4 right-4 bg-white rounded-lg shadow-xl p-4 z-[1000] w-80 max-h-96 overflow-y-auto">
      <div className="flex justify-between items-center mb-3">
        <h3 className="font-semibold text-gray-900">Cluster Details</h3>
        <button
          onClick={onClose}
          className="text-gray-500 hover:text-gray-700"
        >
          ✕
        </button>
      </div>

      {isLoading && (
        <div className="text-center py-4 text-gray-500">Loading...</div>
      )}

      {data && (
        <>
          <div className="text-sm text-gray-600 mb-3">
            <p>Total Scans: <span className="font-medium">{data.total_scans}</span></p>
            <p>Students Scanned: <span className="font-medium">{data.total_students}</span></p>
          </div>

          <div className="space-y-3">
            {data.teachers.map((teacher) => (
              <div
                key={teacher.teacher_id}
                className="p-2 bg-gray-50 rounded border border-gray-200"
              >
                <p className="font-medium text-gray-900">{teacher.teacher_name}</p>
                <p className="text-xs text-gray-600">
                  Scans: {teacher.scan_count} | Students: {teacher.students_scanned}
                </p>
                <p className="text-xs text-gray-500">
                  {new Date(teacher.first_scan).toLocaleString()} - {new Date(teacher.last_scan).toLocaleString()}
                </p>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
};

// ============================
// TeacherSidebar Component
// ============================

interface TeacherSidebarProps {
  params: Omit<HeatmapParams, 'teacher_id'>;
  selectedTeacher: number | null;
  onSelectTeacher: (id: number | null) => void;
}

const TeacherSidebar = ({ params, selectedTeacher, onSelectTeacher }: TeacherSidebarProps) => {
  const { data: teachers, isLoading } = useTeacherSummary(params);

  return (
    <div className="w-72 bg-white border-r border-gray-200 overflow-y-auto">
      <div className="p-4 border-b border-gray-200">
        <h3 className="font-semibold text-gray-900">Teachers</h3>
        <button
          onClick={() => onSelectTeacher(null)}
          className={`mt-2 w-full text-left px-3 py-2 rounded text-sm ${
            selectedTeacher === null
              ? 'bg-blue-100 text-blue-700'
              : 'hover:bg-gray-100'
          }`}
        >
          All Teachers
        </button>
      </div>

      {isLoading && (
        <div className="p-4 text-center text-gray-500">Loading...</div>
      )}

      <div className="divide-y divide-gray-100">
        {teachers?.map((teacher) => (
          <button
            key={teacher.teacher_id}
            onClick={() => onSelectTeacher(teacher.teacher_id)}
            className={`w-full text-left p-3 hover:bg-gray-50 ${
              selectedTeacher === teacher.teacher_id ? 'bg-blue-50' : ''
            }`}
          >
            <div className="flex items-center justify-between">
              <span className="font-medium text-gray-900 text-sm">
                {teacher.teacher_name}
              </span>
              {teacher.has_outside_zone_scans && (
                <span className="px-2 py-0.5 bg-red-100 text-red-700 text-xs rounded-full">
                  ⚠️
                </span>
              )}
            </div>
            <div className="text-xs text-gray-500 mt-1">
              {teacher.total_scans} scans | {teacher.total_students} students
            </div>
            <div className="text-xs text-gray-400">
              Avg distance: {Math.round(teacher.avg_distance_from_school)}m
            </div>
          </button>
        ))}
      </div>
    </div>
  );
};

// ============================
// Main Component
// ============================

interface LocationHeatmapProps {
  schoolId?: number; // Optional for super admin
}

const LocationHeatmap = ({ schoolId }: LocationHeatmapProps) => {
  const [dateRange, setDateRange] = useState<'7d' | '14d' | '30d'>('7d');
  const [selectedTeacher, setSelectedTeacher] = useState<number | null>(null);
  const [showHeatmap, setShowHeatmap] = useState(true);
  const [showAnomalies, setShowAnomalies] = useState(true);
  const [selectedCluster, setSelectedCluster] = useState<{ lat: number; lng: number } | null>(null);

  const params: HeatmapParams = useMemo(
    () => ({
      range: dateRange,
      teacher_id: selectedTeacher ?? undefined,
      school_id: schoolId,
    }),
    [dateRange, selectedTeacher, schoolId]
  );

  const { data, isLoading, error } = useHeatmapData(params);

  // Filter anomalies from points
  const anomalyPoints = useMemo(
    () => data?.points.filter((p) => p.outside_zone) ?? [],
    [data]
  );

  // Default center (Indonesia) or school center
  const mapCenter: [number, number] = data?.school
    ? [data.school.latitude, data.school.longitude]
    : [-6.2088, 106.8456];

  const mapZoom = 17;

  if (error) {
    return (
      <div className="flex items-center justify-center h-full bg-red-50 text-red-600 p-8 rounded-lg">
        <p>Failed to load heatmap data. Please try again.</p>
      </div>
    );
  }

  return (
    <div className="flex h-[600px] bg-white rounded-lg shadow overflow-hidden">
      {/* Teacher Sidebar */}
      <TeacherSidebar
        params={{ range: dateRange, school_id: schoolId }}
        selectedTeacher={selectedTeacher}
        onSelectTeacher={setSelectedTeacher}
      />

      {/* Map Container */}
      <div className="flex-1 relative">
        {/* Controls */}
        <div className="absolute top-4 left-4 z-[1000] bg-white rounded-lg shadow p-3 space-y-3">
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">
              Date Range
            </label>
            <select
              value={dateRange}
              onChange={(e) => setDateRange(e.target.value as '7d' | '14d' | '30d')}
              className="w-full border border-gray-300 rounded px-2 py-1 text-sm"
            >
              <option value="7d">Last 7 Days</option>
              <option value="14d">Last 14 Days</option>
              <option value="30d">Last 30 Days</option>
            </select>
          </div>

          <div className="space-y-2">
            <label className="flex items-center text-sm">
              <input
                type="checkbox"
                checked={showHeatmap}
                onChange={(e) => setShowHeatmap(e.target.checked)}
                className="mr-2"
              />
              Show Heatmap
            </label>
            <label className="flex items-center text-sm">
              <input
                type="checkbox"
                checked={showAnomalies}
                onChange={(e) => setShowAnomalies(e.target.checked)}
                className="mr-2"
              />
              Show Anomalies
            </label>
          </div>
        </div>

        {/* Stats Panel */}
        {data && (
          <div className="absolute bottom-4 left-4 z-[1000] bg-white rounded-lg shadow p-3">
            <div className="text-xs space-y-1">
              <p>
                <span className="font-medium">{data.stats.total_clusters}</span> clusters
              </p>
              <p>
                <span className="font-medium">{data.stats.total_points}</span> total scans
              </p>
              {data.stats.outside_zone_count > 0 && (
                <p className="text-red-600">
                  <span className="font-medium">{data.stats.outside_zone_count}</span> outside zone
                </p>
              )}
            </div>
          </div>
        )}

        {/* Loading Overlay */}
        {isLoading && (
          <div className="absolute inset-0 bg-white/50 z-[1001] flex items-center justify-center">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
          </div>
        )}

        {/* Cluster Detail Panel */}
        {selectedCluster && (
          <ClusterDetailPanel
            lat={selectedCluster.lat}
            lng={selectedCluster.lng}
            params={{ range: dateRange, school_id: schoolId }}
            onClose={() => setSelectedCluster(null)}
          />
        )}

        {/* Map */}
        <MapContainer
          center={mapCenter}
          zoom={mapZoom}
          style={{ height: '100%', width: '100%' }}
          className="z-0"
        >
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />

          <MapController center={mapCenter} zoom={mapZoom} />

          {/* Heatmap Layer */}
          {data && <HeatmapLayer points={data.points} showHeatmap={showHeatmap} />}

          {/* School Zone Circle */}
          {data?.school && (
            <Circle
              center={[data.school.latitude, data.school.longitude]}
              radius={data.school.radius_meters}
              pathOptions={{
                color: '#3b82f6',
                fillColor: '#3b82f6',
                fillOpacity: 0.1,
                weight: 2,
                dashArray: '5, 5',
              }}
            >
              <Popup>
                <div className="text-sm">
                  <strong>{data.school.name}</strong>
                  <br />
                  Allowed Zone ({data.school.radius_meters}m radius)
                </div>
              </Popup>
            </Circle>
          )}

          {/* Anomaly Markers */}
          {showAnomalies &&
            anomalyPoints.map((point, idx) => (
              <Marker
                key={`anomaly-${idx}`}
                position={[point.lat, point.lng]}
                icon={anomalyIcon}
                eventHandlers={{
                  click: () => setSelectedCluster({ lat: point.lat, lng: point.lng }),
                }}
              >
                <Popup>
                  <div className="text-sm">
                    <p className="text-red-600 font-semibold">⚠️ Outside Zone</p>
                    <p>Distance: {Math.round(point.distance_from_school)}m from school</p>
                    <p>Scans: {point.count}</p>
                    <p>Students: {point.students_scanned}</p>
                    <button
                      className="mt-2 text-blue-600 underline"
                      onClick={(e) => {
                        e.stopPropagation();
                        setSelectedCluster({ lat: point.lat, lng: point.lng });
                      }}
                    >
                      View Details
                    </button>
                  </div>
                </Popup>
              </Marker>
            ))}
        </MapContainer>
      </div>
    </div>
  );
};

export default LocationHeatmap;
