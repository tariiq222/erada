import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconArrowLeft, IconDeviceFloppy, IconSettings } from '@tabler/icons-react';
import { useAuth } from '@shared/contexts/AuthContext';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { OrganizationSettingsInput, OrganizationSettingsPayload } from '@admin/model/admin';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Forbidden } from '@admin/pages/Forbidden';

/**
 * Deep-clone for in-memory edits. Only the three top-level keys are
 * editable per the backend contract (see `OrganizationSettingsInput`),
 * so a `structuredClone` of `payload ?? defaults()` is sufficient and
 * avoids accidental leakage of unrelated JSON columns.
 */
function clone<T>(value: T): T {
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value)) as T;
}

function defaultPayload(): OrganizationSettingsPayload {
  return {
    locale_overrides: {},
    branding_overrides: {},
    notification_templates: {},
  };
}

export function OrganizationSettingsPage() {
  const { t } = useTranslation();
  const { organizationId } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();

  const numericId = useMemo(() => {
    const parsed = Number(organizationId);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }, [organizationId]);

  // FE authorization mirror: backend `FormRequest::authorize()` already
  // gates the route on `isSuperAdmin() || actor.organization_id === org.id`,
  // but we mirror the same check on the render path to fail fast and
  // surface the Forbidden screen without firing a doomed GET.
  const isAuthorized = useMemo(() => {
    if (numericId === null) return false;
    if (!user) return false;
    if (user.is_super_admin === true) return true;
    return typeof user.organization_id === 'number'
      && user.organization_id === numericId;
  }, [numericId, user]);

  const [payload, setPayload] = useState<OrganizationSettingsPayload>(defaultPayload());
  const [draft, setDraft] = useState<OrganizationSettingsPayload>(defaultPayload());
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSucceeded, setSaveSucceeded] = useState(false);

  const load = useCallback(async () => {
    if (numericId === null) return;
    setLoading(true);
    setLoadError(null);
    setSaveError(null);
    setSaveSucceeded(false);
    try {
      const settings = await adminApi.organizationSettings.get(numericId);
      const next = {
        locale_overrides: { ...settings.data.locale_overrides },
        branding_overrides: { ...settings.data.branding_overrides },
        notification_templates: { ...settings.data.notification_templates },
      };
      setPayload(next);
      setDraft(clone(next));
    } catch (caught) {
      setLoadError(apiErrorMessage(caught, t('admin.organizationSettings.loadErrorFallback')));
    } finally {
      setLoading(false);
    }
  }, [numericId, t]);

  useEffect(() => {
    if (!isAuthorized) return;
    void load();
  }, [isAuthorized, load]);

  const dirty = useMemo(() => JSON.stringify(payload) !== JSON.stringify(draft), [payload, draft]);

  const onLocaleChange = (key: 'ar' | 'en') => (event: React.ChangeEvent<HTMLInputElement>) => {
    const next = event.target.value;
    setDraft((current) => ({
      ...current,
      locale_overrides: {
        ...current.locale_overrides,
        [key]: next === '' ? null : next,
      },
    }));
    setSaveSucceeded(false);
  };

  const onPrimaryColorChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const next = event.target.value;
    setDraft((current) => ({
      ...current,
      branding_overrides: {
        ...current.branding_overrides,
        primary_color: next === '' ? null : next,
      },
    }));
    setSaveSucceeded(false);
  };

  const onLogoPathChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const next = event.target.value;
    setDraft((current) => ({
      ...current,
      branding_overrides: {
        ...current.branding_overrides,
        logo_path: next === '' ? null : next,
      },
    }));
    setSaveSucceeded(false);
  };

  const onNotificationTemplateChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const next = event.target.value;
    setDraft((current) => ({
      ...current,
      notification_templates: {
        ...current.notification_templates,
        kpi_alert: next,
      },
    }));
    setSaveSucceeded(false);
  };

  const buildInput = useCallback((): OrganizationSettingsInput => {
    const input: OrganizationSettingsInput = {};
    const draftLocale = draft.locale_overrides;
    const persistedLocale = payload.locale_overrides;
    const localeOverrides: NonNullable<OrganizationSettingsInput['locale_overrides']> = {};
    if ((draftLocale.ar ?? null) !== (persistedLocale.ar ?? null)) {
      localeOverrides.ar = draftLocale.ar ?? null;
    }
    if ((draftLocale.en ?? null) !== (persistedLocale.en ?? null)) {
      localeOverrides.en = draftLocale.en ?? null;
    }
    if (Object.keys(localeOverrides).length > 0) {
      input.locale_overrides = localeOverrides;
    }
    const draftBrand = draft.branding_overrides;
    const persistedBrand = payload.branding_overrides;
    const brandingOverrides: NonNullable<OrganizationSettingsInput['branding_overrides']> = {};
    if ((draftBrand.primary_color ?? null) !== (persistedBrand.primary_color ?? null)) {
      brandingOverrides.primary_color = draftBrand.primary_color ?? null;
    }
    if ((draftBrand.logo_path ?? null) !== (persistedBrand.logo_path ?? null)) {
      brandingOverrides.logo_path = draftBrand.logo_path ?? null;
    }
    if (Object.keys(brandingOverrides).length > 0) {
      input.branding_overrides = brandingOverrides;
    }
    if (draft.notification_templates.kpi_alert !== payload.notification_templates.kpi_alert) {
      input.notification_templates = {
        kpi_alert: draft.notification_templates.kpi_alert,
      };
    }
    return input;
  }, [draft, payload]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (numericId === null || saving) return;
    const payload = buildInput();
    if (Object.keys(payload).length === 0) return;
    setSaving(true);
    setSaveError(null);
    setSaveSucceeded(false);
    try {
      const response = await adminApi.organizationSettings.update(numericId, payload);
      const next = {
        locale_overrides: { ...response.data.locale_overrides },
        branding_overrides: { ...response.data.branding_overrides },
        notification_templates: { ...response.data.notification_templates },
      };
      setPayload(next);
      setDraft(clone(next));
      setSaveSucceeded(true);
    } catch (caught) {
      setSaveError(apiErrorMessage(caught, t('admin.organizationSettings.saveErrorFallback')));
    } finally {
      setSaving(false);
    }
  };

  if (!isAuthorized) {
    return <Forbidden />;
  }

  const orgName = t('admin.organizationDetails.unknown');

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <AdminPageHeader
        icon={<IconSettings className="h-6 w-6" />}
        title={t('admin.organizationSettings.title')}
        subtitle={t('admin.organizationSettings.subtitle', { organization: orgName })}
        actions={
          <Link to={`/organizations/${numericId}`} className="inline-flex items-center gap-2">
            <IconArrowLeft className="h-4 w-4" />
            {t('admin.organizationSettings.actions.backToDetails')}
          </Link>
        }
      />

      {loading && (
        <Card>
          <p role="status" className="text-sm text-[var(--text-secondary)]">
            {t('common.loading')}
          </p>
        </Card>
      )}

      {loadError && (
        <Alert variant="danger" title={t('admin.organizationSettings.loadErrorTitle')}>
          {loadError}
          <div className="mt-3">
            <Button
              type="button"
              variant="secondary"
              onClick={() => void load()}
              aria-label={t('common.retry')}
            >
              {t('common.retry')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => navigate('/organizations')}
              className="ms-2"
            >
              {t('admin.organizationSettings.actions.backToList')}
            </Button>
          </div>
        </Alert>
      )}

      {!loading && !loadError && (
        <form onSubmit={(event) => void submit(event)} className="space-y-6" aria-busy={saving}>
          <Card>
            <h2 className="mb-3 text-base font-semibold text-[var(--text-primary)]">
              {t('admin.organizationSettings.sections.locale')}
            </h2>
            <div className="grid gap-4 md:grid-cols-2">
              <Input
                label={t('admin.organizationSettings.fields.localeOverridesAr')}
                value={draft.locale_overrides.ar ?? ''}
                onChange={onLocaleChange('ar')}
                maxLength={16}
              />
              <Input
                label={t('admin.organizationSettings.fields.localeOverridesEn')}
                value={draft.locale_overrides.en ?? ''}
                onChange={onLocaleChange('en')}
                maxLength={16}
              />
            </div>
          </Card>

          <Card>
            <h2 className="mb-3 text-base font-semibold text-[var(--text-primary)]">
              {t('admin.organizationSettings.sections.branding')}
            </h2>
            <div className="grid gap-4 md:grid-cols-2">
              <Input
                label={t('admin.organizationSettings.fields.brandingOverridesPrimaryColor')}
                value={draft.branding_overrides.primary_color ?? ''}
                onChange={onPrimaryColorChange}
                placeholder="#1F7A8C"
                maxLength={7}
              />
              <Input
                label={t('admin.organizationSettings.fields.brandingOverridesLogoPath')}
                value={draft.branding_overrides.logo_path ?? ''}
                onChange={onLogoPathChange}
                maxLength={255}
              />
            </div>
          </Card>

          <Card>
            <h2 className="mb-3 text-base font-semibold text-[var(--text-primary)]">
              {t('admin.organizationSettings.sections.notifications')}
            </h2>
            <Input
              label={t('admin.organizationSettings.fields.notificationTemplateKpiAlert')}
              value={draft.notification_templates.kpi_alert ?? ''}
              onChange={onNotificationTemplateChange}
              maxLength={4000}
            />
          </Card>

          {saveError && (
            <Alert variant="danger" title={t('admin.organizationSettings.saveErrorTitle')}>
              {saveError}
            </Alert>
          )}

          {saveSucceeded && !saveError && (
            <Alert variant="success" title={t('admin.organizationSettings.saveSuccess')}>
              {t('admin.organizationSettings.saveSuccessBody', { organization: orgName })}
            </Alert>
          )}

          <div className="flex flex-wrap gap-2">
            <Button
              type="submit"
              disabled={saving || !dirty}
              aria-label={t('admin.organizationSettings.actions.save')}
            >
              <IconDeviceFloppy className="me-2 h-4 w-4" />
              {saving ? t('admin.organizationSettings.actions.saving') : t('admin.organizationSettings.actions.save')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              disabled={saving || !dirty}
              onClick={() => setDraft(clone(payload))}
            >
              {t('common.cancel')}
            </Button>
          </div>
        </form>
      )}
    </div>
  );
}
