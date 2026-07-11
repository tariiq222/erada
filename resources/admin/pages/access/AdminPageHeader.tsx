import { createElement, isValidElement, type ComponentType, type ReactNode } from 'react';

export function AdminPageHeader({ title, subtitle, icon, actions }: { title: ReactNode; subtitle?: ReactNode; icon?: ReactNode | ComponentType<{ className?: string }>; actions?: ReactNode; iconTone?: string }) {
  const renderedIcon = isValidElement(icon) ? icon : icon ? createElement(icon as ComponentType<{ className?: string }>, { className: 'h-6 w-6' }) : null;
  return <header className="flex flex-wrap items-start justify-between gap-4"><div className="flex items-start gap-3"><span className="mt-1 text-[var(--accent-default)]">{renderedIcon}</span><div><h1 className="text-2xl font-bold text-[var(--text-primary)]">{title}</h1>{subtitle && <p className="text-sm text-[var(--text-tertiary)]">{subtitle}</p>}</div></div>{actions}</header>;
}
