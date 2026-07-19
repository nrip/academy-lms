#!/usr/bin/env node
/**
 * Copies only approved Phase 0 frontend assets from node_modules into public/assets/vendor.
 * Do not add undocumented vendor files by hand.
 */
import { cpSync, mkdirSync, rmSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const vendorRoot = join(root, 'public', 'assets', 'vendor');

const packages = [
  {
    name: 'bootstrap',
    files: [
      { from: 'dist/css/bootstrap.min.css', to: 'bootstrap/css/bootstrap.min.css' },
      { from: 'dist/css/bootstrap.min.css.map', to: 'bootstrap/css/bootstrap.min.css.map' },
      { from: 'dist/js/bootstrap.bundle.min.js', to: 'bootstrap/js/bootstrap.bundle.min.js' },
      { from: 'dist/js/bootstrap.bundle.min.js.map', to: 'bootstrap/js/bootstrap.bundle.min.js.map' },
    ],
  },
  {
    name: 'jquery',
    files: [
      { from: 'dist/jquery.min.js', to: 'jquery/jquery.min.js' },
      { from: 'dist/jquery.min.map', to: 'jquery/jquery.min.map' },
    ],
  },
];

function readPackageVersion(name) {
  const pkgPath = join(root, 'node_modules', name, 'package.json');
  if (!existsSync(pkgPath)) {
    throw new Error(`Missing dependency "${name}". Run npm ci first.`);
  }
  return JSON.parse(readFileSync(pkgPath, 'utf8')).version;
}

rmSync(vendorRoot, { recursive: true, force: true });
mkdirSync(vendorRoot, { recursive: true });

const manifest = { generatedAt: new Date().toISOString(), packages: {} };

for (const pkg of packages) {
  const version = readPackageVersion(pkg.name);
  manifest.packages[pkg.name] = version;
  const pkgRoot = join(root, 'node_modules', pkg.name);
  for (const file of pkg.files) {
    const source = join(pkgRoot, file.from);
    const target = join(vendorRoot, file.to);
    if (!existsSync(source)) {
      throw new Error(`Expected asset missing: ${source}`);
    }
    mkdirSync(dirname(target), { recursive: true });
    cpSync(source, target);
  }
}

writeFileSync(join(vendorRoot, 'MANIFEST.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log('Installed frontend assets:', manifest.packages);
