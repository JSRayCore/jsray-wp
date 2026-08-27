#!/usr/bin/env node
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';

const read = (path) => readFileSync(path, 'utf8');
const json = (path) => JSON.parse(read(path));
const fail = [];

function expect(condition, message) {
  if (!condition) fail.push(message);
}

function includes(path, needle, label = needle) {
  expect(read(path).includes(needle), `${path} is missing ${label}`);
}

const release = json('version.json');
const pkg = json('package.json');
const version = release.version;
const channel = release.channel;

expect(release.project === 'jsray-wp', 'version.json project must be jsray-wp');
expect(typeof version === 'string' && /^\d+\.\d+\.\d+-(?:internal\.\d+|beta(?:\.\d+)?)$|^\d+\.\d+\.\d+$/.test(version), `version.json has an unsupported version: ${version}`);
expect(['internal', 'beta', 'stable'].includes(channel), `version.json has an unsupported channel: ${channel}`);

if (channel === 'internal') {
  expect(/-internal\.\d+$/.test(version), 'internal channel versions must end with -internal.N');
  expect(release.publicBetaReleased === false, 'internal channel must keep publicBetaReleased false');
  expect(pkg.private === true, 'internal channel must keep package.json private true');
}

if (channel === 'beta') {
  expect(/-beta(?:\.\d+)?$/.test(version),
    'beta channel versions must end with -beta, optionally -beta.N');
  expect(release.publicBetaReleased === true, 'beta channel must set publicBetaReleased true');
}

if (channel === 'stable') {
  expect(!version.includes('-'), 'stable channel versions must not include a prerelease suffix');
}

expect(pkg.version === version, `package.json version ${pkg.version} does not match ${version}`);
expect(release.bundledCore?.project === 'jsray', 'bundledCore.project must be jsray');
expect(typeof release.bundledCore?.version === 'string', 'bundledCore.version must be set');

const plugin = read('jsray.php');
expect(plugin.includes(`* Version: ${version}`), `WordPress plugin header does not match ${version}`);
expect(plugin.includes(`define('JSRAY_WP_VERSION', '${version}');`), `JSRAY_WP_VERSION does not match ${version}`);

if (channel === 'internal') {
  includes('readme.txt', 'Stable tag: trunk');
} else {
  includes('readme.txt', `Stable tag: ${version}`);
}

includes('readme.txt', `= ${version} =`);

// WordPress.org rejects a readme without these headers, and the phase wording
// must match the channel so a beta never ships describing itself as internal.
includes('readme.txt', 'Tested up to:');
includes('readme.txt', 'Requires at least:');
includes('readme.txt', 'Requires PHP:');
// Phase wording, in both directions and across all three user-facing files.
//
// Asserting only that the right phrase is present let the wrong one sit beside
// it: both READMEs carried "Internal test build · no public beta yet" as their
// subtitle for the whole public beta, because nothing looked for it and nothing
// looked at the READMEs at all. The changelog is exempt — `= 0.0.1-internal.1 =`
// really was an internal build and its entry should keep saying so.
const PHASE = {
  internal: { want: 'Internal test build', reject: /Public beta|公开测试版/ },
  beta: { want: 'Public beta', reject: /Internal test build|内部测试版/ },
  stable: { want: null, reject: /Internal test build|Public beta|内部测试版|公开测试版/ },
};

const phase = PHASE[channel];

if (phase) {
  if (phase.want) includes('readme.txt', phase.want);

  for (const doc of ['README.md', 'README.zh-CN.md', 'readme.txt']) {
    const body = doc === 'readme.txt'
      ? read(doc).slice(0, read(doc).indexOf('== Changelog =='))
      : read(doc);

    expect(
      !phase.reject.test(body),
      `${doc} still describes a phase this release is not (channel: ${channel})`
    );
  }
}

// Files a distributable plugin must carry.
for (const file of ['uninstall.php', 'block.json', 'languages/jsray.pot', 'core-integrity.json', 'vocabulary.json']) {
  expect(existsSync(file), `${file} missing`);
}

// block.json is the single source of truth for the block: PHP must register
// from the file itself, and the editor script must not redeclare attributes.
const block = json('block.json');
expect(block.name === 'jsray/code', 'block.json name must be jsray/code');
expect(plugin.includes("register_block_type(\n\t\tJSRAY_WP_DIR . 'block.json'"),
  'jsray.php must register the block from block.json, not an inline array');
const editorJs = read('assets/js/jsray-block.js');
expect(!/attributes:\s*{/.test(editorJs),
  'assets/js/jsray-block.js must take attributes from block.json, not redeclare them');

// The integrity manifest must describe the files actually shipping, and its
// version must match the Core snapshot recorded in version.json.
const integrity = json('core-integrity.json');
expect(integrity.version === release.bundledCore.version,
  `core-integrity.json pins Core ${integrity.version} but bundledCore.version is ${release.bundledCore.version} — run 'sh tools/sync-core.sh'`);
for (const [file, digest] of Object.entries(integrity.files ?? {})) {
  if (!existsSync(file)) { fail.push(`core-integrity.json lists ${file}, which is missing`); continue; }
  const actual = 'sha256-' + createHash('sha256').update(readFileSync(file)).digest('base64');
  expect(actual === digest, `${file} does not match its pinned digest — the bundled Core was modified`);
}

// The vocabulary the custom-palette validator checks against must describe the
// bundled Core, not some older snapshot.
const vocabulary = json('vocabulary.json');
expect(Object.keys(vocabulary.tokens ?? {}).length === 23,
  `vocabulary.json should carry 23 tokens, found ${Object.keys(vocabulary.tokens ?? {}).length}`);

// Every palette offered by the settings screen needs a stylesheet to load.
for (const palette of ['default', 'aurora', 'ember', 'fjord']) {
  expect(existsSync(`assets/css/themes/${palette}.css`), `assets/css/themes/${palette}.css missing — run 'sh tools/sync-core.sh'`);
  expect(plugin.includes(`'${palette}'`) || palette === 'default', `palette ${palette} is bundled but not offered in jsray.php`);
}
// Both READMEs must keep the Core-vs-plugin boundary statement visible.
// README badges state the version, the channel and the bundled Core, and
// nothing was checking them: they sat three releases behind reality — `version
// 0.0.1-internal.2`, `channel internal test`, `Core 0.0.1-beta.1` — on a
// README that is the first thing anyone sees. shields.io escapes a hyphen as
// `--`, which is why the badge form is derived rather than compared raw.
const badgeVersion = version.replace(/-/g, '--');
const badgeCore = release.bundledCore.version.replace(/-/g, '--');

for (const doc of ['README.md', 'README.zh-CN.md']) {
  includes(doc, `version-${badgeVersion}-`, `a version badge reading ${version}`);
  includes(doc, `JSRay%20Core-${badgeCore}-`, `a Core badge reading ${release.bundledCore.version}`);
  expect(
    !/channel-internal/.test(read(doc)),
    `${doc} still shows the internal-test channel badge`
  );
}

// WordPress.org assets. These live beside trunk/ in SVN rather than inside it,
// so they never reach the zip — which is also why nothing else here would
// notice them missing. The numbering is a contract: screenshot-N.png pairs
// with the Nth line under `== Screenshots ==`, and an image with no line shows
// up unlabelled on the plugin page.
for (const asset of ['banner-772x250.png', 'banner-1544x500.png',
                     'icon-128x128.png', 'icon-256x256.png']) {
  expect(existsSync(`.wordpress-org/${asset}`), `.wordpress-org/${asset} missing`);
}

const shots = readdirSync('.wordpress-org').filter((f) => /^screenshot-\d+\.png$/.test(f));
const listed = (read('readme.txt').match(/^== Screenshots ==$([\s\S]*?)^== /m)?.[1] ?? '')
  .split('\n').filter((l) => /^\d+\.\s/.test(l)).length;

expect(shots.length > 0, 'no screenshot-N.png in .wordpress-org/');
expect(
  shots.length === listed,
  `${shots.length} screenshot files but readme.txt lists ${listed} — ` +
    'an image without a line renders unlabelled on the plugin page'
);

// WordPress.org guideline 1: everything in the directory must be GPL or
// GPL-compatible, and using WordPress's own licence is strongly recommended.
// These four say it in four places, and a relicence that misses one leaves the
// plugin claiming two different licences at once.
includes('jsray.php', 'License: GPLv2 or later');
includes('jsray.php', 'License URI: https://www.gnu.org/licenses/gpl-2.0.html');
includes('readme.txt', 'License: GPLv2 or later');
includes('readme.txt', 'License URI: https://www.gnu.org/licenses/gpl-2.0.html');
expect(pkg.license === 'GPL-2.0-or-later', `package.json license must be GPL-2.0-or-later, found ${pkg.license}`);
includes('LICENSE', 'GNU GENERAL PUBLIC LICENSE', 'the full GPL text');
includes('LICENSE', 'Version 2, June 1991');

// The bundled Core stays MIT and its notice has to travel with it — that is
// what MIT asks for, and it is why those two files keep their own headers.
// LICENSE is the unmodified GPLv2 text, because GitHub's licence detector
// only recognises it that way — a custom preamble had it reading NOASSERTION.
// The bundled Core's MIT notice therefore lives in its own file, which MIT is
// satisfied by and which ships in the zip.
expect(existsSync('LICENSE-THIRD-PARTY'), 'LICENSE-THIRD-PARTY missing');
includes('LICENSE-THIRD-PARTY', 'MIT License', "the bundled Core's MIT notice");
expect(
  !/JSRay for WordPress/.test(read('LICENSE')),
  'LICENSE must be the plain GPLv2 text — a preamble breaks GitHub licence detection'
);
includes('assets/js/jsray.js', '@license MIT', "the bundled Core's own licence header");

includes('README.md', 'bundles a snapshot', 'the Core snapshot boundary statement');
includes('README.zh-CN.md', '内置 Core 的快照', 'the Core snapshot boundary statement');

// Opportunistic drift check: when the Core repo is checked out as a sibling
// (or JSRAY_CORE_DIR is set), verify the bundled snapshot byte-matches Core's
// dist and that bundledCore.version tracks Core. Skipped silently when Core is
// not present (e.g. CI clones this repo alone).
//
// Integrations batch Core updates rather than chasing every release, so
// day-to-day drift is ADVISORY (warning, exit 0). It only fails the check in
// strict mode — used by the packaging gate and release workflows:
//   JSRAY_STRICT_DRIFT=1 node tools/check-versions.mjs   (or --strict)
for (const doc of ['LICENSE', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'CODE_OF_CONDUCT.md']) {
  expect(existsSync(doc), `${doc} missing — the ecosystem baseline requires it`);
}

// A changelog that exists is not a changelog that was written. Checking only
// for the file let this repository reach a version whose newest entry named
// the version before it — the promotion happened, the note about it did not.
includes('CHANGELOG.md', `## [${version}]`, `a CHANGELOG.md section for ${version}`);

// The supported-versions table is a promise to whoever is deciding whether to
// report privately. Core's sat on an old version through an entire release
// cycle before anything checked it.
includes('SECURITY.md', `| ${version} | ✅`, `${version} in the supported-versions table`);

const strictDrift = process.env.JSRAY_STRICT_DRIFT === '1' || process.argv.includes('--strict');
const warns = [];
const expectDrift = (condition, message) => {
  if (!condition) (strictDrift ? fail : warns).push(message);
};
const coreDir = process.env.JSRAY_CORE_DIR || '../jsray';
const coreDist = resolve(coreDir, 'dist');
if (existsSync(coreDist)) {
  const bundlePairs = [
    ['assets/js/jsray.js', resolve(coreDist, 'jsray.js')],
    ['assets/css/jsray.css', resolve(coreDist, 'jsray.css')],
    ['assets/css/themes/default.css', resolve(coreDist, 'themes/default.css')],
  ];
  for (const [bundled, core] of bundlePairs) {
    if (!existsSync(core)) continue;
    expectDrift(read(bundled) === read(core),
      `bundled ${bundled} differs from Core ${core} — run 'sh tools/sync-core.sh'`);
  }
  const coreVersionPath = resolve(coreDir, 'version.json');
  if (existsSync(coreVersionPath)) {
    const coreRelease = json(coreVersionPath);
    expectDrift(release.bundledCore.version === coreRelease.version,
      `bundledCore.version ${release.bundledCore.version} != Core ${coreRelease.version} — run 'sh tools/sync-core.sh'`);
  }
}

if (warns.length) {
  console.warn('Core drift (advisory — sync before this integration\'s next release):');
  for (const message of warns) console.warn(`- ${message}`);
}

if (fail.length) {
  console.error('Version metadata check failed:');
  for (const message of fail) {
    console.error(`- ${message}`);
  }
  process.exit(1);
}

console.log(`version metadata ok: ${version} (${channel}), bundled core ${release.bundledCore.version}`);
