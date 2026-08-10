# OpenCalendar

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-555555.svg)](https://www.symcon.de)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-1.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-brightgreen.svg)](https://www.symcon.de)<br>
[![License](https://img.shields.io/badge/License-PolyForm--Noncommercial--1.0.0-brightgreen.svg)](LICENSE)
[![Check Style](https://github.com/Burki24/OpenCalendar/actions/workflows/style.yml/badge.svg?branch=main)](https://github.com/Burki24/OpenCalendar/actions/workflows/style.yml?query=branch%3Amain)
[![Run Tests](https://github.com/Burki24/OpenCalendar/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/Burki24/OpenCalendar/actions/workflows/tests.yml?query=branch%3Amain)

OpenCalendar verbindet Online-Kalender mit Symcon. Unterstützt werden Apple
iCloud, Google Calendar, Microsoft 365/Outlook.com, generische CalDAV-Server
sowie schreibgeschützte ICS-/Webcal-Abonnements. Die gefundenen Kalender können
einzeln synchronisiert, in einer gemeinsamen Kachel angezeigt und optional als
interaktive HTML-Seite in IPSView verwendet werden.

Umfangreiche Terminlisten werden intern seitenweise zwischen Konto, Kalender
und Kalenderansicht übertragen; große ICS-Dateien müssen nicht manuell
aufgeteilt werden.

## Voraussetzungen

- Symcon ab Version 9.0
- Netzwerkzugriff des Symcon-Servers auf den jeweiligen Kalenderdienst
- für Google und Microsoft eine aktive Symcon-Connect-Verbindung
- für Apple iCloud ein anwendungsspezifisches Apple-Passwort

## Schnellstart

1. OpenCalendar über den Symcon Module Store installieren.
2. Über **Instanz hinzufügen** eine Instanz **Kalender Konto** anlegen.
3. Den gewünschten Anbieter auswählen und die angezeigten Zugangsdaten
   beziehungsweise den OAuth-Login einrichten.
4. **Verbindung testen** ausführen. Erst bei erfolgreichem Test die
   Kontokonfiguration übernehmen.
5. **Jetzt synchronisieren** ausführen, damit die verfügbaren Kalender gefunden
   werden.
6. Über **Instanz hinzufügen** einen **Kalender Konfigurator** anlegen. Im Dialog
   das zuvor eingerichtete **Kalender Konto** als übergeordnete Instanz wählen.
   Ist bereits ein Konfigurator vorhanden, lässt sich die Verbindung über das
   Zahnrad und **Gateway ändern** kontrollieren oder anpassen.
7. Den Konfigurator öffnen und **Kalender aktualisieren** verwenden. In der Liste
   die gewünschten Kalender über **Erstellen** beziehungsweise **Alle erstellen**
   anlegen.
8. Optional eine Instanz **Kalender Ansicht** erstellen, die gewünschten
   Kalenderinstanzen auswählen und die Ansicht in der Kachelvisualisierung oder
   in IPSView platzieren.

Die ausführlichen Einstellungen der Anbieter sind in der Dokumentation des
[Kalender Kontos](Kalender%20Konto) beschrieben.

## Anbieter im Überblick

Anbieter | Benötigte Angaben | Zugriff
--- | --- | ---
Apple iCloud | Apple-ID und anwendungsspezifisches Passwort | Lesen und Schreiben entsprechend den Kalenderrechten
Google Calendar | Anmeldung über Symcon OAuth | Lesen und Schreiben entsprechend den Google-Kalenderrechten
Microsoft 365/Outlook.com | Anmeldung über Symcon OAuth | Lesen und Schreiben entsprechend den Microsoft-Kalenderrechten
CalDAV | Server-URL, Benutzername und Passwort | Lesen und Schreiben entsprechend den Serverrechten
ICS/Webcal | Eine oder mehrere Feed-URLs, optional HTTP-Zugangsdaten | Schreibgeschützt

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

## Bekannte Einschränkungen

- Einzelne Vorkommen wiederkehrender Termine werden derzeit nur lesend
  dargestellt. Dadurch kann nicht versehentlich die vollständige Terminserie
  überschrieben oder gelöscht werden.
- ICS-/Webcal-Abonnements sind grundsätzlich schreibgeschützt.
- Die IPSView-Ausgabe benötigt im HTML-Box-Steuerelement den Renderer
  **Browser des Clients** oder **Automatisch**, da die Bedienung JavaScript
  verwendet.

## Datenschutz und externe Dienste

OpenCalendar verarbeitet Konto-, Kalender- und Termindaten grundsätzlich auf der
eigenen Symcon-Installation. Das Modul enthält keine eigene Telemetrie und
übermittelt Kalenderinhalte nicht an einen Backenddienst des Modulautors. Bei
OAuth-Anbindungen werden die jeweils notwendigen Dienste von Google, Microsoft
und Symcon verwendet.

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
  zusammen.
  
