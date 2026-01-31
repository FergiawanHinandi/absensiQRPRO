import { create } from 'zustand';

interface MaintenanceState {
    isMaintenance: boolean;
    setMaintenance: (status: boolean) => void;
}

export const useMaintenanceStore = create<MaintenanceState>((set) => ({
    isMaintenance: false,
    setMaintenance: (status) => set({ isMaintenance: status }),
}));
