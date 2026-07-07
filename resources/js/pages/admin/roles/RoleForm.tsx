import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { rolesApi, Role, AbilityGroup } from '@entities/role';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Input } from '@shared/ui/Input';
import { Select } from '@shared/ui/Select';
import { Switch } from '@shared/ui/Switch';
import { PageHeader } from '@shared/ui/PageHeader';
import { SectionHeader } from '@shared/ui/SectionHeader';
import { Alert } from '@shared/ui/Alert';
import DeleteConfirmationModal from '@shared/ui/DeleteConfirmationModal';
import type { SectionHeaderIconTone } from '@shared/ui/SectionHeader';
import {
  IconDeviceFloppy,
  IconLoader,
  IconTrash,
  IconShield,
  IconShieldLock,
  IconX,
  IconFolders,
  IconChecklist,
  IconAlertTriangle,
  IconFlag,
  IconBuildingSkyscraper,
  IconBuildingCommunity,
  IconUsers,
  IconSitemap,
  IconUserCog,
  IconLayoutDashboard,
  IconReportAnalytics,
  IconHistory,
  IconSettings,
  IconPaperclip,
  IconMessage,
  IconTargetArrow,
  IconCalendarEvent,
  IconChartBar,
  IconClipboardText,
  IconCategory,
  IconDots,
  IconShieldCheck,
  type TablerIcon,
} from '@tabler/icons-react';

const GROUP_META: Record<string, { icon: TablerIcon; tone: SectionHeaderIconTone }> = {
  // engine modules
  projects: { icon: IconFolders, tone: 'project' },
  tasks: { icon: IconChecklist, tone: 'task' },
  departments: { icon: IconSitemap, tone: 'neutral' },
  strategy: { icon: IconTargetArrow, tone: 'accent' },
  risks: { icon: IconAlertTriangle, tone: 'risk' },
  ovr: { icon: IconFlag, tone: 'warning' },
  // flat groups
  management: { icon: IconBuildingSkyscraper, tone: 'admin' },
  organizations: { icon: IconBuildingCommunity, tone: 'info' },
  users: { icon: IconUsers, tone: 'info' },
  roles: { icon: IconShieldLock, tone: 'admin' },
  hr: { icon: IconUserCog, tone: 'neutral' },
  dashboard: { icon: IconLayoutDashboard, tone: 'accent' },
  reports: { icon: IconReportAnalytics, tone: 'info' },
  audit: { icon: IconHistory, tone: 'neutral' },
  settings: { icon: IconSettings, tone: 'neutral' },
  attachments: { icon: IconPaperclip, tone: 'neutral' },
  comments: { icon: IconMessage, tone: 'neutral' },
  meetings: { icon: IconCalendarEvent, tone: 'info' },
  kpis: { icon: IconChartBar, tone: 'success' },
  surveys: { icon: IconClipboardText, tone: 'survey' },
  ovr_categories: { icon: IconCategory, tone: 'warning' },
  other: { icon: IconDots, tone: 'neutral' },
};

export const RoleForm: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id?: string }>();
  const isEdit = !!id && id !== 'new';

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [form, setForm] = useState<{ name: string; scope: string; label_ar: string; label_en: string; selected: string[] }>({
    name: '',
    scope: 'organization',
    label_ar: '',
    label_en: '',
    selected: [],
  });
  const [groups, setGroups] = useState<AbilityGroup[]>([]);
  const [scopeOptions, setScopeOptions] = useState<{ key: string; label: string }[]>([]);
  const [isSystem, setIsSystem] = useState(false);
  // Per-module reach cap: module -> own | department | all (default all).
  const [reach, setReach] = useState<Record<string, string>>({});

  useEffect(() => {
    (async () => {
      try {
        const res = await rolesApi.abilities();
        setGroups(res.data.groups);
      } catch {
        // ignore — empty registry renders no toggles
      }
    })();
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const res = await rolesApi.scopeOptions();
        setScopeOptions(res.scopes);
      } catch {
        // ignore - the picker falls back to the organization default
      }
    })();
  }, []);

  useEffect(() => {
    if (!isEdit) return;
    (async () => {
      try {
        const res = (await rolesApi.get(Number(id))) as { data: Role };
        const role = res.data;
        // A role's grants live in two stores; the builder merges them into one set.
        const selected = Array.from(
          new Set([...(role.permissions || []), ...(role.capabilities || [])])
        );
        setForm({
          name: role.name,
          scope: role.scope_type || 'organization',
          label_ar: role.label_ar || '',
          label_en: role.label_en || '',
          selected,
        });
        setReach((role.reach as Record<string, string>) || {});
        setIsSystem(role.is_system);
      } catch (err: any) {
        setError(err?.message || t('common.error'));
      } finally {
        setLoading(false);
      }
    })();
  }, [id, isEdit, t]);

  const locked = isEdit && isSystem;

  // id -> store, so submit routes each grant to the correct backend column.
  const idToStore = useMemo(() => {
    const map = new Map<string, 'engine' | 'flat'>();
    groups.forEach((g) => g.abilities.forEach((a) => map.set(a.id, g.store)));
    return map;
  }, [groups]);

  // Modules the role grants at least one engine capability in — each gets a reach dial.
  const selectedModules = useMemo(() => {
    const mods = new Set<string>();
    form.selected.forEach((id) => {
      if (idToStore.get(id) === 'engine' && id.includes('.')) mods.add(id.split('.')[0]);
    });
    return Array.from(mods).sort();
  }, [form.selected, idToStore]);

  const moduleLabel = useMemo(() => {
    const map = new Map<string, string>();
    groups.forEach((g) => (map.get(g.key) ? null : map.set(g.key, g.label)));
    return (mod: string) => map.get(mod) ?? mod;
  }, [groups]);

  const setReachFor = (mod: string, value: string) => {
    if (locked) return;
    setReach((prev) => ({ ...prev, [mod]: value }));
  };

  const has = (abilityId: string) => form.selected.includes(abilityId);

  const toggle = (abilityId: string) => {
    if (locked) return;
    setForm((prev) => ({
      ...prev,
      selected: prev.selected.includes(abilityId)
        ? prev.selected.filter((p) => p !== abilityId)
        : [...prev.selected, abilityId],
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const permissions = form.selected.filter((p) => idToStore.get(p) === 'flat');
      const permissions_capabilities = form.selected.filter((p) => idToStore.get(p) === 'engine');
      // Send only non-default reaches for granted modules — reach only restricts.
      const reachPayload: Record<string, string> = {};
      selectedModules.forEach((m) => {
        if (reach[m] && reach[m] !== 'all') reachPayload[m] = reach[m];
      });
      const payload = {
        name: form.name,
        scope_type: form.scope,
        label_ar: form.label_ar,
        label_en: form.label_en,
        permissions,
        permissions_capabilities,
        reach: reachPayload,
      };
      if (isEdit) await rolesApi.update(Number(id), payload);
      else await rolesApi.create(payload);
      navigate('/admin/roles');
    } catch (err: any) {
      setError(err?.message || t('common.error'));
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!isEdit || isSystem) return;
    setDeleting(true);
    try {
      await rolesApi.delete(Number(id));
      navigate('/admin/roles');
    } catch (err: any) {
      setError(err?.message || t('common.error'));
      setDeleting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
      </div>
    );
  }

  const selectedCount = form.selected.length;

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <PageHeader
        icon={isSystem ? IconShieldLock : IconShield}
        iconTone="admin"
        title={isEdit ? t('admin.roles.editTitle') : t('admin.roles.addTitle')}
        subtitle={t('admin.roles.subtitle', 'حدّد صلاحيات الدور')}
        metadata={
          <span className="text-[var(--text-tertiary)]">
            {selectedCount} {t('admin.roles.selectedPermissions', 'صلاحية مفعّلة')}
          </span>
        }
        actions={
          <div className="flex gap-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/admin/roles')}>
              <IconX className="w-4 h-4 me-2" />
              {t('common.cancel')}
            </Button>
            <Button type="submit" form="role-form" disabled={saving || locked}>
              <IconDeviceFloppy className="w-4 h-4 me-2" />
              {saving ? t('common.saving') : t('common.save')}
            </Button>
          </div>
        }
      />

      <form id="role-form" onSubmit={handleSubmit} className="space-y-6">
        {error && <Alert variant="danger">{error}</Alert>}

        {/* اسم الدور */}
        <Card className="p-5">
          <label
            htmlFor="role-name"
            className="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
          >
            {t('admin.roles.fields.name')} *
          </label>
          <Input
            id="role-name"
            required
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder={t('admin.roles.fields.namePlaceholder')}
            disabled={locked}
            className="max-w-sm"
          />
          {isSystem && (
            <p className="mt-2 text-xs text-[var(--status-warning-text,var(--status-warning))]">
              {t('admin.roles.systemRoleWarning')}
            </p>
          )}
          <div className="mt-4 max-w-sm">
            <Select
              id="role-scope"
              label={t('admin.roles.fields.scope', 'النطاق')}
              value={form.scope}
              onChange={(e) => setForm({ ...form, scope: e.target.value })}
              disabled={locked}
              options={
                scopeOptions.length > 0
                  ? scopeOptions.map((s) => ({ value: s.key, label: s.label }))
                  : [{ value: 'organization', label: t('admin.roles.fields.scopeOrganization', 'المؤسسة') }]
              }
            />
            <p className="mt-1.5 text-xs text-[var(--text-tertiary)]">
              {t('admin.roles.fields.scopeHint', 'النطاق يحدد أين يُعرّف الدور؛ الافتراضي هو المؤسسة.')}
            </p>
          </div>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <label
                htmlFor="role-label-ar"
                className="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
              >
                {t('admin.roles.fields.labelAr')}
              </label>
              <Input
                id="role-label-ar"
                value={form.label_ar}
                onChange={(e) => setForm({ ...form, label_ar: e.target.value })}
                placeholder={t('admin.roles.fields.labelArPlaceholder')}
                disabled={locked}
                className="max-w-sm"
              />
            </div>
            <div>
              <label
                htmlFor="role-label-en"
                className="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
              >
                {t('admin.roles.fields.labelEn')}
              </label>
              <Input
                id="role-label-en"
                value={form.label_en}
                onChange={(e) => setForm({ ...form, label_en: e.target.value })}
                placeholder={t('admin.roles.fields.labelEnPlaceholder')}
                disabled={locked}
                className="max-w-sm"
              />
            </div>
          </div>
        </Card>

        {/* الصلاحيات — بطاقة لكل وحدة */}
        {groups.length > 0 && (
          <div className="space-y-4">
            <p className="text-sm text-[var(--text-tertiary)]">
              {t(
                'admin.roles.abilitiesHint',
                'فعّل صلاحيات كل وحدة، ثم حدّد مدى كل وحدة في القسم التالي.'
              )}
            </p>
            {groups.map((group) => (
              <AbilityGroupCard
                key={group.key}
                group={group}
                has={has}
                toggle={toggle}
                disabled={locked}
              />
            ))}
          </div>
        )}

        {/* مصفوفة المدى — لكل وحدة ممنوحة: خاص بي / الإدارة / الكل */}
        {selectedModules.length > 0 && (
          <Card className="p-5">
            <SectionHeader
              level={3}
              size="compact"
              icon={IconShieldCheck}
              iconTone="neutral"
              title={t('admin.roles.reachTitle', 'مدى الصلاحيات')}
              className="mb-2"
            />
            <p className="mb-4 text-sm text-[var(--text-tertiary)]">
              {t('admin.roles.reachHint', 'لكل وحدة، حدّد إلى أي مدى تصل صلاحياتها. المدى يقيّد فقط ولا يوسّع.')}
            </p>
            <div className="space-y-1">
              {selectedModules.map((mod) => (
                <div
                  key={mod}
                  className="flex items-center justify-between gap-4 rounded-[var(--radius-md)] px-2 py-2 hover:bg-[var(--bg-hover)]"
                >
                  <span className="shrink-0 text-sm font-medium text-[var(--text-secondary)]">
                    {moduleLabel(mod)}
                  </span>
                  <div className="w-48">
                    <Select
                      options={[
                        { value: 'all', label: t('admin.roles.reachAll', 'الكل') },
                        { value: 'department', label: t('admin.roles.reachDepartment', 'الإدارة') },
                        { value: 'own', label: t('admin.roles.reachOwn', 'خاص بي') },
                      ]}
                      value={reach[mod] ?? 'all'}
                      onChange={(e) => setReachFor(mod, e.target.value)}
                      disabled={locked}
                    />
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}

        {/* تذييل الإجراءات - زر الحذف فقط */}
        {isEdit && !isSystem && (
          <div className="flex items-center pt-4 border-t border-[var(--border)]">
            <Button
              type="button"
              variant="danger"
              onClick={() => setShowDeleteModal(true)}
              disabled={deleting}
            >
              <IconTrash className="w-4 h-4 me-2" />
              {deleting ? t('common.deleting') : t('common.delete')}
            </Button>
          </div>
        )}
      </form>

      <DeleteConfirmationModal<{ name: string }>
        isOpen={showDeleteModal}
        item={isEdit && !isSystem ? { name: form.name } : null}
        title={t('common.confirm_delete')}
        itemName={form.name}
        warningMessage={t('admin.roles.confirmDelete')}
        confirmButtonText={t('common.delete')}
        isDeleting={deleting}
        onClose={() => !deleting && setShowDeleteModal(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
};

const AbilityGroupCard: React.FC<{
  group: AbilityGroup;
  has: (id: string) => boolean;
  toggle: (id: string) => void;
  disabled: boolean;
}> = ({ group, has, toggle, disabled }) => {
  const meta = GROUP_META[group.key] ?? { icon: IconShieldCheck, tone: 'neutral' as const };
  return (
    <Card className="p-5">
      <SectionHeader
        level={3}
        size="compact"
        icon={meta.icon}
        iconTone={meta.tone}
        title={group.label}
        className="mb-4"
      />
      <div className="space-y-1">
        {group.abilities.map((ability) => (
          <div
            key={ability.id}
            className="flex items-center justify-between gap-4 rounded-[var(--radius-md)] px-2 py-2 hover:bg-[var(--bg-hover)]"
          >
            <span className="shrink-0 text-sm font-medium text-[var(--text-secondary)]">
              {ability.label}
            </span>
            <Switch
              checked={has(ability.id)}
              disabled={disabled}
              onChange={() => toggle(ability.id)}
              size="sm"
              aria-label={ability.id}
            />
          </div>
        ))}
      </div>
    </Card>
  );
};

export default RoleForm;
