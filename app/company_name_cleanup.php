<?php
declare(strict_types=1);

/** Deployment-only cleanup; retain original names and scoped print names for recovery. */
function company_name_cleanup(PDO $pdo): int
{
    $count = 0;
    $pdo->beginTransaction();
    try {
        $companies = $pdo->query('SELECT id,name FROM companies ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($companies as $company) {
            $id = (int)$company['id'];
            $cleanName = statement_company_name($company['name']);
            $settings = $pdo->prepare("SELECT setting_key,setting_value FROM settings WHERE company_id = ? AND setting_key IN ('site_name','company_name')");
            $settings->execute([$id]);
            $originalSettings = $settings->fetchAll(PDO::FETCH_KEY_PAIR);
            $changedSettings = [];
            foreach ($originalSettings as $key => $value) {
                $clean = statement_company_name($value);
                if ($clean !== '' && $clean !== $value) $changedSettings[$key] = $clean;
            }
            if (($cleanName === '' || $cleanName === $company['name']) && !$changedSettings) continue;
            $backup = $pdo->prepare("SELECT setting_value FROM settings WHERE company_id = ? AND setting_key = 'company_name_before_cleanup'");
            $backup->execute([$id]);
            if ($backup->fetchColumn() === false) {
                $pdo->prepare("INSERT INTO settings (company_id,setting_key,setting_value) VALUES (?, 'company_name_before_cleanup', ?)")
                    ->execute([$id,json_encode(['name'=>$company['name'],'settings'=>$originalSettings],JSON_THROW_ON_ERROR)]);
            }
            if ($cleanName !== '' && $cleanName !== $company['name']) {
                $pdo->prepare('UPDATE companies SET name = ? WHERE id = ? AND name = ?')->execute([$cleanName,$id,$company['name']]);
            }
            foreach ($changedSettings as $key => $value) {
                $pdo->prepare('UPDATE settings SET setting_value = ? WHERE company_id = ? AND setting_key = ? AND setting_value = ?')
                    ->execute([$value,$id,$key,$originalSettings[$key]]);
            }
            $count++;
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
