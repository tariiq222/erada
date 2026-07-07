import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { scopedRolesApi, AccessSummary } from '@entities/role';
import { Card } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Badge } from '@shared/ui/Badge';
import { PageHeader } from '@shared/ui/PageHeader';
import { Alert } from '@shared/ui/Alert';
import { IconShield, IconArrowRight, IconLoader } from '@tabler/icons-react';

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

/**
 * Read-only "why does this user have access" view (ADR-UNIFIED-ROLE-ACCESS, Phase 6).
 * Explains every role the user holds, its scope target, source (auto vs manual),
 * and the per-module reach cap — so governance is auditable from one screen.
 */
export const UserAccessSummary: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();

  const [data, setData] = useState<AccessSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const res = await scopedRolesApi.accessSummary(Number(id));
        setData((res as { data: AccessSummary }).data);
      } catch (err) {
        setError((err as { message?: string })?.message || t('common.error', 'حدث خطأ'));
      } finally {
        setLoading(false);
      }
    })();
  }, [id, t]);

  const reachSummary = (reach: Record<string, string>): string => {
    const entries = Object.entries(reach || {});
    if (entries.length === 0) return REACH_LABEL.all;
    return entries.map(([mod, r]) => `${mod}: ${REACH_LABEL[r] ?? r}`).join('، ');
  };

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        icon={IconShield}
        iconTone="admin"
        title={t('admin.access.title', 'صلاحيات المستخدم')}
        subtitle={t('admin.access.subtitle', 'لماذا يملك هذا المستخدم صلاحياته – الأدوار ومصدرها ومداها.')}
        actions={
          <Button variant="ghost" onClick={() => navigate(`/users/${id}`)}>
            <IconArrowRight className="w-4 h-4 me-2" />
            {t('common.back', 'رجوع')}
          </Button>
        }
      />

      {error && <Alert variant="danger">{error}</Alert>}

      {loading ? (
        <div className="flex items-center justify-center py-12">
          <IconLoader className="w-6 h-6 animate-spin text-[var(--accent-default)]" />
        </div>
      ) : data ? (
        <>
          <Card className="p-4">
            <h3 className="mb-3 text-sm font-semibold">{t('admin.access.functional', 'الأدوار الوظيفية')}</h3>
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
            <h3 className="mb-3 text-sm font-semibold">{t('admin.access.scoped', 'الأدوار المرتبطة بنطاق')}</h3>
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
                      <tr key={`${row.role}-${row.scope_type}-${row.scope_id}-${i}`} className="border-b border-[var(--border)]">
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
        </>
      ) : null}
    </div>
  );
};

export default UserAccessSummary;
