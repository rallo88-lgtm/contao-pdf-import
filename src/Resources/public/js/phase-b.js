/* Phase B Page-Selector — Click-Toggle + Range-Input + Counter sync. */
(function () {
  "use strict";

  const grid       = document.getElementById("phase-b-grid");
  const rangeInput = document.getElementById("phase-b-range");
  const hiddenInput = document.getElementById("phase-b-selected-pages");
  const counter    = document.getElementById("phase-b-selected-count");
  const submitBtn  = document.getElementById("phase-b-submit");
  if (!grid || !rangeInput || !hiddenInput || !counter || !submitBtn) return;

  const thumbs = Array.from(grid.querySelectorAll(".phase-b-thumb"));

  function getSelected() {
    return thumbs
      .filter(t => t.querySelector("[data-phase-b-checkbox]").checked)
      .map(t => parseInt(t.dataset.page, 10))
      .sort((a, b) => a - b);
  }

  function compactRange(pages) {
    if (pages.length === 0) return "";
    const out = [];
    let start = pages[0], prev = pages[0];
    for (let i = 1; i <= pages.length; i++) {
      if (pages[i] === prev + 1) {
        prev = pages[i];
        continue;
      }
      out.push(start === prev ? String(start) : start + "-" + prev);
      start = pages[i];
      prev = pages[i];
    }
    return out.join(",");
  }

  function parseRange(str) {
    const out = new Set();
    for (const part of String(str).split(/\s*,\s*/).filter(Boolean)) {
      const m = part.match(/^(\d+)(?:\s*-\s*(\d+))?$/);
      if (!m) continue;
      const a = +m[1], b = m[2] ? +m[2] : a;
      const [lo, hi] = a <= b ? [a, b] : [b, a];
      for (let i = lo; i <= hi; i++) out.add(i);
    }
    return [...out].sort((a, b) => a - b);
  }

  function refreshUI() {
    const selected = getSelected();
    counter.textContent = String(selected.length);
    hiddenInput.value = selected.join(",");
    rangeInput.value = compactRange(selected);
    submitBtn.disabled = selected.length === 0;
    submitBtn.textContent = selected.length === 0
      ? "Mind. 1 Seite wählen…"
      : `Auswahl speichern (${selected.length} Seiten) → Phase C`;
  }

  function setSelection(pages) {
    const set = new Set(pages);
    thumbs.forEach(t => {
      const p   = parseInt(t.dataset.page, 10);
      const cb  = t.querySelector("[data-phase-b-checkbox]");
      const sel = set.has(p);
      cb.checked = sel;
      t.classList.toggle("is-selected", sel);
    });
    refreshUI();
  }

  // Thumbnail click → toggle
  thumbs.forEach(t => {
    t.addEventListener("click", (e) => {
      e.preventDefault();
      const cb  = t.querySelector("[data-phase-b-checkbox]");
      cb.checked = !cb.checked;
      t.classList.toggle("is-selected", cb.checked);
      refreshUI();
    });
  });

  // Range-Input → applied to thumbs
  rangeInput.addEventListener("input", () => {
    const pages = parseRange(rangeInput.value);
    setSelection(pages);
    // refreshUI called inside setSelection — but we just typed in the rangeInput,
    // which would be overwritten by compactRange. Skip the rangeInput.value sync
    // when input event came from rangeInput itself. Simple: re-set rangeInput
    // back to user's typed value after counter+hidden sync.
    counter.textContent = String(pages.length);
    hiddenInput.value = pages.join(",");
    submitBtn.disabled = pages.length === 0;
    submitBtn.textContent = pages.length === 0
      ? "Mind. 1 Seite wählen…"
      : `Auswahl speichern (${pages.length} Seiten) → Phase C`;
  });

  // Quick-Buttons
  document.querySelectorAll("[data-phase-b-action]").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const action = btn.dataset.phaseBAction;
      const allPages = thumbs.map(t => parseInt(t.dataset.page, 10));
      if (action === "all") {
        setSelection(allPages);
      } else if (action === "none") {
        setSelection([]);
      } else if (action === "invert") {
        const current = new Set(getSelected());
        setSelection(allPages.filter(p => !current.has(p)));
      }
    });
  });

  // Initial state
  refreshUI();
})();
