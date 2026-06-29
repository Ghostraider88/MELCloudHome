# MELCloud Connection

Splitter-Modul, das die Verbindung zur MELCloud-Home-Cloud herstellt und als Eltern-Instanz
für die Klimageräte dient.

## Aufgaben

- Anmeldung über OAuth 2.0 Authorization Code + PKCE (AWS-Cognito-Federated-Login),
  Speicherung und Erneuerung der Tokens.
- Zyklisches Polling des Gerätestatus über `GET /context` und Verteilung an die Kinder.
- Zyklisches Polling der Energiedaten über die Telemetrie-Endpunkte (längeres Intervall).
- Entgegennahme von Steuerbefehlen der Kinder und Versand als `PUT /api/devices/{id}/control`.
- Konfigurator zum Anlegen der Klimageräte-Instanzen.

## Einstellungen

| Feld | Beschreibung |
|------|--------------|
| E-Mail | Benutzername des MELCloud-Home-Kontos |
| Passwort | Passwort des Kontos |
| Status-Aktualisierung | Polling-Intervall für den Gerätestatus (Sekunden, min. 30) |
| Energie-Aktualisierung | Polling-Intervall für den Energieverbrauch (Minuten, min. 5) |

## Aktionen

- **Login testen** – prüft die Zugangsdaten und zeigt die Anzahl gefundener Klimageräte.
- **Konfigurator** – listet die Geräte des Kontos und legt daraus Geräte-Instanzen an.
