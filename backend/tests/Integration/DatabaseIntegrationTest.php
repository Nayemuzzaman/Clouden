<?php

namespace Tests\Integration;

use App\Enums\JobStatus;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\ProjectDatabase;
use App\Services\Databases\DatabaseConnector;
use App\Services\Databases\DatabaseException;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\PostgresProvisioner;
use App\Services\Databases\TableBrowser;
use App\Services\Process\CommandRunner;
use App\Services\Process\SymfonyCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Runs against a real PostgreSQL server. Enabled when PC_TEST_APPS_DB_HOST is set, e.g.:
 *   docker run -d --name privatecloud-test-pg -e POSTGRES_PASSWORD=testadminpw -p 127.0.0.1:15499:5432 postgres:17-alpine
 *   PC_TEST_APPS_DB_HOST=127.0.0.1 PC_TEST_APPS_DB_PORT=15499 PC_TEST_APPS_DB_PASSWORD=testadminpw php artisan test --testsuite=Integration
 */
class DatabaseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $host;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('PC_TEST_APPS_DB_HOST')) {
            $this->markTestSkipped('PC_TEST_APPS_DB_HOST is not set.');
        }
        $this->host = (string) getenv('PC_TEST_APPS_DB_HOST');
        $this->port = (int) (getenv('PC_TEST_APPS_DB_PORT') ?: 5432);
        config([
            'privatecloud.apps_db.host' => $this->host,
            'privatecloud.apps_db.port' => $this->port,
            'privatecloud.apps_db.admin_password' => getenv('PC_TEST_APPS_DB_PASSWORD'),
        ]);
        // Real pg_dump / pg_restore for the backup round trip.
        $this->app->instance(CommandRunner::class, new SymfonyCommandRunner);

        foreach (['it_shop', 'it_blog'] as $name) {
            app(PostgresProvisioner::class)->drop($name, $name);
        }
    }

    private function connect(string $database, string $user, string $password): PDO
    {
        return new PDO("pgsql:host={$this->host};port={$this->port};dbname={$database};connect_timeout=3", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function create(string $name): ProjectDatabase
    {
        $database = app(DatabaseService::class)->create($name);
        $database->forceFill(['port' => $this->port])->save();

        return $database;
    }

    public function test_each_database_is_isolated_with_its_own_role(): void
    {
        $shop = $this->create('it_shop');
        $blog = $this->create('it_blog');
        $this->assertSame('ready', $shop->status);

        $pdo = $this->connect('it_shop', 'it_shop', $shop->password);
        $pdo->exec('CREATE TABLE t (id int)');
        $this->assertSame('it_shop', $pdo->query('SELECT current_user')->fetchColumn());
        $this->assertFalse((bool) $pdo->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn());

        foreach (['it_blog', 'postgres'] as $other) {
            try {
                $this->connect($other, 'it_shop', $shop->password);
                $this->fail("it_shop could connect to {$other}");
            } catch (PDOException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
        $this->assertNotSame($shop->password, $blog->password);
        $this->assertGreaterThanOrEqual(32, strlen($shop->password));
    }

    public function test_reprovision_recreates_missing_databases_idempotently(): void
    {
        $shop = $this->create('it_shop');
        app(PostgresProvisioner::class)->drop('it_shop', 'it_shop'); // simulate a new, empty server

        $this->artisan('privatecloud:reprovision-databases')->expectsOutputToContain('created')->assertSuccessful();
        $this->connect('it_shop', 'it_shop', $shop->password)->query('SELECT 1');

        $this->artisan('privatecloud:reprovision-databases')->expectsOutputToContain('already existed')->assertSuccessful();
    }

    public function test_reset_credentials_and_delete(): void
    {
        $shop = $this->create('it_shop');
        $old = $shop->password;
        $shop = app(DatabaseService::class)->resetCredentials($shop);

        $this->connect('it_shop', 'it_shop', $shop->password)->query('SELECT 1');
        try {
            $this->connect('it_shop', 'it_shop', $old);
            $this->fail('old password still works');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }

        app(DatabaseService::class)->delete($shop);
        $this->assertFalse(app(PostgresProvisioner::class)->exists('it_shop'));
    }

    public function test_table_browser_end_to_end(): void
    {
        $db = $this->create('it_shop');
        $browser = new TableBrowser(app(DatabaseConnector::class)->pdo($db));

        $browser->createTable('public', 'customers', [
            ['name' => 'id', 'type' => 'bigserial', 'primary' => true],
            ['name' => 'name', 'type' => 'text', 'nullable' => false],
            ['name' => 'email', 'type' => 'varchar(255)'],
            ['name' => 'active', 'type' => 'boolean', 'default_expression' => 'true'],
            ['name' => 'created_at', 'type' => 'timestamptz', 'default_expression' => 'now()'],
        ]);
        $this->assertSame(['customers'], array_column($browser->tables('public'), 'name'));

        $row = $browser->insert('public', 'customers', ['name' => 'Ada', 'email' => 'ada@example.com']);
        $browser->insert('public', 'customers', ['name' => "O'Brien", 'email' => null]);
        $this->assertTrue($row['active']);

        $described = $browser->describe('public', 'customers');
        $this->assertSame(['id'], $described['primary_key']);
        $this->assertSame('varchar(255)', $described['columns'][2]['type']);

        $page = $browser->rows('public', 'customers', sort: 'name', direction: 'desc');
        $this->assertSame(2, $page['total']);
        $this->assertSame("O'Brien", $page['rows'][0]['name']);

        $filtered = $browser->rows('public', 'customers', filters: [['column' => 'name', 'operator' => 'contains', 'value' => "o'b"]]);
        $this->assertSame(1, $filtered['total']);

        // Injection attempts are treated as plain values / unknown identifiers.
        $this->assertSame(0, $browser->rows('public', 'customers', filters: [['column' => 'name', 'operator' => 'eq', 'value' => "x' OR '1'='1"]])['total']);
        foreach ([fn () => $browser->rows('public', 'customers"; DROP TABLE customers; --'), fn () => $browser->rows('public', 'customers', sort: 'name; DROP TABLE customers')] as $attack) {
            try {
                $attack();
                $this->fail('identifier injection accepted');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, $browser->rows('public', 'customers')['total'], 'table intact');

        $updated = $browser->update('public', 'customers', ['id' => (string) $row['id']], ['email' => 'ada@new.example']);
        $this->assertSame('ada@new.example', $updated['email']);

        $browser->addColumn('public', 'customers', ['name' => 'score', 'type' => 'integer', 'default_value' => '0']);
        $browser->alterColumn('public', 'customers', 'score', ['rename_to' => 'points', 'nullable' => false]);
        $this->assertContains('points', array_column($browser->describe('public', 'customers')['columns'], 'name'));

        $browser->delete('public', 'customers', ['id' => (string) $row['id']]);
        $this->assertSame(1, $browser->rows('public', 'customers')['total']);

        $browser->dropTable('public', 'customers');
        $this->assertSame([], $browser->tables('public'));
    }

    public function test_row_added_through_api_exists_in_postgres(): void
    {
        $this->actingAsAdmin();
        $db = $this->create('it_shop');
        $this->connect('it_shop', 'it_shop', $db->password)->exec('CREATE TABLE notes (id serial primary key, body text)');

        $this->getJson("/api/v1/databases/{$db->uuid}/tables")->assertOk()->assertJsonPath('tables.0.name', 'notes');
        $this->postJson("/api/v1/databases/{$db->uuid}/tables/notes/rows", ['values' => ['body' => 'hello from the UI']])->assertCreated();

        $this->assertSame('hello from the UI', $this->connect('it_shop', 'it_shop', $db->password)->query('SELECT body FROM notes')->fetchColumn());
    }

    public function test_sql_editor(): void
    {
        $this->actingAsAdmin();
        $db = $this->create('it_shop');

        $this->postJson("/api/v1/databases/{$db->uuid}/sql", ['sql' => "CREATE TABLE a (id int); INSERT INTO a VALUES (1), (2); SELECT id, 'x' AS label FROM a ORDER BY id"])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('columns', ['id', 'label'])
            ->assertJsonPath('rows', [['1', 'x'], ['2', 'x']])
            ->assertJsonPath('statements', 3);

        $this->postJson("/api/v1/databases/{$db->uuid}/sql", ['sql' => 'SELECT * FROM missing_table'])
            ->assertOk()->assertJsonPath('success', false)
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'relation "missing_table" does not exist'));

        $this->postJson("/api/v1/databases/{$db->uuid}/sql", ['sql' => 'DROP TABLE a'])->assertStatus(409)->assertJsonPath('code', 'destructive_confirmation_required');
        $this->postJson("/api/v1/databases/{$db->uuid}/sql", ['sql' => 'DROP TABLE a', 'confirm_destructive' => true])->assertOk()->assertJsonPath('success', true);

        // The editor runs as the application role, not as a superuser.
        $this->postJson("/api/v1/databases/{$db->uuid}/sql", ['sql' => 'CREATE ROLE evil SUPERUSER'])->assertJsonPath('success', false);

        $this->getJson("/api/v1/databases/{$db->uuid}/sql/history")->assertOk()->assertJsonCount(4, 'data'); // the unconfirmed DROP never ran
    }

    public function test_database_backup_and_restore_round_trip(): void
    {
        $this->actingAsAdmin();
        $db = $this->create('it_shop');
        $pdo = $this->connect('it_shop', 'it_shop', $db->password);
        $pdo->exec("CREATE TABLE items (id int primary key, name text); INSERT INTO items VALUES (1, 'original')");
        $pdo = null;

        $this->postJson('/api/v1/backups', ['database' => $db->uuid])->assertStatus(202);
        $backup = Backup::query()->firstOrFail();
        $this->assertSame(JobStatus::Success, $backup->status, (string) $backup->error);

        $pdo = $this->connect('it_shop', 'it_shop', $db->password);
        $pdo->exec("UPDATE items SET name = 'changed'; CREATE TABLE extra (x int)");
        $pdo = null;

        $operation = $this->withConfirmedPassword()->postJson("/api/v1/backups/{$backup->uuid}/restore", ['confirm' => 'it_shop'])->assertStatus(202)->json('data.id');
        $op = Operation::query()->where('uuid', $operation)->firstOrFail();
        $this->assertSame(JobStatus::Success, $op->status, (string) $op->error);

        $pdo = $this->connect('it_shop', 'it_shop', $db->password);
        $this->assertSame('original', $pdo->query('SELECT name FROM items')->fetchColumn());
        $this->assertSame('it_shop', $pdo->query("SELECT tableowner FROM pg_tables WHERE tablename = 'items'")->fetchColumn(), 'restored objects owned by the app role');
        $this->assertSame(1, Backup::query()->where('trigger', 'pre_restore')->count());
    }
}
