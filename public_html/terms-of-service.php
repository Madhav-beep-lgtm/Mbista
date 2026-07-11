<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Terms of Service';
include __DIR__ . '/../app/views/partials/header.php';
?>
<div class="page-header-band">
    <div class="container">
        <span class="section-label">Legal</span>
        <h1>Terms of Service</h1>
        <p>The terms that apply when you use this website and the client portal.</p>
    </div>
</div>

<section class="section">
    <div class="container" style="max-width: 860px;">
        <div class="card" style="display: grid; gap: 14px; padding: 26px 30px;">
            <p><em>Last updated: <?= e(date('d M Y')) ?></em></p>

            <h3>1. About these terms</h3>
            <p>By using this website or the client portal of M.Bista &amp; Associates, Chartered Accountants, you agree to these terms. Professional engagements themselves are governed by the engagement letter signed for each assignment; if these terms and an engagement letter differ, the engagement letter prevails.</p>

            <h3>2. Use of the portal</h3>
            <p>Portal accounts are personal. Keep your password confidential and tell us immediately if you believe your account has been compromised. You agree not to attempt to access data belonging to other clients or to interfere with the operation of the service.</p>

            <h3>3. Your information and documents</h3>
            <p>You are responsible for the accuracy and completeness of the information and documents you provide. Our reports, filings, and advice rely on what you supply and on the deadlines being met on your side.</p>

            <h3>4. Invoices and payment</h3>
            <p>Invoices issued through the portal are payable by their due date. Requests for discounts, extended credit periods, or instalment payments can be raised in the portal and take effect only once approved by us in writing (including portal confirmation).</p>

            <h3>5. Website content</h3>
            <p>Articles, updates, and other website content are general information, not professional advice for your specific situation. Please consult us before acting on any of it.</p>

            <h3>6. Liability</h3>
            <p>To the extent permitted by law and professional standards, our liability in relation to the website and portal is limited to providing the services described in the applicable engagement letter.</p>

            <h3>7. Governing law</h3>
            <p>These terms are governed by the laws of Nepal. Any disputes shall be subject to the jurisdiction of the courts of Kathmandu.</p>

            <h3>8. Contact</h3>
            <p>Questions about these terms: <a href="mailto:<?= e((string) setting('support_email', 'info@mbca.com.np')) ?>"><?= e((string) setting('support_email', 'info@mbca.com.np')) ?></a>.</p>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
