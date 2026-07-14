import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  IconAlertCircle,
  IconBuilding,
  IconCircleCheck,
  IconPalette,
  IconPlus,
  IconTrash,
  IconWorld,
} from '@tabler/icons-react';
import type { User } from '@shared/types';
import { useAuth } from '@shared/contexts/AuthContext';
import { useToast } from '@shared/ui/Toast';
import { adminApi, apiErrorMessage } from '@admin/api/adminApi';
import type { OrganizationSettingsPayload } from '@admin/model/admin';
import { AdminPageHeader } from '@admin/pages/access/AdminPageHeader';
import { Alert } from '@shared/ui/Alert';
import { Button } from '@shared/ui/Button';
import { Card } from '@shared/ui/Card';
import { EmptyState } from '@shared/ui/EmptyState';
import { Input } from '@shared/ui/Input';
import { SectionHeader } from '@shared/ui/SectionHeader';

/**
 * Local shape that widens `User` for the org-settings surface without
 * touching `resources/js/shared/types`. Mirrors the two flags the
 * backend exposes on `/api/user` (see AuthController::user) and the
 * `organization_id` column the actor is bound to.
 */
type OrgScopedUser = User & {
  organization_id?: number | null;
  is_super_admin?: boolean;
  is_organization_super_admin?: boolean;
};

function orgScopedUser(user: User | null): OrgScopedUser | null {
  return user as OrgScopedUser | null;
}

const emptyPayload: OrganizationSettingsPayload = {
  locale_overrides: { ar: null, en: null },
  branding_overrides: { primary_color: null, logo_path: null },
  notification_templates: {},
};

type SectionKey = 'locale_overrides' | 'branding_overrides' | 'notification_templates';

type Status =
  | 'no-target'
  | 'loading'
  | 'error'
  | 'ready'
  | 'saving'
  | 'saved';

/**
 * Resolve the target organization id given the actor and the URL.
 *
 * Rules:
 *  - OrgSuper (is_organization_super_admin === true) is locked to
 *    `actor.organization_id`. Any `?organization=<other>` mismatch is
 *    a no-target (we never widen via path or query for a tenant
 *    other than their own).
 *  - Platform super admin (is_super_admin === true, not org-super)
 *    MAY reach the page for a specified tenant via
 *    `?organization=<id>`. Without that query param the page falls
 *    into the no-target state. The `X-Organization-Id` header is
 *    intentionally NOT consulted — `actor.organization_id` is null
 *    for super admins, and the URL itself is the only safe selector.
 */
function resolveTargetOrganizationId(
  user: OrgScopedUser | null,
  searchOrganization: string | null,
): number | null {
  if (!user) return null;
  const isSuperAdmin = user.is_super_admin === true;
  const isOrgSuper = user.is_organization_super_admin === true;

  if (isOrgSuper) {
    const ownOrg = user.organization_id ?? null;
    if (ownOrg === null) return null;
    if (searchOrganization !== null) {
      const requested = Number.parseInt(searchOrganization, 10);
      if (Number.isFinite(requested) && requested !== ownOrg) {
        // OrgSuper may not cross tenants; the mismatch is denied,
        // never silently rewritten to the actor's tenant.
        return null;
      }
    }
    return ownOrg;
  }

  if (isSuperAdmin) {
    if (searchOrganization === null) return null;
    const requested = Number.parseInt(searchOrganization, 10);
    return Number.isFinite(requested) ? requested : null;
  }

  return null;
}

function newIdempotencyKey(): string {
  if (
    typeof crypto !== 'undefined' &&
    typeof (crypto as { randomUUID?: () => string }).randomUUID === 'function'
  ) {
    return (crypto as { randomUUID: () => string }).randomUUID();
  }
  // Mathematical fallback if `crypto.randomUUID` is unavailable (older
  // browsers, jsdom in some configs). The backend only requires the
  // key to be unique within the cache scope and to match the
  // `[^A-Za-z0-9_\-]` allow-list filter.
  return (
    Math.random().toString(36).slice(2) +
    Math.random().toString(36).slice(2) +
    Date.now().toString(36)
  );
}

export function OrganizationSettingsPage() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const { addToast } = useToast();
  const [searchParams] = useSearchParams();

  const searchOrganization = searchParams.get('organization');
  const actor = orgScopedUser(user);
  const organizationId = useMemo(
    () => resolveTargetOrganizationId(actor, searchOrganization),
    [actor, searchOrganization],
  );

  const [status, setStatus] = useState<Status>(() =>
    organizationId === null ? 'no-target' : 'loading',
  );
  const [error, setError] = useState<string | null>(null);
  const [payload, setPayload] = useState<OrganizationSettingsPayload>(emptyPayload);
  const [dirtyKeys, setDirtyKeys] = useState<Set<SectionKey>>(new Set());
  const [idempotencyKey, setIdempotencyKey] = useState<string>(() => newIdempotencyKey());

  // Refetch when the target org changes (e.g., user switches tenants
  // via the URL bar or the localStorage actor).
  useEffect(() => {
    let active = true;
    if (organizationId === null) {
      setStatus('no-target');
      setError(null);
      setPayload(emptyPayload);
      setDirtyKeys(new Set());
      return () => {
        active = false;
      };
    }
    setStatus('loading');
    setError(null);
    setDirtyKeys(new Set());
    void adminApi.organizationSettings
      .get(organizationId)
      .then((response) => {
        if (!active) return;
        setPayload(response.data);
        setStatus('ready');
      })
      .catch((caught) => {
        if (!active) return;
        setStatus('error');
        setError(apiErrorMessage(caught, t('admin.orgSettings.error')));
      });
    return () => {
      active = false;
    };
  }, [organizationId, t]);

  const markDirty = (key: SectionKey, partial: Partial<OrganizationSettingsPayload[SectionKey]>) => {
    setPayload((current) => ({
      ...current,
      [key]: { ...current[key], ...partial },
    }) as OrganizationSettingsPayload);
    setDirtyKeys((current) => {
      const next = new Set(current);
      next.add(key);
      return next;
    });
    // Any edit invalidates the "saved" badge — drop to ready.
    if (status === 'saved') setStatus('ready');
  };

  const retryLoad = () => {
    if (organizationId === null) return;
    setStatus('loading');
    setError(null);
    void adminApi.organizationSettings
      .get(organizationId)
      .then((response) => {
        setPayload(response.data);
        setStatus('ready');
      })
      .catch((caught) => {
        setStatus('error');
        setError(apiErrorMessage(caught, t('admin.orgSettings.error')));
      });
  };

  const save = async (event: FormEvent) => {
    event.preventDefault();
    if (organizationId === null) return;
    if (dirtyKeys.size === 0) {
      // Nothing has been edited — refetch to drop the optimistic local
      // edits and reset dirty state.
      setStatus('ready');
      return;
    }
    setStatus('saving');
    setError(null);
    const outgoing: Partial<OrganizationSettingsPayload> = {};
    if (dirtyKeys.has('locale_overrides')) outgoing.locale_overrides = payload.locale_overrides;
    if (dirtyKeys.has('branding_overrides')) outgoing.branding_overrides = payload.branding_overrides;
    if (dirtyKeys.has('notification_templates')) {
      outgoing.notification_templates = payload.notification_templates;
    }

    try {
      const response = await adminApi.organizationSettings.update(
        organizationId,
        outgoing,
        idempotencyKey,
      );
      setPayload(response.data);
      setDirtyKeys(new Set());
      setIdempotencyKey(newIdempotencyKey());
      setStatus('saved');
      addToast({ variant: 'success', message: t('admin.orgSettings.saved') });
    } catch (caught) {
      setStatus('ready');
      const message = apiErrorMessage(caught, t('admin.orgSettings.error'));
      setError(message);
      addToast({ variant: 'error', message });
    }
  };

  // ----- Render branches by status -----

  if (status === 'no-target' || organizationId === null) {
    const isOrgSuper = actor?.is_organization_super_admin === true;
    return (
      <div className="space-y-6 p-6" data-testid="admin-protected-page">
        <AdminPageHeader
          icon={<IconBuilding className="h-6 w-6" />}
          title={t('admin.orgSettings.title')}
          subtitle={t('admin.orgSettings.subtitle')}
        />
        <Card>
          <EmptyState
            role="status"
            icon={IconAlertCircle}
            size="md"
            title={
              isOrgSuper
                ? t('admin.orgSettings.noOrganization.orgSuperTitle')
                : t('admin.orgSettings.noOrganization.title')
            }
            description={
              isOrgSuper
                ? t('admin.orgSettings.noOrganization.orgSuperBody')
                : t('admin.orgSettings.noOrganization.body')
            }
          />
        </Card>
      </div>
    );
  }

  if (status === 'loading') {
    return (
      <div className="space-y-6 p-6" data-testid="admin-protected-page">
        <AdminPageHeader
          icon={<IconBuilding className="h-6 w-6" />}
          title={t('admin.orgSettings.title')}
          subtitle={t('admin.orgSettings.subtitle')}
        />
        <Card>
          <p role="status" className="text-sm text-[var(--text-secondary)]">
            {t('admin.orgSettings.loading')}
          </p>
        </Card>
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div className="space-y-6 p-6" data-testid="admin-protected-page">
        <AdminPageHeader
          icon={<IconBuilding className="h-6 w-6" />}
          title={t('admin.orgSettings.title')}
          subtitle={t('admin.orgSettings.subtitle')}
        />
        <Alert variant="danger" role="alert">
          {error ?? t('admin.orgSettings.error')}
        </Alert>
        <div>
          <Button variant="secondary" onClick={retryLoad}>
            {t('admin.orgSettings.retry')}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 p-6" data-testid="admin-protected-page">
      <AdminPageHeader
        icon={<IconBuilding className="h-6 w-6" />}
        title={t('admin.orgSettings.title')}
        subtitle={t('admin.orgSettings.subtitle')}
        actions={
          status === 'saved' ? (
            <span className="inline-flex items-center gap-2 text-sm text-[var(--status-success)]">
              <IconCircleCheck className="h-4 w-4" aria-hidden="true" />
              {t('admin.orgSettings.saved')}
            </span>
          ) : null
        }
      />

      {error && (
        <Alert variant="danger" role="alert">
          {error}
        </Alert>
      )}

      {Object.keys(payload.notification_templates).length === 0 &&
      !payload.branding_overrides.primary_color &&
      !payload.branding_overrides.logo_path &&
      !payload.locale_overrides.ar &&
      !payload.locale_overrides.en &&
      dirtyKeys.size === 0 && (
        <Card>
          <EmptyState
            role="status"
            icon={IconPalette}
            size="md"
            title={t('admin.orgSettings.emptyTitle')}
            description={t('admin.orgSettings.emptyBody')}
          />
        </Card>
      )}

      <form aria-busy={status === 'saving'} onSubmit={(event) => void save(event)} className="space-y-6">
        <Card>
          <SectionHeader
            icon={IconWorld}
            iconTone="info"
            title={t('admin.orgSettings.sections.locale')}
            description={t('admin.orgSettings.sections.localeBody')}
          />
          <div className="mt-4 grid gap-4 md:grid-cols-2">
            <Input
              label={t('admin.orgSettings.fields.localeAr')}
              aria-label={t('admin.orgSettings.fields.localeAr')}
              value={payload.locale_overrides.ar ?? ''}
              onChange={(event) =>
                markDirty('locale_overrides', { ar: event.target.value || null })
              }
              placeholder="ar"
              maxLength={16}
            />
            <Input
              label={t('admin.orgSettings.fields.localeEn')}
              aria-label={t('admin.orgSettings.fields.localeEn')}
              value={payload.locale_overrides.en ?? ''}
              onChange={(event) =>
                markDirty('locale_overrides', { en: event.target.value || null })
              }
              placeholder="en"
              maxLength={16}
            />
          </div>
        </Card>

        <Card>
          <SectionHeader
            icon={IconPalette}
            iconTone="admin"
            title={t('admin.orgSettings.sections.branding')}
            description={t('admin.orgSettings.sections.brandingBody')}
          />
          <div className="mt-4 grid gap-4 md:grid-cols-2">
            <Input
              label={t('admin.orgSettings.fields.primaryColor')}
              aria-label={t('admin.orgSettings.fields.primaryColor')}
              value={payload.branding_overrides.primary_color ?? ''}
              onChange={(event) =>
                markDirty('branding_overrides', {
                  primary_color: event.target.value || null,
                })
              }
              placeholder={t('admin.orgSettings.fields.primaryColorPlaceholder')}
              pattern="#[0-9A-Fa-f]{6}"
              maxLength={7}
            />
            <Input
              label={t('admin.orgSettings.fields.logoPath')}
              aria-label={t('admin.orgSettings.fields.logoPath')}
              value={payload.branding_overrides.logo_path ?? ''}
              onChange={(event) =>
                markDirty('branding_overrides', { logo_path: event.target.value || null })
              }
              placeholder="/branding/org-logo.svg"
              maxLength={255}
            />
          </div>
        </Card>

        <Card>
          <SectionHeader
            icon={IconBuilding}
            iconTone="neutral"
            title={t('admin.orgSettings.sections.templates')}
            description={t('admin.orgSettings.sections.templatesBody')}
          />
          <NotificationTemplatesEditor
            templates={payload.notification_templates}
            onChange={(next) => markDirty('notification_templates', next)}
          />
        </Card>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" loading={status === 'saving'} disabled={status === 'saving'}>
            {status === 'saving' ? t('admin.orgSettings.saving') : t('admin.orgSettings.save')}
          </Button>
          {dirtyKeys.size > 0 && (
            <span className="text-xs text-[var(--text-tertiary)]">
              {t('admin.orgSettings.unsavedChanges')}
            </span>
          )}
        </div>
      </form>
    </div>
  );
}

interface NotificationTemplatesEditorProps {
  templates: Record<string, string>;
  onChange: (next: Record<string, string>) => void;
}

function NotificationTemplatesEditor({ templates, onChange }: NotificationTemplatesEditorProps) {
  const { t } = useTranslation();
  const entries = useMemo(() => Object.entries(templates), [templates]);

  const updateKey = (oldKey: string, nextKey: string) => {
    if (oldKey === nextKey) return;
    if (!nextKey.trim()) return;
    const next: Record<string, string> = {};
    for (const [key, value] of entries) {
      if (key === oldKey) next[nextKey] = value;
      else next[key] = value;
    }
    onChange(next);
  };

  const updateValue = (key: string, value: string) => {
    const next: Record<string, string> = {};
    for (const [k, v] of entries) next[k] = k === key ? value : v;
    onChange(next);
  };

  const addRow = () => {
    let index = entries.length + 1;
    let candidate = `template_${index}`;
    while (Object.prototype.hasOwnProperty.call(templates, candidate)) {
      index += 1;
      candidate = `template_${index}`;
    }
    onChange({ ...templates, [candidate]: '' });
  };

  const removeRow = (key: string) => {
    const next: Record<string, string> = {};
    for (const [k, v] of entries) if (k !== key) next[k] = v;
    onChange(next);
  };

  return (
    <div className="mt-4 space-y-3">
      {entries.length === 0 && (
        <p className="text-sm text-[var(--text-tertiary)]">{t('admin.orgSettings.templatesEmpty')}</p>
      )}
      {entries.map(([key, value]) => (
        <div
          key={key}
          className="grid items-start gap-3 md:grid-cols-[12rem_1fr_auto]"
          data-testid="org-settings-template-row"
        >
          <Input
            aria-label={t('admin.orgSettings.fields.templateKey')}
            value={key}
            onChange={(event) => updateKey(key, event.target.value)}
            maxLength={128}
          />
          <textarea
            aria-label={t('admin.orgSettings.fields.templateValue')}
            className="min-h-20 rounded-[var(--radius-md)] border border-[var(--border-default)] bg-[var(--surface-base)] p-3 text-sm focus:border-[var(--accent-default)] focus:outline-none focus-visible:shadow-[0_0_0_2px_var(--accent-default)]"
            value={value}
            maxLength={4000}
            onChange={(event) => updateValue(key, event.target.value)}
          />
          <Button
            type="button"
            size="sm"
            variant="ghost"
            aria-label={t('admin.orgSettings.removeTemplate')}
            onClick={() => removeRow(key)}
            leftIcon={<IconTrash className="h-4 w-4" aria-hidden="true" />}
          >
            {t('admin.orgSettings.removeTemplate')}
          </Button>
        </div>
      ))}
      <Button
        type="button"
        size="sm"
        variant="secondary"
        leftIcon={<IconPlus className="h-4 w-4" aria-hidden="true" />}
        onClick={addRow}
      >
        {t('admin.orgSettings.addTemplate')}
      </Button>
    </div>
  );
}
