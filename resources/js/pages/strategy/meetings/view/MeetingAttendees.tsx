import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle } from '@shared/ui';
import type { Meeting } from '@features/meetings/types';

interface Props { meeting: Meeting }

const MeetingAttendees: React.FC<Props> = ({ meeting }) => {
  const { t } = useTranslation();
  const list = meeting.attendees ?? [];
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.meeting.detail.attendees')}</CardTitle>
      </CardHeader>
      <CardContent>
        {list.length === 0 ? (
          <p className="text-sm text-[var(--text-tertiary)]">—</p>
        ) : (
          <ul className="divide-y divide-[var(--border-default)]">
            {list.map((a) => (
              <li key={a.id} className="flex items-center justify-between py-2 text-sm">
                <span className="text-[var(--text-primary)]">{a.name}</span>
                {a.pivot?.role && (
                  <span className="text-xs text-[var(--text-tertiary)]">{a.pivot.role}</span>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
};

export default MeetingAttendees;
