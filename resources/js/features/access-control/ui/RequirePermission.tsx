import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '@shared/contexts/AuthContext';
import type { AccessConfig } from '@shared/contexts/AuthContext';

interface RequirePermissionProps {
  children: React.ReactNode;
  config?: AccessConfig;
  fallback?: React.ReactNode;
}

/**
 * Route guard يتحقق من صلاحية المستخدم قبل عرض المحتوى.
 * يستخدم canAccess() من AuthContext.
 */
export const RequirePermission: React.FC<RequirePermissionProps> = ({
  children,
  config,
  fallback,
}) => {
  const { canAccess, isLoading } = useAuth();

  if (isLoading) {
    return (
      <main className="flex items-center justify-center min-h-screen">
        <div className="w-12 h-12 border-4 border-[var(--accent-default)] border-t-transparent rounded-full animate-spin" role="status" />
      </main>
    );
  }

  if (!canAccess(config ?? {})) {
    if (fallback) {
      return <>{fallback}</>;
    }
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
};

/**
 * Route guard لتير الإدارة — مقاد بصلاحية manage_organization (super_admin يتجاوز)
 */
export const RequireAdmin: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  return (
    <RequirePermission config={{ permissions: ['manage_organization'] }}>
      {children}
    </RequirePermission>
  );
};
