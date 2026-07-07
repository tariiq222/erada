#!/usr/bin/env node
/**
 * rename-icons.mjs — single-shot migration to canonical @tabler/icons-react imports.
 *
 * For every .ts/.tsx under resources/js/**:
 *   1. Find imports from `@shared/ui/icons` (deleted shim) or `@tabler/icons-react`.
 *   2. For each entry in the import body:
 *        a. Skip if `type` keyword or already Icon-prefixed.
 *        b. Apply lucide→tabler alias mapping (Loader2 → Loader, Save → DeviceFloppy, …).
 *        c. Apply close-match fallback for names with no tabler equivalent
 *           (CheckCircle → CircleCheck, XCircle → CircleX, …).
 *        d. Prefix with `Icon` to produce the tabler canonical export name.
 *   3. Rewrite import source to `@tabler/icons-react` (shim → tabler) and rename entries.
 *   4. Build a per-file rename map (old name → new name) and apply to JSX tag positions
 *      (opening tag, self-closing, closing tag). Non-tag occurrences are left untouched.
 *
 * Skips: __tests__/route-mocks/ and __tests__/__mocks__/ (mock fixtures; preserved for safety).
 */
import { promises as fs } from "node:fs";
import { createRequire } from "node:module";
import path from "node:path";
import process from "node:process";

const ROOT = process.cwd();
const SCAN_DIR = path.join(ROOT, "resources/js");
const EXTS = new Set([".ts", ".tsx"]);
const SKIP_DIR_NAMES = new Set(["route-mocks", "__mocks__"]);

/* lucide name → tabler canonical bare name */
const ALIAS = Object.freeze({
	AtSign: "At",
	CalendarClock: "CalendarTime",
	DollarSign: "CurrencyDollar",
	Edit3: "Pencil",
	Film: "Movie",
	Globe: "World",
	Image: "Photo",
	ImageIcon: "Photo",
	KeyRound: "Key",
	Languages: "Language",
	Lightbulb: "Bulb",
	Link2: "Link",
	LinkIcon: "Link",
	ListChecks: "ListCheck",
	ListTodo: "ClipboardList",
	Loader2: "Loader",
	LogIn: "Login",
	Maximize2: "Maximize",
	MessageSquare: "Message",
	Milestone: "Flag",
	MilestoneIcon: "Flag",
	Minimize2: "Minimize",
	MoreHorizontal: "Dots",
	MoreVertical: "DotsVertical",
	Pause: "PlayerPause",
	PieChart: "ChartPie",
	PieChartIcon: "ChartPie",
	PlayCircle: "PlayerPlay",
	RefreshCw: "Refresh",
	RotateCcw: "RotateClockwise",
	Save: "DeviceFloppy",
	ScanSearch: "ScanEye",
	ScrollText: "Notebook",
	SettingsIcon: "Settings",
	Smartphone: "DeviceMobile",
	Timer: "ClockHour4",
	Trash2: "Trash",
	Unlock: "LockOpen",
	UserIcon: "User",
});

/* bare name that has no `Icon<Name>` in tabler → closest tabler bare name */
const FALLBACK = Object.freeze({
	BarChart3: "ChartBar",
	Building2: "Building",
	CheckCircle: "CircleCheck",
	CheckCircle2: "CircleCheck",
	CheckSquare: "SquareCheck",
	ChevronsUpDown: "Selector",
	FileJson: "Json",
	FolderKanban: "LayoutKanban",
	Info: "InfoCircle",
	Layer: "Stack2",
	Layers: "Stack2",
	LifeBuoy: "Lifebuoy",
	LogOut: "Logout",
	XCircle: "CircleX",
});

/* Build a reverse map: tabler bare name → list of lucide names that resolve to it.
   Used to seed the renameMap so value references like `icon={BarChart3}` get
   renamed to `IconChartBar` even when the import is `IconChartBar` (no bare form). */
const REVERSE_ALIAS = (() => {
	const map = new Map();
	for (const [lucide, tabler] of Object.entries(ALIAS)) {
		if (!map.has(tabler)) map.set(tabler, []);
		map.get(tabler).push(lucide);
	}
	for (const [tabler, fallback] of Object.entries(FALLBACK)) {
		if (!map.has(fallback)) map.set(fallback, []);
		map.get(fallback).push(tabler);
	}
	return map;
})();

/* Load tabler exports to verify Icon* names actually exist */
let tabler;
try {
	tabler = createRequire(import.meta.url)("@tabler/icons-react");
} catch (err) {
	console.error("rename-icons: failed to load @tabler/icons-react:", err.message);
	process.exit(2);
}
function tablerHas(bare) {
	return Object.prototype.hasOwnProperty.call(tabler, `Icon${bare}`);
}

async function walk(dir) {
	const out = [];
	let entries;
	try {
		entries = await fs.readdir(dir, { withFileTypes: true });
	} catch (err) {
		if (err.code === "ENOENT") return out;
		throw err;
	}
	for (const entry of entries) {
		if (SKIP_DIR_NAMES.has(entry.name)) continue;
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...(await walk(full)));
		} else if (EXTS.has(path.extname(entry.name))) {
			out.push(full);
		}
	}
	return out;
}

/* Match shim, tabler, or lucide named-import statement (multi-line).
   `[^{}]*` (no nested braces) prevents the non-greedy body from spanning
   across multiple import statements.
   Group 1 captures the optional `type ` keyword (outside the braces) so
   the rewriter can mark all entries as type-only. */
const NAMED_IMPORT_RE =
	/import\s+(type\s+)?\{([^{}]*)\}\s*from\s*['"](?:@shared\/ui\/icons|@tabler\/icons-react|lucide-react)['"]/g;

function parseEntry(raw) {
	const trimmed = raw.trim();
	if (!trimmed) return null;
	if (trimmed.startsWith("type ")) {
		return { kind: "type", raw: trimmed };
	}
	const m = trimmed.match(/^([A-Za-z_$][\w$]*)(?:\s+as\s+([A-Za-z_$][\w$]*))?$/);
	if (!m) return { kind: "unknown", raw: trimmed };
	return { kind: "value", source: m[1], alias: m[2] || null, raw: trimmed };
}

/* Names that are TYPE imports, not icon values. These are exported as types
   (or augmented via vite-env.d.ts) and must never get an `Icon` prefix.
   Example: `LucideIcon` (added by module augmentation on @tabler/icons-react). */
const TYPE_NAMES = new Set(["LucideIcon"]);

function rewriteImportBody(body, warnings, isTypeOnly) {
	const parts = body.split(",").map((s) => s.trim()).filter(Boolean);
	const renamed = [];
	const renameMap = new Map();
	for (const part of parts) {
		let entry = parseEntry(part);
		if (!entry) continue;
		// If the import is `import type {...}` (type-only), treat every entry as type.
		if (isTypeOnly || entry.kind !== "value") {
			renamed.push(entry.raw);
			continue;
		}
		// Names that already have the Icon prefix: verify they exist in tabler.
		// If `Icon<Source>` exists, treat as already-canonical (idempotent).
		// We always drop the `as <Alias>` suffix because canonical `Icon<Name>` is the
		// desired import name. We also seed the renameMap so a stray value reference
		// (e.g. `Circle` used as a component fallback, or `IconCheckCircle2` from a
		// prior bad run) gets renamed to the canonical `Icon<Name>`.
		if (entry.source.startsWith("Icon") && tablerHas(entry.source.slice(4))) {
			renamed.push(entry.source);
			const bare = entry.source.slice(4);
			renameMap.set(bare, entry.source);
			// Also map reverse aliases (lucide names that resolve to this bare).
			const reverses = REVERSE_ALIAS.get(bare) || [];
			for (const rev of reverses) {
				renameMap.set(rev, entry.source);
				renameMap.set(`Icon${rev}`, entry.source);
			}
			continue;
		}
		if (entry.source.startsWith("Icon")) {
			// Corruption case: strip the bogus prefix and re-resolve below.
			entry = { ...entry, source: entry.source.slice(4) };
		}
		// Known type-only names (e.g. `LucideIcon`) must never get an `Icon` prefix
		// and must be imported with the `type` keyword.
		if (TYPE_NAMES.has(entry.source)) {
			if (entry.alias) {
				renamed.push(`type ${entry.alias}`);
			} else {
				renamed.push(`type ${entry.source}`);
			}
			// Track renameMap so any JSX `<Foo />` using the alias points to the right thing.
			// (These are type-only, so no JSX usage; the map is unused but kept for safety.)
			continue;
		}
		// Apply alias, then fallback, then Icon prefix
		const originalSource = entry.source;
		let bare = ALIAS[entry.source] || entry.source;
		if (!tablerHas(bare)) {
			const fb = FALLBACK[bare];
			if (fb && tablerHas(fb)) {
				bare = fb;
			} else {
				warnings.push(`no tabler equivalent for "${entry.source}" (resolved to "${bare}")`);
			}
		}
		const newName = `Icon${bare}`;
		const spec = entry.alias && entry.alias !== newName
			? `${newName} as ${entry.alias}`
			: newName;
		renamed.push(spec);
		renameMap.set(originalSource, newName);
		// Also map the original lucide name (before alias) and the bare tabler form.
		// This catches value references like `icon={BarChart3}` when the import was
		// rewritten to `IconChartBar` via the FALLBACK map.
		if (originalSource !== bare) {
			renameMap.set(bare, newName);
		}
		if (entry.alias) {
			renameMap.set(entry.alias, newName);
		}
	}
	return { body: renamed.join(", "), renameMap };
}

function rewriteJsx(content, renameMap) {
	if (renameMap.size === 0) return content;
	let out = content;
	// Step 1: JSX tag rename (opening, self-closing, closing).
	for (const [oldName, newName] of renameMap) {
		if (oldName === newName) continue;
		const esc = oldName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
		const openRe = new RegExp(`(?<![\\w/])<(${esc})(?=[\\s/>])`, "g");
		const closeRe = new RegExp(`(?<![\\w/])</(${esc})>`, "g");
		out = out.replace(openRe, `<${newName}`);
		out = out.replace(closeRe, `</${newName}>`);
	}
	// Step 2: Value references for names NOT also imported from a non-tabler source.
	// This catches patterns like `const X = IconName || ...` where the icon is used
	// as a fallback value rather than a JSX tag.
	const allImports = collectImportNames(out);
	for (const [oldName, newName] of renameMap) {
		if (oldName === newName) continue;
		if (allImports.has(oldName)) continue; // collision with another import — leave alone
		const esc = oldName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
		const wordRe = new RegExp(`(?<![\\w])\\b(${esc})\\b(?![\\w])`, "g");
		out = out.replace(wordRe, newName);
	}
	return out;
}

/* Collect every PascalCase identifier imported from any source in `content`.
   Used to detect collisions before doing a value-reference rename. */
function collectImportNames(content) {
	const set = new Set();
	const re = /import\s+(?:type\s+)?\{([^}]*)\}\s*from\s*['"][^'"]+['"]/g;
	let m;
	while ((m = re.exec(content)) !== null) {
		const body = m[1];
		for (const part of body.split(",")) {
			const trimmed = part.trim();
			if (!trimmed || trimmed.startsWith("type ")) continue;
			const mm = trimmed.match(/^([A-Za-z_$][\w$]*)(?:\s+as\s+([A-Za-z_$][\w$]*))?$/);
			if (!mm) continue;
			set.add(mm[1]);
			if (mm[2]) set.add(mm[2]);
		}
	}
	return set;
}

async function processFile(file) {
	const rel = path.relative(ROOT, file);
	let content = await fs.readFile(file, "utf8");
	const original = content;
	const perFileRenameMap = new Map();
	const warnings = [];
	let importCount = 0;

	content = content.replace(NAMED_IMPORT_RE, (whole, typeKw, body) => {
		importCount += 1;
		const isTypeOnly = Boolean(typeKw);
		const { body: newBody, renameMap } = rewriteImportBody(body, warnings, isTypeOnly);
		for (const [k, v] of renameMap) perFileRenameMap.set(k, v);
		const prefix = isTypeOnly ? "import type " : "import ";
		return `${prefix}{${newBody}} from '@tabler/icons-react'`;
	});

	if (importCount === 0) return { changed: false };

	content = rewriteJsx(content, perFileRenameMap);

	if (warnings.length) {
		console.warn(`  ${rel}:`);
		for (const w of warnings) console.warn(`    warn: ${w}`);
	}
	if (content !== original) {
		await fs.writeFile(file, content, "utf8");
		return { changed: true };
	}
	return { changed: false };
}

async function main() {
	const files = await walk(SCAN_DIR);
	let changed = 0;
	let unchanged = 0;
	for (const file of files) {
		const r = await processFile(file);
		if (r.changed) changed += 1;
		else unchanged += 1;
	}
	console.log(`rename-icons: ${changed} changed, ${unchanged} unchanged (${files.length} total).`);
}

main().catch((err) => {
	console.error("rename-icons crashed:", err);
	process.exit(2);
});
