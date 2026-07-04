<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_admin();
require_company_context();

$currentCompany = current_company();
$companyId = (int) ($currentCompany['id'] ?? 0);
$pageTitle = 'Payment Receipts';

if (!table_exists('invoice_payment_receipts')) {
    include __DIR__ . '/../../app/views/partials/admin_header.php';
    ?>
    <section class="card">
        <h2>Payment receipts unavailable</h2>
        <p>Apply the latest database migration to enable receipt records and reporting.</p>
    </section>
    <?php
    include __DIR__ . '/../../app/views/partials/admin_footer.php';
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$method = trim((string) ($_GET['method'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$export = strtolower(trim((string) ($_GET['export'] ?? '')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['r.company_id = :company_id'];
$params = ['company_id' => $companyId];

if ($search !== '') {
    $where[] = '(r.receipt_no LIKE :search OR r.payment_reference LIKE :search OR ti.invoice_no LIKE :search OR cp.organization_name LIKE :search OR u.name LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($method !== '') {
    $where[] = 'r.payment_method = :payment_method';
    $params['payment_method'] = $method;
}

if ($dateFrom !== '') {
    $where[] = 'r.received_on >= :date_from';
    $params['date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'r.received_on <= :date_to';
    $params['date_to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);
$baseFrom = ' FROM invoice_payment_receipts r
    LEFT JOIN task_invoices ti ON ti.id = r.invoice_id
    LEFT JOIN invoice_payment_requests ipr ON ipr.id = r.payment_request_id
    LEFT JOIN client_profiles cp ON cp.id = r.client_id
    LEFT JOIN users u ON u.id = cp.user_id
    LEFT JOIN users creator ON creator.id = r.created_by';

$summaryStmt = db()->prepare('SELECT COUNT(*) AS total_rows, COALESCE(SUM(r.amount_received), 0) AS total_amount' . $baseFrom . ' WHERE ' . $whereSql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['total_rows' => 0, 'total_amount' => 0];
$totalRows = (int) ($summary['total_rows'] ?? 0);
$totalAmount = (float) ($summary['total_amount'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rowsSql = 'SELECT r.*, ti.invoice_no, ti.total_amount AS invoice_total, ti.status AS invoice_status,
        ipr.status AS payment_request_status, ipr.requested_on,
        cp.organization_name, u.name AS client_user_name, creator.name AS created_by_name'
    . $baseFrom
    . ' WHERE ' . $whereSql
    . ' ORDER BY r.received_on DESC, r.id DESC';

if ($export === 'pdf') {
    $printStmt = db()->prepare($rowsSql);
    $printStmt->execute($params);
    $rows = $printStmt->fetchAll();

    $safeCompanyName = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($currentCompany['name'] ?? 'company'));
    $fileName = 'payment_receipts_' . $safeCompanyName . '_' . date('Ymd_His') . '.html';

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment Receipt Register</title>
        <style>
            :root { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #0f172a; }
            body { margin: 0; padding: 1.25rem; background: #fff; }
            .toolbar { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
            .toolbar button { border: 1px solid #0f172a; background: #fff; color: #0f172a; padding: 0.4rem 0.8rem; border-radius: 4px; cursor: pointer; }
            h1 { margin: 0 0 0.25rem; font-size: 1.35rem; }
            .meta { margin: 0; color: #334155; font-size: 0.92rem; }
            .summary { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 0.75rem; margin: 1rem 0; }
            .summary-box { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.6rem 0.75rem; }
            .summary-box h2 { margin: 0; font-size: 0.8rem; color: #475569; text-transform: uppercase; letter-spacing: 0.04em; }
            .summary-box strong { font-size: 1.15rem; }
            table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
            th, td { border: 1px solid #cbd5e1; padding: 0.4rem 0.45rem; text-align: left; vertical-align: top; }
            th { background: #f8fafc; font-weight: 600; }
            .muted { color: #64748b; }
            @media print {
                .toolbar { display: none; }
                body { padding: 0; }
                @page { size: A4 landscape; margin: 10mm; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
        <h1>Payment Receipt Register</h1>
        <p class="meta">Company: <?php echo e((string) ($currentCompany['name'] ?? '')); ?> | Generated: <?php echo e(date('Y-m-d H:i')); ?></p>
        <p class="meta">Filters: Search=<?php echo e($search !== '' ? $search : 'All'); ?>, Method=<?php echo e($method !== '' ? $method : 'All'); ?>, Date From=<?php echo e($dateFrom !== '' ? $dateFrom : 'Any'); ?>, Date To=<?php echo e($dateTo !== '' ? $dateTo : 'Any'); ?></p>

        <div class="summary">
            <div class="summary-box">
                <h2>Total Receipts</h2>
                <strong><?php echo number_format($totalRows); ?></strong>
            </div>
            <div class="summary-box">
                <h2>Total Amount Received</h2>
                <strong>NPR <?php echo number_format($totalAmount, 2); ?></strong>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Receipt No</th>
                    <th>Received On</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Invoice</th>
                    <th>Invoice Total</th>
                    <th>Invoice Status</th>
                    <th>Request Status</th>
                    <th>Client</th>
                    <th>Created By</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="11" class="muted">No receipts found for selected filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo e((string) ($row['receipt_no'] ?? '')); ?></td>
                            <td><?php echo e((string) ($row['received_on'] ?? '')); ?></td>
                            <td>NPR <?php echo number_format((float) ($row['amount_received'] ?? 0), 2); ?></td>
                            <td><?php echo e((string) ($row['payment_method'] ?? '')); ?></td>
                            <td><?php echo e((string) ($row['payment_reference'] ?? '')); ?></td>
                            <td><?php echo e((string) ($row['invoice_no'] ?? '')); ?></td>
                            <td>NPR <?php echo number_format((float) ($row['invoice_total'] ?? 0), 2); ?></td>
                            <td><?php echo e((string) ($row['invoice_status'] ?? '')); ?></td>
                            <td><?php echo e((string) ($row['payment_request_status'] ?? '')); ?></td>
                            <td><?php echo e((string) ($row['organization_name'] ?: ($row['client_user_name'] ?? ''))); ?></td>
                            <td><?php echo e((string) ($row['created_by_name'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if ($export === 'csv') {
    $csvStmt = db()->prepare($rowsSql);
    $csvStmt->execute($params);
    $rows = $csvStmt->fetchAll();

    $fileName = 'payment_receipts_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Receipt No', 'Received On', 'Amount Received', 'Payment Method', 'Reference', 'Invoice No', 'Invoice Total', 'Invoice Status', 'Payment Request Status', 'Client', 'Created By']);

    foreach ($rows as $row) {
        fputcsv($output, [
            (string) ($row['receipt_no'] ?? ''),
            (string) ($row['received_on'] ?? ''),
            number_format((float) ($row['amount_received'] ?? 0), 2, '.', ''),
            (string) ($row['payment_method'] ?? ''),
            (string) ($row['payment_reference'] ?? ''),
            (string) ($row['invoice_no'] ?? ''),
            number_format((float) ($row['invoice_total'] ?? 0), 2, '.', ''),
            (string) ($row['invoice_status'] ?? ''),
            (string) ($row['payment_request_status'] ?? ''),
            (string) ($row['organization_name'] ?: ($row['client_user_name'] ?? '')),
            (string) ($row['created_by_name'] ?? ''),
        ]);
    }

    fclose($output);
    exit;
}

$pagedSql = $rowsSql . ' LIMIT :limit OFFSET :offset';
$listStmt = db()->prepare($pagedSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue(':' . $key, $value);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$receipts = $listStmt->fetchAll();

$methodStmt = db()->prepare('SELECT DISTINCT payment_method FROM invoice_payment_receipts WHERE company_id = :company_id AND payment_method IS NOT NULL AND payment_method <> "" ORDER BY payment_method ASC');
$methodStmt->execute(['company_id' => $companyId]);
$methods = $methodStmt->fetchAll(PDO::FETCH_COLUMN);

$baseQuery = [
    'q' => $search,
    'method' => $method,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];
$csvQuery = array_filter($baseQuery, static fn ($v) => $v !== '');
$csvQuery['export'] = 'csv';
$pdfQuery = array_filter($baseQuery, static fn ($v) => $v !== '');
$pdfQuery['export'] = 'pdf';

include __DIR__ . '/../../app/views/partials/admin_header.php';
?>

<section class="card" style="display:grid; gap:1rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;">Receipt Register</h2>
            <p style="margin:0.35rem 0 0; color:#5f6c82;">Track payment receipts by date, method, and invoice.</p>
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <a class="btn btn-secondary" href="<?php echo e(url('admin/receipts.php?' . http_build_query($pdfQuery))); ?>" target="_blank" rel="noopener">Print / Export PDF</a>
            <a class="btn btn-secondary" href="<?php echo e(url('admin/receipts.php?' . http_build_query($csvQuery))); ?>">Export CSV</a>
        </div>
    </div>

    <form method="get" style="display:grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap:0.75rem; align-items:end;">
        <div>
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="<?php echo e($search); ?>" placeholder="Receipt, invoice, reference, client">
        </div>
        <div>
            <label for="method">Method</label>
            <select id="method" name="method">
                <option value="">All methods</option>
                <?php foreach ($methods as $methodOption): ?>
                    <option value="<?php echo e((string) $methodOption); ?>" <?php echo $methodOption === $method ? 'selected' : ''; ?>><?php echo e((string) $methodOption); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="date_from">Date from</label>
            <input id="date_from" type="date" name="date_from" value="<?php echo e($dateFrom); ?>">
        </div>
        <div>
            <label for="date_to">Date to</label>
            <input id="date_to" type="date" name="date_to" value="<?php echo e($dateTo); ?>">
        </div>
        <div style="display:flex; gap:0.5rem;">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-secondary" href="<?php echo e(url('admin/receipts.php')); ?>">Reset</a>
        </div>
    </form>

    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
        <div class="stat-card" style="min-width:220px;">
            <h3>Total Receipts</h3>
            <strong><?php echo number_format($totalRows); ?></strong>
        </div>
        <div class="stat-card" style="min-width:220px;">
            <h3>Total Amount Received</h3>
            <strong>NPR <?php echo number_format($totalAmount, 2); ?></strong>
        </div>
    </div>

    <div style="overflow:auto;">
        <table class="invoice-table">
            <thead>
                <tr>
                    <th>Receipt No</th>
                    <th>Received On</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Invoice</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($receipts === []): ?>
                    <tr>
                        <td colspan="9">No receipts found for selected filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td><?php echo e((string) ($receipt['receipt_no'] ?? '')); ?></td>
                            <td><?php echo e((string) ($receipt['received_on'] ?? '')); ?></td>
                            <td>NPR <?php echo number_format((float) ($receipt['amount_received'] ?? 0), 2); ?></td>
                            <td><?php echo e((string) ($receipt['payment_method'] ?? '')); ?></td>
                            <td><?php echo e((string) ($receipt['payment_reference'] ?? '')); ?></td>
                            <td><?php echo e((string) ($receipt['invoice_no'] ?? '')); ?></td>
                            <td><?php echo e((string) ($receipt['organization_name'] ?: ($receipt['client_user_name'] ?? ''))); ?></td>
                            <td>
                                <span class="badge badge-<?php echo ($receipt['payment_request_status'] ?? '') === 'paid' ? 'success' : 'primary'; ?>">
                                    <?php echo e(ucfirst((string) ($receipt['payment_request_status'] ?? 'recorded'))); ?>
                                </span>
                            </td>
                            <td>
                                <a class="btn btn-secondary" href="<?php echo e(url('admin/export-payment-receipt.php?request_id=' . (int) ($receipt['payment_request_id'] ?? 0))); ?>" target="_blank" rel="noopener">View</a>
                                <a class="btn btn-secondary" href="<?php echo e(url('admin/invoice.php?action=view&id=' . (int) ($receipt['invoice_id'] ?? 0))); ?>">Invoice</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php $pageQuery = array_filter($baseQuery, static fn ($v) => $v !== ''); $pageQuery['page'] = $i; ?>
                <a class="btn <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" href="<?php echo e(url('admin/receipts.php?' . http_build_query($pageQuery))); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../../app/views/partials/admin_footer.php';
