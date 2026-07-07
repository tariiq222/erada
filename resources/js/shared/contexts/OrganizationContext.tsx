import React, {
	createContext,
	useContext,
	useState,
	useEffect,
	useCallback,
	useMemo,
	ReactNode,
} from "react";
import { authApi } from '@shared/api/auth';
import { api } from '@shared/api/client';

export interface OrganizationInfo {
	id: number;
	name: string;
	code: string;
	is_active: boolean;
}

interface OrganizationContextType {
	organizations: OrganizationInfo[];
	currentOrganization: OrganizationInfo | null;
	setCurrentOrganization: (org: OrganizationInfo) => void;
	switchOrganization: (orgId: number) => Promise<void>;
	isLoading: boolean;
	hasMultipleOrganizations: boolean;
}

const STORAGE_KEY = "iradah:current_organization_id";

const OrganizationContext = createContext<OrganizationContextType | undefined>(
	undefined
);

export const OrganizationProvider: React.FC<{ children: ReactNode }> = ({
	children,
}) => {
	const [organizations, setOrganizations] = useState<OrganizationInfo[]>([]);
	const [currentOrganization, setCurrentOrg] =
		useState<OrganizationInfo | null>(null);
	const [isLoading, setIsLoading] = useState(true);

	useEffect(() => {
		(async () => {
			try {
				// Fetch current user; backend should include available organizations
				const userRes = (await authApi.getUser()) as any;
				const orgs: OrganizationInfo[] =
					userRes?.user?.organizations || userRes?.organizations || [];

				if (orgs.length === 0) {
					const fallback: OrganizationInfo = {
						id: userRes?.user?.organization_id || 1,
						name: "المؤسسة الافتراضية",
						code: "DEFAULT",
						is_active: true,
					};
					setOrganizations([fallback]);
					setCurrentOrg(fallback);
				} else {
					setOrganizations(orgs);
					const saved = localStorage.getItem(STORAGE_KEY);
					const matched = orgs.find((o) => String(o.id) === saved);
					setCurrentOrg(matched || orgs[0]);
				}
			} catch (err) {
				// Fallback to a default org so the app remains usable
				const fallback: OrganizationInfo = {
					id: 1,
					name: "المؤسسة الافتراضية",
					code: "DEFAULT",
					is_active: true,
				};
				setOrganizations([fallback]);
				setCurrentOrg(fallback);
			} finally {
				setIsLoading(false);
			}
		})();
	}, []);

	const setCurrentOrganization = useCallback((org: OrganizationInfo) => {
		setCurrentOrg(org);
		localStorage.setItem(STORAGE_KEY, String(org.id));
	}, []);

	const switchOrganization = useCallback(
		async (orgId: number) => {
			const target = organizations.find((o) => o.id === orgId);
			if (!target) return;

			try {
				// Best-effort: notify backend (when endpoint exists)
				await api.post("/auth/switch-org", { organization_id: orgId });
			} catch {
				// Endpoint not yet implemented; persist locally only
			}

			setCurrentOrg(target);
			localStorage.setItem(STORAGE_KEY, String(orgId));
			window.location.reload();
		},
		[organizations]
	);

	const hasMultipleOrganizations = organizations.length > 1;

	const value = useMemo<OrganizationContextType>(
		() => ({
			organizations,
			currentOrganization,
			setCurrentOrganization,
			switchOrganization,
			isLoading,
			hasMultipleOrganizations,
		}),
		[
			organizations,
			currentOrganization,
			setCurrentOrganization,
			switchOrganization,
			isLoading,
			hasMultipleOrganizations,
		],
	);

	return (
		<OrganizationContext.Provider value={value}>
			{children}
		</OrganizationContext.Provider>
	);
};

export const useOrganization = () => {
	const ctx = useContext(OrganizationContext);
	if (!ctx) {
		throw new Error(
			"useOrganization must be used within an OrganizationProvider"
		);
	}
	return ctx;
};
