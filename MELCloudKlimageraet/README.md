# MELCloud Klimagerät

Device-Modul für ein einzelnes Mitsubishi-Klimagerät (ATA). Die Instanz wird normalerweise
über den Konfigurator der **MELCloud Connection** angelegt und erhält darüber automatisch
die passende Geräte-ID.

## Variablen

Alle Variablen nutzen moderne IP-Symcon Variable Presentations (Icons, Farben, Einheiten)
statt klassischer Variablenprofile.

### Steuerbar
| Variable | Darstellung | Beschreibung |
|----------|-------------|--------------|
| Zustand | Schalter | Gerät ein-/ausschalten |
| Betriebsmodus | Aufzählung | Automatik, Heizen, Kühlen, Entfeuchten, Lüften |
| Solltemperatur | Schieberegler mit Temperaturverlauf | Bereich abhängig vom Betriebsmodus und Gerät (typ. 16–31 °C, im Heizmodus je nach Gerät auch niedriger), 0,5 °C-Schritte |
| Lüftergeschwindigkeit | Aufzählung | Automatik, 1–5 |
| Lamelle vertikal | Aufzählung | Automatik, 1–5, Schwenken |
| Lamelle horizontal | Aufzählung | Automatik, Links … Rechts, Schwenken |

### Anzeige
| Variable | Darstellung | Beschreibung |
|----------|-------------|--------------|
| Raumtemperatur | Wertedarstellung | aktuelle Raumtemperatur |
| Außentemperatur | Wertedarstellung | Außentemperatur (nur bei Geräten mit Außensensor) |
| Status | Wertedarstellung | abgeleitet (Aus/Leerlauf/Heizen/Kühlen/Entfeuchten/Lüften/Automatik) |
| Verbunden | Wertedarstellung | echter Cloud-/WLAN-Verbindungsstatus des Geräts |
| Störung | Wertedarstellung | Fehlerzustand des Geräts |
| WLAN-Signal | Wertedarstellung | Signalstärke in dBm |
| Energieverbrauch | Wertedarstellung | kumulierter Verbrauch in kWh (letzte 24h, stündlich) |

## Hinweise

- Schaltvorgänge werden optimistisch sofort gesetzt und beim nächsten Status-Poll bestätigt.
- Empfangene Daten werden über einen Empfangsfilter auf die eigene Geräte-ID begrenzt.
- Der Bereich der Solltemperatur wird beim Moduswechsel automatisch anhand der vom Gerät
  gemeldeten Fähigkeiten (`capabilities` aus der Cloud-Antwort) angepasst – z. B. erlauben
  manche Geräte im Heizmodus niedrigere Werte als im Kühl-/Trocken-/Automatikmodus.
