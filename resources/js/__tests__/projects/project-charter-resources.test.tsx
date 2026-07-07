import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { NewProjectCharter } from '@pages/projects/charter/NewProjectCharter';

// Regression test for the defect where the project's resources
// (human / technical / financial) were collected in the form but never
// rendered in the generated charter (section 7 read a never-populated
// `resources` string). These assertions fail before the fix and pass after.
describe('NewProjectCharter — resources & budget summary (section 7)', () => {
  it('renders the human, technical and financial resource values', () => {
    render(
      <NewProjectCharter
        data={{
          name: 'مشروع تجريبي',
          budget: '500000',
          humanResources: 'فريق التطوير وفريق الجودة',
          technicalResources: 'خوادم سحابية وأدوات CI',
          financialResources: 'ميزانية تشغيلية معتمدة',
        }}
      />
    );

    expect(screen.getByText('فريق التطوير وفريق الجودة')).toBeInTheDocument();
    expect(screen.getByText('خوادم سحابية وأدوات CI')).toBeInTheDocument();
    expect(screen.getByText('ميزانية تشغيلية معتمدة')).toBeInTheDocument();
    expect(screen.getByText('500000')).toBeInTheDocument();
  });

  it('renders the three resource labels under the summary section', () => {
    render(<NewProjectCharter data={{ name: 'مشروع تجريبي' }} />);

    expect(screen.getByText('الموارد البشرية')).toBeInTheDocument();
    expect(screen.getByText('الموارد التقنية')).toBeInTheDocument();
    expect(screen.getByText('الموارد المالية')).toBeInTheDocument();
  });

  it('falls back to a dash when a resource value is missing', () => {
    render(
      <NewProjectCharter
        data={{ name: 'مشروع تجريبي', humanResources: 'فريق التطوير' }}
      />
    );

    expect(screen.getByText('فريق التطوير')).toBeInTheDocument();
    // technical + financial resources + budget are unset -> ' - ' placeholders
    expect(screen.getAllByText('-').length).toBeGreaterThanOrEqual(3);
  });
});
