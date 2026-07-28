# OpenCalendar

## Wichtiger Hinweis zur Einrichtung

**Kalender-Instanzen sollen ausschließlich über den zum Konto gehörenden
Kalender Konfigurator erstellt werden.**

Der Konfigurator übernimmt automatisch den Kalendernamen, die interne
Kalender-ID, die Anbieter-ID, die Farbe, die Schreibrechte und die Verbindung
zum richtigen Kalender Konto. Werden Kalender-Instanzen stattdessen manuell
angelegt, kopiert oder nur über **Gateway ändern** verbunden, fehlen diese
Informationen. Solche Instanzen heißen zunächst lediglich „Kalender“ und
können insbesondere bei Konten mit mehreren Kalendern nicht eindeutig
zugeordnet werden.

Empfohlene Reihenfolge:

1. **Kalender Konto** anlegen und konfigurieren.
2. Das Konto synchronisieren.
3. Den zugehörigen **Kalender Konfigurator** öffnen.
4. Die gewünschten Kalender in der gefundenen Liste auswählen und dort
   erstellen lassen.
5. Erst danach die erzeugten Kalender-Instanzen bei Bedarf im Objektbaum
   verschieben oder umbenennen.

## Datenschutz und externe Dienste

OpenCalendar verarbeitet Konto-, Kalender- und Termindaten grundsätzlich auf der
eigenen Symcon-Installation. Das Modul enthält keine eigene Telemetrie und
übermittelt Kalenderinhalte nicht an einen Backenddienst des Modulautors. Bei
OAuth-Anbindungen werden jedoch die jeweils notwendigen Dienste von Google,
Microsoft und Symcon verwendet.

Vor dem Verbinden eines externen Kontos sollten die
[Datenschutzhinweise](PRIVACY.md) gelesen werden. Ergänzend gelten die
[Nutzungsbedingungen](TERMS.md) sowie die Bedingungen der jeweils verwendeten
Drittanbieter.

## Google Calendar verbinden

Die Google-Anbindung verwendet den zentral registrierten OAuth-Dienst von
Symcon. Anwender benötigen keine eigene Google-Client-ID und keinen
Clientschlüssel. Voraussetzung ist lediglich eine aktive
**Symcon-Connect-Verbindung**.

Im **Kalender Konto** wird als Anbieter **Google Calendar** gewählt und
anschließend **Google-Konto verbinden** aufgerufen. Nach der Anmeldung und
Zustimmung bei Google genügt **Jetzt synchronisieren**. Die gefundenen Kalender
werden danach über den zugehörigen **Kalender Konfigurator** angelegt.

Eine mit einer älteren OpenCalendar-Version über einen persönlichen
Google-OAuth-Client hergestellte Verbindung muss nach dem Update einmal neu
verbunden werden. Der frühere persönliche Client wird danach nicht mehr
verwendet.

OpenCalendar fordert nur
`https://www.googleapis.com/auth/calendar.calendarlist.readonly` zum Auflisten
der abonnierten Kalender sowie
`https://www.googleapis.com/auth/calendar.events` zum Lesen und Verwalten von
Terminen an. Das benutzerspezifische Aktualisierungstoken wird als internes
Attribut der Kalender-Konto-Instanz gespeichert; kurzlebige Access-Tokens
liegen nur im Instanzpuffer.

### Einmalige Google-Freischaltung für Modulautoren

Der gemeinsame Google-OAuth-Client wird einmalig außerhalb des Repositorys
eingerichtet. Diese Einrichtung ist **nicht** von jedem Anwender durchzuführen:

1. In einem Google-Cloud-Projekt die **Google Calendar API** aktivieren.
2. Den OAuth-Zustimmungsbildschirm für eine externe Anwendung konfigurieren und
   die beiden oben genannten Scopes eintragen.
3. Eine OAuth-Client-ID vom Typ **Webanwendung** erstellen.
4. Als autorisierte Redirect-URI
   `https://oauth.ipmagic.de/forward/opencalendar_google` hinterlegen.
5. Den Client unter dem Identifier `opencalendar_google` beim
   Symcon-OAuth-Dienst registrieren lassen.
6. Vor einer öffentlichen Nutzung die von Google für die angeforderten
   Kalenderscopes verlangte OAuth-Verifizierung abschließen.

Da die Redirect-URI auf der Symcon-Domain `ipmagic.de` liegt, muss die
Einrichtung mit Symcon abgestimmt werden. Google erlaubt Redirect-Domains nur,
wenn der Projektverantwortliche sie besitzt oder ausdrücklich verwenden darf;
für die Google-Domainprüfung kann daher Unterstützung durch Symcon erforderlich
sein.

Für einen ersten Entwicklungstest kann die Google-App im Status **Testing**
bleiben und das verwendete Google-Konto als Testnutzer eingetragen werden.
Google lässt bei einer externen Test-App mit Kalenderscopes das Refresh-Token
jedoch nach sieben Tagen ablaufen. Ein dauerhafter Betrieb setzt deshalb den
Produktivstatus und die gegebenenfalls erforderliche Verifizierung voraus.

Clientschlüssel und andere zentrale App-Zugangsdaten gehören **nicht** in das
Repository. Google beschreibt den verwendeten
[OAuth-Ablauf für Webserver-Anwendungen](https://developers.google.com/identity/protocols/oauth2/web-server)
und die
[Kalenderberechtigungen](https://developers.google.com/workspace/calendar/api/auth).

## Microsoft 365 / Outlook.com verbinden

Die Microsoft-Anbindung ist für Anwender bewusst ohne eigene App-Registrierung
aufgebaut. Voraussetzung ist lediglich eine aktive **Symcon-Connect-Verbindung**.
Im **Kalender Konto** wird als Anbieter **Microsoft 365** gewählt und anschließend
**Microsoft-Konto verbinden** aufgerufen. Die Anmeldung und Zustimmung erfolgen
direkt bei Microsoft; Client-ID und Clientschlüssel werden dem Anwender nicht
angezeigt und müssen nicht in Symcon hinterlegt werden.

OpenCalendar fordert ausschließlich delegierten Kalenderzugriff an. Unterstützt
werden Microsoft-365-Geschäfts-/Schulkonten sowie persönliche Microsoft-Konten
wie Outlook.com. Das Modul kann die eigenen Kalender auflisten und – entsprechend
den von Microsoft gemeldeten Kalenderrechten – Termine lesen, erstellen, ändern
und löschen. Mail, Kontakte, OneDrive und Teams-APIs werden nicht angefordert.

Der benutzerspezifische Refresh-Token wird als internes Attribut der
Kalender-Konto-Instanz gespeichert; kurzlebige Access-Tokens werden nur im
Instanzpuffer gehalten.

### Einmalige Freischaltung für Modulautoren

Für die Veröffentlichung muss der gemeinsame OAuth-Client einmalig außerhalb
des Repositorys eingerichtet werden. Diese Einrichtung ist **nicht** von jedem
Anwender durchzuführen:

1. In Microsoft Entra eine Web-App registrieren, die Konten aus beliebigen
   Organisationsverzeichnissen sowie persönliche Microsoft-Konten akzeptiert.
2. Als Redirect-URI
   `https://oauth.ipmagic.de/forward/opencalendar_microsoft` hinterlegen.
3. Delegiert `Calendars.ReadWrite` sowie den OAuth-Scope `offline_access`
   freigeben.
4. Einen Client-Schlüssel erzeugen und den OAuth-Client unter dem Identifier
   `opencalendar_microsoft` beim Symcon-OAuth-Dienst registrieren lassen. Dabei
   Client-ID, Client-Schlüssel, Microsoft-Autorisierungs-/Token-Endpunkte und
   die benötigten Scopes an Symcon übermitteln.

Client-Schlüssel oder andere zentrale App-Zugangsdaten gehören **nicht** in das
Repository. Erst nach dieser einmaligen serverseitigen Registrierung kann der
Microsoft-Login produktiv durchlaufen.

Folgende Module beinhaltet das Repository:

- __Kalender Konto__ ([Dokumentation](Kalender%20Konto))  
	Verbindet Apple-iCloud-, Google-Calendar-, Microsoft-365-/Outlook.com- und CalDAV-Konten, bündelt mehrere iCalendar-Abonnements in einem Konto, hält eine geprüfte Feed-Rückfallebene vor, löst wiederkehrende Feed-Termine lokal auf und stellt die Kalender bereit.

- __Kalender Konfigurator__ ([Dokumentation](Kalender%20Konfigurator))  
	Findet Kalender eines Kontos und legt Kalenderinstanzen an.

- __Kalender__ ([Dokumentation](Kalender))  
	Repräsentiert einen einzelnen Online-Kalender.

- __Kalender Ansicht__ ([Dokumentation](Kalender%20Ansicht))  
	Führt mehrere Kalender in einer modernen Kachelansicht zusammen.
