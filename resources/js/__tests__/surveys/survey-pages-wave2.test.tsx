import React from 'react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const navigateMock = vi.fn();
const showToastMock = vi.fn();
const surveysApiMock = {
  getById: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  publish: vi.fn(),
  close: vi.fn(),
  getResponses: vi.fn(),
  getResponse: vi.fn(),
  flagResponse: vi.fn(),
  exportResponses: vi.fn(),
};

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));
vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: showToastMock }) }));
vi.mock('@entities/survey', () => ({ surveysApi: surveysApiMock }));
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return { ...actual, useNavigate: () => navigateMock };
});

const publishedSurvey = {
  id: 42,
  code: 'SRV-42',
  title: 'Governance Survey',
  description: 'Detailed description',
  type: 'periodic',
  status: 'published',
  category: 'kpi',
  is_public: true,
  requires_auth: false,
  allow_multiple_responses: true,
  allow_edit_response: true,
  responses_count: 2,
  fields_count: 3,
  fields: [
    { id: 1, field_key: 'name', label: 'Name', type: 'text', is_required: true, order: 1 },
    { id: 2, field_key: 'rating', label: 'Rating', type: 'rating', is_required: false, order: 2 },
  ],
  published_at: '2026-06-01T00:00:00Z',
  starts_at: '2026-06-01T00:00:00Z',
  ends_at: '2026-07-01T00:00:00Z',
  welcome_message: 'Welcome',
  thank_you_message: 'Thanks',
  consent_required: true,
  consent_text: 'Consent text',
  created_at: '2026-05-01T00:00:00Z',
  public_url: 'https://example.test/s/SRV-42',
};

const draftSurvey = { ...publishedSurvey, status: 'draft', is_public: false, requires_auth: true };

const renderAt = async (path: string, element: React.ReactNode) => {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/surveys/create" element={element} />
        <Route path="/surveys/:id/edit" element={element} />
        <Route path="/surveys/:id" element={element} />
        <Route path="/surveys/:id/responses" element={element} />
      </Routes>
    </MemoryRouter>,
  );
  await act(async () => { await Promise.resolve(); });
};

describe('survey pages wave 2 coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollIntoView = vi.fn();
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue(undefined) }, configurable: true });
    Object.assign(window.URL, { createObjectURL: vi.fn(), revokeObjectURL: vi.fn() });
    vi.spyOn(window.URL, 'createObjectURL').mockReturnValue('blob:survey');
    vi.spyOn(window.URL, 'revokeObjectURL').mockImplementation(() => undefined);
    surveysApiMock.getById.mockResolvedValue(publishedSurvey);
    surveysApiMock.create.mockResolvedValue({ data: { id: 77 } });
    surveysApiMock.update.mockResolvedValue({});
    surveysApiMock.publish.mockResolvedValue({});
    surveysApiMock.close.mockResolvedValue({});
    surveysApiMock.getResponses.mockResolvedValue({
      data: [
        { id: 1, respondent_type: 'user', respondent_name: 'Sara', respondent_email: 's@example.test', status: 'submitted', submitted_at: '2026-06-01T10:00:00Z', completion_time: 125, answers_count: 3 },
        { id: 2, respondent_type: 'public', respondent_name: null, respondent_email: null, status: 'flagged', submitted_at: '2026-06-02T10:00:00Z', completion_time: null, answers_count: 2 },
      ],
      current_page: 1,
      last_page: 2,
      total: 2,
    });
    surveysApiMock.getResponse.mockResolvedValue({ data: { answers: [{ id: 11, field: { label: 'Name' }, display_value: 'Sara' }] } });
    surveysApiMock.flagResponse.mockResolvedValue({});
    surveysApiMock.exportResponses.mockResolvedValue(new Blob(['csv'], { type: 'text/csv' }));
  });

  it('creates a survey with access, consent, dates, and messages', async () => {
    const user = userEvent.setup();
    const { default: SurveyForm } = await import('@pages/surveys/SurveyForm');
    await renderAt('/surveys/create', <SurveyForm />);

    await user.type(screen.getByPlaceholderText('surveys.survey_title_placeholder'), 'New Survey');
    await user.type(screen.getByPlaceholderText('surveys.description_placeholder'), 'Description');
    await user.click(screen.getByRole('button', { name: 'surveys.type_initial_desc' }));
    await user.click(screen.getByRole('option', { name: 'surveys.type_periodic_desc' }));
    await user.click(screen.getByRole('button', { name: 'surveys.no_category' }));
    await user.click(screen.getByRole('option', { name: 'surveys.category_kpi' }));
    await user.click(screen.getByText('surveys.public_link'));
    await user.click(screen.getByText('surveys.requires_auth'));
    await user.click(screen.getByText('surveys.multiple_responses'));
    await user.click(screen.getByText('surveys.edit_response'));
    await user.type(document.querySelector('input[name="starts_at"]') as HTMLInputElement, '2026-06-01T09:00');
    await user.type(document.querySelector('input[name="ends_at"]') as HTMLInputElement, '2026-07-01T17:00');
    await user.click(screen.getByText('surveys.consent_required'));
    await user.type(screen.getByPlaceholderText('surveys.consent_text_placeholder'), 'Consent required');
    await user.type(screen.getByPlaceholderText('surveys.welcome_message_placeholder'), 'Welcome message');
    await user.type(screen.getByPlaceholderText('surveys.thank_you_message_placeholder'), 'Thank you message');
    await user.click(screen.getByRole('button', { name: 'surveys.create_and_continue' }));

    await waitFor(() => expect(surveysApiMock.create).toHaveBeenCalledWith(expect.objectContaining({
      title: 'New Survey',
      type: 'periodic',
      category: 'kpi',
      is_public: false,
      requires_auth: true,
      consent_text: 'Consent required',
      starts_at: '2026-06-01T09:00',
      ends_at: '2026-07-01T17:00',
    })));
    expect(showToastMock).toHaveBeenCalledWith('success', 'surveys.create_success');
    expect(navigateMock).toHaveBeenCalledWith('/surveys/77/builder');
  });

  it('edits an existing survey, handles fetch and save failures', async () => {
    const user = userEvent.setup();
    const { default: SurveyForm } = await import('@pages/surveys/SurveyForm');
    surveysApiMock.getById.mockResolvedValueOnce({ ...draftSurvey, starts_at: '2026-06-01T09:00:00Z', ends_at: null });
    await renderAt('/surveys/42/edit', <SurveyForm />);
    expect(await screen.findByDisplayValue('Governance Survey')).toBeInTheDocument();
    await user.clear(screen.getByPlaceholderText('surveys.survey_title_placeholder'));
    await user.type(screen.getByPlaceholderText('surveys.survey_title_placeholder'), 'Updated Survey');
    await user.click(screen.getByRole('button', { name: 'common.save_changes' }));
    await waitFor(() => expect(surveysApiMock.update).toHaveBeenCalledWith(42, expect.objectContaining({ title: 'Updated Survey' })));
    expect(navigateMock).toHaveBeenCalledWith('/surveys/42');

    surveysApiMock.update.mockRejectedValueOnce({ response: { data: { message: 'Save failed' } } });
    await user.click(screen.getByRole('button', { name: 'common.save_changes' }));
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'Save failed'));

    surveysApiMock.getById.mockRejectedValueOnce(new Error('missing'));
    await renderAt('/surveys/42/edit', <SurveyForm />);
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.load_error'));
  });

  it('shows, publishes, closes, copies, and handles not-found/errors on survey view', async () => {
    const user = userEvent.setup();
    const { default: SurveyView } = await import('@pages/surveys/SurveyView');
    surveysApiMock.getById.mockResolvedValueOnce(draftSurvey);
    await renderAt('/surveys/42', <SurveyView />);

    expect(await screen.findByRole('heading', { name: 'Governance Survey' })).toBeInTheDocument();
    expect(screen.getByText('Name')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'surveys.publish' }));
    expect(screen.getByText('surveys.publish_settings')).toBeInTheDocument();
    await user.click(screen.getByText('surveys.public_link'));
    await user.click(screen.getByRole('button', { name: 'surveys.confirm_publish' }));
    await waitFor(() => expect(surveysApiMock.publish).toHaveBeenCalledWith(42));

    surveysApiMock.getById.mockResolvedValue(publishedSurvey);
    await user.click(screen.getByRole('button', { name: 'surveys.copy_link' }));
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('success', 'surveys.link_copied'));
    await user.click(screen.getByRole('button', { name: 'common.close' }));
    await waitFor(() => expect(surveysApiMock.close).toHaveBeenCalledWith(42));

    cleanup();
    surveysApiMock.getById.mockResolvedValueOnce(null);
    await renderAt('/surveys/42', <SurveyView />);
    expect(await screen.findByText('surveys.not_found')).toBeInTheDocument();

    cleanup();
    surveysApiMock.getById.mockRejectedValueOnce(new Error('load failed'));
    await renderAt('/surveys/42', <SurveyView />);
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.load_error'));
  });

  it('lists responses, filters, views details, flags, exports, and handles errors', async () => {
    const user = userEvent.setup();
    const { default: SurveyResponses } = await import('@pages/surveys/SurveyResponses');
    await renderAt('/surveys/42/responses', <SurveyResponses />);
    expect(await screen.findByRole('heading', { name: /surveys.responses/ })).toBeInTheDocument();
    expect(screen.getByText('Sara')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'common.all_statuses' }));
    await user.click(screen.getByRole('option', { name: 'surveys.response_flagged' }));
    await waitFor(() => expect(surveysApiMock.getResponses).toHaveBeenLastCalledWith(42, { page: '1', status: 'flagged' }));

    await user.click(screen.getByText('Sara'));
    await waitFor(() => expect(surveysApiMock.getResponse).toHaveBeenCalledWith(42, 1));
    expect(await screen.findByText('Name')).toBeInTheDocument();
    expect(screen.getByText('2:05')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'surveys.flag' }));
    await user.type(screen.getByPlaceholderText('surveys.flag_reason_placeholder'), 'Suspicious answer');
    await user.click(screen.getAllByRole('button', { name: 'surveys.flag' }).at(-1)!);
    await waitFor(() => expect(surveysApiMock.flagResponse).toHaveBeenCalledWith(42, 1, 'Suspicious answer'));

    await user.click(screen.getByRole('button', { name: 'surveys.export_csv' }));
    await waitFor(() => expect(surveysApiMock.exportResponses).toHaveBeenCalledWith(42));
    expect(showToastMock).toHaveBeenCalledWith('success', 'surveys.export_success');

    surveysApiMock.getResponses.mockRejectedValueOnce(new Error('responses failed'));
    const pageButtons = screen.getAllByRole('button');
    await user.click(pageButtons[pageButtons.length - 1]);
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.responses_load_error'));
  });
});
