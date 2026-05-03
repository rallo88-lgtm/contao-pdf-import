/* PDF-Import Demo-Run — Mock-Phasen A–E.
 * Page-spezifischer Code für die "PDF-Import"-BE-Page. Nutzt die
 * shared DosBox-Library aus dos-box.js.
 *
 * Bei der Real-Implementation (Bauplan-Phase 3) werden die Mock-
 * Phasen durch Symfony-StreamedResponse-POSTs ersetzt; die DosBox-
 * API (line/prompt) bleibt unverändert.
 */
(function () {
  "use strict";

  if (!window.DosBox) {
    console.error("PDF-Import-Demo: DosBox library not loaded");
    return;
  }

  const root = document.querySelector("[data-dos=root]");
  if (!root) return;

  const box = new DosBox(root);
  const { sleep, maskKey } = DosBox;

  /* ----- Phasen ------------------------------------------------ */
  async function phaseA_LocalSplit(box) {
    box.line("info", "Phase A · Local Split — Imagick + Smalot");
    await sleep(280);
    box.line("dim", "Reading /files/upload/MBJ-234.pdf (4.2 MB)…");
    await sleep(420);
    for (let p = 1; p <= 24; p++) {
      box.line("ok", `Page ${String(p).padStart(2, "0")} rasterized at 200 dpi`);
      await sleep(40 + Math.random() * 80);
    }
    await sleep(300);
    box.line("info", "Smalot · Page-1-Text: \"Morbus Bechterew Journal · Nr. 234 · Q1/2026\"");
    await sleep(420);
    box.line("ok", "Issue MBJ-234 detected. 24 pages ready.");
    await sleep(300);
  }

  async function phaseB_PageSelection(box) {
    box.line("info", "Phase B · Page-Auswahl");
    await sleep(140);
    while (true) {
      const raw = await box.prompt(
        "Welche Seiten scannen? [A]lle / [L]iste (z.B. 2,4-7,12):",
        (val) => {
          if (!val) return "Leere Eingabe — bitte etwas wählen.";
          if (/^a$/i.test(val)) return null;
          if (/^[\d,\s\-]+$/.test(val)) return null;
          return "Ungültiges Format. Erlaubt: 'A' oder Liste wie '2,4-7,12'.";
        }
      );
      if (/^a$/i.test(raw)) {
        const all = Array.from({ length: 24 }, (_, i) => i + 1);
        box.line("ok", "Selected: alle 24 Seiten");
        return all;
      }
      const pages = DosBox.parseRange(raw);
      if (pages.length === 0) {
        box.line("warn", "Konnte keine Seiten parsen, nochmal:");
        continue;
      }
      const oob = pages.filter(p => p < 1 || p > 24);
      if (oob.length) {
        box.line("warn", `Außerhalb 1–24: ${oob.join(", ")} — nochmal:`);
        continue;
      }
      box.line("ok", `Selected: ${pages.length} Seiten (${pages.join(", ")})`);
      return pages;
    }
  }

  async function phaseC_AwsRound(box, pages, opts = {}) {
    box.line("info", "Phase C · AWS Round (Textract)");
    await sleep(150);
    const apiKey = "AKIAEXAMPLE5XYZabc1";
    box.line("dim", `Calling AWS-Textract with API-Key ${maskKey(apiKey)} (region eu-central-1)…`);
    await sleep(620);

    const blocks = {};
    for (const p of pages) {
      if (opts.failOn === p) {
        box.line("error", `Page ${p}: Textract returned ProvisionedThroughputExceeded — aborting batch.`);
        throw new Error(`textract-fail:${p}`);
      }
      const counts = {
        LAYOUT_TEXT:           4 + Math.floor(Math.random() * 8),
        LAYOUT_TITLE:          Math.random() < 0.3 ? 1 : 0,
        LAYOUT_SECTION_HEADER: 1 + Math.floor(Math.random() * 2),
        LAYOUT_FIGURE:         Math.random() < 0.5 ? 1 : 0,
        LAYOUT_LIST:           Math.random() < 0.3 ? 1 : 0,
      };
      const total = Object.values(counts).reduce((a, b) => a + b, 0);
      const summary = Object.entries(counts)
        .filter(([, n]) => n > 0)
        .map(([k, n]) => `${n}× ${k}`)
        .join(", ");
      blocks[p] = { total, breakdown: counts };
      box.line("ok", `Page ${p}: ${total} blocks (${summary})`);
      await sleep(180 + Math.random() * 220);
    }

    await sleep(200);
    const cost = (pages.length * 0.000625).toFixed(4);
    box.line("info", `Textract-Call cost: $${cost} (${pages.length} pages × $0.000625)`);
    return blocks;
  }

  async function phaseD_ConflictCheck(box, pages) {
    box.line("info", "Phase D · Conflict-Check gegen tl_news");
    await sleep(140);
    const decisions = {};
    for (const p of pages) {
      if (Math.random() > 0.3) {
        box.line("ok", `Page ${p}: kein Konflikt — wird neu angelegt.`);
        decisions[p] = "new";
        continue;
      }
      const choice = await box.prompt(
        `Page ${p} existiert in MBJ-234. [E]rsetzen / [Ü]berspringen / [N]eue Version:`,
        (val) => /^[eüun]$/i.test(val) ? null : "Bitte E, Ü oder N."
      );
      decisions[p] = ({ e: "replace", "ü": "skip", u: "skip", n: "version" })[choice.toLowerCase()];
      box.line("dim", `→ Entscheidung: ${decisions[p]}`);
    }
    return decisions;
  }

  async function phaseE_Insert(box, pages, decisions) {
    box.line("info", "Phase E · Insert (tl_news + tl_content + tl_files)");
    await sleep(180);
    let ok = 0, skipped = 0;
    for (const p of pages) {
      if (decisions[p] === "skip") {
        box.line("dim", `Page ${p}: übersprungen.`);
        skipped++;
        continue;
      }
      box.line("ok", `Page ${p}: Inserting into MBJ-234 (${decisions[p]}) — done.`);
      ok++;
      await sleep(70 + Math.random() * 120);
    }
    await sleep(200);
    box.line("info", `Fertig. ${ok} Seiten importiert, ${skipped} übersprungen.`);
  }

  /* ----- Orchestrator ----------------------------------------- */
  async function runDemo({ failOn = null } = {}) {
    box.clear();
    box.reset();
    box.line("dim", "── PDF-Import demo run · " + new Date().toLocaleTimeString() + " ──");
    try {
      await phaseA_LocalSplit(box);
      const pages   = await phaseB_PageSelection(box);
      await phaseC_AwsRound(box, pages, { failOn });
      const choices = await phaseD_ConflictCheck(box, pages);
      await phaseE_Insert(box, pages, choices);
      box.line("ok", "✓ ALL DONE.");
    } catch (err) {
      box.line("error", "Abort: " + err.message);
      box.line("dim", "Job-State bleibt persistent — User kann via 'Resume' neu einsteigen.");
    } finally {
      box.done();
    }
  }

  /* ----- Boot ------------------------------------------------- */
  document.querySelector("[data-dos-action=run]")
    ?.addEventListener("click", () => runDemo());
  document.querySelector("[data-dos-action=clear]")
    ?.addEventListener("click", () => {
      box.clear();
      box.line("dim", "→ Cleared.");
    });
  document.querySelector("[data-dos-action=fail]")
    ?.addEventListener("click", () => runDemo({ failOn: 5 }));
})();
