# Projektregeln für OpenCalendar 3.0 / Symcon 9.1

Lies zuerst `../SymconDevelopment/AGENTS.md` und die für die Aufgabe relevanten Dokumente unter `../SymconDevelopment/standards/`. Diese zentrale Basis gilt mit den folgenden projektspezifischen und branchspezifischen Ergänzungen.

## Projektkontext

- Dieser Worktree gehört zum Branch `dev_9.1` und entwickelt OpenCalendar 3.0 für IP-Symcon ab Version 9.1 mit PHP 8.5. Er wird nicht stillschweigend mit dem abweichenden 9.0-Zweig `dev` gleichgesetzt.
- OpenCalendar besitzt hier fünf gekoppelte Module: `Kalender Einrichtung` führt als Discovery-Modul durch die Einrichtung, `Kalender Konto` stellt Provider und Gateway bereit, `Kalender Konfigurator` erzeugt vollständig zugeordnete Kalenderinstanzen, `Kalender` synchronisiert und bearbeitet einzelne Kalender, und `Kalender Ansicht` aggregiert sie für PHP-API, HTML-SDK-Kachel und IPSView.
- Unterstützte Datenquellen sind lokale Symcon-Kalender, Apple/iCloud und generisches CalDAV, Google Calendar, Microsoft 365/Outlook.com sowie schreibgeschützte ICS-/Webcal-Quellen. Änderungen an Provider-, OAuth-, Netzwerk-, Schreib-, Lösch-, Serien-, Aufgaben- oder Zeitzonenlogik sind daten- und kompatibilitätskritisch.
- OpenCalendar 3.0 verwaltet zusätzlich lokale und providerseitige Dateianhänge. Zugriff, Eigentümeridentität, Upload, Download, Löschung, Transport, Sicherung und Berechtigungen sind Sicherheits- und Datenschutzgrenzen.
- Providerneutrale Verträge und Fachlogik liegen unter `libs/`; modulspezifische Lebenszyklen, Formulare und öffentliche Methoden verbleiben in den fünf Modulverzeichnissen.
- Dateien unter `libs/helper` sind synchronisierte Kopien aus dem Branch `dev_9.1` von `Symcon_ModuleHelper`. Ändere sie nicht lokal und lege keine Helper-Duplikate an; Änderungen erfolgen in der zentralen Quelle und kommen über den konfigurierten Helper-Sync.
- `.style` und `tests/stubs` sind offizielle Symcon-Git-Submodule. Ändere deren Inhalte oder Zeiger nur auf ausdrücklichen Auftrag.

## Vor jeder Änderung

1. Lies `README.md`, die README des betroffenen Moduls, `library.json`, dessen `module.json`, die betroffenen Quellen unter `libs/` sowie die zugehörigen Tests.
2. Lies bei Discovery-Änderungen zusätzlich `Kalender Einrichtung/README.md` und prüfe Erzeugung, Wiederverwendung, Rücknahme und Abschlussprüfung aller beteiligten Instanzen. Teilfehler dürfen keine unklare oder doppelte Kalenderstruktur hinterlassen.
3. Lies bei Anhangsänderungen die Berechtigungs-, Transport-, Eigentümer- und Speicherklassen sowie `docs/attachments-administration.md`, `docs/attachments-privacy-plan.md` und die betroffenen Abschnitte in `PRIVACY.md`. Lokale Originale, Providerdateien und externe Links bleiben klar getrennt.
4. Lies bei Datenfluss-, Provider- oder Synchronisationsänderungen die Verträge zwischen Konto, Konfigurator und Kalender gemeinsam. Erhalte insbesondere Parent-/Child-GUIDs, Request-Namen, Kalenderidentitäten und den dreistufigen Seitentransfer für große Datenmengen.
5. Prüfe die öffentlichen Präfixe und Skript-APIs `IPSKALDISCOVERY_*`, `IPSKALACC_*`, `IPSKALCFG_*`, `IPSKAL_*` und `IPSKALVIEW_*` auf Rückwärtskompatibilität. Lebenszyklusmethoden, Wizard-Callbacks und interne Visualisierungsaktionen sind davon getrennt zu bewerten.
6. Prüfe Provideränderungen mindestens gegen lokale Kalender, CalDAV/iCloud, Google, Microsoft und die schreibgeschützten ICS-Pfade, soweit der gemeinsame Vertrag betroffen ist. Providerspezifische Sonderfälle dürfen nicht unbemerkt in den gemeinsamen Vertrag einsickern.
7. Prüfe bei Termin- und Serienlogik RFC 5545, lokale Zeitzonen und Sommerzeit, exklusive Ganztags-Enddaten, Ausnahmen, ETags und stabile Ressourcenidentitäten. Ein unklarer Remotezustand oder Abfragefehler ist keine bestätigte Löschung.
8. Prüfe bei Visualisierungsänderungen die native HTML-SDK-Kachel und die IPSView-Ausgabe gemeinsam. Beide verwenden dieselben Assets und Bedienverträge; die IPSView-Brücke bleibt tokengebunden und akzeptiert nur freigegebene Aktionen.
9. Bewahre fremde Änderungen und bestehende Worktrees. Übertrage Änderungen nicht ohne Auftrag zwischen `dev_9.1`, `dev` oder anderen Arbeitszweigen.

## Umsetzung, Migration und Datenschutz

- Erhalte bestehende Konten, Kalenderzuordnungen, Provideridentitäten, öffentliche JSON-Formate, Properties, Statuswerte, Caches und persistierte Daten. Änderungen müssen den dokumentierten Upgradepfad von 2.1 auf 3.0 sowie ApplyChanges- und Neustartverhalten bewahren.
- `Create()`, `ApplyChanges()`, Migration, Discovery, Synchronisation und Wiederherstellung bleiben idempotent und neustartsicher. Bestätigte Schreibvorgänge dürfen durch nachfolgende Synchronisationsfehler nicht verloren gehen.
- Validiere externe URLs, HTTP-Antworten, OAuth-Daten, iCalendar-Inhalte, Anhänge und Providerantworten an der Systemgrenze. Bestehende Größen-, Origin-, TLS-, Zeitlimit-, Retry-, Cache- und Berechtigungsregeln werden nicht umgangen.
- Passwörter, OAuth-Tokens, geheime Feed-Adressen, WebHook-Tokens, Anhangsinhalte und Kalenderinhalte erscheinen weder in unbereinigten Debugmeldungen noch in öffentlichen Status- oder Rückgabewerten.
- Anhangszugriff bleibt standardmäßig verweigert, bis Kalender, Operation und Speicherort ausdrücklich freigegeben sind. Löschungen benötigen eine eindeutige Auswahl und aktuellen Revisionsnachweis; beschädigte oder unklare Bestände werden nicht automatisch bereinigt.
- Anhangssicherungen können vertrauliche Dateinamen und Inhalte enthalten. Sie werden weder in Repository, Debugausgaben, Medien-/Webverzeichnisse noch Visualisierungswerte geschrieben. Eine Wiederherstellung oder providerübergreifende Übertragung wird nicht erfunden, wenn sie nicht implementiert und beauftragt ist.
- Sichtbares Verhalten wird im selben Arbeitspaket in der README des betroffenen Moduls und bei übergreifendem Verhalten zusätzlich in der Root-README dokumentiert. Änderungen an Datenverarbeitung, Anhängen, externen Diensten oder Berechtigungen werden außerdem gegen `PRIVACY.md` und `TERMS.md` geprüft.
- Änderungen an Formularen oder sichtbaren Texten halten `form.json`, `locale.json` und Dokumentation konsistent. PHP-, JavaScript-, Discovery- und IPSView-Verträge werden gemeinsam aktualisiert, wenn sie dieselbe Funktion abbilden.

## Qualität und CI

- Der lokale Gesamteinstieg ist `php tests/run.php`. Er umfasst Discovery, Upgrade und Symcon-9.1-Runtime, Anhangssicherheit, Provider- und Datenflusslogik, Synchronisation und Wiederherstellung, Wiederholungsregeln und Zeitzonen, Aufgaben, öffentliche APIs, Visualisierung, JavaScript, Helper-Integrität, PHPDoc und Symcon-Strict-Prüfungen.
- Führe bei Änderungen zuerst den kleinsten betroffenen PHP-, JavaScript- oder Integrationstest und anschließend `php tests/run.php` aus. Der Lasttest verwendet lokal bis zu 512 MiB PHP-Speicher.
- Verbindliche CI-Checks sind `tests` und `style` aus `Symcon_ModuleCI`. Die Testplattform verwendet PHP 8.5 mit `curl` und `dom`; der Metadaten-Workflow nutzt zusätzlich `style-fix@v1.1.0` und prüft danach erneut. Diese Automatik ersetzt keine lokale Diff- und Stylekontrolle.
- `tests/live-provider-e2e.php` ist ein destruktiver Opt-in-Test gegen reale Kalenderanbieter und gehört nicht zu `tests/run.php`. Führe ihn nur auf ausdrücklichen Auftrag, mit dediziertem Testkalender, bewusst bereitgestellten kurzlebigen Zugangsdaten und der geforderten Schreibbestätigung aus; zurückbleibende Testtermine sind trotz Cleanup möglich.
- Stub- und Offline-Tests ersetzen keine erforderliche Praxisprüfung gegen Symcon 9.1 oder den betroffenen Kalenderanbieter. Reale Schreib-, Lösch-, OAuth-, Serien-, Aufgaben-, Anhangs- und Wiederherstellungsabläufe werden bei entsprechendem Änderungsrisiko zusätzlich kontrolliert abgenommen.
- Neue Testhilfen werden nicht dupliziert. Nutze vorhandene Fixtures, Provider-Fakes, Symcon-Stubs sowie die etablierten PHP-, Python-, Node- und CalDAV-Testeinstiege.

## Branch- und Release-Modell

- Dieser dedizierte Worktree `OpenCalendar-dev_9.1-port` arbeitet auf dem dauerhaften Branch `dev_9.1` und pusht ausschließlich nach `origin/dev_9.1`, sofern der Nutzer nichts anderes beauftragt.
- `dev_9.1` ist die Integrationslinie für OpenCalendar 3.0 / Symcon 9.1. Der stark abweichende Branch `dev` bleibt als eigene Entwicklungs- beziehungsweise 9.0-Linie getrennt; Änderungen werden nicht automatisch gemergt, rebased oder cherry-pickt.
- Helper-Sync-PRs für diese Linie zielen auf `dev_9.1`. Der Branch bleibt nach dem Merge bestehen.
- Der Metadaten-Bot aktualisiert auf `dev_9.1` Version, Build und Datum in `library.json` und kann zuvor mechanische Style-Korrekturen anwenden. Pflege diese generierten Werte nicht manuell und prüfe Bot-Diffs, bevor du sie übernimmst.
- Eine Übernahme nach `main`, die Freigabe von OpenCalendar 3.0, Tags und Releases erfolgen nur auf ausdrücklichen Auftrag und nach erfolgreicher CI, Upgradeprüfung, Symcon-9.1-Abnahme und den erforderlichen Providerprüfungen. Veröffentlichte Tags und Releases werden nicht verschoben oder überschrieben.
