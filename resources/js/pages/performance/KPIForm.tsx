import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {IconDeviceFloppy, IconTarget, IconX} from '@tabler/icons-react';
import { departmentsApi } from '@entities/hr';
import {
  performanceApi,
  type CreatePerformanceKPIRequest,
  type PerformanceKPILink,
} from '@entities/performance';
import { usersApi } from '@entities/user';
import { useOrganization } from '@shared/contexts/OrganizationContext';
import { Breadcrumb, Button, Card, Checkbox, FormActions, FormSection, Input, PageHeader, Select, Textarea } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { getErrorMessage, KPI_DIRECTIONS, KPI_FREQUENCIES, KPI_STATUSES } from './shared';

interface UserOption {
  id: number;
  name: string;
}

interface DepartmentOption {
  id: number;
  name: string;
  code?: string | null;
  level_name?: string | null;
}

interface KPIFormData {
  code: string;
  name: string;
  description: string;
  measurement_method: string;
  category: string;
  baseline: string;
  target: string;
  current_value: string;
  unit: string;
  frequency: string;
  direction: string;
  status: string;
  owner_id: string;
  order: string;
}

const emptyToNull = (value: string) => (value === '' ? null : value);
const optionalText = (value: string) => (value.trim() === '' ? undefined : value.trim());

const isDepartmentLink = (link: PerformanceKPILink) => {
  const normalizedType = String(link.linkable_type ?? '').toLowerCase().replace(/\\/g, '/');

  return normalizedType === 'department' || normalizedType.endsWith('/department');
};

const KPIForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const { currentOrganization } = useOrganization();
  const isEdit = !!id;

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [departments, setDepartments] = useState<DepartmentOption[]>([]);
  const [departmentSearch, setDepartmentSearch] = useState('');
  const [selectedDepartmentIds, setSelectedDepartmentIds] = useState<number[]>([]);
  const [formData, setFormData] = useState<KPIFormData>({
    code: '',
    name: '',
    description: '',
    measurement_method: '',
    category: '',
    baseline: '',
    target: '',
    current_value: '',
    unit: '',
    frequency: 'monthly',
    direction: 'increase',
    status: 'active',
    owner_id: '',
    order: '0',
  });

  useEffect(() => {
    (async () => {
      try {
        const [userData, departmentData] = await Promise.all([
          usersApi.getList(),
          departmentsApi.getList(),
        ]);
        setUsers(userData as UserOption[]);
        setDepartments(departmentData as DepartmentOption[]);
      } catch (error) {
        console.error('Failed to fetch KPI lookups:', error);
      }
    })();
  }, []);

  useEffect(() => {
    if (!isEdit) return;

    const fetchKpi = async () => {
      setLoading(true);
      try {
        const kpiId = Number(id);
        const [kpi, links] = await Promise.all([
          performanceApi.getKPI(kpiId),
          performanceApi.listLinks(kpiId, { per_page: 100 }),
        ]);

        setFormData({
          code: kpi.code || '',
          name: kpi.name || '',
          description: kpi.description || '',
          measurement_method: kpi.measurement_method || '',
          category: kpi.category || '',
          baseline: kpi.baseline == null ? '' : String(kpi.baseline),
          target: kpi.target == null ? '' : String(kpi.target),
          current_value: kpi.current_value == null ? '' : String(kpi.current_value),
          unit: kpi.unit || '',
          frequency: kpi.frequency || 'monthly',
          direction: kpi.direction || 'increase',
          status: kpi.status || 'active',
          owner_id: kpi.owner_id ? String(kpi.owner_id) : '',
          order: kpi.order == null ? '0' : String(kpi.order),
        });

        setSelectedDepartmentIds(
          links.data
            .filter(isDepartmentLink)
            .map((link) => Number(link.linkable_id))
            .filter((departmentId) => Number.isFinite(departmentId) && departmentId > 0)
        );
      } catch {
        showToast('error', t('performance.load_error'));
        navigate('/performance/kpis');
      } finally {
        setLoading(false);
      }
    };

    fetchKpi();
  }, [id, isEdit, navigate, showToast, t]);

  const filteredDepartments = departments.filter((department) => {
    const search = departmentSearch.trim().toLowerCase();

    if (!search) {
      return true;
    }

    return [department.name, department.code, department.level_name]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(search));
  });

  const handleChange = (field: keyof KPIFormData, value: string) => {
    setFormData((current) => ({ ...current, [field]: value }));
  };

  const toggleDepartment = (departmentId: number) => {
    setSelectedDepartmentIds((current) => (
      current.includes(departmentId)
        ? current.filter((id) => id !== departmentId)
        : [...current, departmentId]
    ));
  };

  const buildPayload = (): CreatePerformanceKPIRequest => ({
    ...(currentOrganization?.id ? { organization_id: currentOrganization.id } : {}),
    ...(optionalText(formData.code) ? { code: optionalText(formData.code) } : {}),
    name: formData.name.trim(),
    ...(optionalText(formData.description) ? { description: optionalText(formData.description) } : {}),
    ...(optionalText(formData.measurement_method) ? { measurement_method: optionalText(formData.measurement_method) } : {}),
    ...(optionalText(formData.category) ? { category: optionalText(formData.category) } : {}),
    baseline: emptyToNull(formData.baseline),
    target: emptyToNull(formData.target),
    current_value: emptyToNull(formData.current_value),
    ...(optionalText(formData.unit) ? { unit: optionalText(formData.unit) } : {}),
    frequency: formData.frequency,
    direction: formData.direction,
    status: formData.status,
    owner_id: formData.owner_id ? Number(formData.owner_id) : null,
    order: formData.order === '' ? null : Number(formData.order),
    department_ids: selectedDepartmentIds,
  });

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!formData.name.trim()) {
      showToast('error', t('performance.name_required'));
      return;
    }

    setSaving(true);
    try {
      if (isEdit) {
        await performanceApi.updateKPI(Number(id), buildPayload());
        showToast('success', t('performance.update_success'));
      } else {
        const response = await performanceApi.createKPI(buildPayload());
        showToast('success', t('performance.create_success'));
        navigate(`/performance/kpis/${response.kpi.id}`);
        return;
      }
      navigate('/performance/kpis');
    } catch (error: unknown) {
      showToast('error', getErrorMessage(error, t('performance.save_error')));
    } finally {
      setSaving(false);
    }
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
            { label: t('performance.kpis'), href: '/performance/kpis' },
            { label: isEdit ? t('performance.edit_kpi') : t('performance.new_kpi') },
          ]}
        />
        <PageHeader
          icon={IconTarget}
          iconTone="project"
          title={isEdit ? t('performance.edit_title') : t('performance.create_title')}
          subtitle={isEdit ? t('performance.edit_desc') : t('performance.create_desc')}
        />
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
          <Card className="space-y-8 p-5 sm:p-7 lg:col-span-2">
            <FormSection title={t('strategy.section_basic_info')}>
              <Input
                label={t('performance.name')}
                value={formData.name}
                onChange={(event) => handleChange('name', event.target.value)}
                placeholder={t('performance.name_placeholder')}
                required
              />
              <Input
                label={t('performance.code')}
                value={formData.code}
                onChange={(event) => handleChange('code', event.target.value)}
                placeholder={t('performance.code_placeholder')}
              />
              <Textarea
                label={t('common.description')}
                value={formData.description}
                onChange={(event) => handleChange('description', event.target.value)}
                placeholder={t('performance.description_placeholder')}
                rows={3}
              />
              <Input
                label={t('performance.measurement_method')}
                value={formData.measurement_method}
                onChange={(event) => handleChange('measurement_method', event.target.value)}
                placeholder={t('performance.measurement_method_placeholder')}
              />
              <Input
                label={t('performance.category')}
                value={formData.category}
                onChange={(event) => handleChange('category', event.target.value)}
                placeholder={t('performance.category_placeholder')}
              />
            </FormSection>

            <FormSection title={t('performance.targets_section')} columns={2}>
              <Input
                label={t('performance.baseline')}
                type="number"
                step="0.01"
                value={formData.baseline}
                onChange={(event) => handleChange('baseline', event.target.value)}
              />
              <Input
                label={t('common.target')}
                type="number"
                step="0.01"
                value={formData.target}
                onChange={(event) => handleChange('target', event.target.value)}
              />
              <Input
                label={t('performance.current_value')}
                type="number"
                step="0.01"
                value={formData.current_value}
                onChange={(event) => handleChange('current_value', event.target.value)}
              />
              <Input
                label={t('performance.unit')}
                value={formData.unit}
                onChange={(event) => handleChange('unit', event.target.value)}
                placeholder={t('performance.unit_placeholder')}
              />
            </FormSection>
          </Card>

          <aside className="space-y-5">
            <Card className="space-y-5 p-5">
              <FormSection title={t('performance.settings_section')}>
                <Select
                  label={t('performance.frequency')}
                  value={formData.frequency}
                  onChange={(event) => handleChange('frequency', event.target.value)}
                  options={KPI_FREQUENCIES.map((item) => ({ value: item.value, label: t(item.labelKey) }))}
                />
                <Select
                  label={t('performance.direction')}
                  value={formData.direction}
                  onChange={(event) => handleChange('direction', event.target.value)}
                  options={KPI_DIRECTIONS.map((item) => ({ value: item.value, label: t(item.labelKey) }))}
                />
                <Select
                  label={t('common.status')}
                  value={formData.status}
                  onChange={(event) => handleChange('status', event.target.value)}
                  options={KPI_STATUSES.map((item) => ({ value: item.value, label: t(item.labelKey) }))}
                />
                <Select
                  label={t('common.owner')}
                  placeholder={t('strategy.select_owner')}
                  value={formData.owner_id}
                  onChange={(event) => handleChange('owner_id', event.target.value)}
                  options={users.map((user) => ({ value: String(user.id), label: user.name }))}
                  searchable
                />
                <Input
                  label={t('common.order')}
                  type="number"
                  min={0}
                  value={formData.order}
                  onChange={(event) => handleChange('order', event.target.value)}
                />
              </FormSection>
            </Card>

            <Card className="space-y-4 p-5">
              <div>
                <h3 className="text-base font-semibold text-[var(--text-primary)]">
                  {t('performance.linked_departments')}
                </h3>
                <p className="mt-1 text-sm text-[var(--text-secondary)]">
                  {t('performance.linked_departments_desc')}
                </p>
              </div>
              <Input
                label={t('performance.search_departments')}
                value={departmentSearch}
                onChange={(event) => setDepartmentSearch(event.target.value)}
                placeholder={t('performance.search_departments_placeholder')}
              />
              <div className="max-h-64 space-y-3 overflow-y-auto rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-muted)] p-3">
                {filteredDepartments.length > 0 ? (
                  filteredDepartments.map((department) => (
                    <Checkbox
                      key={department.id}
                      checked={selectedDepartmentIds.includes(department.id)}
                      onChange={() => toggleDepartment(department.id)}
                      label={department.name}
                      description={[department.code, department.level_name].filter(Boolean).join(' - ') || undefined}
                    />
                  ))
                ) : (
                  <p className="py-3 text-center text-sm text-[var(--text-secondary)]">
                    {t('performance.no_departments_found')}
                  </p>
                )}
              </div>
            </Card>

            <FormActions>
              <Button
                type="button"
                variant="outline"
                leftIcon={<IconX className="h-4 w-4" />}
                onClick={() => navigate('/performance/kpis')}
              >
                {t('common.cancel')}
              </Button>
              <Button
                type="submit"
                loading={saving}
                leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
              >
                {isEdit ? t('common.save_changes') : t('performance.create_kpi')}
              </Button>
            </FormActions>
          </aside>
        </div>
      </form>
    </div>
  );
};

export default KPIForm;
