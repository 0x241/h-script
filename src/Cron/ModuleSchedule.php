<?php

namespace HScript\Cron;

use HScript\Database\Connection;
use RuntimeException;

/** Persistent schedule claims; all entry points use the same database lock. */
final class ModuleSchedule
{
    private ?string $lockName = null;

    public function __construct(private Connection $db) {}

    public function acquire(): bool
    {
        $name = $this->db->fetch1($this->required("SELECT CONCAT('hscript-cron-', LEFT(SHA2(DATABASE(),256),40))"));
        $locked = $this->db->fetch1($this->required('SELECT GET_LOCK(?,0)', [(string)$name]));
        if ((string)$locked === '0') return false;
        if ((string)$locked !== '1') throw new RuntimeException('cron_lock_unavailable');
        $this->lockName = (string)$name;
        return true;
    }

    public function release(): void
    {
        if ($this->lockName === null) return;
        $this->required('SELECT RELEASE_LOCK(?)', [$this->lockName]);
        $this->lockName = null;
    }

    public function claim(string $module, string $now, string $next): bool
    {
        $this->required("INSERT IGNORE INTO Cfg (Module,Prop,Val) VALUES ('Cron',?, '0')", [$module]);
        $this->required("UPDATE Cfg SET Val=? WHERE Module='Cron' AND Prop=? AND CAST(Val AS UNSIGNED)<=?", [$next, $module, $now]);
        if ($this->db->affCount() !== 1) return false;
        if ($this->db->replace('Cfg', ['Module' => 'Cron', 'Prop' => 'Last_' . $module, 'Val' => $now]) === false)
            throw new RuntimeException('cron_schedule_storage_failed');
        return true;
    }

    private function required(string $sql, array $params = []): mixed
    {
        $result = $this->db->query($sql, $params);
        if ($result === false) throw new RuntimeException('cron_schedule_storage_failed');
        return $result;
    }
}
