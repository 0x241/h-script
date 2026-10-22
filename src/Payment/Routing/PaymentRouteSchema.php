<?php

declare(strict_types=1);
namespace HScript\Payment\Routing;
use HScript\Database\Connection;
use HScript\Payment\Provider\ProviderNetworkSchema;
use RuntimeException;
final class PaymentRouteSchema
{
    public static function ensure(Connection $db): void
    {
        $engine=$db->fetch1($db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Currs'"));
        if(strtolower((string)$engine)!=='innodb')throw new RuntimeException('Payment routes require InnoDB currencies');
        $column=$db->fetch1Row($db->query("SHOW FULL COLUMNS FROM Currs WHERE Field='cPaymentRoutes'"));
        if(!$column)$db->query('ALTER TABLE Currs ADD COLUMN cPaymentRoutes text NULL');
        elseif(strtolower($column['Type'])!=='text'||$column['Null']!=='YES'||!in_array($column['Default'],[null,'NULL'],true)||$column['Extra']!=='')throw new RuntimeException('Incompatible payment routes column');
        ProviderNetworkSchema::ensure($db);
    }
}
