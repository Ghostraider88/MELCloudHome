# Changelog

## Build 31 - 2026-07-01
- Klimagerät: `Verbunden` folgt jetzt dem echten `isConnected`-Feld der Cloud statt fest auf
  „verbunden" zu stehen. Ausgewertet mit der API-Diagnose aus Build 30.
- Connection: API-Diagnose zeigt Energie-Telemetrie-Kennzahlen jetzt korrekt an (falsches
  Feld `measure` statt `type` abgefragt).

## Build 30 - 2026-07-01
- Connection: Neuer Testknopf **„API-Diagnose ausführen"**. Ruft `/context`, den
  Energie-Telemetrie- und den Trendsummary-Endpunkt für ein Beispielgerät ab, loggt die
  vollständigen Rohantworten ins Debug und meldet per Popup zusammengefasst, welche von der
  Cloud gelieferten Felder aktuell nicht ausgewertet werden (z. B. neue Settings, weitere
  Telemetrie-Kennzahlen oder nicht unterstützte Gerätearten wie Wärmepumpen im selben Konto).

## Build 29 - 2026-07-01
- Klimagerät: Alle Statusvariablen (Power, Vane vertikal/horizontal, Raumtemperatur,
  Außentemperatur, Verbunden, Störung, WLAN-Signal, Energieverbrauch) auf die exakte
  `VARIABLE_PRESENTATION_VALUE_PRESENTATION`-Konfiguration (Icon, Farbe, Suffix,
  Nachkommastellen) umgestellt, die manuell im Symcon-GUI eingerichtet und bestätigt wurde.
  Ersetzt die zuvor verwendeten Templates/`VALUE_INPUT`, die Icons bzw. Einheiten nicht
  korrekt angezeigt hatten.
- Verbunden: eigene Wertedarstellung mit `wifi`/`wifi-slash`-Icons statt einfachem Schalter.
- Power: Glow-Farbe/-Intensität und `power-off`-Icon ergänzt.

## Build 28 - 2026-06-30
- Klimagerät: Fehlende Einheiten (kWh, °C) bei Energieverbrauch und Außentemperatur behoben
  (Zwischenschritt auf `VARIABLE_PRESENTATION_VALUE_INPUT` mit explizitem Suffix, siehe
  Build 29 für die finale Lösung).

## Build 27 - 2026-06-30
- Klimagerät: Verbleibende instanzübergreifende Custom-Variablenprofile (`Verbunden`,
  `WLAN-Signal`, `Energieverbrauch`) durch Variable Presentations ersetzt; Aufräumen der
  Legacy-Profile um `MELCloud.RSSI` ergänzt.

## Build 26 - 2026-06-30
- Connection: Außentemperatur-Abruf korrigiert – die Cloud liefert die Antwort des
  `/report/v1/trendsummary`-Endpunkts listenverpackt (`[{...}]`, Mobile-BFF-Format); das
  Auspacken von `$data[0]` vor dem Zugriff auf `datasets` behebt das Problem.

## Build 25 - 2026-06-30
- Connection: Neuer Testknopf **„Alle Daten abrufen"** in der Konfiguration, ruft Status
  und Energie (inkl. Außentemperatur) für alle Geräte in einem Zug ab.

## Build 24 - 2026-06-30
- Klimagerät: Temperaturverlauf (Farbverlauf) am Solltemperatur-Schieberegler durch
  zusätzliches `TEMPLATE`-Feld neben `PRESENTATION` wiederhergestellt (beide Schlüssel
  sind bei Variable Presentations getrennt zu setzen).

## Build 23 - 2026-06-30
- Klimagerät: Legacy-Profil `~Temperature` auf Raumtemperatur durch
  `VARIABLE_TEMPLATE_VALUE_PRESENTATION_ROOM_TEMPERATURE` ersetzt.

## Build 22 - 2026-06-30
- Klimagerät: Solltemperatur-Schieberegler nach fehlgeschlagenem Template-Versuch (Build 21)
  auf funktionierende `VARIABLE_PRESENTATION_SLIDER`-Darstellung zurückgesetzt.

## Build 21 - 2026-06-30
- Klimagerät: Außentemperatur-Statusvariable ergänzt (Abruf über
  `/report/v1/trendsummary`).
- Klimagerät: Erster (fehlgeschlagener) Versuch, dem Solltemperatur-Schieberegler einen
  Temperaturverlauf zu geben – siehe Korrektur in Build 22/24.

## Build 20 - 2026-06-30
- Klimagerät: Zulässigen Bereich der Solltemperatur auf 16–31 °C korrigiert.

## Build 19 - 2026-06-30
- Klimagerät: Eigene Wertedarstellung für die Störungsvariable statt des fixen
  `~Alert`-Systemprofils (anpassbare Beschriftung statt „OK"/„Alarm").

## Build 18 - 2026-06-30
- Modulstore-Veröffentlichung vorbereitet (Metadaten, Beta-Hinweis in der README).

## Build 17 - 2026-06-30
- Klimagerät: Betriebsstatus auf Wertedarstellung mit String-Typ umgestellt (zeigt
  Beschriftung + Icon statt Rohwert).

## Build 16 - 2026-06-30
- Klimagerät: Betriebsstatus wieder auf Aufzählung umgestellt (Zwischenschritt, siehe
  Build 17 für die endgültige Lösung mit String-Wertedarstellung).

## Build 15 - 2026-06-30
- Connection: WLAN-Signal- und Energiedaten-Quelle anhand des Home-Assistant-Referenz-
  moduls (`andrew-blake/melcloudhome`) korrigiert.

## Build 14 - 2026-06-30
- Klimagerät: Wertedarstellungs-Schema für den Betriebsstatus korrigiert; zusätzliches
  Debug-Logging für WLAN-Signal und Energieverbrauch ergänzt.

## Build 13 - 2026-06-30
- Klimagerät: Icons für Betriebsmodus und Betriebsstatus korrigiert; schnelle,
  aufeinanderfolgende Solltemperatur-Änderungen (z. B. durch Klicken/Ziehen) werden jetzt
  entkoppelt und erst nach kurzer Verzögerung als ein einzelner Steuerbefehl gesendet.

## Build 12 - 2026-06-30
- Klimagerät: Aufzählungs-Buttons (Modus, Lüfter, Lamellen) zeigen jetzt Beschriftung und
  Icon; Betriebsstatus auf Wertedarstellung umgestellt.

## Build 11 - 2026-06-30
- Klimagerät: Schema der `OPTIONS` bei Aufzählungs-Darstellungen korrigiert; nach dem
  Anlegen eines Geräts über den Konfigurator wird die Anzeige automatisch aktualisiert.

## Build 10 - 2026-06-30
- Connection: Polling-Intervalle angepasst und mit Mindestwerten abgesichert (Status
  standardmäßig 60 s, Energie 30 min).

## Build 9 - 2026-06-30
- Klimagerät: Alte, instanzübergreifende Custom-Variablenprofile durch Variable
  Presentations ersetzt (Aufzählung für schaltbare Variablen, Wertedarstellung für reine
  Anzeigen).

## Build 8 - 2026-06-30
- Repository: Verzeichnis `tools/` in `.tools/` umbenannt, um eine fälschliche
  Modul-Fehlermeldung im Module Control zu beheben (reservierte Punkt-Ordner werden
  ignoriert).

## Build 7 - 2026-06-30
- Connection: Steuer-Endpoint korrigiert (`PUT /monitor/ataunit/{id}` statt eines nicht
  existierenden Pfads) und fehlende Query-Parameter (`from`, `to`, `interval`, `measure`)
  bei der Energie-Telemetrie-Abfrage ergänzt.

## Build 6 - 2026-06-30
- Connection/Klimagerät: Schreibpfad für Steuerbefehle repariert (RequestAction →
  SendDataToParent → ForwardData) und Status-Poll-Intervall verkürzt.

## Build 5 - 2026-06-30
- Konfigurator (Typ 4) als eigenständiges Modul `MELCloudConfigurator` eingeführt (zuvor
  fälschlich in den Splitter eingebettet).
- DataFlow-Verkabelung zwischen Connection (Splitter), Configurator und Klimagerät (Device)
  korrigiert (einheitliche DataFlow-GUID, `ReceiveDataFilter`, Array-Create-Format).

## Build 4 - 2026-06-29
- Splitter/Device-Verbindung repariert: gekreuzte DataFlow-GUIDs vereinheitlicht.

## Build 3 - 2026-06-29
- Splitter/Device-Verbindung repariert: einheitliche DataFlow-GUID für den Verbindungsaufbau
  zwischen Connection und Configurator/Device.

## Build 2 - 2026-06-29
- Login: OAuth-Code-Extraktion für das `melcloudhome://`-Custom-URI-Schema sowie
  JavaScript-Weiterleitungsseite nach dem Cognito-Callback korrigiert.
- Legacy-Profil `~Connect` durch `~Switch` ersetzt; nicht mit `IPSModuleStrict` kompatiblen
  `ConnectParent`-Aufruf entfernt.

## Build 1 - 2026-06-29
- Erste lauffähige Version: Anmeldung (OAuth 2.0 + PKCE), Status-Polling, Steuerung der
  Kernfunktionen (Power, Modus, Solltemperatur, Lüfter, Lamellen).
