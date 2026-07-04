<?php
declare(strict_types=1);

function public_section_items(string $section): array
{
    $sections = [
        'about' => [
            'label' => 'About',
            'items' => [
                'overview' => ['label' => 'About the Group', 'url' => 'about/index.php', 'description' => 'A coordinated professional-services group spanning audit, advisory, consulting, training, investment holding, and education services.'],
                'group-overview' => ['label' => 'Group Overview', 'url' => 'about/group-overview.php', 'description' => 'How the group is organised and how the entities work together.'],
                'mbista-associates' => ['label' => 'M. Bista and Associates', 'url' => 'about/mbista-associates.php', 'description' => 'An associated chartered-accountancy practice established in January 2024.'],
                'altiora-global-holding' => ['label' => 'Altiora Global Holding', 'url' => 'about/altiora-global-holding.php', 'description' => 'The investment and holding company supporting group coordination.'],
                'group-companies' => ['label' => 'Group Companies', 'url' => 'about/group-companies.php', 'description' => 'The consulting, training, and education companies in the group.'],
                'mission-vision' => ['label' => 'Mission and Vision', 'url' => 'about/mission-vision.php', 'description' => 'The group direction, service purpose, and long-term professional focus.'],
                'corporate-values' => ['label' => 'Corporate Values', 'url' => 'about/corporate-values.php', 'description' => 'Integrity, discipline, confidentiality, collaboration, and professional responsibility.'],
            ],
        ],
        'team' => [
            'label' => 'Our Team',
            'items' => [
                'overview' => ['label' => 'Team Overview', 'url' => 'team/index.php', 'description' => 'Public profiles of approved professionals across the group.'],
                'leadership' => ['label' => 'Leadership', 'url' => 'team/leadership.php', 'description' => 'Approved leadership profiles.'],
                'management' => ['label' => 'Management', 'url' => 'team/management.php', 'description' => 'Approved management profiles.'],
                'professional-team' => ['label' => 'Professional Team', 'url' => 'team/professional-team.php', 'description' => 'Approved professional-team profiles.'],
                'staff-directory' => ['label' => 'Staff Directory', 'url' => 'team/staff-directory.php', 'description' => 'Public staff directory filtered by visibility controls.'],
            ],
        ],
        'services' => [
            'label' => 'Our Services',
            'items' => [
                'overview' => ['label' => 'Services Overview', 'url' => 'services/index.php', 'description' => 'Core professional services delivered by the relevant group company.'],
                'audit-assurance' => ['label' => 'Audit and Assurance', 'url' => 'services/audit-assurance.php', 'description' => 'Statutory audit, internal audit, tax audit, and assurance support.'],
                'taxation' => ['label' => 'Taxation', 'url' => 'services/taxation.php', 'description' => 'Taxation services and compliance support.'],
                'accounting-advisory' => ['label' => 'Accounting Advisory', 'url' => 'services/accounting-advisory.php', 'description' => 'Accounting advisory, reporting, and business-specific accounting support.'],
                'internal-audit' => ['label' => 'Internal Audit', 'url' => 'services/internal-audit.php', 'description' => 'Internal audit assistance, risk review, and internal-control support.'],
                'business-consulting' => ['label' => 'Business Consulting', 'url' => 'services/business-consulting.php', 'description' => 'Business consulting, management consulting, and process improvement.'],
                'accounting-outsourcing' => ['label' => 'Accounting Outsourcing', 'url' => 'services/accounting-outsourcing.php', 'description' => 'Outsourced accounting and corporate support services.'],
                'training-advisory' => ['label' => 'Training and Advisory', 'url' => 'services/training-advisory.php', 'description' => 'Professional training and advisory services.'],
                'education-consulting' => ['label' => 'Education Consulting', 'url' => 'services/education-consulting.php', 'description' => 'Education consultation, visa guidance, language preparation, and documentation support.'],
            ],
        ],
        'insights' => [
            'label' => 'Insights',
            'items' => [
                'overview' => ['label' => 'Insights Overview', 'url' => 'insights/index.php', 'description' => 'Guidance and updates for clients and stakeholders.'],
                'articles' => ['label' => 'Articles', 'url' => 'insights/articles.php', 'description' => 'Professional articles and practical notes.'],
                'tax-updates' => ['label' => 'Tax Updates', 'url' => 'insights/tax-updates.php', 'description' => 'Tax notices, deadlines, and client guidance.'],
                'audit-insights' => ['label' => 'Audit Insights', 'url' => 'insights/audit-insights.php', 'description' => 'Audit and assurance guidance.'],
                'accounting-updates' => ['label' => 'Accounting Updates', 'url' => 'insights/accounting-updates.php', 'description' => 'Accounting and reporting updates.'],
                'business-advisory' => ['label' => 'Business Advisory', 'url' => 'insights/business-advisory.php', 'description' => 'Business advisory and operational guidance.'],
                'publications' => ['label' => 'Publications', 'url' => 'insights/publications.php', 'description' => 'Publications and reference materials.'],
                'news-events' => ['label' => 'News and Events', 'url' => 'insights/news-events.php', 'description' => 'Group news and events.'],
                'downloads' => ['label' => 'Downloads', 'url' => 'insights/downloads.php', 'description' => 'Approved downloads and resources.'],
            ],
        ],
        'contact' => [
            'label' => 'Contact',
            'items' => [
                'overview' => ['label' => 'Contact Overview', 'url' => 'contact/index.php', 'description' => 'Contact information and enquiry options.'],
                'office-locations' => ['label' => 'Office Locations', 'url' => 'contact/office-locations.php', 'description' => 'Verified office information from settings.'],
                'general-enquiry' => ['label' => 'General Enquiry', 'url' => 'contact/general-enquiry.php', 'description' => 'Submit a general enquiry to the group.'],
                'request-consultation' => ['label' => 'Request a Consultation', 'url' => 'contact/request-consultation.php', 'description' => 'Start a professional conversation about services.'],
                'careers' => ['label' => 'Careers', 'url' => 'contact/careers.php', 'description' => 'Career enquiries and future opportunities.'],
            ],
        ],
    ];

    return $sections[$section] ?? [];
}

function public_section_page(string $section, string $page): array
{
    $sectionData = public_section_items($section);
    $items = $sectionData['items'] ?? [];
    $item = $items[$page] ?? reset($items) ?: [];

    return [
        'section_label' => $sectionData['label'] ?? '',
        'title' => $item['label'] ?? '',
        'description' => $item['description'] ?? '',
        'items' => $items,
    ];
}
