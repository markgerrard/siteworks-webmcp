import { initEditOverlay } from './edit-overlay.js';
import { initToolbar } from './toolbar.js';

document.addEventListener('DOMContentLoaded', () => {
    if (! window.SITE_EDITOR_CONFIG) {
        console.warn('site-editor: SITE_EDITOR_CONFIG missing on window');
        return;
    }
    initEditOverlay(window.SITE_EDITOR_CONFIG);
    initToolbar(window.SITE_EDITOR_CONFIG);
});
