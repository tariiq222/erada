import { Button } from '@shared/ui';
import {IconPrinter} from '@tabler/icons-react';
import { NewProjectCharter } from './NewProjectCharter';
import { ImprovementCharter } from './ImprovementCharter';

export interface ProjectCharterProject {
  type: 'development' | 'improvement';
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
  // success_criteria / requirements / manager_authority / expected_benefits are
  // cast to arrays by the backend; accept array or string here.
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
  resources?: string;
  approvalRequirements?: string;
  exitCriteria?: string;
  managerName?: string;
  pmoSupervisor?: string;
  sponsorName?: string;
  processOwner?: string;
  targetProcess?: string;
  problemStatement?: string;
  currentStateDescription?: string;
  kpiName?: string;
  kpiBaseline?: string;
  kpiTarget?: string;
  kpiUnit?: string;
  rootCauses?: string;
  selectedSolution?: string;
  expectedBenefits?: string[] | string;
  pdcaPlan?: string;
  pdcaDo?: string;
  pdcaCheck?: string;
  pdcaAct?: string;
}

export function ProjectCharter({ project }: { project: ProjectCharterProject }) {
  return (
    <div dir="rtl">
      {/* Print styles injected */}
      <style dangerouslySetInnerHTML={{ __html: `
        @media print {
          .print-hide { display: none !important; }
          body > *:not(#charter-print-area) { display: none !important; }
          #charter-print-area { display: block !important; }
        }
      `}} />

      {/* Print button - hidden when printing */}
      <div className="print-hide flex justify-start mb-4">
        <Button onClick={() => window.print()} variant="outline">
          <IconPrinter className="w-4 h-4 ml-2" />
          طباعة الميثاق
        </Button>
      </div>

      {/* Charter area */}
      <div id="charter-print-area">
        {/* Header */}
        <div className="text-center mb-8 border-b pb-4" style={{ borderColor: 'var(--border-default)' }}>
          <div className="text-2xl font-bold mb-1" style={{ color: 'var(--text-primary)' }}>
            إ<span style={{ color: 'var(--accent-default)' }}>را</span>دة
          </div>
          <h2 className="text-xl font-semibold mb-2" style={{ color: 'var(--text-primary)' }}>
            {project.type === 'development' ? 'ميثاق مشروع' : 'ميثاق مشروع تحسيني'}
          </h2>
          <div className="text-sm flex justify-center gap-6" style={{ color: 'var(--text-secondary)' }}>
            {project.projectCode && <span>رقم المشروع: {project.projectCode}</span>}
            {project.startDate && <span>تاريخ البدء: {project.startDate}</span>}
            <span>{project.type === 'development' ? 'المعيار: PMI / PMBOK' : 'المنهجية: FOCUS-PDCA'}</span>
          </div>
        </div>

        {/* Charter body */}
        {project.type === 'development' ? (
          <NewProjectCharter data={project} />
        ) : (
          <ImprovementCharter data={project} />
        )}

        {/* Signatures */}
        <div className="mt-12 pt-6 border-t grid grid-cols-1 sm:grid-cols-2 gap-8" style={{ borderColor: 'var(--border-default)' }}>
          <div className="text-center">
            <div className="font-medium mb-8" style={{ color: 'var(--text-primary)' }}>
              {project.type === 'development' ? 'راعي المشروع' : 'الراعي'}
            </div>
            <div className="border-t pt-2" style={{ borderColor: 'var(--border-default)' }}>
              <span style={{ color: 'var(--text-secondary)' }}>{project.sponsorName ?? '_______________'}</span>
            </div>
          </div>
          <div className="text-center">
            <div className="font-medium mb-8" style={{ color: 'var(--text-primary)' }}>
              {project.type === 'development' ? 'مدير المشروع' : 'مالك العملية'}
            </div>
            <div className="border-t pt-2" style={{ borderColor: 'var(--border-default)' }}>
              <span style={{ color: 'var(--text-secondary)' }}>
                {project.type === 'development' ? (project.managerName ?? '_______________') : (project.processOwner ?? '_______________')}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

export default ProjectCharter;
