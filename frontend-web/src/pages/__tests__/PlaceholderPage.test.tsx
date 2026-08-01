import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import PlaceholderPage from '../../pages/PlaceholderPage';

describe('PlaceholderPage', () => {
  it('renders title', () => {
    render(<PlaceholderPage title="Coming Soon" />);
    expect(screen.getByText('Coming Soon')).toBeInTheDocument();
  });

  it('renders under development message', () => {
    render(<PlaceholderPage title="Test" />);
    expect(screen.getByText(/dalam tahap pengembangan/i)).toBeInTheDocument();
  });
});
