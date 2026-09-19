/**
 * One-off analysis tool: reconstruct the (undocumented) database schema from
 * the SQL statements embedded in the legacy PHP code.
 *
 *   node tools/analyze-schema.mjs
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const SKIP = new Set(['node_modules', '.git', 'storage', 'vendor', 'superadmin']);

function walk(dir, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.name.startsWith('.') || SKIP.has(entry.name)) continue;
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) walk(full, out);
		else if (entry.name.endsWith('.php') || entry.name.endsWith('.sql')) out.push(full);
	}
	return out;
}

const known = new Set([
	'select', 'from', 'where', 'insert', 'into', 'update', 'set', 'values', 'and', 'or', 'as', 'on', 'join',
	'left', 'right', 'inner', 'outer', 'group', 'order', 'by', 'limit', 'offset', 'desc', 'asc', 'count',
	'sum', 'avg', 'max', 'min', 'if', 'null', 'not', 'in', 'like', 'distinct', 'case', 'when', 'then', 'else',
	'end', 'having', 'union', 'all', 'exists', 'between', 'is', 'true', 'false', 'now', 'date_format',
	'coalesce', 'ifnull', 'concat', 'group_concat', 'replace', 'lower', 'upper', 'substring', 'round',
	'current_timestamp', 'interval', 'day', 'month', 'year', 'table', 'create', 'primary', 'key',
	'auto_increment', 'default', 'varchar', 'int', 'decimal', 'text', 'timestamp', 'datetime', 'enum',
	'boolean', 'current_date', 'double', 'unsigned', 'engine', 'charset', 'collate', 'unique', 'index',
	'database', 'show', 'columns', 'tables', 'describe', 'delete', 'truncate', 'alter', 'add', 'column',
	'primary key', 'foreign', 'references', 'cascade', 'restrict', 'nulls', 'first', 'last', 'using',
]);

const files = walk(ROOT);
/** @type {Map<string, Set<string>>} */
const columns = new Map();
/** @type {Map<string, number>} */
const tableHits = new Map();

const addColumns = (table, text) => {
	if (!table) return;
	const t = table.toLowerCase();
	const set = columns.get(t) ?? new Set();
	const quoted = [...String(text).matchAll(/`(\w+)`/g)].map((m) => m[1]);
	const bare = String(text)
		.replace(/`\w+`/g, ' ')
		.split(/[\s,()]+/)
		.map((s) => s.trim())
		.filter(Boolean);
	for (const candidate of [...quoted, ...bare]) {
		const col = candidate.toLowerCase();
		if (!/^[a-z_][a-z0-9_]*$/.test(col)) continue;
		if (known.has(col)) continue;
		if (col.startsWith('http') || col.length > 40) continue;
		if (tableHits.has(col)) continue;
		set.add(col);
	}
	columns.set(t, set);
};

for (const file of files) {
	const text = fs.readFileSync(file, 'utf8');

	for (const m of text.matchAll(/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)/gi)) {
		tableHits.set(m[1].toLowerCase(), (tableHits.get(m[1].toLowerCase()) ?? 0) + 1);
		addColumns(m[1], m[2]);
	}
	for (const m of text.matchAll(/UPDATE\s+`?(\w+)`?\s+SET\s+(.*?)(?:WHERE|$)/gis)) {
		const cols = [...m[2].matchAll(/`?(\w+)`?\s*=/g)].map((x) => x[1]);
		addColumns(m[1], cols.join(' '));
		tableHits.set(m[1].toLowerCase(), (tableHits.get(m[1].toLowerCase()) ?? 0) + 1);
	}
	for (const m of text.matchAll(/SELECT\s+([\s\S]*?)\s+FROM\s+`?(\w+)`?/gi)) {
		const list = m[1];
		if (list.includes('*') || /count|sum|avg|max|min/i.test(list)) continue;
		// Only trust simple column lists, otherwise we pick up SQL noise.
		const parts = list.split(',');
		const cols = [];
		let clean = true;
		for (const part of parts) {
			const trimmed = part.trim();
			const dotted = trimmed.match(/^(?:\w+\.)?`?(\w+)`?(?:\s+AS\s+\w+)?$/i);
			if (dotted) cols.push(dotted[1]);
			else {
				const alias = trimmed.match(/\s+AS\s+`?(\w+)`?$/i);
				const inner = trimmed.match(/(?:\w+\.)?`?(\w+)`?\s*(?:\(|$)/);
				if (inner && /^(?:\w+\.)?`?\w+`?$/i.test(trimmed.replace(/\s+AS\s+\w+$/i, ''))) cols.push(inner[1]);
				else if (alias) cols.push(alias[1]);
				else clean = false;
			}
		}
		if (clean) addColumns(m[2], cols.join(' '));
		tableHits.set(m[2].toLowerCase(), (tableHits.get(m[2].toLowerCase()) ?? 0) + 1);
	}
}

const tables = [...columns.keys()].sort();
console.log(`Scanned ${files.length} files\n`);
for (const table of tables) {
	const cols = [...columns.get(table)].sort();
	console.log(`\n${table}  (${tableHits.get(table) ?? 0} statements, ${cols.length} columns)`);
	console.log('  ' + cols.join(', '));
}
