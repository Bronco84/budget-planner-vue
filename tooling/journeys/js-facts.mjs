// Extracts import edges and Ziggy route() references from Vue SFCs and JS
// modules using the same parsers Vite uses (@vue/compiler-sfc + @babel/parser).
// Input (stdin):  {"path": "source", ...}
// Output (stdout): {"path": {imports, dynamic_imports, globs, route_refs, error}, ...}
import { parse as parseSfc } from '@vue/compiler-sfc';
import { parse as parseJs } from '@babel/parser';

const chunks = [];
for await (const chunk of process.stdin) chunks.push(chunk);
const files = JSON.parse(Buffer.concat(chunks).toString('utf8'));

const ROUTE_LITERAL = /\broute\(\s*(['"`])([\w.-]+)\1/g;

function walk(node, visit) {
    if (!node || typeof node.type !== 'string') return;
    visit(node);
    for (const key of Object.keys(node)) {
        if (key === 'loc' || key === 'start' || key === 'end') continue;
        const value = node[key];
        if (Array.isArray(value)) value.forEach((child) => walk(child, visit));
        else if (value && typeof value.type === 'string') walk(value, visit);
    }
}

function analyzeScript(source, lang, facts) {
    const ast = parseJs(source, {
        sourceType: 'module',
        errorRecovery: false,
        plugins: lang === 'ts' || lang === 'tsx' ? ['typescript', 'jsx'] : ['jsx'],
    });

    walk(ast.program, (node) => {
        if (
            (node.type === 'ImportDeclaration' || node.type === 'ExportAllDeclaration' || node.type === 'ExportNamedDeclaration') &&
            node.source
        ) {
            facts.imports.push(node.source.value);
        } else if (node.type === 'ImportExpression' || (node.type === 'CallExpression' && node.callee.type === 'Import')) {
            const arg = node.source ?? node.arguments?.[0];
            if (arg?.type === 'StringLiteral') facts.dynamic_imports.push(arg.value);
            else facts.unresolved.push('dynamic import() with non-literal specifier');
        } else if (
            node.type === 'CallExpression' &&
            node.callee.type === 'MemberExpression' &&
            node.callee.object?.type === 'MetaProperty' &&
            node.callee.property?.name === 'glob'
        ) {
            const arg = node.arguments[0];
            if (arg?.type === 'StringLiteral') facts.globs.push(arg.value);
        } else if (node.type === 'CallExpression' && node.callee.type === 'Identifier' && node.callee.name === 'route') {
            const arg = node.arguments[0];
            if (arg?.type === 'StringLiteral') facts.route_refs.push(arg.value);
        } else if (node.type === 'CallExpression' && isTestCall(node.callee)) {
            // Playwright test('name', ...) / test.describe('name', ...) titles.
            const arg = node.arguments[0];
            if (arg?.type === 'StringLiteral' || arg?.type === 'TemplateLiteral') {
                facts.tests.push(arg.type === 'StringLiteral' ? arg.value : arg.quasis.map((q) => q.value.cooked).join('${}'));
            }
        }
    });
}

function isTestCall(callee) {
    if (callee.type === 'Identifier') return callee.name === 'test' || callee.name === 'it';
    return (
        callee.type === 'MemberExpression' &&
        callee.object.type === 'Identifier' &&
        callee.object.name === 'test' &&
        ['only', 'skip', 'fixme', 'slow'].includes(callee.property?.name)
    );
}

const out = {};
for (const [path, source] of Object.entries(files)) {
    const facts = { kind: 'js', imports: [], dynamic_imports: [], globs: [], route_refs: [], tests: [], unresolved: [], error: null };
    try {
        if (path.endsWith('.vue')) {
            const { descriptor, errors } = parseSfc(source, { filename: path });
            if (errors.length) throw new Error(String(errors[0].message ?? errors[0]));
            for (const block of [descriptor.script, descriptor.scriptSetup]) {
                if (block) analyzeScript(block.content, block.lang, facts);
            }
            // Template expressions are not JS modules; route() calls there are
            // matched lexically and only used for inventory/reporting.
            if (descriptor.template) {
                for (const match of descriptor.template.content.matchAll(ROUTE_LITERAL)) facts.route_refs.push(match[2]);
            }
        } else {
            analyzeScript(source, path.endsWith('.ts') ? 'ts' : 'js', facts);
        }
    } catch (error) {
        facts.error = `parse error: ${error.message}`;
    }
    for (const key of ['imports', 'dynamic_imports', 'globs', 'route_refs', 'tests', 'unresolved']) {
        facts[key] = [...new Set(facts[key])].sort();
    }
    out[path] = facts;
}

process.stdout.write(JSON.stringify(out));
