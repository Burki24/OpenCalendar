# Unveröffentlicht

## Neu

- Lokale Symcon-Kalender lassen sich ohne externes Konto nutzen; eigene Termine bleiben lokal und können als ICS-Datei exportiert werden.

- Microsoft-365-Kalender können eine Microsoft-To-Do-Liste auswählen. Neue Aufgabentermine werden dort als native Aufgaben gespeichert und mit ihrem Erledigungsstatus angezeigt. Achtung: Bestehende kalenderbasierte Aufgabentermine werden nicht automatisch übernommen; für die Funktion ist die Microsoft-Berechtigung `Tasks.ReadWrite` erforderlich.

- Aufgabentermine erhalten bei lokalen Kalendern, CalDAV und Google eine anbieterbezogene Kennzeichnung. Microsoft-To-Do-Serienaufgaben folgen der Wiederholungslogik von Microsoft; die Bearbeitung erklärt deren Auswirkung auf Folgetermine.

- Externe Kalender können in ihrer Kalenderinstanz eine eigene Anzeigefarbe erhalten. Das gilt auch für schreibgeschützte ICS-/Webcal-Feeds ohne Anbieterfarbe und wirkt in Kachel und IPSView.

- Optionale, frei sortierbare Buchstaben-Schaltflächen für häufig verwendete Kalenderansichten in der Kopfzeile von Kachel und IPSView. Das Formular zeigt fünf Zeilen; auf schmalen Anzeigen ordnet sich die Kopfzeile in zwei feste Reihen, die Tasten lassen sich bei Platzmangel seitlich wischen und die Datumsüberschrift wird numerisch verkürzt. Die Ansichtswahl bleibt clientlokal.

- Gemeinsame Mausrad-, Scroll- und Frame-Unterstützung aus Symcon_ModuleHelper eingebunden; IPSView-Ereignisse mit umadressiertem Ziel werden zentral behandelt.

- Alle vier Module erklären Abläufe im einheitlichen deutschen Debug-Aufbau mit Funktion, Zeile, Vorgangskennung, Ergebnis sowie geschützten Schnittstellen- und Fehlerdiagnosen.

## Fixes

- Bereits gespeicherte lokale Kalenderfarben im älteren Hexformat bleiben beim Lesen und im Farbformular erhalten, ohne die gespeicherte Einstellung automatisch zu überschreiben.
- CalDAV-Termine werden bei unterschiedlich kodierten Ressourcenadressen nicht mehr doppelt aus dem Cache übernommen.
- Verschobene IPSView-Ausgabeobjekte behalten ihre Identität; eingebettete Ansichten und Apps ermitteln den Aktionsendpunkt wieder zuverlässig und zeigen Speicherfehler im Dialog an.
- IPSView-Datumsfelder, Auswahllisten, Schriften und Dialoggrenzen funktionieren auch in der App; Änderungen an Datum und Uhrzeit werden vor dem Speichern übernommen.
- Große Kalenderdaten überschreiten beim Seitentransfer nicht mehr das gemeinsame Limit der Symcon-Instanzpuffer.
- Microsoft-To-Do-Serienaufgaben werden nach Änderungen erneut eingelesen, damit Folgeaufgaben nicht doppelt erscheinen; bei unbestätigtem Anbieterzustand wird keine Erfolgsmeldung ausgegeben.
- Die Kalender-Kopfzeile bleibt auf Tablets mit mehr als 560 CSS-Pixeln Kachelbreite einzeilig, auch bei größerer Schrift. Schnellansichten lassen sich bei Platzmangel seitlich wischen.
- Bei ausgeblendetem Instanztitel nutzt die Kalender-Kachel ab Symcon 9.1 den oberen Platz und hält einen sichtbaren Maximieren-Button frei. Schmale Kacheln mit Maximieren-Button, sichtbare Titel und Symcon 9.0 behalten den erforderlichen oberen Abstand.
- Google-Termine lassen sich beim Bearbeiten zwischen Uhrzeit und ganztägig umstellen, ohne dass Google den Startzeitpunkt zurückweist.
