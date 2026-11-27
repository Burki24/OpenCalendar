# Datenschutzhinweise / Privacy Notice

**Stand / Last updated: 27.11.2026**

OpenCalendar ist eine quellverfügbare, unter der PolyForm Noncommercial License 1.0.0 bereitgestellte Bibliothek für Symcon. Die Kalenderverarbeitung findet grundsätzlich auf der Symcon-Installation des Anwenders statt. Der Modulautor betreibt keinen eigenen Kalender-Backenddienst und erhält über OpenCalendar keine Kalenderinhalte.

Kontakt zum Projekt: [GitHub-Repository OpenCalendar](https://github.com/Burki24/OpenCalendar) und dessen Issue-Bereich.

## 1. Welche Daten verarbeitet OpenCalendar?

Je nach gewähltem Anbieter verarbeitet OpenCalendar insbesondere:

- Zugangsdaten oder OAuth-Zugangstoken für das verbundene Kalenderkonto,
- Kalenderkennungen, Kalendernamen, Farben und Berechtigungen,
- Termin- und bei ausgewählter Microsoft-To-Do-Liste Aufgabendaten wie Titel, Beschreibung, Ort, Beginn, Ende beziehungsweise Fälligkeit, Ganztags- und Erledigungsstatus, Wiederholungsinformationen sowie technische Termin-, Aufgaben- und Listen-IDs,
- bei ausdrücklich freigegebenem Anhangszugriff Dateinamen, Dateitypen, Dateigrößen, technische Anhangskennungen und beim Hoch- oder Herunterladen die Dateiinhalte,
- Synchronisationszeitpunkte, ETags und technische Fehlerzustände.

Kalender- und Termindaten werden lokal in Symcon zwischengespeichert, soweit dies für Synchronisation, Darstellung und Bearbeitung erforderlich ist. Dadurch können diese Daten auch Bestandteil eines vom Anwender erstellten Symcon-Backups werden.

Für ausdrücklich verknüpfte Puffertermine speichert die Kalenderinstanz die technischen Kennungen und Zeitabstände von Haupt- und Zusatzterminen dauerhaft. Nach Änderungen oder bestätigten Löschungen des Haupttermins kann OpenCalendar die Zusatztermine beim selben Kalenderanbieter automatisch ändern oder löschen. Bei nicht eindeutigem Anbieterzustand erfolgen keine solchen Löschungen.

Wählt der Anwender für Puffertermine **Nur in Symcon**, speichert die zugehörige Kalenderinstanz ihre Originale getrennt vom Anbieter- und Anzeigecache. Diese Zusatztermine werden nicht an den Kalenderanbieter übertragen. Berechtigte Nutzer der Symcon-Ansicht können sie sehen; Symcon-Sicherungen können sie enthalten. **Cache leeren** entfernt sie nicht, das Löschen der Kalenderinstanz entfernt den dort gespeicherten Originalbestand. Bei einer Verknüpfung können Änderungen oder bestätigte Löschungen des Haupttermins die lokalen Puffer automatisch ändern oder löschen.

Bei einem **lokalen Kalender** ist Symcon selbst der ursprüngliche und einzige Kalenderdatenspeicher. Die Originaltermine mit Serienregeln und Ausnahmen werden dauerhaft in der Kalender-Instanz getrennt vom Anzeige-Cache gespeichert. Dafür sind kein Anbieter-Konto, keine OAuth-Berechtigungen und keine Übertragung an einen Kalenderdienst erforderlich. Aufgabenverarbeitung und Aktualisierung erfolgen lokal. Wählt der Anwender ausdrücklich einen externen Kalender als Verschiebeziel, werden die betroffenen Termindaten an dessen Anbieter übertragen. **Cache leeren** löscht die lokalen Originaltermine nicht; das Löschen der lokalen Kalender-Instanz dagegen entfernt auch ihren Originalbestand. Symcon-Backups können die Originale weiterhin enthalten und müssen separat geschützt beziehungsweise gelöscht werden.

Wenn der Anwender einen Termin als **Aufgabentermin** kennzeichnet, verarbeitet OpenCalendar zusätzlich den Aufgabenstatus und gegebenenfalls die Zuordnung zu einer Ursprungsserie. Bei späteren Synchronisationen werden überfällige offene Aufgaben automatisch nachgezogen, bis sie erledigt sind oder die Aufgabenkennzeichnung entfernt wird. Dazu können beim Kalenderanbieter Termine geändert und bei Serien Einzeltermine angelegt sowie die zugehörigen ursprünglichen Vorkommnisse entfernt werden. Die vom Anwender gewählte Option **Geplante Folgetermine mitverschieben** bestimmt, ob der folgende Serienplan mit angepasst wird. Diese kalenderbasierten Aufgaben verwenden normale Kalendertermine und keine Google-Tasks-Anbindung. Bei Microsoft 365 kann der Anwender zusätzlich eine Microsoft-To-Do-Liste für neue Aufgabentermine auswählen; diese werden als native Microsoft-To-Do-Aufgaben verarbeitet. Bestehende kalenderbasierte Aufgabentermine werden nicht automatisch übertragen.

Wählt der Anwender beim Verschieben eines Termins oder einer unterstützten Serie einen anderen Kalenderanbieter als Ziel, überträgt die Symcon-Installation die hierfür erforderlichen Termindaten an diesen Zielanbieter. Erst nach bestätigter Erstellung im Ziel wird versucht, die entsprechende Quelle zu löschen. Eine solche providerübergreifende Übertragung erfolgt auf Veranlassung des Anwenders, nicht automatisch an beliebige Anbieter.

OpenCalendar enthält keine eigene Telemetrie, Werbung oder Nutzeranalyse. Kalenderdaten werden nicht verkauft und nicht für Werbung, Profilbildung oder das Training von KI-Modellen verwendet.

## 2. Google Calendar

### 2.1 Zugriff auf Google-Nutzerdaten

Die Google-Anbindung verwendet eine zentral registrierte und von Google für die öffentliche Nutzung verifizierte OAuth-Anwendung sowie den zentralen OAuth-Dienst von Symcon. Anwender müssen keine eigene Client-ID und keinen eigenen Clientschlüssel hinterlegen.

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
- auf ausdrückliches Abrufen vorhandener Google-Terminanlagen deren Dateinamen, technische Datei-IDs und Google-Verweise,
- OAuth-Access- und Refresh-Tokens, die zur autorisierten Kommunikation mit Google erforderlich sind.

OpenCalendar fordert keinen allgemeinen Zugriff auf das Google-Konto, Gmail, Google Drive, Kontakte oder andere nicht für die Kalenderfunktion erforderliche Google-Dienste an.

### 2.2 Verwendung von Google-Nutzerdaten

Google-Nutzerdaten werden ausschließlich verwendet, um die vom Anwender in OpenCalendar eingerichteten Kalenderfunktionen bereitzustellen.

Hierzu gehören:

- Auflisten der verfügbaren Google-Kalender,
- Synchronisieren von Kalendern und Terminen,
- Anzeigen von Terminen in OpenCalendar, Symcon und den zugehörigen Visualisierungen,
- Anzeigen vorhandener Anlagen eines ausgewählten Google-Termins erst nach ausdrücklichem Klick bei freigegebener Anhangs-Leseberechtigung; bestätigte Google-Drive- und Google-Docs-Verweise können dann durch den Anwender im Browser geöffnet werden. OpenCalendar lädt dabei keine Dateiinhalte über die Google-Drive-API herunter und bietet keinen Google-Anhangs-Upload an,
- optionales Speichern vom Anwender hinzugefügter Dateien ausschließlich auf seiner Symcon-Installation und Zuordnung zu einem bei Google frisch überprüften Termin, sofern die lokale Anhangsablage ausdrücklich freigegeben wurde; diese Dateien werden weder an Google übertragen noch dort synchronisiert,
- Erstellen neuer Termine auf ausdrückliche Veranlassung des Anwenders,
- Ändern bestehender Termine auf ausdrückliche Veranlassung des Anwenders,
- Löschen bestehender Termine auf ausdrückliche Veranlassung des Anwenders,
- automatisches Anpassen oder Löschen ausdrücklich verknüpfter Puffertermine beim selben Anbieter, wenn sich der Haupttermin ändert oder seine Löschung bestätigt wird,
- automatisches Nachziehen offener Aufgaben bei der Synchronisation, nachdem der Anwender die Aufgabenfunktion für den Termin aktiviert hat, einschließlich der dafür erforderlichen Änderungen, Erstellungen und Löschungen bei Serien,
- Übertragen von Terminen und unterstützten Serien in einen vom Anwender ausdrücklich ausgewählten Zielkalender, gegebenenfalls bei einem anderen Anbieter,
- lokale Zwischenspeicherung der für Darstellung und Synchronisation erforderlichen Kalenderinformationen,
- technische Verwaltung des OAuth-Zugriffs und der Synchronisation.

Google-Nutzerdaten werden nicht für Zwecke verwendet, die mit diesen sichtbaren Kalenderfunktionen nicht zusammenhängen.

Insbesondere werden Google-Nutzerdaten nicht für Werbung, Profilbildung, Tracking, Nutzeranalyse, Datenhandel, Bonitätsbewertung oder zum Training von KI-Modellen verwendet.

### 2.3 Weitergabe, Übertragung und Offenlegung von Google-Nutzerdaten

OpenCalendar übermittelt Google-Kalender- und Termindaten nicht an einen Backenddienst des Modulautors.

Der eigentliche Austausch von Kalender- und Termindaten erfolgt direkt zwischen der Symcon-Installation des Anwenders und den Google-Calendar-APIs.

Beim vom Anwender ausgelösten Verschieben aus einem Google-Kalender in einen Kalender eines anderen Anbieters übermittelt die Symcon-Installation die benötigten Termindaten direkt an den ausgewählten Zielanbieter. Beim Verschieben nach Google erfolgt die entsprechende Übertragung an Google. Es gibt dafür keinen Kalender-Backenddienst des Modulautors.

Für die OAuth-Anmeldung und die Token-Aktualisierung werden die dafür erforderlichen OAuth-Daten über den zentralen Symcon-OAuth-Dienst unter `https://oauth.ipmagic.de` verarbeitet. Dazu gehören insbesondere Autorisierungscodes und Refresh-Tokens, die für den Austausch mit Google erforderlich sind. Für den OAuth-Callback wird eine aktive Symcon-Connect-Verbindung des Anwenders benötigt. Diese Dienste werden von der Symcon GmbH betrieben. Die eigentlichen Kalender- und Termininhalte werden von OpenCalendar nicht über einen Server des Modulautors geleitet.

Der Modulautor erhält keinen automatischen Zugriff auf:

- Google-Kalender,
- Google-Termine,
- OAuth-Tokens,
- lokale Kalender-Caches,
- sonstige Google-Nutzerdaten des Anwenders.

Google-Nutzerdaten werden von OpenCalendar nicht verkauft, vermietet oder an Werbenetzwerke, Datenhändler oder Analyseanbieter weitergegeben. Die oben beschriebene, vom Anwender ausgelöste Übertragung an einen ausgewählten Kalenderanbieter dient ausschließlich dem gewünschten Kalenderwechsel.

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
- Beim Trennen eines Google-Kontos versucht OpenCalendar, das gespeicherte Refresh-Token bei Google zu widerrufen und entfernt anschließend die lokalen Google-OAuth-Daten sowie die Kalenderlisten- und Feed-Caches der Konto-Instanz. Termincaches untergeordneter Kalender-Instanzen und bereits erzeugte IPSView-Inhalte werden dadurch nicht gelöscht; siehe Abschnitt 6.

Der Schutz dauerhaft lokal gespeicherter Daten hängt zusätzlich von der Sicherheit der Symcon-Installation, des zugrunde liegenden Betriebssystems und der vom Anwender verwendeten Backup-Speicher ab.

Der Betreiber der Symcon-Installation ist dafür verantwortlich, den Zugriff auf das Symcon-System, das Dateisystem und vorhandene Backups angemessen zu schützen. Backups mit sensiblen Kalender- oder OAuth-Daten sollten nur an geschützten Speicherorten abgelegt und, soweit verfügbar, verschlüsselt gespeichert werden.

### 2.6 Google API Services User Data Policy

Die Verwendung und Übertragung von Informationen, die OpenCalendar über Google APIs erhält, erfolgt entsprechend der **Google API Services User Data Policy**, einschließlich der dort festgelegten **Limited Use Requirements**.

OpenCalendar verwendet Google-Nutzerdaten ausschließlich zur Bereitstellung oder Verbesserung der vom Anwender verwendeten und sichtbaren Kalenderfunktionen.

## 3. Microsoft 365 / Outlook.com

Die Microsoft-Anbindung verwendet eine zentral registrierte Microsoft-Entra-Anwendung und den OAuth-Dienst von Symcon. Anwender müssen keine eigene Client-ID und keinen eigenen Clientschlüssel hinterlegen.

OpenCalendar fordert folgende delegierte Berechtigungen an:

- `Calendars.ReadWrite` zum Lesen, Erstellen, Ändern und Löschen von Kalenderterminen,
- `Tasks.ReadWrite` zum Lesen von Microsoft-To-Do-Listen und zum Lesen, Erstellen, Ändern und Löschen von Aufgaben in einer vom Anwender ausgewählten Liste,
- `offline_access`, damit die Verbindung über ein Refresh-Token dauerhaft genutzt werden kann.

Für Anmeldung und Token-Aktualisierung werden OAuth-Daten über den Symcon-OAuth-Dienst unter `https://oauth.ipmagic.de` verarbeitet. Dazu gehören insbesondere Autorisierungscodes und Refresh-Tokens, die für den Austausch mit Microsoft erforderlich sind.

Die eigentlichen Kalender-, Termin- und Microsoft-To-Do-Aufgabendaten werden von der Symcon-Installation direkt über `https://graph.microsoft.com` mit Microsoft Graph ausgetauscht und nicht über einen Backenddienst des Modulautors geleitet. Microsoft verwaltet die Wiederholungen nativer To-Do-Aufgaben; OpenCalendar liest die gelieferten Aufgaben und deren Status ein.

Das Microsoft-Refresh-Token wird als internes, persistentes Symcon-Attribut gespeichert; kurzlebige Access-Tokens werden nur im Instanzpuffer gehalten. Zusätzlich können Konto-, Kalender-, Termin-, Aufgabenlisten- und Aufgabendaten lokal in Symcon zwischengespeichert werden.

Beim Trennen eines Microsoft-Kontos entfernt OpenCalendar die lokal gespeicherten Microsoft-OAuth-Daten sowie die Kalenderlisten- und Feed-Caches der Konto-Instanz. Termincaches untergeordneter Kalender-Instanzen und bereits erzeugte IPSView-Inhalte bleiben erhalten; siehe Abschnitt 6. Eine bereits bei Microsoft erteilte Zustimmung kann zusätzlich vom Anwender in den Sicherheitseinstellungen seines Microsoft-Kontos beziehungsweise durch den Administrator des jeweiligen Microsoft-365-Mandanten widerrufen werden.

## 4. Apple iCloud, CalDAV und ICS/Webcal

Bei Apple iCloud und anderen CalDAV-Servern werden die vom Anwender eingetragenen Zugangsdaten ausschließlich für den Zugriff auf den konfigurierten Server verwendet.

Bei iCalendar-Abonnements können Feed-URLs selbst geheime Zugriffsinformationen enthalten und sollten wie Passwörter behandelt werden.

OpenCalendar speichert konfigurierte Zugangsdaten und URLs lokal in der Symcon-Installation.

iCalendar-Feeds können einschließlich ihrer Termindaten persistent zwischengespeichert werden, um ETag-/Last-Modified-Prüfungen und eine Rückfallebene bei vorübergehenden Serverfehlern zu ermöglichen.

## 4.1 Dateianhänge und Zugriff über die Kalenderansicht

Anhangslisten werden erst auf ausdrückliche Anfrage in den Termindetails abgerufen. Die Kalender- und Ansichtsinstanz müssen den Zugriff und den jeweiligen Speicherort gesondert erlauben. Berechtigte Nutzer derselben Ansicht teilen diese Anhangsrechte; lokal gespeicherte Dateien sind nicht automatisch benutzerprivat.

Bei lokalen Kalendern und Google-Terminen können vom Anwender hinzugefügte Dateien ausschließlich im persistenten Attribut der zugehörigen Symcon-Kalenderinstanz gespeichert werden. Sie werden nicht an Google oder einen anderen Kalenderanbieter übertragen. Ein ICS-Export und **Cache leeren** entfernen diese Dateien nicht. Auch nach dem Löschen eines Termins können lokale Originaldateien bis zu einer gezielten Bereinigung bestehen bleiben. Das Löschen der Kalenderinstanz kann den lokalen Anhangsbestand entfernen; bestehende Symcon-Backups bleiben davon unberührt. Bestand, Sicherung und gezielte Löschung sind unter [Lokale Dateianhänge sichern und aufräumen](docs/attachments-administration.md) beschrieben.

Bei Microsoft-365-Terminen und Microsoft-To-Do-Aufgaben sowie Apple-/CalDAV-Terminen kann OpenCalendar vom Anwender ausgewählte Dateien direkt beim jeweiligen Anbieter hochladen, vorhandene unterstützte Dateien herunterladen und Anhänge nach Einzelbestätigung aus dem Termin entfernen. Aufbewahrung und weitere Freigaben richten sich nach den Einstellungen des Anbieters. Bei CalDAV kann ein Anhang als eingebetteter iCalendar-Inhalt oder als serververwaltete Datei gespeichert sein. Das Entfernen eines externen Dateiverweises aus dem Termin löscht nicht die verlinkte Datei selbst. ICS-/Webcal-Quellen bleiben schreibgeschützt. Vorhandene Google-Anhänge werden nur mit Metadaten und bestätigtem Google-Link angezeigt; OpenCalendar lädt ihren Inhalt nicht über Google Drive und benötigt keine zusätzliche Drive-Berechtigung.

Uploads und Downloads in Kachel oder IPSView laufen als angemeldete POST-Anfragen über den privaten Hook der Kalenderansicht. Transport, Kalenderzugehörigkeit und konfigurierte Rechte werden pro Anfrage geprüft. Grundsätzlich ist HTTPS erforderlich. Der Betreiber kann für direkte private lokale Verbindungen ausdrücklich eine unverschlüsselte HTTP-Ausnahme aktivieren; dabei können Dateiinhalt und Zugriffskennungen im lokalen Übertragungsweg mitgelesen oder verändert werden. Anbieter-Anhänge und lokale Originale können in Backups des Anbieters beziehungsweise der Symcon-Installation enthalten sein und sind entsprechend zu schützen.

## 5. Symcon Connect und Symcon OAuth

Google OAuth und Microsoft OAuth verwenden den zentralen Symcon-OAuth-Dienst. Für den jeweiligen OAuth-Callback wird eine aktive Symcon-Connect-Verbindung benötigt.

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
- Aufgaben-/Serienzuordnungen und lokale Anhangsoriginale,
- Synchronisationsinformationen.

Der Anwender kann:

- über **Cache leeren** im Kalender Konto dessen Kalenderlisten- und Feed-Caches entfernen,
- über **Cache leeren** in jeder betroffenen Kalender-Instanz deren Termincache und Synchronisationsinformationen leeren,
- OAuth-Verbindungen über **Google-Konto trennen** beziehungsweise **Microsoft-Konto trennen** lokal entfernen,
- durch Löschen der betreffenden Symcon-Instanzen die zugehörigen lokalen Modulwerte entfernen.

Das Trennen eines Kontos leert nicht die Termincaches der untergeordneten Kalender-Instanzen. Auch **Cache leeren** im Konto ist keine Löschung aller lokalen Termindaten. Aufgaben-/Serienzuordnungen und Jahresereignis-Metadaten können in Kalender-Instanzen zusätzlich zum Termincache gespeichert bleiben. Bei einer vollständigen lokalen Entfernung müssen daher auch die nicht mehr benötigten Kalender-Instanzen berücksichtigt werden.

Bereits erzeugte IPSView-HTML-Variablen und deren Inhalte werden beim Trennen eines Kontos oder beim Deaktivieren der IPSView-Ausgabe nicht automatisch gelöscht. Nicht mehr benötigte Ausgabevariablen sind gesondert im Symcon-Objektbaum zu entfernen; gegebenenfalls müssen auch noch verwendete Kalender-Ansichten angepasst werden. Aktive Verbindungen und Synchronisationen können zuvor geleerte Caches erneut füllen. Das lokale Leeren von Caches oder Entfernen von Instanzen löscht keine Termine beim Kalenderanbieter.

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

**Last updated: 25 November 2026**

OpenCalendar is a source-available library for Symcon distributed under the PolyForm Noncommercial License 1.0.0. Calendar processing generally takes place on the user's own Symcon installation. The module author does not operate a calendar backend service and does not receive calendar content through OpenCalendar.

Project contact: [OpenCalendar GitHub repository](https://github.com/Burki24/OpenCalendar) and its issue tracker.

## 1. Data processed by OpenCalendar

Depending on the selected provider, OpenCalendar processes in particular:

- credentials or OAuth access tokens for the connected calendar account,
- calendar identifiers, calendar names, colors and permissions,
- event data and, where a Microsoft To Do list is selected, task data such as title, description, location, start, end or due date, all-day and completion status, recurrence information and technical event, task and list IDs,
- where attachment access is explicitly enabled, file names, types, sizes, technical attachment IDs and file content during upload or download,
- synchronization timestamps, ETags and technical error states.

Calendar and event data is cached locally in Symcon where required for synchronization, display and editing. This data may therefore also be included in Symcon backups created by the user.

For explicitly linked adjacent appointments, the calendar instance persistently stores technical identifiers and time offsets of the main and adjacent events. After a change or confirmed deletion of the main event, OpenCalendar may automatically update or delete the adjacent events at the same provider. An ambiguous provider state does not authorize such a deletion.

When the user chooses **Only in Symcon** for adjacent appointments, the associated calendar instance stores their originals separately from the provider and display cache. These appointments are not transmitted to the calendar provider. Authorized users of the Symcon view can see them, and Symcon backups may contain them. **Clear cache** does not remove them; deleting the calendar instance removes its stored originals. If linked, a change or confirmed deletion of the main appointment may automatically update or delete the local adjacent appointments.

For a **local calendar**, Symcon itself is the original and sole calendar data store. Original events, recurrence rules and exceptions are stored persistently in the calendar instance, separately from the display cache. No provider account, OAuth permissions or transfer to a calendar service is required. Task processing and refreshes happen locally. If the user explicitly selects an external calendar as a move destination, the affected event data is transmitted to that provider. **Clear cache** does not delete local original events; deleting the local calendar instance does remove its original records. Symcon backups may still contain these records and must be protected or deleted separately.

When the user marks an event as a **task appointment**, OpenCalendar additionally processes its task status and, where applicable, its association with an original series. During subsequent synchronizations, overdue open tasks are automatically moved forward until completed or no longer marked as tasks. This can update provider events and, for recurring tasks, create individual events and remove the corresponding original occurrences. The user's **Move planned follow-up appointments** option determines whether the following series schedule is adjusted. These calendar-based tasks use regular calendar events and no Google Tasks integration. For Microsoft 365, users may also select a Microsoft To Do list for new task appointments; these are processed as native Microsoft To Do tasks. Existing calendar-based task appointments are not transferred automatically.

When the user moves an event or a supported series to a calendar hosted by another provider, the Symcon installation transmits the required event data to that destination provider. Only after creation at the destination is confirmed does OpenCalendar attempt to delete the corresponding source. Such cross-provider transfers are initiated by the user, not performed automatically to arbitrary providers.

OpenCalendar contains no proprietary telemetry, advertising or user analytics. Calendar data is not sold and is not used for advertising, profiling, creditworthiness assessment or AI model training.

## 2. Google Calendar

### 2.1 Google user data accessed

Google integration uses a centrally registered OAuth application verified by Google for public use together with Symcon's central OAuth service. Users do not need to provide their own client ID or client secret.

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
- when existing attachments of a Google event are explicitly requested, their filenames, technical file IDs and Google links,
- OAuth access and refresh tokens required for authorized communication with Google.

OpenCalendar does not request general access to the user's Google Account, Gmail, Google Drive, contacts or other Google services that are not required for its calendar functionality.

### 2.2 How Google user data is used

Google user data is used solely to provide the calendar features configured and used by the user in OpenCalendar.

This includes:

- listing available Google calendars,
- synchronizing calendars and events,
- displaying events in OpenCalendar, Symcon and the associated visualizations,
- showing existing attachments of a selected Google event only after an explicit click and when attachment read access is enabled; users may then open verified Google Drive or Docs links in their browser. OpenCalendar does not download file content through the Google Drive API or offer Google attachment uploads,
- optionally storing user-added files solely on the user's Symcon installation and associating them with a freshly verified Google event when local attachment storage has been explicitly enabled; these files are neither transferred to nor synchronized with Google,
- creating new events when explicitly initiated by the user,
- updating existing events when explicitly initiated by the user,
- deleting existing events when explicitly initiated by the user,
- automatically updating or deleting explicitly linked adjacent events at the same provider after a main event changes or its deletion is confirmed,
- automatically moving open tasks forward during synchronization after the user has enabled the task feature for the event, including the updates, creations and deletions required for recurring tasks,
- transferring events and supported series to a destination calendar explicitly selected by the user, including calendars hosted by another provider,
- locally caching calendar information required for display and synchronization,
- technically managing OAuth authorization and synchronization.

Google user data is not used for purposes unrelated to these visible calendar features.

In particular, Google user data is not used for advertising, profiling, tracking, user analytics, data brokerage, creditworthiness assessment or AI model training.

### 2.3 Sharing, transfer and disclosure of Google user data

OpenCalendar does not transmit Google calendar or event data to a backend service operated by the module author.

Actual calendar and event data is exchanged directly between the user's Symcon installation and the Google Calendar APIs.

When the user initiates a move from a Google calendar to another provider's calendar, the Symcon installation sends the required event data directly to the selected destination provider. Moving an event to Google performs the corresponding transfer to Google. No calendar backend operated by the module author is involved.

OAuth data required for authorization and token renewal is processed through Symcon's central OAuth service at `https://oauth.ipmagic.de`. This includes in particular authorization codes and refresh tokens required for the exchange with Google. An active Symcon Connect connection is required for the OAuth callback. These services are operated by Symcon GmbH. OpenCalendar does not route actual calendar and event content through a server operated by the module author.

The module author does not automatically receive access to:

- Google calendars,
- Google events,
- OAuth tokens,
- local calendar caches,
- other Google user data belonging to the user.

Google user data is not sold, rented or transferred by OpenCalendar to advertising networks, data brokers or analytics providers. The user-initiated transfer to a selected calendar provider described above serves only the requested calendar move.

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
- When a Google account is disconnected, OpenCalendar attempts to revoke the stored refresh token at Google and subsequently removes local Google OAuth data and the account instance's calendar-list and feed caches. This does not delete child calendar instances' event caches or previously generated IPSView content; see section 6.

Protection of data stored persistently on the local system also depends on the security of the user's Symcon installation, the underlying operating system and any backup storage used by the user.

The operator of the Symcon installation is responsible for appropriately protecting access to the Symcon system, filesystem and existing backups. Backups containing sensitive calendar or OAuth data should be stored only in protected locations and, where available, using encrypted storage.

### 2.6 Google API Services User Data Policy

OpenCalendar's use and transfer of information received from Google APIs adheres to the **Google API Services User Data Policy**, including its **Limited Use Requirements**.

OpenCalendar uses Google user data solely to provide or improve user-facing calendar features that are visible to and used by the user.

## 3. Microsoft 365 / Outlook.com

Microsoft integration uses a centrally registered Microsoft Entra application and the Symcon OAuth service. Users do not have to provide their own client ID or client secret.

The requested delegated permissions are limited to:

- `Calendars.ReadWrite` to read, create, update and delete calendar events,
- `Tasks.ReadWrite` to read Microsoft To Do lists and to read, create, update and delete tasks in a list selected by the user,
- `offline_access`.

OAuth authorization codes and refresh tokens required for sign-in and token renewal are processed through the Symcon OAuth service at `https://oauth.ipmagic.de`.

Actual calendar, event and Microsoft To Do task data is exchanged directly between the user's Symcon installation and Microsoft Graph at `https://graph.microsoft.com` and is not routed through a backend operated by the module author. Microsoft manages native To Do recurrences; OpenCalendar synchronizes the tasks and their status returned by Microsoft.

The Microsoft refresh token is stored as an internal persistent Symcon attribute; short-lived access tokens are kept only in the instance buffer. Account, calendar, event, task-list and task information may additionally be cached locally in Symcon.

Disconnecting a Microsoft account removes the locally stored Microsoft OAuth data and the account instance's calendar-list and feed caches. Child calendar instances' event caches and previously generated IPSView content are retained; see section 6. Consent already granted at Microsoft can additionally be revoked by the user in their Microsoft account security settings or by the administrator of the relevant Microsoft 365 tenant.

## 4. Apple iCloud, CalDAV and ICS/Webcal

Credentials configured for Apple iCloud or another CalDAV server are used only to access the configured server.

iCalendar feed URLs may themselves contain secret access information and should be treated like passwords.

Configured credentials and URLs are stored locally in the Symcon installation.

iCalendar feeds, including event data, may be cached persistently to support ETag/Last-Modified validation and fallback to the last valid feed during temporary server failures.

## 4.1 File attachments and access through the calendar view

Attachment lists are retrieved only when explicitly requested in event details. Both the calendar and view instances must separately allow attachment access and the relevant storage location. Authorized users of the same view share these attachment rights; locally stored files are not automatically private to an individual user.

For local calendars and Google events, user-added files can be stored exclusively in a persistent attribute of the corresponding Symcon calendar instance. They are not transmitted to Google or another calendar provider. An ICS export and **Clear cache** do not remove these files. Local original files may also remain after an event is deleted until they are explicitly cleaned up. Deleting the calendar instance can remove its local attachment store; existing Symcon backups are unaffected. Inventory, backup and targeted deletion are described in [Backing up and cleaning local attachments](docs/attachments-administration.md).

For Microsoft 365 events and Microsoft To Do tasks, as well as Apple/CalDAV events, OpenCalendar can upload files selected by the user directly to the provider, download supported existing files, and remove individual attachments from an event after confirmation. Retention and further sharing are governed by the provider's settings. CalDAV attachments may be embedded in iCalendar data or managed by the server. Removing an external file reference from an event does not delete the linked file itself. ICS/Webcal sources remain read-only. Existing Google attachments are shown with metadata and a verified Google link only; OpenCalendar does not fetch their content through Google Drive and needs no additional Drive permission.

Uploads and downloads in the tile or IPSView use authenticated POST requests through the calendar view's private hook. Transport, calendar membership and configured rights are checked for each request. HTTPS is required by default. The operator can explicitly enable a plaintext HTTP exception for direct private local connections; file content and access identifiers may then be observed or modified on that local transport path. Provider attachments and local originals may be included in provider or Symcon backups and must be protected accordingly.

## 5. Symcon Connect and Symcon OAuth

Google OAuth and Microsoft OAuth use the central Symcon OAuth service. An active Symcon Connect connection is required for the respective OAuth callback.

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
- task/series associations and original local attachments,
- synchronization information.

Users can:

- clear the calendar account's calendar-list and feed caches using its **Clear cache** function,
- clear the event cache and synchronization information in each affected calendar instance using that instance's **Clear cache** function,
- locally remove OAuth connections using **Disconnect Google account** or **Disconnect Microsoft account**,
- remove associated local module data by deleting the relevant Symcon instances.

Disconnecting an account does not clear its child calendar instances' event caches. **Clear cache** at account level does not delete all locally stored event data either. Task/series associations and anniversary metadata may remain stored in calendar instances separately from the event cache. Complete local removal therefore also needs to account for calendar instances that are no longer required.

Previously generated IPSView HTML variables and their content are not automatically deleted when an account is disconnected or IPSView output is disabled. Output variables that are no longer required must be removed separately in Symcon's object tree; calendar views still in use may also need adjusting. Active connections and synchronizations can refill previously cleared caches. Clearing local caches or removing instances does not delete events at the calendar provider.

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
