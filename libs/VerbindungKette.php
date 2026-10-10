<?php

declare(strict_types=1);

namespace SymconDeye;

/**
 * Verbindungsdaten im Formular des Geraets.
 *
 * Adresse, Port, Unit-ID und "Verbindung aktiv" (beim Solarman-Stick auch
 * dessen Seriennummer) sind Eigenschaften des Geraets. Das Geraet traegt sie
 * selbst in das Gateway und den Client Socket darueber ein; beide werden
 * nicht mehr von Hand eingestellt.
 *
 * Warum nicht nur GetConfigurationForParent: Das erreicht nur die direkte
 * Elterninstanz, nicht den Socket dahinter. Und die Konsole uebernimmt die
 * Werte nicht von selbst, sie sperrt nur die Felder. GetConfigurationForParent
 * liefert trotzdem die Gateway-Werte, damit die Felder dort gesperrt sind.
 *
 * Eingetragen wird nur, was abweicht. Stimmt alles, wird weder Gateway noch
 * Socket neu angewendet; die Verbindung bleibt stehen.
 *
 * Bestehende Instanzen (Adresse bisher im Socket) uebernehmen die Werte
 * einmal aus Gateway und Socket in die eigenen Eigenschaften.
 *
 * Die Klasse legt die Vorgaben in VERBINDUNG fest:
 *   'Port'         => Vorgabe fuer neue Instanzen
 *   'GatewayMode'  => Modus des ModBus Gateways (0 = Modbus TCP), null = nicht anfassen
 *   'LoggerSerial' => true, wenn das Geraet hinter einem Solarman V5 Gateway haengen kann
 * und implementiert hinweisOhneAntwort() und Update(): bool.
 *
 * Die Datei ist in SymconDeye, SymconSolakonONE und SymconMennekesAmtron
 * gleich, bis auf den Namensraum: Gleichnamige Traits zweier Bibliotheken
 * vertragen sich nicht in einem PHP-Lauf.
 */
trait VerbindungKette
{
    /** Hinweis fuer "Verbindung testen", wenn das Geraet nicht antwortet. */
    abstract private function hinweisOhneAntwort(): string;

    /** Im Create() aufrufen. */
    private function registriereVerbindung(): void
    {
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', self::VERBINDUNG['Port']);
        $this->RegisterPropertyInteger('DeviceID', 1);
        $this->RegisterPropertyBoolean('Open', true);
        if (self::VERBINDUNG['LoggerSerial']) {
            $this->RegisterPropertyString('LoggerSerial', '');
        }
        $this->RegisterAttributeBoolean('VerbindungUebernommen', false);
    }

    /** Am Ende von ApplyChanges() aufrufen, setzt auch den Status. */
    private function wendeVerbindungAn(): void
    {
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            // Andere Instanzen erst anwenden, wenn der Kernel steht.
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            $this->SetStatus(102);
            return;
        }
        $this->gleicheKetteAb();
        $this->SetStatus($this->verbindungsStatus());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->UnregisterMessage(0, IPS_KERNELSTARTED);
        } elseif ($Message !== FM_CONNECT || $SenderID !== $this->InstanceID) {
            return;
        }
        $this->gleicheKetteAb();
        $this->SetStatus($this->verbindungsStatus());
    }

    public function GetConfigurationForParent()
    {
        if (!$this->ReadAttributeBoolean('VerbindungUebernommen')) {
            return '{}';
        }
        [$gw] = $this->kette();
        return $gw > 0 ? json_encode($this->gatewayWerte($gw)) : '{}';
    }

    /**
     * Prueft Gateway und Socket und liest einmal alle Werte. Fuer den Knopf
     * im Formular; das Ergebnis steht in der Ausgabe.
     */
    public function TestConnection(): bool
    {
        [$gw, $so] = $this->kette();
        $fehler = '';
        if ($gw === 0) {
            $fehler = 'Die Instanz hängt an keinem Gateway.';
        } elseif ($so > 0 && trim((string) IPS_GetProperty($so, 'Host')) === '') {
            $fehler = 'Keine Adresse eingetragen (oder Änderungen noch nicht übernommen).';
        } elseif ($so > 0 && !IPS_GetProperty($so, 'Open')) {
            $fehler = 'Die Verbindung ist ausgeschaltet (Haken „Verbindung aktiv").';
        } elseif ($so > 0 && IPS_GetInstance($so)['InstanceStatus'] !== 102) {
            $fehler = sprintf(
                'Der Client Socket erreicht %s:%d nicht (Status %d). %s',
                IPS_GetProperty($so, 'Host'),
                IPS_GetProperty($so, 'Port'),
                IPS_GetInstance($so)['InstanceStatus'],
                $this->hinweisOhneAntwort()
            );
        }
        if ($fehler === '') {
            $start = microtime(true);
            if ($this->Update()) {
                $this->UpdateFormField('LetzterAbruf', 'caption', $this->textLetzterAbruf());
                echo sprintf('Verbindung in Ordnung: alle Werte in %d ms gelesen.', (int) round((microtime(true) - $start) * 1000));
                return true;
            }
            $fehler = 'Das Gerät antwortet nicht (Unit-ID richtig?). ' . $this->hinweisOhneAntwort();
        }
        echo $fehler;
        return false;
    }

    /** form.json laden und die Zeile "Letzter erfolgreicher Abruf" fuellen. */
    private function formularMitVerbindung(string $datei): string
    {
        $form = json_decode((string) file_get_contents($datei), true);
        $text = $this->textLetzterAbruf();
        $setze = static function (array &$liste) use (&$setze, $text): void {
            foreach ($liste as &$element) {
                if (($element['name'] ?? '') === 'LetzterAbruf') {
                    $element['caption'] = $text;
                }
                if (isset($element['items']) && is_array($element['items'])) {
                    $setze($element['items']);
                }
            }
        };
        $setze($form['elements']);
        return (string) json_encode($form);
    }

    private function textLetzterAbruf(): string
    {
        $id = @IPS_GetObjectIDByIdent('STAND', $this->InstanceID);
        $zeit = ($id !== false && $id > 0) ? (int) GetValue($id) : 0;
        if ($zeit <= 0) {
            return 'Letzter erfolgreicher Abruf: noch keiner';
        }
        $alter = max(0, time() - $zeit);
        if ($alter < 120) {
            $vor = $alter . ' s';
        } elseif ($alter < 7200) {
            $vor = intdiv($alter, 60) . ' min';
        } elseif ($alter < 172800) {
            $vor = intdiv($alter, 3600) . ' h';
        } else {
            $vor = intdiv($alter, 86400) . ' Tagen';
        }
        return sprintf('Letzter erfolgreicher Abruf: %s (vor %s)', date('d.m.Y H:i:s', $zeit), $vor);
    }

    // ===================== Gateway und Socket =====================

    /** [Gateway, Client Socket], jeweils 0, wenn nicht vorhanden. */
    private function kette(): array
    {
        $gw = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($gw <= 0 || !IPS_InstanceExists($gw)) {
            return [0, 0];
        }
        $so = (int) IPS_GetInstance($gw)['ConnectionID'];
        if ($so <= 0 || !IPS_InstanceExists($so)
            || IPS_GetInstance($so)['ModuleInfo']['ModuleID'] !== '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}') {
            $so = 0;   // kein Client Socket (z. B. serielle Schnittstelle): Adresse entfaellt
        }
        return [$gw, $so];
    }

    /** Was das Geraet im Gateway vorgibt, je nach Gateway-Modul. */
    private function gatewayWerte(int $gw): array
    {
        $werte = ['DeviceID' => $this->ReadPropertyInteger('DeviceID')];
        switch (IPS_GetInstance($gw)['ModuleInfo']['ModuleID']) {
            case '{A5F663AB-C400-4FE5-B207-4D67CC030564}':   // ModBus Gateway
                if (self::VERBINDUNG['GatewayMode'] !== null) {
                    $werte['GatewayMode'] = self::VERBINDUNG['GatewayMode'];
                }
                return $werte;
            case '{CAA0055F-135C-4BCB-A952-D42190C25CEC}':   // Solarman V5 Gateway
                if (self::VERBINDUNG['LoggerSerial']) {
                    $werte['LoggerSerial'] = trim($this->ReadPropertyString('LoggerSerial'));
                }
                return $werte;
            default:
                return [];   // fremdes Gateway: nichts anfassen
        }
    }

    private function gleicheKetteAb(): void
    {
        [$gw, $so] = $this->kette();
        if ($gw === 0) {
            return;
        }
        if (!$this->ReadAttributeBoolean('VerbindungUebernommen')) {
            if (trim($this->ReadPropertyString('Host')) === '' && $so > 0
                && trim((string) IPS_GetProperty($so, 'Host')) !== '') {
                $this->uebernimmAusKette($gw, $so);
                return;
            }
            $this->WriteAttributeBoolean('VerbindungUebernommen', true);
        }

        $this->trageEin($gw, $this->gatewayWerte($gw));
        if ($so > 0) {
            $host = trim($this->ReadPropertyString('Host'));
            $this->trageEin($so, [
                'Host' => $host,
                'Port' => $this->ReadPropertyInteger('Port'),
                'Open' => $host !== '' && $this->ReadPropertyBoolean('Open'),
            ]);
        }
    }

    /**
     * Einmalig fuer bestehende Instanzen: die Werte aus Gateway und Socket
     * werden Eigenschaften des Geraets. Das Anwenden laeuft ueber einen
     * Einmal-Timer, weil es hier mitten in ApplyChanges() steht.
     */
    private function uebernimmAusKette(int $gw, int $so): void
    {
        $werte = [
            'Host' => trim((string) IPS_GetProperty($so, 'Host')),
            'Port' => (int) IPS_GetProperty($so, 'Port'),
            'Open' => (bool) IPS_GetProperty($so, 'Open'),
        ];
        foreach (array_keys($this->gatewayWerte($gw)) as $name) {
            if ($name !== 'GatewayMode') {
                $werte[$name] = IPS_GetProperty($gw, $name);
            }
        }
        $skript = '';
        foreach ($werte as $name => $wert) {
            $skript .= sprintf('IPS_SetProperty(%d, %s, %s);', $this->InstanceID, var_export($name, true), var_export($wert, true));
        }
        $skript .= sprintf('IPS_ApplyChanges(%d);', $this->InstanceID);
        $this->SendDebug('Verbindung', 'Übernehme aus Gateway und Socket: ' . implode(', ', array_keys($werte)), 0);
        $this->RegisterOnceTimer('VerbindungUebernahme', $skript);
    }

    /** Setzt abweichende Eigenschaften und wendet die Instanz nur dann an. */
    private function trageEin(int $id, array $werte): void
    {
        $geaendert = [];
        foreach ($werte as $name => $neu) {
            $alt = IPS_GetProperty($id, $name);
            settype($alt, gettype($neu));
            if ($alt !== $neu) {
                IPS_SetProperty($id, $name, $neu);
                $geaendert[] = $name;
            }
        }
        if ($geaendert === []) {
            return;
        }
        $this->SendDebug('Verbindung', sprintf('#%d: %s neu eingetragen', $id, implode(', ', $geaendert)), 0);
        // Ein Socket, der sein Ziel nicht erreicht, meldet das beim Anwenden
        // als Warnung. Das soll das Speichern des Geraets nicht abbrechen.
        $fehler = '';
        set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
            $fehler = $text;
            return true;
        });
        try {
            IPS_ApplyChanges($id);
        } catch (\Throwable $e) {
            $fehler = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if ($fehler !== '') {
            $this->SendDebug('Verbindung', sprintf('#%d: %s', $id, $fehler), 0);
        }
    }

    private function verbindungsStatus(): int
    {
        if (!$this->ReadAttributeBoolean('VerbindungUebernommen')) {
            return 102;   // Uebernahme laeuft noch
        }
        [$gw, $so] = $this->kette();
        if ($so > 0 && trim($this->ReadPropertyString('Host')) === '') {
            return 201;
        }
        if ($gw > 0 && isset($this->gatewayWerte($gw)['LoggerSerial'])
            && !preg_match('/^[0-9]{1,10}$/', trim($this->ReadPropertyString('LoggerSerial')))) {
            return 202;
        }
        if ($so > 0 && !$this->ReadPropertyBoolean('Open')) {
            return 104;
        }
        return 102;
    }
}
