# Projektregeln für OpenCalendar

Lies zuerst `../SymconDevelopment/AGENTS.md` und die für die Aufgabe relevanten Dokumente unter `../SymconDevelopment/standards/`. Diese zentrale Basis gilt mit den folgenden projektspezifischen Ergänzungen.

## Projektkontext

- OpenCalendar ist eine Symcon-9.x-Kalenderanwendung mit vier gekoppelten Modulen: `Kalender Konto` stellt Provider und Gateway bereit, `Kalender Konfigurator` erzeugt vollständig zugeordnete Kalenderinstanzen, `Kalender` synchronisiert und bearbeitet einzelne Kalender, und `Kalender Ansicht` aggregiert sie für PHP-API, HTML-SDK-Kachel und IPSView.
- Zielplattform ist PHP 8.5 unter IP-Symcon 9.x; `library.json` erklärt die Mindestkompatibilität mit Symcon 9.0.
- Unterstützte Datenquellen sind lokale Symcon-Kalender, Apple/iCloud und generisches CalDAV, Google Calendar, Microsoft 365/Outlook.com sowie schreibgeschützte ICS-/Webcal-Quellen. Änderungen an Provider-, OAuth-, Netzwerk-, Schreib-, Lösch-, Serien-, Aufgaben- oder Zeitzonenlogik sind daten- und kompatibilitätskritisch.
- Providerneutrale Verträge und Fachlogik liegen unter `libs/`; modulspezifische Lebenszyklen, Formulare und öffentliche Methoden verbleiben in den vier Modulverzeichnissen.
- Dateien unter `libs/helper` sind synchronisierte Kopien aus `Symcon_ModuleHelper`. Ändere sie nicht lokal und lege keine Helper-Duplikate an; Änderungen erfolgen in der zentralen Quelle und kommen über den konfigurierten Helper-Sync.
- `.style` und `tests/stubs` sind offizielle Symcon-Git-Submodule. Ändere deren Inhalte oder Zeiger nur auf ausdrücklichen Auftrag.

## Vor jeder Änderung

1. Lies `README.md`, die README des betroffenen Moduls, `library.json`, dessen `module.json`, die betroffenen Quellen unter `libs/` sowie die zugehörigen Tests.
2. Lies bei Datenfluss-, Provider- oder Synchronisationsänderungen die Verträge zwischen Konto, Konfigurator und Kalender gemeinsam. Erhalte insbesondere Parent-/Child-GUIDs, Request-Namen, Kalenderidentitäten und den dreistufigen Seitentransfer für große Terminmengen.
3. Prüfe die öffentlichen Präfixe und Skript-APIs `IPSKALACC_*`, `IPSKALCFG_*`, `IPSKAL_*` und `IPSKALVIEW_*` auf Rückwärtskompatibilität. Lebenszyklusmethoden und interne Visualisierungs-Callbacks sind davon getrennt.
4. Prüfe Provideränderungen mindestens gegen lokale Kalender, CalDAV/iCloud, Google, Microsoft und die schreibgeschützten ICS-Pfade, soweit der gemeinsame Vertrag betroffen ist. Providerspezifische Sonderfälle dürfen nicht unbemerkt in den gemeinsamen Vertrag einsickern.
5. Prüfe bei Termin- und Serienlogik RFC 5545, lokale Zeitzonen und Sommerzeit, exklusive Ganztags-Enddaten, Ausnahmen, ETags und stabile Ressourcenidentitäten. Ein unklarer Remotezustand oder Abfragefehler ist keine bestätigte Löschung.
6. Prüfe bei Visualisierungsänderungen die native HTML-SDK-Kachel und die IPSView-Ausgabe gemeinsam. Beide verwenden dieselben Assets und Bedienverträge; die IPSView-Brücke bleibt tokengebunden und akzeptiert nur freigegebene POST-Aktionen.
7. Bewahre fremde Änderungen und bestehende Worktrees. Übertrage Änderungen nicht ohne Auftrag zwischen `dev`, `dev_9.1` oder anderen Arbeitszweigen.

## Umsetzung, Datenschutz und Dokumentation

- Erhalte bestehende Konten, Kalenderzuordnungen, Provideridentitäten, öffentliche JSON-Formate, Properties, Statuswerte, Caches und persistierte Daten, sofern keine ausdrücklich geplante Migration vorliegt.
- `Create()`, `ApplyChanges()`, Migration, Synchronisation und Wiederherstellung bleiben idempotent und neustartsicher. Bestätigte Schreibvorgänge dürfen durch nachfolgende Synchronisationsfehler nicht verloren gehen.
- Validiere externe URLs, HTTP-Antworten, OAuth-Daten, iCalendar-Inhalte und Providerantworten an der Systemgrenze. Bestehende Größen-, Origin-, TLS-, Zeitlimit-, Retry- und Cache-Regeln werden nicht umgangen.
- Passwörter, OAuth-Tokens, geheime Feed-Adressen, WebHook-Tokens und Kalenderinhalte erscheinen weder in unbereinigten Debugmeldungen noch in öffentlichen Status- oder Rückgabewerten.
- Sichtbares Verhalten wird im selben Arbeitspaket in der README des betroffenen Moduls und bei übergreifendem Verhalten zusätzlich in der Root-README dokumentiert. Änderungen an Datenverarbeitung, externen Diensten oder Berechtigungen werden außerdem gegen `PRIVACY.md` und `TERMS.md` geprüft.
- Änderungen an Formularen oder sichtbaren Texten halten `form.json`, `locale.json` und Dokumentation konsistent. PHP-, JavaScript- und IPSView-Verträge werden gemeinsam aktualisiert, wenn sie dieselbe Funktion abbilden.

## Qualität und CI

- Der lokale Gesamteinstieg ist `php tests/run.php`. Er umfasst Provider- und Datenflusslogik, Synchronisation und Wiederherstellung, Wiederholungsregeln und Zeitzonen, Aufgaben, öffentliche APIs, Visualisierung, JavaScript, Helper-Integrität, PHPDoc und Symcon-Strict-Prüfungen.
- Führe bei Änderungen zuerst den kleinsten betroffenen PHP-, JavaScript- oder Integrationstest und anschließend `php tests/run.php` aus. Netzwerk- und OAuth-Tests dürfen keine realen Konten oder Zugangsdaten voraussetzen, sofern nicht ausdrücklich eine Praxisabnahme beauftragt ist.
- Verbindliche CI-Checks sind `tests` und `style` aus `Symcon_ModuleCI` v1.0.0. Die maßgebliche CI-Testplattform verwendet PHP 8.5 mit `curl` und `dom`.
- Stub- und Offline-Tests ersetzen keine erforderliche Praxisprüfung gegen den betroffenen Kalenderanbieter. Reale Schreib-, Lösch-, OAuth-, Serien-, Aufgaben- und Wiederherstellungsabläufe werden bei entsprechendem Änderungsrisiko zusätzlich mit einem dafür vorgesehenen Testkonto abgenommen.
- Neue Testhilfen werden nicht dupliziert. Nutze die vorhandenen Fixtures, Provider-Fakes, Symcon-Stubs sowie die etablierten PHP-, Python-, Node- und CalDAV-Testeinstiege.

## Branch- und Release-Modell

- `dev` ist der dauerhafte Entwicklungs- und Integrationsbranch. `main` enthält kontrolliert freigegebene Produktstände; weitere Zweige und Worktrees bleiben für ihren jeweiligen Zweck isoliert.
- Laufende Änderungen und Helper-Sync-PRs zielen auf `dev`; `dev` wird nach einem Merge nicht gelöscht.
- Der Metadaten-Bot aktualisiert auf `dev` Version, Build und Datum in `library.json`. Pflege diese generierten Werte nicht manuell, außer die konkrete Metadaten- oder Release-Aufgabe verlangt es.
- Eine Übernahme von `dev` nach `main`, Providerfreigaben, Tags und Releases erfolgen nur auf ausdrücklichen Auftrag und nach erfolgreicher CI sowie den erforderlichen Praxisprüfungen. Veröffentlichte Tags und Releases werden nicht verschoben oder überschrieben.
