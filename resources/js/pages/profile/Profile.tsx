import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {IconUser, IconMail, IconPhone, IconBriefcase, IconLock, IconEye, IconEyeOff, IconDeviceFloppy, IconBuilding} from '@tabler/icons-react';
import { Card, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Badge } from '@shared/ui/Badge';
import { useToast } from '@shared/ui/Toast';
import PageHeader from '@shared/ui/PageHeader';
import SectionHeader from '@shared/ui/SectionHeader';
import { profileApi } from '@shared/api/auth';
import { useAuth } from '@shared/contexts/AuthContext';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import { TwoFactorSettings } from '@features/two-factor';

interface ProfileFormData {
  name: string;
  email: string;
  phone: string;
  extension: string;
  job_title: string;
}

interface PasswordFormData {
  current_password: string;
  password: string;
  password_confirmation: string;
}

interface ValidationErrors {
  [key: string]: string[];
}

const roleLabels: Record<string, string> = {
  super_admin: 'profile.role_super_admin',
  admin: 'profile.role_admin',
  project_manager: 'profile.role_project_manager',
  team_member: 'profile.role_team_member',
  viewer: 'profile.role_viewer',
};

export const Profile: React.FC = () => {
  const { t } = useTranslation();
  const { user, refreshUser } = useAuth();
  const { showToast } = useToast();

  const [profileData, setProfileData] = useState<ProfileFormData>({
    name: user?.name || '',
    email: user?.email || '',
    phone: user?.phone || '',
    extension: (user as any)?.extension || '',
    job_title: user?.job_title || '',
  });

  const [passwordData, setPasswordData] = useState<PasswordFormData>({
    current_password: '',
    password: '',
    password_confirmation: '',
  });

  const [profileErrors, setProfileErrors] = useState<ValidationErrors>({});
  const [passwordErrors, setPasswordErrors] = useState<ValidationErrors>({});
  const [isSavingProfile, setIsSavingProfile] = useState(false);
  const [isSavingPassword, setIsSavingPassword] = useState(false);
  const [showCurrentPassword, setShowCurrentPassword] = useState(false);
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const handleProfileChange = (field: keyof ProfileFormData, value: string) => {
    setProfileData((prev) => ({ ...prev, [field]: value }));
    if (profileErrors[field]) {
      setProfileErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const handlePasswordChange = (field: keyof PasswordFormData, value: string) => {
    setPasswordData((prev) => ({ ...prev, [field]: value }));
    if (passwordErrors[field]) {
      setPasswordErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const handleProfileSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileErrors({});
    setIsSavingProfile(true);

    try {
      await profileApi.update({
        name: profileData.name,
        email: profileData.email,
        phone: profileData.phone || undefined,
        extension: profileData.extension || undefined,
        job_title: profileData.job_title || undefined,
      });
      await refreshUser();
      showToast('success', t('profile.profile_updated'));
    } catch (error: any) {
      if (error.errors) {
        setProfileErrors(error.errors);
      } else {
        showToast('error', error.message || t('profile.profile_update_error'));
      }
    } finally {
      setIsSavingProfile(false);
    }
  };

  const handlePasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setPasswordErrors({});
    setIsSavingPassword(true);

    try {
      await profileApi.changePassword(passwordData);
      setPasswordData({
        current_password: '',
        password: '',
        password_confirmation: '',
      });
      showToast('success', t('profile.password_changed'));
    } catch (error: any) {
      if (error.errors) {
        setPasswordErrors(error.errors);
      } else {
        showToast('error', error.message || t('profile.password_change_error'));
      }
    } finally {
      setIsSavingPassword(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        icon={IconUser}
        iconTone="admin"
        title={t('profile.title')}
        subtitle={t('profile.subtitle')}
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* IconUser Info Card */}
        <Card className="lg:col-span-1">
          <CardContent className="p-6">
            <div className="flex flex-col items-center text-center">
              <div className="w-24 h-24 rounded-full bg-[var(--accent-subtle)] flex items-center justify-center mb-4">
                <IconUser className="h-12 w-12 text-[var(--accent-default)]" />
              </div>
              <h2 className="text-xl font-bold text-[var(--text-primary)]">{user?.name}</h2>
              <p className="text-[var(--text-tertiary)]">{user?.email}</p>

              {user?.job_title && (
                <p className="text-[var(--text-secondary)] mt-1">{user.job_title}</p>
              )}

              <div className="flex flex-wrap gap-2 justify-center mt-4">
                {user?.roles.map((role) => (
                  <Badge key={role} variant="accent">
                    {t(roleLabels[role]) || role}
                  </Badge>
                ))}
              </div>

              {user?.department && (
                <div className="mt-4 flex items-center gap-2 text-[var(--text-secondary)]">
                  <IconBuilding className="h-4 w-4" />
                  <span>{user.department.name}</span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Forms */}
        <div className="lg:col-span-2 space-y-6">
          {/* Profile Form */}
          <Card>
            <SectionHeader
              level={2}
              size="compact"
              icon={IconUser}
              iconTone="admin"
              title={t('profile.personal_info')}
            />
            <CardContent className="p-6">
              <form onSubmit={handleProfileSubmit} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.name')} <RequiredIndicator />
                    </label>
                    <Input
                      value={profileData.name}
                      onChange={(e) => handleProfileChange('name', e.target.value)}
                      placeholder={t('profile.name_placeholder')}
                      leftIcon={<IconUser className="h-4 w-4" />}
                      error={profileErrors.name?.[0]}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.email')} <RequiredIndicator />
                    </label>
                    <Input
                      type="email"
                      value={profileData.email}
                      onChange={(e) => handleProfileChange('email', e.target.value)}
                      placeholder="example@domain.com"
                      leftIcon={<IconMail className="h-4 w-4" />}
                      error={profileErrors.email?.[0]}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.phone')}
                    </label>
                    <Input
                      value={profileData.phone}
                      onChange={(e) => handleProfileChange('phone', e.target.value)}
                      placeholder="05xxxxxxxx"
                      leftIcon={<IconPhone className="h-4 w-4" />}
                      error={profileErrors.phone?.[0]}
                    />
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.extension')}
                    </label>
                    <Input
                      value={profileData.extension}
                      onChange={(e) => handleProfileChange('extension', e.target.value)}
                      placeholder="1234"
                      error={profileErrors.extension?.[0]}
                    />
                  </div>

                  <div className="md:col-span-2">
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.job_title')}
                    </label>
                    <Input
                      value={profileData.job_title}
                      onChange={(e) => handleProfileChange('job_title', e.target.value)}
                      placeholder={t('profile.job_title_placeholder')}
                      leftIcon={<IconBriefcase className="h-4 w-4" />}
                      error={profileErrors.job_title?.[0]}
                    />
                  </div>
                </div>

                <div className="flex justify-end pt-4">
                  <Button type="submit" loading={isSavingProfile} leftIcon={<IconDeviceFloppy className="h-4 w-4" />}>
                    {t('common.save_changes')}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>

          {/* Password Form */}
          <Card>
            <SectionHeader
              level={2}
              size="compact"
              icon={IconLock}
              iconTone="admin"
              title={t('profile.change_password')}
            />
            <CardContent className="p-6">
              <form onSubmit={handlePasswordSubmit} className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                    {t('profile.current_password')} <RequiredIndicator />
                  </label>
                  <div className="relative">
                    <Input
                      type={showCurrentPassword ? 'text' : 'password'}
                      value={passwordData.current_password}
                      onChange={(e) => handlePasswordChange('current_password', e.target.value)}
                      placeholder={t('profile.current_password_placeholder')}
                      leftIcon={<IconLock className="h-4 w-4" />}
                      error={passwordErrors.current_password?.[0]}
                    />
                    <button
                      type="button"
                      onClick={() => setShowCurrentPassword(!showCurrentPassword)}
                      aria-label={showCurrentPassword ? t('auth.hide_password', { defaultValue: 'إخفاء كلمة المرور' }) : t('auth.show_password', { defaultValue: 'إظهار كلمة المرور' })}
                      className="absolute end-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]"
                    >
                      {showCurrentPassword ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
                    </button>
                  </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.new_password')} <RequiredIndicator />
                    </label>
                    <div className="relative">
                      <Input
                        type={showNewPassword ? 'text' : 'password'}
                        value={passwordData.password}
                        onChange={(e) => handlePasswordChange('password', e.target.value)}
                        placeholder={t('profile.new_password_placeholder')}
                        leftIcon={<IconLock className="h-4 w-4" />}
                        error={passwordErrors.password?.[0]}
                      />
                      <button
                        type="button"
                        onClick={() => setShowNewPassword(!showNewPassword)}
                        aria-label={showNewPassword ? t('auth.hide_password', { defaultValue: 'إخفاء كلمة المرور' }) : t('auth.show_password', { defaultValue: 'إظهار كلمة المرور' })}
                        className="absolute end-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]"
                      >
                        {showNewPassword ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                      {t('profile.confirm_password')} <RequiredIndicator />
                    </label>
                    <div className="relative">
                      <Input
                        type={showConfirmPassword ? 'text' : 'password'}
                        value={passwordData.password_confirmation}
                        onChange={(e) => handlePasswordChange('password_confirmation', e.target.value)}
                        placeholder={t('profile.confirm_password_placeholder')}
                        leftIcon={<IconLock className="h-4 w-4" />}
                      />
                      <button
                        type="button"
                        onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                        aria-label={showConfirmPassword ? t('auth.hide_password', { defaultValue: 'إخفاء كلمة المرور' }) : t('auth.show_password', { defaultValue: 'إظهار كلمة المرور' })}
                        className="absolute end-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]"
                      >
                        {showConfirmPassword ? <IconEyeOff className="h-4 w-4" /> : <IconEye className="h-4 w-4" />}
                      </button>
                    </div>
                  </div>
                </div>

                <p className="text-sm text-[var(--text-tertiary)]">
                  {t('profile.password_min_length')}
                </p>

                <div className="flex justify-end pt-4">
                  <Button type="submit" loading={isSavingPassword} leftIcon={<IconLock className="h-4 w-4" />}>
                    {t('profile.change_password')}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>

          {/* Two-Factor Authentication */}
          <TwoFactorSettings />
        </div>
      </div>
    </div>
  );
};

export default Profile;
