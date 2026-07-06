<?php

$migrations = [
    '0001_01_01_000001_create_cache_table',
    '0001_01_01_000002_create_jobs_table',
    '2026_01_01_000002_create_companies_table',
    '2026_01_01_000003_create_employees_table',
    '2026_01_01_000004_create_gyms_table',
    '2026_01_01_000005_create_membership_plans_table',
    '2026_01_01_000006_create_memberships_table',
    '2026_01_01_000007_create_checkins_table',
    '2026_01_01_000008_create_subscriptions_table',
    '2026_01_01_000009_create_invoices_table',
    '2026_01_01_000010_create_wellness_programs_table',
    '2026_01_01_000011_create_employee_wellness_table',
    '2026_01_01_000012_create_appointments_table',
    '2026_01_01_000013_create_notifications_table',
    '2026_01_01_000014_create_audit_logs_table',
    '2026_01_01_000015_add_business_license_to_companies_table',
    '2026_01_01_000016_add_payment_method_to_companies_table',
    '2026_01_01_000017_add_employee_signup_fields',
    '2026_01_01_000018_create_partner_applications_table',
    '2026_01_01_000019_add_gym_partner_to_users_role_enum',
    '2026_01_01_000020_add_platinum_to_gyms_tier_enum',
    '2026_01_01_000021_change_tier_to_varchar_in_membership_plans',
    '2026_01_01_000022_add_payment_status_to_employees_table',
    '2026_01_01_000023_create_payment_methods_table',
    '2026_01_01_000024_create_billing_invoices_table',
    '2026_01_01_000025_create_billing_invoice_items_table',
    '2026_01_01_000026_create_billing_payments_table',
    '2026_01_01_000027_create_billing_negotiations_table',
    '2026_01_01_000028_add_status_fields_to_companies_table',
    '2026_01_01_000029_add_mobile_fields',
    '2026_01_01_000029_add_rbac_fields_to_users_table',
    '2026_01_01_000030_create_permissions_tables',
    '2026_01_01_000030_create_qr_tokens_table',
    '2026_01_01_000031_create_gym_staff_table',
    '2026_01_01_000031_expand_users_role_enum',
    '2026_01_01_000032_create_partner_payouts_table',
    '2026_01_01_000032_dynamic_roles',
    '2026_01_01_000033_add_member_role_and_mobile_subscriptions',
    '2026_01_01_000033_add_plans_permissions',
    '2026_01_01_000034_add_ban_fields_to_employees_table',
    '2026_01_01_000034_add_user_id_to_checkins',
    '2026_01_01_000035_add_admin_approval_to_employees',
    '2026_01_01_000036_create_admin_notifications_table',
    '2026_05_27_112342_add_max_checkins_to_membership_plans',
    '2026_05_29_114050_create_daily_gym_selections_table',
    '2026_06_10_000001_add_type_to_payment_methods_table',
    '2026_06_11_000001_create_gym_upgrade_requests_table',
    '2026_06_11_000002_replace_coarse_permissions_with_granular',
    '2026_06_11_100000_add_telegram_fields_to_users_table',
    '2026_06_17_072012_add_chapa_fields_to_billing_payments_table',
    '2026_06_20_181339_create_personal_access_tokens_table',
];

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=benefit', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ($migrations as $migration) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (migration, batch) VALUES (?, 1)");
    $stmt->execute([$migration]);
    echo "Inserted: $migration\n";
}

echo "\nDone! Now run: php artisan migrate --force\n";
