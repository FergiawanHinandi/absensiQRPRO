import { describe, it, expect } from 'vitest';

describe('Test Setup', () => {
  it('should run tests correctly', () => {
    expect(1 + 1).toBe(2);
  });

  it('should have jsdom environment', () => {
    expect(document).toBeDefined();
    expect(window).toBeDefined();
  });
});
