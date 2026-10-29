<?php

declare(strict_types=1);

namespace LaravelProdGuard\Tests;

use LaravelProdGuard\Cli;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private static function cli(array $args, ?string $cwd = null): array
    {
        $out = '';
        $err = '';
        $code = Cli::run($args, static function (string $s) use (&$out): void {
            $out .= $s;
        }, static function (string $s) use (&$err): void {
            $err .= $s;
        }, $cwd ?? __DIR__ . '/fixtures');

        return [$code, $out, $err];
    }

    public function testFreshFailsOnHigh(): void
    {
        [$code, $out] = self::cli(['fresh']);
        self::assertSame(1, $code);
        self::assertStringContainsString('HIGH   LPG001  fresh/.env:4  APP_DEBUG is true', $out);
        self::assertStringContainsString('1 app(s), 4 finding(s): 1 high, 2 medium, 1 low', $out);
    }

    public function testHardenedPasses(): void
    {
        [$code, $out] = self::cli(['hardened', '--fail-on', 'low']);
        self::assertSame(0, $code);
        self::assertStringContainsString('No problems found.', $out);
    }

    public function testEnvOverride(): void
    {
        // The fresh app's code checked against the hardened production values.
        [$code, $out] = self::cli(['fresh', '--env', 'hardened/.env', '--fail-on', 'low']);
        self::assertSame(0, $code, $out);
    }

    public function testFixturesAreSkippedWhenSearching(): void
    {
        [$code, , $err] = self::cli(['.'], __DIR__);
        self::assertSame(2, $code);
        self::assertStringContainsString('no Laravel application found', $err);
    }

    /** @param list<int|string> $path */
    private static function dig(mixed $v, array $path): mixed
    {
        foreach ($path as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return null;
            }
            $v = $v[$k];
        }

        return $v;
    }

    public function testSarif(): void
    {
        [, $out] = self::cli(['risky', '--format', 'sarif', '--fail-on', 'none']);
        $sarif = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.1.0', self::dig($sarif, ['version']));
        self::assertSame('laravel-prod-guard', self::dig($sarif, ['runs', 0, 'tool', 'driver', 'name']));
        $rules = self::dig($sarif, ['runs', 0, 'tool', 'driver', 'rules']);
        self::assertIsArray($rules);
        self::assertCount(17, $rules);
        $results = self::dig($sarif, ['runs', 0, 'results']);
        self::assertIsArray($results);
        self::assertCount(19, $results);
        foreach ($results as $r) {
            self::assertGreaterThanOrEqual(1, self::dig($r, ['locations', 0, 'physicalLocation', 'region', 'startLine']));
        }
    }

    public function testJsonSummary(): void
    {
        [, $out] = self::cli(['risky', '--format', 'json', '--fail-on', 'none']);
        $json = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['high' => 8, 'medium' => 7, 'low' => 4], self::dig($json, ['summary']));
    }

    public function testBadArguments(): void
    {
        self::assertSame(2, self::cli(['--format', 'xml'])[0]);
        self::assertSame(2, self::cli(['--fail-on', 'critical'])[0]);
        self::assertSame(2, self::cli(['--nope'])[0]);
        self::assertSame(2, self::cli(['fresh', '--env', 'missing.env'])[0]);
        self::assertSame(2, self::cli(['fresh', 'risky', '--env', 'hardened/.env'])[0]);
    }

    public function testNoEnvFileIsANote(): void
    {
        $dir = sys_get_temp_dir() . '/lpg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/composer.json", '{"require":{"laravel/framework":"^13"}}');
        try {
            [$code, $out, $err] = self::cli(['.', '--fail-on', 'none'], $dir);
            self::assertSame(0, $code);
            self::assertStringContainsString('has no .env', $err);
            // With nothing set, the framework defaults apply: debug off, production, no key.
            self::assertStringContainsString('LPG003', $out);
            self::assertStringNotContainsString('LPG001', $out);
        } finally {
            unlink("{$dir}/composer.json");
            rmdir($dir);
        }
    }

    public function testListChecks(): void
    {
        [$code, $out] = self::cli(['--list-checks']);
        self::assertSame(0, $code);
        self::assertSame(17, substr_count($out, "\nLPG") + (str_starts_with($out, 'LPG') ? 1 : 0));
    }
}
