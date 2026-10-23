<?php
declare(strict_types=1);

function hospitality_recipe_import_headers(): array
{
    return ['Recipe code','Recipe name','Menu item code','Effective from (AD)','Ingredient code','Quantity',
        'Effective to (AD)','Batch yield qty','Portions','Portion size','Preparation wastage %',
        'Packaging cost','Other direct cost','Recipe notes','Ingredient notes'];
}

/** Parse only company-scoped master codes; quantities use the ingredient's recipe unit. */
function hospitality_recipe_import_parse(array $rows,array $menu,array $ingredients,array $existingCodes): array
{
    if (count($rows)<2 || count($rows)>1001) throw new RuntimeException('Upload 1 to 1,000 ingredient rows.');
    $headers=array_map('trim',$rows[0]['cells']);
    if ($headers!==hospitality_recipe_import_headers()) throw new RuntimeException('Use the recipe template with its column headings unchanged.');
    $key=static fn(string $value):string=>mb_strtolower(trim($value));
    $menus=[]; $ings=[]; $existing=[];
    foreach($menu as $row) $menus[$key($row['code'])]=$row;
    foreach($ingredients as $row) $ings[$key($row['code'])]=$row;
    foreach($existingCodes as $code) $existing[$key((string)$code)]=true;
    $date=static function(string $value):string {
        // Excel's numeric date cells and explicit AD dates are both accepted.
        if (ctype_digit($value) && (int)$value>0 && (int)$value<100000) $value=(new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$value.' days')->format('Y-m-d');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$parsed || $parsed->format('Y-m-d')!==$value) throw new RuntimeException('Use a valid AD date (YYYY-MM-DD).');
        return $value;
    };
    $number=static function(string $value,float $default,int $precision,bool $positive=false):float {
        if ($value==='') return $default;
        if (!is_numeric($value) || !is_finite((float)$value)) throw new RuntimeException('Enter valid numeric quantities and costs.');
        $n=round((float)$value,$precision);
        if ($n<0 || $n>=1000000000 || ($positive && $n<=0)) throw new RuntimeException('Quantities and portions must be positive; costs cannot be negative.');
        return $n;
    };
    $recipes=[];
    foreach(array_slice($rows,1) as $row) {
        $cells=array_map(static fn($v)=>trim((string)$v),$row['cells']);
        if (implode('',$cells)==='') continue;
        try {
            if (count($cells)>15 && implode('',array_slice($cells,15))!=='') throw new RuntimeException('Unexpected extra columns.');
            $c=array_pad($cells,15,'');
            foreach([0=>60,1=>160,9=>60,13=>255,14=>255] as $index=>$limit) if(mb_strlen($c[$index])>$limit) throw new RuntimeException('Text exceeds the template field length.');
            if ($c[0]==='' || $c[1]==='' || $c[3]==='' || $c[5]==='') throw new RuntimeException('Recipe code, name, effective date and quantity are required on every row.');
            $code=$key($c[0]);
            if(isset($existing[$code])) throw new RuntimeException('Recipe code already exists: '.$c[0]);
            $mi=$menus[$key($c[2])] ?? null; $ingredient=$ings[$key($c[4])] ?? null;
            if (!$mi || !$ingredient) throw new RuntimeException('Select active menu-item and ingredient codes belonging to this company.');
            $from=$date($c[3]); $to=$c[6]!=='' ? $date($c[6]) : null;
            if ($to!==null && $to<$from) throw new RuntimeException('Effective-to date precedes effective-from.');
            $header=['code'=>$c[0],'name'=>$c[1],'menu_item_id'=>(int)$mi['id'],'menu_code'=>$mi['code'],
                'effective_from'=>$from,'effective_to'=>$to,'yield_qty'=>$number($c[7],1,3,true),
                'portions'=>$number($c[8],1,3,true),'portion_size'=>$c[9],'prep_wastage_pct'=>$number($c[10],0,2),
                'packaging_cost'=>$number($c[11],0,2),'other_cost'=>$number($c[12],0,2),'notes'=>$c[13]];
            if ($header['prep_wastage_pct']>=100) throw new RuntimeException('Preparation wastage must be below 100%.');
            if (isset($recipes[$code]) && $recipes[$code]['header']!==$header) throw new RuntimeException('Recipe details must match on every row of '.$c[0].'.');
            if (isset($recipes[$code]['lines'][(int)$ingredient['id']])) throw new RuntimeException('Ingredient repeated in recipe '.$c[0].'. Combine its quantities.');
            $recipes[$code]['header']=$header;
            $recipes[$code]['lines'][(int)$ingredient['id']]=['ingredient_id'=>(int)$ingredient['id'],'code'=>$ingredient['code'],
                'quantity'=>$number($c[5],0,4,true),'unit'=>$ingredient['recipe_unit'],'notes'=>$c[14]];
        } catch(RuntimeException $e) { throw new RuntimeException('Row '.(int)$row['n'].': '.$e->getMessage()); }
    }
    if ($recipes===[]) throw new RuntimeException('No recipe ingredient rows found.');
    if (count($recipes)>100) throw new RuntimeException('Upload at most 100 recipes at a time.');
    return array_values($recipes);
}

function hospitality_recipe_import_preview(int $companyId,array $rows,bool $lock=false): array
{
    if (count($rows)<2 || count($rows)>1001) throw new RuntimeException('Upload 1 to 1,000 ingredient rows.');
    // Scope lookups to uploaded codes. Confirmation uses current locking reads,
    // not a stale repeatable-read snapshot taken before another user's edit.
    $lookup=static function(string $table,string $fields,int $column,bool $active)use($companyId,$rows,$lock):array {
        $codes=[];
        foreach(array_slice($rows,1) as $row) {
            $code=trim((string)($row['cells'][$column] ?? ''));
            if($code!=='') $codes[$code]=true;
        }
        if($codes===[]) return [];
        $codes=array_keys($codes); sort($codes);
        $marks=implode(',',array_fill(0,count($codes),'?'));
        $q=db()->prepare("SELECT $fields FROM $table WHERE company_id=? AND code IN ($marks)".($active ? ' AND active=1' : '').' ORDER BY id'.($lock ? ' FOR UPDATE' : ''));
        $q->execute(array_merge([$companyId],$codes));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    $menu=$lookup('hospitality_menu_items','id,code',2,true);
    $ingredients=$lookup('hospitality_ingredients','id,code,recipe_unit',4,true);
    $existing=$lookup('hospitality_recipes','code',0,false);
    return hospitality_recipe_import_parse($rows,$menu,$ingredients,array_column($existing,'code'));
}

function hospitality_recipe_import_save(int $companyId,array $rows,int $actor): array
{
    $pdo=db(); $owns=!$pdo->inTransaction(); if($owns) $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE')->execute([$companyId]);
        $recipes=hospitality_recipe_import_preview($companyId,$rows,true);
        $insert=$pdo->prepare("INSERT INTO hospitality_recipes(company_id,menu_item_id,code,name,version,effective_from,effective_to,yield_qty,portions,portion_size,prep_wastage_pct,packaging_cost,other_cost,notes,status,created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)");
        $line=$pdo->prepare('INSERT INTO hospitality_recipe_lines(recipe_id,ingredient_id,quantity,unit,notes) VALUES(?,?,?,?,?)');
        $version=$pdo->prepare('SELECT COALESCE(MAX(version),0)+1 FROM hospitality_recipes WHERE menu_item_id=?');
        $ids=[];
        foreach($recipes as $recipe) {
            $h=$recipe['header']; $version->execute([$h['menu_item_id']]);
            $insert->execute([$companyId,$h['menu_item_id'],$h['code'],$h['name'],(int)$version->fetchColumn(),$h['effective_from'],$h['effective_to'],$h['yield_qty'],$h['portions'],$h['portion_size'],$h['prep_wastage_pct'],$h['packaging_cost'],$h['other_cost'],$h['notes'],$actor]);
            $id=(int)$pdo->lastInsertId(); $ids[]=$id;
            foreach($recipe['lines'] as $l) $line->execute([$id,$l['ingredient_id'],$l['quantity'],$l['unit'],$l['notes']]);
            log_activity('hospitality_recipe',$id,'imported','Recipe imported as draft.',$actor);
        }
        if($owns) $pdo->commit();
        return $ids;
    } catch(Throwable $e) { if($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
