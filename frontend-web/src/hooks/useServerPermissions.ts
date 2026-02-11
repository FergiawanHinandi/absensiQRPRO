import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../lib/api';

export interface Permission {
  name: string;
  description?: string;
}

/**
 * Get permissions from server for current user
 * Server determines what user can do
 * 
 * @returns User permissions
 */
export const useServerPermissions = () => {
  return useQuery({
    queryKey: ['permissions'],
    queryFn: async () => {
      const response = await apiClient.get<Permission[]>('/auth/permissions');
      return response.data;
    },
    staleTime: 5 * 60 * 1000, // Cache for 5 minutes
    retry: 1,
  });
};

/**
 * Check if user has a specific permission
 * 
 * @param permission Permission name to check
 * @returns true if user has permission
 */
export const useHasPermission = (permission: string): boolean => {
  const { data: permissions } = useServerPermissions();
  return permissions?.some(p => p.name === permission) ?? false;
};

/**
 * Check if user has any of the specified permissions
 * 
 * @param permissions Array of permission names
 * @returns true if user has at least one permission
 */
export const useHasAnyPermission = (permissions: string[]): boolean => {
  const { data: userPermissions } = useServerPermissions();
  return permissions.some(p => userPermissions?.some(up => up.name === p)) ?? false;
};

/**
 * Check if user has all of the specified permissions
 * 
 * @param permissions Array of permission names
 * @returns true if user has all permissions
 */
export const useHasAllPermissions = (permissions: string[]): boolean => {
  const { data: userPermissions } = useServerPermissions();
  return permissions.every(p => userPermissions?.some(up => up.name === p)) ?? false;
};
