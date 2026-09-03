#!/usr/bin/env node
// Build languages/jsray.pot from the translatable strings in PHP and JS.
//
// wp-cli's `wp i18n make-pot` is the canonical tool, but it needs PHP. This
// covers the call shapes this plugin actually uses — __(), _e(), esc_html__(),
// esc_html_e(), esc_attr__() — so translators get a usable template from a
// plain `npm run build:pot`.
import { readFileSync, writeFileSync, mkdirSync, readdirSync } from 'node:fs';
import { resolve, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DOMAIN = 'jsray';

const sources = [
  'jsray.php',
  'uninstall.php',
  ...readdirSync(resolve(ROOT, 'assets/js'))
    .filter((f) => f.endsWith('.js') && f !== 'jsray.js') // jsray.js is the Core snapshot
    .map((f) => `assets/js/${f}`),
];

// __('text', 'domain') and its escaping variants. Single or double quoted,
// with backslash escapes preserved so the .pot stays faithful.
const CALL = /\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*(['"])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*(['"])jsray\3\s*\)/g;

const entries = new Map();

for (const file of sources) {
  const text = readFileSync(resolve(ROOT, file), 'utf8');
  const lines = text.split('\n');

  for (const [index, line] of lines.entries()) {
    CALL.lastIndex = 0;
    let match;
    while ((match = CALL.exec(line)) !== null) {
      const msgid = match[2];
      const reference = `${relative('.', file)}:${index + 1}`;
      if (!entries.has(msgid)) entries.set(msgid, []);
      entries.get(msgid).push(reference);
    }
  }
}

const header = `# Copyright (C) 2026 JSRay
# This file is distributed under the GPLv2 or later license.
msgid ""
msgstr ""
"Project-Id-Version: JSRay\\n"
"Report-Msgid-Bugs-To: https://github.com/jsrayorg/jsray-wp/issues\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: ${DOMAIN}\\n"
`;

const body = [...entries.entries()]
  .map(([msgid, refs]) => `\n#: ${refs.join(' ')}\nmsgid "${msgid}"\nmsgstr ""\n`)
  .join('');

mkdirSync(resolve(ROOT, 'languages'), { recursive: true });
writeFileSync(resolve(ROOT, 'languages/jsray.pot'), header + body);

console.log(`wrote languages/jsray.pot — ${entries.size} strings from ${sources.length} files`);
