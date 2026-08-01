import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Alert, AlertDescription } from '../alert';

describe('Alert Component', () => {
  it('renders children', () => {
    render(<Alert>Alert content</Alert>);
    expect(screen.getByText('Alert content')).toBeInTheDocument();
  });

  it('applies default variant styles', () => {
    render(<Alert>Test</Alert>);
    const alert = screen.getByText('Test').closest('div')!;
    expect(alert.className).toContain('bg-blue-50');
  });

  it('applies destructive variant styles', () => {
    render(<Alert variant="destructive">Error</Alert>);
    const alert = screen.getByText('Error').closest('div')!;
    expect(alert.className).toContain('bg-red-50');
  });
});

describe('AlertDescription', () => {
  it('renders description text', () => {
    render(<AlertDescription>Details here</AlertDescription>);
    expect(screen.getByText('Details here')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(<AlertDescription className="custom">Text</AlertDescription>);
    expect(screen.getByText('Text').className).toContain('custom');
  });
});
