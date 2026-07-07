import React, { useEffect, useState } from "react";
import { IconAlertOctagon, IconEdit, IconPlus, IconSettings, IconSitemap, IconTrash } from "@tabler/icons-react";
import {
  Badge,
  Button,
  Card,
  DeleteConfirmationModal,
  Input,
  Modal,
  ModalBody,
  ModalFooter,
  PageHeader,
  Skeleton,
  Switch,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@shared/ui";
import { useToast } from "@shared/ui/Toast";
import {
  risksApi,
  type ImpactTypeSettingsPayload,
  type RiskSettingOption,
  type RiskSettings,
  type RiskTypeSettingsPayload,
} from "@entities/risk";
import RiskGoverningDepartmentSection from "./components/RiskGoverningDepartmentSection";

interface RiskTypeFormData {
  value: string;
  label: string;
  sort_order: string;
  is_active: boolean;
}

interface ImpactTypeFormData {
  value: string;
  label: string;
  sort_order: string;
  is_active: boolean;
}

const emptyRiskTypeForm: RiskTypeFormData = {
  value: "",
  label: "",
  sort_order: "0",
  is_active: true,
};

const emptyImpactTypeForm: ImpactTypeFormData = {
  value: "",
  label: "",
  sort_order: "0",
  is_active: true,
};

const getResponseData = <T,>(response: unknown): T | null => {
  if (!response || typeof response !== "object") return null;
  const firstData = (response as { data?: unknown }).data;
  if (!firstData || typeof firstData !== "object") return null;
  const nestedData = (firstData as { data?: unknown }).data;
  return (nestedData ?? firstData) as T;
};

const getErrorMessage = (error: unknown, fallback: string) => {
  if (error && typeof error === "object") {
    const message = (error as { message?: unknown }).message;
    if (typeof message === "string" && message.trim()) return message;

    const responseMessage = (error as { response?: { data?: { message?: unknown } } }).response?.data?.message;
    if (typeof responseMessage === "string" && responseMessage.trim()) return responseMessage;
  }

  return fallback;
};

const sortSettings = (items: RiskSettingOption[]) =>
  [...items].sort((a, b) => {
    const orderDiff = Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0);
    if (orderDiff !== 0) return orderDiff;
    return String(a.value).localeCompare(String(b.value));
  });

const RiskSettingsPage: React.FC = () => {
  const { showToast } = useToast();
  const [riskTypes, setRiskTypes] = useState<RiskSettingOption[]>([]);
  const [impactTypes, setImpactTypes] = useState<RiskSettingOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const [riskTypeModal, setRiskTypeModal] = useState<{ open: boolean; item: RiskSettingOption | null }>({
    open: false,
    item: null,
  });
  const [riskTypeForm, setRiskTypeForm] = useState<RiskTypeFormData>(emptyRiskTypeForm);
  const [riskTypeErrors, setRiskTypeErrors] = useState<{ value?: string; label?: string }>({});
  const [isSavingRiskType, setIsSavingRiskType] = useState(false);

  const [impactModal, setImpactModal] = useState<{ open: boolean; item: RiskSettingOption | null }>({
    open: false,
    item: null,
  });
  const [impactForm, setImpactForm] = useState<ImpactTypeFormData>(emptyImpactTypeForm);
  const [impactErrors, setImpactErrors] = useState<{ value?: string; label?: string }>({});
  const [isSavingImpact, setIsSavingImpact] = useState(false);

  const [deleteModal, setDeleteModal] = useState<{
    open: boolean;
    item: RiskSettingOption | null;
    type: "risk" | "impact" | null;
  }>({ open: false, item: null, type: null });
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchSettings = async () => {
    setIsLoading(true);
    try {
      const response = await risksApi.settings();
      const settings = getResponseData<RiskSettings>(response);
      setRiskTypes(sortSettings(settings?.risk_types ?? []));
      setImpactTypes(sortSettings(settings?.impact_types ?? []));
    } catch (error) {
      showToast("error", getErrorMessage(error, "فشل تحميل إعدادات المخاطر"));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchSettings();
  }, []);

  const openCreateRiskType = () => {
    setRiskTypeForm(emptyRiskTypeForm);
    setRiskTypeErrors({});
    setRiskTypeModal({ open: true, item: null });
  };

  const openEditRiskType = (item: RiskSettingOption) => {
    setRiskTypeForm({
      value: String(item.value),
      label: item.label,
      sort_order: String(item.sort_order ?? 0),
      is_active: item.is_active,
    });
    setRiskTypeErrors({});
    setRiskTypeModal({ open: true, item });
  };

  const closeRiskTypeModal = () => {
    if (isSavingRiskType) return;
    setRiskTypeModal({ open: false, item: null });
    setRiskTypeErrors({});
  };

  const openCreateImpactType = () => {
    setImpactForm(emptyImpactTypeForm);
    setImpactErrors({});
    setImpactModal({ open: true, item: null });
  };

  const openEditImpactType = (item: RiskSettingOption) => {
    setImpactForm({
      value: String(item.value),
      label: item.label,
      sort_order: String(item.sort_order ?? 0),
      is_active: item.is_active,
    });
    setImpactErrors({});
    setImpactModal({ open: true, item });
  };

  const closeImpactModal = () => {
    if (isSavingImpact) return;
    setImpactModal({ open: false, item: null });
    setImpactErrors({});
  };

  const saveRiskType = async () => {
    const nextErrors: { value?: string; label?: string } = {};
    if (!riskTypeForm.value.trim()) nextErrors.value = "القيمة مطلوبة";
    if (!riskTypeForm.label.trim()) nextErrors.label = "التسمية مطلوبة";
    setRiskTypeErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;

    const payload: RiskTypeSettingsPayload = {
      value: riskTypeForm.value.trim(),
      label: riskTypeForm.label.trim(),
      is_active: riskTypeForm.is_active,
      sort_order: Number(riskTypeForm.sort_order || 0),
    };

    setIsSavingRiskType(true);
    try {
      if (riskTypeModal.item) {
        await risksApi.updateRiskType(riskTypeModal.item.id, payload);
        showToast("success", "تم تحديث نوع الخطر");
      } else {
        await risksApi.createRiskType(payload);
        showToast("success", "تم إنشاء نوع الخطر");
      }

      setRiskTypeModal({ open: false, item: null });
      await fetchSettings();
    } catch (error) {
      showToast("error", getErrorMessage(error, "فشل حفظ نوع الخطر"));
    } finally {
      setIsSavingRiskType(false);
    }
  };

  const saveImpactType = async () => {
    const nextErrors: { value?: string; label?: string } = {};
    if (!impactForm.value.trim()) nextErrors.value = "القيمة مطلوبة";
    if (!impactForm.label.trim()) nextErrors.label = "التسمية مطلوبة";
    setImpactErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return;

    const payload: ImpactTypeSettingsPayload = {
      value: impactForm.value.trim(),
      label: impactForm.label.trim(),
      is_active: impactForm.is_active,
      sort_order: Number(impactForm.sort_order || 0),
    };

    setIsSavingImpact(true);
    try {
      if (impactModal.item) {
        await risksApi.updateImpactType(impactModal.item.id, payload);
        showToast("success", "تم تحديث نوع الأثر");
      } else {
        await risksApi.createImpactType(payload);
        showToast("success", "تم إنشاء نوع الأثر");
      }

      setImpactModal({ open: false, item: null });
      await fetchSettings();
    } catch (error) {
      showToast("error", getErrorMessage(error, "فشل حفظ نوع الأثر"));
    } finally {
      setIsSavingImpact(false);
    }
  };

  const deleteSetting = async () => {
    if (!deleteModal.item || !deleteModal.type) return;
    setIsDeleting(true);
    try {
      if (deleteModal.type === "risk") {
        await risksApi.removeRiskType(deleteModal.item.id);
        showToast("success", "تم حذف نوع الخطر");
      } else {
        await risksApi.removeImpactType(deleteModal.item.id);
        showToast("success", "تم حذف نوع الأثر");
      }

      setDeleteModal({ open: false, item: null, type: null });
      await fetchSettings();
    } catch (error) {
      showToast(
        "error",
        getErrorMessage(error, deleteModal.type === "risk" ? "لا يمكن حذف نوع الخطر" : "لا يمكن حذف نوع الأثر"),
      );
    } finally {
      setIsDeleting(false);
    }
  };

  const renderStatus = (isActive: boolean) => (
    <Badge variant={isActive ? "success" : "default"} size="sm">
      {isActive ? "نشط" : "غير نشط"}
    </Badge>
  );

  const renderLoadingRows = (columns: number) => (
    <TableBody>
      {Array.from({ length: 4 }).map((_, index) => (
        <TableRow key={index}>
          {Array.from({ length: columns }).map((__, cellIndex) => (
            <TableCell key={cellIndex}>
              <Skeleton className="h-4 w-full max-w-32" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </TableBody>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title="إعدادات المخاطر"
        subtitle="إدارة أنواع المخاطر وأنواع الأثر المستخدمة في تسجيل المخاطر"
        icon={IconSettings}
      />

      <Tabs defaultValue="risk-types">
        <TabsList>
          <TabsTrigger value="risk-types" icon={<IconAlertOctagon className="h-4 w-4" />}>
            أنواع المخاطر
          </TabsTrigger>
          <TabsTrigger value="impact-types" icon={<IconSettings className="h-4 w-4" />}>
            أنواع الأثر
          </TabsTrigger>
          <TabsTrigger value="governing" icon={<IconSitemap className="h-4 w-4" />}>
            القسم الحاكم
          </TabsTrigger>
        </TabsList>

        <TabsContent value="risk-types">
      <Card className="p-0 border border-[var(--border-default)] overflow-hidden">
        <div className="flex flex-col gap-3 px-4 py-4 border-b border-[var(--border-default)] sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div className="flex items-center gap-2">
            <IconAlertOctagon className="h-5 w-5 text-[var(--accent-default)]" />
            <div>
              <h2 className="text-base font-semibold text-[var(--text-primary)]">أنواع المخاطر</h2>
              <p className="text-sm text-[var(--text-secondary)]">تظهر الأنواع النشطة في نموذج تسجيل الخطر.</p>
            </div>
          </div>
          <Button onClick={openCreateRiskType} leftIcon={<IconPlus className="h-4 w-4" />}>
            إضافة نوع
          </Button>
        </div>

        <div className="overflow-x-auto">
          <Table hoverable>
            <TableHeader>
              <TableRow>
                <TableHead>التسمية</TableHead>
                <TableHead>القيمة</TableHead>
                <TableHead>الترتيب</TableHead>
                <TableHead>الحالة</TableHead>
                <TableHead className="w-36 text-center">الإجراءات</TableHead>
              </TableRow>
            </TableHeader>
            {isLoading ? (
              renderLoadingRows(5)
            ) : (
              <TableBody>
                {riskTypes.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} className="py-10 text-center text-[var(--text-secondary)]">
                      لا توجد أنواع مخاطر بعد.
                    </TableCell>
                  </TableRow>
                ) : (
                  riskTypes.map((item) => (
                    <TableRow key={item.id}>
                      <TableCell className="font-medium">{item.label}</TableCell>
                      <TableCell>{String(item.value)}</TableCell>
                      <TableCell>{item.sort_order}</TableCell>
                      <TableCell>{renderStatus(item.is_active)}</TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-2">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => openEditRiskType(item)}
                            leftIcon={<IconEdit className="h-4 w-4" />}
                          >
                            تعديل
                          </Button>
                          <Button
                            type="button"
                            variant="danger"
                            size="sm"
                            onClick={() => setDeleteModal({ open: true, item, type: "risk" })}
                            leftIcon={<IconTrash className="h-4 w-4" />}
                            aria-label={`حذف ${item.label}`}
                          >
                            {""}
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            )}
          </Table>
        </div>
      </Card>
        </TabsContent>

        <TabsContent value="impact-types">
      <Card className="p-0 border border-[var(--border-default)] overflow-hidden">
        <div className="flex flex-col gap-3 px-4 py-4 border-b border-[var(--border-default)] sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div className="flex items-center gap-2">
            <IconSettings className="h-5 w-5 text-[var(--accent-default)]" />
            <div>
              <h2 className="text-base font-semibold text-[var(--text-primary)]">أنواع الأثر</h2>
              <p className="text-sm text-[var(--text-secondary)]">تظهر الأنواع النشطة في قائمة الآثار المتوقعة.</p>
            </div>
          </div>
          <Button onClick={openCreateImpactType} leftIcon={<IconPlus className="h-4 w-4" />}>
            إضافة نوع أثر
          </Button>
        </div>

        <div className="overflow-x-auto">
          <Table hoverable>
            <TableHeader>
              <TableRow>
                <TableHead>التسمية</TableHead>
                <TableHead>القيمة</TableHead>
                <TableHead>الترتيب</TableHead>
                <TableHead>الحالة</TableHead>
                <TableHead className="w-36 text-center">الإجراءات</TableHead>
              </TableRow>
            </TableHeader>
            {isLoading ? (
              renderLoadingRows(5)
            ) : (
              <TableBody>
                {impactTypes.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} className="py-10 text-center text-[var(--text-secondary)]">
                      لا توجد أنواع أثر بعد.
                    </TableCell>
                  </TableRow>
                ) : (
                  impactTypes.map((item) => (
                    <TableRow key={item.id}>
                      <TableCell className="font-medium">{item.label}</TableCell>
                      <TableCell>{String(item.value)}</TableCell>
                      <TableCell>{item.sort_order}</TableCell>
                      <TableCell>{renderStatus(item.is_active)}</TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-2">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => openEditImpactType(item)}
                            leftIcon={<IconEdit className="h-4 w-4" />}
                          >
                            تعديل
                          </Button>
                          <Button
                            type="button"
                            variant="danger"
                            size="sm"
                            onClick={() => setDeleteModal({ open: true, item, type: "impact" })}
                            leftIcon={<IconTrash className="h-4 w-4" />}
                            aria-label={`حذف ${item.label}`}
                          >
                            {""}
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            )}
          </Table>
        </div>
      </Card>
        </TabsContent>

        <TabsContent value="governing">
          <RiskGoverningDepartmentSection />
        </TabsContent>
      </Tabs>

      <Modal
        isOpen={riskTypeModal.open}
        onClose={closeRiskTypeModal}
        title={riskTypeModal.item ? "تعديل نوع الخطر" : "إضافة نوع خطر"}
        size="md"
      >
        <ModalBody>
          <div className="space-y-4">
            <Input
              label="القيمة"
              value={riskTypeForm.value}
              onChange={(event) => {
                setRiskTypeForm((prev) => ({ ...prev, value: event.target.value }));
                if (riskTypeErrors.value) setRiskTypeErrors((prev) => ({ ...prev, value: undefined }));
              }}
              error={riskTypeErrors.value}
              required
              placeholder="مثال: operational"
            />
            <Input
              label="التسمية"
              value={riskTypeForm.label}
              onChange={(event) => {
                setRiskTypeForm((prev) => ({ ...prev, label: event.target.value }));
                if (riskTypeErrors.label) setRiskTypeErrors((prev) => ({ ...prev, label: undefined }));
              }}
              error={riskTypeErrors.label}
              required
              placeholder="مثال: تشغيلي"
            />
            <Input
              label="الترتيب"
              type="number"
              value={riskTypeForm.sort_order}
              onChange={(event) => setRiskTypeForm((prev) => ({ ...prev, sort_order: event.target.value }))}
            />
            <Switch
              label="نشط"
              description="الأنواع النشطة فقط تظهر في نموذج تسجيل الخطر."
              checked={riskTypeForm.is_active}
              onChange={(event) => setRiskTypeForm((prev) => ({ ...prev, is_active: event.target.checked }))}
            />
          </div>
        </ModalBody>
        <ModalFooter>
          <Button type="button" variant="outline" onClick={closeRiskTypeModal} disabled={isSavingRiskType}>
            إلغاء
          </Button>
          <Button type="button" onClick={saveRiskType} loading={isSavingRiskType}>
            حفظ
          </Button>
        </ModalFooter>
      </Modal>

      <Modal
        isOpen={impactModal.open}
        onClose={closeImpactModal}
        title={impactModal.item ? "تعديل نوع الأثر" : "إضافة نوع أثر"}
        size="md"
      >
        <ModalBody>
          <div className="space-y-4">
            <Input
              label="القيمة"
              value={impactForm.value}
              onChange={(event) => {
                setImpactForm((prev) => ({ ...prev, value: event.target.value }));
                if (impactErrors.value) setImpactErrors((prev) => ({ ...prev, value: undefined }));
              }}
              error={impactErrors.value}
              required
              placeholder="مثال: operational"
            />
            <Input
              label="التسمية"
              value={impactForm.label}
              onChange={(event) => {
                setImpactForm((prev) => ({ ...prev, label: event.target.value }));
                if (impactErrors.label) setImpactErrors((prev) => ({ ...prev, label: undefined }));
              }}
              error={impactErrors.label}
              required
              placeholder="مثال: مالي"
            />
            <Input
              label="الترتيب"
              type="number"
              value={impactForm.sort_order}
              onChange={(event) => setImpactForm((prev) => ({ ...prev, sort_order: event.target.value }))}
            />
            <Switch
              label="نشط"
              description="أنواع الأثر النشطة فقط تظهر في نموذج تسجيل الخطر."
              checked={impactForm.is_active}
              onChange={(event) => setImpactForm((prev) => ({ ...prev, is_active: event.target.checked }))}
            />
          </div>
        </ModalBody>
        <ModalFooter>
          <Button type="button" variant="outline" onClick={closeImpactModal} disabled={isSavingImpact}>
            إلغاء
          </Button>
          <Button type="button" onClick={saveImpactType} loading={isSavingImpact}>
            حفظ
          </Button>
        </ModalFooter>
      </Modal>

      <DeleteConfirmationModal
        isOpen={deleteModal.open}
        item={deleteModal.item}
        onClose={() => {
          if (!isDeleting) setDeleteModal({ open: false, item: null, type: null });
        }}
        onConfirm={deleteSetting}
        title="تأكيد الحذف"
        itemName={deleteModal.item?.label ?? ""}
        itemSubtitle={deleteModal.type === "impact" ? "نوع أثر" : "نوع خطر"}
        warningMessage={`لا يمكن التراجع عن الحذف، وقد يرفض النظام حذف ${deleteModal.type === "impact" ? "نوع الأثر" : "نوع الخطر"} إذا كان مستخدماً في مخاطر سابقة.`}
        confirmButtonText="حذف"
        isDeleting={isDeleting}
      />
    </div>
  );
};

export default RiskSettingsPage;
