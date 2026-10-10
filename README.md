# SymconDeye

IP-Symcon-Bibliothek für **Deye-Mikrowechselrichter** (SUN-M-Reihe, z. B.
MI 2000W, auch baugleiche Bosswerk-Geräte) mit WLAN-Loggerstick. Sie liest
den Wechselrichter lokal aus, ohne Cloud.

Die Bibliothek enthält zwei Module:

| Modul | Art | Aufgabe |
|---|---|---|
| **Solarman V5 Gateway** | Splitter | verpackt Modbus-Anfragen im Solarman-V5-Rahmen des Sticks |
| **Deye Mikrowechselrichter** | Gerät | liest die Register und schreibt die Variablen |

Das Gateway spricht nach oben dieselbe Schnittstelle wie das ModBus Gateway
von IP-Symcon. Ist der Wechselrichter per RS485 angebunden, hängt das
Gerätemodul deshalb ebenso unter einem normalen ModBus Gateway.

## Voraussetzungen

- IP-Symcon ab 8.0, getestet mit 9.0
- Deye-Mikrowechselrichter mit Loggerstick im lokalen Netz, Port 8899
- die Seriennummer des Sticks (Aufkleber am Stick, **nicht** die des
  Wechselrichters)

## Einrichtung

1. In der **Modulsteuerung** (Kern-Instanzen → Modules) diese URL hinzufügen:
   `https://github.com/HeikoSchroers/SymconDeye`
2. Eine Instanz **Deye Mikrowechselrichter** anlegen. Symcon legt dazu ein
   **Solarman V5 Gateway** und einen **Client Socket** an.
3. Im Gateway die Seriennummer des Sticks eintragen. Die Geräte-ID bleibt
   meist 1.
4. Im Client Socket die Adresse des Sticks und Port **8899** eintragen.
5. In der Instanz das Abfrageintervall einstellen (Vorgabe 10 Sekunden).

Im Modul selbst steht keine Adresse und keine Seriennummer.

## Variablen

| Bereich | Variablen |
|---|---|
| Status | Verbindung, letzte Aktualisierung |
| Wechselrichter | AC-Wirkleistung, DC-Leistung gesamt, Netzspannung, Netzstrom, Netzfrequenz, Kühlkörpertemperatur |
| Ertrag | heute, gesamt |
| je Eingang 1–4 | Spannung, Strom, Leistung, Ertrag heute, Ertrag gesamt |

Die Leistung je Eingang ist Spannung × Strom; das Gerät meldet sie nicht
selbst. Die DC-Leistung ist die Summe der Eingänge.

Die Gesamtzähler werden nie auf 0 gesetzt. Eine 0 ist immer ein Lesefehler,
und das Archiv würde den ganzen Stand sonst neu zählen.

**Nachts** ist der Stick ohne PV-Spannung aus. Bei einem Lesefehler setzt
das Modul zunächst nur „Verbindung" auf getrennt; die Werte bleiben stehen,
damit kurze Aussetzer keine Nullen ins Archiv schreiben. Kommt länger keine
Antwort (Vorgabe 300 Sekunden), zeigen Leistung und Strom 0.

Das Modul liest nur. Es schreibt nichts an den Wechselrichter.

## Eigenheiten des Sticks

- Der Stick schickt jede Antwort an **alle** verbundenen Clients, auch die
  Antworten auf fremde Anfragen. Das Gateway nimmt deshalb nur Antworten an,
  deren Folgenummer, Geräte-ID, Funktion, Länge und CRC zur eigenen Anfrage
  passen.
- Er verträgt nur wenige gleichzeitige Verbindungen. Neben einer anderen
  Software, die ebenfalls abfragt, kann es zu Aussetzern kommen.
- Manche Sticks hängen an die Modbus-Antwort eine zweite CRC an. Das
  Gateway wertet nur die erste aus.
- 32-Bit-Werte stehen mit dem niedrigen Wort zuerst.

## Quellen

- Register: [kbialek/deye-inverter-mqtt](https://github.com/kbialek/deye-inverter-mqtt),
  `docs/metric_group_micro.md` (Apache-2.0). Übernommen sind nur Adressen
  und Faktoren; jedes verwendete Register ist am Gerät geprüft.
- Protokoll: [Beschreibung des Solarman-V5-Protokolls](https://pysolarmanv5.readthedocs.io/en/stable/solarmanv5_protocol.html)
  aus pysolarmanv5. Der Code hier ist eigenständig geschrieben.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
