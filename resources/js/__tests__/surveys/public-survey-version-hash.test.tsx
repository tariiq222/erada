import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { publicSurveysApi } from '@entities/survey';
import PublicSurveyPage from '@pages/surveys/PublicSurveyPage';
import { useToast } from '@shared/ui/Toast';

vi.mock('@entities/survey', () => ({
  publicSurveysApi: {
    getByCode: vi.fn(),
    submit: vi.fn(),
    getByInvitation: vi.fn(),
    submitByInvitation: vi.fn(),
  },
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: vi.fn(),
}));

vi.mock('@shared/ui', () => ({
  Button: ({ children, leftIcon: _leftIcon, rightIcon: _rightIcon, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & { leftIcon?: React.ReactNode; rightIcon?: React.ReactNode }) => (
    <button {...props}>{children}</button>
  ),
  Card: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => <div {...props}>{children}</div>,
  Input: (props: React.InputHTMLAttributes<HTMLInputElement>) => <input {...props} />,
  Select: (props: React.SelectHTMLAttributes<HTMLSelectElement> & { options?: Array<{ value: string; label: string }> }) => (
    <select {...props}>
      {props.options?.map((option) => (
        <option key={option.value} value={option.value}>{option.label}</option>
      ))}
    </select>
  ),
  Skeleton: (props: React.HTMLAttributes<HTMLDivElement>) => <div data-testid="skeleton" {...props} />,
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, string>) => {
      const translations: Record<string, string> = {
        'surveys.enter_field': `Enter ${params?.field ?? 'field'}`,
        'surveys.submit_answers': 'Submit answers',
        'surveys.submit_failed': 'Submit failed',
        'surveys.submitting': 'Submitting',
        'surveys.answers_protected': 'Answers protected',
        'surveys.submission_success': 'Submission success',
        'surveys.default_thank_you': 'Thank you',
      };

      return translations[key] ?? key;
    },
  }),
}));

const mockSurveyResponse = {
  data: {
    code: 'PUBLIC-001',
    title: 'Public Survey',
    description: null,
    welcome_message: null,
    thank_you_message: null,
    consent_required: false,
    consent_text: null,
    fields: [
      {
        id: 1,
        field_key: 'feedback',
        label: 'Feedback',
        description: null,
        type: 'text',
        config: {},
        is_required: false,
        order: 1,
        is_visible: true,
      },
    ],
    sections: [],
  },
  version_hash: 'version-hash-123',
};

const renderPage = () => render(
  <MemoryRouter initialEntries={["/s/PUBLIC-001"]}>
    <Routes>
      <Route path="/s/:code" element={<PublicSurveyPage />} />
    </Routes>
  </MemoryRouter>
);

describe('PublicSurveyPage version hash contract', () => {
  const showToast = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useToast).mockReturnValue({ showToast } as unknown as ReturnType<typeof useToast>);
    vi.mocked(publicSurveysApi.getByCode).mockResolvedValue(mockSurveyResponse);
    vi.mocked(publicSurveysApi.submit).mockResolvedValue({ data: { id: 1 } });
  });

  it('stores backend version_hash and includes it in the public submit payload', async () => {
    renderPage();

    await userEvent.type(await screen.findByPlaceholderText('Enter Feedback'), 'My answer');
    await userEvent.click(screen.getByRole('button', { name: 'Submit answers' }));

    await waitFor(() => {
      expect(publicSurveysApi.submit).toHaveBeenCalledWith('PUBLIC-001', expect.objectContaining({
        answers: { feedback: 'My answer' },
        version_hash: 'version-hash-123',
      }));
    });
  });

  it('shows backend version mismatch message and keeps entered answers on the form', async () => {
    vi.mocked(publicSurveysApi.submit).mockRejectedValueOnce({
      response: { data: { message: 'تم تحديث الاستبيان. يرجى تحديث الصفحة والمحاولة مرة أخرى.' } },
    });

    renderPage();

    const input = await screen.findByPlaceholderText('Enter Feedback');
    await userEvent.type(input, 'Keep this answer');
    await userEvent.click(screen.getByRole('button', { name: 'Submit answers' }));

    await waitFor(() => {
      expect(showToast).toHaveBeenCalledWith('error', 'تم تحديث الاستبيان. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
    });

    expect(screen.getByPlaceholderText('Enter Feedback')).toHaveValue('Keep this answer');
  });
});
