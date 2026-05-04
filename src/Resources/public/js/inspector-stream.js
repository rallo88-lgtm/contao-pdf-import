/* Inspektor-Form-Hijack: Submit triggert SSE-Stream zum
 * PdfImportInspectorStreamController, jedes Event landet in der DOS-Box.
 * Final-Event mit resultUrl redirected nach 2s zum Result-Mode der gleichen
 * BE-Seite (?run=hash). Bei Error/no-resultUrl bleibt Form aktiv für
 * Retry. */
(function () {
  "use strict";

  if (!window.DosBox) {
    console.error("inspector-stream: DosBox library not loaded");
    return;
  }

  const form     = document.getElementById("inspector-form");
  const dosRoot  = document.querySelector("[data-dos=root]");
  if (!form || !dosRoot) return;

  const box        = new DosBox(dosRoot);
  const submitBtn  = document.getElementById("inspector-submit");
  const resultLink = document.getElementById("inspector-result-link");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (!form.dataset.streamUrl) {
      box.line("error", "Form fehlt data-stream-url Attribut.");
      box.done();
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = "Analysiere…";
    }
    if (resultLink) {
      resultLink.style.display = "none";
    }

    box.clear();
    box.reset();
    box.line("dim", "── Inspector-Run · " + new Date().toLocaleTimeString() + " ──");

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
            box.line("info", "→ Auto-Redirect zur Ergebnis-Page in 2s…");
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
