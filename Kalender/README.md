# Kalender

Das Modul repräsentiert einen einzelnen Online-Kalender.

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

Nach der Erstellung durch den Konfigurator darf die Instanz im Objektbaum
beliebig verschoben oder vom Benutzer umbenannt werden.

## Funktionsumfang

- Abruf von CalDAV-Terminen über einen konfigurierbaren Zeitraum
- Auflösen wiederkehrender Termine für die lokale Anzeige
- lokaler JSON-Cache und zyklische Synchronisation
- Erstellen neuer Termine
- Ändern einzelner, nicht wiederkehrender Termine
- Löschen einzelner, nicht wiederkehrender Termine
- ETag-basierter Schutz vor dem Überschreiben zwischenzeitlicher Änderungen
- Statusvariablen für die gesamte geladene Terminanzahl, die Termine des
  aktuellen Tages und den Zeitpunkt der letzten Synchronisation

Das Ändern oder einzelne Löschen von Vorkommen einer Terminserie ist noch nicht freigegeben. Dadurch verhindert das Modul, dass eine komplette Serie versehentlich überschrieben oder gelöscht wird.

## Voraussetzungen

- Symcon ab Version 9.0
- eine verbundene Instanz **Kalender Konto**
- eine über den Konfigurator zugewiesene Kalender-ID

## Konfiguration

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation
Aktualisierungsplan | Vorgegebener Rhythmus von fünf Minuten bis jährlich oder ausschließlich manuelle Synchronisation
Benutzerdefiniertes Intervall | Eigener Abstand in Minuten; wird nur beim Zeitplan „Benutzerdefiniertes Intervall“ angezeigt
Vergangene Termine laden | Anzahl der Tage vor dem aktuellen Datum
Zukünftige Termine laden | Anzahl der Tage nach dem aktuellen Datum
Kalenderidentität | Vom Konfigurator gesetzte, schreibgeschützte Anbieterinformationen

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

Ein Termin enthält unter anderem `id`, `uid`, `resourceUrl`, `etag`, `summary`, `description`, `location`, `start`, `end`, `startTimestamp`, `endTimestamp`, `allDay`, `status`, `recurrenceRule` und `recurrenceId`. Wurde der Titel durch ein ausgewähltes iCalendar-Übersetzungsprofil angepasst, enthält `originalSummary` zusätzlich den unveränderten Originaltitel.

## PHP-Befehlsreferenz

```php
bool IPSKAL_Synchronize(int $InstanzID);
string IPSKAL_GetEvents(int $InstanzID);
string IPSKAL_BeginEventsTransfer(int $InstanzID, int $StartTimestamp, int $EndTimestamp);
string IPSKAL_ReadEventsTransferPage(int $InstanzID, string $Token, int $Page);
bool IPSKAL_FinishEventsTransfer(int $InstanzID, string $Token);
string IPSKAL_CreateEvent(int $InstanzID, string $EventJSON);
string IPSKAL_UpdateEvent(int $InstanzID, string $EventJSON);
bool IPSKAL_DeleteEvent(int $InstanzID, string $EventJSON);
string IPSKAL_GetCalendarStatus(int $InstanzID);
void IPSKAL_ClearCache(int $InstanzID);
```

`IPSKAL_GetEvents()` bleibt als kompatibler Direktabruf für kleine Datenmengen
erhalten. Eigene Integrationen mit potenziell vielen Terminen sollten einen
Transfer beginnen, die Seiten von `0` bis `PageCount - 1` abrufen und den
Transfer anschließend auch im Fehlerfall beenden. `StartTimestamp` ist inklusiv,
`EndTimestamp` exklusiv.

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

### Termin ändern

`uid`, `resourceUrl` und `etag` stammen aus `IPSKAL_GetEvents`. Unter `changes` werden nur die zu ändernden Felder übergeben:

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

Nach jeder erfolgreichen Schreiboperation wird der lokale Termincache erneut vom Server geladen.

## Fehlerbehebung

Problem | Prüfung
--- | ---
Konfiguration unvollständig | Instanz im Kalender Konfigurator löschen und aus der aktuellen Kontoliste neu erstellen; die technischen Identitätsfelder nicht manuell setzen
Synchronisation fehlgeschlagen | Zuerst im verbundenen Kalender Konto **Verbindung testen**, anschließend Konto und Kalender erneut synchronisieren
Keine Termine sichtbar | Zeitraum für vergangene und zukünftige Termine prüfen und kontrollieren, ob der Online-Kalender im gewählten Zeitraum Termine enthält
Kalender ist schreibgeschützt | Schreibrechte beim Anbieter prüfen; ICS/Webcal-Abonnements sind immer schreibgeschützt
Ändern oder Löschen wird bei einem Serientermin verweigert | Einzelne Vorkommen wiederkehrender Termine sind bewusst noch nicht zum Schreiben freigegeben
Schreibkonflikt | Kalender erneut synchronisieren; der ETag-Schutz verhindert das Überschreiben einer zwischenzeitlich geänderten Serverversion
