import React, { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { surveysApi } from '@entities/survey';
import {
  Button,
  Card,
  Select,
  Breadcrumb,
  Pagination,
  Skeleton,
  Modal,
  ModalBody,
  ModalFooter,
  PageHeader,
  Textarea,
  StatusBadge,
  type CustomBadgeColor,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { StatCard } from '@shared/ui';
import {IconDownload, IconEye, IconFlag, IconFilter, IconCalendar, IconUser, IconClock, IconUsers, IconChartBar} from '@tabler/icons-react';

interface SurveyResponse {
  id: number;
  respondent_type: 'public' | 'user';
  respondent_name: string | null;
  respondent_email: string | null;
  status: 'submitted' | 'invalid' | 'flagged';
  submitted_at: string;
  completion_time: number | null;
  answers_count: number;
}

interface Survey {
  id: number;
  code: string;
  title: string;
  fields_count: number;
}

interface PaginatedResponse {
  data: SurveyResponse[];
  current_page: number;
  last_page: number;
  total: number;
}

const statusLabels: Record<string, string> = {
  submitted: 'surveys.response_submitted',
  invalid: 'surveys.response_invalid',
  flagged: 'surveys.response_flagged',
};

const statusVariants: Record<string, CustomBadgeColor> = {
  submitted: 'success',
  invalid: 'danger',
  flagged: 'warning',
};

const SurveyResponses: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const [survey, setSurvey] = useState<Survey | null>(null);
  const [responses, setResponses] = useState<SurveyResponse[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });
  const [statusFilter, setStatusFilter] = useState('');
  const [selectedResponse, setSelectedResponse] = useState<SurveyResponse | null>(null);
  const [responseDetails, setResponseDetails] = useState<any>(null);
  const [loadingDetails, setLoadingDetails] = useState(false);
  const [flagModalOpen, setFlagModalOpen] = useState(false);
  const [flagNotes, setFlagNotes] = useState('');
  const [flagging, setFlagging] = useState(false);
  const { showToast } = useToast();

  const fetchSurvey = async () => {
    try {
      const response = await surveysApi.getById(Number(id));
      setSurvey(response as Survey);
    } catch (error) {
      console.error('Failed to fetch survey:', error);
    }
  };

  const fetchResponses = async (page = 1) => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(page) };
      if (statusFilter) params.status = statusFilter;

      const response = await surveysApi.getResponses(Number(id), params);
      const data = response as PaginatedResponse;
      setResponses(data.data);
      setPagination({
        currentPage: data.current_page || 1,
        lastPage: data.last_page || 1,
        total: data.total || 0,
      });
    } catch (error) {
      console.error('Failed to fetch responses:', error);
      showToast('error', t('surveys.responses_load_error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSurvey();
    fetchResponses();
  }, [id]);

  useEffect(() => {
    fetchResponses(1);
  }, [statusFilter]);

  const handleViewResponse = async (response: SurveyResponse) => {
    setSelectedResponse(response);
    setLoadingDetails(true);
    try {
      const result = await surveysApi.getResponse(Number(id), response.id);
      // الـ API يُرجع البيانات ملفوفة في data أو مباشرة
      const details = (result as any).data || result;
      setResponseDetails(details);
    } catch (error) {
      showToast('error', t('surveys.response_details_error'));
    } finally {
      setLoadingDetails(false);
    }
  };

  const handleFlag = async () => {
    if (!selectedResponse || !flagNotes.trim()) return;

    setFlagging(true);
    try {
      await surveysApi.flagResponse(Number(id), selectedResponse.id, flagNotes);
      showToast('success', t('surveys.response_flagged_success'));
      fetchResponses(pagination.currentPage);
      setSelectedResponse(null);
      setResponseDetails(null);
      setFlagModalOpen(false);
      setFlagNotes('');
    } catch (error) {
      showToast('error', t('surveys.response_flag_error'));
    } finally {
      setFlagging(false);
    }
  };

  const handleExport = async () => {
    try {
      const blob = await surveysApi.exportResponses(Number(id));
      const url = window.URL.createObjectURL(blob as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `survey-${survey?.code}-responses.csv`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
      showToast('success', t('surveys.export_success'));
    } catch (error) {
      showToast('error', t('surveys.export_error'));
    }
  };

  const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('ar-EG-u-nu-latn', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const formatDuration = (seconds: number | null) => {
    if (!seconds) return '-';
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
  };

  if (!survey && !loading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] gap-4">
        <p className="text-[var(--text-secondary)]">{t('surveys.not_found')}</p>
        <Link to="/surveys">
          <Button variant="secondary">{t('common.back_to_list')}</Button>
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Breadcrumb */}
      <Breadcrumb
        items={[
          { label: t('surveys.title'), href: '/surveys' },
          { label: survey?.title || t('surveys.survey'), href: `/surveys/${id}` },
          { label: t('surveys.responses') },
        ]}
      />

      <PageHeader
        title={`${t('surveys.responses')}: ${survey?.title}`}
        subtitle={`${pagination.total} ${t('surveys.response')} • ${survey?.fields_count} ${t('surveys.field')}`}
        icon={IconChartBar}
        iconTone="survey"
        actions={
          <Button variant="secondary" size="sm" leftIcon={<IconDownload className="h-4 w-4" />} onClick={handleExport}>
            {t('surveys.export_csv')}
          </Button>
        }
      />

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-4">
        <StatCard
          label={t('surveys.total_responses')}
          value={pagination.total}
          icon={IconUsers}
          color="accent"
        />
        <StatCard
          label={t('surveys.response_submitted')}
          value={responses.filter((r) => r.status === 'submitted').length}
          icon={IconUsers}
          color="success"
        />
        <StatCard
          label={t('surveys.response_flagged')}
          value={responses.filter((r) => r.status === 'flagged').length}
          icon={IconFlag}
          color="warning"
        />
        <StatCard
          label={t('surveys.response_invalid')}
          value={responses.filter((r) => r.status === 'invalid').length}
          icon={IconUsers}
          color="danger"
        />
      </div>

      {/* Filters */}
      <Card className="p-4 border border-[var(--border-default)]">
        <div className="flex items-center gap-4">
          <IconFilter className="w-4 h-4 text-[var(--text-secondary)]" />
          <Select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            options={[
              { value: '', label: t('common.all_statuses') },
              { value: 'submitted', label: t('surveys.response_submitted') },
              { value: 'flagged', label: t('surveys.response_flagged') },
              { value: 'invalid', label: t('surveys.response_invalid') },
            ]}
            className="w-40"
          />
        </div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Responses List */}
        <div className="lg:col-span-1">
          <Card className="border border-[var(--border-default)] overflow-hidden p-0">
            {loading ? (
              <div className="p-6 space-y-4">
                {[1, 2, 3, 4, 5].map((i) => (
                  <div key={i} className="flex items-center gap-4">
                    <Skeleton className="h-8 w-8 rounded-full" />
                    <div className="flex-1 space-y-2">
                      <Skeleton className="h-4 w-1/3" />
                      <Skeleton className="h-3 w-1/4" />
                    </div>
                    <Skeleton className="h-6 w-16 rounded-full" />
                  </div>
                ))}
              </div>
            ) : responses.length === 0 ? (
              <div className="text-center py-12 px-6">
                <IconUsers className="h-12 w-12 text-[var(--text-tertiary)] mx-auto mb-4" />
                <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('surveys.no_responses')}</h3>
                <p className="text-[var(--text-tertiary)]">{t('surveys.no_responses_yet')}</p>
              </div>
            ) : (
              <>
                <div className="divide-y divide-[var(--border-default)]">
                  {responses.map((response, index) => (
                    <div
                      key={response.id}
                      className={`p-3 cursor-pointer hover:bg-[var(--bg-secondary)] transition-colors ${
                        selectedResponse?.id === response.id ? 'bg-[var(--accent-subtle)]' : ''
                      }`}
                      onClick={() => handleViewResponse(response)}
                    >
                      <div className="flex items-center gap-3">
                        <span className="text-xs text-[var(--text-tertiary)] w-5">
                          {((pagination.currentPage || 1) - 1) * 15 + index + 1}
                        </span>
                        <div className="h-8 w-8 rounded-full bg-[var(--accent-subtle)] flex items-center justify-center shrink-0">
                          <IconUser className="w-4 h-4 text-[var(--accent-default)]" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium text-[var(--text-primary)] truncate">
                            {response.respondent_name || t('surveys.visitor')}
                          </p>
                          <p className="text-xs text-[var(--text-tertiary)]">
                            {formatDate(response.submitted_at)}
                          </p>
                        </div>
                        <StatusBadge
                          type="custom"
                          status={response.status}
                          label={t(statusLabels[response.status])}
                          color={statusVariants[response.status]}
                        />
                      </div>
                    </div>
                  ))}
                </div>

                {pagination.lastPage > 1 && (
                  <div className="p-4 border-t border-[var(--border-default)]">
                    <Pagination
                      currentPage={pagination.currentPage}
                      totalPages={pagination.lastPage}
                      onPageChange={(page) => fetchResponses(page)}
                    />
                  </div>
                )}
              </>
            )}
          </Card>
        </div>

        {/* Response Details */}
        <div className="lg:col-span-2">
          <Card className="p-6 sticky top-4 border border-[var(--border-default)]">
            {!selectedResponse ? (
              <div className="text-center py-12 text-[var(--text-tertiary)]">
                <IconEye className="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p>{t('surveys.select_response')}</p>
              </div>
            ) : loadingDetails ? (
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <Skeleton className="h-5 w-24" />
                  <Skeleton className="h-6 w-16 rounded-full" />
                </div>
                <div className="space-y-3">
                  <Skeleton className="h-4 w-32" />
                  <Skeleton className="h-4 w-40" />
                  <Skeleton className="h-4 w-28" />
                </div>
                <Skeleton className="h-px w-full" />
                <Skeleton className="h-4 w-16" />
                <div className="space-y-2">
                  <Skeleton className="h-20 w-full rounded-lg" />
                  <Skeleton className="h-20 w-full rounded-lg" />
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="font-semibold text-[var(--text-primary)]">{t('surveys.response_details')}</h3>
                  <StatusBadge
                    type="custom"
                    status={selectedResponse.status}
                    label={t(statusLabels[selectedResponse.status])}
                    color={statusVariants[selectedResponse.status]}
                  />
                </div>

                <div className="space-y-2 text-sm">
                  <div className="flex items-center gap-2 text-[var(--text-secondary)]">
                    <IconUser className="w-4 h-4" />
                    {selectedResponse.respondent_name || t('surveys.visitor')}
                  </div>
                  <div className="flex items-center gap-2 text-[var(--text-secondary)]">
                    <IconCalendar className="w-4 h-4" />
                    {formatDate(selectedResponse.submitted_at)}
                  </div>
                  <div className="flex items-center gap-2 text-[var(--text-secondary)]">
                    <IconClock className="w-4 h-4" />
                    {formatDuration(selectedResponse.completion_time)}
                  </div>
                </div>

                <hr className="border-[var(--border-primary)]" />

                {/* Answers */}
                {responseDetails?.answers && (
                  <div className="space-y-3">
                    <h4 className="font-medium text-[var(--text-primary)]">{t('surveys.answers')}</h4>
                    {responseDetails.answers.map((answer: any) => (
                      <div key={answer.id} className="p-3 rounded-lg bg-[var(--bg-secondary)]">
                        <p className="text-xs text-[var(--text-secondary)] mb-1">
                          {answer.field?.label || answer.field_key}
                        </p>
                        <p className="text-[var(--text-primary)]">
                          {answer.display_value || answer.answer_text || answer.answer_value || '-'}
                        </p>
                      </div>
                    ))}
                  </div>
                )}

                {/* Actions */}
                {selectedResponse.status === 'submitted' && (
                  <div className="flex items-center gap-2 pt-4">
                    <Button
                      variant="secondary"
                      size="sm"
                      leftIcon={<IconFlag className="w-4 h-4" />}
                      onClick={() => setFlagModalOpen(true)}
                      className="flex-1"
                    >
                      {t('surveys.flag')}
                    </Button>
                  </div>
                )}
              </div>
            )}
          </Card>
        </div>
      </div>

      {/* IconFlag Modal */}
      <Modal
        open={flagModalOpen}
        onClose={() => {
          setFlagModalOpen(false);
          setFlagNotes('');
        }}
        title={t('surveys.flag_response')}
        size="sm"
      >
        <ModalBody>
          <div className="space-y-4">
            <p className="text-sm text-[var(--text-secondary)]">
              {t('surveys.flag_reason_desc')}
            </p>
            <div>
              <Textarea
                value={flagNotes}
                onChange={(e) => setFlagNotes(e.target.value)}
                placeholder={t('surveys.flag_reason_placeholder')}
                rows={3}
                label={t('surveys.flag_reason')}
              />
            </div>
          </div>
        </ModalBody>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => {
              setFlagModalOpen(false);
              setFlagNotes('');
            }}
          >
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            onClick={handleFlag}
            loading={flagging}
            disabled={!flagNotes.trim()}
          >
            {t('surveys.flag')}
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  );
};

export default SurveyResponses;
