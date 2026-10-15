#!/usr/bin/env node

/**
 * Minifies the extension's own stylesheets with the clean-css library.
 *
 * Replaces the clean-css-cli dependency, which pulls in chokidar and the
 * unpatched braces package. Inputs are concatenated in the listed order.
 */

import CleanCSS from 'clean-css';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const cssDir = join(packageRoot, 'Resources', 'Public', 'Css');

const bundles = {
    privacy: ['privacy-layer.min.css', ['shared.css', 'privacy-layer.css']],
    overrides: ['vidply-overrides.min.css', ['vidply-overrides.css']],
    icons: ['vidply-icons.min.css', ['vidply-icons.css']],
    listview: ['listview.min.css', ['shared.css', 'listview.css']],
    episode: ['episode.min.css', ['shared.css', 'episode.css']],
};

const requested = process.argv.slice(2);
const names = requested.length > 0 ? requested : Object.keys(bundles);

for (const name of names) {
    if (!bundles[name]) {
        console.error(`Unknown CSS bundle "${name}". Available: ${Object.keys(bundles).join(', ')}`);
        process.exit(1);
    }

    const [output, inputs] = bundles[name];
    const source = inputs.map((file) => readFileSync(join(cssDir, file), 'utf8')).join('\n');
    const result = new CleanCSS({ returnPromise: false }).minify(source);

    if (result.errors.length > 0) {
        console.error(result.errors.join('\n'));
        process.exit(1);
    }
    result.warnings.forEach((warning) => console.warn(warning));

    mkdirSync(cssDir, { recursive: true });
    writeFileSync(join(cssDir, output), result.styles);
    console.log(`${output}: ${result.stats.originalSize} -> ${result.stats.minifiedSize} bytes`);
}
