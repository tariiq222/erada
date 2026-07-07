import React from 'react';

export interface ImprovementCharterData {
  name: string;
  projectCode?: string;
  department?: string;
  processOwner?: string;
  startDate?: string;
  businessCase?: string;
  targetProcess?: string;
  problemStatement?: string;
  sponsorName?: string;
  teamMembers?: string;
  currentStateDescription?: string;
  kpiName?: string;
  kpiBaseline?: string;
  kpiTarget?: string;
  kpiUnit?: string;
  rootCauses?: string;
  selectedSolution?: string;
  // Cast to an array by the backend; accept array or string.
  expectedBenefits?: string[] | string;
  scopeIncluded?: string;
  scopeExcluded?: string;
  pdcaPlan?: string;
  pdcaDo?: string;
  pdcaCheck?: string;
  pdcaAct?: string;
}

// Accepts either an array (backend casts expected_benefits to an array) or a
// newline/comma-separated string.
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
  verticalAlign: 'top',
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

export function ImprovementCharter({ data }: { data: ImprovementCharterData }) {
  return (
    <div dir="rtl">
      {/* 1. اسم المشروع */}
      <Section title="١. اسم المشروع">
        <p>
          <strong style={{ color: 'var(--text-primary)' }}>{data.name}</strong>
        </p>
      </Section>

      {/* 2. المبرر */}
      <Section title="٢. المبرر">
        <p style={bodyTextStyle}>{data.businessCase ?? ' - '}</p>
      </Section>

      {/* 3. العملية المستهدفة وبيان المشكلة (F) */}
      <Section title="٣. العملية المستهدفة وبيان المشكلة (F – Find)">
        {data.targetProcess && (
          <p style={bodyTextStyle}>{data.targetProcess}</p>
        )}
        {data.problemStatement && (
          <p className="mt-2" style={bodyTextStyle}>{data.problemStatement}</p>
        )}
        {!data.targetProcess && !data.problemStatement && (
          <p style={bodyTextStyle}> - </p>
        )}
      </Section>

      {/* 4. الوضع الحالي والمؤشر (C / S) */}
      <Section title="٤. الوضع الحالي والمؤشر (C – Clarify / S – Select KPI)">
        {data.currentStateDescription && (
          <p className="mb-3" style={bodyTextStyle}>{data.currentStateDescription}</p>
        )}
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>المؤشر</th>
              <th style={thStyle}>الخط الأساسي</th>
              <th style={thStyle}>المستهدف</th>
              <th style={thStyle}>الوحدة</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style={tdStyle}>{data.kpiName ?? ' - '}</td>
              <td style={tdStyle}>{data.kpiBaseline ?? ' - '}</td>
              <td style={tdStyle}>{data.kpiTarget ?? ' - '}</td>
              <td style={tdStyle}>{data.kpiUnit ?? ' - '}</td>
            </tr>
          </tbody>
        </table>
      </Section>

      {/* 5. الأسباب الجذرية (U) */}
      <Section title="٥. الأسباب الجذرية (U – Understand)">
        <ul style={{ paddingRight: '1.25rem', margin: 0 }}>
          {toList(data.rootCauses).length > 0 ? (
            toList(data.rootCauses).map((item, i) => (
              <li key={i} style={bodyTextStyle}>{item}</li>
            ))
          ) : (
            <li style={bodyTextStyle}> - </li>
          )}
        </ul>
      </Section>

      {/* 6. التحسين المختار والفوائد المتوقعة (S) */}
      <Section title="٦. التحسين المختار والفوائد المتوقعة (S – Select Solution)">
        {data.selectedSolution && (
          <p style={bodyTextStyle}>{data.selectedSolution}</p>
        )}
        {toList(data.expectedBenefits).length > 0 && (
          <div className="mt-2">
            <span style={{ color: 'var(--text-primary)', fontWeight: 600 }}>الفوائد المتوقعة: </span>
            <span style={bodyTextStyle}>{toList(data.expectedBenefits).join('، ')}</span>
          </div>
        )}
        {!data.selectedSolution && toList(data.expectedBenefits).length === 0 && (
          <p style={bodyTextStyle}> - </p>
        )}
      </Section>

      {/* 7. الفريق والحوكمة (O) */}
      {/* Scope section removed per FOCUS-PDCA design. */}
      <Section title="٧. الفريق والحوكمة (O – Organize Team)">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>الراعي</div>
            <p style={bodyTextStyle}>{data.sponsorName ?? ' - '}</p>
          </div>
          <div>
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>مالك العملية</div>
            <p style={bodyTextStyle}>{data.processOwner ?? ' - '}</p>
          </div>
          <div className="col-span-2">
            <div style={{ color: 'var(--text-primary)', fontWeight: 600, marginBottom: '0.25rem' }}>الفريق</div>
            <p style={bodyTextStyle}>{data.teamMembers ?? ' - '}</p>
          </div>
        </div>
      </Section>

      {/* 8. خطة المراحل والمتابعة (PDCA) */}
      <Section title="٨. خطة المراحل والمتابعة (PDCA)">
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>خطط (Plan)</th>
              <th style={thStyle}>نفّذ (Do)</th>
              <th style={thStyle}>تحقق (Check)</th>
              <th style={thStyle}>تصرّف (Act)</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style={tdStyle}>{data.pdcaPlan ?? ' - '}</td>
              <td style={tdStyle}>{data.pdcaDo ?? ' - '}</td>
              <td style={tdStyle}>{data.pdcaCheck ?? ' - '}</td>
              <td style={tdStyle}>{data.pdcaAct ?? ' - '}</td>
            </tr>
          </tbody>
        </table>
      </Section>
    </div>
  );
}

export default ImprovementCharter;
