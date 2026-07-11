<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Privacy Policy';
include __DIR__ . '/../app/views/partials/header.php';
?>
<div class="page-header-band">
    <div class="container">
        <span class="section-label">Legal</span>
        <h1>Privacy Policy</h1>
        <p>How M.Bista &amp; Associates collects, uses, and protects your information.</p>
    </div>
</div>

<section class="section">
    <div class="container" style="max-width: 860px;">
        <div class="card" style="display: grid; gap: 14px; padding: 26px 30px;">
            <p><em>Last updated: <?= e(date('d M Y')) ?></em></p>

            <h3>1. Who we are</h3>
            <p>M.Bista &amp; Associates, Chartered Accountants ("we", "our") operates this website and the client portal to deliver audit, taxation, accounting, and advisory services in Nepal.</p>

            <h3>2. Information we collect</h3>
            <p>We collect the information you give us directly: your name, organisation, contact details, and the contents of enquiries you send. If you are a client with portal access, we also hold engagement records such as tasks, invoices, documents you upload, and accounting records we maintain on your behalf.</p>

            <h3>3. How we use it</h3>
            <p>Your information is used only to deliver and administer our professional services, to communicate with you about your engagements, to meet our legal and regulatory obligations (including those set by the Institute of Chartered Accountants of Nepal and Nepali tax law), and to keep the portal secure.</p>

            <h3>4. Confidentiality</h3>
            <p>As chartered accountants we are bound by professional confidentiality. We do not sell your information, and we do not share it with third parties except where required by law, by a regulator, or with service providers (such as hosting) bound by equivalent obligations.</p>

            <h3>5. Security and retention</h3>
            <p>Client records are stored on access-controlled systems; portal access requires individual credentials. We retain engagement records for the periods required by Nepali law and professional standards, after which they are securely disposed of.</p>

            <h3>6. Your choices</h3>
            <p>You may ask us to correct your contact information, or ask what information we hold about you, by writing to <a href="mailto:<?= e((string) setting('support_email', 'info@mbca.com.np')) ?>"><?= e((string) setting('support_email', 'info@mbca.com.np')) ?></a>.</p>

            <h3>7. Changes</h3>
            <p>We may update this policy from time to time; the latest version will always be published on this page.</p>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
