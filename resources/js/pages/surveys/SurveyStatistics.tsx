import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import {IconClipboardList} from '@tabler/icons-react';
import { surveysApi } from '@entities/survey';
import { Card, Skeleton } from "@shared/ui";
import { PageHeader, StatStrip } from "@shared/ui";

const SurveyStatistics: React.FC = () => {
    const { t } = useTranslation();
    const [data, setData] = useState<any>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const result: any = await surveysApi.getStats();
                setData(result);
            } catch (error) {
                console.error("Failed to fetch survey statistics:", error);
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
                    </div>
                </Card>
            </div>
        );
    }

    const byType: Record<string, number> = data?.by_type ?? {};

    return (
        <div className="space-y-6">
            <PageHeader
                title={t("surveys.statisticsTitle")}
                subtitle={t("surveys.subtitle")}
                icon={IconClipboardList}
            />

            <StatStrip
                items={[
                    {
                        label: t("surveys.total"),
                        value: data?.total ?? 0,
                        tone: "neutral",
                    },
                    {
                        label: t("surveys.published"),
                        value: data?.published ?? 0,
                        tone: "success",
                    },
                    {
                        label: t("surveys.draft"),
                        value: data?.draft ?? 0,
                        tone: "warning",
                    },
                ]}
            />

            <Card>
                <h2 className="mb-3 text-[length:var(--text-small)] font-semibold text-[var(--text-primary)]">
                    {t("surveys.byType")}
                </h2>
                <div className="divide-y divide-[var(--border-default)]">
                    {Object.entries(byType).map(([label, count]) => (
                        <div
                            key={label}
                            className="flex items-center justify-between py-2"
                        >
                            <span className="text-[length:var(--text-body)] text-[var(--text-secondary)]">
                                {t(`surveys.type_${label}`, label)}
                            </span>
                            <span className="text-[length:var(--text-body)] font-semibold tabular-nums text-[var(--text-primary)]">
                                {count}
                            </span>
                        </div>
                    ))}
                    {Object.keys(byType).length === 0 && (
                        <div className="py-2 text-[length:var(--text-body)] text-[var(--text-secondary)]">
                            –
                        </div>
                    )}
                </div>
            </Card>
        </div>
    );
};

export default SurveyStatistics;
