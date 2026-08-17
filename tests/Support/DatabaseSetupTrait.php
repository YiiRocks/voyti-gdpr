<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Composer\InstalledVersions;
use M240118192500CreateAssignmentsTable;
use M240118192500CreateItemsTables;
use M260621101843_create_user_module_tables;
use M260621102500_create_user_social_account_table;
use Psr\SimpleCache\CacheInterface;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Db\Sqlite\Dsn;

require_once dirname(__DIR__, 2) . '/vendor/yiisoft/rbac-db/migrations/items/M240118192500CreateItemsTables.php';
require_once dirname(__DIR__, 2) . '/vendor/yiisoft/rbac-db/migrations/assignments/M240118192500CreateAssignmentsTable.php';
require_once InstalledVersions::getInstallPath('yiirocks/voyti') . '/migrations/M260621101843_create_user_module_tables.php';
require_once InstalledVersions::getInstallPath('yiirocks/voyti-social-auth') . '/migrations/M260621102500_create_user_social_account_table.php';

trait DatabaseSetupTrait
{
    private ?ConnectionInterface $dbConnection = null;

    protected function runMigrations(): void
    {
        $builder = new MigrationBuilder($this->dbConnection, new NullMigrationInformer());

        (new M240118192500CreateItemsTables())->up($builder);
        (new M240118192500CreateAssignmentsTable())->up($builder);

        $config = VoytiConfigFactory::create();
        $migration = new M260621101843_create_user_module_tables($config, TestPasswordHasherFactory::create());
        ob_start();
        $migration->up($builder);
        ob_end_clean();

        (new M260621102500_create_user_social_account_table())->up($builder);

        $this->removeSeededAdmin($config);
    }

    protected function setUpDatabase(): void
    {
        $dsn = new Dsn('sqlite', ':memory:');
        $driver = new Driver($dsn);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willReturn(true);
        $cache->method('get')->willReturn(null);
        $schemaCache = new SchemaCache($cache);
        $schemaCache->setEnabled(false);
        $connection = new SqliteConnection($driver, $schemaCache);
        ConnectionProvider::set($connection);
        $this->dbConnection = $connection;

        $this->runMigrations();
    }

    protected function tearDownDatabase(): void
    {
        if ($this->dbConnection !== null) {
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "yii_rbac_assignment"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "yii_rbac_item_child"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "yii_rbac_item"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_audit_log"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_password_history"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_sessions"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_token"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_social_account"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_profile"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user"')->execute();
        }
        ConnectionProvider::clear();
        $this->dbConnection = null;
    }

    private function removeSeededAdmin(VoytiConfig $config): void
    {
        $this->dbConnection->createCommand(
            'DELETE FROM {{%yii_rbac_assignment}} WHERE item_name = :name',
            ['name' => M260621101843_create_user_module_tables::ROLE_NAME],
        )->execute();
        $this->dbConnection->createCommand(
            'DELETE FROM {{%yii_rbac_item_child}} WHERE parent = :name',
            ['name' => M260621101843_create_user_module_tables::ROLE_NAME],
        )->execute();
        $this->dbConnection->createCommand(
            'DELETE FROM {{%yii_rbac_item}} WHERE name = :name',
            ['name' => M260621101843_create_user_module_tables::ROLE_NAME],
        )->execute();
        $this->dbConnection->createCommand(
            'DELETE FROM {{%yii_rbac_item}} WHERE name = :name',
            ['name' => $config->administratorPermissionName],
        )->execute();
        $this->dbConnection->createCommand(
            'DELETE FROM {{%user_profile}} WHERE user_id IN (SELECT id FROM {{%user}} WHERE email = :email)',
            ['email' => M260621101843_create_user_module_tables::SEED_EMAIL],
        )->execute();
        $this->dbConnection->createCommand(
            'DELETE FROM {{%user}} WHERE email = :email',
            ['email' => M260621101843_create_user_module_tables::SEED_EMAIL],
        )->execute();
        $this->dbConnection->createCommand('DELETE FROM sqlite_sequence WHERE name = :name', ['name' => 'user'])->execute();
    }
}
