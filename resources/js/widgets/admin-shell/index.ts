/**
 * AdminShell widget - public API.
 *
 * Super Admin Control Plane shell for /admin/*. Distinct from the regular
 * AppLayout (which renders the operational NASAQ sidebar and organization
 * switcher). This shell is read-mostly and exposes only technical controls.
 */
export { default as AdminLayout } from './ui/AdminLayout';
export { default as AdminSidebar } from './ui/AdminSidebar';
export { default as AdminHeader } from './ui/AdminHeader';