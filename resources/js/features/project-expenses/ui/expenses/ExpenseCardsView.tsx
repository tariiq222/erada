import React from 'react';
import { Card, CardContent, Badge } from '@shared/ui';
import {IconTrash, IconEdit, IconCalendar, IconPaperclip} from '@tabler/icons-react';
import { formatDate } from '@shared/lib/utils';
import { Expense, categoryColors, EXPENSE_CATEGORIES, formatCurrency } from './types';

interface ExpenseCardsViewProps {
  projectId: number;
  expenses: Expense[];
  onEdit: (expense: Expense) => void;
  onDelete: (expense: Expense) => void;
}

const ExpenseCardsView: React.FC<ExpenseCardsViewProps> = ({
  projectId,
  expenses,
  onEdit,
  onDelete,
}) => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
      {expenses.map((expense) => (
        <Card key={expense.id} className="hover:shadow-md transition-shadow">
          <CardContent className="p-4">
            <div className="flex items-start justify-between mb-3">
              <div className="flex-1">
                <div className="flex items-center gap-1 mb-1">
                  <h4 className="font-semibold text-[var(--text-primary)]">{expense.title}</h4>
                  {expense.attachment_path && (
                    <a
                      href={`/api/projects/${projectId}/expenses/${expense.id}/attachment`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-[var(--text-tertiary)] hover:text-[var(--accent-default)]"
                      title="عرض المرفق"
                    >
                      <IconPaperclip className="h-3.5 w-3.5" />
                    </a>
                  )}
                </div>
                {expense.description && (
                  <p className="text-sm text-[var(--text-tertiary)] line-clamp-2">{expense.description}</p>
                )}
              </div>
              <div className="flex items-center gap-1 mr-2">
                <button
                  onClick={() => onEdit(expense)}
                  className="p-1 text-[var(--text-tertiary)] hover:text-[var(--accent-default)] rounded"
                >
                  <IconEdit className="h-3.5 w-3.5" />
                </button>
                <button
                  onClick={() => onDelete(expense)}
                  className="p-1 text-[var(--text-tertiary)] hover:text-[var(--status-danger)] rounded"
                >
                  <IconTrash className="h-3.5 w-3.5" />
                </button>
              </div>
            </div>

            <div className="flex items-center justify-between mb-3">
              <Badge className={categoryColors[expense.category] || categoryColors.other}>
                {EXPENSE_CATEGORIES[expense.category] || expense.category}
              </Badge>
              <span className="text-lg font-bold text-[var(--text-primary)]">
                {formatCurrency(expense.amount)}
              </span>
            </div>

            <div className="flex items-center justify-between text-xs text-[var(--text-tertiary)]">
              <div className="flex items-center gap-1">
                <IconCalendar className="h-3.5 w-3.5" />
                <span>{formatDate(expense.expense_date)}</span>
              </div>
              <span>{expense.creator.name}</span>
            </div>

            {expense.task && (
              <div className="mt-2 pt-2 border-t border-[var(--border-default)]">
                <span className="text-xs text-[var(--accent-default)]">مرتبط بـ: {expense.task.title}</span>
              </div>
            )}
          </CardContent>
        </Card>
      ))}
    </div>
  );
};

export default ExpenseCardsView;
