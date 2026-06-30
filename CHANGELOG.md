# Changelog

Alle nennenswerten Änderungen dieser Library. Build-Nummer und Datum siehe `library.json`.

## 1.0 (Build 17, unveröffentlicht – Beta-Vorbereitung)

- WLAN-Signalstärke (`WiFiSignal`) und Energieverbrauch (`EnergyConsumed`) korrekt befüllt.
- Betriebsstatus (`OperatingStatus`) zeigt Beschriftung + Icon statt Rohwert (Wertedarstellung,
  String-Variable).
- Variablenprofile vollständig durch Variable Presentations ersetzt (Aufzählung für
  schaltbare Variablen, Wertedarstellung für reine Anzeigen).
- Steuer-Endpoint und Energie-Telemetrie-Abfrage korrigiert (`PUT /monitor/ataunit/{id}`,
  Query-Parameter für `GET /telemetry/telemetry/energy/{id}`).
- Konfigurator-Modul (Typ 4) zum Anlegen der Klimagerät-Instanzen.
- DataFlow-Verkabelung zwischen Connection (Splitter), Configurator und Klimagerät (Device)
  korrigiert.
- Polling-Intervalle: Status standardmäßig 60 s, Energie 30 min (min. Werte erzwungen).

## Vor 1.0

- Erste Lauffähigkeit: Anmeldung (OAuth 2.0 + PKCE), Status-Polling, Steuerung der
  Kernfunktionen (Power, Modus, Solltemperatur, Lüfter, Lamellen).
