// jsray-loader.js tested in a node:vm sandbox with a minimal fake DOM.
// No WordPress, no browser — exercises the loader's pure logic:
// language resolution priority, renderer adapter selection, theme
// application, and copy-button binding.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const LOADER_SRC = readFileSync(resolve(ROOT, 'assets/js/jsray-loader.js'), 'utf8');

function fakeEl(className = '') {
  const el = {
    className,
    dataset: {},
    attrs: {},
    listeners: {},
    setAttribute(k, v) { el.attrs[k] = v; },
    addEventListener(type, fn) { (el.listeners[type] = el.listeners[type] || []).push(fn); },
  };
  el.classList = {
    add(c) { el.className = (el.className ? el.className + ' ' : '') + c; },
  };
  return el;
}

function fakeCode(text, className = '', pre = null) {
  const el = fakeEl(className);
  el.textContent = text;
  el.closest = (sel) => (sel === 'pre' ? pre : el.block || null);
  return el;
}

function fakeRenderer() {
  return {
    highlighted: [],
    highlightElement(el) { this.highlighted.push(el); },
    detectLanguage(code) { return /\bdef\s+\w+/.test(code) ? 'python' : ''; },
  };
}

/**
 * Run the loader IIFE against a fake window/document.
 * config  → window.JSRayWP (theme, fallbackLanguage, autoDetectLanguage, renderer)
 * jsray   → window.JSRay (the core renderer)
 */
function bootLoader({ config = {}, jsray, codeEls = [], copyButtons = [], clipboard, documentExtras } = {}) {
  const documentElement = fakeEl();
  const body = fakeEl();
  const document = {
    readyState: 'complete',
    documentElement,
    body,
    addEventListener() {},
    selectors: [],
    querySelectorAll(sel) {
      document.selectors.push(sel);
      return sel.includes('data-jsray-copy') ? copyButtons : codeEls;
    },
    createElement: () => ({ style: {}, setAttribute() {}, select() {} }),
    execCommand: () => true,
    ...documentExtras,
  };
  body.appendChild = () => {};
  body.removeChild = () => {};
  const window = { JSRayWP: config, JSRay: jsray, setTimeout() {} };
  const sandbox = {
    window,
    document,
    navigator: { clipboard: clipboard || { writeText: () => Promise.resolve() } },
  };
  vm.createContext(sandbox);
  new vm.Script(LOADER_SRC, { filename: 'jsray-loader.js' }).runInContext(sandbox);
  return { window, document, documentElement, body };
}

test('explicit language- class wins and pre is tagged', () => {
  const pre = fakeEl();
  const code = fakeCode('const a = 1;', 'language-js', pre);
  const jsray = fakeRenderer();
  bootLoader({ jsray, codeEls: [code] });

  assert.equal(code.dataset.jsrayLang, 'js');
  assert.deepEqual(jsray.highlighted, [code]);
  assert.match(pre.className, /\bjsray\b/);
  assert.equal(pre.dataset.jsrayLang, 'js');
});

test("pre's data-language is used when code has no class, and is sanitized", () => {
  const pre = fakeEl();
  pre.dataset.language = 'SQL!';
  const code = fakeCode('SELECT 1;', '', pre);
  const jsray = fakeRenderer();
  bootLoader({ jsray, codeEls: [code] });

  assert.equal(code.dataset.jsrayLang, 'sql');
  assert.match(code.className, /\blanguage-sql\b/);
});

test('auto-detection via renderer when no class or data attribute', () => {
  const code = fakeCode('def greet(name):\n    print(name)');
  const jsray = fakeRenderer();
  bootLoader({ jsray, codeEls: [code] });

  assert.equal(code.dataset.jsrayLang, 'python');
  assert.deepEqual(jsray.highlighted, [code]);
});

test("autoDetectLanguage='0' disables detection and falls back to fallbackLanguage", () => {
  const code = fakeCode('def greet(name):\n    print(name)');
  const jsray = fakeRenderer();
  bootLoader({
    config: { autoDetectLanguage: '0', fallbackLanguage: 'php' },
    jsray,
    codeEls: [code],
  });

  assert.equal(code.dataset.jsrayLang, 'php');
});

test('unresolvable block is skipped entirely', () => {
  const code = fakeCode('just plain prose');
  const jsray = fakeRenderer();
  jsray.detectLanguage = () => '';
  bootLoader({ jsray, codeEls: [code] });

  assert.equal(code.dataset.jsrayLang, undefined);
  assert.deepEqual(jsray.highlighted, []);
});

test('adapter renderer on JSRayWP.renderer takes precedence over core JSRay', () => {
  const code = fakeCode('const a = 1;', 'language-js');
  const core = fakeRenderer();
  const adapter = fakeRenderer();
  bootLoader({ config: { renderer: adapter }, jsray: core, codeEls: [code] });

  assert.deepEqual(adapter.highlighted, [code]);
  assert.deepEqual(core.highlighted, []);
});

test("config.theme='dark' sets data-theme on <html> and <body>; 'inherit' does not", () => {
  const dark = bootLoader({ config: { theme: 'dark' }, jsray: fakeRenderer() });
  assert.equal(dark.documentElement.attrs['data-theme'], 'dark');
  assert.equal(dark.body.attrs['data-theme'], 'dark');

  const inherit = bootLoader({ config: { theme: 'inherit' }, jsray: fakeRenderer() });
  assert.equal(inherit.documentElement.attrs['data-theme'], undefined);
});

test('copy buttons bind exactly once and report Copied on click', async () => {
  const button = fakeEl();
  const codeInBlock = { textContent: 'copy me' };
  button.closest = () => ({ querySelector: () => codeInBlock });
  button.textContent = 'Copy';

  const { window } = bootLoader({ jsray: fakeRenderer(), copyButtons: [button] });
  // Re-running highlight() must not double-bind
  window.JSRayWP.highlight({ querySelectorAll: (sel) => (sel.includes('data-jsray-copy') ? [button] : []) });

  assert.equal(button.dataset.jsrayCopyBound, '1');
  assert.equal((button.listeners.click || []).length, 1);

  button.listeners.click[0]();
  await new Promise((r) => setImmediate(r)); // let the clipboard promise settle
  assert.equal(button.textContent, 'Copied');
});

const codeSelector = (doc) => doc.selectors.find((s) => !s.includes('data-jsray-copy'));

test('copy feedback uses the strings PHP passes in, not hardcoded English', async () => {
  const button = fakeEl();
  button.closest = () => ({ querySelector: () => ({ textContent: 'copy me' }) });
  button.textContent = '复制';

  bootLoader({
    config: { i18n: { copy: '复制', copied: '已复制', failed: '失败' } },
    jsray: fakeRenderer(),
    copyButtons: [button],
  });

  button.listeners.click[0]();
  await new Promise((r) => setImmediate(r));
  assert.equal(button.textContent, '已复制');
});

test('scanAllCode:false stops the loader from claiming unmarked code blocks', () => {
  const marked = codeSelector(bootLoader({ jsray: fakeRenderer() }).document);
  assert.match(marked, /pre > code/, 'default sweeps every pre > code');

  const optedOut = codeSelector(bootLoader({ config: { scanAllCode: false }, jsray: fakeRenderer() }).document);
  assert.doesNotMatch(optedOut, /(^|,)pre > code/);
  assert.doesNotMatch(optedOut, /\.wp-block-code/);
  // Blocks that declare a language are still ours.
  assert.match(optedOut, /code\[class\*="language-"\]/);
});

test('a rejected async clipboard falls back instead of reporting failure', async () => {
  const button = fakeEl();
  button.closest = () => ({ querySelector: () => ({ textContent: 'copy me' }) });
  button.textContent = 'Copy';

  const created = [];
  const documentStub = {
    createElement() {
      const node = { style: {}, setAttribute() {}, select() {} };
      created.push(node);
      return node;
    },
  };

  const { window } = bootLoader({
    jsray: fakeRenderer(),
    copyButtons: [button],
    // Permissions policy, iframe, missing user activation — all land here.
    clipboard: { writeText: () => Promise.reject(new Error('NotAllowedError')) },
    documentExtras: documentStub,
  });

  button.listeners.click[0]();
  await new Promise((r) => setImmediate(r));

  assert.equal(button.textContent, 'Copied', 'should recover via the legacy path');
  assert.equal(created.length, 1, 'the fallback textarea should have been used');
  void window;
});

test('a detected language replaces the placeholder header, but never a filename', () => {
  const makeSpan = (attr) => {
    const el = fakeEl();
    el.textContent = 'Auto detect';
    el.attrs[attr] = '';
    return el;
  };

  const langSpan = makeSpan('data-jsray-language-label');
  const titleSpan = makeSpan('data-jsray-title-language');

  const block = fakeEl();
  block.querySelector = (sel) => {
    if (sel === '[data-jsray-language-label]') return langSpan;
    if (sel === '[data-jsray-title-language]') return titleSpan;
    return null;
  };

  const code = fakeCode('def greet(name):\n    print(name)');
  code.block = block;
  code.closest = (sel) => (sel === 'pre' ? null : block);

  bootLoader({
    config: { languages: { python: 'Python' } },
    jsray: fakeRenderer(),
    codeEls: [code],
  });

  assert.equal(langSpan.textContent, 'Python', 'the language label must show what was detected');
  assert.equal(titleSpan.textContent, 'Python', 'the placeholder title follows the language');
  assert.equal(block.attrs['data-language'], 'python');
});

test('a header with a filename exposes no placeholder to overwrite', () => {
  const langSpan = fakeEl();
  langSpan.textContent = 'Auto detect';

  const block = fakeEl();
  // PHP omits data-jsray-title-language when the author set a filename, so the
  // loader finds nothing to replace and the filename survives.
  block.querySelector = (sel) => (sel === '[data-jsray-language-label]' ? langSpan : null);

  const code = fakeCode('def greet(name):\n    print(name)');
  code.closest = (sel) => (sel === 'pre' ? null : block);

  bootLoader({ config: { languages: { python: 'Python' } }, jsray: fakeRenderer(), codeEls: [code] });

  assert.equal(langSpan.textContent, 'Python');
});
