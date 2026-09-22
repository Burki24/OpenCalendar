# Lokale Dateianhänge sichern und aufräumen

Diese Verwaltungsfunktionen gehören zur Vorbereitung der Dateianhänge in
`dev_9.1`. Sie stehen vertrauenswürdigen Symcon-Skripten über die Kalender-Instanz
zur Verfügung. Eine Bedienoberfläche in IPSView oder der Kachel ist noch nicht
freigeschaltet. Google und die Übertragung von Dateien zu Kalenderanbietern sind
nicht Bestandteil dieses Schritts.

## Zugriff und Aufbewahrung

Die Verwaltung funktioniert auch bei deaktivierten Anhangsberechtigungen, einer
inaktiven Kalender-Instanz und nach dem Löschen des zugehörigen Termins. So bleiben
lokale Originaldateien sicherbar und gezielt löschbar. Dieser Zugriff setzt die
Berechtigung voraus, vertrauenswürdige Symcon-Skripte auszuführen. Der gemeinsame
Ansichts-Token gewährt diese Verwaltungsrechte nicht.

Die Originale liegen im persistenten Attribut `LocalAttachmentOriginals` der
Kalender-Instanz. „Cache leeren“ und das Löschen eines Termins entfernen sie nicht.
Das Löschen der Kalender-Instanz kann dagegen auch ihren Anhangsspeicher entfernen.
Deshalb vorher eine Sicherung erstellen. Ein ICS-Export enthält diese lokalen
Dateien nicht.

Sicherungen enthalten Dateiinhalte und interne Zuordnungen im Klartext bzw.
Base64, nicht verschlüsselt. Einen privaten Speicherort mit passenden
Zugriffsrechten wählen, bei Bedarf durch das verwendete Sicherungssystem
verschlüsseln und dessen Aufbewahrung festlegen. Keine Ablage in öffentlich
erreichbaren Medien-/Webverzeichnissen, Debug-Ausgaben oder Visualisierungswerten.

## Bestand prüfen

`IPSKAL_GetLocalAttachmentInventory($calendarID)` liefert JSON mit:

- `revision`: Prüfsumme des aktuellen Gesamtbestands für die Löschbestätigung;
- `totalBytes`: Größe der gespeicherten Originaldateien insgesamt;
- `files`: Dateiliste mit `id`, `name`, `revision` der Datei und `size`.

Die Liste enthält keine Dateiinhalte, Eigentümerschlüssel oder Upload-Kennungen.
Sie enthält auch Dateien gelöschter Termine. Sie kennzeichnet Dateien aber nicht
automatisch als verwaist: Die Zuordnung wird nicht anhand von Titel oder Datum
erraten. Dateinamen können selbst vertrauliche Informationen enthalten.

## Vollständige Sicherung auslesen

1. `IPSKAL_BeginLocalAttachmentBackup($calendarID)` aufrufen und JSON dekodieren.
   Die Antwort enthält `Token`, `PageCount`, `ExpiresAt`, `Format`, `Version`,
   `CalendarInstanceID`, `Bytes` und `SHA256`.
2. Mit `IPSKAL_ReadLocalAttachmentBackupPage($calendarID, $token, $page)` alle
   Seiten von `0` bis `PageCount - 1` auslesen. Jede Antwort ist JSON. Die Werte
   aus `Items` einzeln strikt mit Base64 dekodieren und in dieser Reihenfolge
   zu einer Datei zusammensetzen. `Complete` ist nur auf der letzten Seite wahr.
3. Größe und SHA-256 der zusammengesetzten Datei mit `Bytes` und `SHA256` aus
   Schritt 1 vergleichen. Nur bei Übereinstimmung ist die Sicherung vollständig.
   Die Prüfsumme erkennt Übertragungsfehler; sie ersetzt keine geschützte Ablage.
4. `IPSKAL_FinishLocalAttachmentBackup($calendarID, $token)` in einem `finally`
   aufrufen, auch bei einem Abbruch. Dadurch wird nur die temporäre Übertragung
   entfernt, nicht der Originalbestand.

Die Übertragung verfällt nach fünf Minuten. Eine unterbrochene oder nach einem
Symcon-Neustart ungültige Übertragung neu beginnen; Seiten verschiedener Tokens
nicht mischen. Ein bereits gestarteter Export bildet immer seinen Startbestand
ab, auch wenn danach Anhänge hinzugefügt oder gelöscht werden. Die einzelnen
Antworten bleiben unter 200 KiB. Temporäre Übertragungsdateien sind verschlüsselt;
verwaiste abgelaufene Dateien werden beim nächsten Start einer Übertragung durch
den Helper aufgeräumt.

Das Dateiformat ist der Speicher-Snapshot mit `version: 1` und `records`.
Jeder Datensatz enthält Originalname, Datei-ID, interne Zuordnung, Größe,
Inhaltsprüfsumme und den Base64-kodierten Originalinhalt. Die getrennt gelieferte
Kalender-Instanz-ID mit der Sicherung aufbewahren. Kalendertermine und deren
Originaldaten sind separat zu sichern. Eine Übernahme in andere Kalender-Instanzen
oder eine automatische Wiederherstellung über die Oberfläche ist noch nicht
implementiert. Einzelne Dateiinhalte lassen sich aus den `content`-Feldern
dekodieren; dabei Dateinamen aus Sicherungen niemals ungeprüft als Pfade verwenden.

## Gezielt löschen

Nach Prüfung des Bestands und einer gegebenenfalls erforderlichen Sicherung:

`IPSKAL_DeleteLocalAttachmentOriginals($calendarID, $selectionJSON, $inventoryRevision)`

`$selectionJSON` ist eine explizite JSON-Liste der zu löschenden Datei-IDs.
`$inventoryRevision` ist die **Gesamtbestandsrevision** aus der zuvor geprüften
Liste, nicht die Revision einer einzelnen Datei. Die Rückgabe ist die Anzahl
der nach erfolgreicher Speicherung gelöschten Originale.

Die Löschung ist endgültig im aktuellen lokalen Bestand. Unbekannte oder doppelte
IDs, eine leere Liste und eine zwischenzeitliche Bestandsänderung führen zum
Abbruch ohne Teillöschung. Bei einer Änderung zunächst den Bestand erneut laden
und die Auswahl neu prüfen; nicht blind mit einer neuen Revision wiederholen.
Andere lokale Dateien und Dateien beim Anbieter bleiben erhalten. Bestehende
Sicherungen und bereits laufende Sicherungsübertragungen werden nicht gelöscht.

Beschädigte Originaldaten werden nicht automatisch zurückgesetzt. Bei einer
solchen Fehlermeldung den vorhandenen Bestand erhalten und die Wiederherstellung
aus einer geprüften Sicherung vorbereiten.
