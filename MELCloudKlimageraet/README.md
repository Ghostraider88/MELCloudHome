# MELCloud Klimagerät

Device-Modul für ein einzelnes Mitsubishi-Klimagerät (ATA). Die Instanz wird normalerweise
über den Konfigurator der **MELCloud Connection** angelegt und erhält darüber automatisch
die passende Geräte-ID.

## Variablen

### Steuerbar
| Variable | Profil | Beschreibung |
|----------|--------|--------------|
| Ein/Aus | ~Switch | Gerät ein-/ausschalten |
| Betriebsmodus | MELCloud.Mode | Heizen, Kühlen, Automatik, Entfeuchten, Lüften |
| Solltemperatur | MELCloud.Temperature | 10–31 °C in 0,5-Schritten |
| Lüftergeschwindigkeit | MELCloud.FanSpeed | Auto, 1–5 |
| Lamelle vertikal | MELCloud.VaneVertical | Auto, 1–5, Schwenken |
| Lamelle horizontal | MELCloud.VaneHorizontal | Auto, Links … Rechts, Schwenken |

### Anzeige
| Variable | Profil | Beschreibung |
|----------|--------|--------------|
| Raumtemperatur | ~Temperature | aktuelle Raumtemperatur |
| Betriebsstatus | MELCloud.Status | abgeleitet (Aus/Leerlauf/Heizen/Kühlen/…) |
| Verbunden | ~Connect | Gerät online |
| Störung | ~Alert | Fehlerzustand des Geräts |
| WLAN-Signal | MELCloud.RSSI | Signalstärke in dBm |
| Energieverbrauch | ~Electricity | kumulierter Verbrauch in kWh |

## Hinweise

- Schaltvorgänge werden optimistisch sofort gesetzt und beim nächsten Status-Poll bestätigt.
- Empfangene Daten werden über einen Empfangsfilter auf die eigene Geräte-ID begrenzt.
