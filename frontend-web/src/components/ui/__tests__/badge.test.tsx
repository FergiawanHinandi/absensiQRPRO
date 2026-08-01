import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Badge } from '../badge';

describe('Badge Component', () => {
  it('renders children', () => {
    render(<Badge>New</Badge>);
    expect(screen.getByText('New')).toBeInTheDocument();
  });

  it('applies default variant styles', () => {
    render(<Badge>Test</Badge>);
    const badge = screen.getByText('Test');
    expect(badge.className).toContain('bg-blue-100');
  });

  it('applies destructive variant styles', () => {
    render(<Badge variant="destructive">Error</Badge>);
    expect(screen.getByText('Error').className).toContain('bg-red-100');
  });

  it('applies secondary variant styles', () => {
    render(<Badge variant="secondary">Info</Badge>);
    expect(screen.getByText('Info').className).toContain('bg-gray-100');
  });

  it('applies outline variant styles', () => {
    render(<Badge variant="outline">Tag</Badge>);
    expect(screen.getByText('Tag').className).toContain('border-gray-300');
  });
});
