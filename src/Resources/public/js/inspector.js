(function () {
    'use strict';

    // Form-mode: disable submit + show progress hint
    const form = document.getElementById('inspector-form');
    if (form) {
        form.addEventListener('submit', () => {
            const btn = document.getElementById('inspector-submit');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Analysiere... (kann 3–8 Sek dauern)';
            }
        });
    }

    // Result-mode: BBox-overlay toggles + hover-sync
    const overlay = document.querySelector('.bbox-overlay');
    if (!overlay) return;

    const toggleVisible = document.getElementById('bbox-toggle');
    const toggleFill    = document.getElementById('bbox-fill');

    if (toggleVisible) {
        toggleVisible.addEventListener('change', () => {
            overlay.classList.toggle('is-hidden', !toggleVisible.checked);
        });
    }
    if (toggleFill) {
        toggleFill.addEventListener('change', () => {
            overlay.classList.toggle('fill', toggleFill.checked);
        });
    }

    const rects = overlay.querySelectorAll('rect[data-block-id]');
    const rows  = document.querySelectorAll('tr[data-block-id]');

    const setActive = (id) => {
        rects.forEach(r => r.classList.toggle('is-active', r.dataset.blockId === id));
        rows.forEach(r => r.classList.toggle('is-active', r.dataset.blockId === id));
    };
    const clearActive = () => {
        rects.forEach(r => r.classList.remove('is-active'));
        rows.forEach(r => r.classList.remove('is-active'));
    };

    rects.forEach(r => {
        r.addEventListener('mouseenter', () => setActive(r.dataset.blockId));
        r.addEventListener('mouseleave', clearActive);
    });
    rows.forEach(r => {
        r.addEventListener('mouseenter', () => {
            setActive(r.dataset.blockId);
            const rect = overlay.querySelector(`rect[data-block-id="${r.dataset.blockId}"]`);
            if (rect && rect.scrollIntoView) {
                // rects sind in SVG, kein scrollIntoView. Stattdessen Image-Pane scrollen falls nötig — nicht jetzt.
            }
        });
        r.addEventListener('mouseleave', clearActive);
    });
})();
