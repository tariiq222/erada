import React, { useState, useEffect, useMemo } from 'react';
import { useParams, useNavigate, useLocation, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {IconDeviceFloppy, IconUser, IconMail, IconPhone, IconShield, IconLock, IconEye, IconEyeOff} from '@tabler/icons-react';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import { Checkbox } from '@shared/ui/Checkbox';
import { Skeleton } from '@shared/ui/Skeleton';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import { Breadcrumb } from '@shared/ui/Breadcrumb';
import { PageHeader } from '@shared/ui/PageHeader';
import { useToast } from '@shared/ui/Toast';
import { departmentsApi } from '@entities/hr';
import { usersApi } from '@entities/user';
import { rolesApi, type Role } from '@entities/role';

interface UserFormData {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  phone: string;
  extension: string;
  job_title: string;
  department_id: string;
  is_active: boolean;
  roles: string[];
}

interface Department {
  id: number;
  name: string;
}

interface ValidationErrors {
  [key: string]: string[];
}

interface AvailableRole {
  value: string;
  label: string;
  description?: string;
}

interface PasswordInputProps {
  value: string;
  onChange: (value: string) => void;
  label?: string;
  required?: boolean;
  placeholder?: string;
  error?: string;
}

const PasswordInput: React.FC<PasswordInputProps> = ({
  value,
  onChange,
  label,
  required,
  placeholder,
  error,
}) => {
  const { t } = useTranslation();
  const [show, setShow] = useState(false);
  return (
    <div>
      {label && (
        <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
          {label}
          {required && <span className="text-[var(--status-danger)] ms-1">*</span>}
        </label>
      )}
      <div className="relative">
        <Input
          type={show ? 'text' : 'password'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          leftIcon={<IconLock className="h-4 w-4" />}
          error={error}
        />
        <button
          type="button"
          onClick={() => setShow(!show)}
          className="absolute end-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors"
          aria-label={show ? t('users.hide_password') : t('users.show_password')}
          tabIndex={-1}
        >
          {show ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
        </button>
      </div>
    </div>
  );
};

export const UserForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const base = useLocation().pathname.startsWith('/admin') ? '/admin/users' : '/users';
  const { showToast } = useToast();

  const isEditMode = !!id;

  const fallbackRoles = useMemo<AvailableRole[]>(
    () => [
      { value: 'admin', label: t('role.admin'), description: t('users.role_desc_admin') },
      { value: 'viewer', label: t('role.viewer'), description: t('users.role_desc_viewer') },
    ],
    [t]
  );

  const [formData, setFormData] = useState<UserFormData>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    phone: '',
    extension: '',
    job_title: '',
    department_id: '',
    is_active: true,
    roles: [],
  });
  const [departments, setDepartments] = useState<Department[]>([]);
  const [availableRoles, setAvailableRoles] = useState<AvailableRole[]>([]);
  const [isLoading, setIsLoading] = useState(isEditMode);
  const [isSaving, setIsSaving] = useState(false);
  const [errors, setErrors] = useState<ValidationErrors>({});

  useEffect(() => {
    fetchDepartments();
    fetchRoles();
    if (isEditMode) {
      fetchUser();
    }
  }, [id]);

  const fetchDepartments = async () => {
    try {
      const response = await departmentsApi.getList() as Department[];
      setDepartments(response);
    } catch (error) {
      console.warn('Failed to load departments:', error);
    }
  };

  const roleDescription = (role: Role): string | undefined => {
    if (role.name === 'admin') return t('users.role_desc_admin');
    if (role.name === 'viewer') return t('users.role_desc_viewer');

    return role.name;
  };

  const fetchRoles = async () => {
    try {
      const response = (await rolesApi.list()) as { data?: Role[] };
      const roleOptions = (response.data || [])
        .filter((role) => role.name !== 'super_admin')
        .map((role) => ({
          value: role.name,
          label: role.display_name || role.label_ar || role.name,
          description: roleDescription(role),
        }));

      setAvailableRoles(roleOptions.length > 0 ? roleOptions : fallbackRoles);
    } catch (error) {
      console.warn('Failed to load roles:', error);
      setAvailableRoles(fallbackRoles);
    }
  };

  const fetchUser = async () => {
    try {
      const response = await usersApi.getOne(Number(id)) as any;
      setFormData({
        name: response.name || '',
        email: response.email || '',
        password: '',
        password_confirmation: '',
        phone: response.phone || '',
        extension: response.extension || '',
        job_title: response.job_title || '',
        department_id: response.department_id?.toString() || '',
        is_active: response.is_active ?? true,
        roles: response.roles || [],
      });
    } catch {
      showToast('error', t('users.load_error'));
      navigate(base);
    } finally {
      setIsLoading(false);
    }
  };

  const handleChange = (field: keyof UserFormData, value: string | boolean | string[]) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    // Clear field error on change
    if (errors[field]) {
      setErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const handleRoleToggle = (role: string) => {
    const newRoles = formData.roles.includes(role)
      ? formData.roles.filter((r) => r !== role)
      : [...formData.roles, role];
    handleChange('roles', newRoles);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});
    setIsSaving(true);

    try {
      const submitData: any = {
        name: formData.name,
        email: formData.email,
        phone: formData.phone || null,
        extension: formData.extension || null,
        job_title: formData.job_title || null,
        department_id: formData.department_id ? Number(formData.department_id) : null,
        is_active: formData.is_active,
        roles: formData.roles,
      };

      // Include password only if provided
      if (formData.password) {
        submitData.password = formData.password;
        submitData.password_confirmation = formData.password_confirmation;
      }

      if (isEditMode) {
        await usersApi.update(Number(id), submitData);
        showToast('success', t('users.updated_success'));
      } else {
        await usersApi.create(submitData);
        showToast('success', t('users.created_success'));
      }
      navigate(base);
    } catch (error: any) {
      if (error.errors) {
        setErrors(error.errors);
      } else {
        showToast('error', error.message || t('users.save_error'));
      }
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Card>
          <CardContent className="p-6 space-y-4">
            {[...Array(6)].map((_, i) => (
              <Skeleton key={i} className="h-10 w-full" />
            ))}
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        icon={IconUser}
        iconTone="admin"
        breadcrumb={
          <Breadcrumb
            items={[
              { label: t('users.title'), href: base },
              { label: isEditMode ? t('users.edit') : t('users.create_new') },
            ]}
          />
        }
        title={isEditMode ? t('users.edit') : t('users.create_new')}
        description={isEditMode ? t('users.update_data') : t('users.enter_new_data')}
      />

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Basic Info */}
        <Card>
          <CardHeader>
            <CardTitle>{t('users.basic_info')}</CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <Input
                label={t('common.name')}
                required
                value={formData.name}
                onChange={(e) => handleChange('name', e.target.value)}
                placeholder={t('users.enter_name')}
                leftIcon={<IconUser className="h-4 w-4" />}
                error={errors.name?.[0]}
              />

              <Input
                type="email"
                label={t('common.email')}
                required
                value={formData.email}
                onChange={(e) => handleChange('email', e.target.value)}
                placeholder="example@domain.com"
                leftIcon={<IconMail className="h-4 w-4" />}
                error={errors.email?.[0]}
              />

              <PasswordInput
                label={t('users.password')}
                required={!isEditMode}
                value={formData.password}
                onChange={(v) => handleChange('password', v)}
                placeholder={isEditMode ? t('users.password_keep_empty') : t('users.enter_password')}
                error={errors.password?.[0]}
              />

              <PasswordInput
                label={t('users.confirm_password')}
                required={!isEditMode}
                value={formData.password_confirmation}
                onChange={(v) => handleChange('password_confirmation', v)}
                placeholder={t('users.reenter_password')}
                error={errors.password_confirmation?.[0]}
              />
            </div>
          </CardContent>
        </Card>

        {/* Contact Info */}
        <Card>
          <CardHeader>
            <CardTitle>{t('users.contact_info')}</CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              <Input
                label={t('common.phone')}
                value={formData.phone}
                onChange={(e) => handleChange('phone', e.target.value)}
                placeholder="05xxxxxxxx"
                leftIcon={<IconPhone className="h-4 w-4" />}
                error={errors.phone?.[0]}
              />

              <Input
                label={t('users.extension')}
                value={formData.extension}
                onChange={(e) => handleChange('extension', e.target.value)}
                placeholder="1234"
                error={errors.extension?.[0]}
              />

              <Input
                label={t('users.job_title')}
                value={formData.job_title}
                onChange={(e) => handleChange('job_title', e.target.value)}
                placeholder={t('profile.job_title_placeholder')}
                error={errors.job_title?.[0]}
              />
            </div>
          </CardContent>
        </Card>

        {/* Organization, Department & Roles */}
        <Card>
          <CardHeader>
            <CardTitle>{t('users.department_and_roles')}</CardTitle>
          </CardHeader>
          <CardContent className="p-6 space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  {t('common.department')}
                </label>
                <Select
                  value={formData.department_id}
                  onChange={(e) => handleChange('department_id', e.target.value)}
                  options={[
                    { value: '', label: t('users.select_department') },
                    ...departments.map((dept) => ({
                      value: String(dept.id),
                      label: dept.name,
                    })),
                  ]}
                />
                {errors.department_id && (
                  <p className="text-sm text-[var(--status-danger-text)] mt-1">{errors.department_id[0]}</p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  {t('common.status')}
                </label>
                <div className="flex items-center gap-3 h-10">
                  <Checkbox
                    checked={formData.is_active}
                    onChange={(e) => handleChange('is_active', e.target.checked)}
                  />
                  <span className="text-[var(--text-secondary)]">{t('users.user_active')}</span>
                </div>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-3">
                {t('users.roles')} <RequiredIndicator />
              </label>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {(availableRoles.length > 0 ? availableRoles : fallbackRoles).map((role) => (
                  <label
                    key={role.value}
                    className={`relative flex items-start p-4 border rounded-lg cursor-pointer transition-colors ${
                      formData.roles.includes(role.value)
                        ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)]'
                        : 'border-[var(--border-default)] hover:border-[var(--border-strong)]'
                    }`}
                  >
                    <Checkbox
                      checked={formData.roles.includes(role.value)}
                      onChange={() => handleRoleToggle(role.value)}
                      className="mt-0"
                    />
                    <div className="me-3">
                      <div className="flex items-center gap-2">
                        <IconShield className="h-4 w-4 text-[var(--text-tertiary)]" />
                        <span className="font-medium text-[var(--text-primary)]">{role.label}</span>
                      </div>
                      {role.description && (
                        <p className="text-sm text-[var(--text-tertiary)] mt-1">{role.description}</p>
                      )}
                    </div>
                  </label>
                ))}
              </div>
              {errors.roles && (
                <p className="text-sm text-[var(--status-danger-text)] mt-2">{errors.roles[0]}</p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Actions */}
        <div className="flex items-center justify-end gap-3">
          <Link to={base}>
            <Button type="button" variant="outline">
              {t('common.cancel')}
            </Button>
          </Link>
          <Button type="submit" loading={isSaving} leftIcon={<IconDeviceFloppy className="h-4 w-4" />}>
            {isEditMode ? t('common.save_changes') : t('users.create')}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default UserForm;
