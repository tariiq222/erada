import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetUser = vi.fn();
const mockClearAuth = vi.fn();
const mockSetAuthenticated = vi.fn();

vi.mock('@shared/api/auth', () => ({
	authApi: {
		getUser: () => mockGetUser(),
		login: vi.fn(),
		logout: vi.fn(),
	},
}));

vi.mock('@shared/api/client', () => ({
	api: {
		clearAuth: () => mockClearAuth(),
		setAuthenticated: (authenticated: boolean) =>
			mockSetAuthenticated(authenticated),
		setToken: vi.fn(),
	},
}));

import { AuthProvider, useAuth } from '@shared/contexts/AuthContext';

function AuthProbe() {
	const { can, refreshUser, user } = useAuth();

	return (
		<>
			<div data-testid="user-name">{user?.name ?? 'signed-out'}</div>
			<div data-testid="can-edit">
				{can('projects.edit') ? 'yes' : 'no'}
			</div>
			<button type="button" onClick={() => void refreshUser()}>
				Refresh
			</button>
		</>
	);
}

const userResponse = (name: string, canEdit: boolean) => ({
	user: {
		id: 1,
		name,
		email: 'user@example.test',
		department_id: null,
		phone: null,
		extension: null,
		job_title: null,
		is_active: true,
		access: canEdit ? { 'projects.edit': true as const } : {},
	},
});

describe('AuthContext refreshUser', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		window.history.replaceState({}, '', '/projects');
	});

	it('bypasses the initial-load dedupe and replaces capabilities', async () => {
		mockGetUser
			.mockResolvedValueOnce(userResponse('Before refresh', false))
			.mockResolvedValueOnce(userResponse('After refresh', true));

		render(
			<AuthProvider>
				<AuthProbe />
			</AuthProvider>,
		);

		await waitFor(() => {
			expect(screen.getByTestId('user-name')).toHaveTextContent('Before refresh');
		});
		expect(screen.getByTestId('can-edit')).toHaveTextContent('no');

		await act(async () => {
			screen.getByRole('button', { name: 'Refresh' }).click();
		});

		await waitFor(() => {
			expect(screen.getByTestId('user-name')).toHaveTextContent('After refresh');
			expect(screen.getByTestId('can-edit')).toHaveTextContent('yes');
		});
		expect(mockGetUser).toHaveBeenCalledTimes(2);
	});

	it('clears stale elevated access when the forced refresh fails', async () => {
		mockGetUser
			.mockResolvedValueOnce(userResponse('Privileged user', true))
			.mockRejectedValueOnce(new Error('Unauthorized'));

		render(
			<AuthProvider>
				<AuthProbe />
			</AuthProvider>,
		);

		await waitFor(() => {
			expect(screen.getByTestId('can-edit')).toHaveTextContent('yes');
		});

		await act(async () => {
			screen.getByRole('button', { name: 'Refresh' }).click();
		});

		await waitFor(() => {
			expect(screen.getByTestId('user-name')).toHaveTextContent('signed-out');
			expect(screen.getByTestId('can-edit')).toHaveTextContent('no');
		});
		expect(mockClearAuth).toHaveBeenCalledOnce();
	});
});
