<?php

namespace HScript\Telemetry;

use HScript\Database\Connection;
use RuntimeException;

/** Claims an outbound attempt before network I/O, even if that I/O crashes. */
class TelemetryAttemptGate
{
    public const MANUAL_INTERVAL = 300;

    public function __construct(private Connection $db) {}

    public function claim(bool $manual = false): array
    {
        $now = time();
        $next = (int)$this->db->fetch1($this->required("SELECT Val FROM Cfg WHERE Module='Telemetry' AND Prop='NextAttemptAt'"));
        if (!$manual && $next > $now) return ['allowed' => false, 'retry_after' => $next - $now];
        $this->required("INSERT IGNORE INTO Cfg (Module,Prop,Val) VALUES ('Telemetry','NetworkAttemptAt','0')");
        $this->required("UPDATE Cfg SET Val=? WHERE Module='Telemetry' AND Prop='NetworkAttemptAt' AND CAST(Val AS UNSIGNED)<=?", [$now, $now - self::MANUAL_INTERVAL]);
        if ($this->db->affCount() === 1) return ['allowed' => true, 'retry_after' => 0];
        $last = (int)$this->db->fetch1($this->required("SELECT Val FROM Cfg WHERE Module='Telemetry' AND Prop='NetworkAttemptAt'"));
        return ['allowed' => false, 'retry_after' => max(1, $last + self::MANUAL_INTERVAL - $now)];
    }

    private function required(string $sql, array $params = []): mixed
    {
        $result = $this->db->query($sql, $params);
        if ($result === false) throw new RuntimeException('telemetry_attempt_storage_failed');
        return $result;
    }
}
