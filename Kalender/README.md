# Kalender

Das Modul repräsentiert einen einzelnen Online- oder lokalen Kalender.

## Einrichtung

Eine Kalender-Instanz wird nicht über **Instanz hinzufügen** eingerichtet,
sondern aus der gefundenen Liste des **Kalender Konfigurators** erstellt. Dabei
werden Name, technische Kalender-ID, Anbieter-ID, Farbe, Schreibrechte und das
richtige Kalender Konto automatisch übernommen.

Nach der Erstellung:

1. Gewünschten Aktualisierungsplan sowie vergangenen und zukünftigen Zeitraum
   einstellen.
2. Die Konfiguration übernehmen.
3. **Jetzt synchronisieren** ausführen.
4. Unter **Anzahl Termine** und **Letzte Synchronisation** das Ergebnis prüfen.

> **Kalender-Instanzen nicht manuell anlegen oder lediglich über „Gateway
> ändern“ mit einem Konto verbinden.** Sie sollen über den zum Konto gehörenden
> **Kalender Konfigurator** erstellt werden. Nur der Konfigurator trägt den
> tatsächlichen Kalendernamen, die interne Identität, die Farbe, die
> Schreibrechte und die korrekte Kontoverbindung vollständig ein. Das ist
> besonders bei Konten mit mehreren Kalendern erforderlich.

Lokale Kalender werden aus einem **Kalender Konto** mit dem Anbieter **Symcon - Kalender** erstellt. Der damit verbundene Konfigurator bietet genau den im Kontoformular benannten Kalender an. Er hat keine technische Anbieteridentität und keine Netzwerksynchronisierung. Seine Original-`VCALENDAR`-Objekte werden dauerhaft als Instanzdaten in Symcon gespeichert; der Anzeigecache ist davon getrennt. Das Leeren des Caches und ein Neustart löschen keine lokalen Termine. Nehmen Sie Konto und Kalenderinstanz in Ihre Backups auf. Die Summe der gespeicherten Originaldaten ist auf 16 MiB begrenzt.

Nach der Erstellung durch den Konfigurator darf die Instanz im Objektbaum
beliebig verschoben oder vom Benutzer umbenannt werden.

## Funktionsumfang

- Abruf von CalDAV-Terminen über einen konfigurierbaren Zeitraum
- Auflösen wiederkehrender Termine für die lokale Anzeige
- lokaler JSON-Cache und zyklische Synchronisation für Online-Kalender
- lokale Kalender ohne Anbieter, OAuth oder Netzwerksynchronisierung
- Erstellen neuer Termine sowie neuer Google-, Microsoft-, Apple-iCloud- und CalDAV-Serientermine
- providerneutrale, eintägige ganztägige Aufgabentermine und Aufgabenserien mit
  offenem oder erledigtem Status und automatischer Fortschreibung überfälliger Aufgaben
- Ändern und Löschen einzelner Termine sowie einzelner Google-, Microsoft-, Apple-iCloud- und CalDAV-Serienvorkommnisse
- Bearbeiten einer vollständigen Google-, Microsoft-, Apple-iCloud- oder CalDAV-Terminserie
- Bearbeiten oder Löschen eines Google-, Microsoft-, Apple-iCloud- oder CalDAV-Serienvorkommnisses **und aller folgenden Termine** durch sicheres Teilen bzw. Kürzen der Serie
- Löschen einer vollständigen Google-, Microsoft-, Apple-iCloud- oder CalDAV-Terminserie über ein synchronisiertes Serienvorkommnis
- ETag-basierter Schutz vor dem Überschreiben zwischenzeitlicher Änderungen
- Statusvariablen für die gesamte geladene Terminanzahl, die Termine des
  aktuellen Tages und den Zeitpunkt der letzten Synchronisation

Google-, Microsoft-, Apple-iCloud- und CalDAV-Serien können als einzelnes Vorkommnis, als vollständige Serie oder **ab dem ausgewählten Vorkommnis für alle folgenden Termine** bearbeitet und gelöscht werden. Beim Bearbeiten teilt OpenCalendar eine unterstützte Serie am gewählten Termin in einen unveränderten vorderen und einen neu angelegten hinteren Serienteil. Beim Löschen wird der bestehende Parent direkt vor dem ausgewählten Vorkommnis beendet; beginnt die Auswahl beim ersten Vorkommnis, wird die komplette Serie gelöscht. Bei nummerierten Serien übernimmt der neue Serienteil nur die verbleibende Anzahl. Bestehende Ausnahmen ab dem Trennpunkt werden beim Teilen nicht in die neue Serie übernommen. Bei CalDAV werden Einzeländerungen weiterhin als `RECURRENCE-ID`-Ausnahmen gespeichert und Einzellöschungen über `EXDATE` abgebildet. Beim Bearbeiten der vollständigen CalDAV-Serie wird nur der Serien-Master geändert; vorhandene Ausnahmen bleiben erhalten.

## Voraussetzungen

- Symcon ab Version 9.0
- für Online-Kalender eine verbundene Instanz **Kalender Konto** und eine über den Konfigurator zugewiesene Kalender-ID
- für lokale Kalender ein verbundenes **Kalender Konto** mit dem Anbieter **Symcon - Kalender**

## Konfiguration

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation beziehungsweise die lokale Kalenderverarbeitung
Aktualisierungsplan | Vorgegebener Rhythmus von fünf Minuten bis jährlich oder ausschließlich manuelle Synchronisation
Benutzerdefiniertes Intervall | Eigener Abstand in Minuten; wird nur beim Zeitplan „Benutzerdefiniertes Intervall“ angezeigt
Vergangene Termine laden | Anzahl der Tage vor dem aktuellen Datum
Zukünftige Termine laden | Anzahl der Tage nach dem aktuellen Datum
Kalenderidentität | Vom Konfigurator gesetzte, schreibgeschützte Anbieterinformationen; bei lokalen Kalendern ist nur die Kalenderfarbe bearbeitbar

Bestehende Instanzen behalten ihren bisherigen Minutenwert als benutzerdefiniertes Intervall. Monatliche und jährliche Zeitpläne werden intern täglich auf Fälligkeit geprüft, damit keine für lange Zeiträume ungeeigneten Millisekunden-Timer verwendet werden. **Jetzt synchronisieren** bleibt unabhängig vom Zeitplan jederzeit verfügbar.

## Statusvariablen

Variable | Typ | Beschreibung
--- | --- | ---
Anzahl Termine | Integer | Anzahl der aktuell zwischengespeicherten Termine
Termine heute | Integer | Anzahl der Termine, die den aktuellen lokalen Kalendertag zeitlich überlappen
Letzte Synchronisation | Integer | Unix-Zeitpunkt der letzten erfolgreichen Abfrage

**Termine heute** berücksichtigt auch ganztägige und mehrtägige Termine. Der
Wert wird bei jeder Synchronisation und zusätzlich beim lokalen Tageswechsel
neu berechnet. Beim Tageswechsel werden außerdem offene überfällige
Aufgabentermine eines beschreibbaren Kalenders auf den neuen Tag verschoben.

Die eigentlichen Termindaten werden bewusst nicht in einer Statusvariable
gespiegelt, sondern nur im internen Modulcache gehalten. Konto, Kalender und
Kalenderansicht übertragen große Terminmengen automatisch in begrenzten Seiten.
Dadurch wird weder bei der Synchronisation noch beim Aufbau der Ansicht eine
einzelne JSON-Antwort mit sämtlichen Terminen benötigt.

Ein Termin enthält unter anderem `id`, `uid`, `resourceUrl`, `etag`, `summary`, `description`, `location`, `start`, `end`, `startTimestamp`, `endTimestamp`, `allDay`, `status`, `recurrenceRule` und `recurrenceId`. Wurde der Titel durch ein ausgewähltes iCalendar-Übersetzungsprofil angepasst, enthält `originalSummary` zusätzlich den unveränderten Originaltitel. Aufgabentermine enthalten außerdem `task`, `taskCompleted`, `taskStatus` (`open` oder `completed`), `taskRollForwardScope` (`occurrence`, `following` oder `disabled`), das kompatible abgeleitete Feld `taskFollowPlanned` und einen von der Statusmarkierung bereinigten `displaySummary`. Als Jahresereignis markierte Termine erhalten zusätzlich `anniversaryType`, `anniversaryDate`, `years` und `displaySummary`. Unterstützt werden `birthday`, `anniversary`, `wedding` und `death`. Für Geburtstage bleiben zusätzlich die kompatiblen Felder `birthday`, `birthDate` und `age` erhalten. Das Ausgangsdatum wird lokal in OpenCalendar gespeichert; der eigentliche Titel beim Kalenderanbieter bleibt unverändert.

## PHP-Befehlsreferenz

```php
bool IPSKAL_Synchronize(int $InstanzID);
string IPSKAL_GetEvents(int $InstanzID);
string IPSKAL_GetEventForEdit(int $InstanzID, string $EventJSON);
string IPSKAL_GetAnniversaryList(int $InstanzID, int $Days = 0, string $Type = '');
string IPSKAL_GetBirthdayList(int $InstanzID, int $Days = 0);
bool IPSKAL_SetAnniversary(int $InstanzID, string $EventJSON, string $Type, string $Date);
string IPSKAL_GetRecurringSeries(int $InstanzID, string $SeriesID, string $ResourceURL = '');
string IPSKAL_GetRecurringFollowing(int $InstanzID, string $SeriesID, string $OccurrenceID, string $OriginalStart, string $ResourceURL = '');
string IPSKAL_BeginEventsTransfer(int $InstanzID, int $StartTimestamp, int $EndTimestamp);
string IPSKAL_ReadEventsTransferPage(int $InstanzID, string $Token, int $Page);
bool IPSKAL_FinishEventsTransfer(int $InstanzID, string $Token);
string IPSKAL_CreateEvent(int $InstanzID, string $EventJSON);
string IPSKAL_UpdateEvent(int $InstanzID, string $EventJSON);
bool IPSKAL_DeleteEvent(int $InstanzID, string $EventJSON);
string IPSKAL_GetCalendarStatus(int $InstanzID);
void IPSKAL_ClearCache(int $InstanzID);
string IPSKAL_ExportLocalCalendar(int $InstanzID, string $Dateiname, bool $Überschreiben = false);
```

`IPSKAL_GetEvents()` bleibt als kompatibler Direktabruf für kleine Datenmengen
erhalten. Eigene Integrationen mit potenziell vielen Terminen sollten einen
Transfer beginnen, die Seiten von `0` bis `PageCount - 1` abrufen und den
Transfer anschließend auch im Fehlerfall beenden. `StartTimestamp` ist inklusiv,
`EndTimestamp` exklusiv.

`IPSKAL_GetEventForEdit()` lädt vor dem Bearbeiten den aktuellen Providerstand
eines Termins mit seinen schreibrelevanten Identitätsfeldern und dem ETag.
`EventJSON` enthält den aus `GetEvents()` erhaltenen Termin einschließlich
`startTimestamp`, `endTimestamp` und seiner Provideridentität. Die Rückgabe ist
der normalisierte Termin als JSON, kein `success`-Wrapper. Bei einem fehlgeschlagenen
Providerabruf kann ausschließlich für einen bereits lokal bekannten Aufgabentermin
der passende Cacheeintrag zurückgegeben werden. Andernfalls wird eine Ausnahme
ausgelöst und der Fehler im Kalenderstatus gespeichert.

`IPSKAL_GetAnniversaryList()` liefert die in dieser Kalenderinstanz von OpenCalendar verwalteten Jahresereignisse nach dem nächsten Vorkommnis sortiert. `Days = 0` liefert alle Einträge; jeder positive Wert begrenzt die Ausgabe auf die frei wählbare Anzahl der nächsten Kalendertage. Der optionale Filter `Type` akzeptiert `birthday`, `anniversary`, `wedding` oder `death`; ein leerer Wert liefert alle Typen. Die Datensätze enthalten `name`, `anniversaryType`, `anniversaryDate`, `nextDate`, `years`, `displayName` und `daysUntil`. Für Geburtstage werden zusätzlich `birthDate`, `nextBirthday` und `age` geliefert. `IPSKAL_GetBirthdayList()` bleibt als kompatibler Spezialfall erhalten und entspricht dem Filter `birthday`.

`IPSKAL_SetAnniversary()` markiert eine bereits vorhandene wiederkehrende Serie lokal als Jahresereignis. `Type` akzeptiert ebenfalls `birthday`, `anniversary`, `wedding` oder `death`; `Date` enthält das ursprüngliche Datum im Format `YYYY-MM-DD`. Als `EventJSON` kann ein Termin aus `IPSKAL_GetEvents()` verwendet werden. Für eine vollständige Serie ist der von `IPSKAL_GetRecurringSeries()` gelieferte Parent-Termin vorzuziehen. Die Funktion verändert weder Titel noch Wiederholungsregel beim Kalenderanbieter, sondern speichert ausschließlich die OpenCalendar-Metadaten.

```php
$series = IPSKAL_GetRecurringSeries(12345, 'provider-series-id');
IPSKAL_SetAnniversary(12345, $series, 'birthday', '1993-07-20');
```

`IPSKAL_GetCalendarStatus()` liefert neben Synchronisations- und Zählerinformationen
auch `calendarColor`, `canWrite`, `timezone`, `canCreateRecurrence`, `canUpdateFollowing`,
`canUpdateSeries` und `canDeleteSeries`. Die Serienfähigkeiten und die Zeitzone werden aus den vom
Provider erkannten Kalender-Metadaten übernommen.

`IPSKAL_ExportLocalCalendar()` ist ausschließlich für lokale Kalender vorgesehen
und nur über die Symcon-Konsole beziehungsweise ein eigenes Skript nutzbar. Es
schreibt die originalen iCalendar-Daten als ICS-Datei nach
`<Symcon-Verzeichnis>/media/OpenCalendar/`. Der Dateiname darf keinen Pfad
enthalten und muss auf `.ics` enden. Vorhandene Dateien werden standardmäßig
nicht überschrieben.

```php
$file = IPSKAL_ExportLocalCalendar(12345, 'lokaler-kalender-backup.ics');
echo $file;
```

Für ein bewusstes Überschreiben eines vorhandenen Sicherungsexports muss der
dritte Parameter `true` sein.

### Termin erstellen

```php
$result = IPSKAL_CreateEvent(12345, json_encode([
    'summary'     => 'Besprechung',
    'description' => 'Projektstatus abstimmen',
    'location'    => 'Büro',
    'start'       => '2026-07-20T10:00:00+02:00',
    'end'         => '2026-07-20T11:00:00+02:00'
]));
```

Bei ganztägigen Terminen werden `start` und `end` als Datum angegeben. Das Ende ist entsprechend iCalendar exklusiv:

```php
$result = IPSKAL_CreateEvent(12345, json_encode([
    'summary' => 'Urlaub',
    'start'   => '2026-08-03',
    'end'     => '2026-08-08',
    'allDay'  => true
]));
```

### Aufgabentermin erstellen und erledigen

Ein Aufgabentermin ist eintägig und ganztägig. Er darf wiederkehrend sein. Bei
CalDAV und lokalen Kalendern werden die Aufgabenfelder als
`X-OPENCALENDAR-*`-Eigenschaften, bei Google Calendar als private
`extendedProperties` gespeichert. Ältere Titelmarker wie `[OC:TODO]` und
`[OC:DONE]` werden weiterhin gelesen und beim nächsten Schreiben migriert.

Ist für diese Kalenderinstanz eine Microsoft-To-Do-Aufgabenliste ausgewählt,
legt `task = true` stattdessen eine native Aufgabe in dieser Liste an. Titel,
Beschreibung, Fälligkeit, Status und eine optionale Wiederholung werden dabei
über Microsoft Graph gespeichert. Das Ergebnis enthält die native
`taskId`/`taskListId`-Identität und `sourceType = microsoft-todo`. Ein Aufruf
ohne `task = true` erstellt weiterhin einen normalen Outlook-Kalendertermin.

```php
$result = IPSKAL_CreateEvent(12345, json_encode([
    'summary'       => 'Versicherung prüfen',
    'task'          => true,
    'taskCompleted' => false,
    'allDay'        => true,
    'start'         => '2026-09-10',
    'end'           => '2026-09-11'
]));
```

Zum Erledigen wird die Identität aus `IPSKAL_GetEvents()` zusammen mit der
Statusänderung übergeben. Eine erneute Änderung auf `false` öffnet die Aufgabe
wieder:

```php
$result = IPSKAL_UpdateEvent(12345, json_encode([
    'uid'         => 'event-uid@example',
    'resourceUrl' => 'https://server.example/calendar/task.ics',
    'etag'        => '"123456"',
    'changes'     => [
        'task'          => true,
        'taskCompleted' => true
    ]
]));
```

Offene kalenderbasierte Aufgabentermine mit einem Datum vor heute werden beim lokalen
Tageswechsel und bei jeder Synchronisation gemäß `taskRollForwardScope`
behandelt. `occurrence` zieht nur das älteste offene Vorkommnis auf heute,
`following` verschiebt den ab diesem Vorkommnis verbleibenden Serienteil und
`disabled` lässt überfällige Aufgaben unverändert. Erledigte Aufgaben bleiben
in allen Fällen unverändert. `taskFollowPlanned = true` bleibt als kompatible
Abbildung von `following` erhalten. Das Mitverschieben erfordert eine
Kalenderanbieter-Unterstützung für „diesen und alle folgenden Termine“. Da dabei
der echte Kalendertermin aktualisiert wird, muss der Kalender beschreibbar sein.
Zeitgebundene Aufgabentermine werden abgewiesen. Liegt ohne
Mitverschieben bereits ein weiteres geplantes Vorkommnis zwischen dem alten und
dem neuen Datum, wird die offene Aufgabe als Einzeltermin weitergeführt. So
bleibt der Serienplan erhalten und Microsoft 365 kann die Synchronisation nicht
wegen eines überlappenden Serienelements ablehnen.

Native Microsoft-To-Do-Aufgaben werden nicht durch OpenCalendar nachgezogen.
Ihre Fälligkeit und Serienfolge bleiben vollständig unter der Verwaltung von
Microsoft. Bei einer Serienaufgabe zeigt OpenCalendar die aktuell von Graph
gelieferte Aufgabe; die nächste offene Aufgabe erscheint nach dem Erledigen und
der folgenden Synchronisation.

Bei `UpdateEvent` gilt `taskRollForwardScope = following` ebenfalls für eine Änderung des
Startdatums einer Serienaufgabe: Der ausgewählte und alle folgenden Termine
werden mit neu verankertem Serienplan verschoben. Beim ersten Vorkommnis ist
das die ganze Serie. Statusänderungen ohne Datumsänderung bleiben auf das
einzelne Vorkommnis begrenzt. Bestehende Ausnahmen im verschobenen Serienteil
werden zurückgesetzt.

Die Zuordnung eines nachgezogenen Einzeltermins zu seiner Ursprungsserie bleibt
auch außerhalb des eingestellten Synchronisationszeitraums erhalten. Eine
fehlende Aufgabe wird, soweit der Anbieter dies unterstützt, gezielt anhand
ihrer Identität geprüft. Nur bestätigte Erledigung, Entfernung der
Aufgabenkennzeichnung oder Löschung gibt die Serie wieder frei. Bei einem
vorübergehenden Abfragefehler bleibt die Zuordnung vorsichtshalber bestehen.

### Schreibvorgänge und Synchronisationsfehler

Eine vom Kalenderanbieter bestätigte Erstellung, Änderung oder Löschung bleibt
erfolgreich, auch wenn das anschließende Aktualisieren des lokalen Caches
fehlschlägt. `CreateEvent` und `UpdateEvent` liefern dann weiterhin
`success = true` und den bestätigten Termin; `error` enthält gegebenenfalls die
nachgelagerte Fehlermeldung. `DeleteEvent` liefert weiterhin `true`.
Der Aktualisierungsfehler ist außerdem über `GetCalendarStatus().lastError`
erkennbar. Den Schreibvorgang deshalb nicht erneut ausführen, sondern die
Synchronisation wiederholen. Beim Wechsel in einen anderen Kalender wird die
Zielkopie nicht aufgrund eines solchen nachgelagerten Lesefehlers gelöscht.

Erfolgreich empfangene Kalenderdaten werden vor dem zugehörigen
Synchronisationsmarker gespeichert. Scheitert danach das automatische
Nachziehen einer Aufgabe, bleiben auch unabhängige neue oder geänderte Termine
erhalten. Die Synchronisation meldet den Aufgabenfehler und kann erneut
gestartet werden.

### Serientermine erstellen

Für beschreibbare Google-, Microsoft-, Apple-iCloud- und CalDAV-Kalender können beim Erstellen zusätzlich
providerneutrale Serienangaben übergeben werden. Bei Google verwendet OpenCalendar
die Kalenderzeitzone. Für Microsoft und CalDAV wird die übergebene Zeitzone verwendet;
fehlt sie bei einem Aufruf über die Visualisierung, wird die Zeitzone des Clients
verwendet. CalDAV schreibt für zeitgebundene Serien zusätzlich einen passenden
`VTIMEZONE`-Block. Dadurch bleibt die lokale Uhrzeit auch über
Sommer-/Winterzeitwechsel erhalten:

```php
$result = IPSKAL_CreateEvent(12345, json_encode([
    'summary'  => 'Jour fixe',
    'start'    => '2026-08-17T10:00:00+02:00',
    'end'      => '2026-08-17T11:00:00+02:00',
    'recurrence' => [
        'frequency' => 'WEEKLY',
        'interval'  => 1,
        'byDay'     => ['MO'],
        'endMode'   => 'until',
        'until'     => '2026-12-31'
    ]
]));
```

Unterstützt werden für Google, Microsoft und CalDAV/Apple iCloud `DAILY`, `WEEKLY`, `MONTHLY` und
`YEARLY`, ein Intervall, bei wöchentlichen Serien optionale Wochentage sowie die
Endarten `never`, `count` und `until`. Bei Microsoft entspricht eine monatliche
Serie dem Kalendertag des Starttermins und eine jährliche Serie zusätzlich dessen
Monat. Bei Google, Microsoft, Apple iCloud und CalDAV können einzelne Vorkommnisse,
die vollständige Serie sowie **dieses und alle folgenden Vorkommnisse** bearbeitet
und gelöscht werden, sofern OpenCalendar die Wiederholungsregel verlustfrei teilen
kann. Vor dem Bearbeiten einer vollständigen Serie lädt
`IPSKAL_GetRecurringSeries()` den verifizierten Parent-Termin. Für „dieses und
folgende“ liefert `IPSKAL_GetRecurringFollowing()` zusätzlich das verifizierte
Zielvorkommnis und passt bei `COUNT`-Serien die verbleibende Anzahl an. Für CalDAV
kann die bereits bekannte `ResourceURL` an beide Funktionen übergeben werden; damit
wird das Kalenderobjekt direkt geladen. Beim Speichern wird die ursprüngliche Serie
unmittelbar vor dem Zieltermin beendet und ab dem Ziel eine neue Serie angelegt.
Bestehende Ausnahmen ab dem Trennpunkt gehören dadurch nicht zum neuen Serienteil.
Beim Löschen bleibt nur der vordere Serienteil bestehen.

### Termin ändern

`uid`, `resourceUrl` und `etag` stammen bei Einzelterminen und Vorkommnissen aus
`IPSKAL_GetEvents`. Für die vollständige Google-Serie sollten diese Werte aus
`IPSKAL_GetRecurringSeries()` verwendet werden; für „dieses und folgende“ aus
`IPSKAL_GetRecurringFollowing()`. Unter `changes` werden nur die zu
ändernden Felder übergeben:

```php
$result = IPSKAL_UpdateEvent(12345, json_encode([
    'uid'         => 'event-uid@example',
    'resourceUrl' => 'https://server.example/calendar/event.ics',
    'etag'        => '"123456"',
    'changes'     => [
        'summary' => 'Geänderte Besprechung',
        'location' => 'Konferenzraum'
    ]
]));
```

### Termin löschen

```php
$success = IPSKAL_DeleteEvent(12345, json_encode([
    'resourceUrl' => 'https://server.example/calendar/event.ics',
    'etag'        => '"123456"'
]));
```

Nach einer vom Anbieter bestätigten Schreiboperation wird versucht, den lokalen
Termincache erneut vom Server zu laden. Schlägt nur dieses Nachladen fehl, bleibt
der Schreibvorgang erfolgreich; Details und Hinweise zum erneuten Synchronisieren
stehen unter [Schreibvorgänge und Synchronisationsfehler](#schreibvorgänge-und-synchronisationsfehler).

## Fehlerbehebung

Problem | Prüfung
--- | ---
Konfiguration unvollständig | Instanz im Kalender Konfigurator löschen und aus der aktuellen Kontoliste neu erstellen; die technischen Identitätsfelder nicht manuell setzen
Synchronisation fehlgeschlagen | Zuerst im verbundenen Kalender Konto **Verbindung testen**, anschließend Konto und Kalender erneut synchronisieren
Keine Termine sichtbar | Zeitraum für vergangene und zukünftige Termine prüfen und kontrollieren, ob der Online-Kalender im gewählten Zeitraum Termine enthält
Kalender ist schreibgeschützt | Schreibrechte beim Anbieter prüfen; ICS/Webcal-Abonnements sind immer schreibgeschützt
Ändern oder Löschen wird bei einem Serientermin verweigert | Google, Microsoft und unterstützte Apple/CalDAV-RRULEs erlauben Vorkommnis, dieses und folgende sowie vollständige Serie; komplexe Wiederholungsregeln werden nicht verlustbehaftet geteilt
Schreibkonflikt | Kalender erneut synchronisieren; der ETag-Schutz verhindert das Überschreiben einer zwischenzeitlich geänderten Serverversion
