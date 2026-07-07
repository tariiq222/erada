import React from 'react';
import AuditLogTab from './AuditLogTab';

interface AuditTabProps {
  reportId: string | number;
}

/**
 * Thin wrapper that re-uses the existing AuditLogTab inside the lazy-loaded
 * IncidentView page tab slot. Kept as a separate file so the page-level
 * code-splitting boundary stays stable.
 */
const IncidentViewAuditTab: React.FC<AuditTabProps> = ({ reportId }) => {
  return <AuditLogTab reportId={reportId} />;
};

export default IncidentViewAuditTab;