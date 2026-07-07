import React, { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { surveysApi } from '@entities/survey';
import { Button, DeleteConfirmationModal, PageHeader, IconPlus, IconClipboardList, IconFilter } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {
  type Survey,
  type PaginatedResponse,
  FiltersCard,
  SurveysTable,
} from './list';

const SurveysList: React.FC = () => {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [surveys, setSurveys] = useState<Survey[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    total: 0,
  });

  const [filters, setFilters] = useState({
    search: searchParams.get('search') || '',
    type: searchParams.get('type') || '',
    status: searchParams.get('status') || '',
  });

  const [showFilters, setShowFilters] = useState(false);

  // Delete confirmation modal state
  const [deleteModal, setDeleteModal] = useState<{
    isOpen: boolean;
    survey: Survey | null;
  }>({ isOpen: false, survey: null });
  const [isDeleting, setIsDeleting] = useState(false);

  const { showToast } = useToast();

  const fetchSurveys = async (page = 1) => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(page) };
      if (filters.search) params.search = filters.search;
      if (filters.type) params.type = filters.type;
      if (filters.status) params.status = filters.status;

      const response = (await surveysApi.getAll(params)) as PaginatedResponse;
      setSurveys(response.data);
      setPagination({
        currentPage: response.current_page,
        lastPage: response.last_page,
        total: response.total,
      });
    } catch (error) {
      console.error('Failed to fetch surveys:', error);
      showToast('error', t('surveys.load_error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSurveys();
  }, []);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const params = new URLSearchParams();
    if (filters.search) params.set('search', filters.search);
    if (filters.type) params.set('type', filters.type);
    if (filters.status) params.set('status', filters.status);
    setSearchParams(params);
    fetchSurveys(1);
  };

  const handlePageChange = (page: number) => {
    fetchSurveys(page);
  };

  const handlePublish = async (survey: Survey) => {
    try {
      await surveysApi.publish(survey.id);
      showToast('success', t('surveys.publish_success'));
      fetchSurveys(pagination.currentPage);
    } catch (error) {
      showToast('error', t('surveys.publish_error'));
    }
  };

  const handleClose = async (survey: Survey) => {
    try {
      await surveysApi.close(survey.id);
      showToast('success', t('surveys.close_success'));
      fetchSurveys(pagination.currentPage);
    } catch (error) {
      showToast('error', t('surveys.close_error'));
    }
  };

  // فتح نافذة تأكيد الحذف
  const openDeleteModal = (survey: Survey) => {
    setDeleteModal({ isOpen: true, survey });
  };

  // تأكيد حذف الاستبيان
  const handleConfirmDelete = async () => {
    if (!deleteModal.survey) return;

    setIsDeleting(true);
    try {
      await surveysApi.delete(deleteModal.survey.id);
      showToast('success', t('surveys.delete_success'));
      setDeleteModal({ isOpen: false, survey: null });
      fetchSurveys(pagination.currentPage);
    } catch {
      showToast('error', t('surveys.delete_error'));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={t('surveys.title')}
        subtitle={t('surveys.subtitle')}
        icon={IconClipboardList}
        iconTone="survey"
        actions={
          <>
            <Button
              variant={showFilters ? 'secondary' : 'outline'}
              size="sm"
              leftIcon={<IconFilter className="h-4 w-4" />}
              onClick={() => setShowFilters(!showFilters)}
            >
              {t('common.filter')}
            </Button>
            <Link to="/surveys/create">
              <Button leftIcon={<IconPlus className="h-4 w-4" />} size="sm">
                {t('surveys.new')}
              </Button>
            </Link>
          </>
        }
      />

      {/* Filters */}
      {showFilters && (
        <FiltersCard
          filters={filters}
          onFiltersChange={setFilters}
          onSearch={handleSearch}
          onClose={() => setShowFilters(false)}
        />
      )}

      {/* Table */}
      <SurveysTable
        surveys={surveys}
        loading={loading}
        pagination={pagination}
        onPageChange={handlePageChange}
        onPublish={handlePublish}
        onClose={handleClose}
        onDelete={openDeleteModal}
      />

      {/* Delete Survey Confirmation Modal */}
      <DeleteConfirmationModal
        isOpen={deleteModal.isOpen}
        item={deleteModal.survey}
        onClose={() => setDeleteModal({ isOpen: false, survey: null })}
        onConfirm={handleConfirmDelete}
        title={t('surveys.delete_confirm_title')}
        itemName={deleteModal.survey?.title || ''}
        itemSubtitle={t('surveys.survey')}
        warningMessage={t('common.action_irreversible')}
        confirmButtonText={t('surveys.delete_confirm_button')}
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default SurveysList;
