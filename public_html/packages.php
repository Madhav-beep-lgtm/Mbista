<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Packages';
$plans = plans();
include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="hero">
    <div class="container hero-panel">
        <div class="hero-grid">
            <div>
                <div class="kicker">Hosting packages</div>
                <h1>Choose a package that matches the customer workload.</h1>
                <p>All plans are stored in MySQL, so you can edit pricing and specs from the admin panel without changing code.</p>
            </div>
            <div class="hero-card">
                <h3>Need a custom setup?</h3>
                <p>Order the closest plan, then update the order notes with the exact requirements or domain transfer details.</p>
                <a class="button secondary" href="<?= e(url('contact.php')) ?>">Contact us</a>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container package-grid">
        <?php foreach ($plans as $plan): ?>
            <article class="card package-card <?= (int) $plan['sort_order'] === 1 ? 'featured' : '' ?>">
                <div class="badge"><?= e(ucfirst($plan['billing_cycle'])) ?> billing</div>
                <h3><?= e($plan['name']) ?></h3>
                <div class="price"><?= e(site_currency_symbol()) ?><?= e(number_format((float) $plan['price'], 2)) ?> <small>/ <?= e($plan['billing_cycle']) ?></small></div>
                <ul class="package-list">
                    <li><?= e($plan['disk_space_gb']) ?> GB disk space</li>
                    <li><?= e($plan['bandwidth_gb']) ?> GB bandwidth</li>
                    <li><?= e($plan['email_accounts']) ?> email accounts</li>
                    <li><?= e($plan['databases_count']) ?> databases</li>
                    <li><?= e($plan['domains_allowed']) ?> domain(s)</li>
                </ul>
                <p><?= e($plan['features']) ?></p>
                <div class="actions">
                    <a class="button" href="<?= e(url('order.php?plan_id=' . (int) $plan['id'])) ?>">Order now</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
