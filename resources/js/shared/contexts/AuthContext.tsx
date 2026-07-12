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
import { canUser } from '@shared/api/access';
import type { User } from "@shared/types";

// نتيجة تسجيل الدخول
export interface LoginResult {
	success: boolean;
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
	can: (capability: string) => boolean;
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
		if (isPublicPath(currentPath)) {
			hasAttemptedLoadRef.current = true;
			return;
		}

		// إذا حاولنا التحميل مسبقاً (سواء نجح أو فشل)، لا نعيد المحاولة
		if (!force && hasAttemptedLoadRef.current) {
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

		// الـ Token الآن يُرسل في HttpOnly Cookie من الخادم
		// نحتفظ بـ setToken للتوافق مع الإصدارات السابقة فقط
		if (response.token) {
			api.setToken(response.token);
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

	const can = useCallback(
		(capability: string): boolean => canUser(user, capability),
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
			can,
		}),
		[
			user,
			isLoading,
			login,
			logout,
			refreshUser,
			can,
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
