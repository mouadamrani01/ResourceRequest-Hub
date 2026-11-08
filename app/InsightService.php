<?php
declare(strict_types=1);

namespace ResourceHub;

use PDO;

final class InsightService
{
    public function __construct(private readonly PDO $db) {}

    public function summary(array $actor): array
    {
        $sql='SELECT resource_type, status, COUNT(*) AS count, SUM(quantity) AS units FROM resource_requests';
        $params=[];
        if ($actor['role']!=='admin') { $sql.=' WHERE user_id=?'; $params[]=(int)$actor['id']; }
        $sql.=' GROUP BY resource_type,status ORDER BY resource_type,status';
        $stmt=$this->db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function monthly(array $actor): array
    {
        $sql="SELECT DATE_FORMAT(created_at,'%Y-%m') AS period,COUNT(*) AS count
              FROM resource_requests WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MONTH)";
        $params=[];
        if ($actor['role']!=='admin') { $sql.=' AND user_id=?';$params[]=(int)$actor['id']; }
        $sql.=' GROUP BY period ORDER BY period';
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
    }
}
