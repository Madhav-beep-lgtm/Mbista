<?php
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/../public_html/admin/workspace.php');
foreach (['view=service-providers','Service Provider Entity Management',"INSERT INTO service_provider_entities","UPDATE service_provider_entities"] as $removed) {
    if (str_contains($source,$removed)) throw new RuntimeException('Retired management still exposed: ' . $removed);
}
if (!str_contains($source,"deny_access('Service provider management is unavailable.')")) throw new RuntimeException('Legacy POST must be blocked.');
if (!str_contains($source,'client_service_provider_ids($clientId)')) throw new RuntimeException('Existing task linkage must remain intact.');
echo "Service provider management: navigation, page and writes retired; task linkage retained.\n";
