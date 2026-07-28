<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/accounting_module_repair.php';
require_once __DIR__ . '/../../app/jewellery_reports.php';

/**
 * The jewellery sales invoice, laid out as the trade actually prints it.
 *
 * Deliberately NOT the generic document preview in jewellery-print.php. That
 * one renders any document as a table of rows, which is right for a report and
 * wrong for a bill a customer signs: this trade's invoice has a fixed column
 * order (Grs Wt, Less, Net Wt, Wast, Total Wt, then Rate, Amount, Making, and
 * the three stone columns) and a totals block whose lines are named in law —
 * SD Taxable Amt, Vatable Amt, Non Taxable Amt. Getting those wrong is not a
 * cosmetic problem.
 *
 * Every figure printed here is READ from the stored document. Nothing is
 * recomputed at print time, so a reprint years later shows what was actually
 * charged rather than what today's tax rules would charge.
 *
 * Read-only. This file never writes.
 */
accounting_module_repair_database();
require_jewellery();

$company = current_company();
if (!$company) {
    flash('error', 'Company context required.');
    redirect('admin/accounting-dashboard.php');
}
$companyId = (int) $company['id'];
$saleId = (int) ($_GET['id'] ?? 0);
$copy = jw_enum($_GET['copy'] ?? null, ['merchant', 'customer', 'office'], 'merchant');

$sale = jewellery_sale($companyId, $saleId);
if (!$sale) {
    flash('error', 'That bill does not belong to this company.');
    redirect('admin/jewellery-trade.php?view=sales');
}
$lines = jewellery_sale_line_rows($companyId, $saleId);
$exchanges = jewellery_sale_exchange_rows($companyId, $saleId);

$party = null;
if ((int) ($sale['party_id'] ?? 0) > 0) {
    $partyStmt = db()->prepare('SELECT * FROM accounting_parties WHERE id = :id AND company_id = :cid LIMIT 1');
    $partyStmt->execute(['id' => (int) $sale['party_id'], 'cid' => $companyId]);
    $party = $partyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$settings = jewellery_settings($companyId);
$sym = site_currency_symbol();
$n2 = static fn ($v): string => number_format((float) $v, 2);
$n3 = static fn ($v): string => number_format((float) $v, 3);
$n4 = static fn ($v): string => number_format((float) $v, 4);
/** A weight column prints blank at zero — a bill full of 0.0000 is unreadable. */
$w = static function ($v, int $dp = 4) use ($n4, $n3): string {
    return abs((float) $v) < 0.00005 ? '' : ($dp === 3 ? $n3($v) : $n4($v));
};
$m = static function ($v) use ($n2): string {
    return abs((float) $v) < 0.005 ? '' : $n2($v);
};

// The rate is quoted per gram; a shop reads in tola, so both are shown the way
// the paper does — per gram large, per tola in brackets beneath.
$tolaGrams = 11.6638;
$tolaRow = db()->prepare("SELECT grams FROM jewellery_units WHERE company_id = :cid AND code = 'TOLA' LIMIT 1");
$tolaRow->execute(['cid' => $companyId]);
$tolaGrams = (float) ($tolaRow->fetchColumn() ?: $tolaGrams) ?: 11.6638;
// A line is priced in ITS OWN unit, which is not always the gram. The column is
// headed Rate/Gm, so a tola-priced line has to be restated before it is printed
// — otherwise the bill would show a per-tola figure under a per-gram heading.
$unitMap = jw_unit_map($companyId);
$unitGrams = static function (array $line) use ($unitMap): float {
    return (float) ($unitMap[(int) ($line['unit_id'] ?? 0)]['grams'] ?? 1.0) ?: 1.0;
};

$taxRows = jw_document_taxes($companyId, 'sale', $saleId);
$sdRate = 0.0;
$vatRate = 0.0;
foreach ($taxRows as $t) {
    if ((string) $t['output_purpose'] === 'vat_output') {
        $vatRate = (float) db()->query('SELECT rate FROM jewellery_taxes WHERE company_id=' . $companyId
            . " AND code=" . db()->quote((string) $t['tax_code']) . ' LIMIT 1')->fetchColumn();
    } else {
        $sdRate = (float) db()->query('SELECT rate FROM jewellery_taxes WHERE company_id=' . $companyId
            . " AND code=" . db()->quote((string) $t['tax_code']) . ' LIMIT 1')->fetchColumn();
    }
}

$title = 'Invoice ' . (string) $sale['sale_no'];
$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$copyLabel = ['merchant' => 'Merchant Copy', 'customer' => 'Customer Copy', 'office' => 'Office Copy'][$copy];

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $esc($title) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font: 11px/1.35 Arial, Helvetica, sans-serif; color: #000; background: #fff; margin: 14px; }
  .sheet { max-width: 1180px; margin: 0 auto; }
  .bar { margin: 0 0 12px; }
  .bar button, .bar a { font: inherit; padding: 6px 13px; margin-right: 6px; cursor: pointer;
      border: 1px solid #888; background: #f4f4f4; color: #000; text-decoration: none; display: inline-block; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
  .org h1 { font-size: 19px; margin: 0; letter-spacing: .4px; }
  .org .tag { font-size: 10px; letter-spacing: .6px; text-transform: uppercase; }
  .org .addr { font-size: 10.5px; margin-top: 2px; }
  .copy { text-align: right; }
  .copy .kind { font-size: 15px; font-weight: 700; }
  .meta { margin-top: 8px; font-size: 10.5px; }
  .meta div { white-space: nowrap; }
  .meta b { display: inline-block; min-width: 74px; font-weight: 600; }
  .who { display: flex; justify-content: space-between; gap: 16px; margin: 10px 0 6px; font-size: 10.5px; }
  .who b { display: inline-block; min-width: 62px; font-weight: 600; }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #000; padding: 2px 4px; }
  thead th { text-align: center; font-weight: 700; font-size: 9.5px; line-height: 1.2; }
  td.n { text-align: right; white-space: nowrap; }
  td.c { text-align: center; }
  .lines tbody tr { height: 17px; }
  .foot { display: flex; gap: 10px; margin-top: -1px; }
  .foot .left { flex: 1 1 auto; }
  .foot .right { flex: 0 0 260px; }
  .totals td { padding: 2px 5px; }
  .totals .lbl { text-align: left; }
  .totals .amt { text-align: right; white-space: nowrap; }
  .totals tr.net td { font-weight: 700; border-top: 2px solid #000; }
  .words { padding: 5px 4px; font-weight: 700; }
  .terms { margin-top: 8px; font-size: 9.5px; }
  .terms li { margin: 1px 0; }
  .signs { display: flex; justify-content: space-between; margin-top: 26px; font-size: 10.5px; }
  .signs div { border-top: 1px solid #000; padding-top: 3px; min-width: 190px; text-align: center; }
  @media print {
    .bar { display: none; }
    body { margin: 0; font-size: 10px; }
    @page { size: A4 landscape; margin: 8mm; }
  }
</style>
</head>
<body>
<div class="sheet">

  <div class="bar">
    <button onclick="window.print()">Print / Save as PDF</button>
    <?php foreach (['merchant' => 'Merchant', 'customer' => 'Customer', 'office' => 'Office'] as $key => $label): ?>
      <a href="<?= $esc(url('admin/jewellery-invoice.php?id=' . $saleId . '&copy=' . $key)) ?>"><?= $esc($label) ?> copy</a>
    <?php endforeach; ?>
    <a href="<?= $esc(url('admin/jewellery-trade.php?view=sales&edit=' . $saleId)) ?>">Back to the bill</a>
  </div>

  <div class="head">
    <div class="org">
      <h1><?= $esc($company['name'] ?? '') ?></h1>
      <div class="tag"><?= $esc(setting('company_tagline', '')) ?></div>
      <div class="addr"><?= $esc($company['address'] ?? setting('company_address', '')) ?></div>
      <div class="addr">PAN: <?= $esc($company['pan_no'] ?? setting('company_pan', '')) ?></div>
    </div>
    <div class="copy">
      <div class="kind">Invoice</div>
      <div>(<?= $esc($copyLabel) ?>)</div>
      <div class="meta">
        <div><b>Bill No.</b> : <?= $esc($sale['sale_no']) ?></div>
        <div><b>Bill Date</b> : <?= $esc(app_date((string) $sale['sale_date'])) ?></div>
        <?php if ((string) ($sale['tran_date_bs'] ?? '') !== ''): ?>
          <div><b>Tran Date</b> : <?= $esc($sale['tran_date_bs']) ?></div>
        <?php endif; ?>
        <div><b>Sales Person</b> : <?= $esc($sale['sales_person'] ?? '') ?></div>
        <div><b>Customer Id</b> : <?= $esc($sale['customer_ref'] ?? ($party['code'] ?? '')) ?></div>
        <div><b>Pan No.</b> : <?= $esc(($party['pan_no'] ?? '') !== '' ? $party['pan_no'] : 'n/a') ?></div>
      </div>
    </div>
  </div>

  <div class="who">
    <div>
      <div><b>Customer</b> : <?= $esc($party['name'] ?? $sale['customer_name'] ?? '') ?></div>
      <div><b>Address</b> : <?= $esc($party['billing_address'] ?? '') ?></div>
      <div><b>Phone</b> : <?= $esc($party['phone'] ?? '') ?></div>
    </div>
    <div>
      <div><b>Status</b> : <?= $esc(ucfirst((string) $sale['status'])) ?></div>
      <?php if ((string) ($sale['ref_no'] ?? '') !== ''): ?>
        <div><b>Ref.</b> : <?= $esc($sale['ref_no']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th rowspan="2" style="width:22px">SN</th>
        <th rowspan="2" style="width:48px">HS<br>Code</th>
        <th rowspan="2">Description</th>
        <th rowspan="2" style="width:34px">Pcs</th>
        <th rowspan="2" style="width:42px">Type</th>
        <th rowspan="2" style="width:40px">Purity</th>
        <th colspan="5">Gold / Silver</th>
        <th rowspan="2" style="width:74px">Rate/Gm<br>(Tola)</th>
        <th rowspan="2" style="width:72px">Amount</th>
        <th rowspan="2" style="width:58px">Making</th>
        <th colspan="2">Diamond</th>
        <th colspan="2">Other Diamond</th>
        <th colspan="2">Stone</th>
        <th rowspan="2" style="width:76px">Total<br>Amount</th>
      </tr>
      <tr>
        <th style="width:48px">Grs Wt</th>
        <th style="width:44px">Less</th>
        <th style="width:48px">Net Wt</th>
        <th style="width:44px">Wast</th>
        <th style="width:50px">Total Wt</th>
        <th style="width:38px">Crt</th>
        <th style="width:52px">Amt</th>
        <th style="width:38px">Crt</th>
        <th style="width:52px">Amt</th>
        <th style="width:38px">Crt</th>
        <th style="width:52px">Amt</th>
      </tr>
    </thead>
    <tbody>
      <?php $sn = 0; foreach ($lines as $line): $sn++; ?>
      <tr>
        <td class="c"><?= $sn ?></td>
        <td class="c"><?= $esc($line['hs_code'] ?? '') ?></td>
        <td><?= $esc(trim((string) ($line['item_code'] ?? '') . ' ' . (string) ($line['item_name'] ?? ''))) ?>
          <?php if (abs($unitGrams($line) - 1.0) > 0.0001): ?>
            <span style="font-size:9px">(weights in <?= $esc($line['unit_code'] ?? '') ?>)</span>
          <?php endif; ?>
        </td>
        <td class="n"><?= $w($line['qty_pieces'], 3) ?></td>
        <td class="c"><?= $esc($line['metal_name'] ?? '') ?></td>
        <td class="c"><?= $esc($line['purity_code'] ?? '') ?></td>
        <td class="n"><?= $w($line['gross_weight'], 3) ?></td>
        <td class="n"><?= $w($line['stone_weight']) ?></td>
        <td class="n"><?= $w($line['net_weight'], 3) ?>
          <?php // The pure-metal content, printed WITH the actual weight — the
                // ornament as weighed and the metal as the melt would prove it. ?>
          <?php if ((float) ($line['fine_weight'] ?? 0) > 0.0005): ?>
            <br><span style="font-size:9px">fine <?= $w($line['fine_weight'], 3) ?></span>
          <?php endif; ?>
        </td>
        <td class="n"><?= $w($line['wastage_weight']) ?></td>
        <td class="n"><?= $w($line['total_weight'], 3) ?></td>
        <td class="n">
          <?php $perGram = (float) $line['rate'] / $unitGrams($line); ?>
          <?= $n3($perGram) ?>
          <?php if ($perGram > 0): ?>
            <br><span style="font-size:9px">(<?= $n2($perGram * $tolaGrams) ?>)</span>
          <?php endif; ?>
        </td>
        <td class="n"><?= $m($line['metal_amount']) ?></td>
        <td class="n"><?= $m($line['making_amount']) ?></td>
        <td class="n"><?= $w($line['diamond_carat'], 3) ?></td>
        <td class="n"><?= $m($line['diamond_amount']) ?></td>
        <td class="n"><?= $w($line['other_diamond_carat'], 3) ?></td>
        <td class="n"><?= $m($line['other_diamond_amount']) ?></td>
        <td class="n"><?= $w($line['stone_carat']) ?></td>
        <td class="n"><?= $m($line['stone_amount']) ?></td>
        <td class="n"><?= $n2((float) $line['metal_amount'] + (float) $line['making_amount']
            + (float) $line['stone_amount'] + (float) $line['diamond_amount']
            + (float) $line['other_diamond_amount']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($exchanges as $ex): ?>
      <tr>
        <td class="c"></td><td></td>
        <td>Old gold taken in exchange — <?= $esc($ex['item_code'] ?? '') ?></td>
        <td class="n"><?= $w($ex['qty_pieces'], 3) ?></td>
        <td class="c"></td>
        <td class="c"><?= $esc($ex['purity_code'] ?? '') ?></td>
        <td class="n"><?= $w($ex['gross_weight'], 3) ?></td>
        <td></td><td class="n"><?= $w($ex['gross_weight'], 3) ?></td><td></td>
        <td class="n"><?= $w($ex['gross_weight'], 3) ?></td>
        <td class="n"><?= $n3((float) $ex['rate'] / $unitGrams($ex)) ?></td>
        <td class="n">(<?= $n2($ex['amount']) ?>)</td>
        <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        <td class="n">(<?= $n2($ex['amount']) ?>)</td>
      </tr>
      <?php endforeach; ?>
      <?php for ($blank = count($lines) + count($exchanges); $blank < 6; $blank++): ?>
      <tr><?php for ($c = 0; $c < 21; $c++): ?><td>&nbsp;</td><?php endfor; ?></tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <div class="foot">
    <div class="left">
      <table>
        <tr>
          <td class="words">In Words: <?= $esc(npr_amount_in_words((float) $sale['total_amount'])) ?></td>
        </tr>
        <?php
          // The bill's whole pure-metal content, reduced to grams so lines in
          // different units still sum to one honest figure.
          $fineGrams = 0.0;
          foreach ($lines as $line) {
              $fineGrams += (float) ($line['fine_weight'] ?? 0) * $unitGrams($line);
          }
        ?>
        <?php if ($fineGrams > 0.0005): ?>
        <tr>
          <td style="font-size:9.5px;padding:2px 4px">Fine gold equivalent: <?= $n3($fineGrams) ?> g</td>
        </tr>
        <?php endif; ?>
      </table>
      <table style="margin-top:-1px">
        <thead><tr>
          <th>Cash</th><th>Card</th><th>Advance</th><th>Cheque</th><th>Credit</th><th>QR/Transfer</th><th>Purchase</th>
        </tr></thead>
        <tbody><tr style="height:26px">
          <?php
            // The tender split when it was recorded; otherwise the whole
            // receipt sits under the mode the sale was settled in, which is
            // the most the document actually knows.
            $paidCash = (float) ($sale['paid_cash'] ?? 0);
            $paidCard = (float) ($sale['paid_card'] ?? 0);
            $paidCheque = (float) ($sale['paid_cheque'] ?? 0);
            $paidQr = (float) ($sale['paid_qr'] ?? 0);
            $split = $paidCash + $paidCard + $paidCheque + $paidQr;
            $received = (float) $sale['received_amount'];
            if ($split < 0.005 && $received > 0.005) {
                if ((string) $sale['settle_mode'] === 'bank') { $paidQr = $received; } else { $paidCash = $received; }
            }
          ?>
          <td class="n"><?= $m($paidCash) ?></td>
          <td class="n"><?= $m($paidCard) ?></td>
          <td class="n"><?= $m($sale['advance_amount'] ?? 0) ?></td>
          <td class="n"><?= $m($paidCheque) ?></td>
          <td class="n"><?= $m($sale['balance_amount']) ?></td>
          <td class="n"><?= $m($paidQr) ?></td>
          <td class="n"><?= $m($sale['exchange_amount']) ?></td>
        </tr></tbody>
      </table>
      <table style="margin-top:-1px">
        <tr><td style="height:34px">Remarks: <?= $esc($sale['remarks'] ?? $sale['narration'] ?? '') ?></td></tr>
      </table>
    </div>

    <div class="right">
      <table class="totals">
        <tr><td class="lbl">&nbsp;</td><td class="amt"><b>Amount</b></td></tr>
        <tr><td class="lbl">Non Taxable Amt</td><td class="amt"><?= $n2($sale['non_taxable_amount'] ?? 0) ?></td></tr>
        <tr><td class="lbl">SD Taxable Amt</td><td class="amt"><?= $n2($sale['sd_taxable_amount'] ?? 0) ?></td></tr>
        <tr><td class="lbl">SD Tax <?= $esc(rtrim(rtrim(number_format($sdRate, 3), '0'), '.')) ?>%</td>
            <td class="amt"><?= $n2($sale['tax_amount'] ?? 0) ?></td></tr>
        <tr><td class="lbl">Vatable Amt</td><td class="amt"><?= $n2($sale['vatable_amount'] ?? 0) ?></td></tr>
        <tr><td class="lbl">Vat Amt <?= $esc(rtrim(rtrim(number_format($vatRate, 3), '0'), '.')) ?>%</td>
            <td class="amt"><?= $n2($sale['vat_amount']) ?></td></tr>
        <?php if (abs((float) $sale['discount']) > 0.005): ?>
          <tr><td class="lbl">Discount</td><td class="amt">(<?= $n2($sale['discount']) ?>)</td></tr>
        <?php endif; ?>
        <?php if (abs((float) $sale['other_charges']) > 0.005): ?>
          <tr><td class="lbl">Other charges</td><td class="amt"><?= $n2($sale['other_charges']) ?></td></tr>
        <?php endif; ?>
        <tr class="net"><td class="lbl">Net Total</td><td class="amt"><?= $n2($sale['total_amount']) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="terms">
    <ul style="margin:4px 0 0 14px;padding:0">
      <li>Jewelleries are refundable after 2:00 PM only.</li>
      <li>Bill is compulsory while returning the jewelleries.</li>
      <li>Labor charge, waste and synthetic stones are not refundable.</li>
      <li>Jewelleries if brought in melted condition will not be guaranteed by the company.</li>
    </ul>
  </div>

  <div class="signs">
    <div>Customer Sign</div>
    <div>For — <?= $esc($company['name'] ?? '') ?></div>
  </div>

</div>
</body>
</html>
