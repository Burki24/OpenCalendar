# Datenschutzhinweise / Privacy Notice

**Stand / Last updated: 12.08.2026**

OpenCalendar ist eine Open-Source-Bibliothek für Symcon. Die Kalenderverarbeitung findet grundsätzlich auf der Symcon-Installation des Anwenders statt. Der Modulautor betreibt keinen eigenen Kalender-Backenddienst und erhält über OpenCalendar keine Kalenderinhalte.

Kontakt zum Projekt: [GitHub-Repository OpenCalendar](https://github.com/Burki24/OpenCalendar) und dessen Issue-Bereich.

## 1. Welche Daten verarbeitet OpenCalendar?

Je nach gewähltem Anbieter verarbeitet OpenCalendar insbesondere:

- Zugangsdaten oder OAuth-Zugangstoken für das verbundene Kalenderkonto,
- Kalenderkennungen, Kalendernamen, Farben und Berechtigungen,
- Termindaten wie Titel, Beschreibung, Ort, Beginn, Ende, Ganztagsstatus, Wiederholungsinformationen und technische Termin-IDs,
- Synchronisationszeitpunkte, ETags und technische Fehlerzustände.

Kalender- und Termindaten werden lokal in Symcon zwischengespeichert, soweit dies für Synchronisation, Darstellung und Bearbeitung erforderlich ist. Dadurch können diese Daten auch Bestandteil eines vom Anwender erstellten Symcon-Backups werden.

OpenCalendar enthält keine eigene Telemetrie, Werbung oder Nutzeranalyse. Kalenderdaten werden nicht verkauft und nicht für Werbung, Profilbildung, Bonitätsbewertung oder das Training von KI-Modellen verwendet.

## 2. Google Calendar

### 2.1 Zugriff auf Google-Nutzerdaten

Die Google-Anbindung verwendet einen persönlichen OAuth-Webclient des Anwenders. Client-ID und Clientschlüssel werden in der jeweiligen Symcon-Installation konfiguriert.

OpenCalendar fordert ausschließlich folgende Google-Berechtigungen an:

- `https://www.googleapis.com/auth/calendar.calendarlist.readonly` zum Lesen der Kalenderliste,
- `https://www.googleapis.com/auth/calendar.events` zum Lesen, Erstellen, Ändern und Löschen von Kalenderterminen.

Über diese Berechtigungen greift OpenCalendar ausschließlich auf die für die Kalenderfunktion erforderlichen Google-Nutzerdaten zu. Dazu gehören insbesondere:

- Kalenderkennungen und Kalendernamen,
- Kalenderfarben und Zugriffsberechtigungen,
- technische Kalender- und Termin-IDs,
- Titel von Terminen,
- Beschreibungen,
- Orte,
- Beginn und Ende,
- Ganztagsinformationen,
- Wiederholungsinformationen,
- technische Synchronisationsinformationen wie ETags,
- OAuth-Access- und Refresh-Tokens, die zur autorisierten Kommunikation mit Google erforderlich sind.

OpenCalendar fordert keinen allgemeinen Zugriff auf das Google-Konto, Gmail, Google Drive, Kontakte oder andere nicht für die Kalenderfunktion erforderliche Google-Dienste an.

### 2.2 Verwendung von Google-Nutzerdaten

Google-Nutzerdaten werden ausschließlich verwendet, um die vom Anwender in OpenCalendar eingerichteten Kalenderfunktionen bereitzustellen.

Hierzu gehören:

- Auflisten der verfügbaren Google-Kalender,
- Synchronisieren von Kalendern und Terminen,
- Anzeigen von Terminen in OpenCalendar, Symcon und den zugehörigen Visualisierungen,
- Erstellen neuer Termine auf ausdrückliche Veranlassung des Anwenders,
- Ändern bestehender Termine auf ausdrückliche Veranlassung des Anwenders,
- Löschen bestehender Termine auf ausdrückliche Veranlassung des Anwenders,
- lokale Zwischenspeicherung der für Darstellung und Synchronisation erforderlichen Kalenderinformationen,
- technische Verwaltung des OAuth-Zugriffs und der Synchronisation.

Google-Nutzerdaten werden nicht für Zwecke verwendet, die mit diesen sichtbaren Kalenderfunktionen nicht zusammenhängen.

Insbesondere werden Google-Nutzerdaten nicht für Werbung, Profilbildung, Tracking, Nutzeranalyse, Datenhandel, Bonitätsbewertung oder zum Training von KI-Modellen verwendet.

### 2.3 Weitergabe, Übertragung und Offenlegung von Google-Nutzerdaten

OpenCalendar übermittelt Google-Kalender- und Termindaten nicht an einen Backenddienst des Modulautors.

Der eigentliche Austausch von Kalender- und Termindaten erfolgt direkt zwischen der Symcon-Installation des Anwenders und den Google-Calendar-APIs.

Für die OAuth-Anmeldung wird die persönliche Symcon-Connect-Verbindung des Anwenders als technischer Rückkanal für den OAuth-Callback verwendet. Symcon Connect wird von der Symcon GmbH betrieben. Über diesen Dienst können für den OAuth-Ablauf erforderliche technische Verbindungsdaten verarbeitet werden. Die eigentlichen Kalender- und Termininhalte werden von OpenCalendar nicht über einen Server des Modulautors geleitet.

Der Modulautor erhält keinen automatischen Zugriff auf:

- Google-Kalender,
- Google-Termine,
- OAuth-Tokens,
- lokale Kalender-Caches,
- sonstige Google-Nutzerdaten des Anwenders.

Google-Nutzerdaten werden von OpenCalendar nicht verkauft, vermietet oder an Werbenetzwerke, Datenhändler, Analyseanbieter oder andere kommerzielle Dritte weitergegeben.

Eine Einsichtnahme durch den Modulautor oder andere Personen erfolgt nicht über OpenCalendar. Eine Ausnahme besteht nur dann, wenn ein Anwender selbst im Rahmen einer Supportanfrage freiwillig Logs, Screenshots oder andere Daten zur Verfügung stellt.

Eine darüber hinausgehende Offenlegung erfolgt nur, soweit sie gesetzlich zwingend erforderlich ist.

### 2.4 Speicherung von Google-Nutzerdaten

Das Google-Refresh-Token wird als internes, persistentes Symcon-Attribut in der Symcon-Installation des Anwenders gespeichert.

Kurzlebige Google-Access-Tokens werden nur im Instanzpuffer gehalten.

Kalenderlisten und Termindaten können lokal in Symcon zwischengespeichert werden, soweit dies für Synchronisation, Anzeige und Bearbeitung erforderlich ist.

Diese lokal gespeicherten Daten können auch Bestandteil eines vom Anwender erstellten Symcon-Backups werden.

OpenCalendar betreibt keine zentrale Datenbank, in der Google-Kalenderdaten oder Google-OAuth-Tokens der Anwender gesammelt werden.

### 2.5 Schutz von Google-Nutzerdaten

OpenCalendar verwendet mehrere technische und organisatorische Maßnahmen zum Schutz von Google-Nutzerdaten:

- Die Kommunikation mit den Google-OAuth- und Google-Calendar-Diensten erfolgt über verschlüsselte HTTPS/TLS-Verbindungen.
- Es werden nur die für die Kalenderfunktionen erforderlichen Google-Berechtigungen angefordert.
- Kurzlebige Access-Tokens werden nicht dauerhaft als Konfiguration gespeichert, sondern nur im Instanzpuffer gehalten.
- Refresh-Tokens werden als interne Symcon-Attribute gespeichert und nicht über die Kalender-Visualisierung ausgegeben.
- Passwörter und OAuth-Tokens werden von OpenCalendar nicht absichtlich in Debug-Ausgaben oder Kalenderdarstellungen geschrieben.
- OpenCalendar betreibt keinen zentralen Kalender-Backenddienst, wodurch keine zusätzliche zentrale Speicherung von Google-Kalenderdaten beim Modulautor erfolgt.
- Beim Trennen eines Google-Kontos versucht OpenCalendar, das gespeicherte Refresh-Token bei Google zu widerrufen und entfernt anschließend die lokalen OAuth-Daten und Kalender-Caches.

Der Schutz dauerhaft lokal gespeicherter Daten hängt zusätzlich von der Sicherheit der Symcon-Installation, des zugrunde liegenden Betriebssystems und der vom Anwender verwendeten Backup-Speicher ab.

Der Betreiber der Symcon-Installation ist dafür verantwortlich, den Zugriff auf das Symcon-System, das Dateisystem und vorhandene Backups angemessen zu schützen. Backups mit sensiblen Kalender- oder OAuth-Daten sollten nur an geschützten Speicherorten abgelegt und, soweit verfügbar, verschlüsselt gespeichert werden.

### 2.6 Google API Services User Data Policy

Die Verwendung und Übertragung von Informationen, die OpenCalendar über Google APIs erhält, erfolgt entsprechend der **Google API Services User Data Policy**, einschließlich der dort festgelegten **Limited Use Requirements**.

OpenCalendar verwendet Google-Nutzerdaten ausschließlich zur Bereitstellung oder Verbesserung der vom Anwender verwendeten und sichtbaren Kalenderfunktionen.

## 3. Microsoft 365 / Outlook.com

Die Microsoft-Anbindung verwendet eine zentral registrierte Microsoft-Entra-Anwendung und den OAuth-Dienst von Symcon. Anwender müssen keine eigene Client-ID und keinen eigenen Clientschlüssel hinterlegen.

OpenCalendar fordert ausschließlich delegierten Kalenderzugriff an:

- `Calendars.ReadWrite` zum Lesen, Erstellen, Ändern und Löschen von Kalenderterminen,
- `offline_access`, damit die Verbindung über ein Refresh-Token dauerhaft genutzt werden kann.

Für Anmeldung und Token-Aktualisierung werden OAuth-Daten über den Symcon-OAuth-Dienst unter `https://oauth.ipmagic.de` verarbeitet. Dazu gehören insbesondere Autorisierungscodes und Refresh-Tokens, die für den Austausch mit Microsoft erforderlich sind.

Die eigentlichen Kalender- und Termindaten werden von der Symcon-Installation direkt über `https://graph.microsoft.com` mit Microsoft Graph ausgetauscht und nicht über einen Kalender-Backenddienst des Modulautors geleitet.

Das Microsoft-Refresh-Token wird als internes, persistentes Symcon-Attribut gespeichert; kurzlebige Access-Tokens werden nur im Instanzpuffer gehalten. Zusätzlich können Konto-, Kalender- und Termininformationen lokal in Symcon zwischengespeichert werden.

Beim Trennen eines Microsoft-Kontos entfernt OpenCalendar die lokal gespeicherten Microsoft-OAuth-Daten und Kalender-Caches. Eine bereits bei Microsoft erteilte Zustimmung kann zusätzlich vom Anwender in den Sicherheitseinstellungen seines Microsoft-Kontos beziehungsweise durch den Administrator des jeweiligen Microsoft-365-Mandanten widerrufen werden.

## 4. Apple iCloud, CalDAV und ICS/Webcal

Bei Apple iCloud und anderen CalDAV-Servern werden die vom Anwender eingetragenen Zugangsdaten ausschließlich für den Zugriff auf den konfigurierten Server verwendet.

Bei iCalendar-Abonnements können Feed-URLs selbst geheime Zugriffsinformationen enthalten und sollten wie Passwörter behandelt werden.

OpenCalendar speichert konfigurierte Zugangsdaten und URLs lokal in der Symcon-Installation.

iCalendar-Feeds können einschließlich ihrer Termindaten persistent zwischengespeichert werden, um ETag-/Last-Modified-Prüfungen und eine Rückfallebene bei vorübergehenden Serverfehlern zu ermöglichen.

## 5. Symcon Connect und Symcon OAuth

Google OAuth benötigt für den Callback eine aktive Symcon-Connect-Verbindung.

Microsoft OAuth verwendet zusätzlich den zentralen Symcon-OAuth-Dienst.

Diese Dienste werden von der Symcon GmbH betrieben und unterliegen deren eigenen Datenschutzbestimmungen.

OpenCalendar hat keinen Zugriff auf serverseitige Protokolle oder andere Daten, die Symcon im Rahmen dieser Dienste verarbeitet.

## 6. Speicherung, Backups und Löschung

Persistente Eigenschaften und Attribute von OpenCalendar werden in der Symcon-Installation des Anwenders gespeichert.

Dazu können insbesondere gehören:

- Zugangsdaten,
- OAuth-Refresh-Tokens,
- Kalenderlisten,
- Kalender-Metadaten,
- Termincaches,
- Synchronisationsinformationen.

Der Anwender kann:

- Kalender-Caches über die Funktion **Cache leeren** entfernen,
- OAuth-Verbindungen über **Google-Konto trennen** beziehungsweise **Microsoft-Konto trennen** lokal entfernen,
- durch Löschen der betreffenden Symcon-Instanzen die zugehörigen lokalen Modulwerte entfernen.

Beim Trennen eines Google-Kontos versucht OpenCalendar zusätzlich, das vorhandene Refresh-Token bei Google zu widerrufen.

Bereits erstellte Symcon-Backups werden durch das Löschen oder Trennen einer OpenCalendar-Instanz nicht nachträglich verändert.

Für Aufbewahrung, Zugriffsschutz und Löschung solcher Backups ist der Betreiber der Symcon-Installation verantwortlich.

## 7. Protokollierung und Support

OpenCalendar schreibt Zugangspasswörter und OAuth-Tokens nicht absichtlich in Debug-Ausgaben.

Bei Supportanfragen entscheidet der Anwender selbst, welche Logs, Screenshots oder Konfigurationsdaten er an Dritte weitergibt.

Vor einer Weitergabe sollten insbesondere folgende Informationen entfernt oder unkenntlich gemacht werden:

- Zugangsdaten,
- OAuth-Tokens,
- private Feed-URLs,
- OAuth-Codes,
- persönliche Kalendernamen,
- private Termininhalte.

Daten, die ein Anwender freiwillig im Rahmen einer Supportanfrage bereitstellt, werden nicht automatisch durch OpenCalendar übertragen.

## 8. Drittanbieter

Für die Verarbeitung durch externe Dienste gelten zusätzlich deren jeweilige Datenschutzbestimmungen und Nutzungsbedingungen.

Dies betrifft insbesondere:

- Google,
- Microsoft,
- Apple,
- Symcon,
- gegebenenfalls den vom Anwender selbst konfigurierten CalDAV- oder iCalendar-Anbieter.

## 9. Änderungen

Diese Datenschutzhinweise werden angepasst, wenn sich die Datenverarbeitung, die verwendeten OAuth-Berechtigungen oder die Sicherheits- und Übertragungswege wesentlich ändern.

---

# Privacy Notice (English)

**Last updated: 12 August 2026**

OpenCalendar is an open-source library for Symcon. Calendar processing generally takes place on the user's own Symcon installation. The module author does not operate a calendar backend service and does not receive calendar content through OpenCalendar.

Project contact: [OpenCalendar GitHub repository](https://github.com/Burki24/OpenCalendar) and its issue tracker.

## 1. Data processed by OpenCalendar

Depending on the selected provider, OpenCalendar processes in particular:

- credentials or OAuth access tokens for the connected calendar account,
- calendar identifiers, calendar names, colors and permissions,
- event data such as title, description, location, start, end, all-day status, recurrence information and technical event IDs,
- synchronization timestamps, ETags and technical error states.

Calendar and event data is cached locally in Symcon where required for synchronization, display and editing. This data may therefore also be included in Symcon backups created by the user.

OpenCalendar contains no proprietary telemetry, advertising or user analytics. Calendar data is not sold and is not used for advertising, profiling, creditworthiness assessment or AI model training.

## 2. Google Calendar

### 2.1 Google user data accessed

Google integration uses a personal OAuth web client configured by the user. The client ID and client secret are configured in the user's Symcon installation.

OpenCalendar requests only the following Google permissions:

- `https://www.googleapis.com/auth/calendar.calendarlist.readonly` to read the user's calendar list,
- `https://www.googleapis.com/auth/calendar.events` to read, create, update and delete calendar events.

Through these permissions, OpenCalendar accesses only Google user data required to provide its calendar functionality.

This includes in particular:

- calendar identifiers and calendar names,
- calendar colors and access permissions,
- technical calendar and event IDs,
- event titles,
- descriptions,
- locations,
- start and end values,
- all-day information,
- recurrence information,
- technical synchronization information such as ETags,
- OAuth access and refresh tokens required for authorized communication with Google.

OpenCalendar does not request general access to the user's Google Account, Gmail, Google Drive, contacts or other Google services that are not required for its calendar functionality.

### 2.2 How Google user data is used

Google user data is used solely to provide the calendar features configured and used by the user in OpenCalendar.

This includes:

- listing available Google calendars,
- synchronizing calendars and events,
- displaying events in OpenCalendar, Symcon and the associated visualizations,
- creating new events when explicitly initiated by the user,
- updating existing events when explicitly initiated by the user,
- deleting existing events when explicitly initiated by the user,
- locally caching calendar information required for display and synchronization,
- technically managing OAuth authorization and synchronization.

Google user data is not used for purposes unrelated to these visible calendar features.

In particular, Google user data is not used for advertising, profiling, tracking, user analytics, data brokerage, creditworthiness assessment or AI model training.

### 2.3 Sharing, transfer and disclosure of Google user data

OpenCalendar does not transmit Google calendar or event data to a backend service operated by the module author.

Actual calendar and event data is exchanged directly between the user's Symcon installation and the Google Calendar APIs.

For OAuth authorization, the user's personal Symcon Connect connection is used as the technical callback channel. Symcon Connect is operated by Symcon GmbH. Technical connection data required for the OAuth process may be processed through this service. OpenCalendar does not route actual calendar and event content through a server operated by the module author.

The module author does not automatically receive access to:

- Google calendars,
- Google events,
- OAuth tokens,
- local calendar caches,
- other Google user data belonging to the user.

Google user data is not sold, rented or transferred by OpenCalendar to advertising networks, data brokers, analytics providers or other commercial third parties.

The module author or other persons do not read Google user data through OpenCalendar. An exception exists only where a user voluntarily provides logs, screenshots or other information as part of a support request.

Additional disclosure takes place only where required by applicable law.

### 2.4 Storage of Google user data

The Google refresh token is stored as an internal persistent Symcon attribute on the user's Symcon installation.

Short-lived Google access tokens are held only in the instance buffer.

Calendar lists and event data may be cached locally in Symcon where required for synchronization, display and editing.

This locally stored data may also be included in Symcon backups created by the user.

OpenCalendar does not operate a central database that collects users' Google calendar data or Google OAuth tokens.

### 2.5 Protection of Google user data

OpenCalendar uses several technical and organizational measures to protect Google user data:

- Communication with Google OAuth and Google Calendar services takes place over encrypted HTTPS/TLS connections.
- Only Google permissions required for the calendar functionality are requested.
- Short-lived access tokens are not stored permanently as configuration values and are held only in the instance buffer.
- Refresh tokens are stored as internal Symcon attributes and are not exposed through the calendar visualization.
- OpenCalendar does not intentionally write passwords or OAuth tokens to debug output or calendar views.
- OpenCalendar does not operate a central calendar backend, avoiding an additional centralized copy of users' Google calendar data under the module author's control.
- When a Google account is disconnected, OpenCalendar attempts to revoke the stored refresh token at Google and subsequently removes local OAuth data and calendar caches.

Protection of data stored persistently on the local system also depends on the security of the user's Symcon installation, the underlying operating system and any backup storage used by the user.

The operator of the Symcon installation is responsible for appropriately protecting access to the Symcon system, filesystem and existing backups. Backups containing sensitive calendar or OAuth data should be stored only in protected locations and, where available, using encrypted storage.

### 2.6 Google API Services User Data Policy

OpenCalendar's use and transfer of information received from Google APIs adheres to the **Google API Services User Data Policy**, including its **Limited Use Requirements**.

OpenCalendar uses Google user data solely to provide or improve user-facing calendar features that are visible to and used by the user.

## 3. Microsoft 365 / Outlook.com

Microsoft integration uses a centrally registered Microsoft Entra application and the Symcon OAuth service. Users do not have to provide their own client ID or client secret.

The requested delegated permissions are limited to:

- `Calendars.ReadWrite`,
- `offline_access`.

OAuth authorization codes and refresh tokens required for sign-in and token renewal are processed through the Symcon OAuth service at `https://oauth.ipmagic.de`.

Actual calendar and event data is exchanged directly between the user's Symcon installation and Microsoft Graph at `https://graph.microsoft.com` and is not routed through a calendar backend operated by the module author.

The Microsoft refresh token is stored as an internal persistent Symcon attribute; short-lived access tokens are kept only in the instance buffer. Account, calendar and event information may additionally be cached locally in Symcon.

Disconnecting a Microsoft account removes the locally stored Microsoft OAuth data and calendar caches. Consent already granted at Microsoft can additionally be revoked by the user in their Microsoft account security settings or by the administrator of the relevant Microsoft 365 tenant.

## 4. Apple iCloud, CalDAV and ICS/Webcal

Credentials configured for Apple iCloud or another CalDAV server are used only to access the configured server.

iCalendar feed URLs may themselves contain secret access information and should be treated like passwords.

Configured credentials and URLs are stored locally in the Symcon installation.

iCalendar feeds, including event data, may be cached persistently to support ETag/Last-Modified validation and fallback to the last valid feed during temporary server failures.

## 5. Symcon Connect and Symcon OAuth

Google OAuth requires an active Symcon Connect connection for the callback.

Microsoft OAuth additionally uses the central Symcon OAuth service.

These services are operated by Symcon GmbH and are subject to Symcon's own privacy policy.

OpenCalendar has no access to server-side logs or other information processed by Symcon as part of these services.

## 6. Storage, backups and deletion

Persistent OpenCalendar properties and attributes are stored on the user's Symcon installation.

These may include:

- credentials,
- OAuth refresh tokens,
- calendar lists,
- calendar metadata,
- event caches,
- synchronization information.

Users can:

- clear calendar caches using the **Clear cache** function,
- locally remove OAuth connections using **Disconnect Google account** or **Disconnect Microsoft account**,
- remove associated local module data by deleting the relevant Symcon instances.

When disconnecting a Google account, OpenCalendar additionally attempts to revoke the existing refresh token at Google.

Existing Symcon backups are not retroactively modified when an OpenCalendar instance is disconnected or deleted.

The operator of the Symcon installation is responsible for retention, access protection and deletion of such backups.

## 7. Logging and support

OpenCalendar does not intentionally write account passwords or OAuth tokens to debug output.

Users decide which logs, screenshots or configuration information they provide to third parties for support.

Before sharing such information, users should remove or redact in particular:

- credentials,
- OAuth tokens,
- private feed URLs,
- OAuth codes,
- personal calendar names,
- private event content.

Data voluntarily supplied by a user as part of a support request is not automatically transmitted by OpenCalendar.

## 8. Third-party services

The privacy policies and terms of the respective external services also apply.

This includes in particular:

- Google,
- Microsoft,
- Apple,
- Symcon,
- any CalDAV or iCalendar provider configured by the user.

## 9. Changes

This notice will be updated if OpenCalendar materially changes how user data is processed, which OAuth permissions are requested, or which security and transmission mechanisms are used.
