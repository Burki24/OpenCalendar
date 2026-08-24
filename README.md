# OpenCalendar

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-555555.svg)](https://www.symcon.de)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-3.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.1%2B-brightgreen.svg)](https://www.symcon.de)<br>
[![License](https://img.shields.io/badge/License-PolyForm--Noncommercial--1.0.0-brightgreen.svg)](LICENSE)
[![Check Style](https://github.com/Burki24/OpenCalendar/actions/workflows/style.yml/badge.svg?branch=main)](https://github.com/Burki24/OpenCalendar/actions/workflows/style.yml?query=branch%3Amain)
[![Run Tests](https://github.com/Burki24/OpenCalendar/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/Burki24/OpenCalendar/actions/workflows/tests.yml?query=branch%3Amain)

OpenCalendar ist eine Anwendung für Symcon, mit der Nutzer ihre Online-Kalender verbinden, synchronisieren, anzeigen und bearbeiten können. Unterstützt werden Apple iCloud, Google Calendar, Microsoft 365/Outlook.com, generische CalDAV-Server sowie schreibgeschützte ICS-/Webcal-Abonnements.
Die gefundenen Kalender können einzeln synchronisiert, in einer gemeinsamen Kachel angezeigt und optional als interaktive HTML-Seite in IPSView verwendet werden.

Ab Version 3.0 steht zusätzlich die **Kalender Einrichtung** als zentraler
Einrichtungsassistent zur Verfügung. Sie führt vom Kalenderanbieter über das
Kalender Konto und die Kalenderauswahl bis zur fertigen Kalender Ansicht und
übernimmt dabei die notwendigen technischen Verknüpfungen automatisch.

Umfangreiche Terminlisten werden intern seitenweise zwischen Konto, Kalender und
Kalenderansicht übertragen. Online-ICS-/Webcal-Feeds und lokal importierte
ICS-Dateien dürfen jeweils höchstens 16 MiB groß sein. Innerhalb dieser Grenze
müssen umfangreiche Kalenderdateien nicht manuell aufgeteilt werden.

**Datenschutz:** [Datenschutzhinweise](PRIVACY.md)  
**Nutzungsbedingungen:** [Nutzungsbedingungen](TERMS.md)

## Voraussetzungen

- Symcon ab Version 9.1
- Netzwerkzugriff des Symcon-Servers auf den jeweiligen Kalenderdienst
- für Google und Microsoft eine aktive Symcon-Connect-Verbindung; eine eigene
  OAuth-Client-ID oder ein eigener Clientschlüssel ist nicht erforderlich
- für Apple iCloud ein anwendungsspezifisches Apple-Passwort

## Schnellstart mit dem Einrichtungsassistenten

Für eine neue OpenCalendar-Konfiguration ist die **Kalender Einrichtung** der
empfohlene Weg.

1. OpenCalendar installieren und über **Instanz hinzufügen** eine Instanz
   **Kalender Einrichtung** anlegen.
2. **OpenCalendar-Einrichtungsassistent starten** wählen.
3. Den gewünschten Kalenderanbieter auswählen. Optional kann der Assistent
   gleichzeitig einen **Kalender Konfigurator** für die spätere Verwaltung
   dieses Kontos anlegen.
4. Ein vorhandenes **Kalender Konto** auswählen oder ein neues Konto einrichten.
   Google und Microsoft verwenden dabei den vorhandenen OAuth-Ablauf; Apple,
   CalDAV und ICS/Webcal werden direkt im Assistenten konfiguriert.
5. Nach erfolgreicher Verbindungsprüfung die gewünschten Kalender auswählen.
6. Eine vorhandene **Kalender Ansicht** auswählen oder eine neue anlegen.
7. Die Zusammenfassung prüfen und die Einrichtung abschließen. Der Assistent legt
   fehlende Kalender-Instanzen an, verbindet sie mit dem richtigen Konto, ordnet
   sie der Kalender Ansicht zu und führt eine abschließende Synchronisations- und
   Funktionsprüfung durch.

Neu angelegte Kalender-Instanzen erhalten zur besseren Unterscheidung den
Provider als Namenspräfix, beispielsweise `O365 - Familie`, `Apple - Privat`,
`Google - Arbeit`, `CalDAV - Familie` oder `ICS - Feiertage`. Bereits vorhandene
und wiederverwendete Instanzen werden nicht automatisch umbenannt.

Auf der Ergebnisseite können fehlgeschlagene Synchronisationen erneut geprüft
werden. Alternativ kann direkt ein weiteres Kalender-Konto eingerichtet werden,
ohne den Assistenten schließen und neu öffnen zu müssen.

Die vollständige Beschreibung des Ablaufs befindet sich in der Dokumentation der
[Kalender Einrichtung](Kalender%20Einrichtung).

## Manuelle Einrichtung

Die bisherige manuelle Einrichtung bleibt weiterhin unterstützt und ist besonders
für gezielte Änderungen an bestehenden Installationen sinnvoll.

1. Eine Instanz **Kalender Konto** anlegen und den gewünschten Anbieter
   konfigurieren.
2. **Verbindung testen** und anschließend **Jetzt synchronisieren**, damit die
   verfügbaren Kalender ermittelt werden.
3. Einen **Kalender Konfigurator** anlegen und mit dem Kalender Konto verbinden.
4. Im Konfigurator **Kalender aktualisieren** ausführen und die gewünschten
   Kalender über **Erstellen** beziehungsweise **Alle erstellen** anlegen.
5. Eine **Kalender Ansicht** anlegen und die gewünschten Kalenderinstanzen
   auswählen.

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

## Kalender korrekt anlegen

**Kalender-Instanzen sollen über den Einrichtungsassistenten oder den Kalender
Konfigurator erzeugt werden.**

Beide Wege übernehmen die vollständige technische Identität des Kalenders:
interne Kalender-ID, Anbieter-ID, URL, Farbe, Schreibrechte und die Verbindung
zum richtigen Kalender Konto. Manuell angelegte oder kopierte Kalender-Instanzen
besitzen diese vollständige Zuordnung nicht und sollten daher nicht lediglich
über **Gateway ändern** mit einem Konto verbunden werden.

Der **Einrichtungsassistent** ist für die Erst- und Erweiterungseinrichtung
vorgesehen. Der **Kalender Konfigurator** eignet sich besonders für die spätere
Verwaltung eines bereits eingerichteten Kontos, beispielsweise wenn weitere
Kalender hinzukommen. Er kann bei der Einrichtung optional automatisch
mitangelegt werden.

Nach der korrekten Erstellung dürfen Kalender-Instanzen im Objektbaum beliebig
verschoben oder umbenannt werden. Ihre technische Zuordnung bleibt dabei erhalten.

## Mehrere Kalenderkonten

Mehrere Kalenderkonten können direkt nacheinander über die **Kalender
Einrichtung** hinzugefügt werden. Nach Abschluss eines Kontos bietet die
Ergebnisseite an, unmittelbar zur Providerauswahl zurückzukehren und ein weiteres
Konto einzurichten.

Wer zusätzlich Kalender Konfiguratoren verwendet, hat weiterhin zwei
Möglichkeiten:

- **Ein Konfigurator je Konto** ist die übersichtlichste Variante. Jeder
  Konfigurator bleibt dauerhaft mit seinem Kalender Konto verbunden.
- **Ein gemeinsamer Konfigurator** kann nacheinander für mehrere Konten verwendet
  werden. Dazu im Konfigurator über das Zahnrad **Gateway ändern** wählen, das
  gewünschte Kalender Konto verbinden und anschließend zwingend
  **Kalender aktualisieren** ausführen.

Der Konfigurator zeigt immer nur Kalender und bestehende Kalender-Instanzen des
aktuell verbundenen Kontos. Bereits erzeugte Kalender-Instanzen bleiben beim
Wechsel mit ihrem ursprünglichen Konto verbunden und erscheinen wieder, sobald
der Konfigurator erneut mit diesem Konto verbunden wird.

Weitere Hintergründe enthält die
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

- **Kalender Einrichtung** ([Dokumentation](Kalender%20Einrichtung))

  Führt als zentraler Einrichtungsassistent durch Providerwahl, Konto,
  Kalenderauswahl, optionale Konfigurator-Erstellung, Kalender Ansicht und
  Abschlussprüfung.
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
