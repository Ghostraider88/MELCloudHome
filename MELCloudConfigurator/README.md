# MELCloud Configurator

Konfigurator-Modul (Typ 4). Wird automatisch als Kind-Instanz unter einer
**MELCloud Connection**-Instanz angelegt und listet die im MELCloud-Home-Konto
gefundenen Klimageräte auf.

## Aufgaben

- Liest die Geräteliste vom übergeordneten **MELCloud Connection**-Splitter aus.
- Erlaubt das Anlegen je einer **MELCloud Klimagerät**-Instanz pro gefundenem Gerät über
  die Konfigurator-Tabelle der Instanzkonfiguration.

## Hinweise

- Diese Instanz wird nicht manuell über "Instanz hinzufügen" erstellt, sondern erscheint
  automatisch als Kind der Connection-Instanz (siehe Aktion **Konfigurator** dort).
- Ohne aktive Verbindung zum übergeordneten Splitter zeigt die Instanz den Status
  "Keine Verbindung zum Splitter" und listet keine Geräte.
