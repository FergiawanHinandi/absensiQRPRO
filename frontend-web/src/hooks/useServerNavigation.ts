import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../lib/api';

export interface NavigationItem {
  label: string;
  path: string;
  icon: string;
  section?: string;
  badge?: string | number;
  children?: NavigationItem[];
}

/**
 * Get navigation config from server based on user role
 * Server determines what user can see
 * 
 * @returns Navigation items for current user
 */
export const useServerNavigation = () => {
  return useQuery({
    queryKey: ['navigation'],
    queryFn: async () => {
      const response = await apiClient.get<NavigationItem[]>('/auth/navigation');
      return response.data;
    },
    staleTime: 5 * 60 * 1000, // Cache for 5 minutes
    retry: 1,
  });
};
