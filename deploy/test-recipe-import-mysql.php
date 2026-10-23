<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/hospitality_recipe_import.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1'],true)) throw new RuntimeException('Local database only');
$pdo=db(); $cid=(int)$pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetchColumn();
$actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if(!$cid || !$actor) throw new RuntimeException('Local fixtures required');
$pdo->beginTransaction();
try {
    $suffix=strtoupper(bin2hex(random_bytes(5)));
    $pdo->prepare("INSERT INTO hospitality_menu_items(company_id,code,name) VALUES(?,?,'Import test')")->execute([$cid,'MI'.$suffix]); $menu=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO hospitality_ingredients(company_id,code,name,recipe_unit,effective_date) VALUES(?,?,'Import test','g','2026-10-23')")->execute([$cid,'IG'.$suffix]); $ingredient=(int)$pdo->lastInsertId();
    $rows=[['n'=>1,'cells'=>hospitality_recipe_import_headers()],['n'=>2,'cells'=>['R'.$suffix,'Imported recipe','MI'.$suffix,'2026-10-23','IG'.$suffix,'12.5','','1','2','','0','0','0','','']]];
    hospitality_recipe_import_preview($cid,$rows);
    $q=$pdo->prepare('SELECT COUNT(*) FROM hospitality_recipes WHERE menu_item_id=?'); $q->execute([$menu]);
    if((int)$q->fetchColumn()!==0) throw new RuntimeException('Preview saved a recipe');
    $ids=hospitality_recipe_import_save($cid,$rows,$actor);
    $q=$pdo->prepare('SELECT status,version FROM hospitality_recipes WHERE id=? AND company_id=?');$q->execute([$ids[0],$cid]);$recipe=$q->fetch();
    if($recipe['status']!=='draft' || (int)$recipe['version']!==1) throw new RuntimeException('Draft/version failed');
    $q=$pdo->prepare('SELECT ingredient_id,quantity,unit FROM hospitality_recipe_lines WHERE recipe_id=?'); $q->execute([$ids[0]]); $line=$q->fetch();
    if((int)$line['ingredient_id']!==$ingredient || (float)$line['quantity']!==12.5 || $line['unit']!=='g') throw new RuntimeException('Ingredient line mismatch');
    try {hospitality_recipe_import_save($cid,$rows,$actor); throw new LogicException('Replay accepted');} catch(RuntimeException $e) {}
    try {hospitality_recipe_import_preview($cid+100000,$rows); throw new LogicException('Foreign codes accepted');} catch(RuntimeException $e) {}
    $rows[1]['cells'][0]='R2'.$suffix;
    $pdo->prepare('UPDATE hospitality_ingredients SET active=0 WHERE id=?')->execute([$ingredient]);
    try {hospitality_recipe_import_save($cid,$rows,$actor); throw new LogicException('Stale preview accepted');} catch(RuntimeException $e) {}
    $q=$pdo->prepare('SELECT COUNT(*) FROM hospitality_recipes WHERE menu_item_id=?');$q->execute([$menu]);
    if((int)$q->fetchColumn()!==1) throw new RuntimeException('Invalid import changed recipes');
    echo "Recipe import MySQL: preview-only, draft save, unit/quantity, replay, tenant isolation and inactive-master revalidation passed (fixtures rolled back).\n";
} finally {if($pdo->inTransaction()) $pdo->rollBack();}
