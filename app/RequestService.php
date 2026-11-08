<?php
declare(strict_types=1);

namespace ResourceHub;

use DomainException;
use InvalidArgumentException;
use PDO;

/** Request workflow, authorization and capacity-aware allocations. */
final class RequestService
{
    public function __construct(private readonly PDO $db) {}

    public function listFor(array $user, ?string $status = null, string $query = '', ?string $priority = null, ?int $projectId = null): array
    {
        $status = Validation::statusFilter($status);
        if ($priority !== null && !in_array($priority, Validation::PRIORITIES, true)) {
            throw new InvalidArgumentException('Invalid priority filter.');
        }
        if (strlen($query) > 100) { throw new InvalidArgumentException('Search term is too long.'); }
        $sql = 'SELECT r.id, r.title, r.resource_type, r.quantity, r.priority, r.status,
                       r.created_at, u.name AS requester, p.name AS pool_name, pr.name AS project_name
                FROM resource_requests r JOIN users u ON u.id=r.user_id
                LEFT JOIN resource_pools p ON p.id=r.resource_pool_id
                LEFT JOIN project_spaces pr ON pr.id=r.project_id';
        $conditions=[]; $values=[];
        if ($user['role'] !== 'admin') { $conditions[]='r.user_id=?'; $values[]=(int)$user['id']; }
        if ($status !== null) { $conditions[]='r.status=?'; $values[]=$status; }
        if ($priority !== null) { $conditions[]='r.priority=?'; $values[]=$priority; }
        if ($projectId !== null) { $conditions[]='r.project_id=?'; $values[]=$projectId; }
        if ($query !== '') { $conditions[]='(r.title LIKE ? OR r.purpose LIKE ?)'; $values[]='%'.$query.'%'; $values[]='%'.$query.'%'; }
        if ($conditions) { $sql.=' WHERE '.implode(' AND ',$conditions); }
        $sql.=' ORDER BY r.created_at DESC,r.id DESC LIMIT 200';
        $stmt=$this->db->prepare($sql); $stmt->execute($values); return $stmt->fetchAll();
    }

    public function countsFor(array $user): array
    {
        $sql='SELECT status,COUNT(*) AS total FROM resource_requests'; $params=[];
        if ($user['role']!=='admin') { $sql.=' WHERE user_id=?'; $params[]=(int)$user['id']; }
        $sql.=' GROUP BY status';
        $stmt=$this->db->prepare($sql);$stmt->execute($params);
        $counts=array_fill_keys(Validation::STATUSES,0);
        foreach($stmt->fetchAll() as $row) { $counts[$row['status']]=(int)$row['total']; }
        $counts['all']=array_sum($counts);return $counts;
    }

    public function find(int $id,array $user): ?array
    {
        $stmt=$this->db->prepare('SELECT r.*,u.name AS requester,u.email AS requester_email,
                p.name AS pool_name,p.code AS pool_code,p.unit AS pool_unit,pr.name AS project_name
                FROM resource_requests r JOIN users u ON u.id=r.user_id
                LEFT JOIN resource_pools p ON p.id=r.resource_pool_id
                LEFT JOIN project_spaces pr ON pr.id=r.project_id WHERE r.id=?');
        $stmt->execute([$id]);$row=$stmt->fetch();
        if (!$row || ($user['role']!=='admin' && (int)$row['user_id']!==(int)$user['id'])) { return null; }
        return $row;
    }

    public function auditFor(int $id,array $user): array
    {
        if ($this->find($id,$user)===null) { throw new DomainException('Request not found or access denied.'); }
        $stmt=$this->db->prepare('SELECT a.action,a.comment,a.created_at,u.name
                  FROM request_audit a JOIN users u ON u.id=a.actor_id
                  WHERE a.request_id=? ORDER BY a.id DESC');
        $stmt->execute([$id]);return $stmt->fetchAll();
    }

    public function commentsFor(int $id,array $user): array
    {
        if ($this->find($id,$user)===null) { throw new DomainException('Request not found or access denied.'); }
        $stmt=$this->db->prepare('SELECT c.body,c.created_at,u.name,u.role FROM request_comments c
                  JOIN users u ON u.id=c.author_id WHERE c.request_id=? ORDER BY c.id ASC');
        $stmt->execute([$id]);return $stmt->fetchAll();
    }

    public function addComment(int $id,array $actor,string $body): void
    {
        if ($this->find($id,$actor)===null) { throw new DomainException('Request not found or access denied.'); }
        $body=trim($body);
        if ($this->length($body)<2 || $this->length($body)>1500) { throw new DomainException('Message must be 2–1500 characters.'); }
        $stmt=$this->db->prepare('INSERT INTO request_comments (request_id,author_id,body) VALUES (?,?,?)');
        $stmt->execute([$id,(int)$actor['id'],$body]);
    }

    public function create(array $data,array $actor): int
    {
        $validated=Validation::request($data);
        if ($validated['errors']) { throw new DomainException(implode(' ',$validated['errors'])); }
        $data=$validated['data'];
        return $this->transaction(function () use ($data,$actor): int {
            $poolId=$data['resource_pool_id'];
            if ($poolId!==null) {
                $stmt=$this->db->prepare('SELECT resource_type,active FROM resource_pools WHERE id=?');
                $stmt->execute([$poolId]);$pool=$stmt->fetch();
                if (!$pool || !(int)$pool['active'] || $pool['resource_type']!==$data['resource_type']) {
                    throw new DomainException('The selected resource pool is not available.');
                }
            }
            $projectId=$data['project_id'];
            if ($projectId!==null) {
                $projects=new ProjectService($this->db);
                if (!$projects->ownedActive($projectId,$actor)) { throw new DomainException('Select an active project that you own.'); }
            }
            $stmt=$this->db->prepare('INSERT INTO resource_requests
                (user_id,title,resource_type,quantity,purpose,resource_pool_id,project_id,priority,needed_from,needed_until)
                VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([(int)$actor['id'],$data['title'],$data['resource_type'],$data['quantity'],$data['purpose'],
                    $poolId,$projectId,$data['priority'],$data['needed_from'],$data['needed_until']]);
            $id=(int)$this->db->lastInsertId();
            $this->addAudit($id,(int)$actor['id'],'created','');
            return $id;
        });
    }

    /** The pool row lock serializes all allocations and releases for that pool. */
    public function decide(int $id,array $actor,string $decision,string $comment): void
    {
        if ($actor['role']!=='admin'||!Validation::decision($decision)) { throw new DomainException('Not authorized to review this request.'); }
        if ($this->length($comment)>500) { throw new DomainException('Comment is too long (max. 500 characters).'); }
        $this->transaction(function () use ($id,$actor,$decision,$comment): void {
            $poolId=$this->poolId($id);
            if ($poolId!==null) { $this->lockPool($poolId); }
            $stmt=$this->db->prepare('SELECT status,quantity FROM resource_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);$row=$stmt->fetch();
            if (!$row || $row['status']!=='pending') { throw new DomainException('Only pending requests can be reviewed.'); }
            if ($poolId!==null && $decision==='approved') {
                $stmt=$this->db->prepare('SELECT capacity,active FROM resource_pools WHERE id=?');
                $stmt->execute([$poolId]);$pool=$stmt->fetch();
                if (!$pool || !(int)$pool['active']) { throw new DomainException('This resource pool is inactive.'); }
                $used=$this->db->prepare("SELECT COALESCE(SUM(quantity),0) FROM resource_requests WHERE resource_pool_id=? AND status='approved'");
                $used->execute([$poolId]);
                if ((int)$used->fetchColumn()+(int)$row['quantity']>(int)$pool['capacity']) {
                    throw new DomainException('Insufficient available capacity for this allocation.');
                }
            }
            $stmt=$this->db->prepare('UPDATE resource_requests SET status=?,reviewed_by=?,review_comment=? WHERE id=?');
            $stmt->execute([$decision,(int)$actor['id'],$comment,$id]);
            $this->addAudit($id,(int)$actor['id'],$decision,$comment);
        });
    }

    /** Releasing an allocation restores pool capacity and preserves the audit trail. */
    public function release(int $id,array $actor): void
    {
        if ($actor['role']!=='admin') { throw new DomainException('Administrators only.'); }
        $this->transaction(function () use($id,$actor): void {
            $poolId=$this->poolId($id);
            if ($poolId!==null) { $this->lockPool($poolId); }
            $stmt=$this->db->prepare('SELECT status FROM resource_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()!=='approved') { throw new DomainException('Only approved allocations can be released.'); }
            $stmt=$this->db->prepare("UPDATE resource_requests SET status='released' WHERE id=?");$stmt->execute([$id]);
            $this->addAudit($id,(int)$actor['id'],'released','Allocation completed; capacity released.');
        });
    }

    public function cancel(int $id,array $actor): void
    {
        $this->transaction(function () use($id,$actor): void {
            $stmt=$this->db->prepare('SELECT user_id,status FROM resource_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);$row=$stmt->fetch();
            if (!$row||(int)$row['user_id']!==(int)$actor['id']||$row['status']!=='pending') {
                throw new DomainException('Only your pending requests can be cancelled.');
            }
            $stmt=$this->db->prepare("UPDATE resource_requests SET status='cancelled' WHERE id=?");$stmt->execute([$id]);
            $this->addAudit($id,(int)$actor['id'],'cancelled','');
        });
    }

    private function poolId(int $id): ?int
    {
        $stmt=$this->db->prepare('SELECT resource_pool_id FROM resource_requests WHERE id=? FOR UPDATE');$stmt->execute([$id]);
        $value=$stmt->fetchColumn();return ($value===false||$value===null)?null:(int)$value;
    }

    private function lockPool(int $id): void
    {
        $stmt=$this->db->prepare('SELECT id FROM resource_pools WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);if (!$stmt->fetchColumn()) { throw new DomainException('Resource pool not found.'); }
    }

    private function addAudit(int $id,int $actorId,string $action,string $comment): void
    {
        $stmt=$this->db->prepare('INSERT INTO request_audit (request_id,actor_id,action,comment) VALUES (?,?,?,?)');
        $stmt->execute([$id,$actorId,$action,$comment]);
    }
    private function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {$value=$work();$this->db->commit();return $value;}
        catch(\Throwable $error){$this->db->rollBack();throw $error;}
    }
    private function length(string $value): int
    { return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value); }
}
