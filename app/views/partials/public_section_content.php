<?php
$items = $sidebarItems ?? [];

$renderIntroGrid = static function (array $items, string $currentPage): void {
    echo '<div class="link-grid">';
    foreach ($items as $key => $item) {
        if ($key === $currentPage) {
            continue;
        }
        echo '<a class="link-tile" href="' . e(url($item['url'])) . '">';
        echo '<strong>' . e($item['label']) . '</strong>';
        echo '<span>' . e($item['description'] ?? '') . '</span>';
        echo '</a>';
    }
    echo '</div>';
};
?>
<div class="content-prose">
    <p class="lead"><?= e($pageDescription ?? '') ?></p>

    <?php if ($sectionKey === 'team'): ?>
        <?php
        $category = match ($currentPage) {
            'leadership' => 'leadership',
            'management' => 'management',
            'professional-team' => 'professional',
            default => null,
        };
        $members = public_team_members($category);
        ?>
        <?php if ($members === []): ?>
            <p>No public team profiles are currently published for this category.</p>
        <?php else: ?>
            <div class="team-grid internal-team-grid">
                <?php foreach ($members as $member): ?>
                    <article class="team-card">
                        <?php if (!empty($member['photo_path'])): ?>
                            <img class="team-card-photo" src="<?= e(url($member['photo_path'])) ?>" alt="Photo of <?= e($member['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="team-card-initials" aria-hidden="true"><?= e(team_member_initials((string) $member['name'])) ?></span>
                        <?php endif; ?>
                        <h3><?= e($member['name']) ?></h3>
                        <?php if (!empty($member['job_title'])): ?><p class="team-card-title"><?= e($member['job_title']) ?></p><?php endif; ?>
                        <?php if (!empty($member['company_name'])): ?><p class="team-card-meta"><?= e($member['company_name']) ?></p><?php endif; ?>
                        <?php if (!empty($member['qualifications'])): ?><p class="team-card-meta"><?= e($member['qualifications']) ?></p><?php endif; ?>
                        <?php if (!empty($member['expertise'])): ?><p class="team-card-meta"><?= e($member['expertise']) ?></p><?php endif; ?>
                        <?php if (!empty($member['bio'])): ?><p class="team-card-bio"><?= e($member['bio']) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($sectionKey === 'services' && $currentPage !== 'overview'): ?>
        <div class="service-detail-grid">
            <div>
                <h2>Scope of service</h2>
                <ul>
                    <li>Structured review and advisory support within the approved service area.</li>
                    <li>Documentation, reporting, compliance, or consultation support as applicable.</li>
                    <li>Coordination with the relevant group company or associated professional firm.</li>
                </ul>
            </div>
            <div>
                <h2>Suitable clients</h2>
                <ul>
                    <li>Businesses seeking professional accounting, audit, advisory, consulting, training, or education guidance.</li>
                    <li>Clients that need organised records, compliance support, or structured professional advice.</li>
                </ul>
            </div>
        </div>
        <p><a class="button" href="<?= e(url('contact/request-consultation.php')) ?>"><?= icon('contact') ?>Request consultation</a></p>
        <h2>Related services</h2>
        <?php $renderIntroGrid($items, $currentPage); ?>
    <?php elseif ($sectionKey === 'contact' && in_array($currentPage, ['general-enquiry', 'request-consultation'], true)): ?>
        <div class="form-card contact-section-form">
            <h2><?= $currentPage === 'request-consultation' ? 'Request a consultation' : 'General enquiry' ?></h2>
            <form method="post" action="<?= e(url('contact.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Name<input type="text" name="name" required></label>
                <label>Email<input type="email" name="email" required></label>
                <label>Subject<input type="text" name="subject" value="<?= $currentPage === 'request-consultation' ? 'Consultation request' : '' ?>" required></label>
                <label>Message<textarea name="message" required></textarea></label>
                <button type="submit">Send message</button>
            </form>
        </div>
    <?php elseif ($sectionKey === 'contact' && in_array($currentPage, ['overview', 'office-locations'], true)): ?>
        <div class="contact-info-grid">
            <div class="card"><strong>Office</strong><span class="muted"><?= e(setting('office_address', '')) ?></span></div>
            <div class="card"><strong>Phone</strong><span class="muted"><?= e(setting('support_phone', '')) ?></span></div>
            <div class="card"><strong>Email</strong><span class="muted"><?= e(setting('support_email', '')) ?></span></div>
        </div>
        <p><a class="button" href="<?= e(url('contact/general-enquiry.php')) ?>"><?= icon('contact') ?>Send enquiry</a></p>
    <?php else: ?>
        <h2><?= e($pageTitle) ?></h2>
        <p>This page provides a focused overview for <?= e($pageTitle) ?> within the group website. Use the section navigation to move through related pages without returning to the homepage.</p>
        <?php if ($currentPage === 'overview'): ?>
            <?php $renderIntroGrid($items, $currentPage); ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
