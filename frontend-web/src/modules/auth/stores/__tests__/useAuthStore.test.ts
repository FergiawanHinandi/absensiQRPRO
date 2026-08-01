import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useAuthStore } from '../useAuthStore';
import { tokenStore } from '../../../../lib/secureTokenStore';

// Mock authService
vi.mock('../../services/authService', () => ({
  authService: {
    logout: vi.fn().mockResolvedValue({}),
    me: vi.fn().mockResolvedValue({ id: 1, name: 'Test User', role: 'student' }),
  },
}));

describe('useAuthStore', () => {
  beforeEach(() => {
    tokenStore.clearToken();
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: false,
      error: null,
      sessionExpired: false,
    });
  });

  it('starts with unauthenticated state', () => {
    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.user).toBeNull();
    expect(state.token).toBeNull();
  });

  it('login sets user and token', () => {
    const mockUser = { id: 1, name: 'John', role: 'student' as const };
    useAuthStore.getState().login('token-abc', mockUser);

    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(true);
    expect(state.user).toEqual(mockUser);
    expect(state.token).toBe('token-abc');
  });

  it('login stores token in tokenStore', () => {
    const mockUser = { id: 1, name: 'John', role: 'teacher' as const };
    useAuthStore.getState().login('stored-token', mockUser);
    expect(tokenStore.getToken()).toBe('stored-token');
  });

  it('logout clears state', () => {
    const mockUser = { id: 1, name: 'John', role: 'admin' as const };
    useAuthStore.getState().login('tok', mockUser);
    useAuthStore.getState().logout();

    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.user).toBeNull();
  });

  it('logout clears tokenStore', () => {
    const mockUser = { id: 1, name: 'John', role: 'student' as const };
    useAuthStore.getState().login('tok', mockUser);
    useAuthStore.getState().logout();
    expect(tokenStore.getToken()).toBeNull();
  });

  it('setToken updates token in state and store', () => {
    useAuthStore.getState().setToken('new-token');
    expect(useAuthStore.getState().token).toBe('new-token');
    expect(tokenStore.getToken()).toBe('new-token');
  });

  it('getToken retrieves from tokenStore', () => {
    tokenStore.setToken('retrieve-me');
    expect(useAuthStore.getState().getToken()).toBe('retrieve-me');
  });
});
