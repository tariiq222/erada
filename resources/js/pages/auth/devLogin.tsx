import React from 'react';
import { Button } from '@shared/ui';

const DEV_PASSWORD = 'password';

const DEV_ACCOUNTS = [
  { label: 'مدير النظام', email: 'admin@admin.com' },
  { label: 'مدير إدارة', email: 'manager@admin.com' },
  { label: 'موظف مشاريع', email: 'pmo.member@demo.com' },
  { label: 'مدير المشاريع', email: 'pmo.manager@demo.com' },
] as const;

interface DevQuickLoginProps {
  disabled: boolean;
  onPick: (email: string, password: string) => void;
}

export const DevQuickLogin: React.FC<DevQuickLoginProps> = ({ disabled, onPick }) => (
  <div className="mt-6 pt-5 border-t border-dashed border-[var(--border-default)]">
    <p className="text-xs text-center text-[var(--text-secondary)] mb-3">
      دخول سريع (تطوير فقط)
    </p>
    <div className="grid grid-cols-2 gap-2">
      {DEV_ACCOUNTS.map((account) => (
        <Button
          key={account.email}
          type="button"
          variant="outline"
          onClick={() => onPick(account.email, DEV_PASSWORD)}
          disabled={disabled}
          className="h-10 px-2 text-xs"
        >
          {account.label}
        </Button>
      ))}
    </div>
    <p
      dir="ltr"
      className="text-[11px] text-center text-[var(--text-secondary)] mt-2 font-mono"
    >
      كلمة المرور الافتراضية: {DEV_PASSWORD}
    </p>
  </div>
);
