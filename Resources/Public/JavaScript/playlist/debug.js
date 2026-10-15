/**
 * Keeps the player's own "VidPly…" console output quiet unless
 * `window.VIDPLY_DEBUG` is set before the script loads.
 */

const VIDPLY_DEBUG = Boolean(window.VIDPLY_DEBUG);

const hasVidPlyPrefix = (value) => {
    if (typeof value === 'string') return value.startsWith('VidPly');
    if (value && typeof value.toString === 'function') {
        try {
            return value.toString().startsWith('VidPly');
        } catch {
            return false;
        }
    }
    return false;
};

export const suppressVidPlyLogs = (fn) => {
    if (VIDPLY_DEBUG) {
        fn();
        return;
    }
    const originalConsoleLog = console.log;
    console.log = (...args) => {
        if (hasVidPlyPrefix(args[0])) return;
        originalConsoleLog.apply(console, args);
    };
    try {
        fn();
    } finally {
        console.log = originalConsoleLog;
    }
};
