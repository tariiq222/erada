/**
 * MembersPanel — list every user with their authorization assignments, and open a
 * side panel with the full access summary on row click. The side panel renders
 * a chrome-less variant of UserAccessSummary so it fits the Drawer layout
 * (no outer PageHeader, no extra padding).
 */

import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconUsers, IconLoader, IconShield, IconArrowRight } from '@tabler/icons-react';
import { PageHeader } from '@shared/ui/PageHeader';
import { Card } from '@shared/ui/Card';
import { Input } from '@shared/ui/Input';
import { Button } from '@shared/ui/Button';
import { Drawer, DrawerHeader, DrawerBody } from '@shared/ui/Drawer';
import { Alert } from '@shared/ui/Alert';
import { Badge } from '@shared/ui/Badge';
import { usersApi } from '@entities/user';
import {
  authorizationAssignmentsApi,
  type AuthorizationAccessSummary,
} from '@entities/authorization-assignment';

interface MemberRow {
  id: number;
  name: string;
  email: string;
  department?: { id: number; name: string } | null;
}

const pickMembers = (raw: unknown): MemberRow[] => {
  const r = raw as { data?: MemberRow[] } | MemberRow[];
  if (Array.isArray(r)) return r;
  return r?.data ?? [];
};

const SCOPE_LABEL: Record<string, string> = {
  organization: 'المؤسسة',
  department: 'الإدارة',
  project: 'المشروع',
};

const REACH_LABEL: Record<string, string> = {
  own: 'خاص بي',
  department: 'الإدارة',
  all: 'الكل',
};

const AccessSummaryView: React.FC<{ userId: number }> = ({ userId }) => {
  const { t } = useTranslation();
  const [data, setData] = useState<AuthorizationAccessSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await authorizationAssignmentsApi.accessSummary(Number(userId));
        if (!cancelled) setData((res as { data: AuthorizationAccessSummary }).data);
      } catch (err) {
        if (!cancelled) {
          setError((err as { message?: string })?.message || t('common.error', 'حدث خطأ'));
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [userId, t]);

  const reachSummary = (reach: Record<string, string>): string => {
    const entries = Object.entries(reach || {});
    if (entries.length === 0) return REACH_LABEL.all;
    return entries.map(([mod, r]) => `${mod}: ${REACH_LABEL[r] ?? r}`).join('، ');
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
      </div>
    );
  }
  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!data) return null;

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <h3 className="mb-3 text-sm font-semibold">
          {t('admin.access.functional', 'الأدوار الوظيفية')}
        </h3>
        {data.functional_roles.length === 0 ? (
          <p className="text-sm text-[var(--text-secondary)]">—</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {data.functional_roles.map((r) => (
              <Badge key={r} variant="accent">{r}</Badge>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-4">
        <h3 className="mb-3 text-sm font-semibold">
          {t('admin.access.scoped', 'الأدوار المرتبطة بنطاق')}
        </h3>
        {data.scoped.length === 0 ? (
          <p className="text-sm text-[var(--text-secondary)]">—</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.access.role', 'الدور')}</th>
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.access.scope', 'النطاق')}</th>
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.access.source', 'المصدر')}</th>
                  <th className="py-3 px-2 text-start font-semibold">{t('admin.access.reach', 'المدى')}</th>
                </tr>
              </thead>
              <tbody>
                {data.scoped.map((row, i) => (
                  <tr
                    key={`${row.role}-${row.scope_type}-${row.scope_id}-${i}`}
                    className="border-b border-[var(--border)]"
                  >
                    <td className="py-3 px-2 font-medium">{row.label}</td>
                    <td className="py-3 px-2">
                      {(SCOPE_LABEL[row.scope_type] ?? row.scope_type)}
                      {row.scope_name ? ` – ${row.scope_name}` : ''}
                    </td>
                    <td className="py-3 px-2">
                      {row.source === 'auto' ? (
                        <Badge variant="default">{t('admin.access.auto', 'تلقائي – عضوية القسم')}</Badge>
                      ) : (
                        <Badge variant="accent">{t('admin.access.manual', 'إسناد يدوي')}</Badge>
                      )}
                    </td>
                    <td className="py-3 px-2 text-[var(--text-secondary)]">{reachSummary(row.reach)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
};

export const MembersPanel: React.FC<{ embedded?: boolean }> = ({ embedded }) => {
  const { t } = useTranslation();
  const [members, setMembers] = useState<MemberRow[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const res = (await usersApi.getList()) as unknown;
        if (!cancelled) setMembers(pickMembers(res));
      } catch (err) {
        if (!cancelled) {
          setError((err as { message?: string })?.message || t('common.error'));
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [t]);

  const filtered = search
    ? members.filter((m) => {
        const needle = search.toLowerCase();
        return (
          m.name?.toLowerCase().includes(needle) ||
          m.email?.toLowerCase().includes(needle)
        );
      })
    : members;

  return (
    <div className={embedded ? 'space-y-6' : 'p-6 space-y-6'}>
      <PageHeader
        icon={IconUsers}
        iconTone="admin"
        title={t('admin.access.members.title', 'أعضاء المؤسسة')}
        subtitle={t(
          'admin.access.members.subtitle',
          'ابحث في مستخدمي المؤسسة وافتح ملخص صلاحيات كل مستخدم. الإسناد الفعلي يتم داخل صفحة الإدارة.',
        )}
      />

      {error && <Alert variant="danger">{error}</Alert>}

      <Card className="p-4">
        <div className="relative mb-4">
          <Input
            type="text"
            placeholder={t('admin.access.members.searchPlaceholder', 'ابحث بالاسم أو البريد...')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        {loading && (
          <div className="flex items-center justify-center py-12">
            <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
          </div>
        )}
        {!loading && filtered.length === 0 && (
          <div className="text-center py-12 text-[var(--text-secondary)]">
            {t('admin.access.members.empty', 'لا يوجد أعضاء يطابقون البحث.')}
          </div>
        )}
        {!loading && filtered.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.access.members.name', 'الاسم')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.access.members.email', 'البريد')}
                  </th>
                  <th className="py-3 px-2 text-start font-semibold">
                    {t('admin.access.members.department', 'الإدارة')}
                  </th>
                  <th className="py-3 px-2 text-end font-semibold">
                    {t('common.actions')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((m) => (
                  <tr
                    key={m.id}
                    className="border-b border-[var(--border)] hover:bg-[var(--bg-hover)]"
                  >
                    <td className="py-3 px-2 font-medium">{m.name}</td>
                    <td className="py-3 px-2 text-[var(--text-secondary)] font-mono text-xs">
                      {m.email}
                    </td>
                    <td className="py-3 px-2 text-[var(--text-secondary)]">
                      {m.department?.name ?? '—'}
                    </td>
                    <td className="py-3 px-2 text-end">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setSelectedId(m.id)}
                      >
                        <IconShield className="w-4 h-4 me-2" />
                        {t('admin.access.members.viewAccess', 'عرض الصلاحيات')}
                        <IconArrowRight className="w-4 h-4 ms-2 rtl:rotate-180" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Drawer
        open={selectedId !== null}
        onClose={() => setSelectedId(null)}
        position="right"
        size="xl"
        ariaLabel={t('admin.access.drawerTitle', 'ملخص صلاحيات المستخدم')}
      >
        <DrawerHeader onClose={() => setSelectedId(null)}>
          {t('admin.access.drawerTitle', 'ملخص صلاحيات المستخدم')}
        </DrawerHeader>
        <DrawerBody className="p-0">
          {selectedId !== null && <AccessSummaryView userId={selectedId} />}
        </DrawerBody>
      </Drawer>
    </div>
  );
};

export default MembersPanel;
