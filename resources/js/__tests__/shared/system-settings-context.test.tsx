/* global sessionStorage */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';
import React from 'react';

// Mock systemSettingsApi
const mockGet = vi.fn();

vi.mock('@shared/api/settings', () => ({
  systemSettingsApi: {
    get: () => mockGet(),
  },
}));

import { SystemSettingsProvider, useSystemSettings } from '@shared/contexts/SystemSettingsContext';

// Test component to consume the context
const TestConsumer = () => {
  const { settings, isLoading, error, refreshSettings } = useSystemSettings();
  return (
    <div>
      <span data-testid="loading">{isLoading.toString()}</span>
      <span data-testid="error">{error || 'no-error'}</span>
      <span data-testid="name">{settings?.name || 'no-name'}</span>
      <span data-testid="name_en">{settings?.name_en || 'no-name-en'}</span>
      <span data-testid="code">{settings?.code || 'no-code'}</span>
      <span data-testid="phone">{settings?.phone || 'no-phone'}</span>
      <span data-testid="email">{settings?.email || 'no-email'}</span>
      <span data-testid="website">{settings?.website || 'no-website'}</span>
      <button onClick={() => refreshSettings()}>Refresh</button>
    </div>
  );
};

describe('SystemSettingsContext Provider', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Reset document.title
    document.title = '';
    // Clear sessionStorage cache
    sessionStorage.clear();
  });

  afterEach(() => {
    sessionStorage.clear();
  });

  it('renders children', async () => {
    mockGet.mockResolvedValue({
      name: 'منصة إرادة',
      name_en: 'Erada System',
      code: 'ERA',
    });

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <div data-testid="child">Child Content</div>
        </SystemSettingsProvider>
      );
    });

    expect(screen.getByTestId('child')).toBeInTheDocument();
  });

  it('provides settings to consumers', async () => {
    mockGet.mockResolvedValue({
      name: 'منصة إرادة',
      name_en: 'Erada System',
      code: 'ERA',
    });

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    // Should eventually show settings
    await waitFor(() => {
      expect(screen.getByTestId('name')).toHaveTextContent('منصة إرادة');
    });
  });

  it('loads settings from API', async () => {
    const mockSettings = {
      id: 1,
      name: 'شركة الاختبار',
      name_en: 'Test Company',
      code: 'TST',
      phone: '0500000000',
      email: 'test@example.com',
      website: 'https://example.com',
    };
    mockGet.mockResolvedValue(mockSettings);

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('false');
    });

    expect(screen.getByTestId('name')).toHaveTextContent('شركة الاختبار');
    expect(screen.getByTestId('name_en')).toHaveTextContent('Test Company');
    expect(screen.getByTestId('code')).toHaveTextContent('TST');
    expect(screen.getByTestId('phone')).toHaveTextContent('0500000000');
    expect(screen.getByTestId('email')).toHaveTextContent('test@example.com');
    expect(screen.getByTestId('website')).toHaveTextContent('https://example.com');
  });

  it('uses default settings when API returns null', async () => {
    mockGet.mockResolvedValue(null);

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('false');
    });

    expect(screen.getByTestId('name')).toHaveTextContent('منصة إرادة');
    expect(screen.getByTestId('name_en')).toHaveTextContent('Erada System');
    expect(screen.getByTestId('code')).toHaveTextContent('IRADA');
  });

  it('uses default settings on API error', async () => {
    mockGet.mockRejectedValue(new Error('API Error'));

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('false');
    });

    expect(screen.getByTestId('name')).toHaveTextContent('منصة إرادة');
    // Error might or might not be shown depending on implementation
    expect(screen.getByTestId('error').textContent).toMatch(/API Error|no-error/);
  });

  it('updates document title', async () => {
    mockGet.mockResolvedValue({
      name: 'اسم المؤسسة',
      name_en: 'Organization Name',
      code: 'ORG',
    });

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(document.title).toBe('اسم المؤسسة');
    });
  });

  it('uses default name for document title when name is empty', async () => {
    mockGet.mockResolvedValue({
      name: '',
      name_en: 'Test',
      code: 'TST',
    });

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(document.title).toBe('منصة إرادة');
    });
  });

  it('refreshes settings when refresh is called', async () => {
    mockGet.mockResolvedValue({
      name: 'Initial Name',
      name_en: 'Initial',
      code: 'INI',
    });

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(screen.getByTestId('name')).toHaveTextContent('Initial Name');
    });

    // Update mock for refresh
    mockGet.mockResolvedValue({
      name: 'Updated Name',
      name_en: 'Updated',
      code: 'UPD',
    });

    // Click refresh button
    await act(async () => {
      screen.getByText('Refresh').click();
    });

    await waitFor(() => {
      expect(screen.getByTestId('name')).toHaveTextContent('Updated Name');
    });
  });

  it('fills empty values with defaults', async () => {
    mockGet.mockResolvedValue({
      id: 1,
      name: '',
      name_en: '',
      code: '',
      phone: '',
      email: '',
      website: '',
    });

    await act(async () => {
      render(
        <SystemSettingsProvider>
          <TestConsumer />
        </SystemSettingsProvider>
      );
    });

    await waitFor(() => {
      expect(screen.getByTestId('loading')).toHaveTextContent('false');
    });

    expect(screen.getByTestId('name')).toHaveTextContent('منصة إرادة');
    expect(screen.getByTestId('name_en')).toHaveTextContent('Erada System');
    expect(screen.getByTestId('code')).toHaveTextContent('IRADA');
  });
});

describe('useSystemSettings Hook', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
  });

  it('throws error when used outside provider', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

    const TestComponent = () => {
      useSystemSettings();
      return <div>Test</div>;
    };

    expect(() => render(<TestComponent />)).toThrow(
      'useSystemSettings must be used within a SystemSettingsProvider'
    );

    consoleError.mockRestore();
  });
});
