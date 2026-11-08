<?php
declare(strict_types=1);

namespace ResourceHub;

use DomainException;
use PDO;

/** Resource inventory. Capacity represents concurrent, approved allocations. */
final class ResourceService
{
    public function __construct(private readonly PDO $db) {}

    public function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT p.*, COALESCE(usage_data.allocated, 0) AS allocated,
                       p.capacity - COALESCE(usage_data.allocated, 0) AS available
                FROM resource_pools p LEFT JOIN (
                    SELECT resource_pool_id, SUM(quantity) AS allocated
                    FROM resource_requests WHERE status = \'approved\' AND resource_pool_id IS NOT NULL
                    GROUP BY resource_pool_id
                ) AS usage_data ON usage_data.resource_pool_id = p.id';
        if (!$includeInactive) { $sql .= ' WHERE p.active = 1'; }
        $sql .= ' ORDER BY p.resource_type, p.name';
        return $this->db->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        foreach ($this->all(true) as $resource) {
            if ((int) $resource['id'] === $id) { return $resource; }
        }
        return null;
    }

    public function save(array $input, array $actor): int
    {
        if ($actor['role'] !== 'admin') { throw new DomainException('Administrators only.'); }
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
        if ($id === false || $id < 0) { throw new DomainException('Invalid resource ID.'); }
        $name = Validation::text($input['name'] ?? '');
        $code = strtoupper(Validation::text($input['code'] ?? ''));
        $type = Validation::text($input['resource_type'] ?? '');
        $capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100000]]);
        $unit = Validation::text($input['unit'] ?? '');
        $location = Validation::text($input['location'] ?? '');
        $description = Validation::text($input['description'] ?? '');
        $active = ($input['active'] ?? '') === '1' ? 1 : 0;
        if (strlen($name) < 3 || strlen($name) > 120 || !preg_match('/^[A-Z0-9-]{2,32}$/', $code)
            || !in_array($type, Validation::TYPES, true) || $capacity === false
            || strlen($unit) < 1 || strlen($unit) > 30 || strlen($location) > 100 || strlen($description) > 500) {
            throw new DomainException('Check the resource name, code, type, capacity and other fields.');
        }
        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $this->db->prepare('SELECT id, resource_type FROM resource_pools WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $existing = $stmt->fetch();
                if (!$existing) { throw new DomainException('Resource does not exist.'); }
                $used = $this->db->prepare('SELECT COALESCE(SUM(quantity),0) FROM resource_requests WHERE resource_pool_id=? AND status=\'approved\'');
                $used->execute([$id]);
                if ($capacity < (int)$used->fetchColumn()) { throw new DomainException('Capacity cannot be lower than allocated units.'); }
                if ($existing['resource_type'] !== $type) {
                    $associated = $this->db->prepare('SELECT COUNT(*) FROM resource_requests WHERE resource_pool_id = ?');
                    $associated->execute([$id]);
                    if ((int)$associated->fetchColumn() > 0) { throw new DomainException('Cannot change type of a pool already used by requests.'); }
                }
                $stmt = $this->db->prepare('UPDATE resource_pools SET code=?, name=?, resource_type=?, capacity=?, unit=?, location=?, description=?, active=? WHERE id=?');
                $stmt->execute([$code,$name,$type,$capacity,$unit,$location,$description,$active,$id]);
            } else {
                $stmt = $this->db->prepare('INSERT INTO resource_pools (code,name,resource_type,capacity,unit,location,description,active) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$code,$name,$type,$capacity,$unit,$location,$description,$active]);
                $id = (int)$this->db->lastInsertId();
            }
            $this->db->commit();
            return (int)$id;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
