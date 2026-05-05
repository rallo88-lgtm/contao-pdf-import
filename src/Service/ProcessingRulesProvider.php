<?php

namespace Rallo\ContaoPdfImport\Service;

/**
 * Read-only Quelle der Wahrheit für die Verarbeitungs-Regeln und Heuristiken
 * im PDF-Import-Bundle. Wird in der Config-UI als Erklärungstabelle gerendert.
 *
 * Wenn spaeter Phase 4 Mapping-Editor kommt (UI-konfigurierbare Regeln),
 * kann dieser Provider zu einer dynamischen Datenquelle (DCA-backed)
 * erweitert werden.
 */
final class ProcessingRulesProvider
{
    /**
     * Technische Verarbeitungs-Regeln. Was passiert in Phase C/E mit den
     * Textract-Bloecken vor dem Insert in tl_news/tl_content.
     *
     * @return array<int, array{topic: string, rule: string, why: string}>
     */
    public function getProcessingRules(): array
    {
        return [
            [
                'topic' => 'Echtes Datum',
                'rule'  => 'Klammer-Inhalt aus LAYOUT_FOOTER (z.B. "März 2026", "Mai/Juni 2026", "01/2026") wird zum Monatsanfang als tl_news.date. Fallback: deterministicDate (BASE_TIME + IssueNum*1000 + PageNr).',
                'why'   => 'Echtes Datum macht die News im BE/FE sortierbar und chronologisch verständlich. Fallback bleibt für Detection-Fail-Cases als reiner Sortier-Schlüssel.',
            ],
            [
                'topic' => 'Page-Index als Minute',
                'rule'  => 'tl_news.time = date + pageNumber*60. BE zeigt 00:01, 00:02, ... pro Page der gleichen Ausgabe.',
                'why'   => 'Visuell sichtbarer Sortier-Hint im BE-Listing für mehrere Pages aus derselben Ausgabe.',
            ],
            [
                'topic' => 'Match-Key (Conflict-Detection)',
                'rule'  => 'tl_news.headline = "MBJ-{nr} Seite {pageNr}" als deterministischer Schlüssel für Phase D Conflict-Check. Unabhängig vom date-Wert.',
                'why'   => 'Headline ist robust gegen Date-Quirks und Re-Imports. Wenn Bettina die Headline manuell umbenennt, ändert sich die Conflict-Erkennung — bewusste Trade-off.',
            ],
            [
                'topic' => 'Caption-Heuristik',
                'rule'  => 'Pro LAYOUT_FIGURE wird der erste LAYOUT_TEXT direkt darunter als Bildunterschrift erkannt. Bedingungen: vertikal <5% Diff, Höhe <6% (max ~3 Zeilen), horizontal innerhalb der Figure-Bounds ±2%.',
                'why'   => 'Caption landet im Image-CE caption-Feld statt als separater <p>. Saubere figure/figcaption-Semantik im FE.',
            ],
            [
                'topic' => 'Liste mit Items',
                'rule'  => 'LAYOUT_LIST liefert die Items als CHILD-LAYOUT_TEXTs. Die werden zu einem tl_content type=list mit serialisiertem listitems-Array zusammengefasst. Parallel auf Top-Level erscheinende LAYOUT_TEXTs (= dieselben Items) werden geskippt.',
                'why'   => 'Textract liefert List-Items doppelt — einmal nested, einmal flach. Ohne Dedup würde jedes Item doppelt im News-Output erscheinen.',
            ],
            [
                'topic' => 'Reading-Order Multi-Column',
                'rule'  => 'Trigger: 3 aufeinander folgende LAYOUT_FIGUREs mit paarweise ≥10% Diff auf left-Position. Span beginnt bei der Triple und endet bei: vollbreitem Block (Width >0.5), LAYOUT_SECTION_HEADER, LAYOUT_TITLE oder Block ausserhalb der Triple-Cluster. Innerhalb des Spans: sortiert nach (column-index, top).',
                'why'   => 'Textract liefert Multi-Column-Pages oft typgruppiert (alle Bilder, dann alle Texte). Reorder produziert linearen News-Output: Spalte 1 komplett, dann 2, dann 3. Trigger nur bei 3-Bilder-Sequenz, damit reine Single-Column-Pages unangetastet bleiben.',
            ],
            [
                'topic' => 'Bild-Skalierung im Newsreader',
                'rule'  => 'img bekommt max-width:100% + height:auto. Figure mit text-align:center.',
                'why'   => 'Kleine Bilder (Icons, QR-Codes) bleiben in intrinsic Crop-Größe, nur grosse Diagramme werden auf Container-Breite gekappt.',
            ],
            [
                'topic' => 'Multi-Column-Infografik-Split',
                'rule'  => 'Vollbreite LAYOUT_FIGUREs (Width >0.5) deren WORD-Children in ≥3 distinkten left-Clustern liegen (je ≥3 Members, paarweise ≥10% Gap) werden in N Sub-Crops geteilt und als tl_content type=gallery eingebaut (multiSRC + perRow=N + cssClass="rct-infografik"). Caption gilt als gemeinsame Bildunterschrift fuer die Gesamtgrafik.',
                'why'   => 'Magazin-Infografiken (z.B. 3 Donut-Charts nebeneinander) sind auf Mobile bei 100% Breite zu klein zum Lesen. Sub-Crops erlauben Desktop nahtlos nebeneinander (= sieht aus wie Original) und ab <768px gestackt (= jeder Sub-Crop voll lesbar).',
            ],
            [
                'topic' => 'Soft-Delete für Jobs',
                'rule'  => 'tl_pdf_import_job.deleted_at IS NULL filtert das Listing. Stats lesen aus tl_pdf_import_event und sind unbeeinflusst.',
                'why'   => 'Statistik-Ground-Truth (Token-Verbrauch, Phase-Durations) bleibt vollstaendig erhalten, Listing aber aufgeräumt.',
            ],
            [
                'topic' => 'Resume-Routing',
                'rule'  => 'Pro Job-Status mappt JobStatus::resumeRoute() zur passenden Phase-Page: created/split_done → Phase B, pages_selected → Phase C, ocr_done → Phase D, conflicts_resolved → Phase E. Terminale Stati (completed/failed/cancelled) liefern null.',
                'why'   => 'User kann unterbrochene Jobs an exakt der Stelle wieder aufnehmen wo sie steckengeblieben sind.',
            ],
        ];
    }

    /**
     * Hinweise fuer Bettina/Redakteur:innen — was beim PDF-Layout zu
     * beachten ist damit der Auto-Import sauber läuft. Keine
     * Code-Regeln, sondern Best-Practice-Empfehlungen.
     *
     * @return array<int, array{topic: string, rule: string}>
     */
    public function getEditorialHints(): array
    {
        return [
            [
                'topic' => 'Keine Text-Bilder im Background',
                'rule'  => 'Textract extrahiert jeden lesbaren Text — auch aus dekorativen BG-Motiven (z.B. Formulare als Stilelement). Diese Texte landen im News-Body und müssen manuell geloescht werden. Lösung: Background-Motive ohne Text wählen.',
            ],
            [
                'topic' => 'Inhalts-Bilder mit klaren Konturen',
                'rule'  => 'Bilder mit weichen Übergängen oder geringem Kontrast werden manchmal als TEXT/UNKNOWN klassifiziert und nicht als LAYOUT_FIGURE erkannt. Klare Bildränder + farbliche Trennung helfen.',
            ],
            [
                'topic' => 'Bildunterschrift max. 255 Zeichen',
                'rule'  => 'Contao tl_content.caption ist auf 255 Zeichen limitiert. Längere Captions werden gekürzt — bitte schon im PDF kompakt formulieren oder im News-CE manuell nachpflegen. Ausserdem: das "Metadaten überschreiben"-Häkchen im Image-CE muss gesetzt sein damit die Caption sichtbar wird.',
            ],
            [
                'topic' => 'Inspector vor Phase E nutzen',
                'rule'  => 'Per Page im Inspector prüfen ob die Block-Klassifizierung passt (Bild/Text/Liste/Caption). Bei Chaos-Pages (z.B. wenn TEXT statt FIGURE erkannt wird): PDF-Layout anpassen statt nachträglich aufräumen.',
            ],
            [
                'topic' => '3-Spalten-Magazin-Layout',
                'rule'  => 'Pages mit 3 Bildern oben + 3 Spalten Text darunter (z.B. Kursangebote) werden automatisch spaltenweise linearisiert: Bild-1, Text-1, Bild-2, Text-2, Bild-3, Text-3. Funktioniert nur wenn die 3 Bilder direkt aufeinander folgen und in distinkten Spalten liegen.',
            ],
            [
                'topic' => 'Issue-Datum in der Klammer',
                'rule'  => 'Im PDF-Footer "Nr. 184 (März 2026)" — der Klammer-Inhalt liefert das Datum für tl_news.date. Format "Monat YYYY" oder "MM/YYYY" wird erkannt. "Frühjahr 2026" o.ä. nicht — dann fällt das System auf den deterministicDate-Sortier-Schlüssel zurück.',
            ],
        ];
    }
}
