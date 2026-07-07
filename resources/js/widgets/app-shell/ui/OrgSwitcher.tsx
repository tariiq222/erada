import React, { useState, useRef, useEffect, useCallback } from "react";
import { createPortal } from "react-dom";
import { useTranslation } from "react-i18next";
import { useOrganization } from "@shared/contexts/OrganizationContext";
import {IconBuilding, IconChevronDown, IconCheck} from '@tabler/icons-react';

export const OrgSwitcher: React.FC = () => {
	const { t } = useTranslation();
	const {
		organizations,
		currentOrganization,
		switchOrganization,
		hasMultipleOrganizations,
		isLoading,
	} = useOrganization();
	const [open, setOpen] = useState(false);
	const ref = useRef<HTMLDivElement>(null);
	const buttonRef = useRef<HTMLButtonElement>(null);
	const menuRef = useRef<HTMLDivElement>(null);
	const [menuStyle, setMenuStyle] = useState<React.CSSProperties>({});

	// Portal to <body> + fixed positioning so the menu escapes any
	// overflow-hidden ancestor instead of being clipped.
	const positionMenu = useCallback(() => {
		if (!buttonRef.current) return;
		const rect = buttonRef.current.getBoundingClientRect();
		const gap = 4;
		setMenuStyle({
			position: "fixed",
			top: rect.bottom + gap,
			right: window.innerWidth - rect.right,
			zIndex: 9999,
		});
	}, []);

	const openMenu = () => {
		positionMenu();
		setOpen(true);
	};

	useEffect(() => {
		const handleClick = (e: MouseEvent) => {
			const target = e.target as Node;
			const inTrigger = ref.current?.contains(target);
			const inMenu = menuRef.current?.contains(target);
			if (!inTrigger && !inMenu) {
				setOpen(false);
			}
		};
		document.addEventListener("mousedown", handleClick);
		return () => document.removeEventListener("mousedown", handleClick);
	}, []);

	useEffect(() => {
		if (!open) return;
		window.addEventListener("resize", positionMenu);
		window.addEventListener("scroll", positionMenu, true);
		return () => {
			window.removeEventListener("resize", positionMenu);
			window.removeEventListener("scroll", positionMenu, true);
		};
	}, [open, positionMenu]);

	if (isLoading || !currentOrganization) return null;
	if (!hasMultipleOrganizations) {
		return (
			<div className="hidden md:flex items-center gap-2 px-3 py-1 text-sm text-[var(--text-secondary)]">
				<IconBuilding className="w-4 h-4" />
				<span className="font-medium">{currentOrganization.name}</span>
			</div>
		);
	}

	return (
		<div className="relative" ref={ref}>
			<button
				ref={buttonRef}
				type="button"
				onClick={() => (open ? setOpen(false) : openMenu())}
				className="flex items-center gap-2 px-3 py-1 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] hover:bg-[var(--bg-hover)] text-sm"
			>
				<IconBuilding className="w-4 h-4 text-[var(--accent-default)]" />
				<span className="font-medium max-w-[160px] truncate">
					{currentOrganization.name}
				</span>
				<IconChevronDown className="w-3.5 h-3.5 text-[var(--text-secondary)]" />
			</button>
			{open && createPortal(
				<div ref={menuRef} style={menuStyle} className="w-64 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] shadow-lg overflow-hidden">
					<div className="p-2 text-xs font-semibold text-[var(--text-secondary)] uppercase border-b border-[var(--border)]">
						{t("org.switch")}
					</div>
					<div className="max-h-64 overflow-y-auto">
						{organizations.map((org) => (
							<button
								key={org.id}
								type="button"
								onClick={() => switchOrganization(org.id)}
								className="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm hover:bg-[var(--bg-hover)] text-start"
							>
								<div className="flex-1 min-w-0">
									<div className="font-medium truncate">{org.name}</div>
									<div className="text-xs text-[var(--text-secondary)] font-mono">
										{org.code}
									</div>
								</div>
								{org.id === currentOrganization.id && (
									<IconCheck className="w-4 h-4 text-[var(--accent-default)] flex-shrink-0" />
								)}
							</button>
						))}
					</div>
				</div>,
				document.body
			)}
		</div>
	);
};

export default OrgSwitcher;
