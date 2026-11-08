<?php
declare(strict_types=1);

namespace ResourceHub;

use DomainException;
use PDO;

final class ProjectService
{
    public function __construct(private readonly PDO $db) {}

    public function listFor(array $actor): array
    {
        $sql = 'SELECT p.id,p.owner_id,p.name,p.description,p.status,p.created_at,u.name AS owner,
                       COUNT(r.id) AS request_count
                FROM project_spaces p JOIN users u ON u.id=p.owner_id
                LEFT JOIN resource_requests r ON r.project_id=p.id';
        $params=[];
        if ($actor['role'] !== 'admin') { $sql .= ' WHERE p.owner_id=?'; $params[]=(int)$actor['id']; }
        $sql .= ' GROUP BY p.id,p.owner_id,p.name,p.description,p.status,p.created_at,u.name ORDER BY p.id DESC';
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }

    public function ownedActive(int $projectId, array $actor): bool
    {
        $stmt=$this->db->prepare("SELECT 1 FROM project_spaces WHERE id=? AND owner_id=? AND status='active'");
        $stmt->execute([$projectId,(int)$actor['id']]);
        return (bool)$stmt->fetchColumn();
    }

    public function create(array $input,array $actor): int
    {
        $name=Validation::text($input['name']??null);
        $description=Validation::text($input['description']??null);
        if (strlen($name)<3 || strlen($name)>120 || strlen($description)>500) {
            throw new DomainException('Project name must be 3–120 characters and description max. 500.');
        }
        $stmt=$this->db->prepare('INSERT INTO project_spaces (owner_id,name,description) VALUES (?,?,?)');
        $stmt->execute([(int)$actor['id'],$name,$description]);
        return (int)$this->db->lastInsertId();
    }

    public function archive(int $id,array $actor): void
    {
        $stmt=$this->db->prepare("UPDATE project_spaces SET status='archived' WHERE id=? AND owner_id=? AND status='active'");
        $stmt->execute([$id,(int)$actor['id']]);
        if ($stmt->rowCount()!==1) { throw new DomainException('Project not found or already archived.'); }
    }
}
