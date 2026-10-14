<?php
declare(strict_types=1);
require __DIR__.'/../app/business_notes.php';
$source=['status'=>'posted','voucher_type'=>'sales','source_type'=>'jewellery_sale'];
if(!business_note_source_allowed('jewellery','credit_note',$source)) throw new RuntimeException('Jewellery sale rejected');
if(business_note_source_allowed('hospitality','credit_note',$source)) throw new RuntimeException('Wrong business accepted');
if(business_note_source_allowed('jewellery','debit_note',$source)) throw new RuntimeException('Wrong note accepted');
$source['status']='draft';
if(business_note_source_allowed('jewellery','credit_note',$source)) throw new RuntimeException('Draft accepted');
$entries=[['ledger_id'=>1,'entry_type'=>'debit','amount'=>113],['ledger_id'=>2,'entry_type'=>'credit','amount'=>100],['ledger_id'=>3,'entry_type'=>'credit','amount'=>13]];
$reversed=business_note_reverse_entries($entries);
if(array_column($reversed,'entry_type')!==['credit','debit','debit'] || array_column($reversed,'amount')!==[113.0,100.0,13.0]) throw new RuntimeException('Tax/settlement reversal failed');
$entries[0]['amount']=112;
try {business_note_reverse_entries($entries); throw new LogicException('Unbalanced source accepted');} catch(RuntimeException $e) {}
echo "Business notes: source type, status, business separation, exact tax reversal and balance passed.\n";
