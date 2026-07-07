import React, { useState, useEffect, useCallback } from 'react';
import { projectsApi } from '@entities/project';
import {
  Card,
  CardContent,
  Button,
  Select,
  DatePicker,
  Skeleton,
  FilterButton,
  EmptyState,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconPlus, IconReceipt, IconLayoutGrid, IconList, IconX} from '@tabler/icons-react';
import {
  ExpenseStatsCards,
  ExpenseTableView,
  ExpenseCardsView,
  ExpenseFormModal,
  ExpenseDeleteModal,
  Expense,
  ExpenseStats,
  ExpenseFormData,
  EXPENSE_CATEGORIES,
  ProjectExpensesProps,
  Categories,
} from './expenses';

const ProjectExpenses: React.FC<ProjectExpensesProps & { canEdit?: boolean }> = ({ projectId, budget, tasks = [], canEdit = true }) => {
  const { showToast } = useToast();
  const [loading, setLoading] = useState(true);
  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [stats, setStats] = useState<ExpenseStats | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
  const [selectedExpense, setSelectedExpense] = useState<Expense | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');
  const [showFilters, setShowFilters] = useState(false);

  // فلاتر
  const [filterCategory, setFilterCategory] = useState('');
  const [filterFromDate, setFilterFromDate] = useState('');
  const [filterToDate, setFilterToDate] = useState('');

  const hasActiveFilters = filterCategory || filterFromDate || filterToDate;
  const activeFiltersCount = [filterCategory, filterFromDate, filterToDate].filter(Boolean).length;

  const loadExpenses = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, string> = {};
      if (filterCategory) params.category = filterCategory;
      if (filterFromDate) params.from_date = filterFromDate;
      if (filterToDate) params.to_date = filterToDate;

      const response = await projectsApi.getExpenses(projectId, params) as {
        expenses: Expense[];
        stats: ExpenseStats;
        categories: Categories;
      };
      setExpenses(response.expenses || []);
      setStats(response.stats || null);
    } catch (error: unknown) {
      const err = error as { message?: string };
      showToast('error', err.message || 'فشل تحميل المصروفات', 'خطأ');
    } finally {
      setLoading(false);
    }
  }, [projectId, filterCategory, filterFromDate, filterToDate, showToast]);

  useEffect(() => {
    loadExpenses();
  }, [loadExpenses]);

  const handleSubmit = async (formData: ExpenseFormData, attachmentFile: File | null) => {
    const data: {
      title: string;
      description?: string;
      amount: number;
      category: string;
      expense_date: string;
      task_id?: number;
      reference_number?: string;
      attachment?: File;
    } = {
      title: formData.title,
      description: formData.description || undefined,
      amount: parseFloat(formData.amount),
      category: formData.category,
      expense_date: formData.expense_date,
      task_id: formData.task_id ? parseInt(formData.task_id) : undefined,
      reference_number: formData.reference_number || undefined,
    };

    if (attachmentFile) {
      data.attachment = attachmentFile;
    }

    if (selectedExpense) {
      await projectsApi.updateExpense(projectId, selectedExpense.id, data);
      showToast('success', 'تم تحديث المصروف بنجاح', 'تم التحديث');
    } else {
      const response = await projectsApi.createExpense(projectId, data) as { warning?: string };
      if (response.warning) {
        showToast('warning', response.warning, 'تنبيه');
      } else {
        showToast('success', 'تم إضافة المصروف بنجاح', 'تمت الإضافة');
      }
    }

    setSelectedExpense(null);
    loadExpenses();
  };

  const handleDelete = async () => {
    if (!selectedExpense) return;
    setSubmitting(true);

    try {
      await projectsApi.deleteExpense(projectId, selectedExpense.id);
      showToast('success', 'تم حذف المصروف بنجاح', 'تم الحذف');
      setIsDeleteModalOpen(false);
      setSelectedExpense(null);
      loadExpenses();
    } catch (error: unknown) {
      const err = error as { message?: string };
      showToast('error', err.message || 'فشل حذف المصروف', 'خطأ');
    } finally {
      setSubmitting(false);
    }
  };

  const clearFilters = () => {
    setFilterCategory('');
    setFilterFromDate('');
    setFilterToDate('');
  };

  const openEditModal = (expense: Expense) => {
    setSelectedExpense(expense);
    setIsModalOpen(true);
  };

  const openDeleteModal = (expense: Expense) => {
    setSelectedExpense(expense);
    setIsDeleteModalOpen(true);
  };

  const categoryOptions = [
    { value: '', label: 'كل التصنيفات' },
    ...Object.entries(EXPENSE_CATEGORIES).map(([key, label]) => ({
      value: key,
      label: label,
    })),
  ];

  if (loading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-12 rounded-lg" />
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {[1, 2, 3, 4].map((i) => (
            <Skeleton key={i} className="h-20 rounded-xl" />
          ))}
        </div>
        <Skeleton className="h-64 rounded-xl" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <IconReceipt className="h-5 w-5 text-[var(--text-secondary)]" />
          <div>
            <h3 className="font-semibold text-[var(--text-primary)]">سجل المصروفات</h3>
            <p className="text-sm text-[var(--text-tertiary)]">{expenses.length} مصروف</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <FilterButton
            isOpen={showFilters}
            onClick={() => setShowFilters(!showFilters)}
            activeCount={activeFiltersCount}
          />

          <div className="flex items-center bg-[var(--surface-muted)] rounded-lg p-0">
            <button
              onClick={() => setViewMode('table')}
              className={`p-1 rounded-md transition-colors ${viewMode === 'table' ? 'bg-white shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
              title="عرض جدول"
            >
              <IconList className="h-4 w-4" />
            </button>
            <button
              onClick={() => setViewMode('cards')}
              className={`p-1 rounded-md transition-colors ${viewMode === 'cards' ? 'bg-white shadow-sm text-[var(--text-primary)]' : 'text-[var(--text-tertiary)] hover:text-[var(--text-secondary)]'}`}
              title="عرض كروت"
            >
              <IconLayoutGrid className="h-4 w-4" />
            </button>
          </div>

          {canEdit && (
            <Button
              variant="outline"
              size="sm"
              leftIcon={<IconPlus className="h-4 w-4" />}
              onClick={() => {
                setSelectedExpense(null);
                setIsModalOpen(true);
              }}
            >
              إضافة مصروف
            </Button>
          )}
        </div>
      </div>

      {/* Filters Panel */}
      {showFilters && (
        <Card>
          <CardContent className="p-4">
            <div className="flex flex-wrap items-end gap-4">
              <div className="flex-1 min-w-[150px]">
                <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">التصنيف</label>
                <Select
                  options={categoryOptions}
                  value={filterCategory}
                  onChange={(e) => setFilterCategory(e.target.value)}
                  placeholder="كل التصنيفات"
                />
              </div>

              <div className="flex-1 min-w-[140px]">
                <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">من تاريخ</label>
                <DatePicker
                  id="project-expenses-filter-from-date"
                  value={filterFromDate}
                  onChange={setFilterFromDate}
                  className="min-h-9"
                />
              </div>

              <div className="flex-1 min-w-[140px]">
                <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">إلى تاريخ</label>
                <DatePicker
                  id="project-expenses-filter-to-date"
                  value={filterToDate}
                  onChange={setFilterToDate}
                  className="min-h-9"
                />
              </div>

              {hasActiveFilters && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={clearFilters}
                  className="text-[var(--text-tertiary)] hover:text-[var(--status-danger)]"
                >
                  <IconX className="h-4 w-4 ml-1" />
                  مسح الفلاتر
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
      )}

      {/* بطاقات الإحصائيات */}
      <ExpenseStatsCards budget={budget} stats={stats} />

      {/* المحتوى */}
      {expenses.length === 0 ? (
        <Card>
          <EmptyState
            icon={IconReceipt}
            title="لا توجد مصروفات مسجلة"
            description="أضف المصروفات لتتبع ميزانية المشروع"
            size="lg"
            action={
              canEdit ? (
                <Button
                  leftIcon={<IconPlus className="h-4 w-4" />}
                  onClick={() => {
                    setSelectedExpense(null);
                    setIsModalOpen(true);
                  }}
                >
                  إضافة مصروف
                </Button>
              ) : undefined
            }
          />
        </Card>
      ) : viewMode === 'table' ? (
        <ExpenseTableView
          projectId={projectId}
          expenses={expenses}
          onEdit={openEditModal}
          onDelete={openDeleteModal}
        />
      ) : (
        <ExpenseCardsView
          projectId={projectId}
          expenses={expenses}
          onEdit={openEditModal}
          onDelete={openDeleteModal}
        />
      )}

      {/* مودال إضافة/تعديل مصروف */}
      <ExpenseFormModal
        isOpen={isModalOpen}
        onClose={() => {
          setIsModalOpen(false);
          setSelectedExpense(null);
        }}
        projectId={projectId}
        expense={selectedExpense}
        tasks={tasks}
        onSubmit={handleSubmit}
      />

      {/* مودال تأكيد الحذف */}
      <ExpenseDeleteModal
        isOpen={isDeleteModalOpen}
        onClose={() => {
          setIsDeleteModalOpen(false);
          setSelectedExpense(null);
        }}
        expense={selectedExpense}
        onConfirm={handleDelete}
        isDeleting={submitting}
      />
    </div>
  );
};

export default ProjectExpenses;
