#!/usr/bin/env node
/**
 * design:check — enforces the Erada design constitution on production code.
 *
 * Scans `resources/js/pages/**` and `resources/js/widgets/**` and exits 1
 * if any forbidden pattern is found. See docs/DESIGN_RULES.md "v1.2" section
 * for the rationale of each rule.
 *
 * Usage:  node scripts/design-check.mjs
 *     or  npm run design:check
 */

import { promises as fs } from "node:fs";
import path from "node:path";
import process from "node:process";

const ROOT = process.cwd();
const SCAN_DIRS = [
	path.join(ROOT, "resources/js/pages"),
	path.join(ROOT, "resources/js/widgets"),
];
const EXTS = new Set([".ts", ".tsx", ".js", ".jsx"]);

const RULES = [
	{
		id: "raw-hex",
		label: "raw hex color (use var(--token))",
		regex: /#[0-9a-fA-F]{3,8}\b/g,
		// Allow hex inside `var(...)` references such as `var(--accent, #1c82c7)`
		allowInContext: (line) => /var\([^)]*#[0-9a-fA-F]{3,8}[^)]*\)/.test(line) && !/[^,(\s]#[0-9a-fA-F]{3,8}\b/.test(line),
	},
	{
		id: "raw-palette",
		label: "raw Tailwind palette class for status/border/background",
		regex:
			/\b(?:text|bg|border|ring|fill|stroke)-(?:gray|red|green|blue|amber|yellow|emerald|rose|sky|indigo|teal|violet|slate|stone|zinc|neutral)-\d{2,3}\b/g,
	},
	{
		id: "raw-select",
		label: "raw <select> (use @shared/ui Select)",
		regex: /<select[\s/>]/g,
	},
	{
		id: "raw-radio-checkbox",
		label: "raw radio/checkbox <input> (use @shared/ui Checkbox/Radio)",
		regex: /<input[^>]+type=["'](?:radio|checkbox)["']/g,
	},
	{
		id: "lucide-import",
		label: "import from lucide-react (use @tabler/icons-react directly)",
		regex: /from\s+['"]lucide-react['"]/g,
	},
	{
		id: "brand-only-token",
		label: "use of brand-only token (--ink-*, --paper-*)",
		regex: /var\(\s*--(?:ink|paper)-/g,
	},
	{
		id: "em-dash",
		label: "em dash (—) in user-facing text — use en dash (–), hyphen (-), or ':'",
		regex: /—/g,
		// Only flag em dashes in running user-facing text. Allow them in:
		//  - code comments (//, /* */, JSDoc, {/* */}) — never rendered
		//  - "empty value" markers ('—', "—", >—<) — a legitimate typographic placeholder
		allowInContext: (line) => {
			const trimmed = line.trim();
			let code = line.replace(/\/\/.*$/, ""); // drop line-comment tail
			if (/^(\*|\/\*|\{\/\*|\/\*\*)/.test(trimmed)) code = ""; // block/JSDoc/JSX comment line
			code = code
				.replace(/(['"])—\1/g, "") // '—' or "—"
				.replace(/>\s*—\s*</g, "><"); // >—<
			return !/—/.test(code);
		},
	},
	{
		id: "raw-black-white",
		label: "raw black/white utility (use token-mapped surface/text)",
		regex: /\b(?:bg|text|border|ring|fill|stroke)-(?:white|black)\b/g,
	},
];

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
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...(await walk(full)));
		} else if (EXTS.has(path.extname(entry.name))) {
			out.push(full);
		}
	}
	return out;
}

async function main() {
	const files = (await Promise.all(SCAN_DIRS.map(walk))).flat();
	const violations = [];

	for (const file of files) {
		const rel = path.relative(ROOT, file);
		const content = await fs.readFile(file, "utf8");
		const lines = content.split(/\r?\n/);
		lines.forEach((line, idx) => {
			for (const rule of RULES) {
				const matches = line.match(rule.regex);
				if (!matches) continue;
				if (rule.allowInContext && rule.allowInContext(line)) continue;
				for (const m of matches) {
					violations.push({
						rule: rule.id,
						label: rule.label,
						file: rel,
						line: idx + 1,
						match: m,
						excerpt: line.trim().slice(0, 200),
					});
				}
			}
		});
	}

	if (violations.length === 0) {
		console.log(
			`design:check — PASS (${files.length} files scanned, 0 violations)`,
		);
		process.exit(0);
	}

	console.error(
		`design:check — FAIL (${violations.length} violation${violations.length === 1 ? "" : "s"} in ${files.length} files)\n`,
	);
	for (const v of violations) {
		console.error(
			`  ${v.file}:${v.line}  [${v.rule}]  ${v.label}`,
		);
		console.error(`    > ${v.excerpt}`);
	}
	console.error(
		`\nFix the violations above. See docs/DESIGN_RULES.md "v1.2" for the rationale.`,
	);
	process.exit(1);
}

main().catch((err) => {
	console.error("design:check crashed:", err);
	process.exit(2);
});
