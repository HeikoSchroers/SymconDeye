<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VerbindungKette.php';

/**
 * Deye-Mikrowechselrichter (SUN-M-Reihe, z. B. MI 2000W) mit vier Eingaengen.
 *
 * Die Instanz haengt unter dem Solarman V5 Gateway dieser Bibliothek (WLAN-
 * Stick, Port 8899) oder unter einem ModBus Gateway von IP-Symcon, falls der
 * Wechselrichter per RS485 angebunden ist. Adresse, Port, Unit-ID und die
 * Seriennummer des Sticks stehen in dieser Instanz; sie traegt sie selbst in
 * Gateway und Socket ein (libs/VerbindungKette.php).
 *
 * Registertabelle: kbialek/deye-inverter-mqtt, docs/metric_group_micro.md
 * (Apache-2.0; uebernommen sind nur Adressen und Faktoren, kein Code).
 * Jedes verwendete Register ist am Geraet geprueft.
 *
 * Eigenheiten des Geraets:
 * 1. 32-Bit-Werte: niedriges Wort zuerst.
 * 2. Je Eingang gibt es Spannung und Strom, aber keine Leistung. Die
 *    Leistung je Eingang ist deshalb U x I.
 * 3. Nachts ohne PV-Spannung ist der Stick aus. Dann bleiben die letzten
 *    Werte stehen; nach einer Weile ohne Antwort setzt das Modul die
 *    Leistungen auf 0 (die Abendleistung waere sonst die ganze Nacht da).
 */
class DeyeMikrowechselrichter extends IPSModule
{
    use \SymconDeye\VerbindungKette;

    // Modus eines ModBus Gateways nicht anfassen: per RS485 ist es oft RTU.
    private const VERBINDUNG = ['Port' => 8899, 'GatewayMode' => null, 'LoggerSerial' => true];
    private const SOLARMAN_GATEWAY = '{CAA0055F-135C-4BCB-A952-D42190C25CEC}';
    private const MODBUS_TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    // Darstellungen von IP-Symcon (/usr/share/symcon/presentations/)
    private const P_WERT = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const P_AUFZ = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
    private const P_ZEIT = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';

    private const BLOECKE = [[40, 40], [80, 40]];

    /** Register je Eingang: Spannung, Strom, Ertrag heute, Ertrag gesamt (lo, hi). */
    private const EINGAENGE = [
        1 => [109, 110, 65, 69],
        2 => [111, 112, 66, 71],
        3 => [113, 114, 67, 74],
        4 => [115, 116, 68, 77],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 10);
        $this->RegisterPropertyInteger('ZeroAfter', 300);
        $this->RegisterTimer('Update', 0, 'DEYE_Update($_IPS[\'TARGET\']);');
        $this->registriereVerbindung();

        $this->RequireParent(self::SOLARMAN_GATEWAY);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->registerVariables();
        $this->SetTimerInterval('Update', max(2, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->wendeVerbindungAn();
    }

    public function GetConfigurationForm()
    {
        return $this->formularMitVerbindung(__DIR__ . '/form.json');
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Liest alle Register und schreibt die Variablen. */
    public function Update(): bool
    {
        $r = [];
        foreach (self::BLOECKE as [$start, $anzahl]) {
            $teil = $this->readRegisters($start, $anzahl);
            if ($teil === null || count($teil) < $anzahl) {
                $this->leseFehler(sprintf('Block %d..%d nicht lesbar', $start, $start + $anzahl - 1));
                return false;
            }
            $r += $teil;
        }

        $this->SetValue('AC_P', self::u32($r, 86) * 0.1);
        $this->SetValue('AC_U', $r[73] * 0.1);
        $this->SetValue('AC_I', $r[76] * 0.1);
        $this->SetValue('AC_F', $r[79] * 0.01);
        $this->SetValue('TEMP', $r[90] * 0.01);
        $this->SetValue('E_HEUTE', $r[60] * 0.1);

        // Zaehlerstaende nie auf 0 setzen: eine 0 ist immer ein Lesefehler,
        // und das Archiv zaehlt sonst den ganzen Stand neu.
        $gesamt = self::u32($r, 63) * 0.1;
        if ($gesamt > 0) {
            $this->SetValue('E_GESAMT', $gesamt);
        }

        $dc = 0.0;
        foreach (self::EINGAENGE as $n => [$u, $i, $heute, $ges]) {
            $spannung = $r[$u] * 0.1;
            $strom = $r[$i] * 0.1;
            $p = $spannung * $strom;   // Eigenheit 2
            $dc += $p;
            $this->SetValue("PV{$n}_U", $spannung);
            $this->SetValue("PV{$n}_I", $strom);
            $this->SetValue("PV{$n}_P", round($p, 1));
            $this->SetValue("PV{$n}_E_HEUTE", $r[$heute] * 0.1);
            $g = self::u32($r, $ges) * 0.1;
            if ($g > 0) {
                $this->SetValue("PV{$n}_E_GESAMT", $g);
            }
        }
        $this->SetValue('DC_P', round($dc, 1));

        $this->SetValue('STAND', time());
        $this->SetValue('STATUS', true);
        return true;
    }

    /** Fuer "Verbindung testen" (libs/VerbindungKette.php). */
    private function hinweisOhneAntwort(): string
    {
        return 'Nachts ohne PV-Spannung ist der Stick aus. Tagsüber Adresse und Seriennummer des Sticks prüfen.';
    }

    // ===================== Lesen =====================

    /**
     * Lesefehler: zuerst nur den Status setzen. Kurze Aussetzer sollen keine
     * Nullen ins Archiv schreiben (Eigenheit 3).
     */
    private function leseFehler(string $text): void
    {
        $this->SendDebug('Update', $text, 0);
        $this->SetValue('STATUS', false);

        $nach = $this->ReadPropertyInteger('ZeroAfter');
        if ($nach <= 0 || time() - (int) $this->GetValue('STAND') < $nach) {
            return;
        }
        // Spannung, Temperatur und Ertrag bleiben stehen; sie stimmen auch nachts.
        $idents = ['AC_P', 'DC_P', 'AC_I'];
        foreach (array_keys(self::EINGAENGE) as $n) {
            $idents[] = "PV{$n}_P";
            $idents[] = "PV{$n}_I";
        }
        foreach ($idents as $ident) {
            if ($this->GetValue($ident) != 0) {
                $this->SetValue($ident, 0.0);
            }
        }
    }

    /** Liest Holding-Register (Funktion 3). Gibt [Adresse => Wert] oder null. */
    private function readRegisters(int $start, int $anzahl): ?array
    {
        $daten = $this->sendModbus(3, $start, $anzahl);
        if ($daten === null) {
            return null;
        }
        $reg = [];
        for ($i = 0; $i + 1 < strlen($daten) && intdiv($i, 2) < $anzahl; $i += 2) {
            $reg[$start + intdiv($i, 2)] = (ord($daten[$i]) << 8) | ord($daten[$i + 1]);
        }
        return $reg;
    }

    /**
     * Eine Leseanfrage ans Gateway. Gibt die Registerdaten ohne Funktionscode
     * und Byteanzahl zurueck oder null bei Fehler.
     */
    private function sendModbus(int $funktion, int $adresse, int $anzahl): ?string
    {
        if (!$this->HasActiveParent()) {
            $this->weckeSocket();
            return null;
        }
        $fehler = '';
        set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
            $fehler = $text;
            return true;
        });
        try {
            $antwort = $this->SendDataToParent(json_encode([
                'DataID'   => self::MODBUS_TX,
                'Function' => $funktion,
                'Address'  => $adresse,
                'Quantity' => $anzahl,
                'Data'     => '',
            ]));
        } catch (Throwable $e) {
            $fehler = $e->getMessage();
            $antwort = false;
        } finally {
            restore_error_handler();
        }
        // Das Solarman V5 Gateway liefert die Antwort UTF-8-kodiert (aus PHP
        // kommen keine rohen Bytes durch), das ModBus Gateway roh.
        if (is_string($antwort) && !self::stimmig($antwort, $funktion) && mb_check_encoding($antwort, 'UTF-8')) {
            $roh = mb_convert_encoding($antwort, 'ISO-8859-1', 'UTF-8');
            if (self::stimmig($roh, $funktion)) {
                $antwort = $roh;
            }
        }
        if (!is_string($antwort) || strlen($antwort) < 2) {
            $this->SendDebug('Modbus', sprintf('F%d %d/%d: %s', $funktion, $adresse, $anzahl, $fehler ?: 'keine Antwort'), 0);
            return null;
        }
        if (ord($antwort[0]) & 0x80) {
            $this->SendDebug('Modbus', sprintf('F%d %d/%d: Ausnahme %d', $funktion, $adresse, $anzahl, ord($antwort[1])), 0);
            return null;
        }
        // Funktionscode, Byteanzahl, dann die Daten.
        return substr($antwort, 2);
    }

    /** Passt die Laenge zur Antwort auf eine Leseanfrage (bzw. Ausnahme)? */
    private static function stimmig(string $a, int $funktion): bool
    {
        if (strlen($a) < 2) {
            return false;
        }
        if (ord($a[0]) === ($funktion | 0x80)) {
            return strlen($a) === 2;
        }
        return ord($a[0]) === $funktion && strlen($a) === 2 + ord($a[1]);
    }

    // ===================== Variablen =====================

    private function registerVariables(): void
    {
        $pos = 0;
        $v = function (string $ident, string $name, int $typ, array $praes) use (&$pos): void {
            $pos += 10;
            $this->registerVariable($ident, $name, $typ, $praes, $pos);
        };

        $v('STATUS', 'Verbindung', 0, self::pAufz([0 => 'getrennt', 1 => 'verbunden'], 'Network'));
        $v('STAND', 'Letzte Aktualisierung', 1, self::pZeit());

        $v('AC_P', 'AC-Wirkleistung', 2, self::pWert(' W', 1, 'Lightning', 0, 2000));
        $v('DC_P', 'DC-Leistung gesamt', 2, self::pWert(' W', 1, 'EnergySolar', 0, 2000));
        $v('AC_U', 'Netzspannung', 2, self::pWert(' V', 1, 'Power', 0, 280));
        $v('AC_I', 'Netzstrom', 2, self::pWert(' A', 2, 'Electricity', 0, 16));
        $v('AC_F', 'Netzfrequenz', 2, self::pWert(' Hz', 2, 'Gauge', 45, 55));
        $v('TEMP', 'Kühlkörpertemperatur', 2, self::pWert(' °C', 1, 'Temperature', -20, 100));

        $v('E_HEUTE', 'Ertrag heute', 2, self::pWert(' kWh', 2, 'EnergyProduction', 0, 20));
        $v('E_GESAMT', 'Ertrag gesamt', 2, self::pWert(' kWh', 1, 'Gauge'));

        foreach (array_keys(self::EINGAENGE) as $n) {
            $v("PV{$n}_U", "Eingang $n Spannung", 2, self::pWert(' V', 1, 'Power', 0, 60));
            $v("PV{$n}_I", "Eingang $n Strom", 2, self::pWert(' A', 2, 'Electricity', 0, 20));
            $v("PV{$n}_P", "Eingang $n Leistung", 2, self::pWert(' W', 0, 'Lightning', 0, 600));
            $v("PV{$n}_E_HEUTE", "Eingang $n Ertrag heute", 2, self::pWert(' kWh', 2, 'EnergyProduction', 0, 10));
            $v("PV{$n}_E_GESAMT", "Eingang $n Ertrag gesamt", 2, self::pWert(' kWh', 1, 'Gauge'));
        }
    }

    private function registerVariable(string $ident, string $name, int $typ, array $praes, int $pos): void
    {
        switch ($typ) {
            case 0:
                $this->RegisterVariableBoolean($ident, $name, $praes, $pos);
                break;
            case 1:
                $this->RegisterVariableInteger($ident, $name, $praes, $pos);
                break;
            default:
                $this->RegisterVariableFloat($ident, $name, $praes, $pos);
        }
        $icon = $praes['ICON'] ?? '';
        $id = $this->GetIDForIdent($ident);
        if ($icon !== '' && IPS_GetObject($id)['ObjectIcon'] !== $icon) {
            IPS_SetIcon($id, $icon);
        }
    }

    private static function pWert(string $suffix, int $digits, string $icon, float $min = 0.0, float $max = 0.0): array
    {
        return ['PRESENTATION' => self::P_WERT, 'SUFFIX' => $suffix, 'DIGITS' => $digits,
                'ICON' => $icon, 'MIN' => $min, 'MAX' => $max];
    }

    private static function pAufz(array $optionen, string $icon): array
    {
        $o = [];
        foreach ($optionen as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => self::P_AUFZ, 'OPTIONS' => json_encode($o), 'ICON' => $icon, 'LAYOUT' => 0];
    }

    private static function pZeit(string $icon = 'Clock'): array
    {
        return ['PRESENTATION' => self::P_ZEIT, 'DATE' => 2, 'TIME' => 1, 'MONTH_TEXT' => false, 'ICON' => $icon];
    }

    /** 32 Bit, niedriges Wort zuerst (Eigenheit 1). */
    private static function u32(array $r, int $lo): int
    {
        return ($r[$lo] ?? 0) | (($r[$lo + 1] ?? 0) << 16);
    }
}
