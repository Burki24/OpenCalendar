# Debug-Ausgaben

Alle vier Module verwenden den in OpenHomeAlarm abgenommenen Debug-Aufbau über
`Symcon_ModuleHelper/DebugFlowHelper` und den nativen Symcon-Debug-Kanal.

## Aufbau und Umfang

Die Kategorie enthält Modulklasse, Ereignis und tatsächliche Funktion/Zeile.
Die strukturierte Nutzlast enthält `Instanz`, `Vorgang`, `Aufruf`, `Meldung` und
`Bezug`. Meldungen sind deutsch; technische Operationen und Identifiers bleiben
auffindbar. Eine Vorgangskennung verbindet verschachtelte Aufrufe innerhalb einer
Instanz. Ein späterer Timer-/Nachrichtenaufruf und die Gegenstelle erhalten eigene
Kennungen; eine instanzübergreifende Korrelation wird nicht behauptet.

Öffentliche Modul-Einstiege einschließlich Lebenszyklus, Aktionen, Timer,
Formulare und Exporte erhalten START und ENDE, auch bei vorzeitiger Rückgabe und
behandelbaren Ausnahmen. Entscheidende interne Kommunikationsgrenzen werden
zusätzlich erfasst. Reine private Berechnungen werden nicht einzeln protokolliert.
ENDE beschreibt den tatsächlichen Rückgabewert; Ja/Nein ist entsprechend dem
jeweiligen Funktionsvertrag zu lesen und keine pauschale Erfolgsbestätigung.
Funktionen ohne Rückgabewert behaupten keinen Erfolg.

SENDEN beschreibt Übergaben und Statusausgaben, EMPFANG verarbeitete Antworten.
AKTION erklärt Entscheidungen und angeforderte Bedienvorgänge. FEHLER nennt
sichere Ursachen, HTTP-Status oder Fehlerklasse und Quellstelle. Eine Annahme
belegt keine Wirkung auf der externen Gegenstelle oder Anzeige beim Benutzer.

## Schutz und Laufzeit

Zugangsdaten, Tokens, URLs, WebHook-Adressen, freie Anfrage-/Antwortkörper sowie
beliebige Exception-Texte werden nicht an Debug übergeben. Exporte werden nur
mit Art und Umfang beschrieben. Text-/HTML-Statuswerte bleiben geschützt.
Freigegebene numerische Mess-/Statuswerte erscheinen mit ihrem Ziel-Identifier.
Bestehende Helper-Diagnosen werden am nativen Ausgang in eine deutsche Hülle
überführt; ihre Rohdaten werden verworfen. Das gemeinsame Helper-Bundle wird
unverändert synchronisiert; lokale Bundle-Herkunft `working-tree` kennzeichnet
die noch nicht veröffentlichte zentrale Ergänzung ehrlich.

Die Nutzlast ist auf 4096 Bytes begrenzt. Debug erzeugt keine zusätzlichen
Netzwerkabrufe, Quellwertabfragen, Statusschreibvorgänge oder Benachrichtigungen.
Ein Fehler des Debug-Kanals darf Verarbeitung und Rückgabewerte nicht ändern.

## Nachweise und praktische Abnahme

`php tests/debug-output.php` prüft echte Moduleinstiege beziehungsweise den
bestehenden Parent-Client, unveränderte Rückgaben/Anzahl der Datenzugriffe,
Fehlerpfade, geschützte Inhalte, ältere Helper-Diagnosen, ausgeglichene
START-/ENDE-Paare und einen gestörten Debug-Kanal. Der Test ist im normalen
`php tests/run.php` registriert. Bestehende Fachtests sichern Verträge weiter ab.

Die praktische Symcon-Abnahme steht aus: normale und abgelehnte Bedienaktionen,
Anwenden/Neustart, Timerbetrieb, Schnittstellenfehler, große Datenmengen sowie
Lesbarkeit und Datenmenge im Debug-Fenster prüfen. Offline-Tests ersetzen keine
Bestätigung beim Kalenderanbieter, an der Berliner API oder in der Visualisierung.

## Kalendergrenzen

Konto, Konfigurator, Kalender und Ansicht erfassen Entdeckung, Synchronisation,
Provideroperationen, lokalen Schreibzugriff unter Sperre, OAuth-Einstiege,
seitengestützte Übertragungen und Visualisierungsübergaben. Kalenderinhalte,
Kalenderidentitäten, Serienbeispiele und Titel werden nicht ausgegeben.
Der dreistufige Seitentransfer und alle öffentlichen API-Rückgaben bleiben erhalten.
Seitenzugriffe besitzen START/ENDE; zusätzliche Gateway-Fachdetails bleiben bei
`ReadEventsTransferPage` unterdrückt. Bei großen Kalendern die Datenmenge prüfen.
