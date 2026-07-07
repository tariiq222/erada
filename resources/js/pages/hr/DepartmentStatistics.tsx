import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import {IconBuilding} from '@tabler/icons-react';
import { departmentsApi } from '@entities/hr';
import { Skeleton } from "@shared/ui";
import { PageHeader, StatStrip } from "@shared/ui";

const DepartmentStatistics: React.FC = () => {
    const { t } = useTranslation();
    const [departments, setDepartments] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const result: any = await departmentsApi.getList();
                const list = Array.isArray(result)
                    ? result
                    : (result?.data ?? []);
                setDepartments(list);
            } catch (error) {
                console.error("Failed to fetch department statistics:", error);
            } finally {
                setLoading(false);
            }
        };
        fetchData();
    }, []);

    if (loading) {
        return (
            <div className="space-y-6">
                <div className="space-y-2">
                    <Skeleton className="h-7 w-64" variant="rounded" />
                    <Skeleton className="h-4 w-80" variant="rounded" />
                </div>
                <Skeleton className="h-20 w-full" variant="rounded" />
            </div>
        );
    }

    const total = departments.length;
    const active = departments.filter((d) => d?.is_active).length;
    const inactive = total - active;
    const topLevel = departments.filter((d) => !d?.parent_id).length;

    return (
        <div className="space-y-6">
            <PageHeader
                title={t("hr.departments_statisticsTitle")}
                subtitle={t("hr.departments_subtitle")}
                icon={IconBuilding}
                iconTone="admin"
            />

            <StatStrip
                items={[
                    {
                        label: t("hr.total_departments"),
                        value: total,
                        tone: "neutral",
                    },
                    {
                        label: t("hr.active_departments"),
                        value: active,
                        tone: "success",
                    },
                    {
                        label: t("hr.inactive_departments"),
                        value: inactive,
                        tone: "danger",
                    },
                    {
                        label: t("hr.top_level_departments"),
                        value: topLevel,
                        tone: "accent",
                    },
                ]}
            />
        </div>
    );
};

export default DepartmentStatistics;
