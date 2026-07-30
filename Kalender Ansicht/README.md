# Kalender Ansicht

Die Kalender Ansicht fasst Termine mehrerer Kalenderinstanzen in einer responsiven Kachel der Symcon-Kachelvisualisierung oder in einer HTMLBox für IPSView zusammen.

Die Kachel verwendet den vendorten `VisualizationThemeHelper` und orientiert sich dadurch an den nativen Symcon-Designvariablen. Die eigenständige IPSView-Seite verwendet den universellen `IPSViewStyleHelper`. Dadurch stehen in allen angebundenen Modulen dieselben Stilquellen, Bezeichnungen, Flächen, Schriften, Rahmen, Schatten, Statusrollen und Verläufe zur Verfügung. Native Kachel und IPSView-Seite werden über den gemeinsamen `IPSViewHTMLPageHelper` aus derselben Asset-Struktur `visualization/index.html`, `style.css` und `app.js` erzeugt. Der Helper verwaltet außerdem die optionale IPSView-WebContent-Variable einschließlich der bestätigungspflichtigen Löschung.

## Funktionsumfang

- Agenda-, 3-Tage-, Wochen- und Monatsansicht
- unabhängig wählbare horizontale oder vertikale Wochenansicht für Tile und IPSView
- optionale Anzeige des Tages im Jahr in den Tagesüberschriften
- Zusammenführen beliebig vieler ausgewählter Kalender
- Farbliche Zuordnung der Termine zu ihren Kalendern
- Optionale Anzeige von Kalendername, Ort und Beschreibung
- Navigation innerhalb des angezeigten Zeitraums
- Manuelle Synchronisation aller ausgewählten Kalender
- Erstellen, Bearbeiten und Löschen von Terminen in beschreibbaren Kalendern
- Automatische Aktualisierung der Kachel nach einer Kalendersynchronisation
- Responsive Darstellung für große Kacheln und schmale Mobilansichten
- Optionale IPSView-Ausgabe über eine automatisch aktualisierte String-Variable mit der modernen Darstellung **Webinhalt**

Wiederkehrende, vom CalDAV-Server expandierte Einzeltermine werden derzeit nur lesend dargestellt. Dadurch wird verhindert, dass versehentlich die komplette Terminserie verändert wird.

## Voraussetzungen

- Symcon ab Version 9.0 mit Kachelvisualisierung und HTML-SDK
- Mindestens eine eingerichtete Instanz des Moduls `Kalender`
- für die alternative Darstellung IPSView mit einem HTML-Box-Steuerelement und Browser-Renderer

## Einrichtung

1. Eine Instanz `Kalender Ansicht` erstellen.
2. In der Liste `Kalender` die gewünschten Kalenderinstanzen auswählen und aktivieren.
3. Standardansicht, Tile-Wochenausrichtung, Zeitraum, maximale Terminanzahl und die gewünschten Detailfelder einschließlich der optionalen Anzeige des Tages im Jahr festlegen.
4. Die Instanz in der Kachelvisualisierung platzieren.

### IPSView

1. In der Instanz **Kalender Ansicht** die Option **IPSView-HTML-Ausgabe bereitstellen** aktivieren und die Konfiguration speichern.
2. Unter **Stilquelle** zwischen **Benutzerdefinierter Stil**, **IPSView-Standardstil**, **Helle Vorgabe** und **Dunkle Vorgabe** wählen.
3. Für **IPSView-Standardstil** das Medienobjekt auswählen, das die gewünschte `.ipsView`-Datei enthält. Der Helper übernimmt daraus ausschließlich freigegebene Standardstil-Werte wie Farben, Schrift, Rahmen, Schatten und Rundungen. Wird das Medienobjekt aktualisiert, wird auch die WebContent-Ausgabe neu erzeugt.
4. Beim **Benutzerdefinierten Stil** die universellen Flächen-, Schrift-, Icon-, Rahmen-, Popup- und Statusfarben sowie Typografie und Effekte direkt festlegen. Diese Rollen besitzen in allen Modulen dieselbe Bedeutung und Wirkung.
5. Mit **Transparenter Hintergrund** festlegen, ob der vollständige Kalenderhintergrund transparent wird und die umgebende IPSView sichtbar bleibt. Ohne Transparenz verwendet die Kalenderfläche den **View-Hintergrund**; der **Seitenhintergrund** bleibt inneren Kalenderflächen vorbehalten.
6. Mit **IPSView-Wochenausrichtung** festlegen, ob die Wochentage nebeneinander oder als vertikale Tageszeilen dargestellt werden.
7. Über **IPSView-Farbbalkenbreite** die Kalenderkennzeichnung zwischen 2 und 16 Pixeln einstellen. Kalender- und Terminfarben bleiben fachliche Inhaltsfarben und werden nicht durch den gemeinsamen Stil ersetzt.
8. Unterhalb der Instanz wird die String-Variable **IPSView-Kalender** mit der Darstellung **Webinhalt** angelegt.
9. Im IPSView Designer ein Steuerelement vom Typ **HTML-Box** einfügen und diese Variable als ID auswählen.
10. Als HTML Renderer **Browser des Clients** oder **Automatisch** verwenden. Der native einfache HTML Renderer reicht nicht aus, weil Ansichtswechsel und Navigation JavaScript verwenden.

Agenda, 3-Tage-, Wochen- und Monatsansicht sowie die Navigation funktionieren direkt innerhalb der IPSView-HTMLBox. Die Variable wird bei Änderungen oder Synchronisationen der ausgewählten Kalender automatisch neu erzeugt. Wird die IPSView-Ausgabe deaktiviert, bleibt eine bereits vorhandene Variable mit ihrer Objekt-ID, Positionierung und bestehenden Verknüpfungen erhalten und wird nicht mehr aktualisiert. In der Instanzkonfiguration erscheint dann eine gesonderte Löschaktion; erst nach ausdrücklicher Bestätigung wird die Variable entfernt. Das Öffnen von Termindetails ist lesend möglich. Erstellen, Bearbeiten, Löschen und die manuelle Synchronisationsschaltfläche bleiben der Symcon-Kachel vorbehalten, da die IPSView-HTMLBox keine Symcon-HTML-SDK-Aktionsbrücke bereitstellt.

Der `IPSViewStyleHelper` erzeugt sämtliche allgemeinen CSS-Rollen zentral. OpenCalendar ordnet diesen Rollen nur seine Komponenten zu; eigene Grund-, Status- oder Popupfarben werden im Modul nicht mehr festgelegt. Die frei gewählten Farben einzelner Kalender und Termine bleiben davon unabhängig. Der View-Hintergrund, die innere Seitenfläche, Primär-/Aktiv-/Inaktiv-/Label-/Sekundär-/Faint-Texte und neutrale Icons werden dabei getrennt zugeordnet. Eine Änderung der „Primären Schrift“ betrifft daher ausschließlich normalen Inhalt und Werte, nicht Beschriftungen oder deaktivierte Bedienelemente.

Die IPSView-Option **Seite skalieren** wird laut Hersteller nur von den mobilen Clients unterstützt und hat unter Windows keine Wirkung. Schriftgröße und Stilskalierung werden deshalb vom Helper direkt am Kalenderinhalt gesetzt. Für die zuverlässige Verarbeitung von CSS und JavaScript muss in IPSView **Browser des Clients** statt **HTML Renderer** ausgewählt sein.

Über `Kalender synchronisieren` kann die Verbindung bereits in der Konfiguration geprüft werden. Neu vom Konfigurator angelegte Kalender übernehmen Farbe und Schreibberechtigung automatisch. Bei Kalenderinstanzen, die vor Einführung dieser Eigenschaften angelegt wurden, die Konfiguration über den Kalender-Konfigurator einmal neu anwenden.

## PHP-Befehlsreferenz

```php
// Alle ausgewählten Kalender synchronisieren.
$success = IPSKALVIEW_SynchronizeCalendars(12345);

// Die zusammengeführten Termine als JSON abrufen.
$events = IPSKALVIEW_GetAggregatedEvents(12345);

// Den aktuellen eigenständigen HTML-Inhalt für IPSView abrufen.
$html = IPSKALVIEW_GetIPSViewHTML(12345);
```
