# Datenschutzhinweise / Privacy Notice

**Stand / Last updated: 10.08.2026**

OpenCalendar ist eine quelloffene, unter der PolyForm Noncommercial License 1.0.0 bereitgestellte Bibliothek für Symcon. Die Kalenderverarbeitung findet grundsätzlich auf der Symcon-Installation des Anwenders statt. Der Modulautor betreibt keinen eigenen Kalender-Backenddienst und erhält über OpenCalendar keine Kalenderinhalte.

Kontakt zum Projekt: [GitHub-Repository OpenCalendar](https://github.com/Burki24/OpenCalendar) und dessen Issue-Bereich.

## 1. Welche Daten verarbeitet OpenCalendar?

Je nach gewähltem Anbieter verarbeitet OpenCalendar insbesondere:

- Zugangsdaten oder OAuth-Zugangstoken für das verbundene Kalenderkonto,
- Kalenderkennungen, Kalendernamen, Farben und Berechtigungen,
- Termindaten wie Titel, Beschreibung, Ort, Beginn, Ende, Ganztagsstatus, Wiederholungsinformationen und technische Termin-IDs,
- Synchronisationszeitpunkte, ETags und technische Fehlerzustände.

Kalender- und Termindaten werden lokal in Symcon zwischengespeichert, soweit dies für Synchronisation, Darstellung und Bearbeitung erforderlich ist. Dadurch können diese Daten auch Bestandteil eines vom Anwender erstellten Symcon-Backups werden.

OpenCalendar enthält keine eigene Telemetrie, Werbung oder Nutzeranalyse. Kalenderdaten werden nicht verkauft und nicht für Werbung, Profilbildung oder das Training von KI-Modellen verwendet.

## 2. Google Calendar

Die Google-Anbindung verwendet eine zentral registrierte Google-Anwendung und den OAuth-Dienst von Symcon. Anwender müssen keine eigene Client-ID und keinen eigenen Clientschlüssel hinterlegen.

OpenCalendar fordert ausschließlich folgende Google-Berechtigungen an:

- `https://www.googleapis.com/auth/calendar.calendarlist.readonly` zum Auflisten der Kalender,
- `https://www.googleapis.com/auth/calendar.events` zum Lesen und Verwalten von Terminen.

Für Anmeldung und Token-Aktualisierung werden OAuth-Daten über den Symcon-OAuth-Dienst unter `https://oauth.ipmagic.de` verarbeitet. Dazu gehören insbesondere Autorisierungscodes und Refresh-Tokens, die für den Austausch mit Google erforderlich sind. Die eigentlichen Kalender- und Termindaten werden von der Symcon-Installation direkt über `https://www.googleapis.com` mit Google Calendar ausgetauscht und nicht über einen Kalender-Backenddienst des Modulautors geleitet. Das Google-Refresh-Token wird als internes, persistentes Symcon-Attribut gespeichert; kurzlebige Access-Tokens werden nur im Instanzpuffer gehalten.

Die von Google erhaltenen Kalenderdaten werden ausschließlich verwendet, um die vom Anwender eingerichteten Kalender in OpenCalendar zu synchronisieren, darzustellen und – soweit vom Anwender ausgelöst oder konfiguriert – zu verändern.

Beim Trennen eines Google-Kontos versucht OpenCalendar, das gespeicherte Refresh-Token bei Google zu widerrufen, und entfernt anschließend die lokalen OAuth-Daten und Kalender-Caches.

## 3. Microsoft 365 / Outlook.com

Die Microsoft-Anbindung verwendet eine zentral registrierte Microsoft-Entra-Anwendung und den OAuth-Dienst von Symcon. Anwender müssen keine eigene Client-ID und keinen eigenen Clientschlüssel hinterlegen.

OpenCalendar fordert ausschließlich delegierten Kalenderzugriff an:

- `Calendars.ReadWrite` zum Lesen, Erstellen, Ändern und Löschen von Kalenderterminen,
- `offline_access`, damit die Verbindung über ein Refresh-Token dauerhaft genutzt werden kann.

Für Anmeldung und Token-Aktualisierung werden OAuth-Daten über den Symcon-OAuth-Dienst unter `https://oauth.ipmagic.de` verarbeitet. Dazu gehören insbesondere Autorisierungscodes und Refresh-Tokens, die für den Austausch mit Microsoft erforderlich sind. Die eigentlichen Kalender- und Termindaten werden von der Symcon-Installation direkt über `https://graph.microsoft.com` mit Microsoft Graph ausgetauscht und nicht über den OAuth-Dienst des Modulautors geleitet.

Das Microsoft-Refresh-Token wird als internes, persistentes Symcon-Attribut gespeichert; kurzlebige Access-Tokens werden nur im Instanzpuffer gehalten. Zusätzlich können Konto-, Kalender- und Termininformationen lokal in Symcon zwischengespeichert werden.

Beim Trennen eines Microsoft-Kontos entfernt OpenCalendar die lokal gespeicherten Microsoft-OAuth-Daten und Kalender-Caches. Eine bereits bei Microsoft erteilte Zustimmung kann zusätzlich vom Anwender in den Sicherheitseinstellungen seines Microsoft-Kontos beziehungsweise durch den Administrator des jeweiligen Microsoft-365-Mandanten widerrufen werden.

## 4. Apple iCloud, CalDAV und ICS/Webcal

Bei Apple iCloud und anderen CalDAV-Servern werden die vom Anwender eingetragenen Zugangsdaten ausschließlich für den Zugriff auf den konfigurierten Server verwendet. Bei iCalendar-Abonnements können Feed-URLs selbst geheime Zugriffsinformationen enthalten und sollten wie Passwörter behandelt werden.

OpenCalendar speichert konfigurierte Zugangsdaten und URLs lokal in der Symcon-Instanz. iCalendar-Feeds können einschließlich ihrer Termindaten persistent zwischengespeichert werden, um ETag-/Last-Modified-Prüfungen und eine Rückfallebene bei vorübergehenden Serverfehlern zu ermöglichen.

## 5. Symcon Connect und Symcon OAuth

Google und Microsoft OAuth benötigen eine aktive Symcon-Connect-Verbindung und verwenden den zentralen Symcon-OAuth-Dienst. Diese Dienste werden von der Symcon GmbH betrieben und unterliegen deren eigenen Datenschutzbestimmungen.

OpenCalendar hat keinen Zugriff auf serverseitige Protokolle oder andere Daten, die Symcon im Rahmen dieser Dienste verarbeitet.

## 6. Speicherung, Backups und Löschung

Persistente Eigenschaften und Attribute von OpenCalendar werden in der Symcon-Installation des Anwenders gespeichert. Dazu können Zugangsdaten, Refresh-Tokens, Kalenderlisten und Termincaches gehören.

Der Anwender kann:

- Kalender-Caches über die Funktion **Cache leeren** entfernen,
- OAuth-Verbindungen über **Google-Konto trennen** bzw. **Microsoft-Konto trennen** lokal entfernen,
- durch Löschen der betreffenden Symcon-Instanzen die zugehörigen lokalen Modulwerte entfernen.

Bereits erstellte Symcon-Backups werden dadurch nicht nachträglich verändert. Für Aufbewahrung und Löschung solcher Backups ist der Betreiber der Symcon-Installation verantwortlich.

## 7. Protokollierung und Support

OpenCalendar schreibt Zugangspasswörter und OAuth-Tokens nicht absichtlich in Debug-Ausgaben. Bei Supportanfragen entscheidet der Anwender selbst, welche Logs, Screenshots oder Konfigurationsdaten er an Dritte weitergibt. Vor einer Weitergabe sollten Zugangsdaten, Token, private Feed-URLs und persönliche Termininhalte entfernt oder unkenntlich gemacht werden.

## 8. Drittanbieter

Für die Verarbeitung durch externe Dienste gelten zusätzlich deren jeweilige Datenschutzbestimmungen und Nutzungsbedingungen, insbesondere die von Google, Microsoft, Apple und Symcon sowie gegebenenfalls die des vom Anwender selbst konfigurierten CalDAV- oder iCalendar-Anbieters.

## 9. Änderungen

Diese Datenschutzhinweise werden angepasst, wenn sich die Datenverarbeitung oder die verwendeten OAuth-Berechtigungen wesentlich ändern.

---

# Privacy Notice (English)

OpenCalendar is a source-available library for Symcon provided under the PolyForm Noncommercial License 1.0.0. Calendar processing generally takes place on the user's own Symcon installation. The module author does not operate a calendar backend and does not receive calendar content through OpenCalendar.

Project contact: [OpenCalendar GitHub repository](https://github.com/Burki24/OpenCalendar) and its issue tracker.

## 1. Data processed by OpenCalendar

Depending on the selected provider, OpenCalendar processes account credentials or OAuth tokens, calendar metadata and permissions, event data such as title, description, location, start/end time and recurrence information, as well as technical IDs, ETags, synchronization timestamps and error states.

Calendar and event data is cached locally in Symcon where required for synchronization, display and editing. This data may therefore also be included in Symcon backups created by the user.

OpenCalendar contains no proprietary telemetry, advertising or user analytics. Calendar data is not sold and is not used for advertising, profiling or AI model training.

## 2. Google Calendar

Google integration uses a centrally registered Google application and the Symcon OAuth service. Users do not have to provide their own client ID or client secret. The requested scopes are limited to:

- `https://www.googleapis.com/auth/calendar.calendarlist.readonly`,
- `https://www.googleapis.com/auth/calendar.events`.

OAuth authorization codes and refresh tokens required for sign-in and token renewal are processed through the Symcon OAuth service at `https://oauth.ipmagic.de`. Actual calendar and event data is exchanged directly between the user's Symcon installation and Google Calendar at `https://www.googleapis.com` and is not routed through a calendar backend operated by the module author. The Google refresh token is stored as an internal persistent Symcon attribute; short-lived access tokens are held only in the instance buffer.

Google user data is used solely to synchronize, display and, where initiated or configured by the user, modify the calendars managed through OpenCalendar.

When a Google account is disconnected, OpenCalendar attempts to revoke the stored refresh token at Google and then removes the local OAuth data and calendar caches.

## 3. Microsoft 365 / Outlook.com

Microsoft integration uses a centrally registered Microsoft Entra application and the Symcon OAuth service. Users do not have to provide their own client ID or client secret.

The requested delegated permissions are limited to:

- `Calendars.ReadWrite`,
- `offline_access`.

OAuth authorization codes and refresh tokens required for sign-in and token renewal are processed through the Symcon OAuth service at `https://oauth.ipmagic.de`. Actual calendar and event data is exchanged directly between the user's Symcon installation and Microsoft Graph at `https://graph.microsoft.com` and is not routed through a calendar backend operated by the module author.

The Microsoft refresh token is stored as an internal persistent Symcon attribute; short-lived access tokens are kept only in the instance buffer. Account, calendar and event information may additionally be cached locally in Symcon.

Disconnecting a Microsoft account removes the locally stored Microsoft OAuth data and calendar caches. Consent already granted at Microsoft can additionally be revoked by the user in their Microsoft account security settings or by the administrator of the relevant Microsoft 365 tenant.

## 4. Apple iCloud, CalDAV and ICS/Webcal

Credentials configured for Apple iCloud or another CalDAV server are used only to access the configured server. iCalendar feed URLs may themselves contain secret access information and should be treated like passwords.

Configured credentials and URLs are stored locally in the Symcon installation. iCalendar feeds, including event data, may be cached persistently to support HTTP validation and fallback to the last valid feed during temporary server failures.

## 5. Symcon Connect and Symcon OAuth

Google and Microsoft OAuth require an active Symcon Connect connection and use the central Symcon OAuth service. These services are operated by Symcon GmbH and are subject to Symcon's own privacy policy.

OpenCalendar has no access to server-side logs or other information processed by Symcon as part of these services.

## 6. Storage and deletion

Persistent OpenCalendar properties and attributes are stored on the user's Symcon installation and may include credentials, refresh tokens, calendar lists and event caches.

Users can clear calendar caches, disconnect Google or Microsoft OAuth connections, and remove associated local module data by deleting the relevant Symcon instances. Existing Symcon backups are not retroactively modified.

## 7. Logging and support

OpenCalendar does not intentionally write passwords or OAuth tokens to debug output. Users decide which logs, screenshots or configuration information they provide to third parties for support. Credentials, tokens, private feed URLs and personal event content should be removed or redacted before sharing.

## 8. Third-party services

The privacy policies and terms of the respective external services also apply, including Google, Microsoft, Apple and Symcon, as well as any CalDAV or iCalendar provider configured by the user.

## 9. Changes

This notice will be updated if OpenCalendar materially changes how user data is processed or which OAuth permissions are requested.
