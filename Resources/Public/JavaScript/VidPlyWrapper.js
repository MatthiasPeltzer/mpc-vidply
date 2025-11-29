/**
 * VidPly Console Suppressor
 * Suppresses debug messages from VidPly library
 */

// Store original console methods
const originalLog = console.log;
const originalWarn = console.warn;

// Filter VidPly debug messages
console.log = function(...args) {
    const message = args[0];
    if (typeof message === 'string' && message.startsWith('VidPly Playlist:')) {
        return; // Suppress VidPly playlist debug messages
    }
    originalLog.apply(console, args);
};

console.warn = function(...args) {
    const message = args[0];
    if (typeof message === 'string' && message.startsWith('VidPly')) {
        return; // Suppress VidPly warnings
    }
    originalWarn.apply(console, args);
};

// Restore on page unload (optional)
window.addEventListener('beforeunload', () => {
    console.log = originalLog;
    console.warn = originalWarn;
});

