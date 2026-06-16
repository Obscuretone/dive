import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import App from './App';

beforeEach(() => {
  global.fetch = vi.fn(() =>
    Promise.resolve({
      ok: true,
      json: () => Promise.resolve({ tasks: [] }),
    }),
  );
});

afterEach(() => {
  vi.restoreAllMocks();
});

test('renders Dive task shell', async () => {
  render(<App />);

  expect(await screen.findByText(/0 open tasks/i)).toBeInTheDocument();
  expect(screen.getByText(/Dive/i)).toBeInTheDocument();
  expect(screen.getByText(/Pick a task/i)).toBeInTheDocument();
});
