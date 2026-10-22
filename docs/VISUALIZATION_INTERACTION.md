# Visualisierungsinteraktion

## Zentrale Mausradbehandlung am 22.10.2026

Die Ausgabe verwendet die kanonische Interaktionsressource aus `VisualizationAssetHelper`, die `IPSViewHTMLPageHelper` automatisch vor dem Modulskript einbindet. Generische Scroll-/Delta-/Pointer-/Frame-Mechanik wird nicht mehr modulweise neu implementiert. Die verbindliche Prüfregel einschließlich getrenntem Webclient-/App-Nachweis steht in `SymconDevelopment/standards/TESTING.md`.

Einordnung des Praxisbefunds: Chrome- und Edge-Webclients konnten die WoIst-Karte zoomen und die Geräteliste scrollen. Die native Windows-IPSView-App 6.5.19 ignorierte dieselben echten Mausradaktionen in ihrem bisherigen Stand. Die gemeinsame Korrektur ist lokal eingebunden; die direkte native Abnahme nach Aktualisierung des Zielsystems ist noch offen. Synthetische Regressionen sichern umadressierte Ziele mit `(0, 0)`, korrigierte Zeigerposition, Delta-Modi, Ctrl-/Meta-Ausnahmen und skalierte direkte Frame-Weitergabe ab. Browserprüfungen mit überlaufenden synthetischen Geräten bestätigten normalen Kartenzoom sowie Scrollen der inneren Liste über den äußeren Loader, ohne gleichzeitigen Kartenzoom. Diese Nachweise ersetzen keine nachträgliche native App-Abnahme.

Die Kalender-Zielauswahl für modale Dialoge und Picker sowie Touch-Gesten bleiben bestehen. Wheel-Normalisierung und Mauspositionsspeicherung verwenden die zentrale Ressource; vorhandene Picker-Regressionen prüfen denselben Helper. Provider- und Terminlogik wurden nicht geändert.
