import React, { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import {IconUsers} from '@tabler/icons-react';
import { usersApi } from '@entities/user';
import { Skeleton } from "@shared/ui";
import { PageHeader, StatStrip } from "@shared/ui";

const UserStatistics: React.FC = () => {
    const { t } = useTranslation();
    const [data, setData] = useState<any>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const result: any = await usersApi.getStats();
                setData(result);
            } catch (error) {
                console.error("Failed to fetch user statistics:", error);
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

    return (
        <div className="space-y-6">
            <PageHeader
                title={t("users.statisticsTitle")}
                subtitle={t("users.subtitle")}
                icon={IconUsers}
                iconTone="admin"
            />

            <StatStrip
                items={[
                    {
                        label: t("users.total"),
                        value: data?.total ?? 0,
                        tone: "neutral",
                    },
                    {
                        label: t("users.active"),
                        value: data?.active ?? 0,
                        tone: "success",
                    },
                    {
                        label: t("users.inactive"),
                        value: data?.inactive ?? 0,
                        tone: "danger",
                    },
                    {
                        label: t("users.admins"),
                        value: data?.admins ?? 0,
                        tone: "accent",
                    },
                ]}
            />
        </div>
    );
};

export default UserStatistics;
