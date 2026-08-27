# Dolibarr Mahnwesen

Custom-Modul für ein kontrolliertes Mahnwesen bei überfälligen Kundenrechnungen in Dolibarr.

> **Status:** technisch gehärtete Entwicklungslinie `0.6.x`. Dolibarr 22 wurde praktisch getestet. Die CI lädt und prüft die echten Dolibarr-21/22/23-APIs; vollständige Installationstests auf 21 und 23 bleiben Release-Voraussetzung.

## Ziele

- keine Änderungen am Dolibarr-Core
- dauerhafte Mahnfälle und nachvollziehbare Historie
- frei konfigurierbare Mahnstufen und Fristen
- Mahnspesen getrennt für Privatpersonen und Unternehmen
- native Dolibarr-E-Mail-Vorlagen mit HTML-Editor
- Mahn-PDF im Sponge-nahen Dolibarr-Stil
- kontrollierter manueller und später automatischer Versand
- Pause unbefristet oder bis zu einem Datum
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

Verbindliche Reihenfolge im aktuellen Entwicklungsstand:

`Zahlungserinnerung -> 1. Mahnung -> 2. Mahnung -> 3. Mahnung`

Eine Stufe gilt erst dann als erledigt, wenn sie

1. erfolgreich versendet wurde, oder
2. von einem berechtigten Benutzer bewusst als erlassen/übersprungen dokumentiert wurde.

Diese Regel gilt **genauso für die Automatik**. Zusätzlich bleiben die konfigurierten Abstände zwischen zwei Stufen auch dann erhalten, wenn eine frühere Stufe verspätet versendet wurde. Bei Schwellen `3 / 10 / 20 / 30` wird eine am 14. Tag versendete Zahlungserinnerung daher nicht schon am 15. Tag von der 1. Mahnung gefolgt, sondern frühestens 7 Tage später. Details: [docs/WORKFLOW.md](docs/WORKFLOW.md).

## Dynamische Aktion auf der Rechnung

Der aktuelle Entwicklungsstand ergänzt auf der Rechnung eine klare Hauptaktion, abhängig vom tatsächlichen Workflowzustand, z. B.:

- `Zahlungserinnerung vorbereiten`
- `1. Mahnung vorbereiten`
- `2. Mahnung vorbereiten`
- `3. Mahnung vorbereiten`

Die Mahn-E-Mail-Maske orientiert sich am normalen Dolibarr-Rechnungsversand: Empfänger, Absender, Betreff, HTML-Inhalt, Mahn-PDF und optional Rechnungs-PDF werden direkt kontrollierbar angezeigt, bevor versendet wird.


## Ereignisse / Agenda

Der Mahnverlauf bleibt unveränderlich in den eigenen Mahnwesen-Tabellen gespeichert und wird zusätzlich in **Ereignisse/Agenda** der jeweiligen Kundenrechnung gespiegelt. Dadurch sieht man Mahnfall-Anlage, Pause/Fortsetzung, interne Bearbeitung und Versandereignisse dort, wo Dolibarr auch andere Rechnungsereignisse anzeigt. Die Spiegelung ist idempotent; wiederholte Synchronisierung erzeugt keine doppelten Agenda-Einträge.

## E-Mail-Vorlagen

Die Vorlagen werden nativ unter **Einstellungen -> E-Mail -> E-Mail-Vorlagen** gepflegt. Das Modul stellt je Mahnstufe einen eigenen Vorlagentyp bereit. Neben Dolibarrs normalen Variablen gibt es zusätzliche Mahnwesen-Platzhalter.

Siehe [docs/VARIABLES.md](docs/VARIABLES.md).

## Mahnspesen

Mahnspesen sind pro Stufe und Kundentyp konfigurierbar. Ein Dolibarr-Drittpartei-Datensatz kann ausdrücklich eine Privatperson sein; `TE_PRIVATE` wird entsprechend behandelt. Unklare Typen sollen sicherheitshalber nicht automatisch wie B2B behandelt werden.

Die Spesen verändern **nicht** den Originalbetrag der Dolibarr-Rechnung und erzeugen keinen Buchungssatz. Nach erfolgreichem Versand werden sie in einem eigenen Nebenbuch geführt und müssen ausdrücklich als bezahlt oder erlassen verbucht werden. Der technische Standardwert ist 0,00.

## Installation

Git-Checkout direkt in den Custom-Ordner:

```bash
git clone https://github.com/Tabsi1998/dolibarr-mahnwesen.git htdocs/custom/mahnwesen
```

Release-ZIPs verwenden das Format `mahnwesen-x.y.z.zip` mit dem Ordner `mahnwesen/` als ZIP-Wurzel.

Nach dem Upgrade auf `0.6.x` das Modul einmal deaktivieren und wieder aktivieren. Dadurch werden die neuen Tabellen für Versandversuche, Pausen und das Spesen-Nebenbuch angelegt; Mahnfälle und Historie bleiben erhalten. Die Automatik erst nach einem Staging-Smoke-Test aktivieren.

## Kompatibilität

Das Projekt wird nicht auf eine einzelne Dolibarr-Version gebrandet. Zielmatrix und Teststatus: [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## Dokumentation

- [Roadmap](ROADMAP.md)
- [Architektur](docs/ARCHITECTURE.md)
- [Mahnworkflow](docs/WORKFLOW.md)
- [Kompatibilität](docs/COMPATIBILITY.md)
- [Betrieb / Upgrade](docs/OPERATIONS.md)
- [E-Mail-/PDF-Variablen](docs/VARIABLES.md)
- [Sicherheitsmodell](SECURITY.md)
- [Versionshistorie](CHANGELOG.md)

## Lizenz

GPL-3.0-or-later, siehe [LICENSE](LICENSE).

- [Release-Strategie](docs/RELEASES.md)
