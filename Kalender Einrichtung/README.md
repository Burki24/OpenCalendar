# Kalender Einrichtung

Die **Kalender Einrichtung** ist die zentrale Discovery- und Einrichtungsinstanz
für OpenCalendar ab Version 3.0 und benötigt Symcon 9.1 oder neuer.

Technisch heißt das Modul **OpenCalendar Discovery**. In einer deutschen
Symcon-Oberfläche wird dieser Name über die `locale.json` als
**Kalender Einrichtung** angezeigt.

Als Discovery-Modul (Typ 5) bildet es den zentralen Einstieg für die erstmalige
Einrichtung. Anwender sollen dadurch die Abhängigkeiten zwischen Kalender Konto,
Kalender Konfigurator, Kalender und Kalender Ansicht nicht mehr vorab kennen
müssen.

## Aktueller Entwicklungsstand

Der Assistent führt aktuell durch folgende Schritte:

1. Willkommen
2. Kalenderanbieter auswählen
3. Kalender Konto auswählen oder ein neues Konto vorbereiten
4. Kontoauswahl bestätigen

Bei einem **vorhandenen Kalender Konto** baut OpenCalendar die Auswahl beim
Öffnen der Discovery-Konfiguration aus allen vorhandenen **Calendar Account**-
Instanzen auf. Jeder Eintrag zeigt den Provider, den Instanznamen und die
Instanz-ID, beispielsweise `Google Kalender — Kalender Konto (#35780)`. Dadurch
ist der Anbieter bereits vor der Auswahl eindeutig erkennbar. OpenCalendar prüft
zusätzlich weiterhin, ob der Anbieter der ausgewählten Instanz zur Auswahl im
Wizard passt.

Bei einem **neuen Kalender Konto** wird die Instanz erst mit **OK** auf der
Bestätigungsseite erzeugt. Der gewählte Anbieter und der eingegebene Name werden
übernommen. Das Konto bleibt zunächst inaktiv, damit noch keine Verbindung zum
Kalenderanbieter hergestellt wird, bevor Zugangsdaten beziehungsweise OAuth im
nächsten Entwicklungsschritt eingerichtet werden.

Wird das Anlegen einer neuen Instanz während der Einrichtung mit einem Fehler
abgebrochen, versucht OpenCalendar die unvollständig angelegte Instanz wieder zu
entfernen.

## Geplanter Ausbau

Der Assistent soll anschließend schrittweise um folgende Funktionen erweitert
werden:

- Zugangsdaten beziehungsweise OAuth für das ausgewählte Kalender Konto einrichten
- Verbindung prüfen
- verfügbare Kalender ermitteln und auswählen
- Kalenderinstanzen erzeugen
- Kalender Ansicht anlegen oder eine vorhandene auswählen
- gewählte Kalender der Ansicht zuordnen
- abschließende Synchronisation und Ergebnisübersicht
