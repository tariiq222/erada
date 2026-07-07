import { useState, useEffect, useCallback, useRef, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { departmentsApi } from '@entities/hr';
import { projectsApi } from '@entities/project';
import { programsApi } from '@entities/strategy';
import { usersApi } from '@entities/user';
import { useToast } from '@shared/ui/Toast';
import type {
  ProjectFormData,
  UserOption,
  DepartmentOption,
  ProgramOption,
  ValidationErrors,
  MilestoneItem,
  DeliverableItem,
  TaskItem,
  RiskItem,
  KpiInput,
  StakeholderItem,
} from './types';
import {
  emptyMilestone,
  emptyTask,
  emptyRisk,
  emptyKpi,
  emptyTeamMember,
  emptyStakeholder,
  emptyDeliverable,
} from './constants';

interface UseProjectFormOptions {
  id?: string;
  projectType?: string; // 'development' | 'improvement'
  // Answers captured by the triage modal, handed off via navigation state.
  // Submitted as triage_answers on create (backend accepts it).
  triageAnswers?: Record<string, string | null> | null;
}

export function useProjectForm({ id, projectType, triageAnswers }: UseProjectFormOptions) {
  const navigate = useNavigate();
  const { showToast } = useToast();

  const isEditMode = !!id;

  // Form State
  const [formData, setFormData] = useState<ProjectFormData>({
    name: '',
    description: '',
    objectives: [''],
    in_scope: [''],
    out_of_scope: [''],
    department_id: '',
    program_id: '',
    status: 'draft',
    priority: 'medium',
    start_date: '',
    end_date: '',
    budget: '',
    milestones: [{ ...emptyMilestone }],
    tasks: [{ ...emptyTask }],
    risks: [{ ...emptyRisk }],
    kpis: [{ ...emptyKpi }],
    team_members: [{ ...emptyTeamMember }],
    stakeholders: [{ ...emptyStakeholder }],
    human_resources: '',
    technical_resources: '',
    financial_resources: '',
    type: projectType || 'development',
    business_case: '',
    success_criteria: '',
    requirements: '',
    manager_authority: '',
    approval_criteria: '',
    exit_criteria: '',
    target_process: '',
    problem_statement: '',
    root_cause: '',
    expected_benefits: '',
    current_pdca_phase: '',
  });

  // Data State
  const [allDepartments, setAllDepartments] = useState<DepartmentOption[]>([]);
  const [programs, setPrograms] = useState<ProgramOption[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);

  // UI State
  const [isLoading, setIsLoading] = useState(isEditMode);
  const [isSaving, setIsSaving] = useState(false);
  const [errors, setErrors] = useState<ValidationErrors>({});

  // Assignable-manager state.
  // "I am the project manager" checkbox defaults to ON (creator becomes
  // manager — current behavior). When flipped OFF, a required picker
  // appears; the creator assigns another user as the project manager and
  // receives no manager role themselves.
  const [isSelfManager, setIsSelfManager] = useState<boolean>(true);
  const [assignedManagerId, setAssignedManagerId] = useState<string>('');
  const [assignableManagers, setAssignableManagers] = useState<
    Array<{ id: number; name: string; email: string; job_title: string | null; department_id: number | null }>
  >([]);
  const [isLoadingAssignableManagers, setIsLoadingAssignableManagers] = useState<boolean>(false);

  // Fetch Functions
  const fetchDepartments = useCallback(async () => {
    try {
      // On create, scope the picker to departments the user may actually create
      // in (own subtree, or any department for a governing-department member).
      // On edit, keep the full hierarchy so the project's existing department
      // stays selectable.
      const response = (isEditMode
        ? await departmentsApi.getHierarchy()
        : await projectsApi.getCreatableDepartments(projectType)) as { all: DepartmentOption[] };
      setAllDepartments(response.all || []);
    } catch {
      setAllDepartments([]);
    }
  }, [isEditMode, projectType]);

  const fetchPrograms = useCallback(async () => {
    try {
      const response = (await programsApi.getList()) as ProgramOption[];
      setPrograms(response || []);
    } catch {
      setPrograms([]);
    }
  }, []);

  const fetchUsers = useCallback(async () => {
    try {
      const response = (await usersApi.getList()) as UserOption[];
      setUsers(response);
    } catch (error) {
      console.warn('Failed to load users:', error);
    }
  }, []);

  // Fetch the list of users that the current user may assign as project
  // manager for a given project type. The list is small (department-scoped)
  // and stable enough to refetch on every filter change. Resets the picker
  // selection so a stale id from a previous type doesn't carry over.
  const fetchAssignableManagers = useCallback(
    async (type: string) => {
      setIsLoadingAssignableManagers(true);
      try {
        const list = (await projectsApi.getAssignableManagers(
          (type === 'improvement' ? 'improvement' : 'development') as 'development' | 'improvement'
        )) as Array<{ id: number; name: string; email: string; job_title: string | null; department_id: number | null }>;
        setAssignableManagers(list || []);
        setAssignedManagerId('');
      } catch (error) {
        console.warn('Failed to load assignable managers:', error);
        setAssignableManagers([]);
      } finally {
        setIsLoadingAssignableManagers(false);
      }
    },
    []
  );

  const fetchProject = useCallback(async () => {
    if (!id) return;
    try {
      const response = (await projectsApi.getOne(Number(id))) as any;

      const transformedRisks = response.risks?.length
        ? response.risks.map((r: any) => ({
            id: r.id,
            description: r.risk || r.description || '',
            probability: r.probability || 'medium',
            impact: r.impact || 'medium',
            mitigation: r.response || r.mitigation || '',
            status: r.status || 'open',
          }))
        : [{ ...emptyRisk }];

      // عكس ترميز طور PDCA (الباك: plan/do/check/act → الواجهة: P/D/C/A)
      const pdcaReverse: Record<string, string> = { plan: 'P', do: 'D', check: 'C', act: 'A' };

      // The backend casts success_criteria / requirements / manager_authority /
      // expected_benefits to arrays. The form edits them as newline-joined text
      // and splits them back to arrays on submit. Normalize either shape here.
      const arrayToText = (value: unknown): string => {
        if (Array.isArray(value)) return value.join('\n');
        return typeof value === 'string' ? value : '';
      };

      const transformedKpis = response.kpis?.length
        ? response.kpis.map((k: any) => ({
            id: k.id,
            name: k.name || '',
            target: k.target?.toString() ?? '',
            baseline: k.baseline?.toString() ?? '',
            unit: k.unit || '',
            measurement_method: k.measurement_method || '',
          }))
        : [{ ...emptyKpi }];

      setFormData({
        name: response.name || '',
        description: response.description || '',
        objectives: response.objectives?.length ? response.objectives : [''],
        in_scope: response.in_scope?.length ? response.in_scope : [''],
        out_of_scope: response.out_of_scope?.length ? response.out_of_scope : [''],
        department_id: response.department_id?.toString() || '',
        program_id: response.program_id?.toString() || '',
        status: response.status || 'draft',
        priority: response.priority || 'medium',
        start_date: response.start_date || '',
        end_date: response.end_date || '',
        budget: response.budget?.toString() || '',
        milestones: response.milestones?.length ? response.milestones : [{ ...emptyMilestone }],
        tasks: response.tasks?.length
          ? response.tasks.map((t: any) => ({
              id: t.id,
              name: t.name || t.title || '',
              description: t.description || '',
              milestone_index: t.milestone_index,
              milestone_id: t.milestone_id,
              assigned_to: t.assigned_to,
              assigned_to_name: t.assigned_to_name || t.assignee?.name || '',
              priority: t.priority || 'medium',
              start_date: t.start_date || '',
              due_date: t.due_date || '',
            }))
          : [{ ...emptyTask }],
        risks: transformedRisks,
        kpis: transformedKpis,
        team_members: response.members?.length
          ? response.members.map((m: any) => ({
              user_id: m.id,
              name: m.name,
              role: m.pivot?.role || '',
            }))
          : [{ ...emptyTeamMember }],
        stakeholders: response.stakeholders?.length
          ? response.stakeholders
          : [{ ...emptyStakeholder }],
        human_resources: response.human_resources || '',
        technical_resources: response.technical_resources || '',
        financial_resources: response.financial_resources || '',
        type: response.type || 'development',
        business_case: response.business_case || '',
        success_criteria: arrayToText(response.success_criteria),
        requirements: arrayToText(response.requirements),
        manager_authority: arrayToText(response.manager_authority),
        approval_criteria: response.approval_criteria || '',
        exit_criteria: response.exit_criteria || '',
        target_process: response.target_process || '',
        problem_statement: response.problem_statement || '',
        root_cause: response.root_cause || '',
        expected_benefits: arrayToText(response.expected_benefits),
        current_pdca_phase: pdcaReverse[response.current_pdca_phase] || '',
      });
    } catch {
      showToast('error', 'فشل في تحميل بيانات المشروع');
      navigate('/projects');
    } finally {
      setIsLoading(false);
    }
  }, [id, navigate, showToast]);

  // Initialize
  useEffect(() => {
    fetchDepartments();
    fetchPrograms();
    fetchUsers();
    if (isEditMode) {
      fetchProject();
    }
  }, [id, isEditMode, fetchDepartments, fetchPrograms, fetchUsers, fetchProject]);

  // Lazy fetch of assignable managers: only when the picker is actually
  // needed (isSelfManager === false). Re-runs when the project type
  // changes so the list reflects the new type's eligibility rules.
  // Skipped in edit mode: the manager assignment on existing projects is
  // handled by the team-members endpoint, not the create payload.
  useEffect(() => {
    if (isEditMode || isSelfManager) return;
    fetchAssignableManagers(formData.type || 'development');
  }, [isEditMode, isSelfManager, formData.type, fetchAssignableManagers]);

  // Change Handlers
  const handleChange = useCallback((field: keyof ProjectFormData, value: any) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => {
      if (prev[field]) {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      }
      return prev;
    });
  }, []);

  const handleArrayItemChange = useCallback(
    (field: 'objectives' | 'in_scope' | 'out_of_scope', index: number, value: string) => {
      setFormData((prev) => {
        const newArray = [...prev[field]];
        newArray[index] = value;
        return { ...prev, [field]: newArray };
      });
    },
    []
  );

  const addArrayItem = useCallback((field: 'objectives' | 'in_scope' | 'out_of_scope') => {
    setFormData((prev) => ({ ...prev, [field]: [...prev[field], ''] }));
  }, []);

  const removeArrayItem = useCallback(
    (field: 'objectives' | 'in_scope' | 'out_of_scope', index: number) => {
      setFormData((prev) => {
        if (prev[field].length > 1) {
          return { ...prev, [field]: prev[field].filter((_, i) => i !== index) };
        }
        return prev;
      });
    },
    []
  );

  // Milestone Handlers
  const handleMilestoneChange = useCallback(
    (index: number, field: keyof Omit<MilestoneItem, 'deliverables'>, value: string) => {
      setFormData((prev) => {
        const newMilestones = [...prev.milestones];
        newMilestones[index] = { ...newMilestones[index], [field]: value };
        return { ...prev, milestones: newMilestones };
      });
    },
    []
  );

  const addMilestone = useCallback(() => {
    setFormData((prev) => ({
      ...prev,
      milestones: [...prev.milestones, { ...emptyMilestone, deliverables: [{ ...emptyDeliverable }] }],
    }));
  }, []);

  // Generate a set of milestones from template names, spreading their dates
  // evenly across the project span. No-op until both project dates are set.
  const suggestMilestones = useCallback((names: string[]) => {
    setFormData((prev) => {
      if (!prev.start_date || !prev.end_date || names.length === 0) return prev;
      const start = new Date(prev.start_date);
      const end = new Date(prev.end_date);
      const totalDays = Math.max(names.length, Math.round((end.getTime() - start.getTime()) / 86_400_000));
      const at = (offsetDays: number) => {
        const d = new Date(start);
        d.setDate(d.getDate() + offsetDays);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      };
      const milestones: MilestoneItem[] = names.map((name, i) => ({
        name,
        description: '',
        start_date: at(Math.round((totalDays * i) / names.length)),
        due_date: at(Math.round((totalDays * (i + 1)) / names.length)),
        deliverables: [{ ...emptyDeliverable }],
      }));
      return { ...prev, milestones };
    });
  }, []);

  const removeMilestone = useCallback((index: number) => {
    setFormData((prev) => {
      if (prev.milestones.length > 1) {
        return { ...prev, milestones: prev.milestones.filter((_, i) => i !== index) };
      }
      return prev;
    });
  }, []);

  // Deliverable Handlers
  const handleDeliverableChange = useCallback(
    (milestoneIndex: number, deliverableIndex: number, field: keyof DeliverableItem, value: string) => {
      setFormData((prev) => {
        const newMilestones = [...prev.milestones];
        const newDeliverables = [...newMilestones[milestoneIndex].deliverables];
        newDeliverables[deliverableIndex] = { ...newDeliverables[deliverableIndex], [field]: value };
        newMilestones[milestoneIndex] = { ...newMilestones[milestoneIndex], deliverables: newDeliverables };
        return { ...prev, milestones: newMilestones };
      });
    },
    []
  );

  const addDeliverable = useCallback((milestoneIndex: number) => {
    setFormData((prev) => {
      const newMilestones = [...prev.milestones];
      newMilestones[milestoneIndex] = {
        ...newMilestones[milestoneIndex],
        deliverables: [...newMilestones[milestoneIndex].deliverables, { ...emptyDeliverable }],
      };
      return { ...prev, milestones: newMilestones };
    });
  }, []);

  const removeDeliverable = useCallback((milestoneIndex: number, deliverableIndex: number) => {
    setFormData((prev) => {
      const newMilestones = [...prev.milestones];
      if (newMilestones[milestoneIndex].deliverables.length > 1) {
        newMilestones[milestoneIndex] = {
          ...newMilestones[milestoneIndex],
          deliverables: newMilestones[milestoneIndex].deliverables.filter((_, i) => i !== deliverableIndex),
        };
        return { ...prev, milestones: newMilestones };
      }
      return prev;
    });
  }, []);

  // Risk Handlers
  const handleRiskChange = useCallback((index: number, field: keyof RiskItem, value: string) => {
    setFormData((prev) => {
      const newRisks = [...prev.risks];
      newRisks[index] = { ...newRisks[index], [field]: value };
      return { ...prev, risks: newRisks };
    });
  }, []);

  const addRisk = useCallback(() => {
    setFormData((prev) => ({ ...prev, risks: [...prev.risks, { ...emptyRisk }] }));
  }, []);

  const removeRisk = useCallback((index: number) => {
    setFormData((prev) => {
      if (prev.risks.length > 1) {
        return { ...prev, risks: prev.risks.filter((_, i) => i !== index) };
      }
      return prev;
    });
  }, []);

  // KPI Handlers (نظام Performance)
  const handleKpiChange = useCallback((index: number, field: keyof KpiInput, value: string) => {
    setFormData((prev) => {
      const newKpis = [...prev.kpis];
      newKpis[index] = { ...newKpis[index], [field]: value };
      return { ...prev, kpis: newKpis };
    });
  }, []);

  const addKpi = useCallback(() => {
    setFormData((prev) => ({ ...prev, kpis: [...prev.kpis, { ...emptyKpi }] }));
  }, []);

  const removeKpi = useCallback((index: number) => {
    setFormData((prev) => {
      if (prev.kpis.length > 1) {
        return { ...prev, kpis: prev.kpis.filter((_, i) => i !== index) };
      }
      return prev;
    });
  }, []);

  // Task Handlers
  const handleTaskChange = useCallback(
    (index: number, field: keyof TaskItem, value: string | number | undefined) => {
      setFormData((prev) => {
        const newTasks = [...prev.tasks];
        newTasks[index] = { ...newTasks[index], [field]: value };
        return { ...prev, tasks: newTasks };
      });
    },
    []
  );

  const addTask = useCallback(() => {
    setFormData((prev) => ({ ...prev, tasks: [...prev.tasks, { ...emptyTask }] }));
  }, []);

  const removeTask = useCallback((index: number) => {
    setFormData((prev) => {
      if (prev.tasks.length > 1) {
        return { ...prev, tasks: prev.tasks.filter((_, i) => i !== index) };
      }
      return prev;
    });
  }, []);

  // Team Member Handlers
  const addTeamMember = useCallback(() => {
    setFormData((prev) => ({
      ...prev,
      team_members: [...prev.team_members, { ...emptyTeamMember }],
    }));
  }, []);

  const removeTeamMember = useCallback((index: number) => {
    setFormData((prev) => {
      if (prev.team_members.length > 1) {
        return { ...prev, team_members: prev.team_members.filter((_, i) => i !== index) };
      }
      return prev;
    });
  }, []);

  const updateTeamMember = useCallback(
    (index: number, userId: number, userName: string) => {
      setFormData((prev) => {
        const newTeamMembers = [...prev.team_members];
        newTeamMembers[index] = {
          ...newTeamMembers[index],
          user_id: userId,
          name: userName,
          role: 'member',
        };
        return { ...prev, team_members: newTeamMembers };
      });
      fetchUsers();
    },
    [fetchUsers]
  );

  // Stakeholder Handlers
  const handleStakeholderChange = useCallback(
    (index: number, field: keyof StakeholderItem, value: string) => {
      setFormData((prev) => {
        const newStakeholders = [...prev.stakeholders];
        newStakeholders[index] = { ...newStakeholders[index], [field]: value };
        return { ...prev, stakeholders: newStakeholders };
      });
    },
    []
  );

  const addStakeholder = useCallback(() => {
    setFormData((prev) => ({
      ...prev,
      stakeholders: [...prev.stakeholders, { ...emptyStakeholder }],
    }));
  }, []);

  const removeStakeholder = useCallback((index: number) => {
    setFormData((prev) => {
      if (prev.stakeholders.length > 1) {
        return { ...prev, stakeholders: prev.stakeholders.filter((_, i) => i !== index) };
      }
      return prev;
    });
  }, []);

  const updateStakeholder = useCallback(
    (index: number, userId: number, userName: string) => {
      setFormData((prev) => {
        const newStakeholders = [...prev.stakeholders];
        newStakeholders[index] = {
          ...newStakeholders[index],
          user_id: userId,
          name: userName,
        };
        return { ...prev, stakeholders: newStakeholders };
      });
      fetchUsers();
    },
    [fetchUsers]
  );

  // Task Assignee Update
  const updateTaskAssignee = useCallback(
    (index: number, userId: number, userName: string) => {
      setFormData((prev) => {
        const newTasks = [...prev.tasks];
        newTasks[index] = {
          ...newTasks[index],
          assigned_to: userId,
          assigned_to_name: userName,
        };
        return { ...prev, tasks: newTasks };
      });
      fetchUsers();
    },
    [fetchUsers]
  );

  // Move keyboard focus to the first field flagged with a validation error,
  // scrolling it into view (the sticky action bar would otherwise hide it).
  const focusFirstError = useCallback(() => {
    if (typeof window === 'undefined') return;
    window.setTimeout(() => {
      // Guard against late firings during the Vitest teardown phase so an
      // unmount-during-form-validation race does not throw a hard
      // `document is not defined` reference into the test reporter.
      if (typeof document === 'undefined') return;
      const el = document.querySelector<HTMLElement>('form [aria-invalid="true"]');
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.focus({ preventScroll: true });
      } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    }, 80);
  }, []);

  // Local autosave: the in-progress create form is mirrored to localStorage so
  // the user doesn't lose work on a refresh/close. Edit mode loads from the
  // server, so it has no local draft.
  const draftStorageKey = isEditMode ? null : `erada:project-draft:${projectType || 'development'}`;
  const clearLocalDraft = useCallback(() => {
    if (!draftStorageKey || typeof window === 'undefined') return;
    try {
      window.localStorage.removeItem(draftStorageKey);
    } catch {
      /* ignore quota/availability errors */
    }
  }, [draftStorageKey]);

  // Submit. `asDraft` persists a partial project (status=draft) so the user can
  // finish it later; only the name is required and the charter checks are skipped.
  const handleSubmit = useCallback(
    async (e?: FormEvent, asDraft = false) => {
      e?.preventDefault();
      setErrors({});
      setIsSaving(true);

      // Basic frontend validation
      const frontendErrors: ValidationErrors = {};
      if (!formData.name.trim()) {
        frontendErrors.name = ['اسم المشروع مطلوب'];
      }
      // Drafts only need a name. The full charter checks (priority, manager,
      // KPIs) run on a normal create/update; the backend relaxes them too.
      if (!asDraft) {
        if (!formData.priority) {
          frontendErrors.priority = ['أولوية المشروع مطلوبة'];
        }
        // Assignable-manager picker: when the creator opts out of being the
        // manager, a manager selection is required.
        if (!isSelfManager && !assignedManagerId) {
          frontendErrors.manager_user_id = ['يرجى اختيار مدير للمشروع'];
        }
        // Improvement projects (FOCUS-PDCA) require at least one named KPI.
        // Friendly client guard; the backend enforces this too.
        const isImprovementProject = (formData.type || 'development') === 'improvement';
        if (isImprovementProject && !formData.kpis.some((k) => k.name.trim() !== '')) {
          frontendErrors.kpis = ['يجب إضافة مؤشر أداء واحد على الأقل للمشروع التحسيني'];
        }
      }
      if (Object.keys(frontendErrors).length > 0) {
        setErrors(frontendErrors);
        setIsSaving(false);
        showToast('error', 'يرجى تصحيح الأخطاء أدناه');
        focusFirstError();
        return;
      }

      try {
        // Improvement projects follow FOCUS-PDCA, not PMBOK — drop scope/risks.
        const isImprovement = (formData.type || 'development') === 'improvement';

        const submitData: any = {
          name: formData.name,
          description: formData.description || null,
          objectives: formData.objectives.filter((o) => o.trim() !== ''),
          ...(isImprovement
            ? {}
            : {
                in_scope: formData.in_scope.filter((s) => s.trim() !== ''),
                out_of_scope: formData.out_of_scope.filter((s) => s.trim() !== ''),
              }),
          department_id: formData.department_id ? Number(formData.department_id) : null,
          program_id: formData.program_id ? Number(formData.program_id) : null,
          // Assignable-manager override: only sent when the creator opted
          // out of being the manager. When checked, omit it so the backend
          // keeps the current behavior (creator becomes the manager). The
          // legacy manager_id / sponsor_id columns were dropped — the engine
          // resolves the manager via manager_user_id (scoped role) only.
          ...(isSelfManager
            ? {}
            : {
                manager_user_id: assignedManagerId ? Number(assignedManagerId) : null,
              }),
          status: formData.status,
          priority: formData.priority,
          start_date: formData.start_date || null,
          end_date: formData.end_date || null,
          budget: formData.budget ? Number(formData.budget) : null,
          milestones: formData.milestones
            .filter((m) => m.name.trim() !== '' && m.start_date && m.due_date)
            .map((m: any) => ({
              ...(m.id ? { id: m.id } : {}),
              name: m.name,
              description: m.description || null,
              start_date: m.start_date || null,
              due_date: m.due_date || null,
              deliverables: m.deliverables
                .filter((d: any) => d.name.trim() !== '')
                .map((d: any) => ({ ...(d.id ? { id: d.id } : {}), name: d.name })),
            })),
          tasks: formData.tasks
            .filter((t) => t.name.trim() !== '')
            .map((t: any) => ({
              ...(t.id ? { id: t.id } : {}),
              name: t.name,
              title: t.name,
              description: t.description || null,
              milestone_index: t.milestone_index ?? null,
              ...(t.milestone_id ? { milestone_id: t.milestone_id } : {}),
              assigned_to: t.assigned_to ?? null,
              priority: t.priority,
              start_date: t.start_date || null,
              due_date: t.due_date || null,
            })),
          ...(isImprovement
            ? {}
            : {
                risks: formData.risks
                  .filter((r) => r.description.trim() !== '')
                  .map((r: any) => ({
                    ...(r.id ? { id: r.id } : {}),
                    description: r.description,
                    probability: r.probability,
                    impact: r.impact,
                    mitigation: r.mitigation || null,
                    ...(r.status ? { status: r.status } : {}),
                  })),
              }),
          team_members: formData.team_members
            .filter((m) => m.user_id)
            .map((m) => ({
              user_id: Number(m.user_id),
              role: m.role || null,
            })),
          stakeholders: formData.stakeholders
            .filter((s) => s.name.trim() !== '')
            .map((s) => ({
              user_id: s.user_id ?? null,
              name: s.name,
              role: s.role || null,
              contact: s.contact || null,
              influence: s.influence || null,
            })),
          human_resources: formData.human_resources || null,
          technical_resources: formData.technical_resources || null,
          financial_resources: formData.financial_resources || null,
          type: formData.type || 'development',
          // Persist the triage answers captured before creation (create only).
          ...(!isEditMode && triageAnswers ? { triage_answers: triageAnswers } : {}),
          business_case: formData.business_case || null,
          success_criteria: (formData.type || 'development') === 'development'
            ? (Array.isArray(formData.success_criteria)
                ? formData.success_criteria
                : (formData.success_criteria || '').split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== ''))
            : (formData.type || 'development') === 'improvement'
              ? (formData.success_criteria || '').split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== '')
              : formData.success_criteria || null,
          requirements: (formData.type || 'development') === 'development'
            ? (Array.isArray(formData.requirements)
                ? formData.requirements
                : (formData.requirements || '').split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== ''))
            : formData.requirements || null,
          manager_authority: (formData.type || 'development') === 'development'
            ? (Array.isArray(formData.manager_authority)
                ? formData.manager_authority
                : (formData.manager_authority || '').split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== ''))
            : formData.manager_authority || null,
          approval_criteria: formData.approval_criteria || null,
          exit_criteria: formData.exit_criteria || null,
          target_process: formData.target_process || null,
          ...((formData.type || 'development') === 'improvement'
            ? {
                problem_statement: (() => {
                  if (Array.isArray(formData.problem_statement)) {
                    return formData.problem_statement[0] || null;
                  }
                  return formData.problem_statement || null;
                })(),
              }
            : {}),
          root_cause: formData.root_cause || null,
          expected_benefits: (formData.type || 'development') === 'improvement'
            ? (formData.expected_benefits || '').split(/\r?\n/).map((s) => s.trim()).filter((s) => s !== '')
            : formData.expected_benefits || null,
          current_pdca_phase: (formData.type || 'development') === 'improvement'
            ? ({ P: 'plan', D: 'do', C: 'check', A: 'act' }[formData.current_pdca_phase] || null)
            : formData.current_pdca_phase || null,
          // KPIs ship only for improvement projects (FOCUS-PDCA), on both
          // create and edit. New-type projects never carry a kpis payload.
          ...(isImprovement
            ? {
                kpis: formData.kpis
                  .filter((k) => k.name.trim() !== '')
                  .map((k: any) => ({
                    ...(k.id ? { id: k.id } : {}),
                    name: k.name,
                    target: k.target !== '' ? Number(k.target) : null,
                    baseline: k.baseline !== '' ? Number(k.baseline) : null,
                    unit: k.unit || null,
                    measurement_method: k.measurement_method || null,
                  })),
              }
            : {}),
        };

        // A draft is stored incomplete with status 'draft' so it surfaces as a
        // draft in the projects list. The flag tells the backend to relax the
        // required charter fields; it is not a project column.
        if (asDraft) {
          submitData.save_as_draft = true;
          submitData.status = 'draft';
        }

        if (isEditMode) {
          await projectsApi.update(Number(id), submitData);
          showToast('success', 'تم تحديث المشروع بنجاح');
        } else {
          await projectsApi.create(submitData);
          showToast('success', asDraft ? 'تم حفظ المسودة' : 'تم إنشاء المشروع بنجاح');
        }
        clearLocalDraft();
        navigate('/projects');
      } catch (error: any) {
        // Always log to console for debugging
        console.error('Project submit error:', error);

        // Handle permission errors with clear Arabic message
        if (error?.status === 403) {
          showToast('error', 'ليس لديك صلاحية تنفيذ هذا الإجراء');
        } else if (error.errors && Object.keys(error.errors).length > 0) {
          // Backend validation errors
          setErrors(error.errors);
          showToast('error', 'يرجى تصحيح الأخطاء أدناه');

          focusFirstError();
        } else {
          // API or network error
          const errorMessage = error?.message || error?.toString?.() || 'حدث خطأ غير متوقع أثناء الحفظ';
          showToast('error', errorMessage);
        }
      } finally {
        setIsSaving(false);
      }
    },
    [formData, id, isEditMode, navigate, showToast, focusFirstError, isSelfManager, assignedManagerId, triageAnswers, clearLocalDraft]
  );

  // Manual "save as draft" — same path as submit, with the draft flag set.
  const handleSaveDraft = useCallback(() => handleSubmit(undefined, true), [handleSubmit]);

  // Local autosave status, surfaced in the form's autosave strip.
  const [draftSaveStatus, setDraftSaveStatus] = useState<'idle' | 'saving' | 'saved' | 'restored' | 'error'>('idle');
  const [draftSavedAt, setDraftSavedAt] = useState<string | null>(null);

  // Restore a previously autosaved draft once, on mount of the create form.
  const restoredRef = useRef(false);
  useEffect(() => {
    if (restoredRef.current || !draftStorageKey || typeof window === 'undefined') return;
    restoredRef.current = true;
    try {
      const raw = window.localStorage.getItem(draftStorageKey);
      if (!raw) return;
      const saved = JSON.parse(raw) as {
        formData?: Partial<ProjectFormData>;
        isSelfManager?: boolean;
        assignedManagerId?: string;
      };
      if (saved.formData?.name?.trim()) {
        setFormData((prev) => ({ ...prev, ...saved.formData }));
        if (typeof saved.isSelfManager === 'boolean') setIsSelfManager(saved.isSelfManager);
        if (saved.assignedManagerId !== undefined) setAssignedManagerId(saved.assignedManagerId);
        setDraftSaveStatus('restored');
      }
    } catch {
      /* corrupt draft — ignore */
    }
  }, [draftStorageKey]);

  // Debounced autosave: mirror the form to localStorage whenever it changes and
  // the project has a name. Skips the very first run (the initial/restored state).
  const autosaveSkipRef = useRef(true);
  useEffect(() => {
    if (!draftStorageKey || typeof window === 'undefined') return;
    if (autosaveSkipRef.current) {
      autosaveSkipRef.current = false;
      return;
    }
    if (!formData.name.trim()) return;
    setDraftSaveStatus('saving');
    const handle = window.setTimeout(() => {
      try {
        window.localStorage.setItem(
          draftStorageKey,
          JSON.stringify({ formData, isSelfManager, assignedManagerId })
        );
        setDraftSaveStatus('saved');
        setDraftSavedAt(new Date().toLocaleTimeString('ar', { hour: '2-digit', minute: '2-digit' }));
      } catch {
        setDraftSaveStatus('error');
      }
    }, 800);
    return () => window.clearTimeout(handle);
  }, [formData, isSelfManager, assignedManagerId, draftStorageKey]);

  return {
    // State
    formData,
    allDepartments,
    programs,
    users,
    isLoading,
    isSaving,
    draftSaveStatus,
    draftSavedAt,
    errors,
    isEditMode,

    // Assignable-manager state
    isSelfManager,
    setIsSelfManager,
    assignedManagerId,
    setAssignedManagerId,
    assignableManagers,
    isLoadingAssignableManagers,
    fetchAssignableManagers,

    // Handlers
    handleChange,
    handleArrayItemChange,
    addArrayItem,
    removeArrayItem,
    handleMilestoneChange,
    addMilestone,
    removeMilestone,
    suggestMilestones,
    handleDeliverableChange,
    addDeliverable,
    removeDeliverable,
    handleRiskChange,
    addRisk,
    removeRisk,
    handleKpiChange,
    addKpi,
    removeKpi,
    handleTaskChange,
    addTask,
    removeTask,
    updateTaskAssignee,
    addTeamMember,
    removeTeamMember,
    updateTeamMember,
    handleStakeholderChange,
    addStakeholder,
    removeStakeholder,
    updateStakeholder,

    // Submit
    handleSubmit,
    handleSaveDraft,

    // Refresh
    fetchUsers,
  };
}
