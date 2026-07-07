import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { departmentsApi } from '@entities/hr';
import { programsApi, portfoliosApi } from '@entities/strategy';
import { usersApi } from '@entities/user';
import { Button, Card, DatePicker, Input, Select, Textarea, Breadcrumb, FormActions } from '@shared/ui';
import { PageHeader, FormSection } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconDeviceFloppy, IconX, IconRocket} from '@tabler/icons-react';

interface ProgramFormData {
  name: string;
  description: string;
  portfolio_id: number | '';
  department_id: number | '';
  budget: number | '';
  total_program_budget: number | '';
  start_date: string;
  end_date: string;
  weight: number;
  status: string;
  priority: string;
  owner_id: number | '';
  program_manager_id: number | '';
  executive_sponsor_id: number | '';
  progress_calculation_method: string;
  order: number;
}

interface Portfolio {
  id: number;
  code: string;
  name: string;
}

interface User {
  id: number;
  name: string;
}

interface Department {
  id: number;
  name: string;
}

const STATUSES = [
  { value: 'draft', labelKey: 'status.draft' },
  { value: 'planning', labelKey: 'status.planning' },
  { value: 'in_progress', labelKey: 'status.in_progress' },
  { value: 'on_hold', labelKey: 'status.on_hold' },
  { value: 'completed', labelKey: 'status.completed' },
  { value: 'cancelled', labelKey: 'status.cancelled' },
];

const PRIORITIES = [
  { value: 'low', labelKey: 'priority.low' },
  { value: 'medium', labelKey: 'priority.medium' },
  { value: 'high', labelKey: 'priority.high' },
  { value: 'critical', labelKey: 'priority.critical' },
];

const PROGRESS_METHODS = [
  { value: 'average', labelKey: 'strategy.progress_method_average' },
  { value: 'weighted', labelKey: 'strategy.progress_method_weighted' },
  { value: 'manual', labelKey: 'strategy.progress_method_manual' },
];

const ProgramForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const isEdit = !!id;

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [portfolios, setPortfolios] = useState<Portfolio[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [departments, setDepartments] = useState<Department[]>([]);
  const [formData, setFormData] = useState<ProgramFormData>({
    name: '',
    description: '',
    portfolio_id: '',
    department_id: '',
    budget: '',
    total_program_budget: '',
    start_date: '',
    end_date: '',
    weight: 1,
    status: 'draft',
    priority: 'medium',
    owner_id: '',
    program_manager_id: '',
    executive_sponsor_id: '',
    progress_calculation_method: 'average',
    order: 1,
  });

  useEffect(() => {
    fetchLookups();
    if (isEdit) {
      fetchProgram();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const fetchLookups = async () => {
    try {
      const portfoliosData = await portfoliosApi.getList();
      setPortfolios(portfoliosData as Portfolio[]);
      const usersData = await usersApi.getList();
      setUsers(usersData as User[]);
      const deptData = await departmentsApi.getList();
      setDepartments(deptData as Department[]);
    } catch (error) {
      console.error('Failed to fetch lookups:', error);
    }
  };

  const fetchProgram = async () => {
    setLoading(true);
    try {
      const data = (await programsApi.getOne(Number(id))) as any;
      setFormData({
        name: data.name || '',
        description: data.description || '',
        portfolio_id: data.portfolio_id || '',
        department_id: data.department_id || '',
        budget: data.budget || '',
        total_program_budget: data.total_program_budget || '',
        start_date: data.start_date || '',
        end_date: data.end_date || '',
        weight: data.weight || 1,
        status: data.status || 'draft',
        priority: data.priority || 'medium',
        owner_id: data.owner_id || '',
        program_manager_id: data.program_manager_id || '',
        executive_sponsor_id: data.executive_sponsor_id || '',
        progress_calculation_method: data.progress_calculation_method || 'average',
        order: data.order || 1,
      });
    } catch (error) {
      showToast('error', t('strategy.program_load_error'));
      navigate('/strategy/programs');
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      showToast('error', t('strategy.program_name_required'));
      return;
    }
    if (!formData.portfolio_id) {
      showToast('error', t('strategy.portfolio_required'));
      return;
    }
    setSaving(true);
    try {
      const submitData = {
        ...formData,
        portfolio_id: formData.portfolio_id || null,
        department_id: formData.department_id || null,
        owner_id: formData.owner_id || null,
        program_manager_id: formData.program_manager_id || null,
        executive_sponsor_id: formData.executive_sponsor_id || null,
        budget: formData.budget || null,
        total_program_budget: formData.total_program_budget || null,
      };
      if (isEdit) {
        await programsApi.update(Number(id), submitData);
        showToast('success', t('strategy.program_update_success'));
      } else {
        await programsApi.create(submitData);
        showToast('success', t('strategy.program_create_success'));
      }
      navigate('/strategy/programs');
    } catch (error: any) {
      showToast('error', error.message || t('strategy.program_save_error'));
    } finally {
      setSaving(false);
    }
  };

  const handleChange = (field: keyof ProgramFormData, value: string | number) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const handleSelectId = (field: keyof ProgramFormData, value: string) => {
    handleChange(field, value ? Number(value) : '');
  };

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--border-default)] border-t-[var(--accent-default)]" />
      </div>
    );
  }

  const portfolioOptions = portfolios.map((p) => ({ value: String(p.id), label: `${p.code} - ${p.name}` }));
  const userOptions = users.map((u) => ({ value: String(u.id), label: u.name }));
  const departmentOptions = departments.map((d) => ({ value: String(d.id), label: d.name }));

  return (
    <div className="space-y-5">
      <div className="space-y-4">
        <Breadcrumb
          items={[
            { label: t('strategy.executive_planning'), href: '/strategy' },
            { label: t('strategy.programs'), href: '/strategy/programs' },
            { label: isEdit ? t('strategy.edit_program') : t('strategy.new_program') },
          ]}
        />
        <PageHeader
          icon={IconRocket}
          iconTone="project"
          title={isEdit ? t('strategy.edit_program_title') : t('strategy.create_program_title')}
          subtitle={isEdit ? t('strategy.edit_program_desc') : t('strategy.create_program_desc')}
        />
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
          {/* العمود الرئيسي */}
          <Card className="space-y-8 p-5 sm:p-7 lg:col-span-2">
            <FormSection title={t('strategy.section_basic_info')}>
              <Input
                label={t('strategy.program_name')}
                type="text"
                value={formData.name}
                onChange={(e) => handleChange('name', e.target.value)}
                placeholder={t('strategy.program_name_placeholder')}
                required
              />
              <Textarea
                label={t('common.description')}
                value={formData.description}
                onChange={(e) => handleChange('description', e.target.value)}
                placeholder={t('strategy.program_desc_placeholder')}
                rows={3}
              />
              <Select
                label={t('strategy.portfolio')}
                placeholder={t('strategy.select_portfolio')}
                value={formData.portfolio_id ? String(formData.portfolio_id) : ''}
                onChange={(e) => handleSelectId('portfolio_id', e.target.value)}
                options={portfolioOptions}
                hint={t('strategy.portfolio_link_hint')}
                searchable
                required
              />
            </FormSection>

            <FormSection title={t('strategy.section_team')} columns={2}>
              <Select
                label={t('strategy.responsible_department')}
                placeholder={t('strategy.select_department')}
                value={formData.department_id ? String(formData.department_id) : ''}
                onChange={(e) => handleSelectId('department_id', e.target.value)}
                options={departmentOptions}
                searchable
              />
              <Select
                label={t('strategy.program_owner')}
                placeholder={t('strategy.select_owner')}
                value={formData.owner_id ? String(formData.owner_id) : ''}
                onChange={(e) => handleSelectId('owner_id', e.target.value)}
                options={userOptions}
                searchable
              />
              <Select
                label={t('strategy.program_manager')}
                placeholder={t('strategy.select_program_manager')}
                value={formData.program_manager_id ? String(formData.program_manager_id) : ''}
                onChange={(e) => handleSelectId('program_manager_id', e.target.value)}
                options={userOptions}
                searchable
              />
              <Select
                label={t('strategy.executive_sponsor')}
                placeholder={t('strategy.select_executive_sponsor')}
                value={formData.executive_sponsor_id ? String(formData.executive_sponsor_id) : ''}
                onChange={(e) => handleSelectId('executive_sponsor_id', e.target.value)}
                options={userOptions}
                searchable
              />
            </FormSection>

            <FormSection title={t('strategy.section_budget_weight')} columns={2}>
              <Input
                label={t('strategy.allocated_budget')}
                type="number"
                min={0}
                step={0.01}
                value={formData.budget}
                onChange={(e) => handleChange('budget', e.target.value ? Number(e.target.value) : '')}
                placeholder="0.00"
              />
              <Input
                label={t('strategy.total_program_budget')}
                type="number"
                min={0}
                step={0.01}
                value={formData.total_program_budget}
                onChange={(e) =>
                  handleChange('total_program_budget', e.target.value ? Number(e.target.value) : '')
                }
                placeholder="0.00"
              />
              <Input
                label={t('strategy.relative_weight')}
                type="number"
                min={0}
                max={100}
                step={0.1}
                value={formData.weight}
                onChange={(e) => handleChange('weight', parseFloat(e.target.value) || 1)}
                hint={t('strategy.relative_weight_hint')}
              />
              <Select
                label={t('strategy.progress_calculation_method')}
                value={formData.progress_calculation_method}
                onChange={(e) => handleChange('progress_calculation_method', e.target.value)}
                options={PROGRESS_METHODS.map((m) => ({ value: m.value, label: t(m.labelKey) }))}
              />
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
                <Select
                  label={t('common.priority')}
                  value={formData.priority}
                  onChange={(e) => handleChange('priority', e.target.value)}
                  options={PRIORITIES.map((p) => ({ value: p.value, label: t(p.labelKey) }))}
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
                onClick={() => navigate('/strategy/programs')}
              >
                {t('common.cancel')}
              </Button>
              <Button
                type="submit"
                loading={saving}
                leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
              >
                {isEdit ? t('common.save_changes') : t('strategy.create_program')}
              </Button>
            </FormActions>
          </aside>
        </div>
      </form>
    </div>
  );
};

export default ProgramForm;
