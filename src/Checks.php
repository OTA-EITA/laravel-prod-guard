<?php

declare(strict_types=1);

namespace LaravelProdGuard;

final class Checks
{
    private const SK = 'https://github.com/laravel/laravel/blob/v13.11.0/';
    private const FW = 'https://github.com/laravel/framework/blob/v13.39.0/';

    /** Debug toolbars and error pages that should only be dev dependencies. */
    public const DEBUG_PACKAGES = [
        'barryvdh/laravel-debugbar',
        'fruitcake/laravel-debugbar',
        'itsgoingd/clockwork',
        'spatie/laravel-ignition',
        'facade/ignition',
    ];

    /**
     * id => [severity, title, description, references]
     *
     * @var array<string, array{0: Severity, 1: string, 2: string, 3: list<string>}>
     */
    public const ALL = [
        'LPG001' => [Severity::High, 'APP_DEBUG is on', 'With APP_DEBUG=true the error page shows the stack trace, every request header (Cookie and Authorization included), the request body and SQL with its bound values.', [self::SK . '.env.example#L4', self::FW . 'src/Illuminate/Foundation/Exceptions/Renderer/Exception.php#L213-L233']],
        'LPG002' => [Severity::Medium, 'APP_ENV is not production', 'Packages switch on local-only behaviour from APP_ENV; Telescope and Horizon, for example, let anyone in when it is local.', [self::SK . '.env.example#L2', self::SK . 'config/app.php#L29']],
        'LPG003' => [Severity::High, 'APP_KEY is missing or the wrong length', 'Encryption, signed URLs and cookie sessions depend on APP_KEY. AES-256-CBC needs exactly 32 bytes (base64: prefix for encoded keys).', [self::SK . 'config/app.php#L98-L100', self::FW . 'src/Illuminate/Encryption/Encrypter.php#L75-L82']],
        'LPG004' => [Severity::High, 'APP_KEY or .env is in the repository', 'A key in .env.example, or a .env tracked by git, is readable by anyone with the repository. Anyone with the key can forge encrypted cookies and signed URLs.', []],
        'LPG005' => [Severity::High, 'Telescope or Horizon with APP_ENV=local', 'Both dashboards skip their gate when the environment is local, so anyone who can reach the URL gets in.', ['https://github.com/laravel/telescope/blob/v5.26.0/src/TelescopeApplicationServiceProvider.php#L29-L32', 'https://github.com/laravel/horizon/blob/v5.50.1/src/HorizonApplicationServiceProvider.php#L29-L31']],
        'LPG006' => [Severity::Medium, 'Debug tool installed for production', 'A debug toolbar or error page package is in require, not require-dev, so it is deployed. Most turn themselves on whenever APP_DEBUG is true.', []],
        'LPG007' => [Severity::High, 'CORS allows any origin with credentials', "allowed_origins ['*'] together with supports_credentials true makes Laravel echo back whatever Origin a page sends, with cookies allowed.", [self::FW . 'config/cors.php#L22-L32', 'https://github.com/fruitcake/php-cors/blob/v1.4.0/src/CorsService.php#L204-L219']],
        'LPG008' => [Severity::Medium, 'Every proxy is trusted', "trustProxies(at: '*') takes X-Forwarded-For, -Host and -Proto from any client unless the app is only reachable through the load balancer. Client IPs (rate limits, logs) and generated URLs can be spoofed.", [self::FW . 'src/Illuminate/Foundation/Configuration/Middleware.php#L698-L701']],
        'LPG009' => [Severity::Medium, 'Session cookie is not marked Secure', 'SESSION_SECURE_COOKIE is unset by default, so the session cookie is also sent over plain HTTP.', [self::SK . 'config/session.php#L172']],
        'LPG010' => [Severity::Medium, 'Session cookie is readable from JavaScript', 'http_only is off, so any XSS can read the session cookie.', [self::SK . 'config/session.php#L185']],
        'LPG011' => [Severity::Low, 'Session cookie has SameSite=None', 'The cookie is sent on cross-site requests; CSRF protection rests on the token alone.', [self::SK . 'config/session.php#L202']],
        'LPG012' => [Severity::Low, 'Log level is debug', 'LOG_LEVEL defaults to debug, which writes debug messages (and whatever packages log at that level) in production.', [self::SK . '.env.example#L21']],
        'LPG013' => [Severity::Medium, 'APP_URL is plain HTTP', 'Generated links, redirects and password reset URLs use APP_URL.', [self::SK . 'config/app.php#L55']],
        'LPG014' => [Severity::Low, 'BCRYPT_ROUNDS is below the default', 'Fewer rounds make stolen password hashes cheaper to crack. Laravel 13 uses 12.', [self::SK . '.env.example#L16', self::FW . 'config/hashing.php#L32']],
        'LPG015' => [Severity::High, 'Sensitive file under public/', 'Files in public/ are served as they are: a .env, a .git directory or a phpinfo() page there is readable by anyone.', []],
        'LPG016' => [Severity::Medium, 'Database password is empty or common', 'The database is not SQLite and DB_PASSWORD is empty or a well-known value.', [self::SK . '.env.example#L23-L28']],
        'LPG017' => [Severity::Low, 'Sessions are stored in a cookie', 'With SESSION_DRIVER=cookie the whole session lives in the browser, protected only by APP_KEY. A leaked key lets anyone forge any session.', [self::SK . 'config/session.php#L21']],
    ];

    private const COMMON_PASSWORDS = ['password', 'secret', 'root', 'laravel', 'admin', '123456', 'changeme'];

    /** @return list<Finding> */
    public function run(Project $p): array
    {
        $out = [];
        $add = static function (string $id, string $target, string $file, int $line, string $message, string $help = '') use (&$out, $p): void {
            $out[] = new Finding($id, self::ALL[$id][0], $target, $file === '' ? $p->name : self::path($p, $file), $line, $message, $help);
        };
        $packages = $p->packages();

        $debug = $p->setting('app', 'debug', 'APP_DEBUG', 'false');
        if ($debug->isTrue()) {
            $add('LPG001', 'APP_DEBUG', $debug->file, $debug->line, 'APP_DEBUG is true', 'Set APP_DEBUG=false in production.');
        }

        $envName = $p->setting('app', 'env', 'APP_ENV', 'production');
        $local = in_array(strtolower((string) $envName->value), ['local', 'dev', 'development', 'testing'], true);
        if (strtolower((string) $envName->value) !== 'production') {
            $add('LPG002', 'APP_ENV', $envName->file, $envName->line, sprintf('APP_ENV is "%s"', (string) $envName->value), 'Set APP_ENV=production.');
        }

        $key = $p->setting('app', 'key', 'APP_KEY', null);
        $raw = (string) $key->value;
        $bytes = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : $raw;
        if ($raw === '') {
            $add('LPG003', 'APP_KEY', $key->file, $key->line, 'APP_KEY is empty', 'Run php artisan key:generate --show and set the result.');
        } elseif ($bytes === false || strlen($bytes) !== 32) {
            $add('LPG003', 'APP_KEY', $key->file, $key->line, sprintf('APP_KEY decodes to %s, AES-256-CBC needs 32 bytes', $bytes === false ? 'invalid base64' : strlen($bytes) . ' bytes'), 'Run php artisan key:generate --show and set the result.');
        }

        $exampleKey = $p->example?->get('APP_KEY');
        if ($exampleKey !== null && $exampleKey !== '') {
            $same = $raw !== '' && $exampleKey === $raw;
            $add('LPG004', 'APP_KEY', '.env.example', $p->example->line('APP_KEY'), $same ? 'The APP_KEY in use is also written in .env.example' : '.env.example contains an APP_KEY', 'Leave APP_KEY empty in .env.example. If this key was ever used, rotate it (APP_PREVIOUS_KEYS keeps old data readable).');
        }
        foreach ($p->tracked ?? [] as $f) {
            if ($f === '.env' || (str_starts_with($f, '.env.') && !in_array($f, ['.env.example', '.env.testing', '.env.ci', '.env.dusk.local'], true))) {
                $add('LPG004', $f, $f, 0, "{$f} is tracked by git", 'git rm --cached it, add it to .gitignore, and rotate every secret it held.');
            }
        }

        if ($local) {
            foreach (['laravel/telescope' => 'Telescope', 'laravel/horizon' => 'Horizon'] as $pkg => $label) {
                if (($packages[$pkg] ?? null) === 'require') {
                    $add('LPG005', $pkg, $envName->file, $envName->line, "{$label} is installed and APP_ENV is \"{$envName->value}\", so its dashboard skips the gate", 'Set APP_ENV=production, and keep the view gate restricted.');
                }
            }
        }

        foreach (self::DEBUG_PACKAGES as $pkg) {
            if (($packages[$pkg] ?? null) === 'require') {
                $add('LPG006', $pkg, 'composer.json', self::jsonLine($p, $pkg), "{$pkg} is in require" . ($debug->isTrue() ? ' and APP_DEBUG is true' : ''), 'Move it to require-dev and deploy with composer install --no-dev.');
            }
        }

        $this->cors($p, $add);
        $this->proxies($p, $add);

        $secure = $p->setting('session', 'secure', 'SESSION_SECURE_COOKIE', null);
        if (!$secure->isTrue()) {
            $add('LPG009', 'SESSION_SECURE_COOKIE', $secure->file, $secure->line, $secure->value === null ? 'SESSION_SECURE_COOKIE is not set' : 'SESSION_SECURE_COOKIE is false', 'Set SESSION_SECURE_COOKIE=true when the site is served over HTTPS.');
        }
        $httpOnly = $p->setting('session', 'http_only', 'SESSION_HTTP_ONLY', 'true');
        if ($httpOnly->isFalse()) {
            $add('LPG010', 'SESSION_HTTP_ONLY', $httpOnly->file, $httpOnly->line, 'The session cookie is not HttpOnly', 'Remove SESSION_HTTP_ONLY=false.');
        }
        $sameSite = $p->setting('session', 'same_site', 'SESSION_SAME_SITE', 'lax');
        if (strtolower((string) $sameSite->value) === 'none') {
            $add('LPG011', 'SESSION_SAME_SITE', $sameSite->file, $sameSite->line, 'SESSION_SAME_SITE is none', 'Use lax unless the app is embedded cross-site on purpose.');
        }

        $level = $p->setting('logging', 'level', 'LOG_LEVEL', 'debug');
        if (strtolower((string) $level->value) === 'debug') {
            $add('LPG012', 'LOG_LEVEL', $level->file, $level->line, 'LOG_LEVEL is debug', 'Set LOG_LEVEL=info or higher.');
        }

        $url = $p->setting('app', 'url', 'APP_URL', 'http://localhost');
        $host = (string) parse_url((string) $url->value, PHP_URL_HOST);
        if (str_starts_with(strtolower((string) $url->value), 'http://') && !in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !str_ends_with($host, '.test')) {
            $add('LPG013', 'APP_URL', $url->file, $url->line, sprintf('APP_URL is %s', (string) $url->value), 'Serve the site over HTTPS and set APP_URL to the https:// address.');
        }

        $rounds = $p->setting('hashing', 'rounds', 'BCRYPT_ROUNDS', '12');
        if (ctype_digit((string) $rounds->value) && (int) $rounds->value < 12) {
            $add('LPG014', 'BCRYPT_ROUNDS', $rounds->file, $rounds->line, sprintf('BCRYPT_ROUNDS is %s', (string) $rounds->value), 'Use at least 12.');
        }

        foreach ($p->publicFiles as $f) {
            if ($f === '.env' || str_starts_with($f, '.env.') || $f === '.git') {
                $add('LPG015', "public/{$f}", "public/{$f}", 0, "public/{$f} is served by the web server", 'Remove it from public/.');
            } elseif (str_ends_with($f, '.php') && $f !== 'index.php') {
                $code = @file_get_contents("{$p->dir}/public/{$f}");
                if (is_string($code) && preg_match('/\bphpinfo\s*\(/i', $code)) {
                    $add('LPG015', "public/{$f}", "public/{$f}", 0, "public/{$f} calls phpinfo()", 'Delete it; phpinfo() prints environment variables, including secrets.');
                }
            }
        }

        $conn = strtolower((string) ($p->env('DB_CONNECTION') ?? 'sqlite'));
        if ($p->env !== null && $conn !== 'sqlite') {
            $pw = $p->env('DB_PASSWORD');
            if ($pw === null || $pw === '' || in_array(strtolower($pw), self::COMMON_PASSWORDS, true)) {
                $line = $p->env->line('DB_PASSWORD') ?: $p->env->line('DB_CONNECTION');
                $add('LPG016', 'DB_PASSWORD', $p->env->path, $line, $pw === null || $pw === '' ? "DB_PASSWORD is empty ({$conn})" : 'DB_PASSWORD is a common value', 'Use a generated password.');
            }
        }

        $driver = $p->setting('session', 'driver', 'SESSION_DRIVER', 'database');
        if (strtolower((string) $driver->value) === 'cookie') {
            $add('LPG017', 'SESSION_DRIVER', $driver->file, $driver->line, 'SESSION_DRIVER is cookie', 'Prefer database, redis or file sessions.');
        }

        return $out;
    }

    /** @param callable(string, string, string, int, string, string=): void $add */
    private function cors(Project $p, callable $add): void
    {
        $text = $p->configText('cors');
        if ($text === null) {
            return; // Framework default: any origin, but without credentials.
        }
        $origins = preg_match('/[\'"]allowed_origins[\'"]\s*=>\s*\[([^\]]*)\]/', $text, $o, PREG_OFFSET_CAPTURE) ? $o : null;
        $anyOrigin = $origins !== null && preg_match('/[\'"]\*[\'"]/', $origins[1][0]) === 1;
        if (!$anyOrigin) {
            return;
        }
        $line = substr_count(substr($text, 0, (int) $origins[0][1]), "\n") + 1;
        $cred = $p->setting('cors', 'supports_credentials', 'CORS_SUPPORTS_CREDENTIALS', 'false');
        if ($cred->isTrue()) {
            $add('LPG007', 'cors', 'config/cors.php', $line, "allowed_origins is ['*'] and supports_credentials is true", 'List the allowed origins explicitly, or turn supports_credentials off.');
        }
    }

    /** @param callable(string, string, string, int, string, string=): void $add */
    private function proxies(Project $p, callable $add): void
    {
        $text = $p->bootstrap;
        if ($text === null || !preg_match('/trustProxies\s*\(\s*(?:at\s*:\s*)?[\'"]\*[\'"]/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $line = substr_count(substr($text, 0, (int) $m[0][1]), "\n") + 1;
        $add('LPG008', 'trustProxies', 'bootstrap/app.php', $line, "trustProxies(at: '*') trusts forwarded headers from any client", 'List the load balancer addresses, or make sure the app is reachable only through it.');
    }

    private static function path(Project $p, string $file): string
    {
        if (str_starts_with($file, '/')) {
            $root = rtrim($p->dir, '/') . '/';
            if (str_starts_with($file, $root)) {
                $file = substr($file, strlen($root));
            } else {
                return $file;
            }
        }

        return $p->name === '' || $p->name === '.' ? $file : rtrim($p->name, '/') . '/' . $file;
    }

    private static function jsonLine(Project $p, string $pkg): int
    {
        $text = @file_get_contents($p->dir . '/composer.json');
        if (!is_string($text)) {
            return 0;
        }
        foreach (explode("\n", $text) as $i => $line) {
            if (stripos($line, "\"{$pkg}\"") !== false) {
                return $i + 1;
            }
        }

        return 0;
    }
}
