# Unveröffentlicht

## Neu

- Die neue **Kalender Einrichtung** führt schrittweise durch Anbieter, Konto,
  Kalenderauswahl und Kalender Ansicht. Bestehende Installationen können die
  bisherige manuelle Einrichtung weiter nutzen.
- Symcon-lokale Kalender benötigen kein externes Konto, unterstützen Termine und
  Aufgaben und können als ICS-Datei exportiert werden.
- Microsoft-365-Kalender können eine Microsoft-To-Do-Liste für neue native
  Aufgaben auswählen. Dafür ist `Tasks.ReadWrite` erforderlich. Achtung:
  Bestehende kalenderbasierte Aufgaben werden nicht automatisch übertragen.
- Dateianhänge können nach getrennter Freigabe in Kalender und Ansicht angezeigt
  und heruntergeladen werden. Für lokale Kalender und Google-Termine ist lokale
  Ablage in Symcon möglich; Microsoft 365, Microsoft To Do und Apple/CalDAV
  unterstützen bei freigegebenem Anbieterspeicher Upload und einzelnes Löschen.
  Google-Anbieteranhänge öffnen über bestätigte Links; ICS/Webcal bleibt lesend.
- Externe Kalender können in ihrer Kalenderinstanz eine eigene Anzeigefarbe erhalten. Das gilt auch für schreibgeschützte ICS-/Webcal-Feeds ohne Anbieterfarbe und wirkt in Kachel und IPSView.

- Optionale, frei sortierbare Buchstaben-Schaltflächen für häufig verwendete Kalenderansichten in der Kopfzeile von Kachel und IPSView. Das Formular zeigt fünf Zeilen; auf schmalen Anzeigen ordnet sich die Kopfzeile in zwei feste Reihen, die Tasten lassen sich bei Platzmangel seitlich wischen und die Datumsüberschrift wird numerisch verkürzt. Die Ansichtswahl bleibt clientlokal.
- Aufgabentermine erhalten bei lokalen Kalendern, CalDAV und Google eine
  anbieterbezogene Kennzeichnung. Microsoft-To-Do-Serien folgen den
  Wiederholungsregeln von Microsoft; Aufgabenstatus und Terminstatus bleiben
  unabhängig voneinander.

## Fixes

- Bereits gespeicherte lokale Kalenderfarben im alten Hexformat werden angezeigt,
  ohne die Benutzereinstellung beim Anwenden der Konfiguration zu überschreiben.
- CalDAV-Termine werden bei unterschiedlich kodierten Ressourcenadressen nicht
  mehrfach aus dem Cache übernommen.
- Verschobene IPSView-Ausgabeobjekte behalten ihre Identität; eingebettete
  Ansichten und Apps ermitteln den Aktionsendpunkt und zeigen Speicherfehler an.
- IPSView-Datumsfelder, Auswahllisten, Schriften und Dialoggrenzen bleiben in
  der App bedienbar; Datum und Uhrzeit werden vor dem Speichern übernommen.
- Gemeinsame Mausrad-, Scroll- und Frame-Unterstützung verbessert die Bedienung
  von Kachel und IPSView.
- Große Kalenderdaten bleiben beim Seitentransfer innerhalb des gemeinsamen
  Symcon-Instanzpufferlimits.
- Microsoft-To-Do-Serienaufgaben werden nach Änderungen erneut eingelesen;
  ein unbestätigter Anbieterzustand wird nicht als Erfolg ausgegeben.
- Die Kalenderansicht protokolliert Ausnahmen ohne ungeprüfte Anbietertexte,
  Termininhalte oder private URLs im Debug-Kanal.
- Die Kalender-Kopfzeile bleibt auf Tablets mit mehr als 560 CSS-Pixeln Kachelbreite einzeilig, auch bei größerer Schrift. Schnellansichten lassen sich bei Platzmangel seitlich wischen.
- Bei ausgeblendetem Instanztitel nutzt die Kalender-Kachel ab Symcon 9.1 den oberen Platz und hält einen sichtbaren Maximieren-Button frei. Schmale Kacheln mit Maximieren-Button, sichtbare Titel und Symcon 9.0 behalten den erforderlichen oberen Abstand.
- Google-Termine lassen sich beim Bearbeiten zwischen Uhrzeit und ganztägig umstellen, ohne dass Google den Startzeitpunkt zurückweist.
