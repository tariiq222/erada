import React, { useState, useEffect, useCallback } from 'react';
import {IconShield, IconLock, IconLockOpen, IconAlertTriangle} from '@tabler/icons-react';
import { Card, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { Badge } from '@shared/ui/Badge';
import { Skeleton } from '@shared/ui/Skeleton';
import { formatDate } from '@shared/lib/utils';
import { usersApi } from '@entities/user';
import type { UserRoleAssignment, UserSecurity } from '@entities/user';
import { RequirePermission } from '@features/access-control/ui/RequirePermission';

interface Props {
  userId: number;
}

export const UserSecurityCard: React.FC<Props> = ({ userId }) => {
  const [security, setSecurity] = useState<UserSecurity | null>(null);
  const [roleAssignments, setRoleAssignments] = useState<UserRoleAssignment[] | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [hasError, setHasError] = useState(false);
  const [isUnlocking, setIsUnlocking] = useState(false);

  const loadSecurity = useCallback(async () => {
    try {
      const res = (await usersApi.getSecurity(userId)) as { security: UserSecurity };
      setSecurity(res.security);
    } catch {
      setHasError(true);
    }
  }, [userId]);

  const loadData = useCallback(async () => {
    setIsLoading(true);
    setHasError(false);
    try {
      const [secRes, rolesRes] = await Promise.all([
        usersApi.getSecurity(userId) as Promise<{ security: UserSecurity }>,
        usersApi.roleAssignments(userId) as Promise<{ data: UserRoleAssignment[] }>,
      ]);
      setSecurity(secRes.security);
      setRoleAssignments(rolesRes.data);
    } catch {
      setHasError(true);
    } finally {
      setIsLoading(false);
    }
  }, [userId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleUnlock = async () => {
    setIsUnlocking(true);
    try {
      await usersApi.unlock(userId);
      await loadSecurity();
    } catch {
      // فشل فك القفل – لا نخرج من الصفحة
    } finally {
      setIsUnlocking(false);
    }
  };

  if (isLoading) {
    return (
      <Card className="p-0">
        <CardContent className="p-6 space-y-4">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-3/4" />
          <Skeleton className="h-4 w-1/2" />
        </CardContent>
      </Card>
    );
  }

  if (hasError || !security) {
    return (
      <Card className="p-0">
        <CardContent className="p-6">
          <div className="flex items-center gap-2 text-[var(--text-secondary)]">
            <IconAlertTriangle className="h-5 w-5" />
            <span>تعذّر تحميل بيانات الأمان</span>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      {/* بطاقة حالة الأمان */}
      <Card className="p-0">
        <CardContent className="p-6">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <IconShield className="h-5 w-5 text-[var(--text-tertiary)]" />
              <h3 className="text-base font-semibold text-[var(--text-primary)]">
                حالة الأمان
              </h3>
            </div>
            <div className="flex items-center gap-3">
              {security.is_locked ? (
                <Badge variant="danger">
                  <IconLock className="h-3 w-3 me-1" />
                  مقفول
                </Badge>
              ) : (
                <Badge variant="success">
                  <IconLockOpen className="h-3 w-3 me-1" />
                  نشط
                </Badge>
              )}
              {security.is_locked && (
                <RequirePermission capability="users.edit" fallback={null}>
                  <Button
                    variant="danger"
                    loading={isUnlocking}
                    onClick={handleUnlock}
                  >
                    فك القفل
                  </Button>
                </RequirePermission>
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* المحاولات الفاشلة */}
            <div>
              <p className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
                المحاولات الفاشلة
              </p>
              <p className="text-[var(--text-primary)]">{security.failed_attempts}</p>
            </div>

            {/* آخر تسجيل دخول */}
            <div>
              <p className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
                آخر تسجيل دخول
              </p>
              {security.last_login ? (
                <p className="text-[var(--text-primary)]">
                  {formatDate(security.last_login)}
                  {security.last_login_ip && (
                    <span className="text-[var(--text-tertiary)] ms-2">
                      – {security.last_login_ip}
                    </span>
                  )}
                </p>
              ) : (
                <p className="text-[var(--text-tertiary)]">لا يوجد</p>
              )}
            </div>

            {/* آخر محاولة فاشلة */}
            <div>
              <p className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
                آخر محاولة فاشلة
              </p>
              {security.last_failed_login ? (
                <p className="text-[var(--text-primary)]">
                  {formatDate(security.last_failed_login)}
                </p>
              ) : (
                <p className="text-[var(--text-tertiary)]">لا يوجد</p>
              )}
            </div>

            {/* مقفول حتى */}
            {security.locked_until && (
              <div>
                <p className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
                  مقفول حتى
                </p>
                <p className="text-[var(--text-primary)]">
                  {formatDate(security.locked_until)}
                </p>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* بطاقة إسنادات الأدوار */}
      {roleAssignments && (
        <Card className="p-0">
          <CardContent className="p-6">
            <div className="flex items-center gap-2 mb-4">
              <IconShield className="h-5 w-5 text-[var(--text-tertiary)]" />
              <h3 className="text-base font-semibold text-[var(--text-primary)]">
                الأدوار السياقية
              </h3>
            </div>

            <div className="space-y-5">
              {/* المشاريع */}
              <div>
                <p className="text-sm font-medium text-[var(--text-secondary)] mb-2 border-b border-[var(--border-default)] pb-1">
                  المشاريع
                </p>
                {roleAssignments.some((assignment) => assignment.scope_type === 'project') ? (
                  <ul className="space-y-2">
                    {roleAssignments.filter((assignment) => assignment.scope_type === 'project').map((item) => (
                      <li
                        key={item.id}
                        className="flex items-center justify-between"
                      >
                        <span className="text-[var(--text-primary)] text-sm">
                          {item.scope_name}
                        </span>
                        <div className="flex items-center gap-2">
                          <Badge variant="accent">{item.label}</Badge>
                          {item.expires_at && (
                            <span className="text-xs text-[var(--text-tertiary)]">
                              حتى: {formatDate(item.expires_at)}
                            </span>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-[var(--text-tertiary)]">لا توجد أدوار</p>
                )}
              </div>

              {/* الأقسام */}
              <div>
                <p className="text-sm font-medium text-[var(--text-secondary)] mb-2 border-b border-[var(--border-default)] pb-1">
                  الأقسام
                </p>
                {roleAssignments.some((assignment) => assignment.scope_type === 'department') ? (
                  <ul className="space-y-2">
                    {roleAssignments.filter((assignment) => assignment.scope_type === 'department').map((item) => (
                      <li
                        key={item.id}
                        className="flex items-center justify-between"
                      >
                        <span className="text-[var(--text-primary)] text-sm">
                          {item.scope_name}
                        </span>
                        <div className="flex items-center gap-2">
                          <Badge variant="accent">{item.label}</Badge>
                          {item.expires_at && (
                            <span className="text-xs text-[var(--text-tertiary)]">
                              حتى: {formatDate(item.expires_at)}
                            </span>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-[var(--text-tertiary)]">لا توجد أدوار</p>
                )}
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default UserSecurityCard;
