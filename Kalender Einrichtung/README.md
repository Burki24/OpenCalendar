# Kalender Einrichtung

Die **Kalender Einrichtung** ist die zentrale Discovery- und Einrichtungsinstanz
für OpenCalendar ab Version 3.0 und benötigt Symcon 9.1 oder neuer.

Technisch heißt das Modul **OpenCalendar Discovery**. In einer deutschen
Symcon-Oberfläche wird dieser Name über die `locale.json` als
**Kalender Einrichtung** angezeigt.

Als Discovery-Modul (Typ 5) bildet es den zentralen Einstieg für die erstmalige
Einrichtung. Anwender sollen dadurch die Abhängigkeiten zwischen Kalender Konto,
Kalender Konfigurator, Kalender und Kalender Ansicht nicht mehr vorab kennen
müssen.

## Aktueller Entwicklungsstand

Der Assistent führt aktuell durch folgende Schritte:

1. Willkommen
2. Kalenderanbieter auswählen
3. Kalender Konto auswählen oder ein neues Konto vorbereiten
4. anbieterabhängige Kontoeinrichtung
5. Verbindung prüfen
6. verfügbare Kalender ermitteln und auswählen
7. ausgewählte Kalenderinstanzen anlegen beziehungsweise vorhandene übernehmen
8. Kalender Konto abschließen und ein neu angelegtes Konto aktivieren

Bei einem **vorhandenen Kalender Konto** baut OpenCalendar die Auswahl beim
Öffnen der Discovery-Konfiguration aus allen vorhandenen **Calendar Account**-
Instanzen auf. Jeder Eintrag zeigt Provider, Instanzname und Instanz-ID,
beispielsweise `Google Kalender — Kalender Konto (#35780)`.

Bei einem **neuen Kalender Konto** wird vor der anbieterabhängigen Einrichtung
eine inaktive Calendar-Account-Instanz angelegt. Dies ist insbesondere für den
OAuth-Ablauf von Google und Microsoft notwendig, da der OAuth-Callback einer
konkreten Symcon-Instanz zugeordnet werden muss.

Wird von der anbieterabhängigen Seite mit **Zurück** zur Kontoauswahl gewechselt,
entfernt OpenCalendar ein in diesem Wizard-Lauf neu vorbereitetes Konto wieder.
Wird der Wizard dagegen über **Später einrichten** geschlossen, bleibt die
inaktive Instanz erhalten und kann beim nächsten Start als vorhandenes Konto
weiterverwendet werden.

### Apple iCloud

Der Wizard übernimmt Apple-Account-E-Mail-Adresse und app-spezifisches Passwort.
Die bekannte iCloud-CalDAV-Adresse wird automatisch gesetzt. Beim Klick auf
**Weiter** wird die Verbindung mit der vorhandenen
`IPSKALACC_TestConnection()`-Funktion geprüft.

### CalDAV

Server-URL sowie optional Benutzername und Passwort können direkt im Wizard
gesetzt werden. Bei vorhandenen Konten bleiben leere Felder unverändert. Auch
hier wird die Verbindung vor dem Wechsel zur Kalenderauswahl geprüft.

### Google und Microsoft 365

Für Google und Microsoft verwendet der Wizard keine eigenen Zugangsdaten,
sondern startet die bereits vorhandenen nativen Symcon-OAuth-Funktionen des
Kalender Kontos. Nach erfolgreicher Autorisierung kehrt der Anwender zum Wizard
zurück und klickt auf **Weiter**. Der Wizard prüft anschließend OAuth-Status und
Provider-Verbindung.

### ICS / Webcal

Für eine einfache Online-iCalendar-Quelle können URL, Kalendername und
Authentifizierung direkt im Wizard gesetzt werden. Unterstützt werden
**URL / Zugriffsschlüssel** sowie **Benutzername / Passwort**. Komplexere
Mehrfach-Abonnements und lokale ICS-Dateien bleiben weiterhin der vollständigen
Konfiguration des Kalender Kontos vorbehalten.

Bei einem bereits eingerichteten ICS/Webcal-Konto kann die URL leer bleiben.
Dann verändert der Wizard die bestehende iCalendar-Konfiguration nicht und führt
nur den Verbindungstest aus.

### Kalenderauswahl

Nach erfolgreicher Verbindungsprüfung ruft der Wizard die bereits vorhandene
providerunabhängige Kalenderermittlung des Kalender Kontos auf. Die gefundenen
Kalender werden mit Name, Zugriffsart und Kennzeichnung des primären Kalenders
angezeigt und können über eine Checkbox ausgewählt werden.

Ist genau ein Kalender vorhanden, wird er automatisch vorausgewählt. Meldet der
Anbieter einen oder mehrere primäre Kalender, werden diese vorausgewählt. Vor dem
Fortfahren muss mindestens ein Kalender gewählt sein. Die ausgewählten internen
Kalender-IDs werden in der Discovery-Instanz gespeichert.

### Kalenderinstanzen

Mit **OK** auf der Abschlussseite legt OpenCalendar für die ausgewählten Kalender
die benötigten **Kalender**-Instanzen an und verbindet sie direkt mit dem
gewählten Kalender Konto. Dabei wird dieselbe providerunabhängige
Kalenderkonfiguration verwendet wie im Kalender Konfigurator: interne
Kalender-ID, Provider-ID, URL, Farbe, Schreibrechte und Synchronisationswerte.

Existiert unter demselben Kalender Konto bereits eine Kalenderinstanz mit
derselben `CalendarID`, wird diese übernommen und nicht doppelt angelegt.
Vorhandene Instanzen werden dabei nicht umbenannt.

Neu angelegte Kalenderinstanzen erhalten den Anbieter als Namenspräfix:

- Microsoft 365 / Outlook.com: `O365 - <Kalendername>`
- Apple iCloud: `Apple - <Kalendername>`
- Google Kalender: `Google - <Kalendername>`
- CalDAV: `CalDAV - <Kalendername>`
- ICS / Webcal: `ICS - <Kalendername>`

Die IDs der erzeugten beziehungsweise übernommenen Kalenderinstanzen werden in
der Discovery-Instanz gespeichert und können im nächsten Ausbauschritt für die
Kalender Ansicht weiterverwendet werden.

Ein im Wizard **neu angelegtes** Kalender Konto wird erst auf der Abschlussseite
mit **OK** aktiviert. Ein bereits vorhandenes Konto behält seinen bisherigen
Aktivierungszustand unverändert. Schlägt das Anlegen einer Kalenderinstanz fehl,
werden in diesem Abschlussvorgang bereits neu erzeugte Kalenderinstanzen wieder
entfernt; ein zuvor inaktives neues Konto wird ebenfalls wieder deaktiviert.

## Geplanter Ausbau

Der Assistent soll anschließend schrittweise um folgende Funktionen erweitert
werden:

- Kalender Ansicht anlegen oder eine vorhandene auswählen
- gewählte Kalender der Ansicht zuordnen
- abschließende Synchronisation und Ergebnisübersicht
