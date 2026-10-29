<?php

declare(strict_types=1);

namespace LaravelProdGuard\Tests;

use LaravelProdGuard\Checks;
use LaravelProdGuard\EnvFile;
use LaravelProdGuard\Finding;
use LaravelProdGuard\Project;
use PHPUnit\Framework\TestCase;

final class ChecksTest extends TestCase
{
    private static function fixture(string $name): Project
    {
        return Project::load(__DIR__ . "/fixtures/{$name}", $name);
    }

    /**
     * @param list<Finding> $findings
     * @return list<string>
     */
    private static function ids(array $findings): array
    {
        return array_map(static fn (Finding $f): string => "{$f->checkId}/{$f->severity->value}/{$f->target}", $findings);
    }

    public function testFreshProject(): void
    {
        // What composer create-project leaves: .env copied from .env.example plus a generated key.
        $ids = self::ids((new Checks())->run(self::fixture('fresh')));
        self::assertSame([
            'LPG001/high/APP_DEBUG',
            'LPG002/medium/APP_ENV',
            'LPG009/medium/SESSION_SECURE_COOKIE',
            'LPG012/low/LOG_LEVEL',
        ], $ids);
    }

    public function testFreshFindingsPointAtTheEnvFile(): void
    {
        $f = (new Checks())->run(self::fixture('fresh'));
        self::assertSame('fresh/.env', $f[0]->file);
        self::assertSame(4, $f[0]->line);
        self::assertSame('fresh/config/session.php', $f[2]->file);
        self::assertSame(172, $f[2]->line);
    }

    public function testHardenedProject(): void
    {
        self::assertSame([], self::ids((new Checks())->run(self::fixture('hardened'))));
    }

    public function testRiskyProject(): void
    {
        $ids = self::ids((new Checks())->run(self::fixture('risky')));
        foreach ([
            'LPG001/high/APP_DEBUG',
            'LPG002/medium/APP_ENV',
            'LPG003/high/APP_KEY',
            'LPG004/high/APP_KEY',
            'LPG005/high/laravel/telescope',
            'LPG005/high/laravel/horizon',
            'LPG006/medium/barryvdh/laravel-debugbar',
            'LPG007/high/cors',
            'LPG008/medium/trustProxies',
            'LPG009/medium/SESSION_SECURE_COOKIE',
            'LPG010/medium/SESSION_HTTP_ONLY',
            'LPG011/low/SESSION_SAME_SITE',
            'LPG012/low/LOG_LEVEL',
            'LPG013/medium/APP_URL',
            'LPG014/low/BCRYPT_ROUNDS',
            'LPG015/high/public/.env',
            'LPG015/high/public/info.php',
            'LPG016/medium/DB_PASSWORD',
            'LPG017/low/SESSION_DRIVER',
        ] as $want) {
            self::assertContains($want, $ids);
        }
        self::assertCount(19, $ids);
    }

    public function testRiskyDetails(): void
    {
        $by = [];
        foreach ((new Checks())->run(self::fixture('risky')) as $f) {
            $by[$f->checkId . ' ' . $f->target] = $f;
        }
        self::assertSame('APP_KEY decodes to 9 bytes, AES-256-CBC needs 32 bytes', $by['LPG003 APP_KEY']->message);
        self::assertSame('risky/config/cors.php', $by['LPG007 cors']->file);
        self::assertSame(22, $by['LPG007 cors']->line);
        self::assertSame('risky/bootstrap/app.php', $by['LPG008 trustProxies']->file);
        self::assertSame(15, $by['LPG008 trustProxies']->line);
        self::assertSame(185, $by['LPG010 SESSION_HTTP_ONLY']->line);
        self::assertSame(6, $by['LPG006 barryvdh/laravel-debugbar']->line);
    }

    public function testKeyReusedFromExample(): void
    {
        $key = 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=';
        $p = new Project('/x', 'x', EnvFile::parse("APP_KEY={$key}\n"), EnvFile::parse("APP_KEY={$key}\n", '.env.example'), ['require' => ['laravel/framework' => '^13']], null, [], ['.env']);
        $f = array_values(array_filter((new Checks())->run($p), static fn (Finding $f): bool => $f->checkId === 'LPG004'));
        self::assertCount(2, $f);
        self::assertSame('The APP_KEY in use is also written in .env.example', $f[0]->message);
        self::assertSame('.env is tracked by git', $f[1]->message);
    }

    public function testCorsWithoutCredentialsIsFine(): void
    {
        $cors = "<?php\nreturn [\n    'allowed_origins' => ['*'],\n    'supports_credentials' => false,\n];\n";
        $p = (new Project('/x', 'x', null, null, [], null, [], null))->withConfigs(['cors' => $cors]);
        self::assertNotContains('LPG007', array_map(static fn (Finding $f): string => $f->checkId, (new Checks())->run($p)));
    }

    public function testDevOnlyDebugbarIsFine(): void
    {
        $p = new Project('/x', 'x', null, null, ['require-dev' => ['barryvdh/laravel-debugbar' => '^4']], null, [], null);
        self::assertNotContains('LPG006', array_map(static fn (Finding $f): string => $f->checkId, (new Checks())->run($p)));
    }

    public function testRulesHaveReferencesOnlyToPinnedSources(): void
    {
        foreach (Checks::ALL as $id => [, $title, $description, $refs]) {
            self::assertNotSame('', $title, $id);
            self::assertNotSame('', $description, $id);
            foreach ($refs as $ref) {
                self::assertMatchesRegularExpression('#^https://github\.com/[\w.-]+/[\w.-]+/blob/v[\d.]+/#', $ref, $id);
            }
        }
    }
}
