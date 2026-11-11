<?php
declare(strict_types=1);

ob_start();
require dirname(__DIR__) . '/server/bootstrap.php';
adminSession();

$statuses = ['new', 'review', 'quoted', 'follow-up', 'closed'];
$services = ['Sea Freight - FCL', 'Sea Freight - LCL', 'Air Freight', 'Land Transport'];
$error = '';

function csrfField(): void
{
    echo '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function cairoTime(?string $date): string
{
    if (!$date) return '—';
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Africa/Cairo'))
        ->format('d M Y · H:i');
}

function pageName(string $path): string
{
    $part = basename(parse_url($path, PHP_URL_PATH) ?: '');
    if (!$part || $part === 'index.html') return 'Home';
    $names = [
        'sea-freight.html' => 'Sea freight',
        'air-freight.html' => 'Air freight',
        'land-transport.html' => 'Land transport',
        'customs-clearance.html' => 'Customs clearance',
        'warehousing-distribution.html' => 'Warehousing & distribution',
        'import-export-support.html' => 'Import & export support',
    ];
    return $names[$part] ?? (str_ends_with($path, '/') ? 'Home' : $path);
}

function visitValue(array $row, string $key): string
{
    if ($key === 'created_at') return cairoTime($row[$key] ?? null);
    if ($key === 'page') return pageName((string)($row[$key] ?? ''));
    if (in_array($key, ['country', 'city'], true)
        && in_array($row['ip'] ?? '', ['::1', '127.0.0.1'], true)) {
        return $key === 'country' ? 'Local visit' : 'Not applicable';
    }
    return (string)($row[$key] ?? 'Expired');
}

function icon(string $name, int $size = 20): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'orders' => '<path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8l-5-5h-4"/><path d="M16 3v5h5M8 13h8M8 17h8"/>',
        'analytics' => '<path d="M3 3v18h18M7 16v-4M12 16V7M17 16V10"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3.5a4 4 0 0 1 0 7.5M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="m3 3 18 18M10.6 5.1A11.5 11.5 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.4 4.2M6.1 6.1C3.4 8 2 12 2 12s3.5 7 10 7a10.5 10.5 0 0 0 3.9-.7"/><path d="M10 10a3 3 0 0 0 4 4"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'download' => '<path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'filter' => '<path d="M4 7h16M7 12h10M10 17h4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
    if (!isset($paths[$name])) return '';
    $size = max(14, min(28, $size));
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

function urlWith(array $parameters): string
{
    $allowed = array_intersect_key($_GET, array_flip(['from', 'to', 'q', 'status', 'service', 'archive', 'page']));
    foreach ($parameters as $key => $value) {
        if ($value === null) unset($allowed[$key]);
        else $allowed[$key] = $value;
    }
    return './?' . http_build_query($allowed, '', '&', PHP_QUERY_RFC3986);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf();
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : ''; 
        if ($action === 'login' && !isset($_SESSION['admin'])) {
            $user = is_string($_POST['user'] ?? null) ? substr(trim($_POST['user']), 0, 100) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            limit('login', 5); // Per-IP, one minute.
            limitWindow('login-ip-15m', 20, 900, ip());
            limitWindow('login-name-15m', 30, 900, strtolower($user));
            $config = cfg();
            if ($user !== '' && strlen($password) <= 1024
                && hash_equals((string)$config['admin_user'], $user)
                && password_verify($password, (string)$config['admin_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin'] = $config['admin_user'];
                $_SESSION['auth_version'] = adminFingerprint($config);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: ./', true, 303);
                exit;
            }
            $error = 'Incorrect username or password.';
        } elseif (isset($_SESSION['admin'])) {
            if ($action === 'logout') {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, [
                        'path' => $params['path'],
                        'secure' => $params['secure'],
                        'httponly' => $params['httponly'],
                        'samesite' => $params['samesite'] ?? 'Strict',
                    ]);
                }
                session_destroy();
                header('Location: ./', true, 303);
                exit;
            }
            if ($action === 'update' && is_string($_POST['status'] ?? null)
                && in_array($_POST['status'], $statuses, true)) {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) throw new RuntimeException('Invalid order reference');
                $note = $_POST['note'] ?? '';
                if (!is_string($note) || strlen($note) > 5000) throw new RuntimeException('Invalid note');
                $newStatus = $_POST['status'];
                $archived = isset($_POST['archived']) ? 1 : 0;
                db()->beginTransaction();
                $before = query('SELECT status,archived FROM orders WHERE id=? FOR UPDATE', [$id])
                    ->fetch(PDO::FETCH_ASSOC);
                if (!$before) throw new RuntimeException('Order not found');
                query('UPDATE orders SET status=?, archived=? WHERE id=?', [$newStatus, $archived, $id]);
                $changes = 'Status: ' . $before['status'] . ' -> ' . $newStatus
                    . '; Archive: ' . (int)$before['archived'] . ' -> ' . $archived;
                $auditNote = $changes . ($note !== '' ? "\n" . $note : '');
                query('INSERT INTO audit(order_id,created_at,actor,status,note) VALUES(?,UTC_TIMESTAMP(),?,?,?)', [
                    $id, $_SESSION['admin'], $newStatus, $auditNote,
                ]);
                db()->commit();
                header('Location: ./?id=' . $id . '#request-detail', true, 303);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
    error_log('AK dashboard action: ' . get_class($e));
    $error = 'Service unavailable. Please check the server configuration.';
}

$loggedIn = isset($_SESSION['admin']);
$dataError = '';
if ($loggedIn) {
    try {
        $zone = new DateTimeZone('Africa/Cairo');
        $today = new DateTimeImmutable('today', $zone);
        $from = (string)($_GET['from'] ?? $today->format('Y-m-d'));
        $to = (string)($_GET['to'] ?? $today->format('Y-m-d'));
        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Invalid date');
        }
        if ($from > $to) throw new RuntimeException('Invalid date range');
        $start = (new DateTimeImmutable($from, $zone))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end = (new DateTimeImmutable($to, $zone))->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $metrics = query("SELECT COUNT(DISTINCT visitor) visitors, COUNT(DISTINCT session_id) sessions,
            SUM(kind='pageview') views, SUM(kind='quote_start') starts,
            SUM(kind='whatsapp_open') whatsapp FROM events WHERE created_at>=? AND created_at<?",
            [$start, $end])->fetch(PDO::FETCH_ASSOC);
        $savedOrders = (int)query('SELECT COUNT(*) FROM orders WHERE created_at>=? AND created_at<?',
            [$start, $end])->fetchColumn();

        $search = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
        $filterStatus = (string)($_GET['status'] ?? '');
        if (!in_array($filterStatus, $statuses, true)) $filterStatus = '';
        $filterService = (string)($_GET['service'] ?? '');
        if (!in_array($filterService, $services, true)) $filterService = '';
        $archive = isset($_GET['archive']) ? 1 : 0;

        $where = 'created_at>=? AND created_at<? AND archived=?';
        $args = [$start, $end, $archive];
        if ($search !== '') {
            $where .= ' AND (reference LIKE ? OR name LIKE ? OR company LIKE ? OR phone LIKE ?)';
            for ($i = 0; $i < 4; $i++) $args[] = '%' . $search . '%';
        }
        if ($filterStatus !== '') { $where .= ' AND status=?'; $args[] = $filterStatus; }
        if ($filterService !== '') { $where .= ' AND service=?'; $args[] = $filterService; }

        $totalOrders = (int)query('SELECT COUNT(*) FROM orders WHERE ' . $where, $args)->fetchColumn();
        $pageSize = 25;
        $totalPages = max(1, (int)ceil($totalOrders / $pageSize));
        $page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
        $offset = ($page - 1) * $pageSize;
        // LIMIT/OFFSET interpolations are integers calculated locally, never raw user input.
        $rows = query('SELECT id,reference,name,company,service,status,created_at FROM orders WHERE '
            . $where . ' ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $args)->fetchAll(PDO::FETCH_ASSOC);

        if (isset($_GET['export'])) {
            $exportRows = query('SELECT id,reference,created_at,name,company,phone,service,status,country FROM orders WHERE '
                . $where . ' ORDER BY id DESC LIMIT 1000', $args)->fetchAll(PDO::FETCH_ASSOC);
            ob_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="ak-orders.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID','Reference','UTC','Name','Company','Phone','Service','Status','Country']);
            foreach ($exportRows as $row) {
                $cells = [];
                foreach (['id','reference','created_at','name','company','phone','service','status','country'] as $key) {
                    $value = (string)$row[$key];
                    // Prevent spreadsheet formula evaluation even after leading whitespace/BOM.
                    $formula = preg_match('/^[\x00-\x20\x{00A0}\x{FEFF}\x{200B}\x{200C}\x{200D}\x{2060}]*[=+\-@]/u', $value);
                    $cells[] = ($formula || preg_match('/^[\t\r\n]/', $value)) ? "'" . $value : $value;
                }
                fputcsv($out, $cells);
            }
            fclose($out);
            exit;
        }

        $selectedOrder = null;
        $activity = [];
        if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
            $selectedOrder = query('SELECT * FROM orders WHERE id=?', [(int)$_GET['id']])
                ->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($selectedOrder) {
                $activity = query('SELECT created_at,actor,status,note FROM audit WHERE order_id=? ORDER BY id DESC LIMIT 100',
                    [$selectedOrder['id']])->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $by = [];
        foreach (query("SELECT country,COUNT(DISTINCT visitor) visitors, SUM(kind='quote_start') starts
            FROM events WHERE created_at>=? AND created_at<? GROUP BY country", [$start, $end]) as $row) {
            $by[$row['country']] = $row;
        }
        foreach (query('SELECT country,COUNT(*) total FROM orders WHERE created_at>=? AND created_at<? GROUP BY country',
            [$start, $end]) as $row) {
            $by[$row['country']] ??= ['country' => $row['country'], 'visitors' => 0, 'starts' => 0];
            $by[$row['country']]['orders'] = $row['total'];
        }
        uasort($by, static fn($a, $b) => (int)$b['visitors'] <=> (int)$a['visitors']);
        $topPages = query("SELECT page,COUNT(*) total FROM events WHERE kind='pageview'
            AND created_at>=? AND created_at<? GROUP BY page ORDER BY total DESC LIMIT 8",
            [$start, $end])->fetchAll(PDO::FETCH_ASSOC);
        $recentVisits = query("SELECT created_at,ip,country,city,page FROM events WHERE kind='pageview'
            AND created_at>=? AND created_at<? ORDER BY id DESC LIMIT 50",
            [$start, $end])->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('AK dashboard data: ' . get_class($e));
        $dataError = 'Unable to load dashboard data. Check your database settings or date range.';
    }
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#102f47">
    <title><?= $loggedIn ? 'Operations Dashboard' : 'Secure Sign In' ?> | AK INTERNATIONAL</title>
    <link rel="icon" type="image/webp" href="../assets/ak-logo.webp">
    <link rel="stylesheet" href="dashboard.css">
    <script src="dashboard.js" defer></script>
</head>
<body class="<?= $loggedIn ? 'dashboard-mode' : 'login-mode' ?>">
<?php if (!$loggedIn): ?>
<div class="login-layout">
    <section class="login-brand-panel" aria-label="AK International Operations">
        <div class="login-brand-top">
            <img src="../assets/ak-logo.webp" width="56" height="56" alt="AK INTERNATIONAL" class="logo">
            <div><strong>AK INTERNATIONAL</strong><small>GLOBAL LOGISTICS · EST. 2009</small></div>
        </div>
        <div class="login-brand-content">
            <p class="eyebrow eyebrow-light">SECURE OPERATIONS PORTAL</p>
            <h1>One workspace.<br><span>Full operational clarity.</span></h1>
            <p>Manage freight enquiries, follow up on quotations and monitor website activity in a private, focused environment.</p>
            <div class="brand-categories"><span>Freight</span><span>Customs</span><span>Logistics</span></div>
        </div>
        <div class="brand-bottom"><span class="status-dot"></span> Authorized personnel only <span class="brand-divider">·</span> Operations workspace</div>
    </section>

    <main class="login-main" id="main">
        <div class="login-shell">
            <div class="login-mobile-brand"><img src="../assets/ak-logo.webp" width="48" height="48" alt=""><strong>AK INTERNATIONAL</strong></div>
            <div class="login-intro">
                <span class="login-icon"><?= icon('lock', 22) ?></span>
                <p class="eyebrow">WELCOME BACK</p>
                <h2>Sign in to your workspace</h2>
                <p>Enter your administrator credentials to continue.</p>
            </div>
            <?php if ($error): ?><p class="alert-error" role="alert"><?= h($error) ?></p><?php endif; ?>
            <form method="post" class="login-form" autocomplete="on">
                <?php csrfField(); ?>
                <label for="admin-user">Username</label>
                <input id="admin-user" name="user" autocomplete="username" placeholder="Your username" maxlength="100" required autofocus>
                <label for="admin-password">Password</label>
                <div class="password-input">
                    <input id="admin-password" type="password" name="password" autocomplete="current-password" placeholder="Your password" required>
                    <button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><?= icon('eye') ?></button>
                </div>
                <button class="button-primary login-submit" type="submit" name="action" value="login">Sign in securely <?= icon('arrow', 18) ?></button>
            </form>
            <div class="login-footer"><?= icon('lock', 15) ?> Your session is protected and expires after inactivity.</div>
        </div>
        <p class="login-credit">AK INTERNATIONAL · PRIVATE ADMINISTRATION</p>
    </main>
</div>
<?php else: ?>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="./" aria-label="AK International Operations home">
            <img src="../assets/ak-logo.webp" width="50" height="50" alt="">
            <span><strong>AK INTERNATIONAL</strong><small>GLOBAL LOGISTICS · EST. 2009</small></span>
        </a>
        <div class="workspace-label">OPERATIONS WORKSPACE</div>
        <nav class="side-nav" aria-label="Dashboard sections">
            <a href="#overview"><?= icon('dashboard') ?> Overview</a>
            <a href="#orders"><?= icon('orders') ?> Quote requests</a>
            <a href="#analytics"><?= icon('analytics') ?> Website analytics</a>
        </nav>
        <div class="sidebar-bottom">
            <span class="status-dot"></span> Internal workspace
            <small>Freight · Customs · Logistics</small>
        </div>
    </aside>
    <main id="main" class="dashboard-main">
        <header class="topbar">
            <div class="topbar-title">
                <p class="eyebrow">AK INTERNATIONAL / OPERATIONS</p>
                <h1>Operations Dashboard</h1>
                <p>Quotes, visitors and operational activity in one view.</p>
            </div>
            <div class="topbar-actions">
                <span class="admin-chip"><?= icon('lock', 16) ?> <span><?= h($_SESSION['admin']) ?></span></span>
                <form method="post" class="logout-form"><?php csrfField(); ?><button type="submit" name="action" value="logout" class="button-outline"><?= icon('logout', 17) ?> <span>Sign out</span></button></form>
            </div>
        </header>
        <details class="mobile-navigation">
            <summary><?= icon('menu', 19) ?> Workspace menu</summary>
            <nav aria-label="Mobile dashboard navigation"><a href="#overview">Overview</a><a href="#orders">Quote requests</a><a href="#analytics">Website analytics</a></nav>
        </details>
        <?php if ($error): ?><p class="alert-error" role="alert"><?= h($error) ?></p><?php endif; ?>
        <?php if ($dataError): ?>
            <p class="alert-error" role="alert"><?= h($dataError) ?></p>
        <?php else: ?>
        <section id="overview" class="dashboard-section">
            <div class="section-heading">
                <div><p class="eyebrow">PERFORMANCE</p><h2>Overview</h2></div>
                <span class="connected"><span class="status-dot"></span> Database connected</span>
            </div>
            <nav class="periods" aria-label="Reporting period">
                <a href="./">Today</a>
                <a href="?from=<?= $today->modify('-1 day')->format('Y-m-d') ?>&amp;to=<?= $today->modify('-1 day')->format('Y-m-d') ?>">Yesterday</a>
                <a href="?from=<?= $today->modify('-6 days')->format('Y-m-d') ?>">Last 7 days</a>
                <a href="?from=<?= $today->modify('-29 days')->format('Y-m-d') ?>">Last 30 days</a>
            </nav>
            <div class="metrics">
                <?php
                $statCards = [
                    ['Unique browsers', $metrics['visitors'] ?? 0, 'users', 'Approximate visitors'],
                    ['Sessions', $metrics['sessions'] ?? 0, 'clock', 'Recorded sessions'],
                    ['Page views', $metrics['views'] ?? 0, 'eye', 'Pages opened'],
                    ['Quote starts', $metrics['starts'] ?? 0, 'orders', 'Form interactions'],
                    ['WhatsApp attempts', $metrics['whatsapp'] ?? 0, 'arrow', 'Not confirmed messages'],
                    ['Saved orders', $savedOrders, 'check', 'Stored in database'],
                ];
                foreach ($statCards as [$label, $number, $symbol, $description]): ?>
                <article class="metric-card">
                    <div class="metric-top"><span><?= icon($symbol, 20) ?></span><span class="metric-period">Selected period</span></div>
                    <strong><?= number_format((int)($number ?? 0)) ?></strong>
                    <p><?= h($label) ?></p>
                    <small><?= h($description) ?></small>
                </article>
                <?php endforeach; ?>
            </div>
            <p class="data-note">Timezone: Africa/Cairo. Browser counts are approximate; opening WhatsApp does not confirm a sent message. <?= empty(cfg()['geo_csv']) ? 'Country and city lookup is not configured.' : 'Country lookup uses configured local data.' ?> Local tests may be included.</p>
        </section>

        <section id="orders" class="dashboard-section">
            <div class="section-heading">
                <div><p class="eyebrow">QUOTE PIPELINE</p><h2>Quote requests</h2></div>
                <span class="result-count"><?= number_format($totalOrders) ?> matching requests</span>
            </div>
            <form method="get" action="./" class="filter-panel" id="order-filters">
                <label>From <input type="date" name="from" value="<?= h($from) ?>" required></label>
                <label>To <input type="date" name="to" value="<?= h($to) ?>" required></label>
                <label class="search-filter">Search <span class="search-field"><?= icon('search', 17) ?><input type="search" name="q" placeholder="Reference, customer, company or phone" value="<?= h($search) ?>" maxlength="120"></span></label>
                <label>Status <select name="status"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
                <label>Service <select name="service"><option value="">All services</option><?php foreach ($services as $service): ?><option value="<?= h($service) ?>" <?= $filterService === $service ? 'selected' : '' ?>><?= h($service) ?></option><?php endforeach; ?></select></label>
                <label class="archive-filter"><input type="checkbox" name="archive" value="1" <?= $archive ? 'checked' : '' ?>> Show archived</label>
                <div class="filter-actions"><button class="button-primary" type="submit"><?= icon('filter', 17) ?> Apply filters</button><button class="button-outline" type="submit" name="export" value="1"><?= icon('download', 17) ?> Export CSV</button></div>
            </form>

            <?php if ($selectedOrder):
                $payload = json_decode((string)$selectedOrder['payload'], true);
                $payload = is_array($payload) ? $payload : [];
                $message = (string)($payload['message'] ?? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $fullData = json_encode($payload['fields'] ?? $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            ?>
            <article class="detail-panel" id="request-detail">
                <div class="detail-heading"><div><p class="eyebrow">SELECTED REQUEST</p><h3><?= h($selectedOrder['reference']) ?></h3></div><a href="<?= h(urlWith(['id' => null])) ?>#orders" class="button-outline close-detail">Close details</a></div>
                <div class="detail-grid"><div class="submitted-content"><h4>Customer submission</h4><pre><?= h($message) ?></pre><details><summary>Full submitted data</summary><pre><?= h($fullData) ?></pre></details></div>
                <div class="request-editor"><h4>Manage request</h4><form method="post"><?php csrfField(); ?><input type="hidden" name="id" value="<?= (int)$selectedOrder['id'] ?>"><label for="edit-status">Status</label><select id="edit-status" name="status"><?php foreach ($statuses as $status): ?><option value="<?= h($status) ?>" <?= $status === $selectedOrder['status'] ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select><label for="edit-note">Internal note</label><textarea id="edit-note" name="note" maxlength="5000" rows="4" placeholder="Add an internal note..."></textarea><label class="archive-filter"><input type="checkbox" name="archived" value="1" <?= $selectedOrder['archived'] ? 'checked' : '' ?>> Archive request</label><button class="button-primary" name="action" value="update">Save changes</button></form>
                <h4 class="history-title">Activity history</h4><div class="history-list"><?php if (!$activity): ?><p class="empty-small">No activity yet.</p><?php endif; ?><?php foreach ($activity as $entry): ?><div class="history-item"><strong><?= h(ucfirst($entry['status'])) ?></strong><small><?= h(cairoTime($entry['created_at'])) ?> · <?= h($entry['actor']) ?></small><?php if ($entry['note']): ?><p><?= nl2br(h($entry['note'])) ?></p><?php endif; ?></div><?php endforeach; ?></div></div></div>
            </article>
            <?php endif; ?>
            <div class="table-panel"><div class="table-title"><div><h3>All quote requests</h3><p>Newest entries first · <?= number_format($totalOrders) ?> matching</p></div><span class="table-limit">25 per page</span></div>
                <div class="table-scroll"><table class="requests-table"><thead><tr><th>Reference</th><th>Customer / Company</th><th>Service</th><th>Status</th><th>Received · Cairo</th><th class="action-col">Details</th></tr></thead><tbody>
                <?php foreach ($rows as $row): ?><tr><td data-label="Reference"><strong><?= h($row['reference']) ?></strong></td><td data-label="Customer"><span><?= h($row['name']) ?></span><small><?= h($row['company']) ?></small></td><td data-label="Service"><?= h($row['service']) ?></td><td data-label="Status"><span class="status-badge status-<?= h($row['status']) ?>"><?= h(ucfirst($row['status'])) ?></span></td><td data-label="Received"><?= h(cairoTime($row['created_at'])) ?></td><td data-label="Details" class="action-col"><a class="row-link" href="<?= h(urlWith(['id' => $row['id']])) ?>#request-detail">View <?= icon('arrow', 15) ?></a></td></tr><?php endforeach; ?>
                </tbody></table><?php if (!$rows): ?><div class="empty-state"><span><?= icon('orders', 28) ?></span><strong>No quote requests found</strong><p>Adjust the filters or select a different reporting period.</p></div><?php endif; ?></div>
                <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Quote request pages"><span>Page <?= $page ?> of <?= $totalPages ?></span><div><a class="<?= $page <= 1 ? 'disabled' : '' ?>" <?= $page > 1 ? 'href="' . h(urlWith(['page' => $page - 1])) . '#orders"' : 'aria-disabled="true"' ?>>Previous</a><a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page < $totalPages ? 'href="' . h(urlWith(['page' => $page + 1])) . '#orders"' : 'aria-disabled="true"' ?>>Next</a></div></nav><?php endif; ?>
            </div>
        </section>

        <section id="analytics" class="dashboard-section">
            <div class="section-heading"><div><p class="eyebrow">AUDIENCE INSIGHTS</p><h2>Website analytics</h2></div><span class="section-tag">Selected period</span></div>
            <div class="analytics-grid">
                <article class="analytics-panel"><h3>Most visited pages</h3><p class="panel-subtitle">Page views from recorded website events</p>
                    <?php $maxViews = max(1, (int)($topPages[0]['total'] ?? 1)); ?>
                    <?php foreach ($topPages as $row): $percent = max(3, (int)round((int)$row['total'] * 100 / $maxViews)); ?><div class="traffic-item"><div><span><?= h(pageName($row['page'])) ?></span><strong><?= number_format((int)$row['total']) ?></strong></div><div class="traffic-track"><span style="width:<?= $percent ?>%"></span></div></div><?php endforeach; ?>
                    <?php if (!$topPages): ?><p class="empty-small">No page views for this period.</p><?php endif; ?>
                </article>
                <article class="analytics-panel"><h3>Country interest</h3><p class="panel-subtitle">Saved orders / visitors is indicative, not verified conversions.</p><div class="table-scroll"><table class="small-table"><thead><tr><th>Country</th><th>Visitors</th><th>Starts</th><th>Orders</th><th>Ratio</th></tr></thead><tbody>
                    <?php foreach ($by as $row): $visitors = (int)$row['visitors']; $orders = (int)($row['orders'] ?? 0); ?><tr><td><?= h($row['country']) ?></td><td><?= number_format($visitors) ?></td><td><?= number_format((int)$row['starts']) ?></td><td><?= number_format($orders) ?></td><td><?= $visitors ? round($orders / $visitors * 100, 1) . '%' : '—' ?></td></tr><?php endforeach; ?>
                    </tbody></table><?php if (!$by): ?><p class="empty-small">No country data for this period.</p><?php endif; ?></div></article>
            </div>
            <article class="table-panel recent-panel"><div class="table-title"><div><h3>Recent website visits</h3><p>Latest 50 page views · IP data is retained for up to 30 days when maintenance is enabled.</p></div></div><div class="table-scroll"><table><thead><tr><th>Time · Cairo</th><th>IP address</th><th>Country</th><th>City</th><th>Page</th></tr></thead><tbody><?php foreach ($recentVisits as $visit): ?><tr><?php foreach (['created_at','ip','country','city','page'] as $key): ?><td><?= h(visitValue($visit, $key)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><?php if (!$recentVisits): ?><p class="empty-small">No visits recorded for this period.</p><?php endif; ?></div></article>
        </section>
        <?php endif; ?>
        <footer class="dashboard-footer">AK INTERNATIONAL · PRIVATE OPERATIONS PORTAL <span>All times shown in Cairo time</span></footer>
    </main>
</div>
<?php endif; ?>
</body>
</html>
