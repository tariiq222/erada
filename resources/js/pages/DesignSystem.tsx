import * as React from 'react';
import {
  Button,
  Input,
  Textarea,
  Select,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  CardFooter,
  Badge,
  Avatar,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  Accordion,
  AccordionItem,
  AccordionTrigger,
  AccordionContent,
  Modal,
  ModalHeader,
  ModalBody,
  ModalFooter,
  Drawer,
  DrawerHeader,
  DrawerBody,
  DrawerFooter,
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  Pagination,
  Progress,
  Checkbox,
  RadioGroup,
  Radio,
  Switch,
  Alert,
  Tooltip,
  Dropdown,
  DropdownTrigger,
  DropdownMenu,
  DropdownItem,
  Breadcrumb,
  SkeletonText,
  SkeletonCard,
  useToast,
} from '@shared/ui';
import {IconSearch, IconPlus, IconSettings, IconMail, IconLock, IconFolder, IconFileText, IconCalendar, IconBell} from '@tabler/icons-react';
import { useLocale } from '@shared/contexts/LocaleContext';

// Toast Demo Component
const ToastDemo: React.FC = () => {
  const { addToast } = useToast();

  return (
    <div className="flex flex-wrap gap-2">
      <Button
        size="sm"
        variant="primary"
        onClick={() =>
          addToast({
            variant: 'success',
            title: 'تم بنجاح',
            message: 'تم حفظ التغييرات بنجاح',
          })
        }
      >
        نجاح
      </Button>
      <Button
        size="sm"
        variant="danger"
        onClick={() =>
          addToast({
            variant: 'error',
            title: 'خطأ',
            message: 'حدث خطأ أثناء العملية',
          })
        }
      >
        خطأ
      </Button>
      <Button
        size="sm"
        variant="outline"
        onClick={() =>
          addToast({
            variant: 'warning',
            message: 'يرجى مراجعة البيانات المدخلة',
          })
        }
      >
        تحذير
      </Button>
      <Button
        size="sm"
        variant="secondary"
        onClick={() =>
          addToast({
            variant: 'info',
            message: 'يتم معالجة طلبك...',
          })
        }
      >
        معلومة
      </Button>
    </div>
  );
};

const DesignSystem: React.FC = () => {
  const { direction } = useLocale();
  const [modalOpen, setModalOpen] = React.useState(false);
  const [drawerOpen, setDrawerOpen] = React.useState(false);
  const [currentPage, setCurrentPage] = React.useState(1);
  const [checkboxChecked, setCheckboxChecked] = React.useState(false);
  const [radioValue, setRadioValue] = React.useState('option1');
  const [switchChecked, setSwitchChecked] = React.useState(false);

  return (
    <div className="min-h-screen bg-[var(--surface-subtle)]" dir={direction}>
        {/* Header */}
        <header className="bg-[var(--surface-base)] border-b border-[var(--border-default)] sticky top-0 z-40">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div className="flex items-center justify-between h-16">
              <h1 className="text-xl font-bold text-[var(--accent-default)]">
                نظام التصميم الموحد
              </h1>
              <Badge variant="accent">v1.3</Badge>
            </div>
          </div>
        </header>

        <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
          {/* Breadcrumb */}
          <Breadcrumb
            items={[
              { label: 'التوثيق', href: '#' },
              { label: 'نظام التصميم' },
            ]}
            className="mb-8"
          />

          <Tabs defaultValue="buttons">
            <TabsList className="mb-8 flex-wrap">
              <TabsTrigger value="buttons">الأزرار</TabsTrigger>
              <TabsTrigger value="inputs">الحقول</TabsTrigger>
              <TabsTrigger value="cards">البطاقات</TabsTrigger>
              <TabsTrigger value="tabs">التبويبات</TabsTrigger>
              <TabsTrigger value="accordion">الأكورديون</TabsTrigger>
              <TabsTrigger value="modals">النوافذ</TabsTrigger>
              <TabsTrigger value="tables">الجداول</TabsTrigger>
              <TabsTrigger value="forms">النماذج</TabsTrigger>
              <TabsTrigger value="feedback">التنبيهات</TabsTrigger>
              <TabsTrigger value="crisis">Crisis</TabsTrigger>
              <TabsTrigger value="misc">متفرقات</TabsTrigger>
            </TabsList>

            {/* Buttons Section */}
            <TabsContent value="buttons">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>أنماط الأزرار</CardTitle>
                    <CardDescription>
                      الأنماط المختلفة للأزرار المتاحة في النظام
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap gap-3">
                      <Button variant="primary">أساسي</Button>
                      <Button variant="secondary">ثانوي</Button>
                      <Button variant="outline">محدد</Button>
                      <Button variant="ghost">شفاف</Button>
                      <Button variant="danger">خطر</Button>
                      <Button variant="primary">تأكيد</Button>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>أحجام الأزرار</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap items-center gap-3">
                      <Button size="sm">صغير</Button>
                      <Button size="md">متوسط</Button>
                      <Button size="sm">كبير</Button>
                      <Button size="sm">
                        <IconPlus className="h-4 w-4" />
                      </Button>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>أزرار مع أيقونات</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap gap-3">
                      <Button leftIcon={<IconPlus className="h-4 w-4" />}>
                        إضافة جديد
                      </Button>
                      <Button
                        variant="outline"
                        rightIcon={<IconSettings className="h-4 w-4" />}
                      >
                        الإعدادات
                      </Button>
                      <Button variant="secondary" loading>
                        جاري التحميل
                      </Button>
                      <Button disabled>معطل</Button>
                    </div>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Inputs Section */}
            <TabsContent value="inputs">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>حقول الإدخال</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="grid gap-4 md:grid-cols-2">
                      <Input label="الاسم الكامل" placeholder="أدخل اسمك" />
                      <Input
                        label="البريد الإلكتروني"
                        type="email"
                        placeholder="example@domain.com"
                        leftIcon={<IconMail className="h-4 w-4" />}
                      />
                      <Input
                        label="كلمة المرور"
                        type="password"
                        placeholder="••••••••"
                        leftIcon={<IconLock className="h-4 w-4" />}
                      />
                      <Input
                        label="البحث"
                        placeholder="ابحث هنا..."
                        leftIcon={<IconSearch className="h-4 w-4" />}
                        hint="ابحث في المشاريع والمهام"
                      />
                      <Input
                        label="حقل به خطأ"
                        error="هذا الحقل مطلوب"
                        placeholder="أدخل قيمة"
                      />
                      <Input
                        label="حقل معطل"
                        disabled
                        value="قيمة غير قابلة للتعديل"
                      />
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>منطقة النص</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="grid gap-4 md:grid-cols-2">
                      <Textarea
                        label="الوصف"
                        placeholder="أدخل وصفاً تفصيلياً..."
                        hint="الحد الأقصى 500 حرف"
                      />
                      <Textarea
                        label="ملاحظات"
                        placeholder="أضف ملاحظاتك هنا..."
                        error="الوصف قصير جداً"
                      />
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>القوائم المنسدلة</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="grid gap-4 md:grid-cols-2">
                      <Select
                        label="الحالة"
                        placeholder="اختر الحالة"
                        options={[
                          { value: 'draft', label: 'مسودة' },
                          { value: 'active', label: 'نشط' },
                          { value: 'completed', label: 'مكتمل' },
                          { value: 'cancelled', label: 'ملغى' },
                        ]}
                      />
                      <Select
                        label="الأولوية"
                        placeholder="اختر الأولوية"
                        options={[
                          { value: 'low', label: 'منخفضة' },
                          { value: 'medium', label: 'متوسطة' },
                          { value: 'high', label: 'عالية' },
                          { value: 'urgent', label: 'عاجلة' },
                        ]}
                        error="الرجاء اختيار الأولوية"
                      />
                    </div>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Cards Section */}
            <TabsContent value="cards">
              <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <Card variant="default">
                  <CardHeader>
                    <CardTitle>بطاقة افتراضية</CardTitle>
                    <CardDescription>وصف مختصر للبطاقة</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <p className="text-[var(--text-secondary)] text-sm">
                      هذا محتوى البطاقة الافتراضية مع حدود رمادية خفيفة.
                    </p>
                  </CardContent>
                  <CardFooter>
                    <Button size="sm">عرض المزيد</Button>
                  </CardFooter>
                </Card>

                <Card variant="elevated">
                  <CardHeader>
                    <CardTitle>بطاقة محددة</CardTitle>
                    <CardDescription>مع حدود أكثر وضوحاً</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <p className="text-[var(--text-secondary)] text-sm">
                      بطاقة مع حدود أكثر سماكة للتأكيد البصري.
                    </p>
                  </CardContent>
                </Card>

                <Card variant="elevated">
                  <CardHeader>
                    <CardTitle>بطاقة مرتفعة</CardTitle>
                    <CardDescription>مع ظل واضح</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <p className="text-[var(--text-secondary)] text-sm">
                      بطاقة مع ظل لإعطاء انطباع بالعمق والارتفاع.
                    </p>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Tabs Section */}
            <TabsContent value="tabs">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>تبويبات افتراضية</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <Tabs defaultValue="tab1">
                      <TabsList>
                        <TabsTrigger value="tab1">التبويب الأول</TabsTrigger>
                        <TabsTrigger value="tab2">التبويب الثاني</TabsTrigger>
                        <TabsTrigger value="tab3">التبويب الثالث</TabsTrigger>
                      </TabsList>
                      <TabsContent value="tab1">
                        <p className="text-[var(--text-secondary)]">محتوى التبويب الأول</p>
                      </TabsContent>
                      <TabsContent value="tab2">
                        <p className="text-[var(--text-secondary)]">محتوى التبويب الثاني</p>
                      </TabsContent>
                      <TabsContent value="tab3">
                        <p className="text-[var(--text-secondary)]">محتوى التبويب الثالث</p>
                      </TabsContent>
                    </Tabs>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>تبويبات بشكل حبوب</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <Tabs defaultValue="all">
                      <TabsList>
                        <TabsTrigger value="all">الكل</TabsTrigger>
                        <TabsTrigger value="active">النشطة</TabsTrigger>
                        <TabsTrigger value="completed">المكتملة</TabsTrigger>
                      </TabsList>
                      <TabsContent value="all">
                        <p className="text-[var(--text-secondary)]">جميع العناصر</p>
                      </TabsContent>
                      <TabsContent value="active">
                        <p className="text-[var(--text-secondary)]">العناصر النشطة فقط</p>
                      </TabsContent>
                      <TabsContent value="completed">
                        <p className="text-[var(--text-secondary)]">العناصر المكتملة</p>
                      </TabsContent>
                    </Tabs>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>تبويبات مع أيقونات</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <Tabs defaultValue="projects">
                      <TabsList>
                        <TabsTrigger
                          value="projects"
                          icon={<IconFolder className="h-4 w-4" />}
                        >
                          المشاريع
                        </TabsTrigger>
                        <TabsTrigger
                          value="tasks"
                          icon={<IconFileText className="h-4 w-4" />}
                        >
                          المهام
                        </TabsTrigger>
                        <TabsTrigger
                          value="calendar"
                          icon={<IconCalendar className="h-4 w-4" />}
                        >
                          التقويم
                        </TabsTrigger>
                      </TabsList>
                      <TabsContent value="projects">
                        <p className="text-[var(--text-secondary)]">قائمة المشاريع</p>
                      </TabsContent>
                      <TabsContent value="tasks">
                        <p className="text-[var(--text-secondary)]">قائمة المهام</p>
                      </TabsContent>
                      <TabsContent value="calendar">
                        <p className="text-[var(--text-secondary)]">عرض التقويم</p>
                      </TabsContent>
                    </Tabs>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Accordion Section */}
            <TabsContent value="accordion">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>أكورديون فردي</CardTitle>
                    <CardDescription>يفتح عنصر واحد فقط في كل مرة</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <Accordion type="single" defaultValue="item-1">
                      <AccordionItem value="item-1">
                        <AccordionTrigger>ما هو نظام إدارة المشاريع؟</AccordionTrigger>
                        <AccordionContent>
                          نظام إدارة المشاريع هو منصة متكاملة تساعد الفرق على
                          تخطيط وتنفيذ ومتابعة المشاريع بكفاءة عالية. يوفر
                          النظام أدوات لإدارة المهام والموارد والمخاطر.
                        </AccordionContent>
                      </AccordionItem>
                      <AccordionItem value="item-2">
                        <AccordionTrigger>كيف يمكنني إنشاء مشروع جديد؟</AccordionTrigger>
                        <AccordionContent>
                          لإنشاء مشروع جديد، انتقل إلى صفحة المشاريع واضغط على
                          زر "إضافة مشروع". قم بتعبئة النموذج بالمعلومات
                          المطلوبة مثل الاسم والوصف والتواريخ.
                        </AccordionContent>
                      </AccordionItem>
                      <AccordionItem value="item-3">
                        <AccordionTrigger>هل يمكنني تعيين مهام لأعضاء الفريق؟</AccordionTrigger>
                        <AccordionContent>
                          نعم، يمكنك تعيين المهام لأي عضو في فريق المشروع.
                          سيتلقى العضو إشعاراً بالمهمة المسندة إليه ويمكنه
                          تحديث حالتها وتقدمها.
                        </AccordionContent>
                      </AccordionItem>
                    </Accordion>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>أكورديون متعدد</CardTitle>
                    <CardDescription>يمكن فتح عدة عناصر في نفس الوقت</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <Accordion type="multiple" defaultValue={['feature-1']}>
                      <AccordionItem value="feature-1">
                        <AccordionTrigger icon={<IconFolder className="h-5 w-5 text-[var(--accent-default)]" />}>
                          إدارة المشاريع
                        </AccordionTrigger>
                        <AccordionContent>
                          إنشاء وإدارة المشاريع مع تتبع التقدم والميزانية
                          والموارد.
                        </AccordionContent>
                      </AccordionItem>
                      <AccordionItem value="feature-2">
                        <AccordionTrigger icon={<IconFileText className="h-5 w-5 text-[var(--status-success)]" />}>
                          تتبع المهام
                        </AccordionTrigger>
                        <AccordionContent>
                          تعيين المهام ومتابعة حالتها مع إشعارات تلقائية.
                        </AccordionContent>
                      </AccordionItem>
                      <AccordionItem value="feature-3">
                        <AccordionTrigger icon={<IconBell className="h-5 w-5 text-[var(--status-warning)]" />}>
                          الإشعارات
                        </AccordionTrigger>
                        <AccordionContent>
                          تنبيهات فورية للتحديثات والمواعيد النهائية.
                        </AccordionContent>
                      </AccordionItem>
                    </Accordion>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Modals Section */}
            <TabsContent value="modals">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>النوافذ المنبثقة</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap gap-4">
                      <Button onClick={() => setModalOpen(true)}>
                        فتح نافذة منبثقة
                      </Button>
                      <Button variant="outline" onClick={() => setDrawerOpen(true)}>
                        فتح درج جانبي
                      </Button>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>القوائم المنسدلة المخصصة</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <Dropdown>
                      <DropdownTrigger>اختر إجراء</DropdownTrigger>
                      <DropdownMenu>
                        <DropdownItem value="edit" icon={<IconFileText className="h-4 w-4" />}>
                          تعديل
                        </DropdownItem>
                        <DropdownItem value="duplicate" icon={<IconPlus className="h-4 w-4" />}>
                          نسخ
                        </DropdownItem>
                        <DropdownItem value="settings" icon={<IconSettings className="h-4 w-4" />}>
                          الإعدادات
                        </DropdownItem>
                      </DropdownMenu>
                    </Dropdown>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Tables Section */}
            <TabsContent value="tables">
              <div className="space-y-8">
                <Card className="p-0">
                  <CardHeader className="p-5">
                    <CardTitle>جدول المشاريع</CardTitle>
                  </CardHeader>
                  <Table striped hoverable>
                    <TableHeader>
                      <TableRow>
                        <TableHead sortable sortDirection="asc">
                          اسم المشروع
                        </TableHead>
                        <TableHead>الحالة</TableHead>
                        <TableHead sortable>التقدم</TableHead>
                        <TableHead>تاريخ الانتهاء</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      <TableRow>
                        <TableCell className="font-medium">
                          تطوير منصة التجارة الإلكترونية
                        </TableCell>
                        <TableCell>
                          <Badge variant="success">
                            نشط
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Progress value={75} size="sm" />
                        </TableCell>
                        <TableCell>2024-03-15</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell className="font-medium">
                          تحديث نظام الموارد البشرية
                        </TableCell>
                        <TableCell>
                          <Badge variant="warning">
                            قيد المراجعة
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Progress value={45} size="sm" />
                        </TableCell>
                        <TableCell>2024-04-20</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell className="font-medium">
                          إطلاق تطبيق الجوال
                        </TableCell>
                        <TableCell>
                          <Badge variant="accent">
                            تخطيط
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Progress value={15} size="sm" />
                        </TableCell>
                        <TableCell>2024-06-01</TableCell>
                      </TableRow>
                    </TableBody>
                  </Table>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>ترقيم الصفحات</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <Pagination
                      currentPage={currentPage}
                      totalPages={10}
                      onPageChange={setCurrentPage}
                    />
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Forms Section */}
            <TabsContent value="forms">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>عناصر النماذج</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-6">
                      <div>
                        <h4 className="font-medium text-[var(--text-primary)] mb-3">
                          صناديق الاختيار
                        </h4>
                        <div className="space-y-3">
                          <Checkbox
                            label="أوافق على الشروط والأحكام"
                            checked={checkboxChecked}
                            onChange={(e) => setCheckboxChecked(e.target.checked)}
                          />
                          <Checkbox
                            label="تفعيل الإشعارات"
                            description="سيتم إرسال إشعارات عند وجود تحديثات جديدة"
                          />
                          <Checkbox
                            label="خيار معطل"
                            disabled
                          />
                          <Checkbox
                            label="خيار محدد جزئياً"
                            indeterminate
                          />
                        </div>
                      </div>

                      <div>
                        <h4 className="font-medium text-[var(--text-primary)] mb-3">
                          أزرار الراديو
                        </h4>
                        <RadioGroup
                          name="priority"
                          value={radioValue}
                          onChange={setRadioValue}
                        >
                          <Radio value="option1" label="الخيار الأول" />
                          <Radio
                            value="option2"
                            label="الخيار الثاني"
                            description="وصف إضافي للخيار"
                          />
                          <Radio value="option3" label="الخيار الثالث" />
                        </RadioGroup>
                      </div>

                      <div>
                        <h4 className="font-medium text-[var(--text-primary)] mb-3">
                          مفاتيح التبديل
                        </h4>
                        <div className="space-y-3">
                          <Switch
                            label="الوضع الليلي"
                            checked={switchChecked}
                            onChange={(e) => setSwitchChecked(e.target.checked)}
                          />
                          <Switch
                            label="تفعيل الصوت"
                            description="تشغيل الأصوات عند الإشعارات"
                            size="lg"
                          />
                          <Switch
                            label="مفتاح معطل"
                            disabled
                            size="sm"
                          />
                        </div>
                      </div>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>شريط التقدم</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-4">
                      <Progress value={25} showValue />
                      <Progress value={50} size="md" showValue />
                      <Progress value={75} showValue />
                      <Progress value={90} size="sm" />
                    </div>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Feedback Section */}
            <TabsContent value="feedback">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>التنبيهات</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="space-y-4">
                      <Alert variant="info" title="معلومة">
                        هذه رسالة معلوماتية للمستخدم.
                      </Alert>
                      <Alert variant="success" title="تم بنجاح">
                        تم حفظ التغييرات بنجاح.
                      </Alert>
                      <Alert variant="warning" title="تحذير">
                        يرجى مراجعة البيانات قبل المتابعة.
                      </Alert>
                      <Alert variant="danger" title="خطأ" dismissible>
                        حدث خطأ أثناء العملية. يرجى المحاولة مرة أخرى.
                      </Alert>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>رسائل Toast</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <ToastDemo />
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>التلميحات</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap gap-4">
                      <Tooltip content="تلميح من الأعلى" position="top">
                        <Button variant="outline">أعلى</Button>
                      </Tooltip>
                      <Tooltip content="تلميح من الأسفل" position="bottom">
                        <Button variant="outline">أسفل</Button>
                      </Tooltip>
                      <Tooltip content="تلميح من اليسار" position="left">
                        <Button variant="outline">يسار</Button>
                      </Tooltip>
                      <Tooltip content="تلميح من اليمين" position="right">
                        <Button variant="outline">يمين</Button>
                      </Tooltip>
                    </div>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            {/* Misc Section */}
            {/* Crisis Tokens Section (v1.2) */}
            <TabsContent value="crisis">
              <div className="space-y-8" data-accent="crisis">
                <Card data-accent="crisis">
                  <CardHeader>
                    <CardTitle>عائلة Crisis (slate/steel)</CardTitle>
                    <CardDescription>
                      تُستخدم حصراً في واجهات إدارة الأزمات. الهوية
                      <code> var(--crisis-base) </code>
                      تختلف عن الأربعة الثانوية (indigo/teal/amber/violet)
                      وعن الـ primary.
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                      {[
                        "var(--crisis-light)",
                        "var(--crisis-base)",
                        "var(--crisis-dark)",
                        "var(--crisis-subtle)",
                        "var(--crisis-text)",
                        "var(--crisis-border)",
                      ].map((t) => (
                        <div
                          key={t}
                          className="rounded-lg border p-3"
                          style={{
                            borderColor: "var(--crisis-border)",
                            background: "var(--crisis-subtle)",
                            color: "var(--crisis-text)",
                          }}
                        >
                          <div
                            className="h-10 w-full rounded-md mb-2"
                            style={{ background: t }}
                          />
                          <code className="text-xs">{t}</code>
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
                <Card>
                  <CardHeader>
                    <CardTitle>كيفية الاستخدام</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-sm text-[var(--text-secondary)]">
                      ضع <code>data-accent=&quot;crisis&quot;</code> على
                      الجذر أو بطاقة، وكل العناصر الفرعية تلتقط
                      <code> var(--crisis-*) </code>
                      عبر CSS variables. الـ icon <code>command</code> هو
                      الافتراضي للتنقّل في القائمة الجانبية.
                    </p>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            <TabsContent value="misc">
              <div className="space-y-8">
                <Card>
                  <CardHeader>
                    <CardTitle>الشارات</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap gap-2">
                      <Badge>افتراضي</Badge>
                      <Badge variant="accent">أساسي</Badge>
                      <Badge variant="success">نجاح</Badge>
                      <Badge variant="warning">تحذير</Badge>
                      <Badge variant="danger">خطر</Badge>
                      <Badge variant="accent">معلومة</Badge>
                      <Badge variant="success">
                        مميز
                      </Badge>
                      <Badge variant="accent" size="md">
                        كبير
                      </Badge>
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>الصور الرمزية</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex flex-wrap items-end gap-4">
                      <Avatar name="أحمد محمد" size="xs" />
                      <Avatar name="سارة أحمد" size="sm" />
                      <Avatar name="محمد علي" size="md" status="online" />
                      <Avatar name="فاطمة خالد" size="lg" status="away" />
                      <Avatar
                        src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100"
                        name="User"
                        size="xl"
                        status="busy"
                      />
                    </div>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle>هياكل التحميل</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="grid gap-6 md:grid-cols-2">
                      <div>
                        <h4 className="font-medium text-[var(--text-primary)] mb-3">نص</h4>
                        <SkeletonText lines={3} />
                      </div>
                      <div>
                        <h4 className="font-medium text-[var(--text-primary)] mb-3">بطاقة</h4>
                        <SkeletonCard />
                      </div>
                    </div>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>
          </Tabs>
        </main>

        {/* Modal */}
        <Modal open={modalOpen} onClose={() => setModalOpen(false)} size="md">
          <ModalHeader onClose={() => setModalOpen(false)}>
            إضافة مشروع جديد
          </ModalHeader>
          <ModalBody>
            <div className="space-y-4">
              <Input label="اسم المشروع" placeholder="أدخل اسم المشروع" />
              <Textarea label="الوصف" placeholder="أدخل وصف المشروع" />
              <Select
                label="الحالة"
                options={[
                  { value: 'draft', label: 'مسودة' },
                  { value: 'active', label: 'نشط' },
                ]}
              />
            </div>
          </ModalBody>
          <ModalFooter>
            <Button variant="ghost" onClick={() => setModalOpen(false)}>
              إلغاء
            </Button>
            <Button onClick={() => setModalOpen(false)}>حفظ</Button>
          </ModalFooter>
        </Modal>

        {/* Drawer */}
        <Drawer open={drawerOpen} onClose={() => setDrawerOpen(false)} position="right">
          <DrawerHeader onClose={() => setDrawerOpen(false)}>
            تفاصيل المشروع
          </DrawerHeader>
          <DrawerBody>
            <div className="space-y-6">
              <div>
                <h4 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">
                  اسم المشروع
                </h4>
                <p className="text-[var(--text-primary)]">منصة التجارة الإلكترونية</p>
              </div>
              <div>
                <h4 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">الحالة</h4>
                <Badge variant="success">
                  نشط
                </Badge>
              </div>
              <div>
                <h4 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">التقدم</h4>
                <Progress value={75} showValue />
              </div>
              <div>
                <h4 className="text-sm font-medium text-[var(--text-tertiary)] mb-1">الوصف</h4>
                <p className="text-[var(--text-secondary)] text-sm">
                  تطوير منصة تجارة إلكترونية متكاملة تدعم البيع والشراء عبر
                  الإنترنت مع نظام دفع آمن ولوحة تحكم للإدارة.
                </p>
              </div>
            </div>
          </DrawerBody>
          <DrawerFooter>
            <Button variant="outline" onClick={() => setDrawerOpen(false)}>
              إغلاق
            </Button>
            <Button>تعديل</Button>
          </DrawerFooter>
        </Drawer>
      </div>
  );
};

export default DesignSystem;
