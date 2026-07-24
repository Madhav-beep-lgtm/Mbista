<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';

require_admin();
require_company_context();
accounting_module_repair_database();

$company = current_company();
$companyId = (int) ($company['id'] ?? 0);
$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

// The firm's standard bilingual service-scope catalogue (Annex-1 of the
// standard agreement). New agreements start from this and are customised
// per client; rows can be edited, removed, or added freely.
function sa_default_services(): array
{
    return [
        ['title_np' => 'बुक किपिङ', 'title_en' => 'Bookkeeping',
         'tasks_np' => 'प्रथम पक्षले उपलब्ध गराएका प्रमाणका आधारमा नगद, बैंक, बिक्री, खरिद, खर्च, प्राप्ति, भुक्तानी र आवश्यक जर्नल प्रविष्टि गर्ने; लेजर अद्यावधिक गर्ने; बैंक मिलान गर्ने; प्राप्य तथा भुक्तानीयोग्य मौज्दातको मासिक सूची तयार गर्ने; VAT प्रयोजनका खरिद तथा बिक्री अभिलेख मिलान गर्ने; र उपलब्ध तथ्यका आधारमा मासिक ट्रायल ब्यालेन्स तथा व्यवस्थापन प्रयोजनको नाफा–नोक्सान र वासलात तयार गर्ने।',
         'tasks_en' => 'Record cash, bank, sales, purchase, expense, receipt, payment and required journal entries from evidence provided by the First Party; keep ledgers up to date; reconcile banks; prepare monthly receivable and payable lists; reconcile purchase and sales records for VAT purposes; and prepare a monthly trial balance with management-purpose profit or loss and balance sheet from available data.',
         'deliverable_np' => 'मासिक अद्यावधिक लेखा, बैंक मिलान, प्राप्य/भुक्तानीयोग्य सूची, ट्रायल ब्यालेन्स तथा आधारभूत वित्तीय विवरण।',
         'deliverable_en' => 'Monthly updated books, bank reconciliation, receivable/payable lists, trial balance and basic financial statements.'],
        ['title_np' => 'VAT विवरण दाखिला', 'title_en' => 'VAT Return Filing',
         'tasks_np' => 'लागू कर अवधिको बिक्री तथा खरिद बीजक संकलन र मिलान गर्ने; करयोग्य, शून्यदर तथा छुट कारोबार वर्गीकरण गर्ने; खरिद कर कट्टीको उपलब्ध आधार जाँच गर्ने; VAT विवरणको मस्यौदा तयार गर्ने; प्रथम पक्षको स्वीकृति र कर भुक्तानीपछि निर्धारित समयभित्र विद्युतीय दाखिला गर्ने; तथा दाखिला प्रमाण सुरक्षित गर्ने।',
         'tasks_en' => 'Collect and reconcile sales and purchase invoices for the applicable tax period; classify taxable, zero-rated and exempt transactions; verify the available basis for input tax credit; draft the VAT return; file it electronically within the deadline after the First Party approves and pays the tax; and preserve the filing evidence.',
         'deliverable_np' => 'सम्बन्धित कर अवधिको VAT विवरण र दाखिला प्रमाण।',
         'deliverable_en' => 'VAT return and filing evidence for the relevant tax period.'],
        ['title_np' => 'श्रम प्रवर्द्धन शुल्क', 'title_en' => 'Labour Promotion Fee',
         'tasks_np' => 'प्रचलित कानूनअनुसार शुल्क लाग्ने कारोबार पहिचान गर्न प्रथम पक्षले दिएको विवरण समीक्षा गर्ने; लागू आधार र दरअनुसार शुल्क गणना गर्ने; बिक्री अभिलेखसँग मिलान गर्ने; विवरणको मस्यौदा तयार गर्ने; प्रथम पक्षको स्वीकृति तथा शुल्क भुक्तानीपछि निर्धारित माध्यमबाट दाखिला गर्ने; र दाखिला प्रमाण सुरक्षित गर्ने।',
         'tasks_en' => 'Review information provided by the First Party to identify transactions attracting the fee under prevailing law; compute the fee on the applicable base and rate; reconcile with sales records; draft the statement; file it through the prescribed channel after the First Party approves and pays the fee; and preserve the filing evidence.',
         'deliverable_np' => 'सम्बन्धित अवधिको गणना पत्र, विवरण तथा दाखिला प्रमाण।',
         'deliverable_en' => 'Computation sheet, statement and filing evidence for the relevant period.'],
        ['title_np' => 'मासिक नाफा वा नोक्सान प्रतिवेदन', 'title_en' => 'Monthly Profit or Loss Report',
         'tasks_np' => 'मासिक लेखा बन्द भएपछि उपलब्ध र समायोजित तथ्याङ्कका आधारमा आम्दानी, बिक्री लागत, सञ्चालन खर्च, अन्य आय/व्यय र मासिक नाफा वा नोक्सान प्रस्तुत गर्ने; आवश्यक तुलनात्मक वा बजेट भिन्नता उपलब्ध भए समावेश गर्ने।',
         'tasks_en' => 'After the monthly close, present income, cost of sales, operating expenses, other income/expense and the monthly profit or loss from available and adjusted data; include comparatives or budget variances where available.',
         'deliverable_np' => 'मासिक Profit or Loss प्रतिवेदन र प्रमुख टिप्पणी।',
         'deliverable_en' => 'Monthly Profit or Loss report with key notes.'],
        ['title_np' => 'वैधानिक लेखापरीक्षणमा सहयोग', 'title_en' => 'Statutory Audit Support',
         'tasks_np' => 'लेखापरीक्षकले मागेका उपलब्ध लेजर, अनुसूची, मिलान, प्रमाण र व्यवस्थापन विवरण संकलन तथा व्यवस्थित गर्ने; लेखापरीक्षण प्रश्नमा तथ्यगत जवाफ तयार गर्न व्यवस्थापनलाई सहयोग गर्ने; र आवश्यक समायोजन व्यवस्थापनको स्वीकृतिपछि लेखा प्रणालीमा प्रविष्टि गर्ने।',
         'tasks_en' => 'Collect and organise ledgers, schedules, reconciliations, evidence and management information requested by the auditor; support management in preparing factual responses to audit queries; and post approved adjustments into the accounting system.',
         'deliverable_np' => 'Audit schedules, reconciliations र प्रश्नोत्तर सहयोग।',
         'deliverable_en' => 'Audit schedules, reconciliations and query support.'],
        ['title_np' => 'KPI विश्लेषण', 'title_en' => 'KPI Analysis',
         'tasks_np' => 'दुवै पक्षले सहमति गरेका वित्तीय तथा सञ्चालन सूचक निर्धारण गर्ने; मासिक वा उपलब्ध अवधिको परिणाम गणना गर्ने; अघिल्लो अवधि वा लक्ष्यसँग तुलना गर्ने; र मुख्य विचलन तथा सुधार आवश्यक क्षेत्र पहिचान गर्ने।',
         'tasks_en' => 'Define financial and operational indicators agreed by both parties; compute results monthly or per available period; compare with prior periods or targets; and identify key deviations and improvement areas.',
         'deliverable_np' => 'मासिक KPI सारांश तथा विचलन टिप्पणी।',
         'deliverable_en' => 'Monthly KPI summary and deviation notes.'],
        ['title_np' => 'नगद प्रवाह व्यवस्थापन', 'title_en' => 'Cash Flow Management',
         'tasks_np' => 'उपलब्ध प्राप्ति, भुक्तानी, बैंक मौज्दात र प्रतिबद्धताका आधारमा छोटो अवधिको cash-flow forecast तयार गर्ने; प्रमुख नगद कमी वा बढी हुने समय पहिचान गर्ने; र भुक्तानी प्राथमिकता तथा collection follow-up मा व्यवस्थापनलाई सुझाव दिने।',
         'tasks_en' => 'Prepare short-term cash-flow forecasts from available receipts, payments, bank balances and commitments; identify periods of major cash shortfall or surplus; and advise management on payment priorities and collection follow-up.',
         'deliverable_np' => 'आवधिक cash-flow forecast र व्यवस्थापन सुझाव।',
         'deliverable_en' => 'Periodic cash-flow forecast and management advice.'],
        ['title_np' => 'तलब विवरण तयारी', 'title_en' => 'Salary Sheet Preparation',
         'tasks_np' => 'प्रथम पक्षले स्वीकृत गरेको हाजिरी, तलब दर, सुविधा, ओभरटाइम, बिदा, कट्टी, अग्रिम, कर तथा अन्य payroll input का आधारमा मासिक salary sheet तयार गर्ने। कर्मचारी नियुक्ति, हाजिरी स्वीकृति र भुक्तानी अधिकार प्रथम पक्षमै रहनेछ।',
         'tasks_en' => 'Prepare the monthly salary sheet from attendance, salary rates, benefits, overtime, leave, deductions, advances, taxes and other payroll inputs approved by the First Party. Employee appointment, attendance approval and payment authority remain with the First Party.',
         'deliverable_np' => 'मासिक salary sheet र कट्टी सारांश।',
         'deliverable_en' => 'Monthly salary sheet and deduction summary.'],
        ['title_np' => 'e-TDS विवरण दाखिला', 'title_en' => 'e-TDS Return Filing',
         'tasks_np' => 'तलब, सेवा, भाडा वा अन्य लागू भुक्तानीमा प्रथम पक्षले दिएको विवरणका आधारमा TDS गणना तथा मिलान गर्ने; e-TDS विवरण तयार गर्ने; कर रकम समयमै दाखिला भएपछि र प्रथम पक्षको स्वीकृतिमा विवरण दाखिला गर्ने; र प्रमाण सुरक्षित गर्ने।',
         'tasks_en' => 'Compute and reconcile TDS on salary, service, rent and other applicable payments from information provided by the First Party; prepare the e-TDS return; file it upon timely tax deposit and the First Party\'s approval; and preserve the evidence.',
         'deliverable_np' => 'e-TDS return, दाखिला प्रमाण र उपलब्ध कर कट्टी विवरण।',
         'deliverable_en' => 'e-TDS return, filing evidence and available withholding details.'],
        ['title_np' => 'कम्पनी कानून परामर्श', 'title_en' => 'Company Law Advisory',
         'tasks_np' => 'सामान्य नियमित corporate compliance, अभिलेख, साधारण सभा, बोर्ड निर्णय, वार्षिक विवरण तथा कम्पनी रजिष्ट्रारसम्बन्धी प्रक्रियामा व्यवस्थापनलाई परामर्श र दस्तावेज तयारी सहयोग गर्ने। जटिल कानूनी विवाद वा अधिवक्ताको औपचारिक राय आवश्यक विषय छुट्टै हुनेछ।',
         'tasks_en' => 'Advise management and support document preparation on routine corporate compliance, records, general meetings, board decisions, annual returns and Company Registrar procedures. Complex legal disputes or matters requiring formal advocate opinion are separate.',
         'deliverable_np' => 'नियमित compliance guidance तथा आवश्यक मस्यौदा सहयोग।',
         'deliverable_en' => 'Routine compliance guidance and drafting support as required.'],
    ];
}

function sa_default_staffing_np(): string
{
    return "(१) दोस्रो पक्षले दैनिक बुक किपिङ कार्यका लागि एक जना समर्पित कर्मचारी खटाउनेछ। कर्मचारीको कार्यसमय, कार्यस्थल, बिदा र दैनिक समन्वय दुवै पक्षले सहमत गरेको कार्यव्यवस्था बमोजिम हुनेछ।\n(२) समीक्षा, मिलान तथा सुपरिवेक्षणका लागि अर्को एक जना कर्मचारी महिनामा दुई पटक उपलब्ध हुनेछ।\n(३) एक जना चार्टर्ड एकाउन्टेन्टले महिनामा कम्तीमा एक पटक समीक्षा तथा छलफल गर्नेछ। भौतिक रूपमा उपस्थित हुन नसकेको अवस्थामा सो समीक्षा र छलफल अनलाइन वा अन्य सहमत भर्चुअल माध्यमबाट गरिनेछ।";
}

function sa_default_staffing_en(): string
{
    return "(1) The Second Party shall assign one dedicated staff member for daily bookkeeping. Working hours, workplace, leave and daily coordination shall follow the working arrangement agreed by both parties.\n(2) One additional staff member shall be available twice a month for review, reconciliation and supervision.\n(3) A Chartered Accountant shall review and discuss at least once a month; where physical presence is not possible, the review shall be held online or by another agreed virtual medium.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_agreement') {
        $agreementId = (int) ($_POST['agreement_id'] ?? 0);
        $firstPartyEn = trim((string) ($_POST['first_party_name_en'] ?? ''));
        $secondPartyEn = trim((string) ($_POST['second_party_name_en'] ?? ''));
        if ($firstPartyEn === '' || $secondPartyEn === '') {
            flash('error', 'Both party names (English) are required.');
            redirect('admin/service-agreements.php' . ($agreementId > 0 ? '?edit=' . $agreementId : ''));
        }
        // Work-portal contract this agreement documents (the wiring record:
        // client link, task linkage, billing status). One agreement per contract.
        $contractId = (int) ($_POST['contract_id'] ?? 0);
        $contract = null;
        if ($contractId > 0) {
            $contractStmt = db()->prepare('SELECT * FROM service_contracts WHERE id = :id AND company_id = :cid');
            $contractStmt->execute(['id' => $contractId, 'cid' => $companyId]);
            $contract = $contractStmt->fetch() ?: null;
            if (!$contract) {
                flash('error', 'Linked contract not found for this company.');
                redirect('admin/workspace.php?view=contracts');
            }
            $linkedElsewhere = db()->prepare('SELECT id FROM service_agreements WHERE contract_id = :contract_id AND id != :id');
            $linkedElsewhere->execute(['contract_id' => $contractId, 'id' => $agreementId]);
            $otherId = (int) $linkedElsewhere->fetchColumn();
            if ($otherId > 0) {
                flash('error', 'That contract already has an agreement — edit it instead.');
                redirect('admin/service-agreements.php?edit=' . $otherId);
            }
        }
        // Annex-1 service rows.
        $services = [];
        $titlesNp = (array) ($_POST['svc_title_np'] ?? []);
        foreach ($titlesNp as $index => $titleNp) {
            $row = [
                'title_np' => trim((string) $titleNp),
                'title_en' => trim((string) ($_POST['svc_title_en'][$index] ?? '')),
                'tasks_np' => trim((string) ($_POST['svc_tasks_np'][$index] ?? '')),
                'tasks_en' => trim((string) ($_POST['svc_tasks_en'][$index] ?? '')),
                'deliverable_np' => trim((string) ($_POST['svc_deliv_np'][$index] ?? '')),
                'deliverable_en' => trim((string) ($_POST['svc_deliv_en'][$index] ?? '')),
            ];
            if ($row['title_np'] !== '' || $row['title_en'] !== '') {
                $services[] = $row;
            }
        }
        $witnesses = [
            'w1' => ['name' => trim((string) ($_POST['w1_name'] ?? '')), 'address' => trim((string) ($_POST['w1_address'] ?? ''))],
            'w2' => ['name' => trim((string) ($_POST['w2_name'] ?? '')), 'address' => trim((string) ($_POST['w2_address'] ?? ''))],
        ];
        $postedClientId = (int) ($_POST['client_id'] ?? 0);
        if ($postedClientId <= 0 && $contract !== null) {
            $postedClientId = (int) $contract['client_id'];
        }
        $params = [
            'company_id' => $companyId,
            'client_id' => $postedClientId > 0 ? $postedClientId : null,
            'contract_id' => $contractId > 0 ? $contractId : null,
            'agreement_no' => trim((string) ($_POST['agreement_no'] ?? '')) ?: ($contract !== null ? (string) $contract['contract_no'] : 'SA-' . date('Ymd-His')),
            'purpose_en' => trim((string) ($_POST['purpose_en'] ?? '')) ?: 'Accounting and Advisory Services',
            'purpose_np' => trim((string) ($_POST['purpose_np'] ?? '')) ?: 'लेखा तथा परामर्श सेवा',
            'first_party_name_en' => $firstPartyEn,
            'first_party_name_np' => trim((string) ($_POST['first_party_name_np'] ?? '')) ?: null,
            'first_party_address' => trim((string) ($_POST['first_party_address'] ?? '')) ?: null,
            'first_party_reg_no' => trim((string) ($_POST['first_party_reg_no'] ?? '')) ?: null,
            'first_party_signatory' => trim((string) ($_POST['first_party_signatory'] ?? '')) ?: null,
            'first_party_position' => trim((string) ($_POST['first_party_position'] ?? '')) ?: null,
            'second_party_name_en' => $secondPartyEn,
            'second_party_name_np' => trim((string) ($_POST['second_party_name_np'] ?? '')) ?: null,
            'second_party_address' => trim((string) ($_POST['second_party_address'] ?? '')) ?: null,
            'second_party_reg_no' => trim((string) ($_POST['second_party_reg_no'] ?? '')) ?: null,
            'second_party_signatory' => trim((string) ($_POST['second_party_signatory'] ?? '')) ?: null,
            'second_party_position' => trim((string) ($_POST['second_party_position'] ?? '')) ?: null,
            'agreement_date_bs' => trim((string) ($_POST['agreement_date_bs'] ?? '')) ?: null,
            'effective_date' => trim((string) ($_POST['effective_date'] ?? '')) ?: null,
            'effective_date_bs' => trim((string) ($_POST['effective_date_bs'] ?? '')) ?: null,
            'duration_months' => max(1, (int) ($_POST['duration_months'] ?? 24)),
            'trial_months' => max(0, (int) ($_POST['trial_months'] ?? 1)),
            'fee_trial' => max(0.0, round((float) ($_POST['fee_trial'] ?? 0), 2)),
            'fee_monthly' => max(0.0, round((float) ($_POST['fee_monthly'] ?? 0), 2)),
            'payment_days' => max(1, (int) ($_POST['payment_days'] ?? 7)),
            'termination_notice_days' => max(1, (int) ($_POST['termination_notice_days'] ?? 3)),
            'cure_days' => max(1, (int) ($_POST['cure_days'] ?? 7)),
            'jurisdiction_en' => trim((string) ($_POST['jurisdiction_en'] ?? '')) ?: 'the competent court of Kathmandu District',
            'jurisdiction_np' => trim((string) ($_POST['jurisdiction_np'] ?? '')) ?: 'काठमाडौँ जिल्लाको सम्बन्धित अदालत',
            'staffing_np' => trim((string) ($_POST['staffing_np'] ?? '')) ?: null,
            'staffing_en' => trim((string) ($_POST['staffing_en'] ?? '')) ?: null,
            'services_json' => json_encode($services, JSON_UNESCAPED_UNICODE),
            'witnesses_json' => json_encode($witnesses, JSON_UNESCAPED_UNICODE),
            'custom_clauses_np' => trim((string) ($_POST['custom_clauses_np'] ?? '')) ?: null,
            'custom_clauses_en' => trim((string) ($_POST['custom_clauses_en'] ?? '')) ?: null,
            'status' => (string) ($_POST['status'] ?? 'draft') === 'final' ? 'final' : 'draft',
        ];
        if ($agreementId > 0) {
            $own = db()->prepare('SELECT id FROM service_agreements WHERE id = :id AND company_id = :cid');
            $own->execute(['id' => $agreementId, 'cid' => $companyId]);
            if (!$own->fetchColumn()) {
                flash('error', 'Agreement not found for this company.');
                redirect('admin/service-agreements.php');
            }
            $params['id'] = $agreementId;
            $params['updated_by'] = $userId;
            db()->prepare('UPDATE service_agreements SET client_id = :client_id, contract_id = :contract_id, agreement_no = :agreement_no,
                    purpose_en = :purpose_en, purpose_np = :purpose_np,
                    first_party_name_en = :first_party_name_en, first_party_name_np = :first_party_name_np,
                    first_party_address = :first_party_address, first_party_reg_no = :first_party_reg_no,
                    first_party_signatory = :first_party_signatory, first_party_position = :first_party_position,
                    second_party_name_en = :second_party_name_en, second_party_name_np = :second_party_name_np,
                    second_party_address = :second_party_address, second_party_reg_no = :second_party_reg_no,
                    second_party_signatory = :second_party_signatory, second_party_position = :second_party_position,
                    agreement_date_bs = :agreement_date_bs, effective_date = :effective_date, effective_date_bs = :effective_date_bs,
                    duration_months = :duration_months, trial_months = :trial_months,
                    fee_trial = :fee_trial, fee_monthly = :fee_monthly, payment_days = :payment_days,
                    termination_notice_days = :termination_notice_days, cure_days = :cure_days,
                    jurisdiction_en = :jurisdiction_en, jurisdiction_np = :jurisdiction_np,
                    staffing_np = :staffing_np, staffing_en = :staffing_en,
                    services_json = :services_json, witnesses_json = :witnesses_json,
                    custom_clauses_np = :custom_clauses_np, custom_clauses_en = :custom_clauses_en,
                    status = :status, updated_by = :updated_by
                WHERE id = :id AND company_id = :company_id')->execute($params);
            log_activity('service_agreement', $agreementId, 'updated', 'Service agreement ' . $params['agreement_no'] . ' updated.', $userId);
        } else {
            $params['created_by'] = $userId;
            db()->prepare('INSERT INTO service_agreements (company_id, client_id, contract_id, agreement_no, purpose_en, purpose_np,
                    first_party_name_en, first_party_name_np, first_party_address, first_party_reg_no, first_party_signatory, first_party_position,
                    second_party_name_en, second_party_name_np, second_party_address, second_party_reg_no, second_party_signatory, second_party_position,
                    agreement_date_bs, effective_date, effective_date_bs, duration_months, trial_months,
                    fee_trial, fee_monthly, payment_days, termination_notice_days, cure_days,
                    jurisdiction_en, jurisdiction_np, staffing_np, staffing_en,
                    services_json, witnesses_json, custom_clauses_np, custom_clauses_en, status, created_by)
                VALUES (:company_id, :client_id, :contract_id, :agreement_no, :purpose_en, :purpose_np,
                    :first_party_name_en, :first_party_name_np, :first_party_address, :first_party_reg_no, :first_party_signatory, :first_party_position,
                    :second_party_name_en, :second_party_name_np, :second_party_address, :second_party_reg_no, :second_party_signatory, :second_party_position,
                    :agreement_date_bs, :effective_date, :effective_date_bs, :duration_months, :trial_months,
                    :fee_trial, :fee_monthly, :payment_days, :termination_notice_days, :cure_days,
                    :jurisdiction_en, :jurisdiction_np, :staffing_np, :staffing_en,
                    :services_json, :witnesses_json, :custom_clauses_np, :custom_clauses_en, :status, :created_by)')->execute($params);
            $agreementId = (int) db()->lastInsertId();
            log_activity('service_agreement', $agreementId, 'created', 'Service agreement ' . $params['agreement_no'] . ' drafted.', $userId);
        }
        // Merge-back: the agreement IS the contract's document, so keep the
        // contract wiring (client, dates, value, status) aligned with it.
        if ($contract !== null) {
            $syncStart = $params['effective_date'] ?: ((string) ($contract['start_date'] ?? '') ?: null);
            $syncEnd = $syncStart !== null
                ? date('Y-m-d', strtotime($syncStart . ' +' . (int) $params['duration_months'] . ' months -1 day'))
                : ((string) ($contract['end_date'] ?? '') ?: null);
            $trialMonths = (int) $params['trial_months'];
            $regularMonths = max(0, (int) $params['duration_months'] - $trialMonths);
            $syncValue = round((float) $params['fee_trial'] * $trialMonths + (float) $params['fee_monthly'] * $regularMonths, 2);
            $syncStatus = (string) $contract['status'];
            if ($params['status'] === 'final' && $syncStatus === 'draft') {
                $syncStatus = 'active';
            }
            db()->prepare("UPDATE service_contracts SET start_date = :start_date, end_date = :end_date,
                    total_value = :total_value, billing_cycle = COALESCE(NULLIF(billing_cycle, ''), 'monthly'), status = :status
                WHERE id = :id AND company_id = :cid")->execute([
                'start_date' => $syncStart,
                'end_date' => $syncEnd,
                'total_value' => $syncValue,
                'status' => $syncStatus,
                'id' => $contractId,
                'cid' => $companyId,
            ]);
            log_activity('service_contract', $contractId, 'agreement_synced', 'Contract period, value and status synced from agreement ' . $params['agreement_no'] . '.', $userId);
            flash('success', 'Agreement saved and contract ' . $contract['contract_no'] . ' updated (period, value' . ($syncStatus !== (string) $contract['status'] ? ', activated' : '') . '). Use Print for the bilingual document.');
        } else {
            flash('success', 'Agreement saved. Use Print to produce the Nepali, English, or bilingual document.');
        }
        redirect('admin/service-agreements.php?edit=' . $agreementId);
    }

    if ($action === 'delete_agreement') {
        $agreementId = (int) ($_POST['agreement_id'] ?? 0);
        $own = db()->prepare("SELECT agreement_no FROM service_agreements WHERE id = :id AND company_id = :cid AND status = 'draft'");
        $own->execute(['id' => $agreementId, 'cid' => $companyId]);
        $no = $own->fetchColumn();
        if ($no === false) {
            flash('error', 'Only draft agreements of this company can be deleted.');
        } else {
            db()->prepare('DELETE FROM service_agreements WHERE id = :id AND company_id = :cid')->execute(['id' => $agreementId, 'cid' => $companyId]);
            log_activity('service_agreement', $agreementId, 'deleted', 'Draft agreement ' . $no . ' deleted.', $userId);
            flash('success', 'Draft agreement deleted.');
        }
        redirect('admin/service-agreements.php');
    }
}

$clientsStmt = db()->prepare('SELECT id, organization_name, client_code, address, registration_no, authorized_signatory_name, authorized_person_position FROM client_profiles WHERE is_active = 1 AND company_id = :cid ORDER BY organization_name');
$clientsStmt->execute(['cid' => $companyId]);
$clients = $clientsStmt->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $editStmt = db()->prepare('SELECT * FROM service_agreements WHERE id = :id AND company_id = :cid');
    $editStmt->execute(['id' => $editId, 'cid' => $companyId]);
    $edit = $editStmt->fetch() ?: null;
}
// Opened from the Work Portal contracts view: ?contract_id=N edits (or starts)
// THE agreement of that contract, inheriting its client, number and terms.
$linkedContract = null;
$linkedContractId = $edit !== null ? (int) ($edit['contract_id'] ?? 0) : (int) ($_GET['contract_id'] ?? 0);
if ($edit === null && $linkedContractId > 0) {
    $alreadyStmt = db()->prepare('SELECT id FROM service_agreements WHERE contract_id = :contract_id AND company_id = :cid');
    $alreadyStmt->execute(['contract_id' => $linkedContractId, 'cid' => $companyId]);
    $alreadyId = (int) $alreadyStmt->fetchColumn();
    if ($alreadyId > 0) {
        redirect('admin/service-agreements.php?edit=' . $alreadyId);
    }
}
if ($linkedContractId > 0) {
    $contractStmt = db()->prepare('SELECT * FROM service_contracts WHERE id = :id AND company_id = :cid');
    $contractStmt->execute(['id' => $linkedContractId, 'cid' => $companyId]);
    $linkedContract = $contractStmt->fetch() ?: null;
    if ($linkedContract === null && $edit === null) {
        flash('error', 'Contract not found for this company.');
        redirect('admin/workspace.php?view=contracts');
    }
}
// Contract-derived defaults for a fresh agreement form.
$contractDefaults = [];
if ($edit === null && $linkedContract !== null) {
    $months = 24;
    if (!empty($linkedContract['start_date']) && !empty($linkedContract['end_date'])) {
        $spanDays = (strtotime((string) $linkedContract['end_date']) - strtotime((string) $linkedContract['start_date'])) / 86400;
        $months = max(1, (int) round($spanDays / 30.44));
    }
    $contractDefaults = [
        'agreement_no' => (string) $linkedContract['contract_no'],
        'purpose_en' => (string) $linkedContract['title'],
        'effective_date' => (string) ($linkedContract['start_date'] ?? ''),
        'duration_months' => $months,
    ];
    $cycle = strtolower(trim((string) ($linkedContract['billing_cycle'] ?? '')));
    if ((float) $linkedContract['total_value'] > 0 && ($cycle === '' || $cycle === 'monthly')) {
        $contractDefaults['fee_monthly'] = round((float) $linkedContract['total_value'] / $months, 2);
    }
}
// Prefill the first party from a chosen client (?client=ID on a fresh form),
// or from the linked contract's client.
$prefillClient = null;
$prefillClientId = (int) ($_GET['client'] ?? 0);
if ($prefillClientId <= 0 && $edit === null && $linkedContract !== null) {
    $prefillClientId = (int) ($linkedContract['client_id'] ?? 0);
}
foreach ($clients as $clientRow) {
    if ((int) $clientRow['id'] === $prefillClientId) {
        $prefillClient = $clientRow;
    }
}
$services = $edit !== null ? (json_decode((string) ($edit['services_json'] ?? ''), true) ?: []) : sa_default_services();
$witnesses = $edit !== null ? (json_decode((string) ($edit['witnesses_json'] ?? ''), true) ?: []) : [];

$listStmt = db()->prepare('SELECT sa.*, cp.organization_name, sc.contract_no AS linked_contract_no FROM service_agreements sa
    LEFT JOIN client_profiles cp ON cp.id = sa.client_id
    LEFT JOIN service_contracts sc ON sc.id = sa.contract_id
    WHERE sa.company_id = :cid ORDER BY sa.id DESC LIMIT 100');
$listStmt->execute(['cid' => $companyId]);
$agreements = $listStmt->fetchAll();

$pageTitle = 'Contract Agreements';
$pageSubtitle = 'Each Work Portal contract carries one customised bilingual (नेपाली + English) agreement — cover page, table of contents, chapters, annexures and signatures, print-ready. Saving syncs the contract\'s period, value and status.';
$pageHero = ['icon' => 'contracts'];
$bodyClass = 'admin-layout accounting-module-page';
include __DIR__ . '/../../app/views/partials/admin_header.php';
?>
<section class="mbw-card">
    <div class="mbw-card-head"><h2><?= $edit ? 'Edit Agreement — ' . e((string) $edit['agreement_no']) : 'Draft New Agreement' ?><?= $linkedContract !== null ? ' <span class="mbw-pill tone-blue">Contract ' . e((string) $linkedContract['contract_no']) . '</span>' : '' ?></h2>
        <div class="mbw-card-tools">
            <?php if (!$edit && $clients !== []): ?>
                <form method="get" style="display:flex;gap:6px;align-items:center">
                    <select name="client" onchange="this.form.submit()" style="min-height:34px">
                        <option value="">Prefill from client…</option>
                        <?php foreach ($clients as $clientRow): ?>
                            <option value="<?= (int) $clientRow['id'] ?>" <?= $prefillClientId === (int) $clientRow['id'] ? 'selected' : '' ?>><?= e($clientRow['organization_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <?php if ($edit): ?>
                <a class="button" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . (int) $edit['id'] . '&lang=np')) ?>">Print नेपाली</a>
                <a class="button" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . (int) $edit['id'] . '&lang=en')) ?>">Print English</a>
                <a class="button" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . (int) $edit['id'] . '&lang=both')) ?>">Print Bilingual</a>
                <a class="mbw-view-all" href="<?= e(url('admin/service-agreements.php')) ?>">New / Close</a>
            <?php endif; ?>
            <a class="mbw-view-all" href="<?= e(url('admin/workspace.php?view=contracts')) ?>">← Contracts</a>
        </div>
    </div>
    <form method="post" class="workspace-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_agreement">
        <input type="hidden" name="agreement_id" value="<?= e((int) ($edit['id'] ?? 0)) ?>">
        <input type="hidden" name="contract_id" value="<?= e($linkedContract !== null ? (int) $linkedContract['id'] : (int) ($edit['contract_id'] ?? 0)) ?>">
        <label>Client (optional link)
            <select name="client_id">
                <option value="0">— not linked —</option>
                <?php foreach ($clients as $clientRow): ?>
                    <option value="<?= (int) $clientRow['id'] ?>" <?= (int) ($edit['client_id'] ?? $prefillClientId) === (int) $clientRow['id'] ? 'selected' : '' ?>><?= e($clientRow['organization_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Agreement no<input type="text" name="agreement_no" maxlength="60" value="<?= e($edit['agreement_no'] ?? ($contractDefaults['agreement_no'] ?? '')) ?>" placeholder="Auto if blank"></label>
        <label>Purpose (English)<input type="text" name="purpose_en" maxlength="190" value="<?= e($edit['purpose_en'] ?? ($contractDefaults['purpose_en'] ?? 'Accounting and Advisory Services')) ?>" placeholder="Bookkeeping / Internal Audit / Consulting…"></label>
        <label>Purpose (नेपाली)<input type="text" name="purpose_np" maxlength="190" value="<?= e($edit['purpose_np'] ?? 'लेखा तथा परामर्श सेवा') ?>"></label>

        <div class="workspace-span-2"><strong style="font-size:13px;color:var(--mbw-heading)">First Party — प्रथम पक्ष (the client)</strong></div>
        <label>Name (English)<input type="text" name="first_party_name_en" required maxlength="190" value="<?= e($edit['first_party_name_en'] ?? ($prefillClient['organization_name'] ?? '')) ?>"></label>
        <label>Name (नेपाली)<input type="text" name="first_party_name_np" maxlength="190" value="<?= e($edit['first_party_name_np'] ?? '') ?>"></label>
        <label>Address<input type="text" name="first_party_address" maxlength="255" value="<?= e($edit['first_party_address'] ?? ($prefillClient['address'] ?? '')) ?>"></label>
        <label>Registration / PAN no<input type="text" name="first_party_reg_no" maxlength="80" value="<?= e($edit['first_party_reg_no'] ?? ($prefillClient['registration_no'] ?? '')) ?>"></label>
        <label>Signatory name<input type="text" name="first_party_signatory" maxlength="160" value="<?= e($edit['first_party_signatory'] ?? ($prefillClient['authorized_signatory_name'] ?? '')) ?>"></label>
        <label>Signatory position<input type="text" name="first_party_position" maxlength="160" value="<?= e($edit['first_party_position'] ?? ($prefillClient['authorized_person_position'] ?? 'सञ्चालक / Director')) ?>"></label>

        <div class="workspace-span-2"><strong style="font-size:13px;color:var(--mbw-heading)">Second Party — दोस्रो पक्ष (the service provider)</strong></div>
        <label>Name (English)<input type="text" name="second_party_name_en" required maxlength="190" value="<?= e($edit['second_party_name_en'] ?? ($company['name'] ?? '')) ?>"></label>
        <label>Name (नेपाली)<input type="text" name="second_party_name_np" maxlength="190" value="<?= e($edit['second_party_name_np'] ?? '') ?>"></label>
        <label>Address<input type="text" name="second_party_address" maxlength="255" value="<?= e($edit['second_party_address'] ?? '') ?>"></label>
        <label>Registration / PAN no<input type="text" name="second_party_reg_no" maxlength="80" value="<?= e($edit['second_party_reg_no'] ?? '') ?>"></label>
        <label>Signatory name<input type="text" name="second_party_signatory" maxlength="160" value="<?= e($edit['second_party_signatory'] ?? ($currentUser['name'] ?? '')) ?>"></label>
        <label>Signatory position<input type="text" name="second_party_position" maxlength="160" value="<?= e($edit['second_party_position'] ?? 'मुख्य परामर्शदाता / Chief Consultant') ?>"></label>

        <div class="workspace-span-2"><strong style="font-size:13px;color:var(--mbw-heading)">Dates, Term &amp; Fees — मिति, अवधि तथा शुल्क</strong></div>
        <label>Agreement date (BS)<input type="text" name="agreement_date_bs" maxlength="30" value="<?= e($edit['agreement_date_bs'] ?? '') ?>" placeholder="२०८३।०४।०४"></label>
        <label>Service start date (BS)<input type="text" name="effective_date_bs" maxlength="30" value="<?= e($edit['effective_date_bs'] ?? '') ?>" placeholder="२०८३।०४।१०"></label>
        <label>Service start date (AD)<input type="date" name="effective_date" value="<?= e($edit['effective_date'] ?? ($contractDefaults['effective_date'] ?? '')) ?>"></label>
        <label>Duration (months)<input type="number" min="1" name="duration_months" value="<?= e((int) ($edit['duration_months'] ?? ($contractDefaults['duration_months'] ?? 24))) ?>"></label>
        <label>Trial period (months, 0 = none)<input type="number" min="0" name="trial_months" value="<?= e((int) ($edit['trial_months'] ?? 1)) ?>"></label>
        <label>Trial-period monthly fee (Rs, excl. VAT)<input type="number" step="0.01" min="0" name="fee_trial" value="<?= e(number_format((float) ($edit['fee_trial'] ?? 15000), 2, '.', '')) ?>"></label>
        <label>Regular monthly fee (Rs, excl. VAT)<input type="number" step="0.01" min="0" name="fee_monthly" value="<?= e(number_format((float) ($edit['fee_monthly'] ?? ($contractDefaults['fee_monthly'] ?? 35000)), 2, '.', '')) ?>"></label>
        <label>Payment within (days of following month)<input type="number" min="1" name="payment_days" value="<?= e((int) ($edit['payment_days'] ?? 7)) ?>"></label>
        <label>General termination notice (days)<input type="number" min="1" name="termination_notice_days" value="<?= e((int) ($edit['termination_notice_days'] ?? 3)) ?>"></label>
        <label>Breach cure period (days)<input type="number" min="1" name="cure_days" value="<?= e((int) ($edit['cure_days'] ?? 7)) ?>"></label>
        <label>Jurisdiction (English)<input type="text" name="jurisdiction_en" maxlength="120" value="<?= e($edit['jurisdiction_en'] ?? 'the competent court of Kathmandu District') ?>"></label>
        <label>Jurisdiction (नेपाली)<input type="text" name="jurisdiction_np" maxlength="120" value="<?= e($edit['jurisdiction_np'] ?? 'काठमाडौँ जिल्लाको सम्बन्धित अदालत') ?>"></label>

        <div class="workspace-span-2"><strong style="font-size:13px;color:var(--mbw-heading)">Manpower Arrangement — जनशक्ति व्यवस्था (Clause ७ / 7)</strong></div>
        <label class="workspace-span-2">नेपाली<textarea name="staffing_np" rows="3"><?= e($edit['staffing_np'] ?? sa_default_staffing_np()) ?></textarea></label>
        <label class="workspace-span-2">English<textarea name="staffing_en" rows="3"><?= e($edit['staffing_en'] ?? sa_default_staffing_en()) ?></textarea></label>

        <div class="workspace-span-2">
            <strong style="font-size:13px;color:var(--mbw-heading)">Annex-1: Service Scope — अनुसूची–१ सेवाको विस्तृत कार्यक्षेत्र</strong>
            <p style="margin:4px 0 8px;color:var(--mbw-muted);font-size:12px">Customise per client: edit, clear (to drop), or add service rows. Both languages print side by side in the bilingual document.</p>
            <div id="sa-services">
                <?php foreach ($services as $service): ?>
                    <details class="feature-disclosure sa-service" style="margin-bottom:8px">
                        <summary><span><strong><?= e(($service['title_np'] ?? '') !== '' ? $service['title_np'] : ($service['title_en'] ?? 'Service')) ?></strong><small><?= e($service['title_en'] ?? '') ?></small></span><span class="feature-disclosure-action">Edit</span></summary>
                        <div class="workspace-form-grid" style="padding:10px 0">
                            <label>सेवा (नेपाली)<input type="text" name="svc_title_np[]" maxlength="190" value="<?= e($service['title_np'] ?? '') ?>"></label>
                            <label>Service (English)<input type="text" name="svc_title_en[]" maxlength="190" value="<?= e($service['title_en'] ?? '') ?>"></label>
                            <label class="workspace-span-2">समावेश कार्य (नेपाली)<textarea name="svc_tasks_np[]" rows="3"><?= e($service['tasks_np'] ?? '') ?></textarea></label>
                            <label class="workspace-span-2">Included work (English)<textarea name="svc_tasks_en[]" rows="3"><?= e($service['tasks_en'] ?? '') ?></textarea></label>
                            <label>मुख्य प्रतिफल (नेपाली)<input type="text" name="svc_deliv_np[]" maxlength="255" value="<?= e($service['deliverable_np'] ?? '') ?>"></label>
                            <label>Key deliverable (English)<input type="text" name="svc_deliv_en[]" maxlength="255" value="<?= e($service['deliverable_en'] ?? '') ?>"></label>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button secondary" onclick="var host=document.getElementById('sa-services');var first=host.querySelector('.sa-service');var copy=first.cloneNode(true);copy.querySelectorAll('input,textarea').forEach(function(f){f.value='';});copy.querySelector('summary strong').textContent='New service';copy.querySelector('summary small').textContent='';copy.setAttribute('open','');host.appendChild(copy);">+ Add service row</button>
        </div>

        <div class="workspace-span-2"><strong style="font-size:13px;color:var(--mbw-heading)">Witnesses — साक्षीहरू</strong></div>
        <label>Witness 1 name<input type="text" name="w1_name" maxlength="160" value="<?= e($witnesses['w1']['name'] ?? '') ?>"></label>
        <label>Witness 1 address<input type="text" name="w1_address" maxlength="255" value="<?= e($witnesses['w1']['address'] ?? '') ?>"></label>
        <label>Witness 2 name<input type="text" name="w2_name" maxlength="160" value="<?= e($witnesses['w2']['name'] ?? '') ?>"></label>
        <label>Witness 2 address<input type="text" name="w2_address" maxlength="255" value="<?= e($witnesses['w2']['address'] ?? '') ?>"></label>

        <div class="workspace-span-2"><strong style="font-size:13px;color:var(--mbw-heading)">Additional custom clauses (optional, printed at the end of Chapter ०९)</strong></div>
        <label class="workspace-span-2">नेपाली<textarea name="custom_clauses_np" rows="2"><?= e($edit['custom_clauses_np'] ?? '') ?></textarea></label>
        <label class="workspace-span-2">English<textarea name="custom_clauses_en" rows="2"><?= e($edit['custom_clauses_en'] ?? '') ?></textarea></label>

        <label>Status
            <select name="status">
                <option value="draft" <?= (string) ($edit['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="final" <?= (string) ($edit['status'] ?? '') === 'final' ? 'selected' : '' ?>>Final</option>
            </select>
        </label>
        <div class="workspace-span-2"><button type="submit"><?= icon('contracts') ?>Save Agreement</button></div>
    </form>
</section>

<section class="mbw-card">
    <div class="mbw-card-head"><h2>Agreements (<?= count($agreements) ?>)</h2></div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>No</th><th>First party (client)</th><th>Purpose</th><th>Date (BS)</th><th class="is-numeric">Monthly fee</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php if ($agreements === []): ?><tr><td colspan="7">No agreements yet. Draft one for each client before work begins.</td></tr><?php endif; ?>
            <?php foreach ($agreements as $agreement): ?>
                <tr>
                    <td><strong><?= e((string) $agreement['agreement_no']) ?></strong><?= $agreement['linked_contract_no'] !== null ? '<br><small style="color:var(--mbw-muted)">Contract ' . e((string) $agreement['linked_contract_no']) . '</small>' : '' ?></td>
                    <td><?= e($agreement['first_party_name_en']) ?><?= $agreement['organization_name'] ? ' <small style="color:var(--mbw-muted)">(' . e($agreement['organization_name']) . ')</small>' : '' ?></td>
                    <td><?= e($agreement['purpose_en']) ?></td>
                    <td><?= e($agreement['agreement_date_bs'] ?? '—') ?></td>
                    <td class="is-numeric"><?= e(number_format((float) $agreement['fee_monthly'], 2)) ?></td>
                    <td><span class="mbw-pill <?= (string) $agreement['status'] === 'final' ? 'tone-green' : 'tone-amber' ?>"><?= e(ucfirst((string) $agreement['status'])) ?></span></td>
                    <td style="white-space:nowrap">
                        <a class="button secondary" style="min-height:30px;padding:3px 10px" href="<?= e(url('admin/service-agreements.php?edit=' . (int) $agreement['id'])) ?>">Edit</a>
                        <a class="button secondary" style="min-height:30px;padding:3px 10px" target="_blank" href="<?= e(url('admin/export-agreement.php?id=' . (int) $agreement['id'] . '&lang=both')) ?>">Print</a>
                        <?php if ((string) $agreement['status'] === 'draft'): ?>
                            <form method="post" style="display:inline" data-confirm="Delete this DRAFT agreement?">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_agreement">
                                <input type="hidden" name="agreement_id" value="<?= (int) $agreement['id'] ?>">
                                <button type="submit" class="button secondary" style="min-height:30px;padding:3px 10px;color:#a33">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php include __DIR__ . '/../../app/views/partials/admin_footer.php'; ?>
