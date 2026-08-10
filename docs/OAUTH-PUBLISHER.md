# OAuth-Infrastruktur für Modulautoren

Diese Dokumentation richtet sich ausschließlich an Herausgeber und Entwickler
von OpenCalendar. Anwender des Moduls benötigen weder eigene OAuth-Client-IDs
noch Clientschlüssel. Für Google Calendar und Microsoft 365 genügt im
Kalender-Konto eine aktive Symcon-Connect-Verbindung.

## Google Calendar

OpenCalendar verwendet den zentral bei Symcon registrierten OAuth-Identifier
`opencalendar_google` und die Redirect-URI
`https://oauth.ipmagic.de/forward/opencalendar_google`.

Angefordert werden ausschließlich folgende Scopes:

- `https://www.googleapis.com/auth/calendar.calendarlist.readonly`
- `https://www.googleapis.com/auth/calendar.events`

Einmalige Einrichtung:

1. In einem Google-Cloud-Projekt die **Google Calendar API** aktivieren.
2. Den OAuth-Zustimmungsbildschirm für eine externe Anwendung konfigurieren und
   die beiden benötigten Scopes eintragen.
3. Eine OAuth-Client-ID vom Typ **Webanwendung** erstellen.
4. Die genannte Redirect-URI als autorisierte Weiterleitungs-URI hinterlegen.
5. Den Client unter dem Identifier `opencalendar_google` beim
   Symcon-OAuth-Dienst registrieren lassen.
6. Vor einer öffentlichen Nutzung die von Google für den Nutzerkreis und die
   angeforderten Kalenderscopes verlangte Verifizierung abschließen.

Da die Redirect-URI auf der Symcon-Domain `ipmagic.de` liegt, muss die
Einrichtung mit Symcon abgestimmt werden. Eine externe Google-App im Status
**Testing** eignet sich nur für Entwicklungstests. Testnutzer müssen explizit
eingetragen werden; bei den verwendeten Kalenderscopes kann das Refresh-Token
einer Test-App nach sieben Tagen ablaufen.

Google beschreibt den verwendeten
[OAuth-Ablauf für Webserver-Anwendungen](https://developers.google.com/identity/protocols/oauth2/web-server)
und die
[Kalenderberechtigungen](https://developers.google.com/workspace/calendar/api/auth).

## Microsoft 365 und Outlook.com

OpenCalendar verwendet den zentral bei Symcon registrierten OAuth-Identifier
`opencalendar_microsoft` und die Redirect-URI
`https://oauth.ipmagic.de/forward/opencalendar_microsoft`.

Einmalige Einrichtung:

1. In Microsoft Entra eine Web-App registrieren, die Konten aus beliebigen
   Organisationsverzeichnissen sowie persönliche Microsoft-Konten akzeptiert.
2. Die genannte Redirect-URI als Web-Redirect-URI hinterlegen.
3. Delegiert `Calendars.ReadWrite` sowie den OAuth-Scope `offline_access`
   freigeben.
4. Einen Clientschlüssel erzeugen.
5. Client-ID, Clientschlüssel, Microsoft-Autorisierungs- und Token-Endpunkte
   sowie die benötigten Scopes unter dem Identifier
   `opencalendar_microsoft` beim Symcon-OAuth-Dienst registrieren lassen.

## Umgang mit zentralen Zugangsdaten

Clientschlüssel und andere zentrale App-Zugangsdaten gehören nicht in das
Repository, in Konfigurationsformulare oder in Debugmeldungen. OpenCalendar
speichert ausschließlich das benutzerspezifische Refresh-Token als internes
Attribut der jeweiligen Kalender-Konto-Instanz; kurzlebige Access-Tokens liegen
nur im Instanzpuffer.
