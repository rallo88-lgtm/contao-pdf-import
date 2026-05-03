/* ============================================================
 * DosBox — Vanilla-JS-Library für SSE-getriebene BE-Operationen
 * Bundle-shared component aus contao-pdf-import (kann von anderen
 * Toolbox-Bundles per <script src="bundles/contaopdfimport/js/dos-box.js">
 * mitbenutzt werden).
 *
 * Public API:
 *   const box = new DosBox(rootElement);   // findet sub-elemente per [data-dos]-Attribut
 *   box.line(type, msg)         — type: ok|warn|error|info|dim|prompt
 *   box.lineRaw(html)           — pre-styled HTML
 *   box.clear() / done() / reset()
 *   await box.prompt(text, validator?)   — wartet auf User-Input
 *   DosBox.parseRange("2,4-7")  — Static-Helper → [2,4,5,6,7]
 *   DosBox.maskKey("AKIA...")   — Static-Helper für API-Key-Maskierung
 *   DosBox.sleep(ms)            — Static-Helper Promise
 * ============================================================ */
(function (global) {
  "use strict";

  class DosBox {
    constructor(root) {
      if (!root) {
        throw new Error("DosBox: root element required");
      }
      this.root        = root;
      this.output      = root.querySelector("[data-dos=output]");
      this.cursor      = root.querySelector("[data-dos=cursor]");
      this.inputArea   = root.querySelector("[data-dos=input-area]");
      this.promptText  = root.querySelector("[data-dos=input-prompt]");
      this.inputField  = root.querySelector("[data-dos=input-field]");

      if (!this.output || !this.cursor) {
        throw new Error("DosBox: required sub-elements missing ([data-dos=output], [data-dos=cursor])");
      }
    }

    line(type, msg, glyph) {
      const el = document.createElement("div");
      el.className = "dos-line " + type;
      const g = (glyph !== undefined ? glyph : DosBox.GLYPHS[type]);
      if (g) {
        const gEl = document.createElement("span");
        gEl.className = "dos-glyph";
        gEl.textContent = g;
        el.appendChild(gEl);
      }
      el.appendChild(document.createTextNode(msg));
      this.output.insertBefore(el, this.cursor);
      this._scroll();
    }

    lineRaw(html) {
      const el = document.createElement("div");
      el.className = "dos-line";
      el.innerHTML = html;
      this.output.insertBefore(el, this.cursor);
      this._scroll();
    }

    clear() {
      [...this.output.children].forEach(c => {
        if (c !== this.cursor) c.remove();
      });
      this.cursor.classList.remove("is-done");
    }

    done()  { this.cursor.classList.add("is-done"); }
    reset() { this.cursor.classList.remove("is-done"); }

    _scroll() {
      this.output.scrollTop = this.output.scrollHeight;
    }

    prompt(text, validator) {
      return new Promise((resolve) => {
        if (!this.inputArea || !this.inputField || !this.promptText) {
          throw new Error("DosBox: prompt() requires [data-dos=input-area|input-prompt|input-field] sub-elements");
        }
        this.line("prompt", text);
        this.cursor.classList.add("is-done");
        this.promptText.textContent = text.replace(/^\?\s*/, "") + " ";
        this.inputArea.classList.add("is-active");
        this.inputField.value = "";
        this.inputField.focus();

        const handler = (ev) => {
          if (ev.key !== "Enter") return;
          const val = this.inputField.value.trim();
          if (validator) {
            const err = validator(val);
            if (err) {
              this.line("warn", err);
              return;
            }
          }
          this.inputField.removeEventListener("keydown", handler);
          this.inputArea.classList.remove("is-active");
          this.line("input", val || "(leer)");
          this.cursor.classList.remove("is-done");
          resolve(val);
        };
        this.inputField.addEventListener("keydown", handler);
      });
    }

    static parseRange(str) {
      const out = new Set();
      for (const part of String(str).split(/\s*,\s*/).filter(Boolean)) {
        const m = part.match(/^(\d+)(?:\s*-\s*(\d+))?$/);
        if (!m) continue;
        const a = +m[1], b = m[2] ? +m[2] : a;
        const [lo, hi] = a <= b ? [a, b] : [b, a];
        for (let i = lo; i <= hi; i++) out.add(i);
      }
      return [...out].sort((x, y) => x - y);
    }

    static maskKey(key) {
      const s = String(key);
      if (s.length <= 8) return s;
      return s.slice(0, 4) + "****" + s.slice(-4);
    }

    static sleep(ms) {
      return new Promise(r => setTimeout(r, ms));
    }

    static GLYPHS = {
      ok:     "✓",
      warn:   "⚠",
      error:  "✗",
      info:   "→",
      dim:    "·",
      prompt: "?",
    };
  }

  global.DosBox = DosBox;
})(window);
