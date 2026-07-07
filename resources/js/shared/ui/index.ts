// Base Components
export { Button, type ButtonProps } from './Button';
export { Input, type InputProps } from './Input';
export { FieldError, type FieldErrorProps } from './FieldError';
export { Textarea, type TextareaProps } from './Textarea';
export { Select, type SelectProps, type SelectOption } from './Select';
export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter, type CardProps } from './Card';
export { Badge, type BadgeProps } from './Badge';
export {
  StatusBadge,
  type StatusBadgeProps,
  type ProjectStatus,
  type TaskStatus,
  type TaskPriority,
  type CustomBadgeColor,
  getProjectStatusLabel,
  getTaskStatusLabel,
  getPriorityLabel,
  PROJECT_STATUS_MAP,
  TASK_STATUS_MAP,
  PRIORITY_MAP
} from './StatusBadge';
export { Avatar, type AvatarProps } from './Avatar';

// Navigation & Layout
export { Tabs, TabsList, TabsTrigger, TabsContent, type TabsProps, type TabsListProps, type TabsTriggerProps, type TabsContentProps } from './Tabs';
export { Accordion, AccordionItem, AccordionTrigger, AccordionContent, type AccordionProps, type AccordionItemProps, type AccordionTriggerProps, type AccordionContentProps } from './Accordion';
export { Breadcrumb, type BreadcrumbProps, type BreadcrumbItem } from './Breadcrumb';

// Overlays
export { Modal, ModalHeader, ModalBody, ModalFooter, type ModalProps, type ModalHeaderProps } from './Modal';
export { Drawer, DrawerHeader, DrawerBody, DrawerFooter, type DrawerProps, type DrawerHeaderProps } from './Drawer';
export { Dropdown, DropdownTrigger, DropdownMenu, DropdownItem, DropdownSeparator, type DropdownProps, type DropdownTriggerProps, type DropdownMenuProps, type DropdownItemProps } from './Dropdown';
export { Tooltip, type TooltipProps } from './Tooltip';
export { FieldHelp, type FieldHelpProps } from './FieldHelp';

// Data Display
export { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell, TableCaption, type TableProps, type TableHeadProps } from './Table';
export { Pagination, type PaginationProps } from './Pagination';
export { Progress, type ProgressProps } from './Progress';
export { Skeleton, SkeletonText, SkeletonCard, SkeletonTable, type SkeletonProps } from './Skeleton';

// Form Components
export { Checkbox, type CheckboxProps } from './Checkbox';
export { RadioGroup, Radio, type RadioGroupProps, type RadioProps } from './Radio';
export { Switch, type SwitchProps } from './Switch';
export { DatePicker, type DatePickerProps } from './DatePicker';
export {
  DurationChips,
  shiftDate,
  PROJECT_DURATIONS,
  TASK_DURATIONS,
  type DurationOption,
  type DurationChipsProps,
} from './DurationChips';

// Feedback
export { Alert, type AlertProps } from './Alert';
export { ToastProvider, useToast, ToastContainer, ToastItem } from './Toast';

// Filter
export { FilterButton, type FilterButtonProps } from './FilterButton';

// Composite shared UI (moved from components/shared)
export { default as MentionInput } from './MentionInput';
export type { MentionInputProps, UserOption } from './MentionInput';
export { default as StatCard } from './StatCard';
export type { StatCardProps, StatCardColor } from './StatCard';
export { default as PageHeader } from './PageHeader';
export type {
  PageHeaderProps,
  PageHeaderSize,
  PageHeaderIconTone,
  PageHeaderIconVariant,
} from './PageHeader';
export { default as SectionHeader } from './SectionHeader';
export type {
  SectionHeaderProps,
  SectionHeaderLevel,
  SectionHeaderSize,
  SectionHeaderIconTone,
  SectionHeaderIconVariant,
} from './SectionHeader';
export { default as StatStrip } from './StatStrip';
export type { StatStripProps, StatStripItem, StatTone } from './StatStrip';
export { default as FilterBar } from './FilterBar';
export type { FilterBarProps } from './FilterBar';
export { default as FilterField } from './FilterField';
export type { FilterFieldProps } from './FilterField';
export { default as FilterRow } from './FilterRow';
export type { FilterRowProps } from './FilterRow';
export { default as FormSection } from './FormSection';
export type { FormSectionProps } from './FormSection';
export { default as FormActions } from './FormActions';
export type { FormActionsProps } from './FormActions';
export { DataTable, RowAction } from './DataTable';
export type {
  DataTableProps,
  DataTableColumn,
  DataTablePagination,
  DataTableEmpty,
  RowActionProps,
} from './DataTable';
export { default as DeleteConfirmationModal } from './DeleteConfirmationModal';
export type { DeleteConfirmationModalProps } from './DeleteConfirmationModal';
export { CommandPalette, type CommandPaletteProps } from './CommandPalette';
export { Kbd, type KbdProps } from './Kbd';
export { MaskedField, MASKED_FIELD_DEFAULT_MASK, type MaskedFieldProps } from './MaskedField';

// D7 components
export { IconButton, type IconButtonProps, type IconButtonVariant } from './IconButton';
export { RequiredIndicator, type RequiredIndicatorProps } from './RequiredIndicator';
export { StatusIconBox, type StatusIconBoxProps, type StatusIconBoxStatus } from './StatusIconBox';
export { EmptyState, type EmptyStateProps, type EmptyStateSize } from './EmptyState';

// Curated icon set
export * from './icons';
