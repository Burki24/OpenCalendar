# OpenCalendar

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-555555.svg)](https://www.symcon.de)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-2.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-brightgreen.svg)](https://www.symcon.de)<br>
[![License](https://img.shields.io/badge/License-PolyForm--Noncommercial--1.0.0-brightgreen.svg)](LICENSE)
[![Check Style](https://github.com/Burki24/OpenCalendar/actions/workflows/style.yml/badge.svg?branch=main)](https://github.com/Burki24/OpenCalendar/actions/workflows/style.yml?query=branch%3Amain)
[![Run Tests](https://github.com/Burki24/OpenCalendar/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/Burki24/OpenCalendar/actions/workflows/tests.yml?query=branch%3Amain)

OpenCalendar ist eine Anwendung für Symcon, mit der Nutzer ihre Online-Kalender verbinden, synchronisieren, anzeigen und bearbeiten können. Unterstützt werden Apple iCloud, Google Calendar, Microsoft 365/Outlook.com, generische CalDAV-Server sowie schreibgeschützte ICS-/Webcal-Abonnements.
Die gefundenen Kalender können einzeln synchronisiert, in einer gemeinsamen Kachel angezeigt und optional als interaktive HTML-Seite in IPSView verwendet werden.

Umfangreiche Terminlisten werden intern seitenweise zwischen Konto, Kalender und
Kalenderansicht übertragen. Online-ICS-/Webcal-Feeds und lokal importierte
ICS-Dateien dürfen jeweils höchstens 16 MiB groß sein. Innerhalb dieser Grenze
müssen umfangreiche Kalenderdateien nicht manuell aufgeteilt werden.

**Datenschutz:** [Datenschutzhinweise](PRIVACY.md)
**Nutzungsbedingungen:** [Nutzungsbedingungen](TERMS.md)

## Voraussetzungen

- Symcon ab Version 9.0
- Netzwerkzugriff des Symcon-Servers auf den jeweiligen Kalenderdienst
- für Google und Microsoft eine aktive Symcon-Connect-Verbindung; eine eigene
  OAuth-Client-ID oder ein eigener Clientschlüssel ist nicht erforderlich
- für Apple iCloud ein anwendungsspezifisches Apple-Passwort

## Schnellstart

1. OpenCalendar über den Symcon Module Store installieren.
2. Über **Instanz hinzufügen** eine Instanz **Kalender Konto** anlegen.
3. Den gewünschten Anbieter auswählen und die angezeigten Zugangsdaten beziehungsweise den OAuth-Login einrichten.
4. **Verbindung testen** ausführen. Erst bei erfolgreichem Test die Kontokonfiguration übernehmen.
5. **Jetzt synchronisieren** ausführen, damit die verfügbaren Kalender gefunden werden.
6. Über **Instanz hinzufügen** einen **Kalender Konfigurator** anlegen. Im Dialog das zuvor eingerichtete **Kalender Konto** als übergeordnete Instanz wählen. Ist bereits ein Konfigurator vorhanden, lässt sich die Verbindung über das Zahnrad und **Gateway ändern** kontrollieren oder anpassen.
7. Den Konfigurator öffnen und **Kalender aktualisieren** verwenden. In der Liste die gewünschten Kalender über **Erstellen** beziehungsweise **Alle erstellen** anlegen.
8. Optional eine Instanz **Kalender Ansicht** erstellen, die gewünschten Kalenderinstanzen auswählen und die Ansicht in der Kachelvisualisierung oder in IPSView platzieren.

Die ausführlichen Einstellungen der Anbieter sind in der Dokumentation des
[Kalender Kontos](Kalender%20Konto) beschrieben.

## Anbieter im Überblick

Anbieter | Benötigte Angaben | Zugriff
--- | --- | ---
Apple iCloud | Apple-ID und anwendungsspezifisches Passwort | Lesen und Schreiben entsprechend den Kalenderrechten
Google Calendar | Öffentlich freigegebene und von Google verifizierte OpenCalendar-OAuth-Anwendung über Symcon | Lesen und Schreiben entsprechend den Google-Kalenderrechten
Microsoft 365/Outlook.com | Zentrale OpenCalendar-OAuth-Anwendung über Symcon | Lesen und Schreiben entsprechend den Microsoft-Kalenderrechten
CalDAV | Server-URL, Benutzername und Passwort | Lesen und Schreiben entsprechend den Serverrechten
ICS/Webcal | Eine oder mehrere Feed-URLs (öffentlich, Zugriffsschlüssel oder Benutzername/Passwort) oder lokale ICS-Dateien vom Arbeitsrechner | Schreibgeschützt

## Kalender immer über den Konfigurator anlegen

**Kalender-Instanzen sollen ausschließlich aus der Liste des Kalender
Konfigurators erstellt werden.**

Der Konfigurator übernimmt automatisch den Kalendernamen, die interne
Kalender-ID, die Anbieter-ID, die Farbe, die Schreibrechte und die Verbindung
zum aktuell gewählten Kalender Konto. Manuell angelegte oder kopierte
Kalender-Instanzen besitzen diese vollständige Identität nicht. Sie dürfen daher
nicht lediglich über **Gateway ändern** mit einem Konto verbunden werden.

Nach der Erstellung dürfen Kalender-Instanzen im Objektbaum beliebig verschoben
oder umbenannt werden. Ihre technische Zuordnung bleibt dabei erhalten.

## Mehrere Kalenderkonten

Für mehrere Konten gibt es zwei unterstützte Arbeitsweisen:

- **Ein Konfigurator je Konto** ist die empfohlene und übersichtlichste Variante.
  Jeder Konfigurator bleibt dauerhaft mit seinem Kalender Konto verbunden.
- **Ein gemeinsamer Konfigurator** kann nacheinander für mehrere Konten verwendet
  werden. Dazu im Konfigurator über das Zahnrad **Gateway ändern** wählen, das
  gewünschte Kalender Konto verbinden und anschließend zwingend
  **Kalender aktualisieren** ausführen.

Der Konfigurator zeigt immer nur Kalender und bestehende Kalender-Instanzen des
aktuell verbundenen Kontos. Bereits erzeugte Kalender-Instanzen bleiben beim
Wechsel mit ihrem ursprünglichen Konto verbunden und erscheinen wieder, sobald
der Konfigurator erneut mit diesem Konto verbunden wird.

Diese Wiederverwendung entspricht dem von Symcon vorgesehenen Austausch einer
übergeordneten Instanz. Die Kalender-Instanzen selbst werden weiterhin nur aus
der aktuellen Konfiguratorliste erstellt. Weitere Hintergründe enthält die
[Dokumentation des Kalender Konfigurators](Kalender%20Konfigurator).

## Google-Serientermine

In beschreibbaren Google-Kalendern unterstützt OpenCalendar Serientermine
vollständig im gemeinsamen Dialog-Workflow der Kachelvisualisierung und IPSView.

- Neue Serien können **täglich, wöchentlich, monatlich oder jährlich** angelegt
  werden. Unterstützt werden ein frei wählbares Intervall, bei Wochenserien
  mehrere Wochentage sowie die Endarten **Nie**, **Nach Anzahl** und
  **Am Datum**.
- Bei zeitgebundenen Serien wird die Zeitzone des Google-Kalenders verwendet,
  damit die lokale Uhrzeit auch über Sommer-/Winterzeitwechsel erhalten bleibt.
- Einzelne Serienvorkommnisse können bearbeitet und gelöscht werden.
- **Diesen und alle folgenden Termine** können gemeinsam bearbeitet oder
  gelöscht werden. Beim Bearbeiten wird die Serie am gewählten Vorkommnis
  geteilt; der zurückliegende Teil bleibt unverändert. Beim Löschen endet die
  Serie unmittelbar vor dem gewählten Vorkommnis.
- Die **gesamte Serie** kann bearbeitet oder gelöscht werden.
- Komplexe Wiederholungsregeln, die OpenCalendar nicht verlustfrei in seinem
  Serieneditor abbilden kann, werden nicht automatisch geteilt oder vereinfacht.

## Microsoft-Serientermine

In beschreibbaren Microsoft-365-/Outlook.com-Kalendern können neue Terminserien
mit demselben gemeinsamen Serieneditor wie bei Google angelegt werden. Unterstützt
werden tägliche, wöchentliche, monatliche und jährliche Wiederholungen, Intervalle,
mehrere Wochentage sowie die Endarten **Nie**, **Nach Anzahl** und **Am Datum**.
Für zeitgebundene Serien wird die vom Client beziehungsweise über die PHP-API
übergebene Zeitzone verwendet, damit Microsoft Graph die lokale Uhrzeit der Serie
erhält. Einzelne Vorkommnisse bestehender Microsoft-Serien können gezielt
bearbeitet oder gelöscht werden, ohne die übrigen Vorkommnisse der Serie zu ändern.
Auch die **gesamte Microsoft-Serie** kann bearbeitet oder gelöscht werden. Beim
Bearbeiten wird der Serien-Master geladen; Wiederholungsmuster, die dem gemeinsamen
OpenCalendar-Serieneditor entsprechen, können dabei ebenfalls geändert werden.
**Diesen und alle folgenden Termine** wird wie bei Google durch ein sicheres Teilen
der Serie umgesetzt: Der bisherige Serienteil endet unmittelbar vor dem gewählten
Vorkommnis und beim Bearbeiten wird ab dort eine neue Serie angelegt. Bei nummerierten
Serien wird die verbleibende Anzahl übernommen. Beim Löschen wird nur der vordere
Serienteil behalten. Bereits vorhandene Ausnahmen ab dem Trennpunkt werden beim
Bearbeiten nicht in den neuen Serienteil übernommen. Komplexere Microsoft-Muster
bleiben erhalten, werden aber nicht verlustbehaftet im Serieneditor vereinfacht.

## Apple/iCloud- und CalDAV-Serientermine

Beschreibbare Apple-iCloud- und generische CalDAV-Kalender können neue Terminserien
mit demselben gemeinsamen Serieneditor wie Google und Microsoft anlegen. Unterstützt
werden tägliche, wöchentliche, monatliche und jährliche Wiederholungen, Intervalle,
mehrere Wochentage sowie die Endarten **Nie**, **Nach Anzahl** und **Am Datum**.

OpenCalendar speichert die Serie als RFC-5545-`RRULE` im CalDAV-Kalenderobjekt.
Zeitgebundene Serien werden mit der lokalen Zeitzone und einem passenden
`VTIMEZONE`-Block geschrieben, damit die lokale Uhrzeit auch über
Sommer-/Winterzeitwechsel erhalten bleibt. Apple iCloud verwendet denselben
CalDAV-Pfad wie andere Server.

Zusätzlich können einzelne Vorkommnisse bestehender Apple-iCloud- und
CalDAV-Serien bearbeitet und gelöscht werden. Beim Bearbeiten schreibt
OpenCalendar eine `RECURRENCE-ID`-Ausnahme in das bestehende Kalenderobjekt; beim
Löschen wird das ausgewählte Vorkommnis über `EXDATE` ausgeschlossen. Auch die
**vollständige Serie** kann bearbeitet oder gelöscht werden. OpenCalendar lädt den
Serien-Master dabei direkt über die bereits bekannte CalDAV-Ressource. Einfache
RRULEs können im gemeinsamen Serieneditor geändert werden; komplexere Regeln bleiben
erhalten und werden nicht verlustbehaftet vereinfacht.

Auch **Diesen und alle folgenden Termine** wird für unterstützte RRULEs verarbeitet.
Beim Bearbeiten legt OpenCalendar ab dem gewählten Vorkommnis zuerst eine neue
CalDAV-Serie an und kürzt anschließend die ursprüngliche Serie unmittelbar davor.
Bei nummerierten Serien übernimmt der neue Teil nur die verbleibende Anzahl;
vorhandene Ausnahmen ab dem Trennpunkt werden bewusst zurückgesetzt. Beim Löschen
wird nur die ursprüngliche Serie vor dem gewählten Vorkommnis beendet. Beginnt die
Auswahl mit dem ersten Vorkommnis, wird die bestehende Serie direkt geändert bzw.
gelöscht, ohne einen zweiten Serienteil anzulegen.

## Jahresereignisse

OpenCalendar kann jährlich wiederkehrende persönliche Ereignisse als
**Geburtstag**, **Jahrestag**, **Hochzeitstag** oder **Todestag** verwalten. Der
Typ und das ursprüngliche Ausgangsdatum werden als lokale OpenCalendar-Metadaten
gespeichert; der Terminname beim Kalenderanbieter bleibt unverändert. In der
Kachelvisualisierung und in IPSView wird die Zahl der vergangenen Jahre
dynamisch ergänzt, beispielsweise `Max Mustermann (33J)`.

Für Skripte stehen providerneutrale PHP-Funktionen zur Verfügung. Auf Ebene einer
einzelnen Kalenderinstanz können Jahresereignisse mit
`IPSKAL_GetAnniversaryList()` gelesen und mit `IPSKAL_SetAnniversary()` markiert
oder geändert werden. `IPSKAL_GetBirthdayList()` bleibt als kompatibler
Geburtstags-Spezialfall erhalten. Über die **Kalender Ansicht** liefert
`IPSKALVIEW_GetAnniversaryList()` wahlweise alle ausgewählten Kalender oder eine
bestimmte Kalenderinstanz und unterstützt sowohl einen frei wählbaren Zeitraum in
Tagen als auch einen Filter nach Ereignistyp. Die vollständige Befehlsreferenz
befindet sich in der Dokumentation der Module **Kalender** und
**Kalender Ansicht**.

## Bekannte Einschränkungen

- **Diesen und alle folgenden Termine** wird bei Microsoft-Onlinebesprechungen und
  Serien mit Anhängen nicht automatisch geteilt, weil diese Daten beim Erzeugen des
  neuen Serienteils nicht verlustfrei übernommen werden können.
- ICS-/Webcal-Abonnements und lokal importierte ICS-Dateien sind grundsätzlich schreibgeschützt.
- Die IPSView-Ausgabe benötigt im HTML-Box-Steuerelement den Renderer
  **Browser des Clients** oder **Automatisch**, da die Bedienung JavaScript
  verwendet.

## Datenschutz und externe Dienste

OpenCalendar verarbeitet Konto-, Kalender- und Termindaten grundsätzlich auf der
eigenen Symcon-Installation. Das Modul enthält keine eigene Telemetrie und
übermittelt Kalenderinhalte nicht an einen Backenddienst des Modulautors. Bei
OAuth-Anbindungen werden die jeweils notwendigen Dienste von Google, Microsoft
und Symcon verwendet. Die Google-OAuth-Anwendung von OpenCalendar ist öffentlich
freigegeben und von Google verifiziert. Anwender benötigen weder ein eigenes
Google-Cloud-Projekt noch eine eigene Client-ID oder einen Clientschlüssel. Für
Google und Microsoft werden die für Anmeldung und Token-Aktualisierung
erforderlichen OAuth-Daten über den zentralen Symcon-OAuth-Dienst verarbeitet;
Kalender- und Termininhalte werden direkt zwischen der lokalen
Symcon-Installation und dem jeweiligen Kalenderanbieter übertragen.

Vor dem Verbinden eines externen Kontos sollten die
[Datenschutzhinweise](PRIVACY.md) gelesen werden. Ergänzend gelten die
[Nutzungsbedingungen](TERMS.md) sowie die Bedingungen der jeweils verwendeten
Drittanbieter.

## Enthaltene Module

- **Kalender Konto** ([Dokumentation](Kalender%20Konto))

  Verbindet die unterstützten Anbieter und stellt deren Kalender bereit.
- **Kalender Konfigurator** ([Dokumentation](Kalender%20Konfigurator))

  Findet die Kalender des aktuell verbundenen Kontos und legt vollständig
  konfigurierte Kalender-Instanzen an.
- **Kalender** ([Dokumentation](Kalender))

  Repräsentiert einen einzelnen Online-Kalender und synchronisiert dessen
  Termine.
- **Kalender Ansicht** ([Dokumentation](Kalender%20Ansicht))

  Führt mehrere Kalender in einer responsiven Kachel- oder IPSView-Ansicht
  zusammen und stellt die ausgewählten Kalender zusätzlich providerübergreifend
  über PHP-Funktionen für Tages- und Datumsbereichsabfragen sowie für
  Jahresereignisse bereit. Neben der vollständigen Ausgabe stehen kompakte
  Varianten für einfache Skripte zur Verfügung.
