<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ResourceHub\Auth;
use ResourceHub\Database;
use ResourceHub\LoginLimiter;
use ResourceHub\RequestService;
use ResourceHub\ResourceService;
use ResourceHub\ProjectService;
use ResourceHub\InsightService;

// Never run destructive fixtures against the normal application database.
$name = getenv('DB_NAME');
if (!is_string($name) || !str_ends_with($name, '_test')) {
    fwrite(STDERR, "Set DB_NAME to a dedicated database ending in _test before running integration tests.\n");
    exit(2);
}

$db = Database::connection();
foreach (['request_comments','request_audit','resource_requests','project_spaces','resource_pools','login_throttles','users'] as $table) {
    $db->exec('DELETE FROM ' . $table);
}

$checks = 0;
function expect(bool $value, string $message): void
{
    global $checks;
    $checks++;
    if (!$value) {
        throw new RuntimeException('Integration check failed: ' . $message);
    }
}
function account(PDO $db, string $name, string $email, string $role): array
{
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    // A fixed password is acceptable ONLY in this disposable test database.
    $stmt->execute([$name, $email, password_hash('test_password_123456', PASSWORD_DEFAULT), $role]);
    return ['id' => (int) $db->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role];
}
function throwsDomain(callable $operation, string $message): void
{
    try {
        $operation();
        expect(false, $message);
    } catch (DomainException) {
        expect(true, $message);
    }
}

$admin = account($db, 'Demo Admin', 'admin@example.test', 'admin');
$ada = account($db, 'Ada Researcher', 'ada@example.test', 'member');
$ben = account($db, 'Ben Researcher', 'ben@example.test', 'member');
$service = new RequestService($db);

expect($service->countsFor($admin)['all'] === 0, 'empty database count');
expect(password_verify('test_password_123456', $db->query("SELECT password_hash FROM users WHERE role = 'admin'")->fetchColumn()), 'password stored as a hash');

$gpu = [
    'title' => 'GPU training for anomaly detection',
    'resource_type' => 'GPU',
    'quantity' => 2,
    'purpose' => 'Benchmarking model detection quality on anonymized transaction data.',
];
$gpuId = $service->create($gpu, $ada);
expect($gpuId > 0, 'request inserted');
expect($service->find($gpuId, $ada)['status'] === 'pending', 'new request begins pending');
expect($service->find($gpuId, $ben) === null, 'different member cannot view request');
expect($service->find($gpuId, $admin) !== null, 'administrator may view request');
expect(count($service->listFor($ada)) === 1, 'owner sees own request');
expect(count($service->listFor($ben)) === 0, 'other member sees no requests');
expect(count($service->listFor($admin)) === 1, 'administrator sees all requests');
expect(count($service->auditFor($gpuId, $ada)) === 1, 'creation is audited');
throwsDomain(fn () => $service->auditFor($gpuId, $ben), 'audit history is access-controlled');
throwsDomain(fn () => $service->decide($gpuId, $ada, 'approved', ''), 'member cannot approve');
throwsDomain(fn () => $service->cancel($gpuId, $ben), 'member cannot cancel someone else\'s request');
throwsDomain(fn () => $service->decide($gpuId, $admin, 'pending', ''), 'invalid decision blocked');
throwsDomain(fn () => $service->create(['title' => 'x'], $ada), 'service validates data independent of HTML');

$service->decide($gpuId, $admin, 'approved', 'GPU nodes available next week.');
expect($service->find($gpuId, $ada)['status'] === 'approved', 'approval is persisted');
expect(count($service->auditFor($gpuId, $ada)) === 2, 'approval recorded in audit');
expect($service->countsFor($ada)['approved'] === 1, 'owner status count');
throwsDomain(fn () => $service->decide($gpuId, $admin, 'rejected', ''), 'reviewed request cannot be reviewed twice');
throwsDomain(fn () => $service->cancel($gpuId, $ada), 'approved request cannot be cancelled');

$storageId = $service->create([
    'title' => 'Storage for simulation results',
    'resource_type' => 'Storage',
    'quantity' => 5,
    'purpose' => 'Retaining reproducible datasets and benchmark output.',
], $ada);
$service->cancel($storageId, $ada);
expect($service->find($storageId, $admin)['status'] === 'cancelled', 'owner cancellation persisted');
expect(count($service->auditFor($storageId, $admin)) === 2, 'cancellation audited');

$cpuId = $service->create([
    'title' => 'CPU for finite-element simulation',
    'resource_type' => 'CPU',
    'quantity' => 16,
    'purpose' => 'Testing convergence of numerical models on a compute cluster.',
], $ben);
$service->decide($cpuId, $admin, 'rejected', 'Requested allocation exceeds project quota.');
expect($service->countsFor($admin)['all'] === 3, 'all request count');
expect(count($service->listFor($admin, 'approved')) === 1, 'status filtering');
expect($service->countsFor($ada)['all'] === 2, 'member count excludes other member');
expect($service->countsFor($ben)['all'] === 1, 'second member count');

$limiter = new LoginLimiter($db);
$email = 'example@example.test';
$ip = '192.0.2.10';
expect($limiter->allows($email, $ip), 'fresh login bucket allowed');
for ($i = 0; $i < 8; $i++) {
    $limiter->recordFailure($email, $ip);
}
expect(!$limiter->allows($email, $ip), 'eight failures trigger throttle');
expect($limiter->allows($email, '192.0.2.11'), 'separate IP has separate bucket');
$limiter->clear($email, $ip);
expect($limiter->allows($email, $ip), 'successful login can clear bucket');
for ($i = 0; $i < 8; $i++) {
    $limiter->recordFailure($email, $ip);
}
$stmt = $db->prepare('UPDATE login_throttles SET window_started_at = UTC_TIMESTAMP() - INTERVAL 16 MINUTE WHERE bucket = ?');
$stmt->execute([LoginLimiter::bucket($email, $ip)]);
expect($limiter->allows($email, $ip), 'old failure window expires');
$limiter->recordFailure($email, $ip);
expect($limiter->allows($email, $ip), 'new window resets count');

// New platform features: capacity locks, release lifecycle, project isolation and discussion.
$inventory = new ResourceService($db);
$workspace = new ProjectService($db);
$pool = $inventory->save([
    'id'=>0,'code'=>'TEST-GPU','name'=>'Test GPU cluster','resource_type'=>'GPU',
    'capacity'=>4,'unit'=>'GPUs','location'=>'Test lab','description'=>'Isolated test pool','active'=>'1',
],$admin);
expect($pool>0,'administrator creates resource pool');
expect(count($inventory->all())===1,'inventory lists created pool');
throwsDomain(fn()=> $inventory->save([
    'id'=>0,'code'=>'BAD-POOL','name'=>'Unauthorized cluster','resource_type'=>'GPU',
    'capacity'=>3,'unit'=>'GPUs','location'=>'Lab','description'=>'','active'=>'1',
],$ada),'member cannot modify resource inventory');
$projectId=$workspace->create(['name'=>'Research anomaly detection','description'=>'GPU benchmark experiment'],$ada);
expect($projectId>0,'member creates project');
expect(count($workspace->listFor($ben))===0,'projects private between members');
$makeRequest = fn (int $qty) => [
    'title'=>'Allocation for anomaly detection','resource_type'=>'GPU','resource_pool_id'=>$pool,
    'project_id'=>$projectId,'priority'=>'high','quantity'=>$qty,
    'needed_from'=>'2027-01-01','needed_until'=>'2027-02-01',
    'purpose'=>'Running validation experiments on a shared research cluster.',
];
$first=$service->create($makeRequest(3),$ada);
expect($service->find($first,$ada)['project_id']===$projectId || (int)$service->find($first,$ada)['project_id']===$projectId,'request linked to correct project');
throwsDomain(fn()=> $service->create($makeRequest(1),$ben),'cannot attach request to someone else project');
$service->decide($first,$admin,'approved','Capacity is available.');
expect((int)$inventory->find($pool)['available']===1,'approval reserves GPU capacity');
$next=$service->create($makeRequest(2),$ada);
throwsDomain(fn()=> $service->decide($next,$admin,'approved',''),'over-allocation blocked');
expect($service->find($next,$admin)['status']==='pending','over-allocation does not change status');
throwsDomain(fn()=> $inventory->save([
    'id'=>$pool,'code'=>'TEST-GPU','name'=>'Test GPU cluster','resource_type'=>'GPU','capacity'=>2,
    'unit'=>'GPUs','location'=>'Test lab','description'=>'','active'=>'1',
],$admin),'cannot shrink pool below active allocations');
$service->addComment($first,$ada,'Is the GPU pool ready for the experiment?');
$service->addComment($first,$admin,'Yes, please proceed.');
expect(count($service->commentsFor($first,$ada))===2,'request discussion persists');
throwsDomain(fn()=> $service->commentsFor($first,$ben),'conversation is private');
throwsDomain(fn()=> $service->addComment($first,$ben,'Unauthorized comment'),'other members cannot comment');
$service->release($first,$admin);
expect($service->find($first,$admin)['status']==='released','completed allocation is released');
expect((int)$inventory->find($pool)['available']===4,'released allocation restores capacity');
$service->decide($next,$admin,'approved','Available now.');
expect((int)$inventory->find($pool)['available']===2,'next request can be approved after release');
expect($service->countsFor($ada)['released']===1,'released requests included in statistics');
expect(count((new InsightService($db))->summary($ada))>0,'analytics use real request records');
expect(count($service->listFor($ada,null,'anomaly','high',$projectId))===2,'search priority and project filters');
$workspace->archive($projectId,$ada);
throwsDomain(fn()=> $service->create($makeRequest(1),$ada),'archived project cannot accept new requests');
expect(count($workspace->listFor($ada))===1,'archived project remains in history');

fwrite(STDOUT, "{$checks} MySQL integration checks passed.\n");
