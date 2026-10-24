<?php
/**
 * DNA SSL for WHMCS - per-service certificate state.
 *
 * WHMCS has nowhere to keep a CSR, the DCV choice or a generated private key on a
 * service, so they live in one small table keyed by the service id. The table is
 * created on first use; there is no activation step to forget.
 */

use WHMCS\Database\Capsule;

class DnaSsl_Store
{
    const TABLE = 'mod_dnassl';

    /** Columns a caller may write; anything else is dropped. */
    const COLUMNS = [
        'certificate_id', 'domain', 'package', 'status', 'configured', 'csr', 'dcv_method',
        'approver', 'web_server', 'san_list', 'private_key',
    ];

    public static function ensureTable(): void
    {
        static $done = false;
        if ($done) return;

        $schema = Capsule::schema();
        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($t) {
                $t->unsignedInteger('service_id')->primary();
                $t->string('certificate_id', 64)->default('');
                $t->string('domain', 255)->default('');
                $t->string('package', 128)->default('');
                $t->string('status', 32)->default('');
                $t->boolean('configured')->default(false);
                $t->text('csr')->nullable();
                $t->string('dcv_method', 16)->default('');
                $t->string('approver', 255)->default('');
                $t->string('web_server', 32)->default('');
                $t->text('san_list')->nullable();
                // encrypt()ed with the WHMCS system key; only set when we generated the CSR.
                $t->text('private_key')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        $done = true;
    }

    public static function get(int $serviceId): array
    {
        self::ensureTable();
        $row = Capsule::table(self::TABLE)->where('service_id', $serviceId)->first();

        return $row ? (array) $row : [];
    }

    public static function save(int $serviceId, array $fields): void
    {
        self::ensureTable();

        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        $fields['updated_at'] = date('Y-m-d H:i:s');

        if (Capsule::table(self::TABLE)->where('service_id', $serviceId)->exists()) {
            Capsule::table(self::TABLE)->where('service_id', $serviceId)->update($fields);
        } else {
            Capsule::table(self::TABLE)->insert(['service_id' => $serviceId] + $fields);
        }
    }

    public static function privateKey(array $row): string
    {
        $stored = (string) ($row['private_key'] ?? '');

        return $stored !== '' ? (string) decrypt($stored) : '';
    }
}
