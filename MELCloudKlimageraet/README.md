# MELCloud Klimagerät

Device-Modul für ein einzelnes Mitsubishi-Klimagerät (ATA). Die Instanz wird normalerweise
über den Konfigurator der **MELCloud Connection** angelegt und erhält darüber automatisch
die passende Geräte-ID.

## Variablen

### Steuerbar
| Variable | Darstellung | Beschreibung |
|----------|-------------|--------------|
| Zustand | Schalter (Enumeration) | Gerät ein-/ausschalten |
| Betriebsmodus | Enumeration | Heizen, Kühlen, Automatik, Entfeuchten, Lüften |
| Solltemperatur | Schieberegler | 10–31 °C in 0,5-Schritten |
| Lüftergeschwindigkeit | Enumeration | Auto, 1–5 |
| Lamelle vertikal | Enumeration | Auto, 1–5, Schwenken |
| Lamelle horizontal | Enumeration | Auto, Links … Rechts, Schwenken |

### Anzeige
| Variable | Darstellung | Beschreibung |
|----------|-------------|--------------|
| Raumtemperatur | ~Temperature | aktuelle Raumtemperatur |
| Status | Enumeration | abgeleitet (Aus/Leerlauf/Heizen/Kühlen/…) |
| Verbunden | ~Connect | Gerät online |
| Störung | ~Alert | Fehlerzustand des Geräts |
| WLAN-Signal | MELCloud.RSSI | Signalstärke in dBm |
| Energieverbrauch | ~Electricity | kumulierter Verbrauch in kWh |

## Hinweise

- Schaltvorgänge werden optimistisch sofort gesetzt und beim nächsten Status-Poll bestätigt.
- Empfangene Daten werden über einen Empfangsfilter auf die eigene Geräte-ID begrenzt.
- Zustand, Betriebsmodus, Solltemperatur, Lüftergeschwindigkeit und Lamellen nutzen die neuen
  IP-Symcon Variable-Presentations (`IPS_SetVariableCustomPresentation`/`SetVariablePresentation`
  via `MaintainVariable`) statt der alten, instanzübergreifenden Custom-Variablenprofile. Beim
  ersten Update nach diesem Wechsel werden die alten Profile (`MELCloud.Mode`, `MELCloud.FanSpeed`,
  `MELCloud.VaneVertical`, `MELCloud.VaneHorizontal`, `MELCloud.Status`, `MELCloud.Temperature`)
  automatisch entfernt, sobald keine Instanz mehr darauf verweist.
