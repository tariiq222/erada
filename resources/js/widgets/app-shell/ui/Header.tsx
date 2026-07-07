import React, { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useSystemSettings } from '@shared/contexts/SystemSettingsContext';
import LanguageSwitcher from '@shared/ui/LanguageSwitcher';
import ThemeSwitcher from '@shared/ui/ThemeSwitcher';
import {IconSearch, IconMenu, IconSettings, IconLogout, IconUser, IconChevronDown, IconShield} from '@tabler/icons-react';
import { OrgSwitcher } from './OrgSwitcher';
import { NotificationBell } from '@features/meetings/components/NotificationBell';

interface HeaderProps {
  onMenuClick: () => void;
  sidebarOpen: boolean;
}

const Header: React.FC<HeaderProps> = ({ onMenuClick }) => {
  const { t } = useTranslation();
  const { user, logout, canAccess } = useAuth();
  const { settings: systemSettings } = useSystemSettings();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  // triggerRef wraps the user button (in-flow), panelRef wraps the portaled menu (in <body>).
  const triggerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const [panelStyle, setPanelStyle] = useState<React.CSSProperties>({});

  // Position the user menu from the trigger's viewport rect so it can be portaled
  // to <body> and escape any overflow-hidden/overflow-auto ancestor or stacking context.
  // RTL header: align the panel's end (right) edge to the trigger's end (right) edge,
  // mirroring the original `end-0` anchor, and clamp into the viewport.
  const positionDropdown = useCallback(() => {
    if (!buttonRef.current) return;
    const rect = buttonRef.current.getBoundingClientRect();
    const pad = 12;
    const gap = 8;
    const width = 288; // matches the menu max width (w-72)
    const panelHeight = 360; // approximate menu height for flip decision
    // Align the panel's end (right) edge to the trigger's end (right) edge, then clamp.
    const right = window.innerWidth - rect.right;
    const clampedRight = Math.min(
      Math.max(right, pad),
      Math.max(window.innerWidth - width - pad, pad),
    );
    const spaceBelow = window.innerHeight - rect.bottom - pad - gap;
    const spaceAbove = rect.top - pad - gap;
    const placeBelow = spaceBelow >= panelHeight || spaceBelow >= spaceAbove;
    const base: React.CSSProperties = { position: 'fixed', right: clampedRight, width, zIndex: 9999 };
    setPanelStyle(
      placeBelow
        ? { ...base, top: rect.bottom + gap }
        : { ...base, bottom: window.innerHeight - rect.top + gap },
    );
  }, []);

  const openDropdown = () => {
    positionDropdown();
    setDropdownOpen(true);
  };

  // Recompute position on resize and on any scroll (capture phase catches scrollable ancestors).
  useEffect(() => {
    if (!dropdownOpen) return;
    window.addEventListener('resize', positionDropdown);
    window.addEventListener('scroll', positionDropdown, true);
    return () => {
      window.removeEventListener('resize', positionDropdown);
      window.removeEventListener('scroll', positionDropdown, true);
    };
  }, [dropdownOpen, positionDropdown]);

  // Close dropdown when clicking outside BOTH the trigger and the portaled panel.
  useEffect(() => {
    if (!dropdownOpen) return;
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const inTrigger = triggerRef.current?.contains(target);
      const inPanel = panelRef.current?.contains(target);
      if (!inTrigger && !inPanel) {
        setDropdownOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [dropdownOpen]);

  return (
    <header className="fixed top-0 start-0 end-0 lg:start-[264px] z-30 h-16 bg-[var(--surface-base)] border-b border-[var(--border-default)]">
      <div className="h-full px-4 sm:px-6 flex items-center justify-between gap-2 sm:gap-4">
        {/* Mobile menu button */}
        <button
          type="button"
          onClick={onMenuClick}
          aria-label={t('nav.open_menu', { defaultValue: 'فتح القائمة / Open menu' })}
          className="lg:hidden h-11 w-11 shrink-0 inline-flex items-center justify-center rounded-lg sm:rounded-xl hover:bg-[var(--surface-muted)] text-[var(--text-secondary)] transition-colors"
        >
          <IconMenu className="h-5 w-5" aria-hidden="true" />
        </button>

        {/* IconSearch */}
        <div className="flex-1 max-w-xl hidden md:block">
          <div className="relative group">
            <IconSearch className="absolute start-3 sm:start-4 top-1/2 -translate-y-1/2 h-4 w-4 sm:h-5 sm:w-5 text-[var(--text-tertiary)] group-focus-within:text-[var(--accent-default)] transition-colors" />
            <input
              placeholder={t('common.search_system')}
              className="w-full h-10 sm:h-12 ps-10 sm:ps-12 pe-3 sm:pe-4 bg-[var(--surface-base)]/80 border-2 border-transparent rounded-lg sm:rounded-xl text-sm sm:text-base text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] focus:outline-none focus:bg-[var(--surface-base)] focus:border-[var(--accent-default)] focus:ring-4 focus:ring-[var(--accent-default)]/10 transition-[background-color,border-color,box-shadow] duration-200"
            />
            <kbd className="absolute end-4 top-1/2 -translate-y-1/2 hidden lg:inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-[var(--text-secondary)] bg-[var(--surface-muted)] rounded-lg">
              <span className="text-lg">⌘</span>K
            </kbd>
          </div>
        </div>

        {/* Actions */}
        <div className="flex items-center gap-0 sm:gap-1">
          {/* Org Switcher */}
          <OrgSwitcher />
          <ThemeSwitcher />
          {/* Language Switcher */}
          <LanguageSwitcher />

          {/* Notifications */}
          <NotificationBell />

          {/* Divider */}
          <div className="w-px h-6 sm:h-8 bg-[var(--border-default)] mx-1 sm:mx-2" />

          {/* IconUser Dropdown */}
          <div className="relative" ref={triggerRef}>
            <button
              type="button"
              ref={buttonRef}
              onClick={() => (dropdownOpen ? setDropdownOpen(false) : openDropdown())}
              aria-haspopup="menu"
              aria-expanded={dropdownOpen}
              aria-label={t('nav.user_menu', { defaultValue: 'قائمة المستخدم / IconUser menu' })}
              className="flex items-center gap-2 sm:gap-3 p-1 sm:p-2 rounded-lg sm:rounded-xl hover:bg-[var(--surface-base)] transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--surface-base)]"
            >
              <div className="hidden md:block text-end">
                <p className="text-sm font-semibold text-[var(--text-primary)]">{user?.name}</p>
                <p className="text-xs text-[var(--text-tertiary)]">{user?.email}</p>
              </div>
              <div className="relative">
                <div className="h-8 w-8 sm:h-10 sm:w-10 md:h-11 md:w-11 rounded-lg sm:rounded-xl bg-[var(--accent-default)] flex items-center justify-center">
                  <span className="text-[var(--text-inverse)] font-bold text-xs sm:text-sm md:text-lg" aria-hidden="true">
                    {user?.name?.charAt(0)}
                  </span>
                </div>
                <div className="absolute -bottom-0.5 -end-0.5 h-2.5 w-2.5 sm:h-3 sm:w-3 md:h-3.5 md:w-3.5 rounded-full bg-[var(--status-success)] border-2 border-[var(--surface-base)]" aria-hidden="true" />
              </div>
              <IconChevronDown className={`hidden md:block h-4 w-4 text-[var(--text-tertiary)] transition-transform duration-200 motion-reduce:transition-none ${dropdownOpen ? 'rotate-180' : ''}`} aria-hidden="true" />
            </button>

            {/* Dropdown IconMenu — portaled to <body> with fixed positioning so it escapes
                any overflow-hidden/overflow-auto ancestor and stacks above sibling chrome. */}
            {dropdownOpen && createPortal(
              <div
                ref={panelRef}
                role="menu"
                aria-label={t('nav.user_menu', { defaultValue: 'قائمة المستخدم / IconUser menu' })}
                style={panelStyle}
                className="bg-[var(--surface-base)] rounded-xl shadow-lg border border-[var(--border-default)] overflow-hidden animate-in fade-in zoom-in-95 duration-200 motion-reduce:animate-none"
              >
                {/* IconUser Info */}
                <div className="p-4 bg-[var(--accent-subtle)] border-b border-[var(--border-default)]">
                  <div className="flex items-center gap-3">
                    <div className="h-12 w-12 rounded-xl bg-[var(--accent-default)] flex items-center justify-center" aria-hidden="true">
                      <span className="text-[var(--text-inverse)] font-bold text-lg">
                        {user?.name?.charAt(0)}
                      </span>
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="font-semibold text-[var(--text-primary)] truncate">{user?.name}</p>
                      <p className="text-sm text-[var(--text-tertiary)] truncate">{user?.email}</p>
                    </div>
                  </div>
                </div>

                {/* IconMenu Items */}
                <div className="p-2" role="none">
                  <Link
                    role="menuitem"
                    to="/profile"
                    onClick={() => setDropdownOpen(false)}
                    className="w-full min-h-11 flex items-center gap-3 px-3 py-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-base)] transition-colors"
                  >
                    <IconUser className="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" />
                    <span className="text-sm font-medium">{t('nav.profile')}</span>
                  </Link>
                  {canAccess({ permission: 'core.view_organizations' }) && (
                    <Link
                      role="menuitem"
                      to="/admin/organizations"
                      onClick={() => setDropdownOpen(false)}
                      className="w-full min-h-11 flex items-center gap-3 px-3 py-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-base)] transition-colors"
                    >
                      <IconSettings className="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" />
                      <span className="text-sm font-medium">{t('nav.settings')}</span>
                    </Link>
                  )}
                </div>

                {/* Divider */}
                <div className="border-t border-[var(--border-default)]" />

                {/* IconLogout */}
                <div className="p-2" role="none">
                  <button
                    type="button"
                    role="menuitem"
                    onClick={logout}
                    className="w-full min-h-11 flex items-center gap-3 px-3 py-2 rounded-lg text-[var(--status-danger)] hover:bg-[var(--status-danger-subtle)] transition-colors"
                  >
                    <IconLogout className="h-4 w-4" aria-hidden="true" />
                    <span className="text-sm font-medium">{t('nav.logout')}</span>
                  </button>
                </div>

                {/* Footer - Copyright */}
                <div className="px-4 py-3 bg-[var(--surface-base)] border-t border-[var(--border-default)]">
                  <div className="flex items-center justify-center gap-2 text-xs text-[var(--text-tertiary)]">
                    <IconShield className="h-3.5 w-3.5" aria-hidden="true" />
                    <span>{t('common.all_rights_reserved')} &copy; {new Date().getFullYear()} {systemSettings?.name || t('common.app_name')}</span>
                  </div>
                </div>
              </div>,
              document.body
            )}
          </div>
        </div>
      </div>
    </header>
  );
};

export default Header;
