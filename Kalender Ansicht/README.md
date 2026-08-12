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
3. Standardansicht, Kachel-Wochenausrichtung, Kachel-Schriftgröße, geladenen
   Terminzeitraum und maximale Terminanzahl festlegen. Die Kachel-Schriftgröße
   kann zwischen 50 und 200 Prozent eingestellt werden; 100 Prozent entspricht
   der bisherigen Darstellung.
4. Den aufklappbaren Bereich **Anzeigeoptionen** öffnen. Dort sind die
   allgemeinen Zusatzinformationen wie Wochenenden, Kalendername, Ort und
   Beschreibung sowie die ansichtsspezifische Matrix für Terminanzahl,
   Kalenderwoche und Tageszahl gebündelt. Für die Listenansicht werden dort
   zusätzlich die gewünschten Listenspalten und die Bedienelemente der Listenansicht gewählt.
5. Über **Ansichtszeiträume** die sichtbare Länge jeder Darstellung festlegen.
6. Die Konfiguration übernehmen und **Kalender synchronisieren** ausführen.
7. Die Instanz in der Symcon-Kachelvisualisierung platzieren.

Mit **Alle Kalenderinstanzen wiederherstellen** werden alle im System
vorhandenen Kalender-Instanzen erneut ausgewählt und aktiviert. Die Aktion ist
hilfreich, wenn die Auswahlliste nach einer Änderung oder Wiederherstellung
unvollständig ist; eine vorhandene individuelle Auswahl wird dabei ersetzt.

## Funktionsumfang

- Agenda-, Listen-, 3-Tage-, Wochen- und Monatsansicht
- unabhängig wählbare horizontale oder vertikale Wochenansicht für Kachel und
  IPSView
- separat einstellbare Schriftgröße der Kachelvisualisierung von 50 bis 200 Prozent;
  die IPSView-Schrift-/Stilskalierung bleibt davon unabhängig
- je Darstellung separat konfigurierbare Anzeige von Kalenderwoche und
  Tageszahl; in der Listenansicht werden beide Angaben als eigene Spalten geführt
- frei wählbare sichtbare Zeiträume je Ansicht: Tage für Agenda, Liste und
  3-Tage-Ansicht, Wochen für die Wochenansicht und Monate für die Monatsansicht
- reduzierte Listenansicht mit Kalenderfarben und frei auswählbaren Spalten für
  Datum, Beginn, Ende, Titel, Kalender, Ort und Beschreibung; Navigation,
  Termin-Erstellung und Aktualisieren können dort optional ausgeblendet werden
- optional und je Darstellung separat einblendbare Terminanzahl pro Tag in
  Agenda-, 3-Tage- und Wochenansicht
- Zusammenführen beliebig vieler ausgewählter Kalender
- Farben der einzelnen Kalender und Termine
- optionale Anzeige von Kalendername, Ort und Beschreibung
- Navigation innerhalb des dargestellten Zeitraums
- manuelle Synchronisation aller ausgewählten Kalender
- Erstellen, Bearbeiten, Verschieben und Löschen von Terminen in beschreibbaren Kalendern
- automatische Aktualisierung nach einer Kalendersynchronisation, ohne die am jeweiligen Client gewählte Ansicht oder das Bezugsdatum zurückzusetzen
- responsive Bedienung auf großen Kacheln und schmalen Mobilansichten
- optionale IPSView-Ausgabe über eine WebContent-Variable

Wiederkehrende, vom Anbieter expandierte Einzelvorkommen werden derzeit nur
lesend dargestellt. Dadurch kann nicht versehentlich die vollständige
Terminserie verändert werden.

Normale Einzeltermine können im Bearbeitungsdialog in einen anderen, in dieser
Kalender Ansicht ausgewählten und beschreibbaren Kalender verschoben werden.
Dazu wird im Feld **Kalender** einfach ein anderes Ziel gewählt; die
Schaltfläche **Speichern** wechselt dann auf **Verschieben**. OpenCalendar legt
den Termin zuerst im Zielkalender an und löscht ihn erst anschließend im
Quellkalender. Scheitert das Löschen, bleibt die Zielkopie bewusst erhalten und
der Anwender wird aufgefordert, beide Kalender zu prüfen, damit kein Termin
durch einen unsicheren Rollback verloren geht. Providerübergreifendes
Verschieben, beispielsweise Google → Microsoft oder CalDAV → Google, ist damit
möglich. Provider-spezifische Zusatzdaten, die OpenCalendar nicht im gemeinsamen
Terminmodell führt, werden dabei nicht übertragen.

## Einstellungen

Eigenschaft | Beschreibung
--- | ---
Kalender | Ausgewählte Kalender-Instanzen und ihre Aktivierung
Standardansicht | Agenda, Liste, 3 Tage, Woche oder Monat; wird nur verwendet, solange der jeweilige Client noch keinen eigenen Ansichtsstand gespeichert hat
Kachel-Wochenausrichtung | Horizontale Wochenspalten oder vertikale Tageszeilen
Vergangene/Zukünftige Tage | Datenzeitraum, aus dem Termine für alle Darstellungen geladen und zusammengeführt werden
Maximale Termine | Obergrenze der in einer Antwort verarbeiteten Termine
Anzeigeoptionen → Allgemein | Wochenenden, Kalendername, Ort und Beschreibung zentral im aufklappbaren Bereich konfigurieren
Anzeigeoptionen → Terminanzahl | Je Ansicht separat für Agenda, 3 Tage und Woche; im Monat wird keine Tages-Terminanzahl eingeblendet
Anzeigeoptionen → Kalenderwoche | Je Ansicht separat für Agenda, Liste, 3 Tage, Woche und Monat
Anzeigeoptionen → Tageszahl | Je Ansicht separat für Agenda, Liste, 3 Tage, Woche und Monat
Anzeigeoptionen → Listenansicht | Bedienelemente der Listenansicht ein-/ausblenden; Zeitraum und Ansichtswechsel bleiben sichtbar
Anzeigeoptionen → Listenspalten | Legt fest, welche Datenfelder in der Listenansicht als Spalten erscheinen
Ansichtszeiträume | Sichtbare Länge jeder Ansicht im aufklappbaren Bereich; Agenda/Liste/3 Tage in Tagen, Woche in Wochen und Monat in Monaten

Die Kalenderwoche erscheint in der Wochenansicht in der Zeitraumüberschrift.
In der 3-Tage-Ansicht werden bei einem Wochenwechsel beide Kalenderwochen
angegeben, beispielsweise **KW 33/34**. Die Agenda erhält beim Beginn einer
neuen sichtbaren Kalenderwoche einen dezenten KW-Trenner. In der Monatsansicht
steht die KW am Montag als erstem Tag der jeweiligen ISO-Kalenderwoche, auch
wenn dieser Montag noch zum Vor- oder bereits zum Folgemonat gehört. Die
Tageszahl wird in Agenda, 3-Tage- und Wochenansicht in der vorhandenen
Tagesüberschrift und im Monat dezent beim Tagesdatum dargestellt. In der
Listenansicht werden Kalenderwoche und Tageszahl bei Aktivierung als eigene,
schmale Spalten ausgegeben.

Die zuletzt am jeweiligen Browser/Monitor gewählte Ansicht und das zugehörige Bezugsdatum werden clientseitig und instanzbezogen gespeichert. Dadurch bleiben beispielsweise Liste und gewählter Zeitraum auch erhalten, wenn eine Kalendersynchronisation die IPSView-Seite neu lädt. Unterschiedliche Monitore können unabhängig voneinander verschiedene Ansichten verwenden. Die konfigurierte Standardansicht dient weiterhin als Ausgangswert für neue Clients.

Über das **Kalenderfilter-Symbol** in der Toolbar lassen sich die in der Kalender-Ansicht konfigurierten Kalender clientseitig ein- oder ausblenden. Es können einzelne, mehrere, alle oder keine Kalender gewählt werden. Der Filter verändert weder die Instanzkonfiguration noch die Synchronisation und wird pro Browser/Monitor zusammen mit dem Ansichtsstand gespeichert. Dadurch können Kachel und unterschiedliche IPSView-Clients unabhängig voneinander verschiedene Kalenderkombinationen anzeigen.

Die **Listenansicht** verzichtet bewusst auf Karten und zusätzliche
Gruppierungen. Jeder Termin wird in einer einfachen Tabellenzeile dargestellt;
der schmale Farbbalken übernimmt die Farbe des jeweiligen Kalenders. Datum,
Beginn, Ende, Titel, Kalendername, Ort und Beschreibung können unabhängig
voneinander als Spalten ein- oder ausgeblendet werden. Optional lassen sich in
dieser Ansicht die Schaltflächen für Zurück, Heute, Weiter, Termin erstellen und
Aktualisieren ausblenden. Die Zeitraumüberschrift und die Auswahl der Ansichten
bleiben dabei sichtbar, damit die Listenansicht jederzeit verlassen werden kann.

Der aufklappbare Bereich **Ansichtszeiträume** steuert die sichtbare Länge der einzelnen
Darstellungen und gleichzeitig die Schrittweite der Vor-/Zurück-Navigation.
Agenda, Liste und 3-Tage-Ansicht verwenden Tage, die Wochenansicht verwendet
Wochen und die Monatsansicht Monate. Der sichtbare Zeitraum und der oben
konfigurierte Datenzeitraum sind unabhängig voneinander. Sollen Termine im
gesamten sichtbaren Zeitraum vorhanden sein, muss der Datenzeitraum
**Vergangene/Zukünftige Tage** diesen Bereich ebenfalls abdecken.

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

Agenda, Listen-, 3-Tage-, Wochen- und Monatsansicht funktionieren direkt in der
IPSView-HTML-Box. In beschreibbaren Kalendern lassen sich dort außerdem Termine
erstellen, bearbeiten, zwischen beschreibbaren Kalendern verschieben und löschen. Die kompakte Schaltfläche **＋ Termin** bleibt
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

// Alle Termine eines lokalen Kalendertags providerübergreifend abrufen.
$appointments = IPSKALVIEW_GetDayAppointments(12345, '2026-08-11');

// Alle Termine eines inklusiven lokalen Datumsbereichs providerübergreifend abrufen.
$appointments = IPSKALVIEW_GetAppointments(12345, '2026-08-11', '2026-08-17');

// Kompakte Tagesliste: summary, start, end, startTime und endTime.
$appointments = IPSKALVIEW_GetDayAppointmentsCompact(12345, '2026-08-11');

// Optional nur Termine einer ausgewählten Kalenderinstanz (z. B. ID 23456).
$appointments = IPSKALVIEW_GetDayAppointmentsCompact(12345, '2026-08-11', 23456);

// Kompakte Terminliste für einen inklusiven Datumsbereich.
$appointments = IPSKALVIEW_GetAppointmentsCompact(12345, '2026-08-11', '2026-08-17');

// Auch beim Datumsbereich kann optional nach Kalenderinstanz gefiltert werden.
$appointments = IPSKALVIEW_GetAppointmentsCompact(12345, '2026-08-11', '2026-08-17', 23456);

// Den aktuellen eigenständigen HTML-Inhalt für IPSView abrufen.
$html = IPSKALVIEW_GetIPSViewHTML(12345);
```


`GetDayAppointments()` und `GetAppointments()` verwenden ausschließlich die in
dieser **Kalender Ansicht** ausgewählten Kalender und führen deren lokal
zwischengespeicherte Termine providerübergreifend zusammen. Der Bereich wird
unabhängig von den Visualisierungseinstellungen **Vergangene Tage**, **Zukünftige
Tage** und **Maximale Termine** abgefragt. Verfügbar sind dabei die Termine, die
die jeweiligen Kalender-Instanzen bereits in ihrem eigenen Synchronisationszeitraum
gecached haben. `GetAppointments()` behandelt das angegebene Enddatum inklusiv.
Ganztagstermine berücksichtigen weiterhin das providerseitig exklusive Enddatum
korrekt. Jeder Eintrag enthält zusätzlich `calendarInstanceId`, `calendarName`,
`calendarColor` und `canWrite`.

Die Funktionen liefern JSON. Beispiel:

```php
$appointments = json_decode(
    IPSKALVIEW_GetDayAppointments(12345, date('Y-m-d')),
    true,
    512,
    JSON_THROW_ON_ERROR
);
```

Für einfache Skripte stehen zusätzlich `GetDayAppointmentsCompact()` und
`GetAppointmentsCompact()` bereit. Sie verwenden dieselbe Kalenderauswahl und
dieselben Bereichsregeln, liefern pro Termin aber ausschließlich `summary`,
`start`, `end`, `startTime` und `endTime`. `start` und `end` sind dabei immer
lokale Datumswerte im Format `YYYY-MM-DD`. Bei zeitgebundenen Terminen enthalten
`startTime` und `endTime` die lokale Uhrzeit im Format `HH:MM`. Ganztagstermine
liefern die lokalisierte Bezeichnung `Ganztägig`/`All day` als `startTime`, einen
leeren `endTime`-Wert und in `end` das sichtbare inklusive Enddatum statt der
providerseitig technischen exklusiven Endgrenze. Als letztes optionales Argument
kann bei beiden Compact-Funktionen die Instanz-ID eines in dieser Kalender Ansicht
ausgewählten Kalenders angegeben werden. Der Standardwert `0` liefert wie bisher
alle ausgewählten Kalender. Eine konkrete ID filtert ausschließlich auf diesen
Kalender; eine nicht ausgewählte oder unbekannte ID liefert ein leeres JSON-Array.

## Technische Hinweise

Kachel und IPSView-Seite werden aus derselben Asset-Struktur unter
`visualization/` erzeugt. Die vendorten Helper `VisualizationThemeHelper`,
`IPSViewStyleHelper` und `IPSViewHTMLPageHelper` sorgen für gemeinsame
Symcon-Designvariablen, IPSView-Stilrollen und die kontrollierte Verwaltung der
WebContent-Variable. Kalender- und Terminfarben bleiben davon unabhängige
fachliche Inhaltsfarben.

### Tagesübersicht der Monatsansicht

In der Monatsansicht ist der Hinweis `+ weitere` anklickbar. Er öffnet eine Tagesübersicht mit allen Terminen dieses Tages; ein Klick auf einen Termin öffnet den bestehenden Bearbeitungsdialog.
