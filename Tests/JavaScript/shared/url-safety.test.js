import { describe, expect, it } from 'vitest';
import { isSafeUrl, sanitizeCssUrl } from '../../../Resources/Public/JavaScript/shared/url-safety.js';

describe('isSafeUrl', () => {
    it.each([
        'https://example.com/a.mp4',
        'http://example.com/a.mp4',
        '/fileadmin/a.mp4',
        'fileadmin/a.mp4',
        '//cdn.example.com/a.mp4',
    ])('accepts %s', (url) => {
        expect(isSafeUrl(url)).toBe(true);
    });

    it.each([
        'javascript:alert(1)',
        ' JavaScript:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        'vbscript:msgbox',
        'ftp://example.com/a.mp4',
        '',
        null,
        undefined,
        42,
    ])('rejects %s', (url) => {
        expect(isSafeUrl(url)).toBe(false);
    });
});

describe('sanitizeCssUrl', () => {
    it('returns a trimmed safe URL', () => {
        expect(sanitizeCssUrl('  /fileadmin/poster.jpg  ')).toBe('/fileadmin/poster.jpg');
        expect(sanitizeCssUrl('https://i.ytimg.com/vi/abc/hq.jpg')).toBe('https://i.ytimg.com/vi/abc/hq.jpg');
    });

    it.each([
        '/a.jpg") ; background: url("evil',
        "/a.jpg'",
        '/a(1).jpg',
        '/a\\b.jpg',
        '/a<b>.jpg',
        '/a`b`.jpg',
        '/a b.jpg',
        '/a\u0000.jpg',
        'javascript:alert(1)',
        '',
        null,
    ])('rejects %j', (url) => {
        expect(sanitizeCssUrl(url)).toBeNull();
    });
});
