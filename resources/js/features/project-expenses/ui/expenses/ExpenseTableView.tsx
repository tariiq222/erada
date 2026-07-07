import React from 'react';
import {
  Card,
  CardContent,
  Badge,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
} from '@shared/ui';
import {IconTrash, IconEdit, IconCalendar, IconPaperclip} from '@tabler/icons-react';
import { IconButton } from '@shared/ui/IconButton';
import { formatDate } from '@shared/lib/utils';
import { Expense, categoryColors, EXPENSE_CATEGORIES, formatCurrency } from './types';

interface ExpenseTableViewProps {
  projectId: number;
  expenses: Expense[];
  onEdit: (expense: Expense) => void;
  onDelete: (expense: Expense) => void;
}

const ExpenseTableView: React.FC<ExpenseTableViewProps> = ({
  projectId,
  expenses,
  onEdit,
  onDelete,
}) => {
  return (
    <Card>
      <CardContent className="p-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>العنوان</TableHead>
              <TableHead>التصنيف</TableHead>
              <TableHead>المبلغ</TableHead>
              <TableHead>التاريخ</TableHead>
              <TableHead>المهمة</TableHead>
              <TableHead>بواسطة</TableHead>
              <TableHead className="w-20">إجراءات</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {expenses.map((expense) => (
              <TableRow key={expense.id}>
                <TableCell>
                  <div className="flex items-center gap-2">
                    {expense.attachment_path && (
                      <a
                        href={`/api/projects/${projectId}/expenses/${expense.id}/attachment`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="p-1 text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] rounded-lg transition-colors flex-shrink-0"
                        title="عرض المرفق"
                      >
                        <IconPaperclip className="h-4 w-4" />
                      </a>
                    )}
                    <div>
                      <p className="font-medium text-[var(--text-primary)]">{expense.title}</p>
                      {expense.description && (
                        <p className="text-xs text-[var(--text-tertiary)] truncate max-w-xs">{expense.description}</p>
                      )}
                      {expense.reference_number && (
                        <p className="text-xs text-[var(--text-tertiary)]">#{expense.reference_number}</p>
                      )}
                    </div>
                  </div>
                </TableCell>
                <TableCell>
                  <Badge className={categoryColors[expense.category] || categoryColors.other}>
                    {EXPENSE_CATEGORIES[expense.category] || expense.category}
                  </Badge>
                </TableCell>
                <TableCell>
                  <span className="font-semibold text-[var(--text-primary)]">
                    {formatCurrency(expense.amount)}
                  </span>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-1 text-[var(--text-secondary)]">
                    <IconCalendar className="h-3.5 w-3.5" />
                    <span className="text-sm">{formatDate(expense.expense_date)}</span>
                  </div>
                </TableCell>
                <TableCell>
                  {expense.task ? (
                    <span className="text-sm text-[var(--accent-default)]">{expense.task.title}</span>
                  ) : (
                    <span className="text-[var(--text-tertiary)]">-</span>
                  )}
                </TableCell>
                <TableCell>
                  <span className="text-sm text-[var(--text-secondary)]">{expense.creator.name}</span>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => onEdit(expense)}
                      className="p-1 text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] rounded-lg transition-colors"
                      title="تعديل"
                    >
                      <IconEdit className="h-4 w-4" />
                    </button>
                    <IconButton
                      variant="danger"
                      size="sm"
                      onClick={() => onDelete(expense)}
                      title="حذف"
                    >
                      <IconTrash className="h-4 w-4" />
                    </IconButton>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
};

export default ExpenseTableView;
