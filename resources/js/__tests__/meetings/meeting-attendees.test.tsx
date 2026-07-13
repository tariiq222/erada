import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import MeetingAttendees from '@pages/strategy/meetings/view/MeetingAttendees';
import type { Meeting } from '@features/meetings/types';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

describe('MeetingAttendees cluster-safe rendering', () => {
  it('renders redacted attendees without requiring pivot data', () => {
    render(
      <MeetingAttendees
        meeting={{
          id: 1,
          attendees: [{ id: 7, name: 'مستخدم العنقود' }],
        } as Meeting}
      />,
    );

    expect(screen.getByText('مستخدم العنقود')).toBeInTheDocument();
  });
});
