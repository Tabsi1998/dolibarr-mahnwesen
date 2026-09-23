# Dolibarr Mahnwesen

Custom-Modul für ein kontrolliertes Mahnwesen bei überfälligen Kundenrechnungen in Dolibarr.

> **Status:** stabile Linie `1.0.x`. Automatische Laufzeittests installieren das Modul in Dolibarr 21.0.4, 22.0.5, 23.0.4 und 24.0.1 und prüfen Aktivierung, Zugriffsrechte, einen echten Versand und den automatischen Versand; die CI prüft die verwendeten Dolibarr-21–24-APIs. Der konkrete Produktivbetrieb bleibt vor Ort per Staging-Smoke-Test mit dem eigenen Mailserver zu bestätigen.

## Ziele

- keine Änderungen am Dolibarr-Core
- dauerhafte Mahnfälle und nachvollziehbare Historie
- frei konfigurierbare Mahnstufen und Fristen
- Mahnspesen getrennt für Privatpersonen und Unternehmen
- native Dolibarr-E-Mail-Vorlagen mit HTML-Editor
- Mahn-PDF im Sponge-nahen Dolibarr-Stil
- kontrollierter manueller und automatischer Versand
- Pause unbefristet oder bis zu einem Datum
- Mahnsperre am Kunden oder an der Rechnung, in Dolibarrs eigenen Feldern, mit Grund und Enddatum
- Schutz vor Doppelversand
- möglichst breite Kompatibilität über mehrere Dolibarr-Hauptversionen

## Standard-Mahnstufen

| Stufe | Standard |
|---|---:|
| Zahlungserinnerung | 3 Tage nach Fälligkeit |
| 1. Mahnung | 10 Tage nach Fälligkeit |
| 2. Mahnung | 20 Tage nach Fälligkeit |
| 3. Mahnung | 30 Tage nach Fälligkeit |

Alle Werte sind konfigurierbar.

## Sehr wichtige Workflow-Regel

Die Zeitberechnung und der tatsächliche Mahnworkflow werden getrennt behandelt.

Beispiel: Eine Rechnung ist 25 Tage überfällig. Zeitlich wäre bereits die **2. Mahnung** erreicht. Wenn die **1. Mahnung aber nie versendet wurde**, darf das Modul nicht einfach Stufe 2 senden.

Verbindliche Reihenfolge:

`Zahlungserinnerung -> 1. Mahnung -> 2. Mahnung -> 3. Mahnung`

Eine Stufe gilt erst dann als erledigt, wenn sie

1. erfolgreich versendet wurde, oder
2. von einem berechtigten Benutzer bewusst als erlassen/übersprungen dokumentiert wurde.

Diese Regel gilt **genauso für die Automatik**. Zusätzlich bleiben die konfigurierten Abstände zwischen zwei Stufen auch dann erhalten, wenn eine frühere Stufe verspätet versendet wurde. Bei Schwellen `3 / 10 / 20 / 30` wird eine am 14. Tag versendete Zahlungserinnerung daher nicht schon am 15. Tag von der 1. Mahnung gefolgt, sondern frühestens 7 Tage später. Details: [docs/WORKFLOW.md](docs/WORKFLOW.md).

## Dynamische Aktion auf der Rechnung

Auf der Rechnung erscheint eine klare Hauptaktion, abhängig vom tatsächlichen Workflowzustand, z. B.:

- `Zahlungserinnerung vorbereiten`
- `1. Mahnung vorbereiten`
- `2. Mahnung vorbereiten`
- `3. Mahnung vorbereiten`

Die Mahn-E-Mail-Maske verwendet Dolibarrs native `FormMail`-Komponente: Vorlagenauswahl, Absenderprofile, Empfänger, CC/BCC, Betreff, Zustellbestätigung und HTML-Editor verhalten sich wie in Dolibarrs normaler Versandmaske. Das verpflichtende Mahn-PDF, die optionale Rechnungs-PDF und zusätzliche Uploads werden angezeigt; eine gemeinsame E-Mail-/PDF-Vorschau steht vor dem Versand bereit.


## Ereignisse / Agenda

Der Mahnverlauf bleibt unveränderlich in den eigenen Mahnwesen-Tabellen gespeichert und wird zusätzlich in **Ereignisse/Agenda** der jeweiligen Kundenrechnung gespiegelt. Die zentrale Historienseite zeigt Fallereignisse, Automatikläufe, Versandversuche, Message-IDs, Anhänge und deren SHA-256-Nachweise sowie das Spesen-Nebenbuch.

## Automatischer Versand

Der tägliche Dolibarr-Cronjob synchronisiert Fälle und beendet fällige Pausen. Der tatsächliche Versand ist standardmäßig **AUS** und benötigt zusätzlich die Freigabe je Mahnstufe. Konfigurierbar sind Laufmaximum, Fehlversuche je Fall/Stufe, Empfängerregel und ein Kundenlimit pro Lauf. Der Trockenlauf prüft denselben Workflow ohne E-Mail oder Falländerung. Jeder Lauf wird dauerhaft protokolliert; hat der Mailserver eine Nachricht nachweislich nicht erhalten, wird sie bis zum Fehlerlimit erneut versucht, unklare SMTP-Ergebnisse sperren Wiederholungen bis zur manuellen Klärung. Eine fehlerhafte Rechnung wird übersprungen und gemeldet, statt den ganzen Lauf zu stoppen. Der Trockenlauf trifft genau die Entscheidung des Crons, eine einstellbare Adresse wird über Läufe mit Problemen benachrichtigt, und die fehlgeschlagenen Versuche eines Laufs lassen sich in einem Schritt freigeben.

## E-Mail-Vorlagen

Die Vorlagen werden nativ unter **Einstellungen -> E-Mail -> E-Mail-Vorlagen** gepflegt. Das Modul stellt je Mahnstufe einen eigenen Vorlagentyp bereit. Neben Dolibarrs normalen Variablen gibt es zusätzliche Mahnwesen-Platzhalter.

Siehe [docs/VARIABLES.md](docs/VARIABLES.md).

## Mahnspesen

Mahnspesen stehen in **Mahnprofilen**: Ein Profil legt je Stufe Tage, Spesen und Zahlungsfrist fest, dazu ob automatisch gesendet werden darf und was nach der letzten Stufe kommt (Inkasso prüfen, Mitgliedschaft prüfen oder nichts). Welches Profil gilt, entscheidet in dieser Reihenfolge: die Wahl an der Rechnung (Feld „Mahnprofil“), die Kategorien der Produkte und Leistungen auf der Rechnung, die Kategorien des Kunden, der Kundentyp (Privatperson oder Firma), sonst das Standardprofil. Passen mehrere Profile, gilt das vorsichtigste: ohne automatischen Versand vor einem mit, dann die niedrigsten Spesen. So bekommen ein Mitgliedsbeitrag, ein Verkauf an ein Mitglied und eine Sponsorrechnung jeweils die passenden Regeln. Der Reiter „Mahnwesen“ an der Rechnung und der Testlauf nennen das Profil und den Grund.

Beim Update auf 1.3.1 werden die bisherigen Spesen für Unternehmen und Privatpersonen zu Profilen, sodass jeder Kunde dieselben Spesen zahlt wie vorher.

## Mahnstatus über die API lesen

Andere Anwendungen können den Mahnstatus über Dolibarrs eigene REST-API lesen, nur lesend. Es gibt zwei getrennte Rechte: *Betrieb* sieht den Stand der ganzen Entity samt Automatik und letztem Lauf, *Kunde* nur die Kunden, für die der technische Benutzer in Dolibarr als Vertriebsmitarbeiter eingetragen ist. Diese Zuordnung ist der Nachweis; eine Kundennummer aus der Anfrage allein zählt nicht. Interne Notizen, E-Mail-Texte, Empfänger und fremde Kunden gibt die API nie heraus; eine unbekannte, fremde oder nicht erlaubte Rechnung antwortet gleich, damit nichts über ihre Existenz verrät. Über dieselbe API lassen sich auch die tatsächlich versandten Mahnschreiben lesen: die archivierten Bytes von damals samt Prüfsumme, nie eine neu erzeugte Fassung. Entwürfe, Vorschauen und unklare Versandversuche gibt es dort nicht. Einzelheiten in [docs/API.md](docs/API.md).

## Ereignisse für andere Module

Jede Änderung an einem Mahnfall wird als Ereignis vermerkt und danach über Dolibarrs eigene Auslöser gemeldet: gesendete Mahnung, letzte Stufe, Pause, Fortsetzung, Wiederöffnung, Abschluss. Der Vermerk entsteht in derselben Transaktion wie die Änderung, die Meldung erst danach. Scheitert ein Empfänger, bleibt das Ereignis mit gezähltem Versuch offen; es wird nie eine Mahnung doppelt versendet und keine Änderung zurückgerollt. Gemeldet werden nur Verweise und Zustände - keine E-Mail-Texte, Empfänger, Bankdaten oder Begründungen. Die Seite *Versandversuche* zeigt die Ereignisse und was noch offen ist.

## Zahlungen

Eine Zahlung, ein Zahlungsstorno oder eine Korrektur an der Rechnung wirkt sofort auf den Mahnfall, über Dolibarrs eigene Auslöser. Eine Teilzahlung senkt den offenen Betrag, die vollständige Zahlung beendet den Fall, ein Storno öffnet ihn wieder, ohne bereits versandte Mahnungen zu wiederholen. Der tägliche Lauf bleibt als Netz bestehen. Mahnspesen und Zinsen im Nebenbuch sind davon getrennt: Die Zahlung der Rechnung bezahlt sie nicht automatisch.

## Mitgliedsbeiträge

Eine Beitragsrechnung erkennt das Modul an Dolibarrs eigener Verknüpfung zwischen Rechnung und Mitgliedsbeitrag, egal ob sie über die Mitgliedskarte oder einen Beitragslauf entstanden ist. Nur diese Verknüpfung gilt als Nachweis: Ein Verkauf an ein Mitglied bleibt ein gewöhnlicher Verkauf, auch bei gleicher Kundenkategorie oder E-Mail. Die Vorlage *Mitgliedsbeitrag* legt dafür ein Profil an, ausgeschaltet, ohne Gebühren und ohne Automatik, mit dem letzten Schritt „Mitgliedschaft prüfen“. Ist eine Rechnung zugleich Beitrag und Verkauf, gilt das vorsichtigste Profil. Der Reiter *Mahnwesen* verlinkt zur Mitgliedschaft, wenn der Benutzer Mitglieder sehen darf.

## Spesen und Zinsen in Rechnung stellen

Offene Mahnspesen und Verzugszinsen eines Falls lassen sich mit einem Knopf am Reiter *Mahnwesen* als eigener Rechnungsentwurf in Dolibarr anlegen: je Forderung eine Zeile, ohne Umsatzsteuer, mit Hinweis auf die ursprüngliche Rechnung. Die ursprüngliche Rechnung bleibt unverändert. Die Forderungen verweisen dann auf die neue Rechnung und gelten als bezahlt, sobald diese bezahlt ist; das trägt der tägliche Lauf ein. Was so in Rechnung gestellt wurde, wird in späteren Mahnungen nicht noch einmal verlangt.

## Verzugszinsen

Je Profil lässt sich eine Zinsregel eintragen: ein fester Satz im Jahr oder der Basiszinssatz plus ein Aufschlag in Prozentpunkten. Die Basiszinssätze pflegen Sie mit ihrem Gültigkeitsbeginn unter *Einrichtung > Verzugszinsen*; der Basiszinssatz ändert sich halbjährlich. Gerechnet wird Tag für Tag ab dem Tag nach der Fälligkeit auf den offenen Betrag, auch über einen Wechsel des Basiszinssatzes hinweg. Die Zinsen stehen im Mahnschreiben, in der E-Mail und im Nebenbuch; die Dolibarr-Rechnung bleibt unverändert. Ohne Zinsregel fallen keine Zinsen an. Welcher Satz zulässig ist, hängt von Kundenart und Land ab (Österreich § 1000 ABGB, § 456 UGB; Deutschland § 288 BGB) - das Modul rechnet nur mit den Sätzen, die Sie eintragen.

Die Spesen verändern **nicht** den Originalbetrag der Dolibarr-Rechnung und erzeugen keinen Buchungssatz. Nach erfolgreichem Versand werden sie in einem eigenen Nebenbuch geführt und müssen ausdrücklich als bezahlt oder erlassen verbucht werden. Der technische Standardwert ist 0,00.

## Voraussetzungen

| | Mindestens | Getestet |
| --- | --- | --- |
| Dolibarr | 21.0 | 21.0.4, 22.0.5, 23.0.4, 24.0.1 |
| PHP | 7.4 | 7.4, 8.1, 8.2, 8.3, 8.4 |
| Dolibarr-Module | Rechnungen | Agenda für den Verlauf an der Rechnung, Cron für die Automatik |

## Installation und Updates

Jede Änderung erscheint als eigenes Release „Mahnwesen vX.Y.Z“ mit Patchnotes auf der [Release-Seite](https://github.com/Tabsi1998/dolibarr-mahnwesen/releases).

1. `module_mahnwesen-x.y.z.zip` herunterladen. Nicht umbenennen: Dolibarr nimmt nur diesen Namen an und leitet daraus den Modulordner ab.
2. In Dolibarr *Start > Einstellungen > Module > Externes Modul bereitstellen* öffnen und das ZIP hochladen. Ein vorhandener Ordner `custom/mahnwesen` wird dabei ersetzt.
3. **Mahnwesen** in der Modulliste aktivieren - bei einem Update einmal deaktivieren und wieder aktivieren. Mahnfälle, Historie und Einstellungen bleiben erhalten.
4. Die Automatik erst nach einem Test auf einem Test-System einschalten.

Wer lieber mit Git arbeitet, klont das Repository als `htdocs/custom/mahnwesen` und aktualisiert mit `git pull`; ein ZIP-Upload über diesen Ordner würde den `.git`-Ordner löschen.

## Kompatibilität

Das Projekt wird nicht auf eine einzelne Dolibarr-Version gebrandet. Zielmatrix und Teststatus: [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## Dokumentation

- [Roadmap](ROADMAP.md)
- [Architektur](docs/ARCHITECTURE.md)
- [Mahnworkflow](docs/WORKFLOW.md)
- [Kompatibilität](docs/COMPATIBILITY.md)
- [Betrieb / Upgrade](docs/OPERATIONS.md)
- [Versionen und Releases](docs/RELEASES.md)
- [E-Mail-/PDF-Variablen](docs/VARIABLES.md)
- [Sicherheitsmodell](SECURITY.md)
- [Versionshistorie](CHANGELOG.md)

## Lizenz

GPL-3.0-or-later, siehe [LICENSE](LICENSE).
