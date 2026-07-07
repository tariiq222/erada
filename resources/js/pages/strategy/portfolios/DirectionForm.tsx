import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { portfoliosApi } from '@entities/strategy';
import { Button, Card, DatePicker, Input, Select, Textarea, Breadcrumb, FormActions } from '@shared/ui';
import { PageHeader, FormSection } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconDeviceFloppy, IconX, IconBriefcase} from '@tabler/icons-react';

interface DirectionFormData {
  name: string;
  description: string;
  rationale: string;
  strategic_plan_link: string;
  directive_source: string;
  directive_source_other: string;
  start_date: string;
  end_date: string;
  status: string;
  order: number;
}

const DIRECTIVE_SOURCES = [
  { value: 'cluster_3', labelKey: 'strategy.source_cluster_3' },
  { value: 'moh', labelKey: 'strategy.source_moh' },
  { value: 'holding', labelKey: 'strategy.source_holding' },
  { value: 'other', labelKey: 'strategy.source_other' },
];

const STATUSES = [
  { value: 'draft', labelKey: 'status.draft' },
  { value: 'active', labelKey: 'common.active' },
  { value: 'completed', labelKey: 'status.completed' },
  { value: 'cancelled', labelKey: 'status.cancelled' },
];

const DirectionForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const isEdit = !!id;

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [formData, setFormData] = useState<DirectionFormData>({
    name: '',
    description: '',
    rationale: '',
    strategic_plan_link: '',
    directive_source: '',
    directive_source_other: '',
    start_date: '',
    end_date: '',
    status: 'draft',
    order: 1,
  });

  useEffect(() => {
    if (isEdit) {
      fetchDirection();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const fetchDirection = async () => {
    setLoading(true);
    try {
      const data = (await portfoliosApi.getOne(Number(id))) as any;
      setFormData({
        name: data.name || '',
        description: data.description || '',
        rationale: data.rationale || '',
        strategic_plan_link: data.strategic_plan_link || '',
        directive_source: data.directive_source || '',
        directive_source_other: data.directive_source_other || '',
        start_date: data.start_date || '',
        end_date: data.end_date || '',
        status: data.status || 'draft',
        order: data.order || 1,
      });
    } catch (error) {
      showToast('error', t('strategy.portfolio_load_error'));
      navigate('/strategy/portfolios');
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      showToast('error', t('strategy.portfolio_name_required'));
      return;
    }
    setSaving(true);
    try {
      if (isEdit) {
        await portfoliosApi.update(Number(id), formData);
        showToast('success', t('strategy.portfolio_update_success'));
      } else {
        await portfoliosApi.create(formData);
        showToast('success', t('strategy.portfolio_create_success'));
      }
      navigate('/strategy/portfolios');
    } catch (error: any) {
      showToast('error', error.message || t('strategy.portfolio_save_error'));
    } finally {
      setSaving(false);
    }
  };

  const handleChange = (field: keyof DirectionFormData, value: string | number) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--border-default)] border-t-[var(--accent-default)]" />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="space-y-4">
        <Breadcrumb
          items={[
            { label: t('strategy.executive_planning'), href: '/strategy' },
            { label: t('strategy.portfolios'), href: '/strategy/portfolios' },
            { label: isEdit ? t('strategy.edit_portfolio') : t('strategy.new_portfolio') },
          ]}
        />
        <PageHeader
          icon={IconBriefcase}
          iconTone="project"
          title={isEdit ? t('strategy.edit_portfolio_title') : t('strategy.create_portfolio_title')}
          subtitle={isEdit ? t('strategy.edit_portfolio_desc') : t('strategy.create_portfolio_desc')}
        />
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
          {/* العمود الرئيسي */}
          <Card className="space-y-8 p-5 sm:p-7 lg:col-span-2">
            <FormSection title={t('strategy.section_basic_info')}>
              <Input
                label={t('strategy.portfolio_name')}
                type="text"
                value={formData.name}
                onChange={(e) => handleChange('name', e.target.value)}
                placeholder={t('strategy.portfolio_name_placeholder')}
                required
              />
              <Textarea
                label={t('common.description')}
                value={formData.description}
                onChange={(e) => handleChange('description', e.target.value)}
                placeholder={t('strategy.portfolio_desc_placeholder')}
                rows={3}
              />
            </FormSection>

            <FormSection title={t('strategy.section_strategic_directive')} columns={2}>
              <Input
                label={t('strategy.strategic_plan_link_label')}
                type="text"
                value={formData.strategic_plan_link}
                onChange={(e) => handleChange('strategic_plan_link', e.target.value)}
                placeholder={t('strategy.strategic_plan_link_placeholder')}
                hint={t('strategy.strategic_plan_link_hint')}
              />
              <Select
                label={t('strategy.directive_source')}
                placeholder={t('strategy.select_directive_source')}
                value={formData.directive_source}
                onChange={(e) => handleChange('directive_source', e.target.value)}
                options={DIRECTIVE_SOURCES.map((s) => ({ value: s.value, label: t(s.labelKey) }))}
              />
              {formData.directive_source === 'other' && (
                <div className="sm:col-span-2">
                  <Input
                    label={t('strategy.other_source_name')}
                    type="text"
                    value={formData.directive_source_other}
                    onChange={(e) => handleChange('directive_source_other', e.target.value)}
                    placeholder={t('strategy.other_source_placeholder')}
                  />
                </div>
              )}
              <div className="sm:col-span-2">
                <Textarea
                  label={t('strategy.rationale')}
                  value={formData.rationale}
                  onChange={(e) => handleChange('rationale', e.target.value)}
                  placeholder={t('strategy.rationale_placeholder')}
                  rows={4}
                />
              </div>
            </FormSection>
          </Card>

          {/* العمود الجانبي */}
          <aside className="space-y-5">
            <Card className="space-y-5 p-5">
              <FormSection title={t('strategy.section_schedule_status')}>
                <DatePicker
                  label={t('common.start_date')}
                  value={formData.start_date}
                  onChange={(value) => handleChange('start_date', value)}
                />
                <DatePicker
                  label={t('common.end_date')}
                  value={formData.end_date}
                  onChange={(value) => handleChange('end_date', value)}
                />
                <Select
                  label={t('common.status')}
                  value={formData.status}
                  onChange={(e) => handleChange('status', e.target.value)}
                  options={STATUSES.map((s) => ({ value: s.value, label: t(s.labelKey) }))}
                />
                <Input
                  label={t('common.order')}
                  type="number"
                  min={1}
                  value={formData.order}
                  onChange={(e) => handleChange('order', parseInt(e.target.value) || 1)}
                />
              </FormSection>
            </Card>

            <FormActions>
              <Button
                type="button"
                variant="outline"
                leftIcon={<IconX className="h-4 w-4" />}
                onClick={() => navigate('/strategy/portfolios')}
              >
                {t('common.cancel')}
              </Button>
              <Button
                type="submit"
                loading={saving}
                leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
              >
                {isEdit ? t('common.save_changes') : t('strategy.create_portfolio')}
              </Button>
            </FormActions>
          </aside>
        </div>
      </form>
    </div>
  );
};

export default DirectionForm;
