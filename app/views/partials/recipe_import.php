<?php
declare(strict_types=1);
$held=$_SESSION['hospitality_recipe_import'] ?? null;
$recipeImportPreview=[]; $recipeImportError='';
if ($held && ($held['company_id'] ?? 0)===$companyId && ($held['user_id'] ?? 0)===$userId && ($held['fiscal_year_id'] ?? 0)===$fiscalYearId) {
    try {
        if (($held['expires'] ?? 0)<time()) throw new RuntimeException('Recipe preview expired. Upload the file again.');
        $recipeImportPreview=hospitality_recipe_import_preview($companyId,$held['rows']);
    } catch(Throwable $e) {
        $recipeImportError=$e instanceof PDOException ? 'Recipe preview is unavailable. Upload the file again.' : $e->getMessage();
        unset($_SESSION['hospitality_recipe_import']);
    }
}
?>
<div class="recipe-import-toolbar">
 <details class="recipe-upload-menu">
  <summary class="button secondary"><?= icon('upload') ?>Upload recipes</summary>
  <div class="recipe-upload-panel">
   <form method="post" enctype="multipart/form-data" class="recipe-upload-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="recipe_import_preview">
    <label>Recipe file (.xlsx or .csv, max 5 MB)<input type="file" name="recipe_file" accept=".xlsx,.csv" required></label>
    <button type="submit" class="button"><?= icon('upload') ?>Upload &amp; Preview</button>
   </form>
   <div class="actions">
    <?php if ($hasXlsx): ?><a class="button secondary" href="<?= e(url('admin/hospitality.php?view=recipes&recipe_template=xlsx')) ?>">Excel template</a><?php endif; ?>
    <a class="button secondary" href="<?= e(url('admin/hospitality.php?view=recipes&recipe_template=csv')) ?>">CSV template</a>
   </div>
   <p class="muted">One ingredient per row. Repeat recipe details on each row. Use existing menu and ingredient codes; quantities use the ingredient's recipe unit. Dates are AD. New codes only; existing recipes stay unchanged.</p>
  </div>
 </details>
</div>
<?php if ($recipeImportError): ?><div class="notice error"><?= e($recipeImportError) ?></div><?php endif; ?>
<?php if ($recipeImportPreview): ?>
<section class="mbw-card recipe-import-preview">
 <div class="mbw-card-head"><h2>Recipe upload preview</h2><span><?= count($recipeImportPreview) ?> draft(s)</span></div>
 <?php foreach($recipeImportPreview as $recipe): $h=$recipe['header']; ?>
 <details class="recipe-preview-item" open>
  <summary><?= e($h['code'].' — '.$h['name']) ?></summary>
  <dl class="recipe-preview-meta">
   <div><dt>Menu code</dt><dd><?= e($h['menu_code']) ?></dd></div>
   <div><dt>Effective dates</dt><dd><?= e(app_date($h['effective_from'])) ?><?= $h['effective_to'] ? ' — '.e(app_date($h['effective_to'])) : '' ?></dd></div>
   <div><dt>Batch yield / portions</dt><dd><?= e($h['yield_qty'].' / '.$h['portions']) ?></dd></div>
   <div><dt>Portion size</dt><dd><?= e($h['portion_size'] ?: '—') ?></dd></div>
   <div><dt>Preparation wastage</dt><dd><?= e($h['prep_wastage_pct']) ?>%</dd></div>
   <div><dt>Packaging / other cost</dt><dd><?= e(number_format($h['packaging_cost'],2).' / '.number_format($h['other_cost'],2)) ?></dd></div>
  </dl>
  <?php if($h['notes']!==''): ?><p><?= e($h['notes']) ?></p><?php endif; ?>
  <div class="mbw-tablewrap"><table><thead><tr><th>Ingredient code</th><th>Quantity</th><th>Recipe unit</th><th>Notes</th></tr></thead><tbody>
   <?php foreach($recipe['lines'] as $line): ?><tr><td><?= e($line['code']) ?></td><td><?= e(number_format($line['quantity'],4)) ?></td><td><?= e($line['unit']) ?></td><td><?= e($line['notes']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
 </details>
 <?php endforeach; ?>
 <form method="post" class="actions">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="import_token" value="<?= e($held['token']) ?>">
  <button type="submit" class="button" name="action" value="recipe_import_confirm">Confirm import as drafts</button>
  <button type="submit" class="button secondary" name="action" value="recipe_import_cancel">Cancel</button>
 </form>
</section>
<?php endif; ?>
