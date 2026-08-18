<?php
declare(strict_types=1);
/**
 * IFRS/IAS 2 Inventory Valuation
 *
 * Entry point for inventory valuation using:
 * - FIFO (First In, First Out)
 * - Weighted Average Cost
 * - Specific Identification
 *
 * Implements IAS 2: Inventories standard
 */

require '../../app/init.php';

if (!is_admin()) {
    redirect_to('/');
}

$companyId = (int) get_session_company();

// Get valuation records
$stmt = db()->prepare(
    "SELECT id, valuation_date, method, status, item_count, total_value, created_by
     FROM inventory_valuations
     WHERE company_id = :cid
     ORDER BY valuation_date DESC
     LIMIT 50"
);
$stmt->execute(['cid' => $companyId]);
$valuations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new valuation creation
$newValuation = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    try {
        $valuationDate = (string) ($_POST['valuation_date'] ?? '');
        $method = (string) ($_POST['valuation_method'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valuationDate)) {
            throw new Exception('Invalid date format');
        }

        if (!in_array($method, ['fifo', 'weighted_average', 'specific'], true)) {
            throw new Exception('Invalid valuation method');
        }

        $insertStmt = db()->prepare(
            "INSERT INTO inventory_valuations
             (company_id, valuation_date, method, status, created_by)
             VALUES (:cid, :date, :method, 'draft', :user)"
        );

        $insertStmt->execute([
            'cid' => $companyId,
            'date' => $valuationDate,
            'method' => $method,
            'user' => current_user()['id'] ?? null
        ]);

        $newValuation = [
            'id' => db()->lastInsertId(),
            'valuation_date' => $valuationDate,
            'method' => $method,
            'status' => 'draft',
            'item_count' => 0,
            'total_value' => 0
        ];

        $successMessage = "Valuation created. Add items to value.";
    } catch (Exception $e) {
        $errorMessage = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Inventory Valuation - IAS 2</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { margin-bottom: 30px; }
        h1 { margin: 0 0 5px; color: #333; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 15px; }
        .btn { padding: 8px 16px; background: #0066cc; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #0052a3; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }

        .alert { padding: 12px 16px; margin-bottom: 20px; border-radius: 6px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }

        .valuations-table { width: 100%; border-collapse: collapse; }
        .valuations-table th { background: #f0f0f0; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #ddd; }
        .valuations-table td { padding: 12px; border-bottom: 1px solid #ddd; }
        .valuations-table tr:hover { background: #fafafa; }
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-draft { background: #fff3cd; color: #856404; }
        .status-posting { background: #d1ecf1; color: #0c5460; }
        .status-posted { background: #d4edda; color: #155724; }
        .status-archived { background: #e2e3e5; color: #383d41; }

        .method-info { background: #e7f3ff; border-left: 4px solid #0066cc; padding: 12px; margin-bottom: 15px; font-size: 13px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Inventory Valuation (IAS 2)</h1>
            <p class="subtitle">Determine inventory costs using FIFO, Weighted Average, or Specific Identification</p>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success"><?= e($successMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-error"><?= e($errorMessage) ?></div>
        <?php endif; ?>

        <!-- Create New Valuation -->
        <div class="card">
            <h2>Create New Valuation</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create">

                <div class="method-info">
                    <strong>Valuation Methods:</strong><br>
                    • <strong>FIFO</strong>: First items purchased are first sold (common for perishables)<br>
                    • <strong>Weighted Average</strong>: Uses average cost of all units (most stable)<br>
                    • <strong>Specific Identification</strong>: Track individual item costs (for jewelry, high-value items)
                </div>

                <div class="grid">
                    <div class="form-group">
                        <label>Valuation Date</label>
                        <input type="date" name="valuation_date" required>
                    </div>
                    <div class="form-group">
                        <label>Method</label>
                        <select name="valuation_method" required>
                            <option value="">— Choose method —</option>
                            <option value="fifo">FIFO (First In, First Out)</option>
                            <option value="weighted_average">Weighted Average Cost</option>
                            <option value="specific">Specific Identification</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn">+ Create Valuation</button>
            </form>
        </div>

        <!-- Recent Valuations -->
        <div class="card">
            <h2>Recent Valuations</h2>

            <?php if (empty($valuations) && empty($newValuation)): ?>
                <p style="color: #666;">No valuations created yet.</p>
            <?php else: ?>
                <table class="valuations-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Items</th>
                            <th>Total Value</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Show new valuation at top if just created -->
                        <?php if ($newValuation): ?>
                            <tr style="background: #fffacd;">
                                <td><?= e($newValuation['valuation_date']) ?></td>
                                <td><strong><?= ucfirst(str_replace('_', ' ', $newValuation['method'])) ?></strong></td>
                                <td><?= $newValuation['item_count'] ?></td>
                                <td><?= number_format($newValuation['total_value'], 2) ?></td>
                                <td><span class="status-badge status-draft">DRAFT</span></td>
                                <td>—</td>
                                <td>
                                    <a href="?edit=<?= $newValuation['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">Edit</a>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($valuations as $v): ?>
                            <tr>
                                <td><?= e($v['valuation_date']) ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $v['method'])) ?></td>
                                <td><?= (int) $v['item_count'] ?></td>
                                <td><?= number_format($v['total_value'], 2) ?></td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($v['status']) ?>">
                                        <?= strtoupper($v['status']) ?>
                                    </span>
                                </td>
                                <td><?= e((string) ($v['created_by'] ?? '—')) ?></td>
                                <td>
                                    <a href="?edit=<?= (int) $v['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Help Section -->
        <div class="card">
            <h2>📖 Understanding IAS 2 Inventory Valuation</h2>
            <div style="line-height: 1.6; color: #555; font-size: 14px;">
                <p><strong>IAS 2 Standard:</strong> Inventories must be valued at the lower of cost or net realizable value.</p>

                <h3 style="margin-top: 15px;">When to Use Each Method:</h3>
                <ul>
                    <li><strong>FIFO:</strong> Best for perishable goods, where older stock must sell first</li>
                    <li><strong>Weighted Average:</strong> Best for commodity items, provides stable valuation</li>
                    <li><strong>Specific ID:</strong> Best for high-value unique items (jewelry, equipment)</li>
                </ul>

                <h3 style="margin-top: 15px;">Key Concepts:</h3>
                <ul>
                    <li><strong>Cost:</strong> Includes purchase price + import duties + direct costs to bring to saleable condition</li>
                    <li><strong>Net Realizable Value:</strong> Estimated selling price - estimated costs to complete and sell</li>
                    <li><strong>Valuation Adjustment:</strong> Record the difference as a provision for obsolescence</li>
                </ul>

                <p style="margin-top: 15px; background: #fff8dc; padding: 10px; border-radius: 4px;">
                    <strong>💡 Tip:</strong> Run valuations monthly to catch obsolete or slow-moving stock early.
                </p>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px; color: #666; font-size: 13px;">
            <p>Inventory Valuation System | IAS 2 Compliant | Supports FIFO, Weighted Average, Specific ID</p>
        </div>
    </div>
</body>
</html>
