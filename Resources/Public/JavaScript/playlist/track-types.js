/**
 * Classification of playlist tracks: which element type (<audio>/<video>) a
 * track needs and which external privacy service it belongs to.
 */

import {getServiceType} from '../shared/media-services.js';

/**
 * Infer whether a playlist track needs an <audio> element when recreated.
 */
export function isAudioPlaylistTrack(track) {
    const type = (track?.type || '').toLowerCase();
    if (type.startsWith('audio/') || type === 'audio' || type === 'soundcloud') {
        return true;
    }
    const src = (track?.src || '').toLowerCase();
    return /\.(mp3|m4a|ogg|wav|aac|flac)(\?|#|$)/i.test(src);
}

/**
 * Infer whether a playlist track needs a <video> element when recreated.
 */
export function isVideoPlaylistTrack(track) {
    const type = (track?.type || '').toLowerCase();
    if (type.startsWith('video/') || type === 'video') {
        return true;
    }
    if (['youtube', 'vimeo', 'hls', 'dash'].includes(type)) {
        return true;
    }
    if (type.startsWith('application/')) {
        return true;
    }
    const src = (track?.src || '').toLowerCase();
    if (src.includes('youtube.com') || src.includes('youtu.be') || src.includes('vimeo.com')) {
        return true;
    }
    if (src.includes('soundcloud.com')) {
        return false;
    }
    return /\.(mp4|webm|ogv|m3u8|mpd)(\?|#|$)/i.test(src);
}

/**
 * True when the playlist contains both audio- and video-type tracks.
 */
export function playlistHasMixedElementTypes(tracks) {
    let hasAudio = false;
    let hasVideo = false;
    for (const track of tracks) {
        if (isAudioPlaylistTrack(track)) {
            hasAudio = true;
        }
        if (isVideoPlaylistTrack(track)) {
            hasVideo = true;
        }
        if (hasAudio && hasVideo) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve the privacy service for a playlist track (src URL or explicit type).
 */
export function resolveTrackServiceType(track) {
    const fromSrc = getServiceType(track?.src);
    if (fromSrc) {
        return fromSrc;
    }
    const type = (track?.type || '').toLowerCase();
    if (type === 'youtube' || type === 'vimeo' || type === 'soundcloud') {
        return type;
    }
    return null;
}
