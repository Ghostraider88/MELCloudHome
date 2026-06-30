# MELCloud Connection

Splitter-Modul, das die Verbindung zur MELCloud-Home-Cloud herstellt und als Eltern-Instanz
für die Klimageräte dient.

## Aufgaben

- Anmeldung über OAuth 2.0 Authorization Code + PKCE (AWS-Cognito-Federated-Login),
  Speicherung und Erneuerung der Tokens.
- Zyklisches Polling des Gerätestatus über `GET /context` und Verteilung an die Kinder.
- Zyklisches Polling der Energiedaten über die Telemetrie-Endpunkte (längeres Intervall).
- Entgegennahme von Steuerbefehlen der Kinder und Versand als `PUT /monitor/ataunit/{id}`.
- Konfigurator zum Anlegen der Klimageräte-Instanzen.

## Einstellungen

| Feld | Beschreibung |
|------|--------------|
| E-Mail | Benutzername des MELCloud-Home-Kontos |
| Passwort | Passwort des Kontos |
| Status-Aktualisierung | Polling-Intervall für den Gerätestatus (Sekunden, min. 60) |
| Energie-Aktualisierung | Polling-Intervall für den Energieverbrauch (Minuten, min. 30) |

## Hinweise

- Status-Polling deckt alle steuerungsrelevanten Klimadaten ab (Solltemperatur, Raumtemperatur,
  Betriebsmodus, Lüfterstufe, Vane-Einstellungen, Power-State). 60 Sekunden ist die sinnvolle
  Untergrenze für die MELCloud-Statusdaten – lokale Fernbedienungs-Änderungen sind damit binnen
  ca. einer Minute sichtbar, ohne die Cloud aggressiv abzufragen (kein 10s/30s-Polling).
- Energie-/Verbrauchsdaten werden bewusst seltener (≥30 Minuten) abgefragt, da diese Endpunkte
  empfindlicher auf häufige Abfragen reagieren (bekannte HTTP-429-Throttling-Fehler).

## Aktionen

- **Login testen** – prüft die Zugangsdaten und zeigt die Anzahl gefundener Klimageräte.
- **Konfigurator** – listet die Geräte des Kontos und legt daraus Geräte-Instanzen an.
