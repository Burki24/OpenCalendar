# Kalender

Das Modul repräsentiert einen einzelnen Online- oder Symcon-lokalen Kalender.

Die Dateianhangsfunktion befindet sich in `dev_9.1` weiterhin im Ausbau.
Für Termine eines Symcon-lokalen Kalenders können berechtigte Ansichten bereits
TXT-, PDF-, PNG- und JPEG-Dateien bis 2 MiB lokal speichern, herunterladen und
bewusst löschen. Die Verwaltungsfunktionen für Sicherung und Bereinigung sind unter
[Lokale Dateianhänge sichern und aufräumen](../docs/attachments-administration.md)
beschrieben. Anbieter-Uploads, lokale Anhänge an Online-Termine, Google und
benutzerbezogene Rechte sind noch nicht freigegeben.

## Einrichtung

Ein Online-Kalender wird nicht über **Instanz hinzufügen** manuell eingerichtet.
Er wird entweder durch die **Kalender Einrichtung** oder aus der gefundenen
Liste des **Kalender Konfigurators** erstellt. Beide Wege übernehmen Name,
technische Kalender-ID, Anbieter-ID, Farbe, Schreibrechte und das richtige
Kalender Konto automatisch.

Nach der Erstellung:

1. Gewünschten Aktualisierungsplan sowie vergangenen und zukünftigen Zeitraum
   einstellen.
2. Die Konfiguration übernehmen.
3. **Jetzt synchronisieren** ausführen.
4. Unter **Anzahl Termine** und **Letzte Synchronisation** das Ergebnis prüfen.

> **Online-Kalender nicht manuell anlegen oder lediglich über „Gateway
> ändern“ mit einem Konto verbinden.** Unterstützt sind die Erstellung durch die
> **Kalender Einrichtung** und aus der aktuellen Liste des **Kalender
> Konfigurators**. Beide Wege tragen die vollständige Kalenderidentität, Farbe,
> Schreibrechte und die korrekte Kontoverbindung ein.

Nach der korrekten Erstellung darf die Instanz im Objektbaum
beliebig verschoben oder vom Benutzer umbenannt werden.

## Lokaler Kalender

Lokale Kalender werden wie alle anderen Kalender über ein **Kalender Konto** und
den **Kalender Konfigurator** angelegt:

1. Beim Kalender Konto als Anbieter **Symcon - Kalender** wählen sowie Name und
   Farbe festlegen.
2. Das Konto aktivieren und speichern.
3. Einen damit verbundenen Kalender Konfigurator öffnen und den angebotenen
   Kalender anlegen. Dabei werden Verbindung, Farbe und Kalenderidentität
   automatisch übernommen.

Technisch aktiviert die boolesche Eigenschaft `LocalCalendar` den lokalen Betrieb.
Sie wird dabei vom Konfigurator gesetzt und ist kein manuell umzustellender
Betriebsmodus. Ein lokaler Kalender verwendet eine lokale Kalenderidentität,
aber keine externe Anbieter-ID oder URL. Online- und lokale Kalender sind
getrennte Datenbestände: Zum Übernehmen bestehender Termine die
Verschieben-Funktion benutzen, nicht den Betriebsmodus eines eingerichteten
Online-Kalenders umstellen.

Die Originaldaten einschließlich Serienregeln, Änderungen einzelner Vorkommnisse
und Lösch-Ausnahmen werden im persistenten Attribut `LocalCalendarResources`
gespeichert. Der normale Termin-Cache enthält lediglich den aufgelösten
Anzeigezeitraum. **Cache leeren**, Änderungen an `PastDays`/`FutureDays` und Neustarts
lassen den Originalbestand erhalten. Ein erneut geladener Zeitraum wird aus diesen
Originalen berechnet. Bestätigte Schreibvorgänge aktualisieren die lokale Ansicht;
**Synchronisieren** liest im lokalen Modus nur den lokalen Bestand neu und führt
keine Netzwerkanfrage aus. Beim Tageswechsel werden fällige Aufgabentermine
verarbeitet. Offene überfällige Aufgaben werden dabei auch außerhalb des
eingestellten Anzeigezeitraums berücksichtigt, etwa nach längerer Abschaltung.

Pro lokalem Kalender sind insgesamt höchstens **16 MiB unkomprimierte
ICS-Originaldaten** vorgesehen. Eine Speicherung, die diese Grenze überschreiten
würde, wird abgewiesen; der zuvor gespeicherte Bestand bleibt erhalten. Dies ist
eine Speichergrenze für alle Originaltermine und Serien zusammen, keine Grenze
für die Anzahl der im Anzeigezeitraum berechneten Vorkommnisse.

Unterstützt werden Einzeltermine, Serien mit Einzel- und Folgeänderungen,
Aufgabentermine mit Nachziehen, Jahresereignisse, explizite Erinnerungsangaben,
Terminstatus und Verfügbarkeit. Erinnerungsangaben sind wie bei den anderen
Kalendern Daten für die vorhandenen Erinnerungsabfragen und keine eigenständige
Push-Benachrichtigung. Ein externer Kalenderlink ist nicht vorhanden.

Lokale Kalender können Quelle oder Ziel der vorhandenen kalenderübergreifenden
Verschieben-Funktion sein. Bei einem externen Ziel gelten dessen Möglichkeiten
und Einschränkungen; die Quelldaten werden erst nach bestätigter Zielerstellung
gelöscht. Schlägt das anschließende Löschen fehl, können beide Kopien existieren.

**Datensicherung:** Symcon ist hier der einzige Speicherort der Originale.
Regelmäßige Symcon-Backups sind erforderlich. Das Löschen der Kalender-Instanz
entfernt auch den Originalbestand; **Cache leeren** ist ausdrücklich keine
Terminlöschung. Zusätzlich kann ein lokaler Kalender ausschließlich über die
Symcon-Konsole beziehungsweise ein eigenes Skript als ICS-Datei exportiert werden.

## Funktionsumfang

- providerneutraler Abruf von Terminen über einen konfigurierbaren Zeitraum
- Auflösen wiederkehrender Termine für die lokale Anzeige
- lokaler JSON-Cache und zyklische Synchronisation
- Erstellen neuer Termine sowie neuer Google-, Microsoft-, Apple-iCloud- und CalDAV-Serientermine
- Ändern und Löschen einzelner Termine sowie einzelner Google-, Microsoft-, Apple-iCloud- und CalDAV-Serienvorkommnisse
- Bearbeiten einer vollständigen Google-, Microsoft-, Apple-iCloud- oder CalDAV-Terminserie
- Bearbeiten oder Löschen eines Google-, Microsoft-, Apple-iCloud- oder CalDAV-Serienvorkommnisses **und aller folgenden Termine** durch sicheres Teilen bzw. Kürzen der Serie
- Löschen einer vollständigen Google-, Microsoft-, Apple-iCloud- oder CalDAV-Terminserie über ein synchronisiertes Serienvorkommnis
- ETag-basierter Schutz vor dem Überschreiben zwischenzeitlicher Änderungen
- Statusvariablen für die gesamte geladene Terminanzahl, die Termine des
  aktuellen Tages und den Zeitpunkt der letzten Synchronisation

Google-, Microsoft-, Apple-iCloud- und CalDAV-Serien können als einzelnes Vorkommnis, als vollständige Serie oder **ab dem ausgewählten Vorkommnis für alle folgenden Termine** bearbeitet und gelöscht werden. Beim Bearbeiten teilt OpenCalendar eine unterstützte Serie am gewählten Termin in einen unveränderten vorderen und einen neu angelegten hinteren Serienteil. Beim Löschen wird der bestehende Parent direkt vor dem ausgewählten Vorkommnis beendet; beginnt die Auswahl beim ersten Vorkommnis, wird die komplette Serie gelöscht. Bei nummerierten Serien übernimmt der neue Serienteil nur die verbleibende Anzahl. Bestehende Ausnahmen ab dem Trennpunkt werden beim Teilen nicht in die neue Serie übernommen. Bei CalDAV werden Einzeländerungen weiterhin als `RECURRENCE-ID`-Ausnahmen gespeichert und Einzellöschungen über `EXDATE` abgebildet. Beim Bearbeiten der vollständigen CalDAV-Serie wird nur der Serien-Master geändert; vorhandene Ausnahmen bleiben erhalten.

## Aufgabentermine

Die API unterstützt kalenderbasierte Aufgaben und native Microsoft-To-Do-Aufgaben.
Die [Kalender Ansicht](../Kalender%20Ansicht/README.md#aufgabentermine) beschreibt
die Bedienung; die [Microsoft-Einrichtung](../README.md#microsoft-to-do-einrichten)
erläutert `Tasks.ReadWrite`, erneute OAuth-Anmeldung und Listenauswahl.

### Kalenderbasierte Aufgaben

Über `CreateEvent` und `UpdateEvent` werden `task`, `taskCompleted` und
`taskRollForwardScope` übergeben. Aufgaben sind eintägig und ganztägig.
CalDAV und lokale Kalender speichern die Aufgabenfelder als
`X-OPENCALENDAR-*`-Eigenschaften, Google Calendar als private
`extendedProperties`; der Titel bleibt dabei frei von technischen Markern.
Bestehende ASCII- und Kästchenmarker bleiben lesbar und werden für diese
Anbieter beim nächsten Aufgabenschreiben migriert. Microsoft-Kalendertermine
ohne native Aufgabenanbindung behalten die kompatiblen Titelmarker.

`taskRollForwardScope` steuert offene, überfällige Aufgaben bei Synchronisation
und lokalem Tageswechsel:

- `occurrence`: Nur die früheste anstehende Aufgabe auf heute nachziehen.
- `following`: Auch den folgenden Serienteil verschieben, sofern der Anbieter
  dies sicher unterstützt. Diese Auswahl gilt ebenfalls für manuelle
  Datumsänderungen.
- `disabled`: Keine automatische Änderung des Datums.

`taskFollowPlanned` bleibt kompatibel: `true` entspricht `following`, `false`
entspricht `occurrence`. Ein ausdrücklich übergebenes `taskRollForwardScope`
hat Vorrang. Erledigte Aufgaben werden nicht nachgezogen; eine reine
Statusänderung betrifft nur das ausgewählte Vorkommnis.

Würde das einzelne Nachziehen ein anderes Vorkommnis überschreiten, wird die
Aufgabe als Einzeltermin fortgeführt; die Zuordnung zur Ursprungsserie bleibt
gespeichert. Eine solche offene Aufgabe blockiert weiteres Nachziehen aus ihrer
Serie auch außerhalb des geladenen Zeitraums. Erst bestätigtes Erledigen,
Entfernen der Aufgabenkennzeichnung oder Löschen gibt die Serie frei.
Fehlgeschlagene oder uneindeutige Provider-Abfragen lösen diese Zuordnung nicht.

Die 3.0-Verträge bleiben erhalten:

- Aufgabenstatus, Terminstatus und Verfügbarkeit sind unabhängig. Ein
  vorläufiger, als frei markierter Kalendertermin bleibt beim Fortführen als
  Einzelaufgabe vorläufig und frei. Abgesagte Termine bleiben ausgeblendet und
  werden nicht automatisch verschoben.
- Nur nach einem als vorübergehend klassifizierten Providerfehler darf beim
  Bearbeiten auf eine bekannte Aufgabe im Cache zurückgegriffen werden.
  Berechtigungsfehler, Konflikte und bestätigtes Fehlen werden nicht verdeckt.

### Native Microsoft To Do

Die Eigenschaft `MicrosoftTaskListID` bindet eine Aufgabenliste des verbundenen
Microsoft-Kontos ein. Die Auswahl ist eine OpenCalendar-Zuordnung, keine
Verknüpfung der Liste mit einem Outlook-Kalender bei Microsoft.

Bei ausgewählter Liste legt `CreateEvent` mit `task = true` eine native Aufgabe
an. Ohne Aufgabenkennzeichnung entsteht weiterhin ein Outlook-Kalendertermin.
Bestehende kalenderbasierte Aufgaben werden nicht automatisch konvertiert.
Die Schreibantwort enthält die projizierte Aufgabenidentität mit
`sourceType = microsoft-todo`, `taskId` und `taskListId`. Diese Identität bei
späteren Änderungen oder Löschungen beibehalten.

Native Aufgaben liegen getrennt vom Termin-Cache in `CachedMicrosoftTasks`.
`GetMicrosoftTasks` liefert diese nativen Aufgabendaten; `GetEvents` bleibt
ein Abruf der Kalendertermine. Erst der paginierte Transfer zur Kalender
Ansicht ergänzt die Aufgaben mit gültiger Fälligkeit als ganztägige Einträge.
Die Fälligkeit wird auf den lokalen Kalendertag umgerechnet; Aufgaben ohne
Fälligkeit erscheinen nicht in dieser Projektion.

Microsoft verwaltet die Wiederholung. OpenCalendar erzeugt keine zukünftigen
Vorkommnisse, solange die aktuelle Serienaufgabe offen ist. Nach ihrer Erledigung
übernimmt die nächste Synchronisation die von Microsoft bereitgestellte
Folgeaufgabe. Native Aufgaben werden nicht nachgezogen und erhalten in der
Projektion `taskRollForwardScope = disabled`. Die Kalenderoperationen für
ganze Serien oder folgende Serienteile gelten nicht für diese Projektion.

Unterstützt das Konto keine Delta-Abfrage für Aufgaben, wird die Liste vollständig
und seitenweise eingelesen. Der Vollabgleich ersetzt den Aufgabenbestand, damit
gelöschte Einträge nicht im Cache verbleiben. Aufgabenfehler stehen getrennt
unter `microsoftTaskLastError` im Kalenderstatus; ein erfolgreicher
Kalenderabruf allein beweist daher noch keine erfolgreiche Aufgabensynchronisation.

## Voraussetzungen

- Symcon ab Version 9.1
- eine verbundene Instanz **Kalender Konto**
- eine über die Kalender Einrichtung oder den Konfigurator zugewiesene Kalender-ID

## Konfiguration

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation
Aktualisierungsplan | Vorgegebener Rhythmus von fünf Minuten bis jährlich oder ausschließlich manuelle Synchronisation
Benutzerdefiniertes Intervall | Eigener Abstand in Minuten; wird nur beim Zeitplan „Benutzerdefiniertes Intervall“ angezeigt
Vergangene Termine laden | Anzahl der Tage vor dem aktuellen Datum
Zukünftige Termine laden | Anzahl der Tage nach dem aktuellen Datum
Microsoft-To-Do-Aufgabenliste | Optional eingebundene native Liste des Microsoft-Kontos; leer bedeutet keine Einbindung. Benötigt Aufgaben-Zugriff über OAuth
Kalenderidentität | Von der Kalender Einrichtung oder dem Konfigurator gesetzte, schreibgeschützte Anbieterinformationen

Bestehende Instanzen behalten ihren bisherigen Minutenwert als benutzerdefiniertes Intervall. Monatliche und jährliche Zeitpläne werden intern täglich auf Fälligkeit geprüft, damit keine für lange Zeiträume ungeeigneten Millisekunden-Timer verwendet werden. **Jetzt synchronisieren** bleibt unabhängig vom Zeitplan jederzeit verfügbar.

## Statusvariablen

Variable | Typ | Beschreibung
--- | --- | ---
Anzahl Termine | Integer | Anzahl der aktuell zwischengespeicherten Termine
Termine heute | Integer | Anzahl der Termine, die den aktuellen lokalen Kalendertag zeitlich überlappen
Letzte Synchronisation | Integer | Unix-Zeitpunkt der letzten erfolgreichen Abfrage

**Termine heute** berücksichtigt auch ganztägige und mehrtägige Termine. Der
Wert wird bei jeder Synchronisation und zusätzlich beim lokalen Tageswechsel
neu berechnet.

Die eigentlichen Termindaten werden bewusst nicht in einer Statusvariable
gespiegelt, sondern nur im internen Modulcache gehalten. Konto, Kalender und
Kalenderansicht übertragen große Terminmengen automatisch in begrenzten Seiten.
Dadurch wird weder bei der Synchronisation noch beim Aufbau der Ansicht eine
einzelne JSON-Antwort mit sämtlichen Terminen benötigt.

Ein Termin enthält unter anderem `id`, `uid`, `resourceUrl`, `etag`, `summary`, `description`, `location`, `start`, `end`, `startTimestamp`, `endTimestamp`, `allDay`, `status`, `recurrenceRule` und `recurrenceId`. Wurde der Titel durch ein ausgewähltes iCalendar-Übersetzungsprofil angepasst, enthält `originalSummary` zusätzlich den unveränderten Originaltitel. Als Jahresereignis markierte Termine erhalten zusätzlich `anniversaryType`, `anniversaryDate`, `years` und `displaySummary`. Unterstützt werden `birthday`, `anniversary`, `wedding` und `death`. Für Geburtstage bleiben zusätzlich die kompatiblen Felder `birthday`, `birthDate` und `age` erhalten. Das Ausgangsdatum wird lokal in OpenCalendar gespeichert; der eigentliche Titel beim Kalenderanbieter bleibt unverändert.

## PHP-Befehlsreferenz

Aufgabentermine enthalten zusätzlich `task`, `taskCompleted`, `taskStatus`
(`open` oder `completed`), `taskRollForwardScope` (`occurrence`, `following` oder
`disabled`), das kompatible Feld `taskFollowPlanned` und `displaySummary` mit dem von
Aufgabenmarkern bereinigten Titel. Bei einem aus einer Serie nachgezogenen
Einzeltermin kennzeichnet `taskRolledForward` die gespeicherte Serienzuordnung.
Diese Aufgabenfelder sind unabhängig von `status` (Terminstatus) und
`transparency` (`OPAQUE` für belegt, `TRANSPARENT` für frei).

```php
bool IPSKAL_Synchronize(int $InstanzID);
string IPSKAL_GetEvents(int $InstanzID);
string IPSKAL_GetMicrosoftTasks(int $InstanzID);
string IPSKAL_GetAnniversaryList(int $InstanzID, int $Days = 0, string $Type = '');
string IPSKAL_GetBirthdayList(int $InstanzID, int $Days = 0);
bool IPSKAL_SetAnniversary(int $InstanzID, string $EventJSON, string $Type, string $Date);
string IPSKAL_GetEventForEdit(int $InstanzID, string $EventJSON);
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

`IPSKAL_ExportLocalCalendar()` ist nur für lokale Kalender vorgesehen und kann
nicht aus dem Web-Interface oder IPSView aufgerufen werden. Es schreibt die
originalen iCalendar-Daten nach `<Symcon-Verzeichnis>/media/OpenCalendar/`.
Der Dateiname darf keinen Pfad enthalten, muss auf `.ics` enden und wird ohne
den Parameter `true` nicht überschrieben:

```php
$file = IPSKAL_ExportLocalCalendar(12345, 'lokaler-kalender-backup.ics');
echo $file;
```

`IPSKAL_GetAnniversaryList()` liefert die in dieser Kalenderinstanz von OpenCalendar verwalteten Jahresereignisse nach dem nächsten Vorkommnis sortiert. `Days = 0` liefert alle Einträge; jeder positive Wert begrenzt die Ausgabe auf die frei wählbare Anzahl der nächsten Kalendertage. Der optionale Filter `Type` akzeptiert `birthday`, `anniversary`, `wedding` oder `death`; ein leerer Wert liefert alle Typen. Die Datensätze enthalten `name`, `anniversaryType`, `anniversaryDate`, `nextDate`, `years`, `displayName` und `daysUntil`. Für Geburtstage werden zusätzlich `birthDate`, `nextBirthday` und `age` geliefert. `IPSKAL_GetBirthdayList()` bleibt als kompatibler Spezialfall erhalten und entspricht dem Filter `birthday`.

`IPSKAL_SetAnniversary()` markiert eine bereits vorhandene wiederkehrende Serie lokal als Jahresereignis. `Type` akzeptiert ebenfalls `birthday`, `anniversary`, `wedding` oder `death`; `Date` enthält das ursprüngliche Datum im Format `YYYY-MM-DD`. Als `EventJSON` kann ein Termin aus `IPSKAL_GetEvents()` verwendet werden. Für eine vollständige Serie ist der von `IPSKAL_GetRecurringSeries()` gelieferte Parent-Termin vorzuziehen. Die Funktion verändert weder Titel noch Wiederholungsregel beim Kalenderanbieter, sondern speichert ausschließlich die OpenCalendar-Metadaten.

```php
$series = IPSKAL_GetRecurringSeries(12345, 'provider-series-id');
IPSKAL_SetAnniversary(12345, $series, 'birthday', '1993-07-20');
```

`IPSKAL_GetEventForEdit()` lädt die aktuelle providerseitige Identität eines
Termins erneut und liefert den normalisierten Termin einschließlich der für
Schreibvorgänge relevanten Felder wie dem aktuellen ETag.

`IPSKAL_GetCalendarStatus()` liefert neben Synchronisations- und Zählerinformationen
auch `calendarColor`, `canWrite`, `timezone`, die Serienfähigkeiten,
`maxReminders`, `canUseDefaultReminder`, `canCreateWithDefaultReminder`,
`canWriteStatus` und `canWriteTransparency` sowie providerabhängige Standardwerte
für Status, Verfügbarkeit und Erinnerungen. Die Fähigkeiten und Standardwerte
werden aus den vom Provider erkannten Kalender-Metadaten übernommen.

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

### Schreibvorgänge und Synchronisationsfehler

Nach dem Erstellen oder Ändern wird zunächst versucht, den geschriebenen Termin
gezielt beim Anbieter abzurufen und den lokalen Cache zu aktualisieren. Ist das
nicht möglich, wird eine Synchronisation versucht. Nach einer bestätigten
Löschung werden die betroffenen Termine direkt aus dem lokalen Cache entfernt;
ein erneuter Serverabruf ist dafür nicht erforderlich.

Eine vom Anbieter bestätigte Erstellung, Änderung oder Löschung bleibt
erfolgreich, auch wenn die anschließende lokale Verarbeitung oder Aktualisierung
fehlschlägt. `CreateEvent` und `UpdateEvent` liefern dann weiterhin
`success = true` und den bestätigten Termin; `error` enthält gegebenenfalls die
nachgelagerte Fehlermeldung. `DeleteEvent` liefert weiterhin `true`.
Der Fehler ist außerdem über `GetCalendarStatus().lastError` erkennbar.
Den Schreibvorgang deshalb nicht erneut ausführen, sondern die Synchronisation
wiederholen. Beim Kalenderwechsel wird die Zielkopie nicht wegen eines solchen
Folgefehlers nach bestätigter Löschung an der Quelle zurückgenommen.

Empfangene Kalenderdaten werden vor dem zugehörigen Synchronisationsmarker
gespeichert. Scheitert danach das automatische Nachziehen einer Aufgabe,
bleiben die zuvor empfangenen Termine erhalten. Die Synchronisation meldet
den Aufgabenfehler und kann erneut gestartet werden.

## Fehlerbehebung

Problem | Prüfung
--- | ---
Konfiguration unvollständig | Instanz löschen und über Kalender Einrichtung oder Kalender Konfigurator aus der aktuellen Kontoliste neu erstellen; die technischen Identitätsfelder nicht manuell setzen
Synchronisation fehlgeschlagen | Zuerst im verbundenen Kalender Konto **Verbindung testen**, anschließend Konto und Kalender erneut synchronisieren
Keine Termine sichtbar | Zeitraum für vergangene und zukünftige Termine prüfen und kontrollieren, ob der Online-Kalender im gewählten Zeitraum Termine enthält
Kalender ist schreibgeschützt | Schreibrechte beim Anbieter prüfen; ICS/Webcal-Abonnements sind immer schreibgeschützt
Ändern oder Löschen wird bei einem Serientermin verweigert | Google, Microsoft und unterstützte Apple/CalDAV-RRULEs erlauben Vorkommnis, dieses und folgende sowie vollständige Serie; komplexe Wiederholungsregeln werden nicht verlustbehaftet geteilt
Schreibkonflikt | Kalender erneut synchronisieren; der ETag-Schutz verhindert das Überschreiben einer zwischenzeitlich geänderten Serverversion
