import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import { BrowserRouter } from 'react-router-dom';

// Mock react-i18next with real Arabic translations for the keys the component uses.
vi.mock('react-i18next', () => {
  const translations: Record<string, string> = {
    'strategy.goldenChain.unlinkedTag': 'غير مرتبط استراتيجيًا',
    'strategy.goldenChain.projectUnlinkedDesc':
      'هذا المشروع غير مرتبط ببرنامج أو هدف تنفيذي',
    'strategy.goldenChain.projectUnlinked': 'المشروع غير مرتبط',
    'strategy.goldenChain.title': 'السلسلة الذهبية',
    'strategy.goldenChain.loadError': 'فشل في تحميل السلسلة الذهبية',
    'strategy.programs.program': 'المبادرة',
    'strategy.portfolios.executiveCommitment': 'الالتزام التنفيذي',
    'projects.project': 'المشروع',
  };
  return {
    useTranslation: () => ({
      t: (key: string) => translations[key] ?? key,
      i18n: { changeLanguage: vi.fn(), language: 'ar' },
    }),
    Trans: ({ i18nKey }: { i18nKey: string }) => translations[i18nKey] ?? i18nKey,
    initReactI18next: { type: '3rdParty', init: vi.fn() },
  };
});

// Mock external I/O only (the strategy API). Leave the component's own logic
// (state, render branches, Link wiring) under test.
const mockGetGoldenChain = vi.fn();
vi.mock('@entities/strategy', () => ({
  strategyDashboardApi: {
    getGoldenChain: (...args: unknown[]) => mockGetGoldenChain(...args),
  },
}));

import GoldenChainTag from '../../pages/strategy/components/GoldenChainTag';

const fullChain = {
  portfolio: { id: 1, code: 'PF-001', name: 'الالتزام الأول' },
  program: { id: 2, code: 'PRG-001', name: 'المبادرة الأولى' },
  project: { id: 3, code: 'PRJ-001', name: 'المشروع الأول' },
};

const unlinkedChain = {
  portfolio: null,
  program: null,
  project: { id: 1, code: 'PRJ-001', name: 'مشروع غير مرتبط' },
};

const TestWrapper: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <BrowserRouter>{children}</BrowserRouter>
);

describe('GoldenChainTag', () => {
  beforeEach(() => {
    mockGetGoldenChain.mockReset();
  });

  it('renders nothing while the API call is in flight', () => {
    // Never-resolving promise keeps the component in loading state.
    mockGetGoldenChain.mockReturnValue(new Promise(() => {}));
    const { container } = render(
      <TestWrapper>
        <GoldenChainTag type="project" id={1} />
      </TestWrapper>,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing when the API call rejects', async () => {
    mockGetGoldenChain.mockRejectedValueOnce(new Error('boom'));
    const { container } = render(
      <TestWrapper>
        <GoldenChainTag type="project" id={1} />
      </TestWrapper>,
    );
    await waitFor(() => {
      expect(mockGetGoldenChain).toHaveBeenCalledTimes(1);
    });
    expect(container).toBeEmptyDOMElement();
  });

  it('renders a link pill to the program when the project is linked', async () => {
    mockGetGoldenChain.mockResolvedValueOnce(fullChain);
    render(
      <TestWrapper>
        <GoldenChainTag type="project" id={3} />
      </TestWrapper>,
    );
    const link = await screen.findByRole('link', { name: /المبادرة الأولى/ });
    expect(link).toHaveAttribute('href', '/strategy/programs/2');
    // The link title includes the portfolio name + program name.
    expect(link).toHaveAttribute('title', 'الالتزام الأول - المبادرة الأولى');
  });

  it('renders the warning pill when the project has no program', async () => {
    mockGetGoldenChain.mockResolvedValueOnce(unlinkedChain);
    render(
      <TestWrapper>
        <GoldenChainTag type="project" id={1} />
      </TestWrapper>,
    );
    const warning = await screen.findByText('غير مرتبط استراتيجيًا');
    // Non-clickable: it must not be a link.
    expect(warning.closest('a')).toBeNull();
    // The parent <span> carries the descriptive title.
    const pill = warning.parentElement;
    expect(pill).not.toBeNull();
    expect(pill!.tagName.toLowerCase()).toBe('span');
    expect(pill).toHaveAttribute(
      'title',
      'هذا المشروع غير مرتبط ببرنامج أو هدف تنفيذي',
    );
  });

  it('renders nothing for non-project entity types', async () => {
    mockGetGoldenChain.mockResolvedValueOnce(fullChain);
    const { container } = render(
      <TestWrapper>
        <GoldenChainTag type="portfolio" id={1} />
      </TestWrapper>,
    );
    await waitFor(() => {
      expect(mockGetGoldenChain).toHaveBeenCalledTimes(1);
    });
    expect(container).toBeEmptyDOMElement();
  });

  it('fetches the chain using the supplied type and id', async () => {
    mockGetGoldenChain.mockResolvedValueOnce(fullChain);
    render(
      <TestWrapper>
        <GoldenChainTag type="project" id={42} />
      </TestWrapper>,
    );
    await waitFor(() => {
      expect(mockGetGoldenChain).toHaveBeenCalledWith('project', 42);
    });
  });
});