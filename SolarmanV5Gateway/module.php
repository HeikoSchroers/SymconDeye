<?php

declare(strict_types=1);

/**
 * Solarman V5 Gateway: uebersetzt Modbus-Anfragen in den Solarman-V5-Rahmen
 * der WLAN-Loggersticks (Deye, Bosswerk und Verwandte, TCP-Port 8899).
 *
 * Die Kinder sprechen dieselbe Schnittstelle wie mit dem ModBus Gateway von
 * IP-Symcon (DataID {E310B701-...}, Felder Function/Address/Quantity/Data)
 * und erhalten dieselbe Antwort: Funktionscode, Byteanzahl, Daten - nur
 * UTF-8-kodiert, weil ein PHP-Modul keine rohen Bytes zurueckgeben kann.
 *
 * Darunter haengt ein Client Socket mit Adresse und Port des Sticks.
 *
 * Protokoll: https://pysolarmanv5.readthedocs.io/en/stable/solarmanv5_protocol.html
 *
 * Eigenheiten des Sticks:
 * - Er schickt jede Antwort an ALLE verbundenen Clients, auch die Antworten
 *   auf fremde Anfragen. Eine Antwort wird deshalb nur angenommen, wenn
 *   Folgenummer, Geraet, Funktion, Laenge und CRC zur eigenen Anfrage passen.
 * - Er wiederholt nur das untere Byte der Folgenummer.
 * - Nachts ohne PV-Spannung ist er aus; der Client Socket meldet dann einen
 *   Fehler und verbindet sich spaeter von selbst wieder.
 */
class SolarmanV5Gateway extends IPSModule
{
    private const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const IO_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('LoggerSerial', '');
        $this->RegisterPropertyInteger('DeviceID', 1);
        $this->RegisterPropertyInteger('Timeout', 5);

        $this->RequireParent(self::CLIENT_SOCKET);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetBuffer('Rx', '');
        $this->SetBuffer('Warte', '');
        $this->SetBuffer('Antwort', '');
        $sn = $this->ReadPropertyString('LoggerSerial');
        $this->SetStatus(preg_match('/^\d{1,10}$/', $sn) && (int) $sn <= 0xFFFFFFFF ? 102 : 201);
    }

    /** Anfrage eines Kindes (Modbus-Schnittstelle). Gibt die Antwort zurueck. */
    public function ForwardData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['Function'], $d['Address'], $d['Quantity'])) {
            return '';
        }
        if ($this->GetStatus() !== 102 || !$this->HasActiveParent()) {
            return '';
        }
        $fc = (int) $d['Function'];
        $adr = (int) $d['Address'];
        $anz = (int) $d['Quantity'];
        $daten = mb_convert_encoding((string) ($d['Data'] ?? ''), 'ISO-8859-1', 'UTF-8');

        $rtu = $this->rtuAnfrage($fc, $adr, $anz, $daten);
        if ($rtu === null) {
            $this->SendDebug('Anfrage', 'Funktion ' . $fc . ' wird nicht unterstuetzt', 0);
            return '';
        }

        // Immer nur eine Anfrage zur Zeit, sonst ordnet man Antworten falsch zu.
        $sem = 'SolarmanV5_' . $this->InstanceID;
        $timeout = max(1, $this->ReadPropertyInteger('Timeout'));
        if (!IPS_SemaphoreEnter($sem, $timeout * 1000)) {
            $this->SendDebug('Anfrage', 'Vorige Anfrage laeuft noch', 0);
            return '';
        }
        try {
            $seq = (((int) $this->GetBuffer('Seq')) + 1) & 0xFFFF;
            $this->SetBuffer('Seq', (string) $seq);
            $this->SetBuffer('Antwort', '');
            $this->SetBuffer('Warte', json_encode([
                's' => $seq & 0xFF, 'f' => $fc, 'n' => $anz,
                'l' => self::antwortLaenge($fc, $anz),
            ]));

            $rahmen = $this->v5Packen($rtu, $seq);
            $this->SendDebug('Senden', bin2hex($rahmen), 0);
            $this->SendDataToParent(json_encode([
                'DataID' => self::IO_TX,
                'Buffer' => mb_convert_encoding($rahmen, 'UTF-8', 'ISO-8859-1'),
            ]));

            $ende = microtime(true) + $timeout;
            while (microtime(true) < $ende) {
                $antwort = $this->GetBuffer('Antwort');
                if ($antwort !== '') {
                    // Rohe Bytes kommen aus einem PHP-Modul nicht unversehrt
                    // beim Kind an: IP-Symcon behandelt die Rueckgabe als
                    // Text. Deshalb ausdruecklich als UTF-8 (jedes Byte ein
                    // Zeichen); das Kind wandelt zurueck.
                    return mb_convert_encoding(hex2bin($antwort), 'UTF-8', 'ISO-8859-1');
                }
                IPS_Sleep(20);
            }
            $this->SendDebug('Anfrage', sprintf('Keine passende Antwort (F%d %d/%d)', $fc, $adr, $anz), 0);
            return '';
        } finally {
            $this->SetBuffer('Warte', '');
            IPS_SemaphoreLeave($sem);
        }
    }

    /** Daten vom Client Socket. Sucht V5-Rahmen und prueft sie gegen die offene Anfrage. */
    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        $neu = mb_convert_encoding((string) ($d['Buffer'] ?? ''), 'ISO-8859-1', 'UTF-8');
        $rx = $this->GetBuffer('Rx') . $neu;

        while (($start = strpos($rx, "\xA5")) !== false) {
            $rx = substr($rx, $start);
            if (strlen($rx) < 13) {
                break;
            }
            $len = unpack('v', substr($rx, 1, 2))[1];
            $gesamt = 11 + $len + 2;
            if ($len > 1024) {
                $rx = substr($rx, 1);   // kein Rahmen, weitersuchen
                continue;
            }
            if (strlen($rx) < $gesamt) {
                break;
            }
            $rahmen = substr($rx, 0, $gesamt);
            $rx = substr($rx, $gesamt);
            if ($rahmen[$gesamt - 1] !== "\x15") {
                $rx = substr($rahmen, 1) . $rx;   // falscher Anfang, ab dem naechsten Byte suchen
                continue;
            }
            $this->rahmenPruefen($rahmen, $len);
        }
        // Der Puffer bleibt klein, auch wenn nur Unsinn kommt.
        $this->SetBuffer('Rx', strlen($rx) > 2048 ? '' : $rx);
    }

    private function rahmenPruefen(string $rahmen, int $len): void
    {
        // Nur Antworten auf Datenanfragen (Steuercode 0x1510); der Stick
        // schickt daneben Herzschlag- und Statusrahmen.
        if ($rahmen[3] !== "\x10" || $rahmen[4] !== "\x15") {
            return;
        }
        $warte = json_decode($this->GetBuffer('Warte'), true);
        if (!is_array($warte) || ord($rahmen[5]) !== $warte['s']) {
            $this->SendDebug('Empfang', 'Fremde Antwort verworfen: ' . bin2hex($rahmen), 0);
            return;
        }
        $mb = substr(substr($rahmen, 11, $len), 14);
        $slave = $this->ReadPropertyInteger('DeviceID');
        if (strlen($mb) < 5 || ord($mb[0]) !== $slave) {
            return;
        }
        $fc = ord($mb[1]);
        if ($fc === ($warte['f'] | 0x80)) {
            $soll = 3;   // Ausnahme: Geraet, Funktion, Fehlercode
        } elseif ($fc === $warte['f']) {
            $soll = $warte['l'];
        } else {
            return;
        }
        // Manche Sticks haengen eine zweite CRC an; gezaehlt wird nur die erste.
        if (strlen($mb) < $soll + 2
            || self::crc16(substr($mb, 0, $soll)) !== unpack('v', substr($mb, $soll, 2))[1]) {
            $this->SendDebug('Empfang', 'Laenge oder CRC passt nicht: ' . bin2hex($rahmen), 0);
            return;
        }
        if ($fc <= 4 && ord($mb[2]) !== 2 * $warte['n']) {
            return;
        }
        // Wie das ModBus Gateway: ohne Geraeteadresse und CRC.
        $this->SetBuffer('Antwort', bin2hex(substr($mb, 1, $soll - 1)));
    }

    // ===================== Rahmen =====================

    private function rtuAnfrage(int $fc, int $adr, int $anz, string $daten): ?string
    {
        $slave = $this->ReadPropertyInteger('DeviceID');
        switch ($fc) {
            case 1: case 2: case 3: case 4:
                $f = pack('CCnn', $slave, $fc, $adr, $anz);
                break;
            case 5: case 6:
                if (strlen($daten) < 2) {
                    return null;
                }
                $f = pack('CCn', $slave, $fc, $adr) . substr($daten, 0, 2);
                break;
            case 15: case 16:
                $f = pack('CCnnC', $slave, $fc, $adr, $anz, strlen($daten)) . $daten;
                break;
            default:
                return null;
        }
        return $f . pack('v', self::crc16($f));
    }

    /** Erwartete Laenge der RTU-Antwort ohne CRC. */
    private static function antwortLaenge(int $fc, int $anz): int
    {
        switch ($fc) {
            case 1: case 2:
                return 3 + intdiv($anz + 7, 8);
            case 3: case 4:
                return 3 + 2 * $anz;
            default:
                return 6;   // Geraet, Funktion, Adresse, Wert bzw. Anzahl
        }
    }

    private function v5Packen(string $modbus, int $seq): string
    {
        $nutzlast = "\x02" . str_repeat("\x00", 14) . $modbus;
        $kopf = "\xA5" . pack('v', strlen($nutzlast)) . "\x10\x45"
              . pack('v', $seq) . pack('V', (int) $this->ReadPropertyString('LoggerSerial'));
        $rumpf = $kopf . $nutzlast;
        $pruef = 0;
        for ($i = 1, $n = strlen($rumpf); $i < $n; $i++) {
            $pruef += ord($rumpf[$i]);
        }
        return $rumpf . chr($pruef & 0xFF) . "\x15";
    }

    private static function crc16(string $daten): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($daten); $i < $n; $i++) {
            $crc ^= ord($daten[$i]);
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }
        return $crc;
    }
}
