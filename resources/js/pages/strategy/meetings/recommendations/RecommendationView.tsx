import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  Breadcrumb,
  Button,
  DeleteConfirmationModal,
  Skeleton,
  Card,
  CardContent,
} from '@shared/ui';
import { IconEdit, IconTrash } from '@shared/ui/icons';
import { useRecommendationView, RecommendationOverview, RecommendationStatusActions } from './view';

const RecommendationViewSkeleton: React.FC = () => (
  <div className="space-y-4">
    <Skeleton className="h-6 w-48" />
    <Skeleton className="h-8 w-72" />
    <Card>
      <CardContent className="space-y-3 p-6">
        <Skeleton className="h-4 w-32" />
        <Skeleton className="h-20 w-full" />
      </CardContent>
    </Card>
  </div>
);

const RecommendationView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const { recommendation, loading, deleting, remove, approve, accept, reject, defer, complete } =
    useRecommendationView(id);
  const [showDelete, setShowDelete] = useState(false);

  if (loading || !recommendation) return <RecommendationViewSkeleton />;

  const allowed = recommendation.allowed_actions;
  const canEdit = allowed?.update ?? false;
  const canDelete = allowed?.delete ?? false;
  const canApprove = allowed?.approve ?? false;
  const canAccept = allowed?.accept ?? false;
  const canReject = allowed?.reject ?? false;
  const canDefer = allowed?.defer ?? false;
  const canComplete = allowed?.complete ?? false;

  return (
    <div className="space-y-4">
      <Breadcrumb
        items={[
          {
            label: t('meetings.recommendation.list.header'),
            href: '/strategy/meetings/recommendations',
          },
          { label: `#${recommendation.id}` },
        ]}
      />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-[var(--text-primary)]">
          {recommendation.title}
        </h1>
        <div className="flex items-center gap-2">
          {canEdit && (
            <Link to={`/strategy/meetings/recommendations/${recommendation.id}/edit`}>
              <Button variant="outline" size="sm" leftIcon={<IconEdit className="h-4 w-4" />}>
                {t('meetings.recommendation.detail.edit_button')}
              </Button>
            </Link>
          )}
          {canDelete && (
            <Button
              variant="danger"
              size="sm"
              leftIcon={<IconTrash className="h-4 w-4" />}
              onClick={() => setShowDelete(true)}
            >
              {t('meetings.recommendation.detail.delete_button')}
            </Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <RecommendationOverview recommendation={recommendation} />
        </div>
        <div>
          <RecommendationStatusActions
            recommendation={recommendation}
            canApprove={canApprove}
            canAccept={canAccept}
            canReject={canReject}
            canDefer={canDefer}
            canComplete={canComplete}
            onApprove={approve}
            onAccept={accept}
            onReject={reject}
            onDefer={defer}
            onComplete={complete}
          />
        </div>
      </div>

      <DeleteConfirmationModal
        isOpen={showDelete}
        item={recommendation}
        onClose={() => setShowDelete(false)}
        onConfirm={remove}
        itemName={recommendation.title}
        title={t('meetings.recommendation.detail.delete_confirm')}
        warningMessage={t('meetings.recommendation.detail.delete_confirm')}
        isDeleting={deleting}
      />
    </div>
  );
};

export default RecommendationView;
