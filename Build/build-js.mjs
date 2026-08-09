#!/usr/bin/env node

/**
 * Bundles and minifies the extension's own frontend scripts.
 *
 * Every entry is written to an explicit output path. The target directory also
 * holds the vidply player distribution (vidply.esm.min.js, vidply.chunk-*.min.js,
 * hls.min.js, dash.all.min.js) copied in from .libs/vidply, so this build must
 * never use an outdir or a clean step that could remove those files.
 */

import * as esbuild from 'esbuild';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const javaScriptDir = join(packageRoot, 'Resources', 'Public', 'JavaScript');

const entries = [
    { name: 'PlaylistInit', format: 'esm' },
    { name: 'EpisodeInit', format: 'esm' },
    { name: 'Listview', format: 'esm' },
    // Loaded as a classic deferred script, so it must not emit import statements.
    { name: 'PrivacyLayer', format: 'iife' },
];

for (const entry of entries) {
    await esbuild.build({
        entryPoints: [join(javaScriptDir, `${entry.name}.js`)],
        outfile: join(javaScriptDir, `${entry.name}.min.js`),
        bundle: true,
        minify: true,
        format: entry.format,
        // The player is shipped pre-built from .libs/vidply and code-split into
        // chunks it loads itself, so it stays a runtime import instead of being
        // inlined. Output sits next to the entry, so the specifier still resolves.
        external: ['./vidply/*'],
        target: ['es2020', 'chrome80', 'firefox78', 'safari14', 'edge88'],
        // Destructuring is native in every targeted browser. esbuild 0.25+ errors
        // out rather than passing through patterns it cannot fully lower, so tell
        // it the feature is available instead.
        supported: { destructuring: true },
        charset: 'utf8',
        legalComments: 'none',
        logLevel: 'warning',
    });

    console.log(`built ${entry.name}.min.js (${entry.format})`);
}
