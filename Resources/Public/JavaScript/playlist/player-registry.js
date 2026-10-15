/**
 * Bookkeeping of initialized players: prevents double initialization and lets
 * theme sync and slider integrations reach every live player.
 */

// Track initialized players to prevent double-init
export const initializedPlayers = new WeakSet();

// Track all initialized players for theme sync (WeakRef to avoid memory leaks)
const allPlayerRefs = new Set();

export function getAlivePlayers() {
    const alive = [];
    for (const ref of allPlayerRefs) {
        const player = ref.deref();
        if (player) {
            alive.push(player);
        } else {
            allPlayerRefs.delete(ref);
        }
    }
    return alive;
}

export function trackPlayer(player) {
    allPlayerRefs.add(new WeakRef(player));
}
