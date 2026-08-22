# Kalender Einrichtung

Die **Kalender Einrichtung** ist die zentrale Discovery- und
Einrichtungsinstanz für OpenCalendar ab Version 3.0 und benötigt Symcon 9.1
oder neuer.

Technisch heißt das Modul **OpenCalendar Discovery**. In einer deutschen
Symcon-Oberfläche wird dieser Name über die `locale.json` als
**Kalender Einrichtung** angezeigt.

Als Discovery-Modul (Typ 5) bildet es den zentralen Einstieg für die
erstmalige Einrichtung. Anwender sollen dadurch die Abhängigkeiten zwischen
Kalender Konto, Kalender Konfigurator, Kalender und Kalender Ansicht nicht
mehr vorab kennen müssen.

## Aktueller Entwicklungsstand

Der erste Entwicklungsschritt enthält bewusst nur das technische
Wizard-Grundgerüst:

1. Willkommen
2. Kalenderanbieter auswählen
3. Einrichtungsübersicht

Dabei werden die mit Symcon 9.1 eingeführten Wizard-Seiten eines
`PopupButton` sowie die neue `RadioButtonGroup` verwendet.

**Dieser Stand erzeugt noch keine Konten, Konfiguratoren, Kalenderinstanzen
oder Kalender Ansichten.** Er dient zunächst dazu, das neue
9.1-Wizard-Verhalten direkt in Symcon zu prüfen, bevor die eigentliche
Einrichtung Schritt für Schritt angeschlossen wird.

## Geplanter Ausbau

Der Assistent soll anschließend schrittweise um folgende Funktionen erweitert
werden:

- vorhandene OpenCalendar-Installation erkennen
- Kalenderanbieter und Zugangsdaten beziehungsweise OAuth einrichten
- Verbindung prüfen
- verfügbare Kalender ermitteln und auswählen
- Kalenderinstanzen erzeugen
- Kalender Ansicht anlegen oder eine vorhandene auswählen
- gewählte Kalender der Ansicht zuordnen
- abschließende Synchronisation und Ergebnisübersicht
