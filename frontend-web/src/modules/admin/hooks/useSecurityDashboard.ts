import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

// =========================================================================
// TYPES
// =========================================================================

export interface SecuritySummary {
    alerts_last_24h: number;
    critical_alerts: number;
    high_severity_alerts: number;
    schools_with_alerts: number;
    most_common_event: string | null;
    most_common_event_count: number;
    unresolved_alerts: number;
}

export interface TrendDataPoint {
    date: string;
    total: number;
    critical: number;
    high: number;
    medium: number;
    low: number;
}

export interface AlertByType {
    event_type: string;
    label: string;
    count: number;
    critical_count: number;
}

export interface AlertBySchool {
    school_id: number;
    school_name: string;
    total_alerts: number;
    critical: number;
    high: number;
    unresolved: number;
}

export interface AlertBySeverity {
    severity: string;
    count: number;
}

export interface CriticalAlert {
    id: number;
    event_type: string;
    event_label: string;
    severity: string;
    description: string;
    user_name: string;
    user_email: string | null;
    user_role: string | null;
    school_name: string;
    school_id: number | null;
    ip_address: string | null;
    device_id: string | null;
    is_resolved: boolean;
    timestamp: string;
    time_ago: string;
    details: Record<string, any> | null;
}

// =========================================================================
// HOOKS
// =========================================================================

/**
 * Get security dashboard summary
 */
export const useSecuritySummary = () => {
    return useQuery({
        queryKey: ['securityDashboard', 'summary'],
        queryFn: async () => {
            try {
                const response = await apiClient.get('/admin/security/summary');
                return response.data.data as SecuritySummary;
            } catch (error) {
                // Fallback empty data to avoid dashboard crash
                return {
                    alerts_last_24h: 0,
                    critical_alerts: 0,
                    high_severity_alerts: 0,
                    schools_with_alerts: 0,
                    most_common_event: null,
                    most_common_event_count: 0,
                    unresolved_alerts: 0,
                } as SecuritySummary;
            }
        },
        refetchInterval: 30000, // 30 seconds
    });
};

/**
 * Get alert trend data for charts
 */
export const useSecurityTrend = (range: '7d' | '30d' = '7d') => {
    return useQuery({
        queryKey: ['securityDashboard', 'trend', range],
        queryFn: async () => {
            try {
                const response = await apiClient.get('/admin/security/trend', {
                    params: { range },
                });
                return response.data.data as TrendDataPoint[];
            } catch (error) {
                // Fallback empty array to avoid dashboard crash
                return [] as TrendDataPoint[];
            }
        },
        refetchInterval: 60000, // 1 minute
    });
};

/**
 * Get alerts grouped by type
 */
export const useSecurityByType = (range: '7d' | '30d' = '7d') => {
    return useQuery({
        queryKey: ['securityDashboard', 'byType', range],
        queryFn: async () => {
            try {
                const response = await apiClient.get('/admin/security/by-type', {
                    params: { range },
                });
                return response.data.data as AlertByType[];
            } catch (error) {
                // Fallback empty array to avoid dashboard crash
                return [] as AlertByType[];
            }
        },
        refetchInterval: 60000,
    });
};

/**
 * Get alerts grouped by school
 */
export const useSecurityBySchool = (range: '7d' | '30d' = '7d') => {
    return useQuery({
        queryKey: ['securityDashboard', 'bySchool', range],
        queryFn: async () => {
            try {
                const response = await apiClient.get('/admin/security/by-school', {
                    params: { range },
                });
                return response.data.data as AlertBySchool[];
            } catch (error) {
                // Fallback empty array to avoid dashboard crash
                return [] as AlertBySchool[];
            }
        },
        refetchInterval: 60000,
    });
};

/**
 * Get alerts grouped by severity
 */
export const useSecurityBySeverity = (range: '7d' | '30d' = '7d') => {
    return useQuery({
        queryKey: ['securityDashboard', 'bySeverity', range],
        queryFn: async () => {
            try {
                const response = await apiClient.get('/admin/security/by-severity', {
                    params: { range },
                });
                return response.data.data as AlertBySeverity[];
            } catch (error) {
                // Fallback empty array to avoid dashboard crash
                return [] as AlertBySeverity[];
            }
        },
        refetchInterval: 60000,
    });
};

/**
 * Get recent critical alerts
 */
export const useCriticalAlerts = (limit: number = 20) => {
    return useQuery({
        queryKey: ['securityDashboard', 'criticalRecent', limit],
        queryFn: async () => {
            try {
                const response = await apiClient.get('/admin/security/critical-recent', {
                    params: { limit },
                });
                return response.data.data as CriticalAlert[];
            } catch (error) {
                // Fallback empty array to avoid dashboard crash
                return [] as CriticalAlert[];
            }
        },
        refetchInterval: 30000, // 30 seconds for live feed
    });
};

/**
 * Acknowledge a single alert
 */
export const useAcknowledgeAlert = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (alertId: number) => {
            try {
                const response = await apiClient.patch(`/admin/security-alerts/${alertId}/ack`);
                return response.data;
            } catch (error) {
                // Re-throw error for mutation error handling
                throw error;
            }
        },
        onSuccess: () => {
            // Invalidate all security dashboard queries
            queryClient.invalidateQueries({ queryKey: ['securityDashboard'] });
        },
    });
};

/**
 * Bulk acknowledge alerts
 */
export const useBulkAcknowledgeAlerts = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (ids: number[]) => {
            const response = await apiClient.post('/admin/security-alerts/bulk-ack', { ids });
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['securityDashboard'] });
        },
    });
};
