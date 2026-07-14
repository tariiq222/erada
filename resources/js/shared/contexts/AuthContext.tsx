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
import type {
	LoginResponse,
	LoginSuccessPayload,
	LoginTwoFactorChallenge,
} from '@shared/api/types';

/**
 * Type guard for the backend's 2FA challenge wire shape. Mirrors the
 * source of truth — see app/Modules/Core/Http/Controllers/AuthController.php
 * (response keys: `two_factor_required`, `user_id`, `pending_token`).
 */
function isTwoFactorChallenge(
	response: LoginResponse,
): response is LoginTwoFactorChallenge {
	return (
		(response as { two_factor_required?: unknown }).two_factor_required === true
	);
}

// نتيجة تسجيل الدخول
//
// Returned by `useAuth().login()` after awaiting `POST /api/login`.
// The HttpOnly `auth_token` cookie is set by the backend on either a
// normal login success or a successful /api/2fa/verify; we must not
// mark the user as authenticated before either of those events.
//
// Fields:
//   - success=true                    → user is authenticated, AuthContext
//                                       has promoted the user state and
//                                       `isAuthenticated` is now true.
//   - success=false + requiresTwoFactor → backend reports that the user is
//                                       2FA-confirmed but the second
//                                       factor has not yet been supplied.
//                                       No auth_token cookie was issued;
//                                       the caller must route to
//                                       /verify-2fa with the `pendingToken`.
//   - success=false (no 2FA fields)   → credentials were rejected.
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
		// Wire shape comes from app/Modules/Core/Http/Controllers/AuthController
		// (`/api/login`). When 2FA is required, the response is
		// { two_factor_required: true, user_id, pending_token, message } and the
		// backend deliberately issues NO Sanctum token and NO `auth_token`
		// cookie — the only path to a session is /api/2fa/verify. We must
		// surface that contract verbatim to the caller and never mark the user
		// as authenticated on the password-only step.
		const response = await authApi.login(email, password);

		// 2FA challenge branch: backend has accepted the password but
		// withheld the auth_token cookie until /api/2fa/verify completes.
		// The single source-of-truth discriminant for the wire shape is
		// `two_factor_required`; the union guard narrows to the challenge
		// payload below.
		const challenge = isTwoFactorChallenge(response) ? response : null;
		if (challenge) {
			return {
				success: false,
				requiresTwoFactor: true,
				pendingToken: challenge.pending_token,
				userId: challenge.user_id,
				userName: undefined,
			};
		}

		// Normal login success. The HttpOnly `auth_token` cookie was set by
		// the backend; we mirror that into the in-memory auth flag and stash
		// the user projection so isAuthenticated flips immediately. `setToken`
		// is a legacy compatibility shim — body tokens are no longer returned.
		const successResponse = response as LoginSuccessPayload;
		if (successResponse.token) {
			api.setToken(successResponse.token);
		}
		api.setAuthenticated(true);
		setUser(successResponse.user as User);

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
