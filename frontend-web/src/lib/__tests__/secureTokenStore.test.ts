import { describe, it, expect, beforeEach } from 'vitest';
import { tokenStore, sessionIndicator } from '../../lib/secureTokenStore';

describe('tokenStore', () => {
  beforeEach(() => {
    sessionStorage.clear();
    tokenStore.clearToken();
  });

  it('stores and retrieves token', () => {
    tokenStore.setToken('test-token-123');
    expect(tokenStore.getToken()).toBe('test-token-123');
  });

  it('returns null when no token is set', () => {
    expect(tokenStore.getToken()).toBeNull();
  });

  it('hasToken returns true when token exists', () => {
    tokenStore.setToken('my-token');
    expect(tokenStore.hasToken()).toBe(true);
  });

  it('hasToken returns false when no token', () => {
    expect(tokenStore.hasToken()).toBe(false);
  });

  it('clearToken removes the token', () => {
    tokenStore.setToken('to-clear');
    tokenStore.clearToken();
    expect(tokenStore.getToken()).toBeNull();
  });

  it('returns null for expired token', () => {
    // Set token with 1 second expiry, then wait
    tokenStore.setToken('expire-soon', 1);
    // Manually expire it by modifying sessionStorage
    const expiryKey = '__auth_token_expiry__';
    sessionStorage.setItem(expiryKey, (Date.now() - 1000).toString());
    expect(tokenStore.getToken()).toBeNull();
  });

  it('getTimeUntilExpiry returns positive for valid token', () => {
    tokenStore.setToken('valid', 3600);
    expect(tokenStore.getTimeUntilExpiry()).toBeGreaterThan(0);
  });

  it('getTimeUntilExpiry returns 0 when no token', () => {
    expect(tokenStore.getTimeUntilExpiry()).toBe(0);
  });
});

describe('sessionIndicator', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });

  it('setActive marks session as active', () => {
    sessionIndicator.setActive();
    expect(sessionIndicator.wasActive()).toBe(true);
  });

  it('wasActive returns false when not set', () => {
    expect(sessionIndicator.wasActive()).toBe(false);
  });

  it('clear removes session indicator', () => {
    sessionIndicator.setActive();
    sessionIndicator.clear();
    expect(sessionIndicator.wasActive()).toBe(false);
  });
});
