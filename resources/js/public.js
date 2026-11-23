// Image viewer for the specimen page. Without JavaScript the sheet is shown at
// fit width and the image link opens the file, so every control is progressive.
const ZOOM_STEPS = [1, 1.5, 2, 3, 4];
// Fit = the whole sheet visible; Fit resets zoom and the thumbnails reset it too.

document.querySelectorAll('[data-image-viewer]').forEach((viewer) => {
    const image = viewer.querySelector('[data-viewer-image]');
    const download = viewer.querySelector('[data-viewer-download]');
    let step = 0;
    let fitWidth = 0;

    const apply = () => {
        if (step === 0) {
            image.style.maxWidth = image.style.maxHeight = image.style.width = '';
            return;
        }
        // Zoom relative to the whole-sheet "fit" size.
        image.style.maxWidth = image.style.maxHeight = 'none';
        image.style.width = `${fitWidth * ZOOM_STEPS[step]}px`;
    };

    const zoom = viewer.querySelector('[data-viewer-zoom]');
    zoom?.removeAttribute('hidden');
    zoom?.addEventListener('click', (event) => {
        const action = event.target.closest('[data-zoom]')?.dataset.zoom;
        if (action && step === 0) fitWidth = image.getBoundingClientRect().width;
        if (action === 'in') step = Math.min(step + 1, ZOOM_STEPS.length - 1);
        if (action === 'out') step = Math.max(step - 1, 0);
        if (action === 'fit') step = 0;
        if (action) apply();
    });

    const fullscreen = viewer.querySelector('[data-fullscreen]');
    if (fullscreen && document.fullscreenEnabled) {
        fullscreen.removeAttribute('hidden');
        fullscreen.addEventListener('click', () => {
            if (document.fullscreenElement) document.exitFullscreen();
            else viewer.requestFullscreen();
        });
    }

    viewer.querySelectorAll('[data-viewer-thumb]').forEach((thumb) => {
        thumb.addEventListener('click', (event) => {
            event.preventDefault();
            image.src = thumb.href;
            if (download) download.href = thumb.href;
            viewer.querySelectorAll('[data-viewer-thumb]').forEach((other) => other.removeAttribute('aria-current'));
            thumb.setAttribute('aria-current', 'true');
            step = 0;
            apply();
        });
    });
});
