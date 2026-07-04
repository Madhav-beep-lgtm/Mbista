<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$pageTitle = 'Home';
$bodyClass = 'home-page';
$stats = stats_summary();

$groupParent = null;
$groupSubsidiaries = [];
$associatedFirm = null;
if (table_exists('companies')) {
    $companiesStmt = db()->query("SELECT id, code, name, parent_company_id FROM companies WHERE is_active = 1 ORDER BY name ASC");
    foreach ($companiesStmt->fetchAll() as $companyRow) {
        if ($companyRow['code'] === 'AGHPL') {
            $groupParent = $companyRow;
        } elseif ($companyRow['code'] === 'MBAACA') {
            $associatedFirm = $companyRow;
        } elseif ((int) ($companyRow['parent_company_id'] ?? 0) > 0) {
            $groupSubsidiaries[] = $companyRow;
        }
    }
}

// Approved company descriptions keyed by company code.
$companyProfiles = [
    'AGHPL' => [
        'category' => 'Investment & Holding',
        'description' => 'Investment and holding company that holds, manages, coordinates, and supports the group companies.',
        'services' => ['Group coordination', 'Investment holding', 'Subsidiary support'],
    ],
    'EBCPL' => [
        'category' => 'Business Consulting',
        'description' => 'Professional business-consulting company supporting organizations with advisory, outsourcing, and compliance services.',
        'services' => ['Business consulting', 'Accounting outsourcing', 'Financial advisory', 'Legal and compliance support', 'Management consulting'],
    ],
    'MBTAS' => [
        'category' => 'Training & Advisory',
        'description' => 'Training and advisory company focused on professional development and business-specific accounting support.',
        'services' => ['Professional training', 'Accounting advisory', 'Jewellery-business accounting and advisory', 'Internal-control support', 'Outsourcing and consulting'],
    ],
    'PEPL' => [
        'category' => 'Education Services',
        'description' => 'Education and consultation services for students and individuals planning to study or work abroad.',
        'services' => ['Education consultation', 'Language preparation classes', 'Student-visa consultation', 'Working-visa consultation', 'Documentation support'],
    ],
    'VEPL' => [
        'category' => 'Education Consulting',
        'description' => 'Education-consulting company guiding students toward suitable academic pathways and institutions.',
        'services' => ['Education consulting', 'Academic pathway guidance', 'Application and documentation support'],
    ],
    'MBAACA' => [
        'category' => 'Chartered Accountants',
        'description' => 'Professional audit and accounting firm established in January 2024, delivering assurance, taxation, and advisory services.',
        'services' => ['Statutory and internal audit', 'Taxation services', 'Accounting advisory', 'Risk and compliance', 'Business advisory'],
    ],
];

$publicTeamMembers = public_team_members();
$teamPreview = array_slice($publicTeamMembers, 0, 4);
$homepageServiceCategories = [
    'Audit and assurance',
    'Consulting and outsourcing',
    'Training and advisory',
    'Education services',
];
$companyDisplayCount = count(array_merge($groupParent ? [$groupParent] : [], $groupSubsidiaries, $associatedFirm ? [$associatedFirm] : []));

include __DIR__ . '/../app/views/partials/header.php';
?>
<section class="hero" id="top">
    <div class="container hero-panel">
        <div class="hero-backdrop" aria-hidden="true"></div>
        <div class="hero-grid">
            <div class="hero-copy">
                <div class="kicker">Audit &middot; Advisory &middot; Investment &middot; Training &middot; Education</div>
                <h1><?= e(setting('hero_title', 'Integrated expertise for business, finance, investment, and education.')) ?></h1>
                <p><?= e(setting('hero_description', 'A professional group spanning audit and assurance, business consulting, corporate training, investment holding, and education services.')) ?></p>
                <div class="actions">
                    <a class="button" href="<?= e(url('contact/request-consultation.php')) ?>"><?= icon('contact') ?>Request consultation</a>
                    <a class="button secondary" href="<?= e(url('about/index.php')) ?>"><?= icon('about') ?>Explore the group</a>
                </div>
            </div>
            <div class="hero-aside">
                <div class="hero-card">
                    <h3><?= icon('companies') ?>One group, six companies</h3>
                    <p>Chartered accountancy, business consulting, training, investment holding, and education services under coordinated professional leadership.</p>
                    <ul class="checklist">
                        <li>Audit, tax, and assurance</li>
                        <li>Consulting and outsourcing</li>
                        <li>Training and education pathways</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="about">
    <div class="container section-head">
        <div>
            <div class="kicker">About the group</div>
            <h2>A coordinated group of professional-service companies</h2>
            <p>The group brings together an associated chartered-accountancy practice, an investment holding company, and specialised subsidiaries in consulting, training, and education — each focused on disciplined, integrity-led service.</p>
        </div>
    </div>
    <div class="container stats-grid">
        <div class="stat-card"><strong><?= e($companyDisplayCount) ?></strong><span>Group &amp; associated companies</span></div>
        <div class="stat-card"><strong>2024</strong><span>Chartered practice established</span></div>
        <div class="stat-card"><strong><?= e(count($publicTeamMembers)) ?></strong><span>Published team profiles</span></div>
        <div class="stat-card"><strong><?= e(count($homepageServiceCategories)) ?></strong><span>Service categories</span></div>
    </div>
</section>

<section class="section" id="group-structure">
    <div class="container section-head">
        <div>
            <div class="kicker">Group structure</div>
            <h2>How the group is organised</h2>
            <p>Altiora Global Holdings holds and coordinates the subsidiary companies. M. Bista and Associates operates as an associated professional firm.</p>
        </div>
    </div>
    <div class="container group-structure">
        <?php if ($groupParent): ?>
            <div class="group-structure-parent">
                <strong><?= e($groupParent['name']) ?></strong>
                <span>Investment &amp; holding company</span>
            </div>
            <div class="group-structure-connector" aria-hidden="true"></div>
        <?php endif; ?>
        <?php if ($groupSubsidiaries !== []): ?>
            <div class="group-structure-children">
                <?php foreach ($groupSubsidiaries as $subsidiary): ?>
                    <div class="group-structure-child">
                        <strong><?= e($subsidiary['name']) ?></strong>
                        <span><?= e($companyProfiles[$subsidiary['code']]['category'] ?? 'Group company') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($associatedFirm): ?>
            <div class="group-structure-associate">
                <strong><?= e($associatedFirm['name']) ?></strong>
                <span>Associated professional firm &mdash; chartered accountants</span>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section" id="services">
    <div class="container section-head">
        <div>
            <div class="kicker">Our services</div>
            <h2>Core service categories</h2>
            <p>Focused professional services delivered by the right company within the group.</p>
        </div>
    </div>
    <div class="container package-grid">
        <article class="card package-card featured">
            <div class="badge"><?= icon('reports') ?>Audit &amp; assurance</div>
            <h3>Audit, tax, and assurance</h3>
            <p>Statutory audit, internal audit, tax audit, taxation services, and assurance engagements led by the chartered-accountancy practice.</p>
        </article>
        <article class="card package-card">
            <div class="badge"><?= icon('accounting') ?>Consulting &amp; outsourcing</div>
            <h3>Business consulting</h3>
            <p>Business consulting, accounting outsourcing, financial advisory, legal and compliance support, and process improvement.</p>
        </article>
        <article class="card package-card">
            <div class="badge"><?= icon('insights') ?>Training &amp; advisory</div>
            <h3>Professional training</h3>
            <p>Professional training, accounting advisory, jewellery-business accounting, and business-specific internal-control support.</p>
        </article>
        <article class="card package-card">
            <div class="badge"><?= icon('teams') ?>Education services</div>
            <h3>Education &amp; visa consultation</h3>
            <p>Education consultation, language preparation, student and working-visa guidance, and documentation support for study abroad.</p>
        </article>
    </div>
</section>

<section class="section" id="mbaaca">
    <div class="container section-head">
        <div>
            <div class="kicker">Chartered accountants</div>
            <h2>M. Bista and Associates, Chartered Accountants</h2>
            <p>Established in January 2024, the firm delivers audit, taxation, accounting, compliance, and advisory services with a structured, evidence-led operating model.</p>
        </div>
        <a class="button secondary" href="<?= e(url('contact/request-consultation.php')) ?>"><?= icon('contact') ?>Engage the firm</a>
    </div>
    <div class="container feature-grid">
        <article class="card">
            <div class="badge"><?= icon('reports') ?>Assurance</div>
            <h3>Audit quality with structure</h3>
            <p>Statutory, internal, and tax audit engagements organised around evidence, review, documentation, and clear reporting responsibility.</p>
        </article>
        <article class="card">
            <div class="badge"><?= icon('accounting') ?>Taxation &amp; compliance</div>
            <h3>Reliable compliance support</h3>
            <p>Taxation services, financial reporting, due diligence, and compliance workflows kept organised and company-scoped.</p>
        </article>
        <article class="card">
            <div class="badge"><?= icon('insights') ?>Advisory</div>
            <h3>Better operating decisions</h3>
            <p>Business advisory, risk and internal-control consulting, and financial and management consulting for growing organisations.</p>
        </article>
    </div>
</section>

<section class="section" id="companies">
    <div class="container section-head">
        <div>
            <div class="kicker">Group companies</div>
            <h2>Companies in the group</h2>
            <p>Each company focuses on a clear service discipline, coordinated under the holding company.</p>
        </div>
    </div>
    <div class="container card-grid">
        <?php foreach (array_merge($groupParent ? [$groupParent] : [], $groupSubsidiaries, $associatedFirm ? [$associatedFirm] : []) as $companyRow): ?>
            <?php $profile = $companyProfiles[$companyRow['code']] ?? null; ?>
            <article class="card">
                <span class="company-card-label"><?= e($profile['category'] ?? 'Group company') ?></span>
                <h3><?= e($companyRow['name']) ?></h3>
                <p><?= e($profile['description'] ?? '') ?></p>
                <?php if (!empty($profile['services'])): ?>
                    <ul class="company-card-services">
                        <?php foreach ($profile['services'] as $serviceItem): ?>
                            <li><?= e($serviceItem) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="actions">
                    <a class="button secondary" href="<?= e(url('about/group-companies.php')) ?>">Learn more</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($teamPreview !== []): ?>
<section class="section" id="team">
    <div class="container section-head">
        <div>
            <div class="kicker">Our people</div>
            <h2>Professional team</h2>
            <p>Experienced professionals across audit, consulting, training, and education services.</p>
        </div>
        <a class="button secondary" href="<?= e(url('team/index.php')) ?>"><?= icon('teams') ?>View all team members</a>
    </div>
    <div class="container team-grid">
        <?php foreach ($teamPreview as $member): ?>
            <article class="team-card">
                <?php if (!empty($member['photo_path'])): ?>
                    <img class="team-card-photo" src="<?= e(url($member['photo_path'])) ?>" alt="Photo of <?= e($member['name']) ?>" loading="lazy">
                <?php else: ?>
                    <span class="team-card-initials" aria-hidden="true"><?= e(team_member_initials((string) $member['name'])) ?></span>
                <?php endif; ?>
                <h3><?= e($member['name']) ?></h3>
                <?php if (!empty($member['job_title'])): ?>
                    <p class="team-card-title"><?= e($member['job_title']) ?></p>
                <?php endif; ?>
                <?php if (!empty($member['company_name'])): ?>
                    <p class="team-card-meta"><?= e($member['company_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($member['qualifications'])): ?>
                    <p class="team-card-meta"><?= e($member['qualifications']) ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="section" id="insights">
    <div class="container section-head">
        <div>
            <div class="kicker">Insights</div>
            <h2>Guidance and updates</h2>
            <p>Practical professional guidance for clients of the group.</p>
        </div>
    </div>
    <div class="container card-grid">
        <article class="card">
            <h3><?= icon('insights') ?>Regulatory updates</h3>
            <p>Accounting standards, audit requirements, tax notices, and statutory changes relevant to clients.</p>
        </article>
        <article class="card">
            <h3><?= icon('tasks') ?>Compliance calendar</h3>
            <p>Due-date reminders and filing alerts that help clients prepare on time.</p>
        </article>
        <article class="card">
            <h3><?= icon('contact') ?>Client guidance</h3>
            <p>Practical notes for records, engagement documents, filings, and review preparation.</p>
        </article>
    </div>
</section>

<section class="section" id="contact">
    <div class="container">
        <div class="cta-band">
            <div>
                <h2>Start a professional conversation</h2>
                <p>Talk to the group about audit, consulting, training, or education services.</p>
            </div>
            <a class="button" href="<?= e(url('contact/request-consultation.php')) ?>"><?= icon('contact') ?>Request a consultation</a>
        </div>
    </div>
    <div class="container contact-info-grid" style="margin-top: 18px;">
        <div class="card">
            <strong>Office</strong>
            <span class="muted"><?= e(setting('office_address', '')) ?></span>
        </div>
        <div class="card">
            <strong>Phone</strong>
            <span class="muted"><?= e(setting('support_phone', '')) ?></span>
        </div>
        <div class="card">
            <strong>Email</strong>
            <span class="muted"><?= e(setting('support_email', '')) ?></span>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
