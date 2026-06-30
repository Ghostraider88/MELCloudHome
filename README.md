# MELCloud Home Klima für IP-Symcon

IP-Symcon-Library zur Steuerung von **Mitsubishi-Klimageräten (Air-to-Air / Klimaanlagen)**
über die **MELCloud Home** Cloud.

> Hinweis: Es wird ausschließlich die Klima-Funktionalität (ATA) unterstützt – keine
> Wärmepumpe (ATW), kein Warmwasser. Als technische Vorlage diente das Home-Assistant-Projekt
> [`andrew-blake/melcloudhome`](https://github.com/andrew-blake/melcloudhome).

## Module

| Modul | Typ | Aufgabe |
|-------|-----|---------|
| [MELCloud Connection](MELCloudConnection/) | Splitter | Anmeldung (OAuth 2.0 + PKCE), Status-Polling, Konfigurator, Befehlsweiterleitung |
| [MELCloud Klimagerät](MELCloudKlimageraet/) | Device | Ein Klimagerät: Status-Variablen und Steuerung |

## Installation

1. In IP-Symcon **Module Control** öffnen und die URL dieses Repositories als neue Library hinzufügen.
2. Eine Instanz **„MELCloud Connection"** anlegen.
3. E-Mail und Passwort des MELCloud-Home-Kontos eintragen, mit **„Login testen"** prüfen.
4. Über den **Konfigurator** in der Connection-Instanz die gefundenen Klimageräte anlegen.

## Funktionsumfang je Klimagerät

**Steuerbar:** Ein/Aus, Betriebsmodus (Heizen/Kühlen/Automatik/Entfeuchten/Lüften),
Solltemperatur (10–31 °C), Lüftergeschwindigkeit (Auto, 1–5), Lamellen vertikal und horizontal.

**Anzeige:** Raumtemperatur, Betriebsstatus, Verbindungsstatus, Störung,
WLAN-Signalstärke, Energieverbrauch.

## Architektur

```
MELCloud Connection (Splitter, hält Session + Konfigurator)
   └── MELCloud Klimagerät (Device, je Gerät eine Instanz)
```

- Statusdaten werden zyklisch von `GET /context` geholt und an die Geräte verteilt.
- Steuerbefehle gehen vom Gerät über den Splitter als `PUT /monitor/ataunit/{id}` an die Cloud.
- Energiedaten werden über die Telemetrie-Endpunkte in einem längeren Intervall geholt.

## Hinweise

- Die Anmeldung nutzt den OAuth-2.0-PKCE-Flow mit AWS-Cognito-Federated-Login.
  Ändert Mitsubishi den Login-Ablauf, kann eine Anpassung des Connection-Moduls nötig werden.
- Polling-Intervalle bewusst konservativ wählen, um die API-Limits zu respektieren
  (Standard: Status 60 s, Energie 30 min).
