import React, {
	createContext,
	useContext,
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from "react";
import { authApi } from '@shared/api/auth';
import { api } from '@shared/api/client';
import {
	canAccessCompat,
	hasPermissionCompat,
} from '@shared/api/access-bridge';
import type { User } from "@shared/types";

// إعدادات الوصول للموديولات
export interface AccessConfig {
	permission?: string;
	permissions?: string[];
	allPermissions?: string[];
	roles?: string[];
}

// نتيجة تسجيل الدخول
export interface LoginResult {
	success: boolean;
	requiresTwoFactor?: boolean;
	pendingToken?: string;
	userId?: number;
	userName?: string;
}

interface AuthContextType {
	user: User | null;
	isLoading: boolean;
	isAuthenticated: boolean;
	login: (email: string, password: string) => Promise<LoginResult>;
	logout: () => Promise<void>;
	refreshUser: () => Promise<void>;
	hasRole: (role: string) => boolean;
	hasAnyRole: (roles: string[]) => boolean;
	hasPermission: (permission: string) => boolean;
	hasAnyPermission: (permissions: string[]) => boolean;
	isSuperAdmin: () => boolean;
	isAdmin: () => boolean;
	canAccess: (config: AccessConfig) => boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

// الصفحات العامة التي لا تحتاج مصادقة
const PUBLIC_PATHS = [
	"/login",
	"/register",
	"/forgot-password",
	"/verify-2fa",
	"/s/",
	"/design-system",
];

// التحقق مما إذا كان المسار الحالي عاماً
function isPublicPath(path: string): boolean {
	return PUBLIC_PATHS.some((publicPath) => path.startsWith(publicPath));
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
	const initialPath =
		typeof window !== "undefined" ? window.location.pathname : "/";
	const isInitialPublicPath = isPublicPath(initialPath);

	const [user, setUser] = useState<User | null>(null);
	// على الصفحات العامة لا نحتاج تحميل، وإلا نبدأ بالتحميل
	const [isLoading, setIsLoading] = useState(!isInitialPublicPath);

	// منع الاستدعاءات المتكررة
	const isLoadingRef = useRef(false);
	const hasAttemptedLoadRef = useRef(isInitialPublicPath);

	const loadUser = useCallback(async (force = false) => {
		const currentPath = window.location.pathname;

		// منع الاستدعاءات المتزامنة
		if (isLoadingRef.current) {
			return;
		}

		// على الصفحات العامة، لا نحتاج لتحميل المستخدم أبداً
		if (isPublicPath(currentPath) && !force) {
			hasAttemptedLoadRef.current = true;
			return;
		}

		// إذا حاولنا التحميل مسبقاً (سواء نجح أو فشل)، لا نعيد المحاولة
		if (hasAttemptedLoadRef.current && !force) {
			return;
		}

		isLoadingRef.current = true;
		hasAttemptedLoadRef.current = true;

		try {
			const response = await authApi.getUser();
			setUser(response.user);
			api.setAuthenticated(true);
		} catch {
			// فشل - نمسح حالة المصادقة
			api.clearAuth();
			setUser(null);
		} finally {
			setIsLoading(false);
			isLoadingRef.current = false;
		}
	}, []);

	// تحميل المستخدم مرة واحدة فقط عند mount
	useEffect(() => {
		loadUser();
	}, [loadUser]);

	const login = useCallback(async (
		email: string,
		password: string,
	): Promise<LoginResult> => {
		const response = await authApi.login(email, password);

		if (response.requires_2fa && response.pending_token) {
			api.clearAuth();
			setUser(null);

			return {
				success: false,
				requiresTwoFactor: true,
				pendingToken: response.pending_token,
				userId: response.user.id,
				userName: response.user.name,
			};
		}

		api.setAuthenticated(true);
		setUser(response.user as User);

		return { success: true };
	}, []);

	const logout = useCallback(async () => {
		try {
			await authApi.logout();
		} finally {
			// مسح حالة المصادقة - الخادم يمسح الـ Cookie
			api.clearAuth();
			setUser(null);
		}
	}, []);

	const refreshUser = useCallback(async () => {
		await loadUser(true);
	}, [loadUser]);

	// التحقق من دور واحد
	const hasRole = useCallback((role: string) => {
		if (user?.roles.includes("super_admin")) return true;
		return user?.roles.includes(role) || false;
	}, [user]);

	// التحقق من أي دور من قائمة أدوار
	const hasAnyRole = useCallback((roles: string[]) => {
		if (user?.roles.includes("super_admin")) return true;
		return roles.some((role) => user?.roles.includes(role));
	}, [user]);

	// التحقق من صلاحية واحدة
	// Two-tier ability contract:
	//  - hasPermission / hasAnyPermission / canAccess read the GENERIC
	//    capability list at auth/me (user.permissions) via the central
	//    access-bridge. The bridge prefers `user.access` and falls back to
	//    `user.permissions[]` for stale sessions (Phase 9 cleanup freeze
	//    has not completed yet). Use these for menu items, top-level
	//    buttons, and any "do I have this permission at all" check.
	//  - For per-record decisions ("can I edit THIS project / THIS task"),
	//    always read element.abilities.* straight from the resource response.
	//    Do NOT infer record-level access from the generic permission list,
	//    because AccessDecision::can against a record consults the scope
	//    chain, inline element roles, and the lifecycle-gated owner floor —
	//    none of which are reflected in user.permissions.
	const hasPermission = useCallback(
		(permission: string) => hasPermissionCompat(user, permission),
		[user],
	);

	// التحقق من أي صلاحية من قائمة صلاحيات
	const hasAnyPermission = useCallback(
		(permissions: string[]) =>
			permissions.some((permission) => hasPermissionCompat(user, permission)),
		[user],
	);

	// هل المستخدم Super Admin
	const isSuperAdmin = useCallback(() => {
		return user?.roles.includes("super_admin") || false;
	}, [user]);

	// تير الإدارة — مقاد بصلاحية manage_organization لا باسم الدور (super_admin يتجاوز).
	// الـ bridge يضمن الرجوع إلى permissions[] كـ fallback أثناء نافذة التوافق.
	const isAdmin = useCallback(
		() => hasPermissionCompat(user, "manage_organization"),
		[user],
	);

	// دالة موحدة للتحقق من إمكانية الوصول
	const canAccess = useCallback(
		(config: AccessConfig): boolean => canAccessCompat(user, config),
		[user],
	);

	const value = useMemo<AuthContextType>(
		() => ({
			user,
			isLoading,
			isAuthenticated: !!user,
			login,
			logout,
			refreshUser,
			hasRole,
			hasAnyRole,
			hasPermission,
			hasAnyPermission,
			isSuperAdmin,
			isAdmin,
			canAccess,
		}),
		[
			user,
			isLoading,
			login,
			logout,
			refreshUser,
			hasRole,
			hasAnyRole,
			hasPermission,
			hasAnyPermission,
			isSuperAdmin,
			isAdmin,
			canAccess,
		],
	);

	return (
		<AuthContext.Provider value={value}>
			{children}
		</AuthContext.Provider>
	);
}

export function useAuth() {
	const context = useContext(AuthContext);
	if (context === undefined) {
		throw new Error("useAuth must be used within an AuthProvider");
	}
	return context;
}
