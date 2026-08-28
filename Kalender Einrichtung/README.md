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

## Ablauf des Einrichtungsassistenten

Der Assistent führt durch folgende Schritte:

1. Willkommen
2. Kalenderanbieter auswählen und optional einen Kalender Konfigurator vormerken
3. Kalender Konto auswählen oder ein neues Konto vorbereiten
4. anbieterabhängige Kontoeinrichtung
5. Verbindung prüfen
6. verfügbare Kalender ermitteln und auswählen
7. Kalender Ansicht auswählen oder neu anlegen
8. ausgewählte Kalenderinstanzen anlegen beziehungsweise vorhandene übernehmen
9. ausgewählte Kalenderinstanzen der Kalender Ansicht zuordnen
10. Kalender Konto abschließen und ein neu angelegtes Konto aktivieren
11. Konto und ausgewählte Kalender abschließend synchronisieren und technisch prüfen
12. Ergebnisübersicht mit angelegten beziehungsweise übernommenen Instanzen anzeigen

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

### Optionaler Kalender Konfigurator

Auf der Seite **Kalenderanbieter auswählen** kann optional **Kalender Konfigurator
mit anlegen** aktiviert werden. Der Konfigurator wird mit demselben Kalender
Konto verbunden und dient später dazu, die verfügbaren Kalender dieses Kontos
erneut zu ermitteln sowie weitere Kalenderinstanzen komfortabel hinzuzufügen,
ohne den vollständigen Einrichtungsassistenten erneut durchlaufen zu müssen.

Ist für das gewählte Kalender Konto bereits ein Kalender Konfigurator vorhanden,
wird dieser übernommen und kein zweiter angelegt. Neu angelegte Konfiguratoren
erhalten einen Namen aus Provider, Kontoname und Funktionsbezeichnung, zum
Beispiel `O365 - Privat - Konfigurator`. Wird die Option nicht aktiviert, legt
der Assistent keinen Konfigurator an.

### Apple iCloud

Der Wizard übernimmt Apple-Account-E-Mail-Adresse und app-spezifisches Passwort.
Für OpenCalendar muss im Apple Account unter **Anmelden und Sicherheit >
App-spezifische Passwörter** ein eigenes Passwort erzeugt werden; dafür muss die
Zwei-Faktor-Authentifizierung aktiviert sein. Direkt im Wizard stehen dazu
Schaltflächen zum [Apple Account](https://account.apple.com/) und zur offiziellen
[Apple-Support-Anleitung](https://support.apple.com/102654) bereit.
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

Mit **Weiter** auf der Prüfseite legt OpenCalendar für die ausgewählten Kalender
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
der Discovery-Instanz gespeichert und anschließend für die Kalender Ansicht
verwendet.

### Kalender Ansicht

Nach der Kalenderauswahl kann eine neue **Kalender Ansicht** angelegt oder eine
bereits vorhandene Ansicht ausgewählt werden. Für eine neue Ansicht wird ein frei
wählbarer Name verwendet; als Vorgabe trägt der Wizard `OpenCalendar` ein.

Bei einer neuen Ansicht werden alle im Wizard ausgewählten Kalenderinstanzen
aktiviert eingetragen. Bei einer vorhandenen Ansicht ergänzt OpenCalendar die
ausgewählten Kalender, ohne bereits bestehende Zuordnungen zu entfernen. Ist ein
ausgewählter Kalender dort bereits vorhanden, wird er aktiviert. Andere vorhandene
Kalender und deren Aktivierungszustand bleiben unverändert.

Die verwendete Kalender-Ansicht-ID wird in der Discovery-Instanz gespeichert.

Ein im Wizard **neu angelegtes** Kalender Konto wird beim abschließenden
Einrichtungsschritt aktiviert. Ein bereits vorhandenes Konto behält seinen bisherigen
Aktivierungszustand unverändert. Schlägt das Anlegen einer Kalenderinstanz oder
das Einrichten der Kalender Ansicht fehl, werden in diesem Abschlussvorgang neu
erzeugte Kalenderinstanzen wieder
entfernt; ein zuvor inaktives neues Konto wird ebenfalls wieder deaktiviert.

### Abschlussprüfung und Ergebnisübersicht

Nach dem Anlegen beziehungsweise Übernehmen der benötigten Instanzen führt der
Assistent eine abschließende technische Prüfung durch. Das Kalender Konto wird
aktualisiert und jeder im Wizard ausgewählte Kalender wird einmal synchronisiert.
Eine vorhandene, deaktivierte Konto-Instanz wird dafür nur vorübergehend aktiviert
und anschließend wieder in ihren ursprünglichen Zustand versetzt. Neu angelegte
Konten bleiben nach erfolgreicher Einrichtung aktiviert.

Anschließend initialisiert OpenCalendar die verwendete **Kalender Ansicht**. Fehler
in dieser Abschlussprüfung führen nicht dazu, dass eine bereits erfolgreich
angelegte Struktur wieder gelöscht wird. Stattdessen zeigt die letzte Wizard-Seite
eine Warnung und nennt die Kalender, deren Synchronisation nicht erfolgreich war.

Die Ergebnisübersicht zeigt:

- das verwendete Kalender Konto und dessen Aktivierungszustand,
- ob ein Kalender Konfigurator angelegt, übernommen oder nicht angefordert wurde,
- wie viele Kalenderinstanzen neu angelegt beziehungsweise übernommen wurden,
- das Ergebnis der abschließenden Synchronisation,
- die verwendete Kalender Ansicht und das Ergebnis ihrer Initialisierung.

Damit ist der technische Einrichtungsablauf vom Provider bis zur einsatzbereiten
Kalender Ansicht vollständig im Assistenten abgebildet.

## Abschluss, Wiederholung und weitere Konten

Bei Warnungen zeigt die Ergebnisseite die verfügbare Fehlerursache beziehungsweise
den Instanzstatus an. Die Konto- und Kalendersynchronisation sowie die Initialisierung
der Kalender Ansicht können direkt aus der Ergebnisseite erneut geprüft werden.
Zusätzlich werden kurze Hinweise für eine manuelle Wiederholung über Kalender Konto
und Kalenderinstanz angezeigt.

Nach dem Ergebnis kann der Anwender die Einrichtung beenden oder direkt zur
Providerauswahl zurückkehren, um ein weiteres Kalender-Konto einzurichten. Bereits
abgeschlossene Konfigurationen bleiben unverändert.
