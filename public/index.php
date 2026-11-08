<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use ResourceHub\Auth;
use ResourceHub\Database;
use ResourceHub\Http;
use ResourceHub\InsightService;
use ResourceHub\ProjectService;
use ResourceHub\RequestService;
use ResourceHub\ResourceService;
use ResourceHub\Validation;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");

function esc(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfInput(): void { echo '<input type="hidden" name="_csrf" value="'.esc(Http::csrfToken()).'">'; }
function service(): RequestService { return new RequestService(Database::connection()); }
function resources(): ResourceService { return new ResourceService(Database::connection()); }
function projects(): ProjectService { return new ProjectService(Database::connection()); }
function insights(): InsightService { return new InsightService(Database::connection()); }
function isAdmin(array $user): bool { return $user['role'] === 'admin'; }
function requireAdmin(array $user): void { if (!isAdmin($user)) { Http::abort(403,'Administrator access required.'); } }
function selected(string $actual,string $expected): string { return $actual===$expected?' selected':''; }
function statusBadge(string $status): string
{
    $safe=in_array($status,Validation::STATUSES,true)?$status:'unknown';
    return '<span class="status '.esc($safe).'">'.esc(ucfirst($status)).'</span>';
}
function priorityBadge(string $priority): string
{
    return '<span class="priority '.esc($priority).'">'.esc(ucfirst($priority)).'</span>';
}
function layoutStart(string $title,?array $user=null,string $active=''): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#10263b"><title>'.esc($title).' · ResourceRequest Hub</title>';
    echo '<link rel="stylesheet" href="/assets/style.css"></head><body>';
    echo '<header class="header"><div class="shell header-row"><a class="brand" href="/" aria-label="ResourceRequest Hub home">';
    echo '<span class="brand-icon">R</span><span>ResourceRequest <strong>Hub</strong></span></a>';
    if ($user!==null) {
        $nav=['/' => 'Overview','/requests' => 'Requests','/catalog' => 'Catalog','/projects' => 'Projects','/analytics' => 'Analytics'];
        if (isAdmin($user)) { $nav['/admin/resources']='Administration'; }
        echo '<nav aria-label="Main navigation">';
        foreach ($nav as $url=>$label) {
            echo '<a href="'.esc($url).'"'.($active===$url?' aria-current="page"':'').'>'.esc($label).'</a>';
        }
        echo '</nav><div class="user-area"><span class="user-label">'.esc($user['name']).'<small>'.(isAdmin($user)?'Administrator':'Research member').'</small></span>';
        echo '<form action="/logout" method="post">';csrfInput();echo '<button class="button-quiet" type="submit">Sign out</button></form></div>';
    }
    echo '</div></header><main class="shell site-main">';
    if (!empty($_SESSION['flash'])) {
        $flash=$_SESSION['flash'];unset($_SESSION['flash']);
        echo '<div role="status" class="notice '.esc($flash['type']).'">'.esc($flash['message']).'</div>';
    }
}
function layoutEnd(): void { echo '</main><script src="/assets/app.js" defer></script></body></html>'; }
function heading(string $eyebrow,string $title,string $subtitle='',string $action=''): void
{
    echo '<section class="hero"><div><p class="eyebrow">'.esc($eyebrow).'</p><h1>'.esc($title).'</h1>';
    if ($subtitle!=='') { echo '<p class="muted">'.esc($subtitle).'</p>'; }
    echo '</div>'.$action.'</section>';
}
function requestTable(array $rows,bool $full=true): void
{
    if (!$rows) { echo '<div class="empty"><h3>Nothing here yet</h3><p>When requests are submitted, you will see them here.</p></div>';return; }
    echo '<div class="table-scroll"><table><thead><tr><th>Request</th><th>Resource</th><th>Priority</th><th>Status</th>';
    if ($full) { echo '<th>Requested by</th>'; }echo '<th>Submitted</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><a class="row-link" href="/requests/'.(int)$r['id'].'">'.esc($r['title']).'</a><small>#'.(int)$r['id'];
        if ($r['project_name']) { echo ' · '.esc($r['project_name']); }echo '</small></td>';
        echo '<td>'.esc($r['pool_name']?:$r['resource_type']).'<small>'.(int)$r['quantity'].' units</small></td>';
        echo '<td>'.priorityBadge($r['priority']).'</td><td>'.statusBadge($r['status']).'</td>';
        if ($full) { echo '<td>'.esc($r['requester']).'</td>'; }
        echo '<td>'.esc(substr($r['created_at'],0,10)).'</td></tr>';
    }
    echo '</tbody></table></div>';
}
function resourceCard(array $pool): void
{
    $pct=(int)$pool['capacity']>0?min(100,round(100*(int)$pool['allocated']/(int)$pool['capacity'])):0;
    echo '<article class="card resource-card"><div class="resource-top"><span class="resource-icon">'.esc($pool['resource_type']).'</span>';
    echo '<span class="mini-label">'.((int)$pool['active']===1?'Available pool':'Inactive').'</span></div>';
    echo '<h3>'.esc($pool['name']).'</h3><p class="muted small">'.esc($pool['description']).'</p>';
    echo '<div class="capacity-label"><strong>'.max(0,(int)$pool['available']).' available</strong><span>'.(int)$pool['allocated'].' / '.(int)$pool['capacity'].' '.esc($pool['unit']).' allocated</span></div>';
    echo '<div class="meter" role="progressbar" aria-valuenow="'.$pct.'" aria-valuemin="0" aria-valuemax="100" aria-label="Resource utilization"><span style="width:'.$pct.'%"></span></div>';
    echo '<div class="resource-meta"><span>'.esc($pool['code']).'</span><span>'.esc($pool['location']).'</span></div></article>';
}
function safeCsv(mixed $value): string
{
    $v=(string)$value;
    return preg_match('/^[=+@\-\t\r]/u',ltrim($v))?"'".$v:$v;
}

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$method=$_SERVER['REQUEST_METHOD']??'GET';

try {
    if ($path==='/login' && $method==='GET') {
        if (Auth::user()!==null) { Http::redirect('/'); }
        layoutStart('Sign in');
        echo '<section class="auth-layout"><div class="auth-copy"><p class="eyebrow">Research infrastructure management</p>';
        echo '<h1>Everything your research team needs to manage shared resources.</h1><p>Organize projects, track capacity, request compute and storage, and keep every approval in one place.</p>';
        echo '<ul><li>Resource inventory and live availability</li><li>Projects, requests and approval workflows</li><li>Transparent activity history and reporting</li></ul></div>';
        echo '<div class="card auth-card"><p class="eyebrow">Secure workspace</p><h2>Welcome back</h2><form method="post" action="/login">';csrfInput();
        echo '<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" maxlength="190" required>';
        echo '<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>';
        echo '<button class="button-primary full" type="submit">Sign in</button></form>';
        echo '<p class="muted small">Access is managed by your workspace administrator.</p></div></section>';
        layoutEnd();exit;
    }
    if ($path==='/login' && $method==='POST') {
        Http::verifyCsrf();$email=Validation::text($_POST['email']??null);
        $password=is_string($_POST['password']??null)?$_POST['password']:'';
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';$limiter=new \ResourceHub\LoginLimiter(Database::connection());
        if (!$limiter->allows($email,$ip)) { Http::abort(429,'Too many login attempts. Please try again in 15 minutes.'); }
        if (Auth::attempt($email,$password)) { $limiter->clear($email,$ip);Http::redirect('/'); }
        $limiter->recordFailure($email,$ip);Http::flash('error','Invalid email or password.');Http::redirect('/login');
    }
    if ($path==='/logout' && $method==='POST') {
        Http::verifyCsrf();Auth::logout();Http::redirect('/login');
    }
    if (str_starts_with($path,'/api/')) {
        $user=Auth::user();if ($user===null) { Http::json(['error'=>'Authentication required'],401); }
    } else { $user=Auth::requireUser(); }
    $requests=service();

    // Private APIs; every request is scoped to the authenticated user.
    if ($path==='/api/requests' && $method==='GET') {
        try { $status=Validation::statusFilter($_GET['status']??null); }
        catch(InvalidArgumentException $e){Http::json(['error'=>$e->getMessage()],400);}
        Http::json(['data'=>$requests->listFor($user,$status)]);
    }
    if (preg_match('~^/api/requests/([1-9][0-9]*)$~',$path,$m) && $method==='GET') {
        $id=Validation::requestId($m[1]);$r=$id===null?null:$requests->find($id,$user);
        if ($r===null) { Http::json(['error'=>'Not found'],404); }Http::json(['data'=>$r]);
    }
    if ($path==='/api/resources' && $method==='GET') { Http::json(['data'=>resources()->all()]); }
    if ($path==='/api/insights' && $method==='GET') { Http::json(['data'=>insights()->summary($user)]); }

    if ($path==='/' && $method==='GET') {
        $counts=$requests->countsFor($user);
        $recent=array_slice($requests->listFor($user),0,8);
        layoutStart('Overview',$user,'/');
        heading('Workspace overview','Make every resource count.', 'A clear view of requests, allocations and available infrastructure.', '<a class="button-primary" href="/requests/new">+ New request</a>');
        echo '<section class="stats" aria-label="Request statistics">';
        foreach (['all'=>'Total requests','pending'=>'Awaiting review','approved'=>'Active allocations','released'=>'Released'] as $key=>$label) {
            echo '<a class="stat card" href="/requests'.($key==='all'?'':'?status='.$key).'"><span>'.esc($label).'</span><strong>'.(int)$counts[$key].'</strong><small>Explore requests →</small></a>';
        }
        echo '</section><div class="section-heading"><div><p class="eyebrow">Infrastructure</p><h2>Resource availability</h2></div><a href="/catalog">View catalog →</a></div>';
        echo '<div class="resource-grid">';foreach(array_slice(resources()->all(),0,3) as $p){resourceCard($p);}echo '</div>';
        echo '<section class="card table-card"><div class="table-heading"><div><h2>Recent requests</h2><p class="muted small">Latest 100 requests you may access · newest shown below</p></div><a href="/requests" class="button-quiet">All requests</a></div>';
        requestTable($recent);echo '</section><div class="quick-actions"><a class="quick-link card" href="/projects"><strong>Research projects</strong><span>Organize and track work by project →</span></a>';
        echo '<a class="quick-link card" href="/analytics"><strong>Usage analytics</strong><span>Explore demand and allocation trends →</span></a></div>';
        layoutEnd();exit;
    }

    if ($path==='/requests' && $method==='GET') {
        try {
            $status=Validation::statusFilter($_GET['status']??null);
            $priority=Validation::text($_GET['priority']??null);$priority=$priority===''?null:$priority;
            $q=Validation::text($_GET['q']??null);
            $items=$requests->listFor($user,$status,$q,$priority);
        } catch (InvalidArgumentException $error) { Http::abort(400,$error->getMessage()); }
        layoutStart('Requests',$user,'/requests');
        heading('Request management','Resource requests','Search, prioritize and follow each request through its lifecycle.',
            '<a class="button-primary" href="/requests/new">+ New request</a>');
        echo '<section class="card table-card"><form class="filter-bar" method="get" action="/requests">';
        echo '<label class="sr-only" for="search">Search</label><input id="search" name="q" placeholder="Search requests..." maxlength="100" value="'.esc($q).'">';
        echo '<label class="sr-only" for="status">Status</label><select name="status" id="status"><option value="">All statuses</option>';
        foreach(Validation::STATUSES as $s){echo '<option value="'.esc($s).'"'.selected($status??'',$s).'>'.esc(ucfirst($s)).'</option>';}
        echo '</select><label class="sr-only" for="priority">Priority</label><select name="priority" id="priority"><option value="">All priorities</option>';
        foreach(Validation::PRIORITIES as $p){echo '<option value="'.esc($p).'"'.selected($priority??'',$p).'>'.esc(ucfirst($p)).'</option>';}
        echo '</select><button class="button-primary" type="submit">Apply filters</button><a class="button-quiet" href="/exports/requests.csv">Export CSV</a></form>';
        requestTable($items);echo '</section>';
        layoutEnd();exit;
    }

    if ($path==='/exports/requests.csv' && $method==='GET') {
        $all=$requests->listFor($user);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="resource-requests.csv"');
        $out=fopen('php://output','wb');fputcsv($out,['ID','Title','Resource','Quantity','Priority','Status','Requester','Project','Created']);
        foreach ($all as $r) {
            fputcsv($out,[safeCsv($r['id']),safeCsv($r['title']),safeCsv($r['pool_name']?:$r['resource_type']),safeCsv($r['quantity']),
                safeCsv($r['priority']),safeCsv($r['status']),safeCsv($r['requester']),safeCsv($r['project_name']??''),safeCsv($r['created_at'])]);
        }
        fclose($out);exit;
    }

    if ($path==='/catalog' && $method==='GET') {
        layoutStart('Resource catalog',$user,'/catalog');
        heading('Resource inventory','Explore the resource catalog','See available compute, GPU and storage pools before requesting an allocation.',
            '<a class="button-primary" href="/requests/new">Request resources</a>');
        echo '<div class="resource-grid">';foreach(resources()->all() as $pool){resourceCard($pool);}echo '</div>';
        layoutEnd();exit;
    }

    if ($path==='/projects' && $method==='GET') {
        layoutStart('Projects',$user,'/projects');
        heading('Research organization','Project workspaces','Keep requests and collaboration attached to the right research initiative.');
        echo '<div class="split-layout"><section class="card panel-pad"><h2>Create a project</h2><p class="muted small">A project groups your resource requests together.</p>';
        echo '<form method="post" action="/projects">';csrfInput();
        echo '<label for="project-name">Project name</label><input id="project-name" name="name" required minlength="3" maxlength="120" placeholder="e.g. Industrial anomaly detection">';
        echo '<label for="project-description">Description</label><textarea id="project-description" name="description" maxlength="500" rows="4" placeholder="What is this project about?"></textarea>';
        echo '<button class="button-primary" type="submit">Create project</button></form></section>';
        echo '<section class="card panel-pad"><h2>Projects</h2><div class="project-list">';
        $items=projects()->listFor($user);
        if (!$items) { echo '<p class="muted">No projects yet. Create your first project to organize work.</p>'; }
        foreach ($items as $pr) {
            echo '<div class="project-item"><div><strong>'.esc($pr['name']).'</strong><p class="muted small">'.esc($pr['description']).'</p>';
            echo '<span class="mini-label">'.(int)$pr['request_count'].' requests · '.esc($pr['status']).'</span>';
            if (isAdmin($user)) { echo '<span class="mini-label"> · '.esc($pr['owner']).'</span>'; }
            echo '</div>';
            if ($pr['status']==='active' && ((int)$pr['owner_id']===(int)$user['id'])) {
                echo '<form action="/projects/'.(int)$pr['id'].'/archive" method="post">';csrfInput();
                echo '<button type="submit" class="button-quiet" data-confirm="Archive this project?">Archive</button></form>';
            }
            echo '</div>';
        }
        echo '</div></section></div>';layoutEnd();exit;
    }
    if ($path==='/projects' && $method==='POST') {
        Http::verifyCsrf();
        try { projects()->create($_POST,$user);Http::flash('success','Project created. You can now link resource requests to it.'); }
        catch(DomainException $e){Http::flash('error',$e->getMessage());}
        Http::redirect('/projects');
    }
    if (preg_match('~^/projects/([1-9][0-9]*)/archive$~',$path,$m)&&$method==='POST') {
        Http::verifyCsrf();
        try { $id=Validation::requestId($m[1]);if ($id===null) {Http::abort(404,'Project not found.');}
            projects()->archive($id,$user);Http::flash('success','Project archived. Existing requests are preserved.'); }
        catch(DomainException $e){Http::flash('error',$e->getMessage());}
        Http::redirect('/projects');
    }

    if ($path==='/analytics' && $method==='GET') {
        $rows=insights()->summary($user);$monthly=insights()->monthly($user);$counts=$requests->countsFor($user);
        layoutStart('Analytics',$user,'/analytics');
        heading('Performance intelligence','Analytics & reporting','Understand resource demand, approval outcomes and infrastructure utilization.',
            '<a class="button-quiet" href="/exports/requests.csv">Export report (CSV)</a>');
        echo '<div class="stats">';
        foreach(['all'=>'Total requests','approved'=>'Currently allocated','rejected'=>'Declined','released'=>'Completed'] as $key=>$label){
            echo '<div class="card stat"><span>'.esc($label).'</span><strong>'.(int)$counts[$key].'</strong></div>';
        }
        echo '</div><div class="analytics-grid"><section class="card panel-pad"><h2>Requests by resource</h2><p class="muted small">All recorded requests</p>';
        $types=array_fill_keys(Validation::TYPES,0);foreach($rows as $r){$types[$r['resource_type']]+=(int)$r['count'];}
        $max=max(1,...array_values($types));
        foreach($types as $type=>$number){$pct=(int)round(100*$number/$max);
            echo '<div class="chart-row"><div class="chart-label"><strong>'.esc($type).'</strong><span>'.$number.' requests</span></div><div class="chart-track"><span style="width:'.$pct.'%"></span></div></div>';
        }
        echo '</section><section class="card panel-pad"><h2>Recent monthly activity</h2><p class="muted small">Submitted requests · last six months</p>';
        if (!$monthly){echo '<p class="muted">No activity recorded yet.</p>';}
        $max=1;foreach($monthly as $row){$max=max($max,(int)$row['count']);}
        foreach($monthly as $row){$pct=(int)round(100*(int)$row['count']/$max);
            echo '<div class="chart-row"><div class="chart-label"><strong>'.esc($row['period']).'</strong><span>'.(int)$row['count'].'</span></div><div class="chart-track"><span style="width:'.$pct.'%"></span></div></div>';
        }
        echo '</section></div><section class="card panel-pad"><h2>Current utilization</h2><div class="resource-grid analytics-pools">';
        foreach(resources()->all() as $pool){resourceCard($pool);}echo '</div></section>';layoutEnd();exit;
    }

    if ($path==='/admin/resources' && $method==='GET') {
        requireAdmin($user);$pools=resources()->all(true);
        $editId=Validation::requestId(Validation::text($_GET['edit']??null));$edit=$editId===null?null:resources()->find($editId);
        layoutStart('Resource administration',$user,'/admin/resources');
        heading('Operations center','Resource administration','Manage available resource pools, capacities and allocation limits.');
        echo '<div class="split-layout admin-layout"><section class="card panel-pad"><h2>'.($edit?'Edit resource':'Add resource pool').'</h2>';
        echo '<form action="/admin/resources/save" method="post">';csrfInput();
        echo '<input type="hidden" name="id" value="'.($edit?(int)$edit['id']:0).'">';
        $fields=['name'=>['Name','GPU Research Cluster',120],'code'=>['Unique code','GPU-LAB',32],'unit'=>['Capacity unit','GPUs',30],'location'=>['Location','Data Center 1',100]];
        foreach($fields as $field=>$opts){echo '<label for="pool-'.esc($field).'">'.esc($opts[0]).'</label><input id="pool-'.esc($field).'" name="'.esc($field).'" required maxlength="'.(int)$opts[2].'" placeholder="'.esc($opts[1]).'" value="'.esc($edit[$field]??'').'">';}
        echo '<div class="two-cols"><div><label for="pool-type">Type</label><select id="pool-type" name="resource_type">';
        foreach(Validation::TYPES as $type){echo '<option value="'.esc($type).'"'.selected($edit['resource_type']??'',$type).'>'.esc($type).'</option>';}
        echo '</select></div><div><label for="pool-capacity">Total capacity</label><input id="pool-capacity" name="capacity" type="number" min="1" max="100000" value="'.(int)($edit['capacity']??16).'" required></div></div>';
        echo '<label for="pool-description">Description</label><textarea id="pool-description" name="description" rows="3" maxlength="500">'.esc($edit['description']??'').'</textarea>';
        echo '<label for="pool-active">Availability</label><select id="pool-active" name="active">';
        echo '<option value="1"'.selected((string)($edit['active']??1),'1').'>Active</option><option value="0"'.selected((string)($edit['active']??1),'0').'>Inactive</option></select>';
        echo '<div class="form-actions"><a class="button-quiet" href="/admin/resources">Reset</a><button class="button-primary" type="submit">Save resource</button></div></form></section>';
        echo '<section class="card panel-pad"><h2>Inventory</h2><div class="project-list">';
        foreach($pools as $pool){echo '<div class="project-item"><div><strong>'.esc($pool['name']).'</strong><p class="muted small">'.esc($pool['code']).' · '.esc($pool['resource_type']).' · '.(int)$pool['available'].' / '.(int)$pool['capacity'].' free';
            if (!(int)$pool['active']){echo ' · Inactive';}echo '</p></div><a class="button-quiet" href="/admin/resources?edit='.(int)$pool['id'].'">Edit</a></div>';}
        echo '</div></section></div>';layoutEnd();exit;
    }
    if ($path==='/admin/resources/save' && $method==='POST') {
        Http::verifyCsrf();requireAdmin($user);
        try {resources()->save($_POST,$user);Http::flash('success','Resource inventory updated.');}
        catch(DomainException $e){Http::flash('error',$e->getMessage());}
        catch(\PDOException $e){Http::flash('error','That resource code is already used or the data is invalid.');}
        Http::redirect('/admin/resources');
    }

    if ($path==='/requests/new' && $method==='GET') {
        $old=$_SESSION['old_request']??[];unset($_SESSION['old_request']);
        layoutStart('New request',$user,'/requests');
        heading('New allocation','Request resources','Describe your research needs, select a pool and link the request to a project.');
        echo '<div class="card form-card wide-form"><form method="post" action="/requests">';csrfInput();
        echo '<label for="title">Request title *</label><input id="title" name="title" required minlength="5" maxlength="120" placeholder="GPU allocation for anomaly detection" value="'.esc($old['title']??'').'">';
        echo '<div class="two-cols"><div><label for="pool">Resource pool *</label><select id="pool" name="resource_pool_id" required><option value="">Choose a pool</option>';
        foreach(resources()->all() as $pool){echo '<option value="'.(int)$pool['id'].'"'.selected((string)($old['resource_pool_id']??''),(string)$pool['id']).'>'.esc($pool['name']).' · '.(int)$pool['available'].' available</option>';}
        echo '</select></div><div><label for="quantity">Quantity *</label><input id="quantity" type="number" name="quantity" min="1" max="100" value="'.esc($old['quantity']??1).'" required></div></div>';
        echo '<div class="two-cols"><div><label for="project">Research project</label><select id="project" name="project_id"><option value="">No project selected</option>';
        foreach(projects()->listFor($user) as $project){
            if ($project['status']!=='active' || ((int)$project['owner_id']!==(int)$user['id'])){continue;}
            echo '<option value="'.(int)$project['id'].'"'.selected((string)($old['project_id']??''),(string)$project['id']).'>'.esc($project['name']).'</option>';
        }
        echo '</select><p class="muted small">Need a project? <a href="/projects">Create one</a>.</p></div>';
        echo '<div><label for="priority">Priority</label><select id="priority" name="priority">';
        foreach(Validation::PRIORITIES as $p){echo '<option value="'.esc($p).'"'.selected($old['priority']??'normal',$p).'>'.esc(ucfirst($p)).'</option>';}
        echo '</select></div></div>';
        echo '<div class="two-cols"><div><label for="needed-from">Needed from</label><input id="needed-from" name="needed_from" type="date" value="'.esc($old['needed_from']??'').'"></div>';
        echo '<div><label for="needed-until">Needed until</label><input id="needed-until" name="needed_until" type="date" value="'.esc($old['needed_until']??'').'"></div></div>';
        echo '<label for="purpose">Research purpose *</label><textarea id="purpose" name="purpose" rows="6" minlength="15" maxlength="1500" required placeholder="Explain the expected workload and why you need this resource.">'.esc($old['purpose']??'').'</textarea>';
        echo '<p class="muted small">Resource availability is checked again when an administrator approves the request.</p>';
        echo '<div class="form-actions"><a href="/requests" class="button-quiet">Cancel</a><button type="submit" class="button-primary">Submit request</button></div></form></div>';
        layoutEnd();exit;
    }
    if ($path==='/requests' && $method==='POST') {
        Http::verifyCsrf();
        $poolId=Validation::requestId(Validation::text($_POST['resource_pool_id']??null));
        $pool=$poolId===null?null:resources()->find($poolId);
        if ($pool===null) {Http::flash('error','Choose a valid resource pool.');Http::redirect('/requests/new');}
        $input=$_POST;$input['resource_type']=$pool['resource_type'];
        $valid=Validation::request($input);
        if ($valid['errors']) {$_SESSION['old_request']=$valid['data'];Http::flash('error',implode(' ',$valid['errors']));Http::redirect('/requests/new');}
        try {$id=$requests->create($valid['data'],$user);Http::flash('success','Request submitted successfully.');Http::redirect('/requests/'.$id);}
        catch(DomainException $e){$_SESSION['old_request']=$valid['data'];Http::flash('error',$e->getMessage());Http::redirect('/requests/new');}
    }

    if (preg_match('~^/requests/([1-9][0-9]*)$~',$path,$m) && $method==='GET') {
        $id=Validation::requestId($m[1]);$r=$id===null?null:$requests->find($id,$user);
        if ($r===null) {Http::abort(404,'Request not found.');}
        layoutStart('Request #'.$r['id'],$user,'/requests');
        heading('Request #'.$r['id'],$r['title'],'Submitted by '.$r['requester'].' · '.substr($r['created_at'],0,10),statusBadge($r['status']));
        echo '<div class="detail-grid"><section class="card detail-card"><h2>Allocation details</h2><dl>';
        foreach(['Resource pool'=>$r['pool_name']?:$r['resource_type'],'Quantity'=>$r['quantity'],'Priority'=>ucfirst($r['priority']),
                 'Project'=>$r['project_name']?:'Not linked','Needed from'=>$r['needed_from']?:'Not specified',
                 'Needed until'=>$r['needed_until']?:'Not specified','Created'=>$r['created_at'],'Last update'=>$r['updated_at']] as $label=>$value){
            echo '<div><dt>'.esc($label).'</dt><dd>'.esc($value).'</dd></div>';
        }
        echo '</dl><h3>Research purpose</h3><p class="text-content">'.nl2br(esc($r['purpose'])).'</p>';
        if ($r['review_comment']) {echo '<h3>Reviewer feedback</h3><p class="text-content">'.nl2br(esc($r['review_comment'])).'</p>';}
        echo '<div class="discussion"><h2>Discussion</h2><p class="muted small">Messages between the requester and the review team.</p>';
        foreach($requests->commentsFor((int)$r['id'],$user) as $c){echo '<div class="comment"><div class="comment-meta"><strong>'.esc($c['name']).'</strong><small>'.esc($c['created_at']).'</small></div><p>'.nl2br(esc($c['body'])).'</p></div>';}
        echo '<form method="post" action="/requests/'.(int)$r['id'].'/comments">';csrfInput();
        echo '<label for="message">Add a message</label><textarea name="message" id="message" minlength="2" maxlength="1500" rows="3" required placeholder="Ask a question or share an update..."></textarea>';
        echo '<button class="button-primary" type="submit">Post message</button></form></div></section>';
        echo '<aside class="card action-card"><h2>Workflow</h2>';
        if ($r['status']==='pending' && isAdmin($user)) {
            echo '<p class="muted small">Review the research requirements and available capacity before approving.</p>';
            echo '<form method="post" action="/requests/'.(int)$r['id'].'/decision">';csrfInput();
            echo '<label for="comment">Review note</label><textarea name="comment" id="comment" maxlength="500" rows="4" placeholder="Explain your decision (optional)"></textarea>';
            echo '<div class="review-actions"><button class="button-primary" name="decision" value="approved" type="submit">Approve</button>';
            echo '<button class="button-danger" name="decision" value="rejected" type="submit">Reject</button></div></form>';
        } elseif ($r['status']==='pending' && (int)$r['user_id']===(int)$user['id']) {
            echo '<p class="muted small">Your request is waiting for review.</p><form method="post" action="/requests/'.(int)$r['id'].'/cancel">';csrfInput();
            echo '<button class="button-danger" type="submit" data-confirm="Cancel this pending request?">Cancel request</button></form>';
        } elseif ($r['status']==='approved' && isAdmin($user)) {
            echo '<p class="muted small">This allocation occupies capacity. Release it when the work is complete.</p>';
            echo '<form method="post" action="/requests/'.(int)$r['id'].'/release">';csrfInput();
            echo '<button type="submit" class="button-primary" data-confirm="Release this allocation and restore capacity?">Release allocation</button></form>';
        } else {echo '<p class="muted small">Current status: '.esc($r['status']).'.</p>';}
        echo '<h3>Activity history</h3><ol class="timeline">';
        foreach($requests->auditFor((int)$r['id'],$user) as $a){echo '<li><strong>'.esc(ucfirst($a['action'])).'</strong><small>'.esc($a['name']).' · '.esc($a['created_at']).'</small>';
            if ($a['comment']!==''){echo '<p>'.esc($a['comment']).'</p>';}echo '</li>';}
        echo '</ol></aside></div>';layoutEnd();exit;
    }
    if (preg_match('~^/requests/([1-9][0-9]*)/(decision|cancel|release|comments)$~',$path,$m) && $method==='POST') {
        Http::verifyCsrf();$id=Validation::requestId($m[1]);if ($id===null){Http::abort(404,'Request not found.');}
        try {
            switch($m[2]) {
                case 'decision':$requests->decide($id,$user,Validation::text($_POST['decision']??null),Validation::text($_POST['comment']??null));$message='Review decision saved.';break;
                case 'cancel':$requests->cancel($id,$user);$message='Request cancelled.';break;
                case 'release':$requests->release($id,$user);$message='Allocation released; capacity restored.';break;
                case 'comments':$requests->addComment($id,$user,Validation::text($_POST['message']??null));$message='Message posted.';break;
            }
            Http::flash('success',$message);
        } catch(DomainException $e){Http::flash('error',$e->getMessage());}
        Http::redirect('/requests/'.$id);
    }
    Http::abort(404,'Page not found.');
} catch(\Throwable $error) {
    error_log('[ResourceRequest Hub] '.$error->getMessage());
    Http::abort(500,'Server error. Check the application logs.');
}
