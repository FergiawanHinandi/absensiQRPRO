import { apiClient } from '../../../lib/api';

export interface SubscriptionPackage {
    id: number;
    name: string;
    description: string;
    price: number;
    duration_days: number;
    is_popular: boolean;
    features: Record<string, string | number | boolean>;
}

export interface PurchaseResponse {
    success: boolean;
    token: string;
    redirect_url: string;
    payment: unknown;
}

export const billingService = {
    getPackages: async (): Promise<SubscriptionPackage[]> => {
        const { data } = await apiClient.get<{ data: SubscriptionPackage[] }>('/admin/subscription/packages');
        return data.data;
    },

    purchasePackage: async (packageId: number): Promise<PurchaseResponse> => {
        const { data } = await apiClient.post<PurchaseResponse>('/admin/subscription/purchase', { package_id: packageId });
        return data;
    }
};
