<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ResourceHub\LoginLimiter;
use ResourceHub\Validation;

$total = 0;
$failed = 0;
function check(bool $condition, string $description): void
{
    global $total, $failed;
    $total++;
    if (!$condition) {
        $failed++;
        fwrite(STDERR, "FAIL  {$description}\n");
    }
}

function requestData(array $overrides = []): array
{
    return array_replace([
        'title' => 'GPU allocation for my thesis',
        'resource_type' => 'GPU',
        'quantity' => 2,
        'purpose' => 'Training and evaluating an anomaly detection model.',
    ], $overrides);
}

$valid = Validation::request(requestData());
check($valid['errors'] === [], 'valid request accepted');
check($valid['data']['quantity'] === 2, 'numeric quantity is an integer');
check(Validation::request(requestData(['quantity' => '4']))['data']['quantity'] === 4, 'numeric string accepted');
check(Validation::request(requestData(['title' => 'x']))['errors'] !== [], 'title minimum length');
check(Validation::request(requestData(['title' => str_repeat('x', 121)]))['errors'] !== [], 'title maximum length');
check(Validation::request(requestData(['purpose' => 'short']))['errors'] !== [], 'purpose minimum length');
check(Validation::request(requestData(['purpose' => str_repeat('x', 1501)]))['errors'] !== [], 'purpose maximum length');
check(Validation::request(requestData(['resource_type' => 'unknown']))['errors'] !== [], 'resource allowlist');
check(Validation::request(requestData(['resource_type' => ['GPU']]))['errors'] !== [], 'array input type rejected');
check(Validation::request(requestData(['quantity' => 0]))['errors'] !== [], 'zero quantity rejected');
check(Validation::request(requestData(['quantity' => 101]))['errors'] !== [], 'too large quantity rejected');
check(Validation::request(requestData(['quantity' => '1.5']))['errors'] !== [], 'decimal quantity rejected');
check(Validation::request(requestData(['quantity' => ['1']]))['errors'] !== [], 'array quantity rejected');
check(Validation::request(requestData(['title' => ['bad']]))['errors'] !== [], 'array title rejected');
check(Validation::request(requestData(['purpose' => ['bad']]))['errors'] !== [], 'array purpose rejected');
check(Validation::request(requestData(['title' => '   Research allocation   ']))['data']['title'] === 'Research allocation', 'title trimmed');
check(Validation::request(requestData(['priority' => 'high']))['errors'] === [], 'high priority accepted');
check(Validation::request(requestData(['priority' => 'emergency']))['errors'] !== [], 'unknown priority rejected');
check(Validation::request(requestData(['resource_pool_id' => '3']))['data']['resource_pool_id'] === 3, 'resource ID normalized');
check(Validation::request(requestData(['resource_pool_id' => ['bad']]))['errors'] !== [], 'array resource ID rejected');
check(Validation::request(requestData(['needed_from' => '2027-01-05', 'needed_until' => '2027-01-11']))['errors'] === [], 'valid date range accepted');
check(Validation::request(requestData(['needed_from' => '2027-03-12', 'needed_until' => '2027-03-02']))['errors'] !== [], 'inverted dates rejected');
check(Validation::request(requestData(['needed_from' => '2027-02-30']))['errors'] !== [], 'nonexistent date rejected');
check(Validation::statusFilter('released') === 'released', 'released status accepted');
check(Validation::decision('approved'), 'approval accepted');
check(Validation::decision('rejected'), 'rejection accepted');
check(!Validation::decision('pending'), 'non-decision rejected');
check(!Validation::decision('APPROVED'), 'uppercase status rejected');
check(Validation::statusFilter(null) === null, 'missing filter accepted');
check(Validation::statusFilter('') === null, 'blank filter accepted');
check(Validation::statusFilter('approved') === 'approved', 'valid status filter');
foreach (['bad', ['approved'], true, 1] as $bad) {
    try {
        Validation::statusFilter($bad);
        check(false, 'bad status filter raises an error');
    } catch (InvalidArgumentException) {
        check(true, 'bad status filter raises an error');
    }
}
check(Validation::requestId('1') === 1, 'positive integer path ID');
check(Validation::requestId('2147483647') === 2147483647, 'large integer path ID');
check(Validation::requestId('0') === null, 'zero path ID rejected');
check(Validation::requestId('abc') === null, 'non-numeric path ID rejected');
check(Validation::requestId('99999999999999999999999999999') === null, 'overflow path ID rejected');
check(Validation::requestId('01') === null, 'leading zero path ID rejected');
check(LoginLimiter::bucket('TEST@EXAMPLE.COM', '127.0.0.1') === LoginLimiter::bucket('test@example.com', '127.0.0.1'), 'throttle key normalizes email');
check(LoginLimiter::bucket('test@example.com', '127.0.0.1') !== LoginLimiter::bucket('test@example.com', '127.0.0.2'), 'throttle distinguishes IP');
check(strlen(LoginLimiter::bucket('test@example.com', '127.0.0.1')) === 64, 'hashed throttle key');

fwrite(STDOUT, sprintf("%d/%d unit checks passed.\n", $total - $failed, $total));
exit($failed === 0 ? 0 : 1);
