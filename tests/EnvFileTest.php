<?php

declare(strict_types=1);

namespace LaravelProdGuard\Tests;

use LaravelProdGuard\EnvFile;
use LaravelProdGuard\Project;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    public function testValuesAndLines(): void
    {
        $env = EnvFile::parse(<<<'ENV'
            # comment
            APP_NAME=Laravel
            export APP_ENV=production
            APP_DEBUG=false # trailing comment
            MAIL_FROM_NAME="${APP_NAME}"
            SINGLE='${APP_NAME} #not a comment'
            DOUBLE="a \"quoted\" value"
            MULTI="line one
            line two"
            AFTER=1
            EMPTY=
            ENV);

        self::assertSame('Laravel', $env->get('APP_NAME'));
        self::assertSame(2, $env->line('APP_NAME'));
        self::assertSame('production', $env->get('APP_ENV'));
        self::assertSame('false', $env->get('APP_DEBUG'));
        self::assertSame('Laravel', $env->get('MAIL_FROM_NAME'));
        self::assertSame('${APP_NAME} #not a comment', $env->get('SINGLE'));
        self::assertSame('a "quoted" value', $env->get('DOUBLE'));
        self::assertSame("line one\nline two", $env->get('MULTI'));
        self::assertSame(8, $env->line('MULTI'));
        self::assertSame(10, $env->line('AFTER'));
        self::assertSame('', $env->get('EMPTY'));
        self::assertFalse($env->has('MISSING'));
    }

    public function testUnknownVariablesExpandToEmpty(): void
    {
        self::assertSame('x--y', EnvFile::parse("A=x-\${NOPE}-y\n")->get('A'));
    }

    public function testSettingOrder(): void
    {
        $session = <<<'PHP'
            <?php
            return [
                // 'secure' => true,
                'secure' => env('SESSION_SECURE_COOKIE'),
                'same_site' => env('SESSION_SAME_SITE', 'lax'),
                'http_only' => false,
            ];
            PHP;
        $env = EnvFile::parse("SESSION_SAME_SITE=none\n", '.env');
        $p = (new Project('/x', 'x', $env, null, [], null, [], null))->withConfigs(['session' => $session]);

        $secure = $p->setting('session', 'secure', 'SESSION_SECURE_COOKIE', null);
        self::assertNull($secure->value);
        self::assertSame('config/session.php', $secure->file);
        self::assertSame(4, $secure->line);
        self::assertFalse($secure->explicit);

        $sameSite = $p->setting('session', 'same_site', 'SESSION_SAME_SITE', 'lax');
        self::assertSame('none', $sameSite->value);
        self::assertSame('.env', $sameSite->file);
        self::assertTrue($sameSite->explicit);

        $httpOnly = $p->setting('session', 'http_only', 'SESSION_HTTP_ONLY', 'true');
        self::assertSame('false', $httpOnly->value);
        self::assertTrue($httpOnly->explicit);

        // Config not published: env, then the framework default.
        $debug = $p->setting('app', 'debug', 'APP_DEBUG', 'false');
        self::assertSame('false', $debug->value);
        self::assertSame('', $debug->file);
    }

    public function testCastAndDefaultInConfig(): void
    {
        $app = "<?php\nreturn [\n    'debug' => (bool) env('APP_DEBUG', true),\n];\n";
        $p = (new Project('/x', 'x', null, null, [], null, [], null))->withConfigs(['app' => $app]);
        $s = $p->setting('app', 'debug', 'APP_DEBUG', 'false');
        self::assertSame('true', $s->value);
        self::assertSame(3, $s->line);
    }

    public function testNormalize(): void
    {
        self::assertSame('true', Project::normalize('(true)'));
        self::assertSame('false', Project::normalize('FALSE'));
        self::assertNull(Project::normalize('null'));
        self::assertSame('', Project::normalize('empty'));
        self::assertSame('base64:abc', Project::normalize('base64:abc'));
    }
}
