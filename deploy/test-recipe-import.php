<?php
declare(strict_types=1);
require __DIR__.'/../app/hospitality_recipe_import.php';
require __DIR__.'/../app/export_engine.php';
$menu=[['id'=>1,'code'=>'MENU1']];
$ingredients=[['id'=>2,'code'=>'ING1','recipe_unit'=>'g'],['id'=>3,'code'=>'ING2','recipe_unit'=>'ml']];
$header=['n'=>1,'cells'=>hospitality_recipe_import_headers()];
$cells=['REC1','Test recipe','MENU1','2026-10-23','ING1','250','','1','2','plate','2','5','3','Recipe note','Ingredient note'];
$rows=[$header,['n'=>2,'cells'=>$cells],['n'=>3,'cells'=>array_replace($cells,[4=>'ING2',5=>'10'])]];
$parse=static fn(array $r,array $existing=[])=>hospitality_recipe_import_parse($r,$menu,$ingredients,$existing);
$result=$parse($rows);
if(count($result)!==1 || count($result[0]['lines'])!==2 || $result[0]['header']['portions']!==2.0 || $result[0]['lines'][2]['unit']!=='g') throw new RuntimeException('Grouping/units failed');
$reject=static function(callable $run):void {
    try {$run();} catch(RuntimeException $e) {return;}
    throw new LogicException('Invalid recipe accepted');
};
foreach([0=>'',2=>'OTHER-COMPANY',3=>'2026-02-30',4=>'UNKNOWN',5=>'0',7=>'-1',8=>'0',10=>'100',11=>'NaN',6=>'2026-01-01'] as $column=>$bad) {
    $reject(fn()=>$parse([$header,['n'=>2,'cells'=>array_replace($cells,[$column=>$bad])]]));
}
$reject(fn()=>$parse($rows,['rec1']));
$reject(fn()=>$parse([$header,$rows[1],$rows[1]]));
$reject(fn()=>$parse([$header,$rows[1],['n'=>3,'cells'=>array_replace($rows[2]['cells'],[1=>'Different name'])]]));
$reject(fn()=>$parse(array_merge([$header],array_fill(0,1001,$rows[1]))));
$blank=array_replace($cells,[7=>'',8=>'',10=>'',11=>'',12=>'']);
if($parse([$header,['n'=>2,'cells'=>$blank]])[0]['header']['portions']!==1.0) throw new RuntimeException('Defaults failed');
// Exercise the same Excel/CSV readers used by the upload, including template headings.
$path=tempnam(sys_get_temp_dir(),'recipe-import-');
try {
    $f=fopen($path,'w'); foreach($rows as $row) fputcsv($f,$row['cells'],',','"','\\'); fclose($f);
    if(count($parse(spreadsheet_read_rows($path,'recipes.csv')))!==1) throw new RuntimeException('CSV round trip failed');
    if(class_exists('ZipArchive')) {
        file_put_contents($path,xlsx_build_sheets(['Recipes'=>array_column($rows,'cells')]));
        if(count($parse(spreadsheet_read_rows($path,'recipes.xlsx')))!==1) throw new RuntimeException('Excel round trip failed');
    }
} finally {unlink($path);}
echo "Recipe import: grouping, codes, dates, quantities, duplicates, consistent headers, defaults, row limits and CSV/Excel round trips passed.\n";
