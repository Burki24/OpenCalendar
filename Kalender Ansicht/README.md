# Kalender Ansicht

Die **Kalender Ansicht** führt Termine mehrerer Kalender-Instanzen in einer
gemeinsamen responsiven Darstellung zusammen. Sie kann direkt in der
Symcon-Kachelvisualisierung oder über eine WebContent-Variable in IPSView
verwendet werden.

## Voraussetzungen

- Symcon ab Version 9.0 mit Kachelvisualisierung und HTML-SDK
- mindestens eine über den Kalender Konfigurator angelegte und synchronisierte
  **Kalender**-Instanz
- für IPSView ein HTML-Box-Steuerelement mit Browser-Renderer

## Schnellstart für die Kachelvisualisierung

1. Über **Instanz hinzufügen** eine Instanz **Kalender Ansicht** erstellen.
2. In der Liste **Kalender** für jede gewünschte Zeile eine Kalender-Instanz
   auswählen und **Aktiviert** einschalten.
3. Standardansicht, Zeitraum und maximale Terminanzahl festlegen.
4. Gewünschte Zusatzinformationen wie Kalendername, Ort oder Beschreibung
   aktivieren.
5. Die Konfiguration übernehmen und **Kalender synchronisieren** ausführen.
6. Die Instanz in der Symcon-Kachelvisualisierung platzieren.

Mit **Alle Kalenderinstanzen wiederherstellen** werden alle im System
vorhandenen Kalender-Instanzen erneut ausgewählt und aktiviert. Die Aktion ist
hilfreich, wenn die Auswahlliste nach einer Änderung oder Wiederherstellung
unvollständig ist; eine vorhandene individuelle Auswahl wird dabei ersetzt.

## Funktionsumfang

- Agenda-, 3-Tage-, Wochen- und Monatsansicht
- unabhängig wählbare horizontale oder vertikale Wochenansicht für Kachel und
  IPSView
- optionale Anzeige des Tages im Jahr
- Anzeige der gesamten Terminanzahl je Tag in Agenda-, 3-Tage- und
  Wochenansicht
- Zusammenführen beliebig vieler ausgewählter Kalender
- Farben der einzelnen Kalender und Termine
- optionale Anzeige von Kalendername, Ort und Beschreibung
- Navigation innerhalb des dargestellten Zeitraums
- manuelle Synchronisation aller ausgewählten Kalender
- Erstellen, Bearbeiten und Löschen von Terminen in beschreibbaren Kalendern
- automatische Aktualisierung nach einer Kalendersynchronisation
- responsive Bedienung auf großen Kacheln und schmalen Mobilansichten
- optionale IPSView-Ausgabe über eine WebContent-Variable

Wiederkehrende, vom Anbieter expandierte Einzelvorkommen werden derzeit nur
lesend dargestellt. Dadurch kann nicht versehentlich die vollständige
Terminserie verändert werden.

## Einstellungen

Eigenschaft | Beschreibung
--- | ---
Kalender | Ausgewählte Kalender-Instanzen und ihre Aktivierung
Standardansicht | Agenda, 3 Tage, Woche oder Monat
Kachel-Wochenausrichtung | Horizontale Wochenspalten oder vertikale Tageszeilen
Vergangene/Zukünftige Tage | Zeitraum, aus dem Termine zusammengeführt werden
Maximale Termine | Obergrenze der in einer Antwort verarbeiteten Termine
Wochenenden anzeigen | Blendet Samstag und Sonntag ein oder aus
Tag im Jahr anzeigen | Ergänzt Tagesüberschriften um die fortlaufende Tagesnummer
Terminanzahl je Tag | Wird in Agenda-, 3-Tage- und Wochenansicht automatisch über alle ausgewählten Kalender angezeigt
Kalendername/Ort/Beschreibung | Legt fest, welche Termindetails sichtbar sind

Die ausgewählten Kalender werden intern seitenweise gelesen. Dies geschieht
automatisch und ermöglicht auch bei umfangreichen Kalendern den Aufbau der
Ansicht, ohne Symcons Größenlimit für einzelne PHP-Rückgaben zu überschreiten.

## Einrichtung in IPSView

1. Den Bereich **IPSView** in der Instanzkonfiguration öffnen.
2. **IPSView-HTML-Ausgabe bereitstellen** aktivieren.
3. Unter **Stilquelle** zwischen **Benutzerdefinierter Stil**,
   **IPSView-Standardstil**, **Helle Vorgabe** und **Dunkle Vorgabe** wählen.
4. Für **IPSView-Standardstil** das Medienobjekt auswählen, das die gewünschte
   `.ipsView`-Datei enthält. Aus der Datei werden ausschließlich freigegebene
   Standardstilwerte wie Farben, Schrift, Rahmen, Schatten und Rundungen
   übernommen.
5. Beim benutzerdefinierten Stil die benötigten Flächen-, Text-, Icon-, Rahmen-,
   Popup- und Statusfarben sowie Typografie und Effekte einstellen.
6. Mit **Transparenter Hintergrund** festlegen, ob die umgebende IPSView sichtbar
   bleiben soll.
7. IPSView-Wochenausrichtung und Farbbalkenbreite einstellen und die
   Konfiguration übernehmen.
8. Unterhalb der Instanz wird die String-Variable **IPSView-Kalender** mit der
   Darstellung **Webinhalt** angelegt.
9. Im IPSView Designer ein Steuerelement vom Typ **HTML-Box** einfügen und die
   Variable **IPSView-Kalender** als ID auswählen.
10. Als HTML-Renderer **Browser des Clients** oder **Automatisch** verwenden. Der
    einfache native HTML-Renderer genügt nicht, weil Navigation, Ansichtswechsel
    und Terminbearbeitung JavaScript verwenden.

Agenda, 3-Tage-, Wochen- und Monatsansicht funktionieren direkt in der
IPSView-HTML-Box. In beschreibbaren Kalendern lassen sich dort außerdem Termine
erstellen, bearbeiten und löschen. Die kompakte Schaltfläche **＋ Termin** bleibt
sichtbar, ist ohne beschreibbaren Kalender jedoch deaktiviert.

Wird die IPSView-Ausgabe später deaktiviert, bleibt die vorhandene Variable mit
ihrer Objekt-ID, Position und bestehenden Verknüpfungen erhalten, wird aber nicht
mehr aktualisiert. In der Instanz erscheint dann eine eigene Löschaktion. Erst
nach ausdrücklicher Bestätigung wird die Variable entfernt.

### IPSView-Verbindung absichern

IPSView stellt die Symcon-HTML-SDK-Funktion `requestAction()` nicht bereit.
OpenCalendar verwendet deshalb eine instanzbezogene WebHook-Brücke mit einem
zufälligen, persistent gespeicherten Zugriffstoken. Akzeptiert werden nur die
benötigten Aktionen zum Laden, Synchronisieren und Bearbeiten von Terminen per
POST.

Das Token ist Bestandteil der erzeugten WebContent-Seite und sollte wie die
IPSView-/Symcon-Verbindung geschützt werden. Für Zugriffe außerhalb des eigenen
Netzes sollte ausschließlich HTTPS oder Symcon Connect verwendet werden.

Die IPSView-Option **Seite skalieren** wird laut IPSView-Hersteller nur von
mobilen Clients unterstützt und hat unter Windows keine Wirkung. Schriftgröße
und Stilskalierung werden deshalb direkt auf den Kalenderinhalt angewendet.

## Fehlerbehebung

Problem | Prüfung
--- | ---
Keine Termine sichtbar | Kalenderauswahl, Aktivierung, Zeitraum und letzte Synchronisation der Kalender-Instanzen prüfen
Synchronisation schlägt fehl | Jede ausgewählte Kalender-Instanz einzeln synchronisieren und anschließend das zugehörige Kalender Konto testen
Schaltfläche „＋ Termin“ ist deaktiviert | Mindestens einen ausgewählten Kalender mit Schreibrechten verwenden; ICS/Webcal ist immer schreibgeschützt
IPSView zeigt nur statisches oder unvollständiges HTML | Im IPSView-Steuerelement **Browser des Clients** oder **Automatisch** als Renderer wählen
IPSView-Inhalt ist veraltet | **IPSView-HTML neu generieren** ausführen und prüfen, ob die Ausgabe aktiviert ist
Kalenderauswahl ist leer oder beschädigt | **Alle Kalenderinstanzen wiederherstellen** verwenden und die gewünschte Auswahl anschließend anpassen

## PHP-Befehlsreferenz

```php
// Alle ausgewählten Kalender synchronisieren.
$success = IPSKALVIEW_SynchronizeCalendars(12345);

// Die zusammengeführten Termine als JSON abrufen.
$events = IPSKALVIEW_GetAggregatedEvents(12345);

// Den aktuellen eigenständigen HTML-Inhalt für IPSView abrufen.
$html = IPSKALVIEW_GetIPSViewHTML(12345);
```

## Technische Hinweise

Kachel und IPSView-Seite werden aus derselben Asset-Struktur unter
`visualization/` erzeugt. Die vendorten Helper `VisualizationThemeHelper`,
`IPSViewStyleHelper` und `IPSViewHTMLPageHelper` sorgen für gemeinsame
Symcon-Designvariablen, IPSView-Stilrollen und die kontrollierte Verwaltung der
WebContent-Variable. Kalender- und Terminfarben bleiben davon unabhängige
fachliche Inhaltsfarben.
