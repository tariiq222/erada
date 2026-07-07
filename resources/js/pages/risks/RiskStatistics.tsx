import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import {IconChartBar, IconChartPie, IconBarbell} from '@tabler/icons-react';
import { risksDashboardApi } from '@entities/risk';
import { Card, Skeleton } from "@shared/ui";
import { PageHeader, StatStrip } from "@shared/ui";
import { SectionHeader } from "@shared/ui/SectionHeader";

const STATUS_LABEL: Record<string, string> = {
    open: "مفتوح",
    treating: "قيد المعالجة",
    closed: "مغلق",
    accepted: "مقبول",
};

const LEVEL_LABEL: Record<string, string> = {
    low: "منخفض",
    medium: "متوسط",
    high: "عالٍ",
    critical: "حرج",
};

const RiskStatistics: React.FC = () => {
    const { t } = useTranslation();
    const [data, setData] = useState<any>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const result: any = await risksDashboardApi.get();
                setData(result);
            } catch (error) {
                console.error("Failed to fetch risk statistics:", error);
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
                <Card>
                    <Skeleton className="mb-3 h-5 w-40" variant="rounded" />
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-full" variant="rounded" />
                        <Skeleton className="h-4 w-3/4" variant="rounded" />
                        <Skeleton className="h-4 w-2/3" variant="rounded" />
                    </div>
                </Card>
                <Card>
                    <Skeleton className="mb-3 h-5 w-40" variant="rounded" />
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-full" variant="rounded" />
                        <Skeleton className="h-4 w-3/4" variant="rounded" />
                        <Skeleton className="h-4 w-2/3" variant="rounded" />
                    </div>
                </Card>
            </div>
        );
    }

    const totals = data?.totals ?? { all: 0, open: 0, overdue_actions: 0 };
    const by_status: Record<string, number> = data?.by_status ?? {};
    const by_level: Record<string, number> = data?.by_level ?? {};

    return (
        <div className="space-y-6">
            <PageHeader
                title={t("risks.statisticsTitle")}
                subtitle={t("risks.subtitle")}
                icon={IconChartBar}
                iconTone="risk"
            />

            <StatStrip
                items={[
                    {
                        label: t("risks.total"),
                        value: totals.all,
                        tone: "neutral",
                    },
                    {
                        label: t("risks.open"),
                        value: totals.open,
                        tone: "danger",
                    },
                    {
                        label: t("risks.overdueActions"),
                        value: totals.overdue_actions,
                        tone: "warning",
                    },
                ]}
            />

            <Card>
                <SectionHeader
                    title={t("risks.byStatus")}
                    level={3}
                    size="compact"
                    icon={IconChartPie}
                    iconTone="risk"
                    className="mb-3"
                />
                <div className="divide-y divide-[var(--border-default)]">
                    {Object.entries(by_status).map(([label, count]) => (
                        <div
                            key={label}
                            className="flex items-center justify-between py-2"
                        >
                            <span className="text-[length:var(--text-body)] text-[var(--text-secondary)]">
                                {STATUS_LABEL[label] ?? label}
                            </span>
                            <span className="text-[length:var(--text-body)] font-semibold tabular-nums text-[var(--text-primary)]">
                                {count}
                            </span>
                        </div>
                    ))}
                    {Object.keys(by_status).length === 0 && (
                        <div className="py-2 text-[length:var(--text-body)] text-[var(--text-secondary)]">
                            –
                        </div>
                    )}
                </div>
            </Card>

            <Card>
                <SectionHeader
                    title={t("risks.byLevel")}
                    level={3}
                    size="compact"
                    icon={IconBarbell}
                    iconTone="risk"
                    className="mb-3"
                />
                <div className="divide-y divide-[var(--border-default)]">
                    {Object.entries(by_level).map(([label, count]) => (
                        <div
                            key={label}
                            className="flex items-center justify-between py-2"
                        >
                            <span className="text-[length:var(--text-body)] text-[var(--text-secondary)]">
                                {LEVEL_LABEL[label] ?? label}
                            </span>
                            <span className="text-[length:var(--text-body)] font-semibold tabular-nums text-[var(--text-primary)]">
                                {count}
                            </span>
                        </div>
                    ))}
                    {Object.keys(by_level).length === 0 && (
                        <div className="py-2 text-[length:var(--text-body)] text-[var(--text-secondary)]">
                            –
                        </div>
                    )}
                </div>
            </Card>
        </div>
    );
};

export default RiskStatistics;
