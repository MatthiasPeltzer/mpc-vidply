import { describe, expect, it } from 'vitest';
import {
    isAudioPlaylistTrack,
    isVideoPlaylistTrack,
    playlistHasMixedElementTypes,
    resolveTrackServiceType,
} from '../../../Resources/Public/JavaScript/playlist/track-types.js';

describe('isAudioPlaylistTrack', () => {
    it.each([
        [{ type: 'audio/mpeg' }],
        [{ type: 'audio' }],
        [{ type: 'soundcloud' }],
        [{ src: '/fileadmin/episode.MP3' }],
        [{ src: 'https://cdn.example.com/a.m4a?token=1' }],
        [{ src: '/a.flac#t=10' }],
    ])('detects audio for %j', (track) => {
        expect(isAudioPlaylistTrack(track)).toBe(true);
    });

    it.each([
        [{ type: 'video/mp4' }],
        [{ src: '/fileadmin/clip.mp4' }],
        [{}],
        [null],
    ])('rejects %j', (track) => {
        expect(isAudioPlaylistTrack(track)).toBe(false);
    });
});

describe('isVideoPlaylistTrack', () => {
    it.each([
        [{ type: 'video/webm' }],
        [{ type: 'video' }],
        [{ type: 'youtube' }],
        [{ type: 'vimeo' }],
        [{ type: 'hls' }],
        [{ type: 'application/dash+xml' }],
        [{ src: 'https://www.youtube.com/watch?v=abc' }],
        [{ src: 'https://youtu.be/abc' }],
        [{ src: 'https://vimeo.com/123' }],
        [{ src: '/stream/master.m3u8' }],
        [{ src: '/fileadmin/clip.ogv' }],
    ])('detects video for %j', (track) => {
        expect(isVideoPlaylistTrack(track)).toBe(true);
    });

    it.each([
        [{ src: 'https://soundcloud.com/artist/track.mp4' }],
        [{ type: 'audio/mpeg', src: '/a.mp3' }],
        [{}],
        [undefined],
    ])('rejects %j', (track) => {
        expect(isVideoPlaylistTrack(track)).toBe(false);
    });
});

describe('playlistHasMixedElementTypes', () => {
    it('is true when audio and video tracks are combined', () => {
        expect(playlistHasMixedElementTypes([{ type: 'audio/mpeg' }, { type: 'video/mp4' }])).toBe(true);
    });

    it('is false for audio-only and video-only playlists', () => {
        expect(playlistHasMixedElementTypes([{ type: 'audio/mpeg' }, { src: '/b.ogg' }])).toBe(false);
        expect(playlistHasMixedElementTypes([{ type: 'video/mp4' }, { type: 'youtube' }])).toBe(false);
        expect(playlistHasMixedElementTypes([])).toBe(false);
    });
});

describe('resolveTrackServiceType', () => {
    it('prefers the service derived from the source URL', () => {
        expect(resolveTrackServiceType({ src: 'https://vimeo.com/1', type: 'youtube' })).toBe('vimeo');
    });

    it('falls back to an explicit service type', () => {
        expect(resolveTrackServiceType({ src: '/local.mp4', type: 'SoundCloud' })).toBe('soundcloud');
    });

    it('returns null for local media', () => {
        expect(resolveTrackServiceType({ src: '/local.mp4', type: 'video/mp4' })).toBeNull();
        expect(resolveTrackServiceType(null)).toBeNull();
    });
});
