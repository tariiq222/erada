export interface SurveySection {
  id: number;
  title: string;
  description: string | null;
  order: number;
  is_visible: boolean;
}

export interface SurveyField {
  id?: number;
  field_key: string;
  name: string;
  label: string;
  description: string;
  type: string;
  config: Record<string, any>;
  is_required: boolean;
  order: number;
  section_id: number | null;
}

export interface Survey {
  id: number;
  code: string;
  title: string;
  type: 'initial' | 'periodic';
  status: string;
  fields: SurveyField[];
}

export interface TargetColumn {
  label: string;
  type: string;
  required?: boolean;
}

export interface TargetModel {
  label: string;
  model: string;
  columns: Record<string, TargetColumn>;
}

export interface FieldOption {
  label: string;
  value: string;
}

export const fieldTypes = [
  { value: 'text', label: 'نص قصير' },
  { value: 'textarea', label: 'نص طويل' },
  { value: 'number', label: 'رقم' },
  { value: 'email', label: 'بريد إلكتروني' },
  { value: 'phone', label: 'هاتف' },
  { value: 'date', label: 'تاريخ' },
  { value: 'time', label: 'وقت' },
  { value: 'datetime', label: 'تاريخ ووقت' },
  { value: 'select', label: 'قائمة منسدلة' },
  { value: 'radio', label: 'اختيار واحد' },
  { value: 'checkbox', label: 'اختيار متعدد' },
  { value: 'rating', label: 'تقييم (نجوم)' },
  { value: 'scale', label: 'مقياس (1-10)' },
  { value: 'file', label: 'رفع ملف' },
];

// تحديد نوع الحقل بناءً على نوع العمود في قاعدة البيانات
export const columnTypeToFieldType: Record<string, string> = {
  string: 'text',
  text: 'textarea',
  email: 'email',
  phone: 'phone',
  integer: 'number',
  foreign: 'select',
};

export const hasOptions = (type: string) => ['select', 'radio', 'checkbox', 'multiselect'].includes(type);

export const initialFieldData: Omit<SurveyField, 'id' | 'order'> = {
  field_key: '',
  name: '',
  label: '',
  description: '',
  type: 'text',
  config: {},
  is_required: false,
  section_id: null,
};
