# Kalender Konfigurator

Der **Kalender Konfigurator** zeigt die Kalender des aktuell verbundenen
**Kalender Kontos** an. Aus dieser Liste erstellt er vollständig konfigurierte
**Kalender-Instanzen** mit korrekter Identität, Kontoverbindung, Farbe und
Schreibberechtigung.

## Voraussetzungen

- Symcon ab Version 9.0
- ein eingerichtetes und aktives **Kalender Konto**
- eine erfolgreiche Kontosynchronisation

## Einrichtung

1. Zuerst das gewünschte **Kalender Konto** vollständig konfigurieren.
2. Im Konto **Verbindung testen** und anschließend **Jetzt synchronisieren**
   ausführen.
3. Über **Instanz hinzufügen** einen **Kalender Konfigurator** erstellen und das
   Kalender Konto als übergeordnete Instanz auswählen.
4. Falls der Konfigurator bereits vorhanden ist, über das Zahnrad prüfen, welches
   Konto als Gateway verbunden ist. Bei Bedarf **Gateway ändern** verwenden.
5. Den Konfigurator öffnen und **Kalender aktualisieren** ausführen.
6. Kontrollieren, ob die erwarteten Kalender angezeigt werden.
7. Einen einzelnen Kalender über **Erstellen** oder alle fehlenden Kalender über
   **Alle erstellen** anlegen.

Die erzeugten Kalender-Instanzen können anschließend im Objektbaum verschoben
oder umbenannt werden. Ihre technische Kalender- und Kontozuordnung bleibt dabei
erhalten.

## Funktionsumfang

- lädt ausschließlich die Kalender des aktuell verbundenen Kontos
- zeigt Kalendername, Farbe, Zugriffsart und vorhandene Instanz an
- erstellt einzelne oder alle noch nicht angelegten Kalender
- übernimmt Kalender-ID, Anbieter-ID, Farbe und Schreibrechte
- übernimmt bei ICS/Webcal den voreingestellten Aktualisierungsplan
- verbindet neue Kalender-Instanzen mit dem aktuellen Kalender Konto
- erkennt nur bestehende Kalender-Instanzen, die mit demselben Konto verbunden
  sind
- trennt den zwischengespeicherten Kalenderfund sicher nach Kalenderkonto

## Ein oder mehrere Konfiguratoren

### Empfohlen: Ein Konfigurator je Konto

Bei mehreren Kalenderkonten ist je Konto ein eigener Konfigurator am
übersichtlichsten. Der Name des Konfigurators sollte das Konto erkennen lassen,
beispielsweise „Kalender – Privat“ oder „Kalender – Firma“.

### Unterstützt: Einen Konfigurator wiederverwenden

Ein eigener Konfigurator je Konto ist keine technische Pflicht. Ein vorhandener
Konfigurator kann nacheinander für mehrere Konten verwendet werden:

1. Das Zielkonto vollständig konfigurieren und synchronisieren.
2. Im Kalender Konfigurator über das Zahnrad **Gateway ändern** wählen.
3. Das gewünschte **Kalender Konto** als übergeordnete Instanz auswählen.
4. Die Änderung übernehmen und den Konfigurator erneut öffnen.
5. Zwingend **Kalender aktualisieren** ausführen.
6. Prüfen, ob die Liste zum ausgewählten Konto gehört, und erst danach die
   gewünschten Kalender erstellen.

Beim Gatewaywechsel bleiben bereits erzeugte Kalender-Instanzen mit ihrem
ursprünglichen Konto verbunden. Sie werden in der aktuellen Liste nicht mehr
angezeigt und erscheinen wieder, wenn der Konfigurator erneut mit ihrem Konto
verbunden wird. Ein Fundcache eines vorherigen Kontos wird beim Wechsel
verworfen.

Symcon erlaubt das Austauschen einer übergeordneten Instanz. Gleichzeitig dürfen
Konfiguratoren nur Instanzen ihres aktuell verbundenen Splitters beziehungsweise
Gateways verwalten. OpenCalendar setzt diese Trennung um, indem es Kalender und
bestehende Instanzen immer gegen das aktuelle Kalender Konto abgleicht:

- [Symcon: Instanzen und übergeordnete Instanzen](https://www.symcon.de/de/service/dokumentation/grundlagen/instanzen/)
- [Symcon: Konfigurationselement Configurator](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/konfigurationsformulare/configurator/)
- [Symcon: Anforderungen beim Store-Review](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/)

## Kalender-Instanzen nicht manuell verbinden

Die Wiederverwendung bezieht sich ausschließlich auf das Gateway des
**Kalender Konfigurators**.

Kalender-Instanzen selbst sollen immer aus der aktuellen Konfiguratorliste
erstellt werden. Manuell angelegte, kopierte oder lediglich über
**Gateway ändern** mit einem Konto verbundene Kalender-Instanzen erhalten nicht
automatisch den richtigen Kalendernamen und die vollständige Kalenderidentität.
Bei Konten mit mehreren Kalendern wäre anschließend keine eindeutige Zuordnung
möglich.

## Fehlerbehebung

Problem | Prüfung
--- | ---
Es werden keine Kalender angezeigt | Im Kalender Konto zuerst **Verbindung testen** und **Jetzt synchronisieren** ausführen, danach im Konfigurator **Kalender aktualisieren**
Der Konfigurator meldet eine fehlende Kontoverbindung | Über das Zahnrad und **Gateway ändern** ein aktives Kalender Konto auswählen
Nach einem Gatewaywechsel ist die Liste leer | Das neue Konto synchronisieren und anschließend **Kalender aktualisieren**
Ein bereits angelegter Kalender fehlt in der Liste | Prüfen, ob der Konfigurator aktuell mit demselben Kalender Konto wie die Kalender-Instanz verbunden ist
Ein Kalender ist nur lesbar | Die vom Anbieter gemeldeten Kalenderrechte prüfen; ICS/Webcal ist grundsätzlich schreibgeschützt
