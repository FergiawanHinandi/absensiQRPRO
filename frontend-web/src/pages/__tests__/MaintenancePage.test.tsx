import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import MaintenancePage from '../../pages/MaintenancePage';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';

// Mock tokenStore
vi.mock('../../lib/secureTokenStore', () => ({
  tokenStore: { setToken: vi.fn(), getToken: vi.fn(), clearToken: vi.fn(), hasToken: vi.fn() },
  sessionIndicator: { setActive: vi.fn(), wasActive: vi.fn(), clear: vi.fn() },
}));

vi.mock('../../modules/auth/services/authService', () => ({
  authService: { logout: vi.fn(), me: vi.fn() },
}));

describe('MaintenancePage', () => {
  beforeEach(() => {
    useAuthStore.setState({ user: null, isAuthenticated: false });
  });

  it('renders maintenance heading', () => {
    render(<MaintenancePage />);
    expect(screen.getByText(/sistem sedang dalam pemeliharaan/i)).toBeInTheDocument();
  });

  it('renders maintenance message', () => {
    render(<MaintenancePage />);
    expect(screen.getByText(/perawatan rutin/i)).toBeInTheDocument();
  });

  it('renders wait message', () => {
    render(<MaintenancePage />);
    expect(screen.getByText(/mohon tunggu/i)).toBeInTheDocument();
  });

  it('shows user email when logged in', () => {
    useAuthStore.setState({
      user: { id: 1, name: 'Admin', email: 'admin@test.com', role: 'admin' },
      isAuthenticated: true,
    });
    render(<MaintenancePage />);
    // The email is rendered as a text node, use textContent
    expect(screen.getByText(/admin@test.com/)).toBeInTheDocument();
  });

  it('does not show user section when not logged in', () => {
    render(<MaintenancePage />);
    expect(screen.queryByText(/user id/i)).not.toBeInTheDocument();
  });
});
