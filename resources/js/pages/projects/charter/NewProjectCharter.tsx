import React from 'react';

export interface NewProjectCharterData {
  name: string;
  projectCode?: string;
  startDate?: string;
  endDate?: string;
  status?: string;
  description?: string;
  department?: string;
  program?: string;
  priority?: string;
  budget?: string;
  businessCase?: string;
  // These three are cast to arrays by the backend; accept array or string.
  managerAuthority?: string[] | string;
  objectives?: string;
  scopeIncluded?: string;
  scopeExcluded?: string;
  successCriteria?: string[] | string;
  highLevelRequirements?: string[] | string;
  teamMembers?: string;
  stakeholders?: string;
  milestones?: Array<{ name: string; deliverable: string; date: string }>;
  risks?: string;
  humanResources?: string;
  technicalResources?: string;
  financialResources?: string;
  approvalRequirements?: string;
  exitCriteria?: string;
  managerName?: string;
  pmoSupervisor?: string;
  sponsorName?: string;
}

// Accepts either an array (backend casts success_criteria / requirements /
// manager_authority to arrays) or a newline/comma-separated string.
function toList(value?: string[] | string | null): string[] {
  if (!value) return [];
  if (Array.isArray(value)) return value.map((s) => String(s).trim()).filter(Boolean);
  return value.split(/[\n،,]+/).map((s) => s.trim()).filter(Boolean);
}

const sectionHeadingStyle: React.CSSProperties = {
  color: 'var(--text-primary)',
  borderBottom: '1px solid var(--border-default)',
  paddingBottom: '0.25rem',
  marginBottom: '0.75rem',
  fontSize: '1rem',
  fontWeight: 600,
};

const bodyTextStyle: React.CSSProperties = {
  color: 'var(--text-secondary)',
};

const tableStyle: React.CSSProperties = {
  width: '100%',
  borderCollapse: 'collapse',
  color: 'var(--text-secondary)',
  fontSize: '0.875rem',
};

const thStyle: React.CSSProperties = {
  border: '1px solid var(--border-default)',
  padding: '0.5rem 0.75rem',
  background: 'var(--surface-subtle)',
  color: 'var(--text-primary)',
  fontWeight: 600,
  textAlign: 'right',
};

const tdStyle: React.CSSProperties = {
  border: '1px solid var(--border-default)',
  padding: '0.5rem 0.75rem',
  textAlign: 'right',
  color: 'var(--text-secondary)',
};

interface SectionProps {
  title: string;
  children: React.ReactNode;
}

function Section({ title, children }: SectionProps) {
  return (
    <div className="mb-6">
      <h3 style={sectionHeadingStyle}>{title}</h3>
      {children}
    </div>
  );
}

export function NewProjectCharter({ data }: { data: NewProjectCharterData }) {
  return (
    <div dir="rtl">
      {/* 1. الغرض والمبرر */}
      <Section title="١. الغرض والمبرر">
        <p style={bodyTextStyle}>
          <strong style={{ color: 'var(--text-primary)' }}>{data.name}</strong>
        </p>
        {data.businessCase && (
          <p className="mt-2" style={bodyTextStyle}>{data.businessCase}</p>
        )}
      </Section>

      {/* 2. الأهداف ومعايير النجاح */}
      <Section title="٢. الأهداف ومعايير النجاح">
        {data.objectives && (
          <p style={bodyTextStyle}>{data.objectives}</p>
        )}
        {toList(data.successCriteria).length > 0 && (
          <div className="mt-2">
            <span style={{ color: 'var(--text-primary)', fontWeight: 600 }}>معايير النجاح: </span>
            <span style={bodyTextStyle}>{toList(data.successCriteria).join('، ')}</span>
          </div>
        )}
      </Section>

      {/* 3. المتطلبات عالية المستوى */}
      <Section title="٣. المتطلبات عالية المستوى">
        <ul style={{ paddingRight: '1.25rem', margin: 0 }}>
          {toList(data.highLevelRequirements).map((item, i) => (
            <li key={i} style={bodyTextStyle}>{item}</li>
          ))}
        </ul>
      </Section>

      {/* 4. الوصف والحدود (النطاق) */}
      <Section title="٤. الوصف والحدود (النطاق)">
        {data.description && (
          <p className="mb-3" style={bodyTextStyle}>{data.description}</p>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div
            style={{
              border: '1px solid var(--border-default)',
              borderRadius: '0.375rem',
              padding: '0.75rem',
            }}
          >
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.5rem' }}>مشمول</div>
            <p style={bodyTextStyle}>{data.scopeIncluded ?? ' - '}</p>
          </div>
          <div
            style={{
              border: '1px solid var(--border-default)',
              borderRadius: '0.375rem',
              padding: '0.75rem',
            }}
          >
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.5rem' }}>غير مشمول</div>
            <p style={bodyTextStyle}>{data.scopeExcluded ?? ' - '}</p>
          </div>
        </div>
      </Section>

      {/* 5. المخاطر عالية المستوى */}
      <Section title="٥. المخاطر عالية المستوى">
        <ul style={{ paddingRight: '1.25rem', margin: 0 }}>
          {toList(data.risks).map((item, i) => (
            <li key={i} style={bodyTextStyle}>{item}</li>
          ))}
        </ul>
      </Section>

      {/* 6. ملخص المعالم والجدول */}
      <Section title="٦. ملخص المعالم والجدول الزمني">
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>المعلم</th>
              <th style={thStyle}>المخرج</th>
              <th style={thStyle}>التاريخ</th>
            </tr>
          </thead>
          <tbody>
            {(data.milestones ?? []).length === 0 ? (
              <tr>
                <td colSpan={3} style={{ ...tdStyle, textAlign: 'center' }}>لا توجد معالم مسجّلة</td>
              </tr>
            ) : (
              (data.milestones ?? []).map((m, i) => (
                <tr key={i}>
                  <td style={tdStyle}>{m.name}</td>
                  <td style={tdStyle}>{m.deliverable}</td>
                  <td style={tdStyle}>{m.date}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </Section>

      {/* 7. ملخص الميزانية والموارد */}
      <Section title="٧. ملخص الميزانية والموارد">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>الميزانية</div>
            <p style={bodyTextStyle}>{data.budget ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>الموارد البشرية</div>
            <p style={bodyTextStyle}>{data.humanResources ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>الموارد التقنية</div>
            <p style={bodyTextStyle}>{data.technicalResources ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>الموارد المالية</div>
            <p style={bodyTextStyle}>{data.financialResources ?? ' - '}</p>
          </div>
        </div>
      </Section>

      {/* 8. أصحاب المصلحة */}
      <Section title="٨. أصحاب المصلحة">
        <p style={bodyTextStyle}>{data.stakeholders ?? ' - '}</p>
      </Section>

      {/* 9. متطلبات الموافقة ومعايير القبول */}
      <Section title="٩. متطلبات الموافقة ومعايير القبول">
        <p style={bodyTextStyle}>{data.approvalRequirements ?? ' - '}</p>
      </Section>

      {/* 10. معايير الإنهاء / الخروج */}
      <Section title="١٠. معايير الإنهاء / الخروج">
        <p style={bodyTextStyle}>{data.exitCriteria ?? ' - '}</p>
      </Section>

      {/* 11. الفريق والحوكمة */}
      <Section title="١١. الفريق والحوكمة">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>مدير المشروع</div>
            <p style={bodyTextStyle}>{data.managerName ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>المشرف (PMO)</div>
            <p style={bodyTextStyle}>{data.pmoSupervisor ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>فريق المشروع</div>
            <p style={bodyTextStyle}>{data.teamMembers ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>صلاحيات المدير</div>
            <p style={bodyTextStyle}>{toList(data.managerAuthority).join('، ') || ' - '}</p>
          </div>
        </div>
      </Section>

      {/* 12. الراعي المعتمِد */}
      <Section title="١٢. الراعي المعتمِد">
        <p>
          <strong style={{ color: 'var(--text-primary)' }}>{data.sponsorName ?? ' - '}</strong>
        </p>
        <p className="mt-1" style={{ fontSize: '0.813rem', color: 'var(--text-secondary)' }}>
          صاحب صلاحية اعتماد الميثاق
        </p>
      </Section>
    </div>
  );
}

export default NewProjectCharter;
