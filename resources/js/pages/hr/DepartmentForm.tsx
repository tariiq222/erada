import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconNetwork, IconDeviceFloppy, IconX, IconShieldLock } from '@tabler/icons-react';
import { Button, Card, Input, Select, Textarea, Checkbox, Alert, Breadcrumb, PageHeader, FormSection, Skeleton } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { departmentsApi } from '@entities/hr';
import { usersApi } from '@entities/user';
import { DEPARTMENT_LEVEL_LABELS } from './components/departmentTypes';

interface CapacityRole {
  role_key: string;
  label: string;
  scope: string | null;
}

// Suggested defaults preselected for a brand-new department.
const DEFAULT_MEMBER_ROLE = 'dept_member';
const DEFAULT_MANAGER_ROLE = 'dept_manager';

interface FormData {
  name: string;
  code: string;
  description: string;
  parent_id: string;
  level: string;
  manager_id: string;
  is_active: boolean;
}

const EMPTY_FORM: FormData = {
  name: '',
  code: '',
  description: '',
  parent_id: '',
  level: '1',
  manager_id: '',
  is_active: true,
};

const DepartmentForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const isEdit = !!id;

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [parentDepartments, setParentDepartments] = useState<{ id: number; name: string; level: number }[]>([]);
  const [users, setUsers] = useState<{ id: number; name: string }[]>([]);
  const [allowedLevels, setAllowedLevels] = useState<Record<number, string>>({});
  const [availableRoles, setAvailableRoles] = useState<CapacityRole[]>([]);
  const [memberRoleKeys, setMemberRoleKeys] = useState<string[]>([]);
  const [managerRoleKeys, setManagerRoleKeys] = useState<string[]>([]);
  const [formData, setFormData] = useState<FormData>(EMPTY_FORM);

  useEffect(() => {
    fetchParentDepartments();
    fetchUsers();
    if (isEdit) {
      fetchDepartment();
      fetchCapacityRoles(Number(id));
    } else {
      fetchAvailableRolesForNew();
      fetchAllowedLevels('');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  useEffect(() => {
    fetchAllowedLevels(formData.parent_id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formData.parent_id]);

  const fetchParentDepartments = async () => {
    try {
      const data = (await departmentsApi.getList()) as { id: number; name: string; level: number }[];
      setParentDepartments(data.filter((d) => d.id !== Number(id)));
    } catch (error) {
      console.warn('Failed to load parent departments:', error);
    }
  };

  const fetchUsers = async () => {
    try {
      const data = (await usersApi.getList()) as { id: number; name: string }[];
      setUsers(data);
    } catch (error) {
      console.warn('Failed to load users:', error);
    }
  };

  // Create mode: the available list is department-independent. We also preselect
  // the suggested defaults (member/manager) when they are present in the list.
  const fetchAvailableRolesForNew = async () => {
    try {
      const response = (await departmentsApi.getAvailableCapacityRoles()) as {
        available: CapacityRole[];
      };
      const available = response.available || [];
      setAvailableRoles(available);

      const keys = available.map((r) => r.role_key);
      setMemberRoleKeys(keys.includes(DEFAULT_MEMBER_ROLE) ? [DEFAULT_MEMBER_ROLE] : []);
      setManagerRoleKeys(keys.includes(DEFAULT_MANAGER_ROLE) ? [DEFAULT_MANAGER_ROLE] : []);
    } catch (error) {
      console.warn('Failed to load available capacity roles:', error);
      setAvailableRoles([]);
    }
  };

  // Edit mode: load the department's current member/manager policy + the list.
  const fetchCapacityRoles = async (departmentId: number) => {
    try {
      const response = (await departmentsApi.getCapacityRoles(departmentId)) as {
        member_role_keys: string[];
        manager_role_keys: string[];
        available: CapacityRole[];
      };
      setAvailableRoles(response.available || []);
      setMemberRoleKeys(response.member_role_keys || []);
      setManagerRoleKeys(response.manager_role_keys || []);
    } catch (error) {
      console.warn('Failed to load capacity roles:', error);
    }
  };

  const fetchDepartment = async () => {
    setLoading(true);
    try {
      const data = (await departmentsApi.getOne(Number(id))) as any;
      setFormData({
        name: data.name || '',
        code: data.code || '',
        description: data.description || '',
        parent_id: data.parent_id?.toString() || '',
        level: data.level?.toString() || '1',
        manager_id: data.manager_id?.toString() || '',
        is_active: data.is_active ?? true,
      });
    } catch {
      showToast('error', t('hr.departments_load_error'));
      navigate('/hr/departments');
    } finally {
      setLoading(false);
    }
  };

  const fetchAllowedLevels = async (parentId: string) => {
    try {
      const response = (await departmentsApi.getAllowedLevels(parentId || null)) as {
        levels: Record<number, string>;
      };
      let levels = response.levels;

      // أبقِ المستوى الحالي ظاهراً عند التعديل حتى لو لم يعد ضمن المسموح
      if (isEdit && formData.level) {
        const currentLevel = parseInt(formData.level);
        if (currentLevel && !levels[currentLevel]) {
          levels = {
            ...levels,
            [currentLevel]: DEPARTMENT_LEVEL_LABELS[currentLevel] || `${t('hr.level')} ${currentLevel}`,
          };
        }
      }

      setAllowedLevels(levels);

      const levelKeys = Object.keys(response.levels);
      if (levelKeys.length > 0 && !isEdit) {
        setFormData((prev) => ({ ...prev, level: levelKeys[0] }));
      }
    } catch (error) {
      console.error('Error fetching allowed levels:', error);
      setAllowedLevels(parentId ? DEPARTMENT_LEVEL_LABELS : { 1: t('dept_level.1') });
    }
  };

  const toggleMemberRole = (roleKey: string) => {
    setMemberRoleKeys((prev) =>
      prev.includes(roleKey) ? prev.filter((k) => k !== roleKey) : [...prev, roleKey]
    );
  };

  const toggleManagerRole = (roleKey: string) => {
    setManagerRoleKeys((prev) =>
      prev.includes(roleKey) ? prev.filter((k) => k !== roleKey) : [...prev, roleKey]
    );
  };

  const noCapacityRolesSelected = memberRoleKeys.length === 0 && managerRoleKeys.length === 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      showToast('error', t('hr.department_name'));
      return;
    }
    setSaving(true);
    try {
      const payload = {
        ...formData,
        parent_id: formData.parent_id || null,
        level: parseInt(formData.level) || 1,
        manager_id: formData.manager_id || null,
      };

      const capacityPayload = {
        member_role_keys: memberRoleKeys,
        manager_role_keys: managerRoleKeys,
      };

      let departmentId = Number(id);
      if (isEdit) {
        await departmentsApi.update(departmentId, payload);
        showToast('success', t('hr.department_updated'));
      } else {
        const created = (await departmentsApi.create(payload)) as { id?: number };
        departmentId = created?.id ?? departmentId;
        showToast('success', t('hr.department_created'));
      }

      if (departmentId) {
        try {
          await departmentsApi.updateCapacityRoles(departmentId, capacityPayload);
        } catch (rolesError) {
          console.warn('Failed to update capacity roles:', rolesError);
          showToast('error', t('hr.capacity.update_failed'));
        }
      }

      navigate('/hr/departments');
    } catch (error: any) {
      let errorMsg = error.message || t('common.error_occurred');
      if (error.errors) {
        const firstError = Object.values(error.errors)[0];
        if (Array.isArray(firstError) && firstError.length > 0) {
          errorMsg = firstError[0] as string;
        }
      }
      showToast('error', errorMsg);
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-7 w-64" variant="rounded" />
        <Skeleton className="h-4 w-80" variant="rounded" />
        <Skeleton className="h-64 w-full" variant="rounded" />
      </div>
    );
  }

  const noLevels = Object.keys(allowedLevels).length === 0;

  return (
    <div className="space-y-5">
      <div className="space-y-4">
        <Breadcrumb
          items={[
            { label: t('hr.departments'), href: '/hr/departments' },
            { label: isEdit ? t('hr.edit_department') : t('hr.create_department') },
          ]}
        />
        <PageHeader
          icon={IconNetwork}
          iconTone="admin"
          title={isEdit ? t('hr.edit_department') : t('hr.create_department')}
          subtitle={isEdit ? t('hr.edit_department_subtitle') : t('hr.create_department_subtitle')}
        />
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
          {/* العمود الرئيسي */}
          <Card className="space-y-8 p-5 sm:p-7 lg:col-span-2">
            <FormSection title={t('hr.section_basic_info')}>
              <Input
                label={t('hr.department_name')}
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                required
              />
              <Input
                label={t('hr.department_code')}
                value={formData.code}
                onChange={(e) => setFormData({ ...formData, code: e.target.value })}
              />
              <Textarea
                label={t('common.description')}
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                rows={3}
              />
            </FormSection>

            <FormSection title={t('hr.section_hierarchy')} columns={2}>
              <Select
                label={t('hr.parent_department')}
                value={formData.parent_id}
                onChange={(e) => setFormData({ ...formData, parent_id: e.target.value })}
                hint={t('hr.select_parent_hint')}
                options={[
                  { value: '', label: t('hr.no_parent_department') },
                  ...parentDepartments.map((dept) => ({
                    value: dept.id.toString(),
                    label: `${dept.name} (${DEPARTMENT_LEVEL_LABELS[dept.level]})`,
                  })),
                ]}
              />
              <Select
                label={t('hr.department_level')}
                value={formData.level}
                onChange={(e) => setFormData({ ...formData, level: e.target.value })}
                disabled={noLevels}
                error={noLevels && formData.parent_id ? t('hr.no_sub_levels') : undefined}
                options={
                  noLevels
                    ? [{ value: '', label: t('hr.no_levels_available') }]
                    : Object.entries(allowedLevels).map(([value, label]) => ({
                        value,
                        label: label as string,
                      }))
                }
              />
              <Select
                label={t('hr.department_manager')}
                value={formData.manager_id}
                onChange={(e) => setFormData({ ...formData, manager_id: e.target.value })}
                placeholder={t('hr.select_manager')}
                searchable
                options={[
                  { value: '', label: t('hr.no_manager') },
                  ...users.map((user) => ({ value: user.id.toString(), label: user.name })),
                ]}
              />
            </FormSection>
          </Card>

          {/* العمود الجانبي */}
          <aside className="space-y-5">
            <Card className="space-y-4 p-5">
              <div className="flex items-start gap-2">
                <IconShieldLock className="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-tertiary)]" />
                <div>
                  <h2 className="text-sm font-semibold text-[var(--text-primary)]">
                    {t('hr.section_permissions')}
                  </h2>
                  <p className="mt-1 text-xs leading-relaxed text-[var(--text-tertiary)]">
                    {t('hr.capacity.hint')}
                  </p>
                </div>
              </div>

              {noCapacityRolesSelected && (
                <Alert variant="warning">{t('hr.capacity.empty_warning')}</Alert>
              )}

              {availableRoles.length === 0 ? (
                <p className="text-sm text-[var(--text-tertiary)]">{t('hr.no_roles_available')}</p>
              ) : (
                <div className="space-y-5">
                  <CapacityGroup
                    title={t('hr.capacity.member_roles')}
                    roles={availableRoles}
                    selected={memberRoleKeys}
                    onToggle={toggleMemberRole}
                    crossCuttingLabel={t('hr.capacity.cross_cutting')}
                  />
                  <CapacityGroup
                    title={t('hr.capacity.manager_roles')}
                    roles={availableRoles}
                    selected={managerRoleKeys}
                    onToggle={toggleManagerRole}
                    crossCuttingLabel={t('hr.capacity.cross_cutting')}
                  />
                </div>
              )}
            </Card>

            <Card className="space-y-4 p-5">
              <h2 className="text-sm font-semibold text-[var(--text-primary)]">
                {t('hr.department_status')}
              </h2>
              <Checkbox
                label={t('common.active')}
                description={t('hr.department_active_hint')}
                checked={formData.is_active}
                onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
              />
            </Card>

            <Card className="p-4">
              <div className="flex flex-col gap-2">
                <Button
                  type="submit"
                  loading={saving}
                  leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
                  className="w-full"
                >
                  {isEdit ? t('common.save_changes') : t('common.add')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  leftIcon={<IconX className="h-4 w-4" />}
                  onClick={() => navigate('/hr/departments')}
                  className="w-full"
                >
                  {t('common.cancel')}
                </Button>
              </div>
            </Card>
          </aside>
        </div>
      </form>
    </div>
  );
};

const CapacityGroup: React.FC<{
  title: string;
  roles: CapacityRole[];
  selected: string[];
  onToggle: (roleKey: string) => void;
  crossCuttingLabel: string;
}> = ({ title, roles, selected, onToggle, crossCuttingLabel }) => (
  <div className="space-y-3">
    <h3 className="text-xs font-semibold uppercase tracking-wide text-[var(--text-tertiary)]">
      {title}
    </h3>
    <div className="space-y-3">
      {roles.map((role) => (
        <div key={`${title}-${role.role_key}`} className="flex items-center justify-between gap-2">
          <Checkbox
            label={role.label}
            checked={selected.includes(role.role_key)}
            onChange={() => onToggle(role.role_key)}
          />
          {role.scope === 'organization' && (
            <span className="shrink-0 rounded-[var(--radius-sm)] bg-[var(--surface-muted)] px-2 py-0.5 text-[10px] font-medium text-[var(--text-tertiary)]">
              {crossCuttingLabel}
            </span>
          )}
        </div>
      ))}
    </div>
  </div>
);

export default DepartmentForm;
