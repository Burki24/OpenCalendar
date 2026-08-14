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

- Agenda-, Listen-, Tage-, Wochen- und Monatsansicht
- unabhängig wählbare horizontale oder vertikale Wochenansicht für Kachel und
  IPSView
- separat einstellbare Schriftgröße der Kachelvisualisierung von 50 bis 200 Prozent;
  die IPSView-Schrift-/Stilskalierung bleibt davon unabhängig
- je Darstellung separat konfigurierbare Anzeige von Kalenderwoche und
  Tageszahl; in der Listenansicht werden beide Angaben als eigene Spalten geführt
- frei wählbare sichtbare Zeiträume je Ansicht: Tage für Agenda, Liste und
  Tage-Ansicht, Wochen für die Wochenansicht und Monate für die Monatsansicht
- reduzierte Listenansicht mit Kalenderfarben und frei auswählbaren Spalten für
  Datum, Beginn, Ende, Titel, Kalender, Ort und Beschreibung; Navigation,
  Termin-Erstellung und Aktualisieren können dort optional ausgeblendet werden
- optional und je Darstellung separat einblendbare Terminanzahl pro Tag in
  Agenda-, Tage- und Wochenansicht
- Zusammenführen beliebig vieler ausgewählter Kalender
- Farben der einzelnen Kalender und Termine
- optionale Anzeige von Kalendername, Ort und Beschreibung
- Navigation innerhalb des dargestellten Zeitraums
- manuelle Synchronisation aller ausgewählten Kalender
- Erstellen, Bearbeiten, Verschieben und Löschen von Terminen in beschreibbaren Kalendern
- Komfortable Zeiteingabe: Wird beim Beginn nur das Datum geändert, folgt das Enddatum automatisch auf denselben Tag und behält seine Uhrzeit bei. Bei einer geänderten Beginn-Uhrzeit wird das Ende automatisch auf eine Stunde später gesetzt; Ganztagstermine bleiben auf demselben sichtbaren Tag.
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
Standardansicht | Agenda, Liste, Tage, Woche oder Monat; wird nur verwendet, solange der jeweilige Client noch keinen eigenen Ansichtsstand gespeichert hat
Kachel-Wochenausrichtung | Horizontale Wochenspalten oder vertikale Tageszeilen
Vergangene/Zukünftige Tage | Datenzeitraum, aus dem Termine für alle Darstellungen geladen und zusammengeführt werden
Maximale Termine | Obergrenze der in einer Antwort verarbeiteten Termine
Anzeigeoptionen → Allgemein | Wochenenden, Kalendername, Ort und Beschreibung zentral im aufklappbaren Bereich konfigurieren
Anzeigeoptionen → Terminanzahl | Je Ansicht separat für Agenda, Tage und Woche; im Monat wird keine Tages-Terminanzahl eingeblendet
Anzeigeoptionen → Kalenderwoche | Je Ansicht separat für Agenda, Liste, Tage, Woche und Monat
Anzeigeoptionen → Tageszahl | Je Ansicht separat für Agenda, Liste, Tage, Woche und Monat
Anzeigeoptionen → Listenansicht | Bedienelemente der Listenansicht ein-/ausblenden; Zeitraum und Ansichtswechsel bleiben sichtbar
Anzeigeoptionen → Listenspalten | Legt fest, welche Datenfelder in der Listenansicht als Spalten erscheinen
Ansichtszeiträume | Sichtbare Länge jeder Ansicht im aufklappbaren Bereich; Agenda/Liste/Tage in Tagen, Woche in Wochen und Monat in Monaten

Die Kalenderwoche erscheint in der Wochenansicht in der Zeitraumüberschrift.
In der Tage-Ansicht werden bei einem Wochenwechsel beide Kalenderwochen
angegeben, beispielsweise **KW 33/34**. Die Agenda erhält beim Beginn einer
neuen sichtbaren Kalenderwoche einen dezenten KW-Trenner. In der Monatsansicht
steht die KW am Montag als erstem Tag der jeweiligen ISO-Kalenderwoche, auch
wenn dieser Montag noch zum Vor- oder bereits zum Folgemonat gehört. Die
Tageszahl wird in Agenda, Tage- und Wochenansicht in der vorhandenen
Tagesüberschrift und im Monat dezent beim Tagesdatum dargestellt. In der
Listenansicht werden Kalenderwoche und Tageszahl bei Aktivierung als eigene,
schmale Spalten ausgegeben.

Die zuletzt am jeweiligen Browser/Monitor gewählte Ansicht und das zugehörige Bezugsdatum werden clientseitig und instanzbezogen gespeichert. Dadurch bleiben beispielsweise Liste und gewählter Zeitraum auch erhalten, wenn eine Kalendersynchronisation die IPSView-Seite neu lädt. Unterschiedliche Monitore können unabhängig voneinander verschiedene Ansichten verwenden. Die konfigurierte Standardansicht dient weiterhin als Ausgangswert für neue Clients.

Über das **Kalenderfilter-Symbol** in der Toolbar lassen sich die in der Kalender-Ansicht konfigurierten Kalender clientseitig ein- oder ausblenden. Es können einzelne, mehrere, alle oder keine Kalender gewählt werden. Der Filter verändert weder die Instanzkonfiguration noch die Synchronisation und wird pro Browser/Monitor zusammen mit dem Ansichtsstand gespeichert. Dadurch können Kachel und unterschiedliche IPSView-Clients unabhängig voneinander verschiedene Kalenderkombinationen anzeigen.

Alle eingebetteten Dialoge verwenden ein gemeinsames responsives OpenCalendar-Modaldesign. Kopf- und Aktionsbereich bleiben bei kleinen Darstellungsflächen sichtbar, während nur der Inhaltsbereich scrollt. Einheitliche Größenklassen, Abstände, Schließen-Schaltflächen, Fokusdarstellung, Popup-Farben, Rahmen und Schatten gelten gleichermaßen für Kachelvisualisierung und IPSView.

Ein Klick auf einen Termin öffnet zunächst eine reine Termindetail-Ansicht mit Kalender, Beginn, Ende, Ort und Beschreibung. Schreibbare Einzeltermine können von dort gezielt bearbeitet oder gelöscht werden; schreibgeschützte und wiederkehrende Termine bleiben in der Detailansicht lesbar.
Beim Löschen erscheint eine eigene OpenCalendar-Bestätigung mit Terminname und Zeitraum. Die native Browser-Abfrage wird nicht verwendet; Abbrechen kehrt zum zuvor geöffneten Detail- oder Bearbeitungsdialog zurück.

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
Agenda, Liste und Tage-Ansicht verwenden Tage, die Wochenansicht verwendet
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

Agenda, Listen-, Tage-, Wochen- und Monatsansicht funktionieren direkt in der
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

// Optional nur Termine einer ausgewählten Kalenderinstanz (z. B. ID 23456).
$appointments = IPSKALVIEW_GetDayAppointments(12345, '2026-08-11', 23456);

// Alle Termine eines inklusiven lokalen Datumsbereichs providerübergreifend abrufen.
$appointments = IPSKALVIEW_GetAppointments(12345, '2026-08-11', '2026-08-17');

// Auch die vollständige Bereichsabfrage kann nach Kalenderinstanz gefiltert werden.
$appointments = IPSKALVIEW_GetAppointments(12345, '2026-08-11', '2026-08-17', 23456);

// Kompakte Tagesliste: summary, start, end, startTime und endTime.
$appointments = IPSKALVIEW_GetDayAppointmentsCompact(12345, '2026-08-11');

// Optional nur Termine einer ausgewählten Kalenderinstanz (z. B. ID 23456).
$appointments = IPSKALVIEW_GetDayAppointmentsCompact(12345, '2026-08-11', 23456);

// Kompakte Terminliste für einen inklusiven Datumsbereich.
$appointments = IPSKALVIEW_GetAppointmentsCompact(12345, '2026-08-11', '2026-08-17');

// Auch beim Datumsbereich kann optional nach Kalenderinstanz gefiltert werden.
$appointments = IPSKALVIEW_GetAppointmentsCompact(12345, '2026-08-11', '2026-08-17', 23456);

// Anzahl der Termine eines Tages oder Datumsbereichs ermitteln.
$count = IPSKALVIEW_GetDayAppointmentCount(12345, '2026-08-11');
$count = IPSKALVIEW_GetAppointmentCount(12345, '2026-08-11', '2026-08-17');

// Optional kann auch bei den Zählfunktionen nach Kalenderinstanz gefiltert werden.
$count = IPSKALVIEW_GetDayAppointmentCount(12345, '2026-08-11', 23456);

// Alle heute noch laufenden oder bevorstehenden Termine abrufen bzw. zählen.
$appointments = IPSKALVIEW_GetRemainingDayAppointments(12345);
$count = IPSKALVIEW_GetRemainingDayAppointmentCount(12345);

// Den nächsten noch nicht begonnenen Termin abrufen.
$appointment = IPSKALVIEW_GetNextAppointment(12345);

// Alle aktuell laufenden Termine abrufen oder direkt zählen.
$appointments = IPSKALVIEW_GetCurrentAppointments(12345);
$count = IPSKALVIEW_GetCurrentAppointmentCount(12345);

// Alle Termine abrufen bzw. zählen, die innerhalb der nächsten 24 Stunden beginnen.
$appointments = IPSKALVIEW_GetUpcomingAppointments(12345, 24);
$count = IPSKALVIEW_GetUpcomingAppointmentCount(12345, 24);

// Die nächsten drei noch nicht begonnenen Termine abrufen.
$appointments = IPSKALVIEW_GetNextAppointments(12345, 3);

// Metadaten aller in dieser Ansicht ausgewählten Kalender abrufen.
$calendars = IPSKALVIEW_GetSelectedCalendars(12345);

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
`calendarColor` und `canWrite`. Als letztes optionales Argument kann bei beiden
Funktionen die Instanz-ID eines ausgewählten Kalenders angegeben werden. Der
Standardwert `0` liefert alle ausgewählten Kalender.

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

Für typische Symcon-Skripte stehen zusätzlich Komfortfunktionen zur Verfügung.
`GetDayAppointmentCount()` und `GetAppointmentCount()` liefern direkt eine Zahl,
ohne dass die Terminliste zuvor in PHP dekodiert werden muss. Beide akzeptieren
optional eine ausgewählte Kalenderinstanz als Filter.

`GetRemainingDayAppointments()` liefert alle Termine des heutigen Tages, die zum
Abfragezeitpunkt noch nicht beendet sind. Laufende und ganztägige Termine werden
dabei mit berücksichtigt. `GetRemainingDayAppointmentCount()` liefert für dieselbe
Auswahl direkt die Anzahl. Auch diese beiden Funktionen können optional auf eine
ausgewählte Kalenderinstanz eingeschränkt werden.

`GetCurrentAppointments()` liefert ausschließlich Termine, die gerade laufen.
`GetCurrentAppointmentCount()` liefert für dieselbe Auswahl direkt die Anzahl.
`GetNextAppointment()` ist bewusst davon getrennt und liefert den nächsten noch
nicht begonnenen Termin aus dem lokal synchronisierten Zukunftsbestand. Ist kein
kommender Termin im Cache vorhanden, wird JSON `null` zurückgegeben.
`GetNextAppointments()` liefert entsprechend die nächsten 1 bis 1000 noch nicht
begonnenen Termine als Liste. Alle Funktionen unterstützen den optionalen
Kalenderfilter.

`GetUpcomingAppointments()` liefert Termine, die innerhalb der angegebenen nächsten
Stunden beginnen. Bereits laufende Termine werden bewusst nicht berücksichtigt und
können über `GetCurrentAppointments()` abgefragt werden. Das Zeitfenster darf 1 bis
26280 Stunden betragen und kann über Mitternacht sowie mehrere Kalendertage reichen.
`GetUpcomingAppointmentCount()` liefert für dieselbe Auswahl direkt die Anzahl.
Beide Funktionen unterstützen den optionalen Kalenderfilter.

`GetSelectedCalendars()` liefert die in der Instanz ausgewählten und aktivierten
Kalender als JSON mit `instanceId`, `name`, `color` und `canWrite`. Der nur im
Browser gesetzte temporäre Kalenderfilter verändert diese konfigurierte Auswahl
nicht.

## Technische Hinweise

Kachel und IPSView-Seite werden aus derselben Asset-Struktur unter
`visualization/` erzeugt. Die vendorten Helper `VisualizationThemeHelper`,
`IPSViewStyleHelper` und `IPSViewHTMLPageHelper` sorgen für gemeinsame
Symcon-Designvariablen, IPSView-Stilrollen und die kontrollierte Verwaltung der
WebContent-Variable. Kalender- und Terminfarben bleiben davon unabhängige
fachliche Inhaltsfarben.

### Tagesübersicht der Monatsansicht

In der Monatsansicht öffnet ein Klick auf die Tageszahl oder einen freien Bereich der Tageszelle die Tagesübersicht; der Hinweis `+ weitere` bleibt ebenfalls direkt anklickbar. Die Übersicht zeigt alle Termine des Tages, ganztägige zuerst und anschließend zeitgebundene chronologisch, sowie die Terminanzahl. Ein Klick auf einen Termin öffnet zunächst die Termindetail-Ansicht. Von dort kann ein schreibbarer Einzeltermin gezielt bearbeitet werden. Ist mindestens ein beschreibbarer Kalender verfügbar, kann über **Termin an diesem Tag erstellen** direkt ein neuer Termin für den ausgewählten Tag angelegt werden. Der zusätzliche Floating-Button zum Erstellen eines Termins wird deshalb in der Monatsansicht ausgeblendet und bleibt nur in Agenda, Liste, Tage und Woche verfügbar.
