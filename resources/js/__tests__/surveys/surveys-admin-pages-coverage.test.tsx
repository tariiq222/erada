import React from 'react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const surveysApiMock = {
  getAll: vi.fn(),
  publish: vi.fn(),
  close: vi.fn(),
  delete: vi.fn(),
};
const showToastMock = vi.fn();
const setSearchParamsMock = vi.fn();

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));
vi.mock('@entities/survey', () => ({ surveysApi: surveysApiMock }));
vi.mock('@shared/ui/Toast', () => ({ useToast: () => ({ showToast: showToastMock }) }));
vi.mock('@shared/ui', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@shared/ui')>()),
  FilterRow: ({ children, onClear, clearLabel }: any) => (
    <div data-testid="filter-row">{children}{onClear && <button onClick={onClear}>{clearLabel}</button>}</div>
  ),
  FilterField: ({ label, children }: any) => (<div data-testid="filter-field"><span>{label}</span>{children}</div>),
}));
vi.mock('@shared/contexts/AuthContext', () => ({ useAuth: () => ({ user: { id: 1 } }) }));
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useSearchParams: () => [new URLSearchParams('search=initial&type=initial&status=draft'), setSearchParamsMock],
  };
});

const surveyRows = [
  {
    id: 10,
    code: 'SRV-10',
    title: 'Draft survey',
    description: 'draft description',
    type: 'initial',
    status: 'draft',
    responses_count: 0,
    fields_count: 3,
    created_at: '2026-06-01T00:00:00Z',
  },
  {
    id: 11,
    code: 'SRV-11',
    title: 'Published survey',
    description: null,
    type: 'periodic',
    status: 'published',
    responses_count: 8,
    fields_count: 6,
    created_at: null,
  },
  {
    id: 12,
    code: 'SRV-12',
    title: 'Closed survey',
    description: 'closed description',
    type: 'final',
    status: 'closed',
    responses_count: 0,
    fields_count: 1,
    created_at: '2026-06-03T00:00:00Z',
  },
];

describe('surveys admin list coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollIntoView = vi.fn();
    surveysApiMock.getAll.mockResolvedValue({ data: surveyRows, current_page: 1, last_page: 2, total: 30 });
    surveysApiMock.publish.mockResolvedValue({});
    surveysApiMock.close.mockResolvedValue({});
    surveysApiMock.delete.mockResolvedValue({});
  });

  it('loads list, toggles filters, searches, paginates, publishes, closes, and deletes surveys', async () => {
    const user = userEvent.setup();
    const { default: SurveysList } = await import('@pages/surveys/SurveysList');

    render(<MemoryRouter><SurveysList /></MemoryRouter>);

    expect(await screen.findByText('Draft survey')).toBeInTheDocument();
    expect(screen.getByText('Published survey')).toBeInTheDocument();
    expect(screen.getByText('Closed survey')).toBeInTheDocument();
    expect(surveysApiMock.getAll).toHaveBeenCalledWith({ page: '1', search: 'initial', type: 'initial', status: 'draft' });

    await user.click(screen.getByRole('button', { name: 'common.filter' }));
    await user.clear(screen.getByPlaceholderText('surveys.search_placeholder'));
    await user.type(screen.getByPlaceholderText('surveys.search_placeholder'), 'governance');
    await user.click(screen.getByRole('button', { name: 'surveys.type_initial' }));
    await user.click(screen.getByRole('option', { name: 'surveys.type_periodic' }));
    await user.click(screen.getByRole('button', { name: 'status.draft' }));
    await user.click(screen.getByRole('option', { name: 'surveys.published' }));
    await user.click(screen.getByRole('button', { name: 'common.search' }));

    await waitFor(() => expect(setSearchParamsMock).toHaveBeenCalled());
    await waitFor(() => expect(surveysApiMock.getAll).toHaveBeenLastCalledWith({ page: '1', search: 'governance', type: 'periodic', status: 'published' }));

    await user.click(screen.getByRole('button', { name: 'common.next_page' }));
    await waitFor(() => expect(surveysApiMock.getAll).toHaveBeenLastCalledWith({ page: '2', search: 'governance', type: 'periodic', status: 'published' }));

    await user.click(screen.getByLabelText('surveys.publish'));
    await waitFor(() => expect(surveysApiMock.publish).toHaveBeenCalledWith(10));
    expect(showToastMock).toHaveBeenCalledWith('success', 'surveys.publish_success');

    await user.click(screen.getByLabelText('common.close'));
    await waitFor(() => expect(surveysApiMock.close).toHaveBeenCalledWith(11));
    expect(showToastMock).toHaveBeenCalledWith('success', 'surveys.close_success');

    await user.click(screen.getAllByLabelText('common.delete')[0]);
    expect(screen.getByText('surveys.delete_confirm_title')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'surveys.delete_confirm_button' }));
    await waitFor(() => expect(surveysApiMock.delete).toHaveBeenCalledWith(10));
    expect(showToastMock).toHaveBeenCalledWith('success', 'surveys.delete_success');
  });

  it('renders loading, empty, stats, and API error states for survey list components', async () => {
    const { default: SurveysTable } = await import('@pages/surveys/list/SurveysTable');
    const { default: StatsCards } = await import('@pages/surveys/list/StatsCards');
    const { default: SurveysList } = await import('@pages/surveys/SurveysList');

    const noop = vi.fn();
    const { rerender } = render(
      <MemoryRouter>
        <SurveysTable surveys={[]} loading pagination={{ currentPage: 1, lastPage: 1, total: 0 }} onPageChange={noop} onPublish={noop} onClose={noop} onDelete={noop} />
      </MemoryRouter>,
    );
    expect(document.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0);

    rerender(
      <MemoryRouter>
        <SurveysTable surveys={[]} loading={false} pagination={{ currentPage: 1, lastPage: 1, total: 0 }} onPageChange={noop} onPublish={noop} onClose={noop} onDelete={noop} />
      </MemoryRouter>,
    );
    expect(screen.getByText('surveys.no_surveys')).toBeInTheDocument();
    expect(screen.getByText('surveys.create_new')).toBeInTheDocument();

    rerender(<StatsCards surveys={surveyRows as any} total={30} />);
    expect(screen.getByText('surveys.total_surveys')).toBeInTheDocument();
    expect(screen.getByText('30')).toBeInTheDocument();
    expect(screen.getByText('8')).toBeInTheDocument();

    surveysApiMock.getAll.mockRejectedValueOnce(new Error('load failed'));
    render(<MemoryRouter><SurveysList /></MemoryRouter>);
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.load_error'));
  });

  it('reports action failures without weakening page behavior', async () => {
    const user = userEvent.setup();
    surveysApiMock.publish.mockRejectedValueOnce(new Error('publish failed'));
    surveysApiMock.close.mockRejectedValueOnce(new Error('close failed'));
    surveysApiMock.delete.mockRejectedValueOnce(new Error('delete failed'));
    const { default: SurveysList } = await import('@pages/surveys/SurveysList');

    render(<MemoryRouter><SurveysList /></MemoryRouter>);
    expect(await screen.findByText('Draft survey')).toBeInTheDocument();

    await user.click(screen.getByLabelText('surveys.publish'));
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.publish_error'));
    await user.click(screen.getByLabelText('common.close'));
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.close_error'));
    await user.click(screen.getAllByLabelText('common.delete')[0]);
    await user.click(screen.getByRole('button', { name: 'surveys.delete_confirm_button' }));
    await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'surveys.delete_error'));
  });
});
