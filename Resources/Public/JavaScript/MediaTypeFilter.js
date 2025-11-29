/**
 * Media Type Enhancements for VidPly Extension
 * Adds helper behavior for the media type select field (currently for video URL validation only)
 */
export default class MediaTypeFilter {
    constructor() {
        this.videoUrlPatterns = {
            youtube: {
                pattern: '^https?://(www\\.)?(youtube\\.com/watch\\?v=|youtu\\.be/)[\\w-]+',
                placeholder: 'https://www.youtube.com/watch?v=VIDEO_ID',
            },
            vimeo: {
                pattern: '^https?://(www\\.)?vimeo\\.com/\\d+',
                placeholder: 'https://vimeo.com/VIDEO_ID',
            },
        };
    }

    initialize() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setupFieldWatcher());
        } else {
            this.setupFieldWatcher();
        }
    }

    setupFieldWatcher() {
        const mediaTypeField = this.findFieldByName('tx_mpcvidply_media_type');
        if (!mediaTypeField) {
            return;
        }

        const applyRules = () => {
            const mediaType = mediaTypeField.value || mediaTypeField.options[mediaTypeField.selectedIndex]?.value;
            this.updateVideoUrlField(mediaType);
        };

        applyRules();
        mediaTypeField.addEventListener('change', applyRules);
    }

    findFieldByName(fieldName) {
        const selectors = [
            `[name*="[${fieldName}]"]`,
            `[data-formengine-input-name*="[${fieldName}]"]`,
            `select[name*="${fieldName}"]`,
            `#${fieldName}`,
        ];

        for (const selector of selectors) {
            const field = document.querySelector(selector);
            if (field) {
                return field;
            }
        }
        return null;
    }

    updateVideoUrlField(mediaType) {
        const videoUrlField = this.findFieldByName('tx_mpcvidply_video_url');
        if (!videoUrlField) {
            return;
        }

        const config = this.videoUrlPatterns[mediaType];
        const fieldWrapper = videoUrlField.closest('.form-group') || videoUrlField.closest('.formengine-field-item');

        if (config) {
            videoUrlField.setAttribute('pattern', config.pattern);
            videoUrlField.setAttribute('placeholder', config.placeholder);
            videoUrlField.removeAttribute('readonly');
            if (fieldWrapper) {
                fieldWrapper.classList.remove('d-none');
            }
        } else {
            videoUrlField.removeAttribute('pattern');
            videoUrlField.removeAttribute('placeholder');
            videoUrlField.value = '';
            if (fieldWrapper) {
                fieldWrapper.classList.add('d-none');
            }
        }
    }
}

const filter = new MediaTypeFilter();
filter.initialize();
