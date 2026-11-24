// Image viewer for the specimen page. Without JavaScript the sheet is shown at
// fit width and the image link opens the file, so every control is progressive.
//
// Mouse: hovering the sheet shows a lens and a magnified pane beside it.
// Click (or the Zoom button): zoom in place, then drag to pan. Click again to reset.
const LOUPE = 2.5;
const PANEL_MIN_WIDTH = 900; // the side pane needs the two-column layout

document.querySelectorAll('[data-image-viewer]').forEach((viewer) => {
    const image = viewer.querySelector('[data-viewer-image]');
    const stage = viewer.querySelector('[data-viewer-stage]');
    const download = viewer.querySelector('[data-viewer-download]');
    const media = viewer.closest('.record-media');

    if (image && stage) {
        let zoomed = false;
        let pan = { x: 0, y: 0 };
        let drag = null;
        const zoomButton = viewer.querySelector('[data-viewer-zoom]');

        const lens = document.createElement('div');
        lens.className = 'image-lens';
        const pane = document.createElement('div');
        pane.className = 'image-pane';
        pane.setAttribute('aria-hidden', 'true');
        stage.append(lens);
        media?.append(pane);

        const hideLoupe = () => { lens.hidden = pane.hidden = true; };
        hideLoupe();

        // pan is the translation applied before scaling from the image's top-left corner.
        const limits = () => {
            const stageBox = stage.getBoundingClientRect();
            const lo = (size, span, offset) => Math.min(span - size * LOUPE - offset, -offset);
            const hi = (size, span, offset) => Math.max(span - size * LOUPE - offset, -offset);
            return {
                x: [lo(image.offsetWidth, stageBox.width, image.offsetLeft), hi(image.offsetWidth, stageBox.width, image.offsetLeft)],
                y: [lo(image.offsetHeight, stageBox.height, image.offsetTop), hi(image.offsetHeight, stageBox.height, image.offsetTop)],
            };
        };

        const clampPan = () => {
            const range = limits();
            pan.x = Math.min(range.x[1], Math.max(range.x[0], pan.x));
            pan.y = Math.min(range.y[1], Math.max(range.y[0], pan.y));
        };

        const render = () => {
            image.style.transform = zoomed ? `translate(${pan.x}px, ${pan.y}px) scale(${LOUPE})` : '';
        };

        const setZoom = (on, point) => {
            zoomed = on;
            hideLoupe();
            stage.classList.toggle('is-zoomed', on);
            zoomButton?.setAttribute('aria-pressed', String(on));
            if (on) {
                // Keep the clicked point under the cursor; centre the sheet when there is none.
                const box = image.getBoundingClientRect();
                const u = point ? point.x - box.left : box.width / 2;
                const v = point ? point.y - box.top : box.height / 2;
                pan.x = u * (1 - LOUPE);
                pan.y = v * (1 - LOUPE);
                clampPan();
            }
            render();
        };

        // Hover loupe (mouse only; touch uses click-to-zoom and drag).
        const showLoupe = (event) => {
            if (zoomed || event.pointerType !== 'mouse' || ! matchMedia(`(min-width: ${PANEL_MIN_WIDTH}px)`).matches) return;
            const box = image.getBoundingClientRect();
            const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
            if (! inside) return hideLoupe();

            const stageBox = stage.getBoundingClientRect();
            const mediaBox = media.getBoundingClientRect();
            pane.hidden = lens.hidden = false;
            pane.style.height = `${Math.min(stageBox.height, 560)}px`;
            pane.style.width = `${Math.min(mediaBox.width * 0.9, 560)}px`;
            const paneW = pane.offsetWidth;
            const paneH = pane.offsetHeight;
            const lensW = paneW / LOUPE;
            const lensH = paneH / LOUPE;
            const left = Math.min(Math.max(event.clientX - box.left - lensW / 2, 0), Math.max(0, box.width - lensW));
            const top = Math.min(Math.max(event.clientY - box.top - lensH / 2, 0), Math.max(0, box.height - lensH));

            lens.style.width = `${lensW}px`;
            lens.style.height = `${lensH}px`;
            lens.style.left = `${box.left - stageBox.left + stage.scrollLeft + left}px`;
            lens.style.top = `${box.top - stageBox.top + stage.scrollTop + top}px`;

            pane.style.backgroundImage = `url("${image.currentSrc || image.src}")`;
            pane.style.backgroundSize = `${box.width * LOUPE}px ${box.height * LOUPE}px`;
            pane.style.backgroundPosition = `${-left * LOUPE}px ${-top * LOUPE}px`;
            pane.style.left = `calc(100% + 36px)`;
            pane.style.top = `${stageBox.top - mediaBox.top}px`;
        };

        stage.addEventListener('pointermove', (event) => {
            if (drag) {
                pan.x = drag.px + event.clientX - drag.x;
                pan.y = drag.py + event.clientY - drag.y;
                drag.moved = drag.moved || Math.hypot(event.clientX - drag.x, event.clientY - drag.y) > 4;
                clampPan();
                render();
            } else {
                showLoupe(event);
            }
        });
        stage.addEventListener('pointerleave', hideLoupe);

        stage.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || ! image.contains(event.target) && event.target !== stage) return;
            drag = { x: event.clientX, y: event.clientY, px: pan.x, py: pan.y, moved: false };
            if (zoomed) stage.setPointerCapture(event.pointerId);
        });
        stage.addEventListener('pointerup', (event) => {
            if (! drag) return;
            const wasDrag = drag.moved;
            drag = null;
            if (wasDrag) return;
            // A plain click toggles zoom; only clicks on the sheet itself count.
            const box = image.getBoundingClientRect();
            const onSheet = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
            if (zoomed || onSheet) setZoom(! zoomed, { x: event.clientX, y: event.clientY });
        });
        stage.addEventListener('pointercancel', () => { drag = null; });

        zoomButton?.removeAttribute('hidden');
        zoomButton?.addEventListener('click', () => setZoom(! zoomed));
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && zoomed) setZoom(false); });
        document.addEventListener('fullscreenchange', () => { if (zoomed) setZoom(false); });
        stage.addEventListener('dragstart', (event) => event.preventDefault());

        viewer.resetZoom = () => { if (zoomed) setZoom(false); hideLoupe(); };
    }

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
            viewer.resetZoom?.();
        });
    });
});

// Header search: the magnifier opens a search bar over the navigation. Without
// JavaScript it stays a plain link to the Explore search.
const searchToggle = document.querySelector('[data-search-toggle]');
const searchPanel = document.getElementById('header-search');

if (searchToggle && searchPanel) {
    const input = searchPanel.querySelector('input');
    searchToggle.setAttribute('role', 'button');
    searchToggle.setAttribute('aria-expanded', 'false');

    const setOpen = (open) => {
        searchPanel.hidden = ! open;
        searchToggle.setAttribute('aria-expanded', String(open));
        if (open) {
            input.focus();
        } else if (searchPanel.contains(document.activeElement)) {
            searchToggle.focus();
        }
    };

    searchToggle.addEventListener('click', (event) => {
        event.preventDefault();
        setOpen(searchPanel.hidden);
    });
    searchPanel.querySelector('[data-search-close]').addEventListener('click', () => setOpen(false));
    searchPanel.addEventListener('keydown', (event) => { if (event.key === 'Escape') setOpen(false); });
    searchPanel.addEventListener('submit', (event) => {
        // Nothing typed: stay put rather than load an unfiltered Explore page.
        if (input.value.trim() === '') { event.preventDefault(); input.focus(); }
    });
}
