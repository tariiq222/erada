import { useMemo } from 'react';
import { useAuth } from '@shared/contexts/AuthContext';
import type { AccessMap, User } from '@shared/types';

const CANONICAL_CAPABILITY = /^[a-z_]+(?:\.[a-z_]+)+$/;

export function canUser(
	user: User | null | undefined,
	capability: string,
): boolean {
	if (!user || !CANONICAL_CAPABILITY.test(capability)) {
		return false;
	}

	return user.access?.[capability] === true;
}

export interface AccessRequirement {
	capability?: string;
	anyCapabilities?: string[];
	allCapabilities?: string[];
}

export function meetsAccessRequirement(
	can: (capability: string) => boolean,
	requirement: AccessRequirement,
): boolean {
	if (requirement.allCapabilities?.some((capability) => !can(capability))) {
		return false;
	}

	const selectors = [
		...(requirement.capability ? [requirement.capability] : []),
		...(requirement.anyCapabilities ?? []),
	];

	return selectors.length > 0
		? selectors.some((capability) => can(capability))
		: Boolean(requirement.allCapabilities?.length);
}

export function useAccess(): AccessMap {
	const { user } = useAuth();

	return useMemo<AccessMap>(() => user?.access ?? {}, [user?.access]);
}

/**
 * Module-level canonical capability check. Record decisions remain server-owned
 * and must be read from the resource's `abilities` payload.
 */
export function useCan(capability: string): boolean {
	const { user } = useAuth();
	return canUser(user, capability);
}
