import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { IconChevronDown, IconTrash } from '@tabler/icons-react';
import { Button, Card, CardContent, CardHeader, CardTitle, Checkbox, IconButton } from '@shared/ui';
import { cn } from '@shared/lib/utils';
import type { MeetingFormData } from './useMeetingForm';

interface Props {
  data: MeetingFormData;
  onChange: <K extends keyof MeetingFormData>(key: K, value: MeetingFormData[K]) => void;
  userOptions: { value: number; label: string }[];
}

const MeetingAttendeesPicker: React.FC<Props> = ({ data, onChange, userOptions }) => {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [staged, setStaged] = useState<number[]>([]);
  const containerRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const [dropdownStyle, setDropdownStyle] = useState<React.CSSProperties>({});

  const available = userOptions.filter((u) => !data.attendee_ids.includes(u.value));
  const selectedUsers = userOptions.filter((u) => data.attendee_ids.includes(u.value));

  // Position the floating panel from the trigger's viewport rect so it can be
  // portaled to <body> and escape any overflow-hidden ancestor (cards/tables).
  const positionDropdown = useCallback(() => {
    if (!buttonRef.current) return;
    const rect = buttonRef.current.getBoundingClientRect();
    const pad = 12;
    const gap = 4;
    const desired = 240;
    const spaceBelow = window.innerHeight - rect.bottom - pad - gap;
    const spaceAbove = rect.top - pad - gap;
    const placeBelow = spaceBelow >= desired || spaceBelow >= spaceAbove;
    const base: React.CSSProperties = { position: 'fixed', left: rect.left, width: rect.width, zIndex: 9999 };
    setDropdownStyle(
      placeBelow
        ? { ...base, top: rect.bottom + gap }
        : { ...base, bottom: window.innerHeight - rect.top + gap }
    );
  }, []);

  const openDropdown = () => {
    positionDropdown();
    setOpen(true);
  };

  useEffect(() => {
    if (!open) return;
    window.addEventListener('resize', positionDropdown);
    window.addEventListener('scroll', positionDropdown, true);
    return () => {
      window.removeEventListener('resize', positionDropdown);
      window.removeEventListener('scroll', positionDropdown, true);
    };
  }, [open, positionDropdown]);

  useEffect(() => {
    if (!open) return;
    const onClickOutside = (e: MouseEvent) => {
      const target = e.target as Node;
      const inContainer = containerRef.current?.contains(target);
      const inDropdown = dropdownRef.current?.contains(target);
      if (!inContainer && !inDropdown) setOpen(false);
    };
    document.addEventListener('mousedown', onClickOutside);
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [open]);

  const toggleStaged = (id: number) => {
    setStaged((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const addStaged = () => {
    if (staged.length === 0) return;
    onChange('attendee_ids', [...data.attendee_ids, ...staged]);
    setStaged([]);
    setOpen(false);
  };

  const removeAttendee = (id: number) => {
    onChange('attendee_ids', data.attendee_ids.filter((x) => x !== id));
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.meeting.fields.attendees')}</CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div className="flex items-end gap-2">
          <div className="relative flex-1" ref={containerRef}>
            <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
              {t('meetings.meeting.form.select_attendees')}
            </label>
            <button
              type="button"
              ref={buttonRef}
              onClick={() => (open ? setOpen(false) : openDropdown())}
              aria-haspopup="listbox"
              aria-expanded={open}
              className={cn(
                'relative w-full text-start px-3 py-2 pe-10 text-sm rounded-md',
                'bg-[var(--surface-base)] border text-[var(--text-primary)]',
                open ? 'border-[var(--accent-default)] ring-2 ring-[var(--accent-subtle)]'
                  : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
              )}
            >
              <span className={cn('block truncate', staged.length === 0 && 'text-[var(--text-secondary)]')}>
                {staged.length > 0
                  ? available.filter((u) => staged.includes(u.value)).map((u) => u.label).join('، ')
                  : t('meetings.meeting.form.select_attendees')}
              </span>
              <span className="absolute inset-y-0 end-0 flex items-center pe-3 pointer-events-none">
                <IconChevronDown className={cn('h-4 w-4 transition-transform', open && 'rotate-180 text-[var(--accent-default)]')} />
              </span>
            </button>
            {open && createPortal(
              <div
                ref={dropdownRef}
                style={dropdownStyle}
                className="bg-[var(--surface-base)] border border-[var(--border-default)] rounded-md shadow-lg overflow-hidden"
              >
                <ul role="listbox" className="py-1 max-h-60 overflow-auto">
                  {available.length === 0 && (
                    <li className="px-3 py-2 text-sm text-[var(--text-secondary)] text-center">—</li>
                  )}
                  {available.map((u) => (
                    <li key={u.value} className="px-3 py-1.5">
                      <Checkbox
                        label={u.label}
                        checked={staged.includes(u.value)}
                        onChange={() => toggleStaged(u.value)}
                      />
                    </li>
                  ))}
                </ul>
              </div>,
              document.body
            )}
          </div>
          <Button type="button" onClick={addStaged} disabled={staged.length === 0}>
            {t('meetings.meeting.actions.add_attendees')}
          </Button>
        </div>

        <div>
          <span className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
            {t('meetings.meeting.detail.attendees')}
          </span>
          {selectedUsers.length > 0 ? (
            <ul className="divide-y divide-[var(--border-default)] rounded-md border border-[var(--border-default)]">
              {selectedUsers.map((u) => (
                <li key={u.value} className="flex items-center justify-between px-3 py-2 text-sm">
                  <span className="text-[var(--text-primary)]">{u.label}</span>
                  <IconButton
                    type="button"
                    variant="danger"
                    size="xs"
                    onClick={() => removeAttendee(u.value)}
                    aria-label={t('meetings.meeting.actions.remove_attendee')}
                    title={t('meetings.meeting.actions.remove_attendee')}
                  >
                    <IconTrash className="h-4 w-4" />
                  </IconButton>
                </li>
              ))}
            </ul>
          ) : (
            <p className="rounded-md border border-dashed border-[var(--border-default)] px-3 py-4 text-center text-sm text-[var(--text-tertiary)]">
              –
            </p>
          )}
        </div>
      </CardContent>
    </Card>
  );
};

export default MeetingAttendeesPicker;
