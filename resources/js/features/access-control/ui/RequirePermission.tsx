import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '@shared/contexts/AuthContext';
import { meetsAccessRequirement, type AccessRequirement } from '@shared/api/access';

interface RequirePermissionProps {
  children: React.ReactNode;
  capability?: string;
  requirement?: AccessRequirement;
  fallback?: React.ReactNode;
}

/**
 * Route guard يتحقق من صلاحية المستخدم قبل عرض المحتوى.
 * يستخدم canAccess() من AuthContext.
 */
export const RequirePermission: React.FC<RequirePermissionProps> = ({
  children,
  capability,
  requirement,
  fallback,
}) => {
  const { can, isLoading } = useAuth();

  if (isLoading) {
    return (
      <main className="flex items-center justify-center min-h-screen">
        <div className="w-12 h-12 border-4 border-[var(--accent-default)] border-t-transparent rounded-full animate-spin" role="status" />
      </main>
    );
  }

  const allowed = capability
    ? can(capability)
    : meetsAccessRequirement(can, requirement ?? {});

  if (!allowed) {
    if (fallback) {
      return <>{fallback}</>;
    }
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
};

/**
 * Admin route guard with an explicit canonical capability.
 *
 * There is intentionally no implicit "admin" role or umbrella legacy key:
 * every deep link must name the same capability enforced by its API surface.
 */
export const RequireAdmin: React.FC<{
  children: React.ReactNode;
  capability: string;
}> = ({ children, capability }) => {
  return (
    <RequirePermission capability={capability}>
      {children}
    </RequirePermission>
  );
};
