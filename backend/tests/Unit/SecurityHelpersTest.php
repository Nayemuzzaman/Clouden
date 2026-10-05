<?php

namespace Tests\Unit;

use App\Services\Audit\AuditLogger;
use App\Services\Databases\ColumnType;
use App\Services\Databases\DestructiveQueryDetector;
use App\Services\Databases\Identifier;
use App\Services\Deployment\ImageReference;
use App\Services\Domains\DomainValidator;
use App\Services\Environment\DotenvParser;
use App\Services\Environment\LogRedactor;
use App\Services\Source\GitRefs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityHelpersTest extends TestCase
{
    public function test_identifier_quoting_escapes_quotes(): void
    {
        $this->assertSame('"users"', Identifier::quote('users'));
        $this->assertSame('"we""ird"', Identifier::quote('we"ird'));
        $this->assertSame('"public"."Order"', Identifier::qualified('public', 'Order'));
    }

    public function test_managed_names_are_strict(): void
    {
        $this->assertTrue(Identifier::isValidManagedName('japanlingo'));
        $this->assertTrue(Identifier::isValidManagedName('app_2'));
        foreach (['Japan', '1app', 'a-b', 'postgres', 'template1', 'pg_shadow', 'a;drop', '', str_repeat('a', 64)] as $bad) {
            $this->assertFalse(Identifier::isValidManagedName($bad), $bad);
        }
        $this->assertSame('my_shop', Identifier::fromSlug('my-shop'));
        $this->assertSame('db_2fast', Identifier::fromSlug('2fast'));
    }

    public function test_column_types_are_allow_listed(): void
    {
        $this->assertTrue(ColumnType::isAllowed('varchar(255)'));
        $this->assertTrue(ColumnType::isAllowed('numeric(10,2)'));
        $this->assertTrue(ColumnType::isAllowed('text[]'));
        $this->assertFalse(ColumnType::isAllowed('text; drop table users'));
        $this->assertFalse(ColumnType::isAllowed('int default 1'));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function sqlProvider(): array
    {
        return [
            'select' => ['SELECT * FROM users', false],
            'delete with where' => ['DELETE FROM users WHERE id = 1', false],
            'delete without where' => ['delete from users', true],
            'update without where' => ['UPDATE users SET admin = true', true],
            'update with where' => ['UPDATE users SET admin = true WHERE id = 2', false],
            'drop' => ['DROP TABLE users', true],
            'truncate' => ['truncate users', true],
            'alter' => ['ALTER TABLE users ADD COLUMN x int', true],
            'second statement' => ['SELECT 1; DROP TABLE x;', true],
            'keyword in string' => ["SELECT 'DROP TABLE users'", false],
            'keyword in comment' => ["SELECT 1 -- DROP TABLE users\n", false],
            'where only in string' => ["DELETE FROM logs WHERE note = 'x'", false],
            'where hidden in string' => ["DELETE FROM logs -- WHERE\n", true],
            'dollar quoted' => ['SELECT $$DROP TABLE x$$', false],
            'grant' => ['GRANT ALL ON users TO bob', true],
            'cte delete' => ['WITH x AS (SELECT 1) DELETE FROM users', true],
        ];
    }

    #[DataProvider('sqlProvider')]
    public function test_destructive_query_detection(string $sql, bool $destructive): void
    {
        $this->assertSame($destructive, DestructiveQueryDetector::analyze($sql) !== [], $sql);
    }

    public function test_git_ref_validation(): void
    {
        $this->assertTrue(GitRefs::isValidBranch('main'));
        $this->assertTrue(GitRefs::isValidBranch('feature/new-ui_2'));
        foreach (['-upload-pack', '--exec=x', 'a..b', 'a b', 'a;b', 'refs@{1}', 'x.lock', '/abs', 'trailing/', '$(id)'] as $bad) {
            $this->assertFalse(GitRefs::isValidBranch($bad), $bad);
        }
        $this->assertTrue(GitRefs::isValidFullName('acme/shop.web'));
        $this->assertFalse(GitRefs::isValidFullName('acme/../etc'));
        $this->assertFalse(GitRefs::isValidFullName('acme'));
        $this->assertSame('acme/shop', GitRefs::githubFullNameFromUrl('https://github.com/acme/shop.git'));
    }

    public function test_git_url_validation_blocks_ssrf_and_unsafe_schemes(): void
    {
        $this->assertNull(GitRefs::validateGitUrl('https://8.8.8.8/repo.git', false));
        $this->assertNotNull(GitRefs::validateGitUrl('http://8.8.8.8/repo.git', false));
        $this->assertNotNull(GitRefs::validateGitUrl('https://127.0.0.1/repo.git', false));
        $this->assertNotNull(GitRefs::validateGitUrl('https://10.0.0.5/repo.git', false));
        $this->assertNotNull(GitRefs::validateGitUrl('https://169.254.169.254/latest', false));
        $this->assertNotNull(GitRefs::validateGitUrl('file:///etc/passwd', false));
        $this->assertNotNull(GitRefs::validateGitUrl('ext::sh -c id', false));
        $this->assertNotNull(GitRefs::validateGitUrl('https://u:p@8.8.8.8/r.git', false));
        $this->assertNotNull(GitRefs::validateGitUrl('https://8.8.8.8:22/r.git', false));
        $this->assertNull(GitRefs::validateGitUrl('http://gitfixture:8000/app.git', true), 'allowed in development mode');
    }

    public function test_domain_normalization(): void
    {
        $this->assertSame('app.example.com', DomainValidator::normalize(' HTTPS://App.Example.COM/ '));
        $this->assertSame('xn--bcher-kva.example.com', DomainValidator::normalize('bücher.example.com'));
        foreach (['', 'example', '*.example.com', '1.2.3.4', 'a_b.example.com', 'example.com/path', 'ex ample.com', "app.example.com\nimport evil", 'app.example.com {'] as $bad) {
            $this->assertNull(DomainValidator::normalize($bad), $bad);
        }
        $this->assertFalse(DomainValidator::isSafeHostname('evil.com { respond 200 }'));
    }

    public function test_image_reference_validation(): void
    {
        foreach (['nginx', 'nginx:1.27', 'ghcr.io/owner/app:latest', 'registry:5000/team/app@sha256:'.str_repeat('a', 64)] as $ok) {
            $this->assertTrue(ImageReference::isValid($ok), $ok);
        }
        foreach (['Nginx', 'nginx:', 'nginx;rm -rf', '--privileged'] as $bad) {
            $this->assertFalse(ImageReference::isValid($bad), $bad);
        }
        $this->assertSame(['ghcr.io/owner/app', 'v2'], ImageReference::split('ghcr.io/owner/app:v2'));
        $this->assertSame(['registry:5000/app', 'latest'], ImageReference::split('registry:5000/app'));
    }

    public function test_dotenv_parser(): void
    {
        $parsed = DotenvParser::parse("A=1\nB='single # not comment'\nC=\"double \\\"quoted\\\"\"\nD=value # comment\nexport E=e\n\n# x\nF=\nG=\"multi\nline\"\n");
        $this->assertSame(['A' => '1', 'B' => 'single # not comment', 'C' => 'double "quoted"', 'D' => 'value', 'E' => 'e', 'F' => '', 'G' => "multi\nline"], $parsed['variables']);
        $this->assertSame([], $parsed['errors']);
    }

    public function test_log_redactor(): void
    {
        $redactor = new LogRedactor(['hunter22', 'sk_live_123']);
        $this->assertSame('pass=[secret] key=[secret]', $redactor->redact('pass=hunter22 key=sk_live_123'));
    }

    public function test_audit_metadata_is_scrubbed(): void
    {
        $scrubbed = (new AuditLogger)->scrub(['key' => 'APP_KEY', 'value' => 'secret', 'nested' => ['password' => 'x', 'name' => 'ok'], 'token' => 't']);
        $this->assertSame(['key' => 'APP_KEY', 'value' => '[redacted]', 'nested' => ['password' => '[redacted]', 'name' => 'ok'], 'token' => '[redacted]'], $scrubbed);
    }
}
