# Übernahme der dev-Korrekturen für OpenCalendar 3.0

Die Architektur von `dev_9.1` bleibt die Grundlage für OpenCalendar 3.0 und
Symcon 9.1. Die Übernahme ist ein fachlicher Abgleich, kein vollständiger Merge
des älteren `dev`-Codes. Ausgangspunkt des Abgleichs sind `dev` bei `7cc640a`
und `dev_9.1` bei `086eb43`.

## Übernahmeregeln

### Ergänzung: native To-Do-Serien (dev `f0deaa9`)

Die Folgekorrekturen wurden auf Basis von `dev_9.1` bei `d3a03ae` fachlich
übernommen, ohne den älteren Modulaufbau zu übernehmen:

- Frischer Aufgabenstand vor Datumsänderungen; Neuausrichtung nativer Serien
  über ihre Wiederholung statt eines duplizierenden Fälligkeits-PATCH.
- Bereits von Microsoft reduzierte Restanzahl unverändert übernehmen.
- Verschieben vor Erledigen; Identität und lokales Rückgabedatum prüfen,
  bei Abweichung einmal nachlesen und keine anschließende Erledigung senden.
- Unveränderte Bearbeitungen ohne Schreibzugriff erfolgreich abschließen.
- Hinweis im bestehenden 9.1-Dialog und Dokumentation der Zeitzoneneinschränkung.

Die vorhandene 9.1-Fälligkeitsumrechnung einschließlich UTC-Quellformat,
Sekundenbruchteilen und Sommerzeit-Roundtrips bleibt erhalten. Die öffentliche
Quellzeit- und Zeitzonenauflösung wird nur um eine lokale Datumsansicht ergänzt.
Providerneutrale Fehlerverträge, 9.1-UI, zentrale Helper, Metadaten und
Upgrade-Strukturen bleiben unverändert. Die Graph-Zeitzonenabweichung wird
erkannt, nicht serverseitig behoben; die Zeitzonenfälle sind lokale HTTP-Replays,
kein zusätzlicher Live-Nachweis für 9.1.

### Allgemeine Regeln

- Bereits gleichwertig gelöste Fehler werden nicht erneut implementiert.
- Fehlendes Verhalten wird an den bestehenden 9.1-Schnittstellen ergänzt.
- Providerneutrale Verträge, lokale Originaldaten und Upgrade-Kompatibilität
  werden nicht durch ältere Implementierungen ersetzt.
- Zentrale Helper werden als versionierte Bundles mit Manifest übernommen;
  ihre Aufrufer werden in die bestehende 9.1-Modulstruktur eingepasst.

## Betroffene Bereiche und erhaltene Verträge

Bereich | Übernommenes Verhalten | Zu erhaltender 9.1-Vertrag
--- | --- | ---
Aufgabenmetadaten | iCalendar-X-Properties, private Google-Eigenschaften, drei Nachziehregeln und alte Marker | Terminstatus und Verfügbarkeit bleiben unabhängig vom Aufgabenstatus
Google-Schreiben | Keine leeren Aufgaben-Löschwerte beim Erstellen normaler Termine | Providerneutrale Identitätsabfrage und strukturierte Providerfehler bleiben erhalten
iCalendar | Aufgabenfelder lesen, schreiben und entfernen | Bestehende Serienauflösung einschließlich RFC-DURATION und Sommerzeitbehandlung bleibt erhalten
Microsoft To Do | Native Listen, getrennte Aufgabenabfrage, Schreiboperationen, lokale Fälligkeitsanzeige und Vollabgleich als Delta-Fallback | Getrennte Aufgaben- und Termincaches; vorhandene paginierte Konto-/Kalender-Kommunikation bleibt die Integrationsstelle
Microsoft-Terminserien | Unmittelbare Aktualisierung bearbeiteter Vorkommnisse und Erkennung geänderter Provider-IDs | Vorhandene providerneutrale Nachladefunktion statt Wiedereinführung älterer Microsoft-Sonderwege
Jahresereignisse | Jahreszahl im Anzeigetitel nach Synchronisation und Aufgaben-Anreicherung erhalten | Gespeicherte Jahrestagsmetadaten und kompatible Geburtstagsfelder bleiben erhalten
Kalender Ansicht | Native Aufgaben erkennen, passende Optionen anzeigen und Dialogdarstellung korrigieren | Symcon-9.1-Steuerelemente, HTML-SDK, Status/Verfügbarkeit und IPSView-Ausgabe bleiben bestehen
Helper | Aktualisierte zentrale Implementierungen und zugehörige Aufrufer | Die 9.1-Initialisierung, Modulaufteilung und Konfiguration bleiben maßgeblich

Die Einrichtung über Assistent und Konfigurator, der eigenständige lokale
Kalenderbetrieb, die lokale Originaldatenhaltung sowie die dokumentierten
Upgrade- und Wiederanlaufwege bleiben unverändert. Insbesondere wird der lokale
Originalbestand nicht durch einen Anzeige- oder Aufgaben-Cache ersetzt.

## Kompatibilität bestehender Aufgaben

Vorhandene Aufgabenmarker bleiben lesbar. CalDAV, lokale Kalender und Google
Calendar wechseln beim nächsten Aufgabenschreiben zur strukturierten Speicherung;
eine Neueingabe aller Termine ist dafür nicht nötig. Native Microsoft-Aufgaben
sind hingegen ein eigener Datenbestand. Die Auswahl einer To-Do-Liste migriert
keine bestehenden Outlook-Kalenderaufgaben.

Bei nativen Microsoft-Serienaufgaben wird nur die von Microsoft gelieferte
Aufgabe dargestellt. Weitere Vorkommnisse werden nicht vorausberechnet.
Microsoft stellt nach dem Erledigen die Folgeaufgabe bereit. Das lokale
Nachziehen kalenderbasierter Aufgaben ist darauf nicht anzuwenden.

Die zusätzliche delegierte Berechtigung `Tasks.ReadWrite` erfordert bei
bestehenden Microsoft-Verbindungen gegebenenfalls eine erneute OAuth-Anmeldung
über Symcon. Anwenderhinweise stehen in der
[Hauptdokumentation](../README.md#microsoft-to-do-einrichten).

## Prüfumfang vor Freigabe

Zusätzliche Integrationsregressionen sichern UTC-/DST-Fälligkeiten beim
Bearbeiten nativer Aufgaben, die getrennten Optionen bereits vorhandener
Kalenderaufgaben und die Rücknahme einer neu angelegten nativen Zielaufgabe,
falls das Entfernen des ursprünglichen Kalendertermins fehlschlägt.

Die Konfliktlösungen für die Helper-PRs #181 und #196 sind im lokalen
Arbeitsstand kombiniert. Der dritte offene Helper-PR #195 richtet sich an
`dev`, nicht an `dev_9.1`. Die PR-Branches auf GitHub wurden nicht verändert.

Die migrierten Aufgaben-, Metadaten- und Microsoft-Regressionstests sind gemeinsam
mit den vorhandenen 9.1-Prüfungen auszuführen. Besonders relevant sind
Provider-Parität, Schreib- und Nachladeverhalten, Kalender-Metadaten,
Serienidentität, Sommerzeit/DURATION, lokale Datenhaltung, Upgrade und Neustart
sowie Symcon-9.1-Laufzeit und Visualisierung.

Der vollständige Einstieg ist `php tests/run.php`; zusätzlich gelten die
Format- und Integritätsprüfungen der Repository-CI. Die bestehenden
9.1-Tests dürfen dabei nicht durch den älteren dev-Testkatalog ersetzt werden.
Diese Liste beschreibt den erforderlichen Prüfumfang, keinen pauschalen
Nachweis einer bereits erfolgten Live-Provider-Freigabe. Vor Veröffentlichung
bleiben Prüfungen mit den tatsächlich verbundenen Konten und Clients erforderlich.

## Lokaler Prüfnachweis vom 18.09.2026

- `php tests/run.php`: alle 74 Testschritte erfolgreich, einschließlich
  CalDAV-HTTP, Lookup-/Schreibparität, Aufgaben, Upgrade und Neustart.
- PHP-CS-Fixer mit Repository-Konfiguration: keine offenen Formatkorrekturen
  in den 28 geänderten/neuen PHP-Dateien außerhalb der vendorten Helper.
- Helper-Integritätsprüfung und `git diff --check`: erfolgreich.
- Lokale Ausführung unter PHP 8.2.31; die Symcon-9.1-Laufzeitgrenzen werden
  durch die vorhandenen Vertrags-/Stubtests geprüft, nicht durch eine hier
  aktualisierte Live-Symcon-Installation. Die CI mit PHP 8.5 wurde nicht ausgelöst.

Arbeitsstand: `E:\git\OpenCalendar-dev_9.1-port`, Branch `dev_9.1`.
Keine Commits, Pushes, PR-Merges oder Live-Deployments durchgeführt.
