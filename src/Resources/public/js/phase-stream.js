/* Generic Phase-Stream-Handler.
 * Hijackt Form-Submit, fetched die Stream-URL, liest SSE-Events aus
 * ReadableStream, emit jedes Event in die DosBox. Bei done-Event mit
 * resultUrl: Auto-Redirect nach 2s + manueller Result-Link.
 *
 * Konvention auf der Seite:
 *   - Form mit data-stream-url Attribut
 *   - Submit-Button: id ends with "-submit" ODER class tl_submit innerhalb des Forms
 *   - Result-Link: id ends with "-result-link" (initial display:none)
 *   - DosBox-Container irgendwo auf der Seite mit [data-dos=root]
 */
(function () {
  "use strict";

  if (!window.DosBox) {
    console.error("phase-stream: DosBox library not loaded");
    return;
  }

  const form = document.querySelector("form[data-stream-url]");
  const dosRoot = document.querySelector("[data-dos=root]");
  if (!form || !dosRoot) return;

  const box        = new DosBox(dosRoot);
  const submitBtn  = form.querySelector('button[type="submit"]');
  const resultLink = document.querySelector('a[id$="-result-link"]');

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = "Verarbeite...";
    }
    if (resultLink) {
      resultLink.style.display = "none";
    }

    box.clear();
    box.reset();
    box.line("dim", "── Phase-Run · " + new Date().toLocaleTimeString() + " ──");

    let response;
    try {
      response = await fetch(form.dataset.streamUrl, {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
      });
    } catch (err) {
      box.line("error", "Network: " + err.message);
      box.done();
      resetSubmit();
      return;
    }

    if (!response.ok) {
      box.line("error", "HTTP " + response.status + " " + response.statusText);
      box.done();
      resetSubmit();
      return;
    }

    const reader  = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer    = "";

    while (true) {
      let chunk;
      try {
        chunk = await reader.read();
      } catch (err) {
        box.line("error", "Stream-Read: " + err.message);
        box.done();
        resetSubmit();
        return;
      }
      if (chunk.done) break;
      buffer += decoder.decode(chunk.value, { stream: true });

      let idx;
      while ((idx = buffer.indexOf("\n\n")) >= 0) {
        const raw = buffer.slice(0, idx);
        buffer    = buffer.slice(idx + 2);
        if (!raw.startsWith("data: ")) continue;

        let evt;
        try {
          evt = JSON.parse(raw.slice(6));
        } catch {
          continue;
        }

        if (evt.type === "done") {
          box.done();
          if (evt.resultUrl && resultLink) {
            resultLink.href = evt.resultUrl;
            resultLink.style.display = "inline-block";
            box.line("info", "→ Auto-Redirect in 2s…");
            setTimeout(() => { window.location.href = evt.resultUrl; }, 2000);
          } else {
            resetSubmit();
          }
          return;
        }

        box.line(evt.type || "info", evt.msg || "");
      }
    }
  });

  function resetSubmit() {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = "Erneut versuchen";
    }
  }
})();
