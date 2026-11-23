// Checks that every Composer and npm dependency uses a licence that is
// compatible with AGPL-3.0-or-later (CLAUDE.md: check licences of new
// dependencies). Reads only the lock files, needs no extra packages.
//
// Usage: node scripts/check-licenses.mjs   (from panel/)

import { existsSync, readFileSync } from 'node:fs';

const allowed = new Set([
    'MIT',
    'ISC',
    '0BSD',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'Apache-2.0',
    'MPL-2.0',
    'OFL-1.1',
    'LGPL-2.1-or-later',
    'LGPL-3.0-or-later',
    'GPL-3.0-or-later',
    'AGPL-3.0-or-later',
]);

// Packages whose metadata lacks a licence field. Each entry was checked by
// hand against the LICENSE file shipped in the package.
const checkedByHand = {
    '@tabler/icons-webfont': 'MIT',
};

const problems = [];

function check(manager, name, licenses) {
    const list = (licenses.length ? licenses : [checkedByHand[name]]).filter(Boolean);

    // Composer lists dual licences as alternatives: one allowed one is enough.
    // npm uses SPDX expressions such as "(MIT OR Apache-2.0)".
    const options = list.flatMap((license) => license.replace(/[()]/g, '').split(/\s+OR\s+/));

    if (!options.some((license) => allowed.has(license.trim()))) {
        problems.push(`${manager}: ${name} (${list.join(', ') || 'no licence'})`);
    }
}

let counted = 0;

if (existsSync('composer.lock')) {
    const lock = JSON.parse(readFileSync('composer.lock', 'utf8'));
    for (const pkg of [...lock.packages, ...(lock['packages-dev'] ?? [])]) {
        check('composer', pkg.name, pkg.license ?? []);
        counted++;
    }
} else {
    problems.push('composer.lock is missing');
}

const npmLock = JSON.parse(readFileSync('package-lock.json', 'utf8'));
for (const [path, pkg] of Object.entries(npmLock.packages)) {
    if (path === '') continue;
    const name = path.replace(/^.*node_modules\//, '');
    check('npm', name, pkg.license ? [pkg.license] : []);
    counted++;
}

if (problems.length) {
    console.error('Licences that need a decision:\n  ' + problems.join('\n  '));
    process.exit(1);
}

console.log(`All ${counted} dependencies use allowed licences.`);
