import { describe, it, expect, beforeEach } from 'vitest';
import { useMaintenanceStore } from '../maintenanceStore';

describe('useMaintenanceStore', () => {
  beforeEach(() => {
    useMaintenanceStore.setState({ isMaintenance: false });
  });

  it('defaults to not in maintenance', () => {
    expect(useMaintenanceStore.getState().isMaintenance).toBe(false);
  });

  it('setMaintenance(true) enables maintenance mode', () => {
    useMaintenanceStore.getState().setMaintenance(true);
    expect(useMaintenanceStore.getState().isMaintenance).toBe(true);
  });

  it('setMaintenance(false) disables maintenance mode', () => {
    useMaintenanceStore.getState().setMaintenance(true);
    useMaintenanceStore.getState().setMaintenance(false);
    expect(useMaintenanceStore.getState().isMaintenance).toBe(false);
  });
});
