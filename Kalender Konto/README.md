# Kalender Konto

Das Modul **Kalender Konto** verwaltet die Verbindung zu einem Kalenderanbieter.
Es findet die verfügbaren Kalender und stellt sie der **Kalender Einrichtung**,
dem **Kalender Konfigurator** und den erzeugten Kalender-Instanzen zur Verfügung.

Unterstützt werden Apple iCloud, Google Calendar, Microsoft 365/Outlook.com,
generische CalDAV-Server sowie mehrere schreibgeschützte ICS-/Webcal-Feeds.

## Voraussetzungen

- Symcon ab Version 9.1
- Zugriff des Symcon-Servers auf den jeweiligen Kalenderdienst
- für Google und Microsoft eine aktive Symcon-Connect-Verbindung; eine eigene
  OAuth-Client-ID oder ein eigener Clientschlüssel ist nicht erforderlich
- für Apple iCloud ein anwendungsspezifisches Apple-Passwort

## Schnellstart

Für eine vollständige neue OpenCalendar-Konfiguration ist die **Kalender
Einrichtung** der empfohlene Einstieg. Der folgende Ablauf beschreibt die
direkte manuelle Einrichtung eines Kalender Kontos.

1. Über **Instanz hinzufügen** eine Instanz **Kalender Konto** erstellen.
2. Das Konto sinnvoll benennen, besonders wenn mehrere Konten verwendet werden.
3. Den Anbieter auswählen und die im passenden Abschnitt beschriebenen Angaben
   eintragen beziehungsweise den OAuth-Login ausführen.
4. **Verbindung testen** verwenden. Der Test muss erfolgreich sein und die
   erwartete Anzahl gefundener Kalender melden.
5. Die Konfiguration übernehmen und **Jetzt synchronisieren** ausführen.
6. Anschließend entweder die **Kalender Einrichtung** starten und dieses Konto
   als vorhandenes Konto auswählen oder einen **Kalender Konfigurator** öffnen
   beziehungsweise erstellen und dort die gewünschten Kalender-Instanzen anlegen.

Mit **Kontostatus anzeigen** lassen sich der Verbindungszustand, die letzte
Synchronisation und gegebenenfalls bereinigte Fehlermeldungen kontrollieren.

## Anbieter einrichten

### Apple iCloud

Für iCloud wird CalDAV über `https://caldav.icloud.com` verwendet. Die
Server-Adresse wird automatisch gesetzt und kann in diesem Modus nicht geändert
werden.

1. Im Apple-Account ein anwendungsspezifisches Passwort erzeugen. Eine Anleitung
   bietet [Apple Support](https://support.apple.com/de-de/102654).
2. Als Anbieter **Apple iCloud** wählen.
3. Unter **Benutzername** die Apple-ID beziehungsweise deren E-Mail-Adresse
   eintragen.
4. Unter **Passwort** das anwendungsspezifische Passwort eintragen, nicht das
   normale Apple-Account-Passwort.
5. **Verbindung testen**, die Konfiguration übernehmen und synchronisieren.

Beschreibbare iCloud-Kalender können neue tägliche, wöchentliche, monatliche und
jährliche Terminserien mit Intervall, Wochentagen sowie den Endarten Nie, Anzahl
oder Enddatum anlegen. Die Serie wird als RFC-5545-`RRULE` über CalDAV gespeichert.
Zeitgebundene Serien enthalten einen passenden `VTIMEZONE`-Block. Einzelne
Vorkommnisse bestehender Serien können außerdem bearbeitet oder gelöscht werden.
Änderungen werden als `RECURRENCE-ID`-Ausnahme gespeichert; Löschungen ergänzen
ein `EXDATE`, ohne das übrige Serienobjekt zu entfernen. Die vollständige Serie
kann ebenfalls bearbeitet oder gelöscht werden. Für unterstützte RRULEs kann die
Serie außerdem **ab einem ausgewählten Vorkommnis für alle folgenden Termine**
bearbeitet oder gekürzt werden. Beim Bearbeiten wird zuerst ein neuer zukünftiger
Serienteil angelegt und danach die ursprüngliche Ressource unmittelbar vor dem
Trennpunkt beendet. Einfache RRULEs lassen sich dabei im gemeinsamen Serieneditor
ändern; komplexere Regeln werden unverändert erhalten und nicht automatisch geteilt.

### Generisches CalDAV

1. Als Anbieter **CalDAV** wählen.
2. Unter **Server-URL** die vom Anbieter angegebene vollständige HTTP(S)-Adresse
   eintragen. Das Modul ermittelt daraus Principal, Calendar Home Set und die
   verfügbaren Kalender.
3. Benutzername und Passwort des CalDAV-Kontos eintragen.
4. Die TLS-Prüfung nur bei einem bewusst verwendeten, selbst signierten Server
   deaktivieren. Für öffentlich erreichbare Server sollte sie aktiviert bleiben.
5. **Verbindung testen**, die Konfiguration übernehmen und synchronisieren.

Falls die automatische Ermittlung mit einer allgemeinen Serveradresse nicht
funktioniert, sollte die vom Anbieter ausdrücklich genannte CalDAV-Basisadresse
verwendet werden.

Beschreibbare CalDAV-Kalender unterstützen dieselbe Neuanlage und Bearbeitung von
Terminserien wie iCloud. Zusätzlich können einzelne Vorkommnisse, vollständige
Serien sowie bei unterstützten RRULEs **dieses und alle folgenden Vorkommnisse**
bearbeitet oder gelöscht werden. Beim Teilen wird die vorhandene Ressource mit
ETag geschützt; der neue zukünftige Serienteil erhält eine eigene UID und Ressource.

### Google Calendar

Google wird über die öffentlich freigegebene und von Google verifizierte
OpenCalendar-OAuth-Anwendung sowie den zentralen OAuth-Dienst von Symcon
verbunden. Anwender benötigen weder ein eigenes Google-Cloud-Projekt noch eine
eigene Client-ID oder einen Clientschlüssel. Es besteht keine Beschränkung auf
vorab eingetragene Google-Testnutzer.

1. Eine aktive Symcon-Connect-Verbindung sicherstellen.
2. Als Anbieter **Google Calendar** wählen und die Konfiguration übernehmen.
3. **Google-Konto verbinden** aufrufen.
4. Bei Google anmelden und den Zugriff auf Kalenderliste und Termine bestätigen.
5. Zur Instanz zurückkehren und **Jetzt synchronisieren** ausführen.

OpenCalendar fordert nur das Auflisten der abonnierten Kalender und den Zugriff
auf deren Termine an. Kalender mit den Google-Rollen `owner` und `writer` werden
als beschreibbar erkannt; `reader` wird schreibgeschützt angeboten.
`freeBusyReader`-Einträge werden nicht angelegt, weil sie keine Termindetails
liefern.

**Google-Konto trennen** widerruft den Token nach Möglichkeit bei Google und
entfernt die lokal gespeicherten OAuth-Daten. Verbindungen aus älteren
OpenCalendar-Versionen mit einer persönlichen Google-Client-ID müssen einmal neu
über **Google-Konto verbinden** autorisiert werden.

### Microsoft 365 und Outlook.com

Microsoft wird ebenfalls über den zentralen OAuth-Dienst von Symcon verbunden.
Unterstützt werden Microsoft-365-Geschäfts- und Schulkonten sowie persönliche
Konten wie Outlook.com.

1. Eine aktive Symcon-Connect-Verbindung sicherstellen.
2. Als Anbieter **Microsoft 365** wählen und die Konfiguration übernehmen.
3. **Microsoft-Konto verbinden** aufrufen.
4. Bei Microsoft anmelden und den Kalenderzugriff bestätigen.
5. Zur Instanz zurückkehren und **Jetzt synchronisieren** ausführen.

OpenCalendar verwendet delegierten Kalenderzugriff. Mail, Kontakte, OneDrive,
Teams und andere Microsoft-Graph-Bereiche werden nicht angefordert. Microsofts
`canEdit`-Angabe bestimmt, ob ein Kalender beschreibbar oder schreibgeschützt
angelegt wird. In beschreibbaren Microsoft-Kalendern können außerdem neue tägliche,
wöchentliche, monatliche und jährliche Terminserien mit Intervall sowie den
Endarten Nie, Anzahl oder Enddatum angelegt werden. Einzelne Vorkommnisse,
**dieses und alle folgenden Vorkommnisse** sowie die vollständige Serie können
außerdem bearbeitet und gelöscht werden. Für „dieses und folgende“ wird die
bestehende Serie vor dem ausgewählten Vorkommnis beendet und beim Bearbeiten ab
dort als neuer Serienteil fortgeführt. Nummerierte Serien übernehmen dabei die
verbleibende Anzahl.

Bei bestehenden Microsoft-Onlinebesprechungen wird die Beschreibung in der
Kalenderansicht nicht zur Bearbeitung angeboten. Microsoft speichert darin
Besprechungsinformationen, die durch ein unvollständiges Überschreiben verloren
gehen könnten. Titel, Ort und Zeit bleiben bearbeitbar.

**Microsoft-Konto trennen** entfernt die lokal gespeicherten Microsoft-
Zugangsdaten.

### ICS/Webcal

ICS-/Webcal-Quellen sind schreibgeschützt. Ein Konto kann mehrere voneinander
unabhängige Online-Abonnements und lokal hochgeladene ICS-Dateien verwalten.

Minimaler Ablauf:

1. Als Anbieter **ICS/Webcal** wählen.
2. In der Liste **iCalendar-Abonnements** eine Zeile hinzufügen.
3. Den Eintrag aktivieren, einen verständlichen Kalendernamen und die vollständige
   HTTP(S)- oder `webcal://`-URL eintragen.
4. Unter **Authentifizierung** die passende Variante wählen:
   - **URL / Zugriffsschlüssel** für öffentliche oder schlüsselbasierte Feeds. Der
     Schlüssel bleibt Bestandteil der Feed-URL, z. B. `?accesskey=...`; OpenCalendar
     sendet dann ausdrücklich keine HTTP-Zugangsdaten.
   - **Benutzername / Passwort** nur für Feeds mit HTTP-Authentifizierung.
   - **Automatisch** dient der Abwärtskompatibilität und verwendet HTTP-
     Authentifizierung nur, wenn Benutzername und Passwort gemeinsam vorhanden sind.
5. Einen passenden Aktualisierungsplan wählen und optional eine Farbe im Format
   `#RRGGBB` eintragen.
6. **Verbindung testen**, die Konfiguration übernehmen und synchronisieren.
7. Jeden gefundenen Feed anschließend über die **Kalender Einrichtung** oder den
   **Kalender Konfigurator** als eigene Kalender-Instanz erstellen.

`webcal://` wird automatisch über HTTPS abgerufen. Bleibt die Farbe leer,
verwendet OpenCalendar – sofern vorhanden – die Farbe aus dem Feed. Der
eingetragene Kalendername überschreibt `X-WR-CALNAME` aus dem Feed.

Schlüsselbasierte Dienste wie DIVERA247 liefern den Zugriffsschlüssel als Teil der
URL. Für solche Feeds **URL / Zugriffsschlüssel** wählen. Dadurch werden eventuell
noch gespeicherte Benutzername-/Passwortwerte nicht als HTTP-Authentifizierung an
den Feed gesendet.

Die bisherigen Einzelfelder **iCalendar-URL**, **Kalendername**,
**Benutzername**, **Passwort** und **Titelübersetzung** bleiben für ältere
Konfigurationen erhalten. Der dort konfigurierte Feed wird zusätzlich zu den
Listeneinträgen angeboten. Identische URLs werden nicht doppelt angelegt.

Eine Feed-URL kann selbst ein Zugangsgeheimnis enthalten, beispielsweise Googles
„Privatadresse im iCal-Format“. Sie sollte deshalb wie ein Passwort behandelt
werden. Geheime Feed-Adressen verbleiben in der Konto-Instanz und werden nicht in
die Konfiguration einer erzeugten Kalender-Instanz übernommen.

#### Lokale ICS-Dateien

Im aufklappbaren Bereich **Lokale ICS-Dateien** können `.ics`-Dateien direkt vom
Rechner ausgewählt werden, auf dem die Symcon-Konsole geöffnet ist. Symcon
überträgt den Dateiinhalt dabei Base64-codiert in die Instanzkonfiguration; ein
Dateipfad auf dem Symcon-Server ist nicht erforderlich.

Für jede lokale Datei werden **Aktiv**, ein eindeutiger **Kalendername**, die
**ICS-Datei**, optional eine **Titelübersetzung** und eine **Kalenderfarbe**
konfiguriert. Der Kalendername dient zugleich als stabile technische Zuordnung:
Wird eine aktualisierte Datei unter demselben Kalendernamen erneut ausgewählt,
bleibt die bereits erzeugte Kalender-Instanz zugeordnet.

Lokale ICS-Dateien werden nur beim Auswählen beziehungsweise Ersetzen in die
OpenCalendar-Konfiguration übernommen. Änderungen an der Originaldatei auf dem
Arbeitsrechner werden nicht automatisch erkannt. Zum Aktualisieren muss die Datei
erneut ausgewählt und die Konfiguration übernommen werden. Die Dateien bleiben
schreibgeschützt und werden mit derselben Terminserienlogik wie Online-Feeds
ausgewertet. Dateien über 16 MiB oder Inhalte ohne gültigen `VCALENDAR`-Rahmen
werden abgewiesen.

#### Titelübersetzung

Das optionale Profil **Öffentliche Google-Kalender – Deutsch** übersetzt bekannte
englische Termintitel der öffentlichen Google-Kalender für Mondphasen und
Kalendertage. Andere Titel bleiben unverändert. Bei übersetzten Terminen enthält
`originalSummary` weiterhin den ursprünglichen Titel. Der heruntergeladene Feed
und sein Cache werden nicht verändert.

#### Terminserien

Terminserien werden lokal für den von der Kalenderinstanz angeforderten Zeitraum
aufgelöst. Unterstützt werden tägliche, wöchentliche, monatliche und jährliche
`RRULE`-Serien einschließlich `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY`, `BYMONTH`,
`BYMONTHDAY`, `BYSETPOS` und `WKST`. `RDATE` ergänzt einzelne Vorkommen,
`EXDATE` entfernt sie und über `RECURRENCE-ID` gelieferte Änderungen oder Absagen
ersetzen das zugehörige Serienvorkommen.

#### Robuste Feed-Aktualisierung

Jedes aktive Abonnement besitzt einen eigenen persistenten Feed-Cache:

- `ETag` und `Last-Modified` werden für bedingte HTTP-Abfragen verwendet.
- Bei `304 Not Modified` wird die bereits geprüfte lokale Version genutzt.
- Leere Antworten, HTML-Fehlerseiten und ungültige iCalendar-Antworten ersetzen
  niemals den letzten gültigen Feed.
- Bei vorübergehenden Netzwerkproblemen, HTTP `408`, `425`, `429` oder
  Serverfehlern ab `500` bleiben die letzten gültigen Daten verfügbar und werden
  als veraltet markiert.
- Authentifizierungsfehler und dauerhafte Clientfehler wie `404` werden nicht
  durch Cache-Daten verborgen.
- Große Terminmengen werden nach dem Parsen automatisch in begrenzten Seiten an
  die Kalender-Instanz übertragen. Auch umfangreiche ICS-Dateien müssen deshalb
  nicht manuell geteilt werden.

**Verbindung testen** prüft immer den aktuellen Serverzustand und meldet einen
Fehler auch dann, wenn noch eine ältere Feed-Version zur Anzeige verfügbar ist.
**Kontostatus anzeigen** enthält je Abonnement `lastCheck`, `lastDownload`,
`lastChange`, `stale` und `lastError`, aber keine Feed-Adresse oder Feed-Inhalte.
**Cache leeren** entfernt die gefundenen Kalender und gespeicherten Feed-
Versionen. Anschließend muss erneut synchronisiert werden.

## Einstellungen

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Kontosynchronisation
Anbieter | Apple iCloud, Google Calendar, Microsoft 365, CalDAV oder ICS/Webcal
Server-URL | CalDAV-Basisadresse beziehungsweise bei älteren ICS-Konfigurationen eine einzelne Feed-URL
Authentifizierung | Bei ICS Auswahl zwischen URL/Zugriffsschlüssel, Benutzername/Passwort und automatischem Kompatibilitätsmodus
Benutzername | Kontoname oder E-Mail-Adresse; bei ICS nur im Modus Benutzername/Passwort erforderlich
Passwort | Konto- oder anwendungsspezifisches Passwort; bei ICS nur im Modus Benutzername/Passwort erforderlich
iCalendar-Abonnements | Liste der aktiven Online-Feeds, Namen, URLs, Authentifizierungsarten, Zugangsdaten, Übersetzungsprofile, Zeitpläne und Farben
Lokale ICS-Dateien | Vom Arbeitsrechner hochgeladene `.ics`-Dateien mit Kalendername, Übersetzungsprofil und Farbe
Aktualisierungsplan | Vorgegebener Rhythmus von fünf Minuten bis jährlich oder ausschließlich manuelle Synchronisation
Benutzerdefiniertes Intervall | Eigener Abstand in Minuten für den entsprechenden Zeitplantyp
TLS-Zertifikat prüfen | Nur bei eigenen CalDAV- und ICS-Endpunkten änderbar; für Apple, Google, Microsoft und Symcon OAuth immer aktiv
Zeitlimit der Anfrage | Maximale Dauer einer HTTP-Anfrage

Bei ICS/Webcal steuert der Zeitplan des Kontos die Ermittlung und Pflege der
Feed-Metadaten. Der Zeitplan der erzeugten Kalender-Instanz bestimmt, wann deren
Termine für den eingestellten Zeitraum angefordert werden. Beide Abläufe können
eine Feed-Abfrage auslösen; unnötig kurze Intervalle sollten daher vermieden
werden.

## Mehrere Konten und unterstützte Einrichtungswege

Für weitere Kalenderkonten kann die **Kalender Einrichtung** nach einem
erfolgreichen Abschluss direkt erneut gestartet werden. Für die spätere Pflege
oder Erweiterung eines Kontos kann zusätzlich ein Kalender Konfigurator verwendet
werden. Ein eigener Konfigurator je Konto ist die übersichtlichste Variante, aber
keine technische Pflicht. Ein vorhandener Konfigurator darf über **Gateway ändern**
mit einem anderen Kalender Konto verbunden werden. Danach muss
**Kalender aktualisieren** ausgeführt werden. Der Konfigurator zeigt und verwaltet
nur Kalender des aktuell verbundenen Kontos.

Kalender-Instanzen selbst dürfen nicht manuell angelegt, kopiert oder lediglich
über **Gateway ändern** einem Konto zugeordnet werden. Sie müssen über die
**Kalender Einrichtung** oder aus der Liste des korrekt verbundenen
**Kalender Konfigurators** erstellt werden.

## Datenschutz und OAuth

Zugangsdaten, OAuth-Tokens sowie Kalender- und Termindaten werden grundsätzlich
lokal in Symcon gespeichert oder zwischengespeichert. Google und Microsoft
verwenden für Anmeldung und Token-Aktualisierung den zentralen
Symcon-OAuth-Dienst. Die Google-OAuth-Anwendung ist öffentlich freigegeben und
von Google verifiziert; anwenderseitige OAuth-Zugangsdaten sind nicht
erforderlich. Kalender- und Termindaten werden direkt zwischen der
Symcon-Installation und dem jeweiligen Anbieter übertragen.

Ausführliche Angaben enthält die [Datenschutzerklärung](../PRIVACY.md). Die
einmalige zentrale OAuth-Einrichtung für Herausgeber ist getrennt unter
[OAuth-Infrastruktur für Modulautoren](../docs/OAUTH-PUBLISHER.md) dokumentiert.

Passwörter, OAuth-Tokens und geheime Feed-Adressen werden weder in öffentliche
Rückgabewerte noch unbereinigt in Debugmeldungen geschrieben.

## Datenfluss

Unterstützte Anforderungen von Child-Modulen:

- `GetCalendars`
- `DiscoverCalendars`
- `GetEvents`
- `BeginEventsTransfer`
- `ReadEventsTransferPage`
- `FinishEventsTransfer`
- `CreateEvent`
- `UpdateEvent`
- `DeleteEvent`
- `Synchronize`
- `TestConnection`

Nach einer erfolgreichen Synchronisation sendet das Konto `CalendarsUpdated` an
seine Children.

`GetEvents` bleibt für kompatible kleine Abfragen erhalten. Die Kalender-Instanz
verwendet für reguläre Synchronisationen den dreistufigen Seitentransfer, damit
keine einzelne Datenflussantwort das Symcon-Ausgabelimit erreicht.

## PHP-Befehlsreferenz

```php
string IPSKALACC_TestConnection(int $InstanzID);
bool IPSKALACC_Synchronize(int $InstanzID);
string IPSKALACC_GetCalendars(int $InstanzID);
string IPSKALACC_GetAccountStatus(int $InstanzID);
void IPSKALACC_ClearCache(int $InstanzID);
string IPSKALACC_ConnectGoogle(int $InstanzID);
bool IPSKALACC_DisconnectGoogle(int $InstanzID);
string IPSKALACC_ConnectMicrosoft(int $InstanzID);
bool IPSKALACC_DisconnectMicrosoft(int $InstanzID);
```

Methoden mit komplexen Rückgabewerten liefern JSON.
