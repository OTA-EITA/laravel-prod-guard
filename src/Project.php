<?php

declare(strict_types=1);

namespace LaravelProdGuard;

/**
 * The files of one Laravel application, read without running any of them:
 * the .env meant for production, .env.example, composer.json, the published
 * config files, bootstrap/app.php and the public directory.
 */
final class Project
{
    /** @var array<string, string> config name => file contents */
    private array $configs = [];

    /**
     * @param array<string, mixed> $composer
     * @param list<string> $publicFiles names directly under public/
     * @param list<string>|null $tracked .env files tracked by git, or null outside a git repository
     */
    public function __construct(
        public readonly string $dir,
        /** How the project is named in findings (relative to where the tool runs). */
        public readonly string $name,
        public readonly ?EnvFile $env,
        public readonly ?EnvFile $example,
        public readonly array $composer,
        public readonly ?string $bootstrap,
        public readonly array $publicFiles,
        public readonly ?array $tracked,
    ) {
    }

    public static function load(string $dir, string $name, ?string $envPath = null): self
    {
        $composerFile = $dir . '/composer.json';
        $text = @file_get_contents($composerFile);
        if ($text === false) {
            throw new \RuntimeException("{$composerFile}: cannot read");
        }
        $composer = json_decode($text, true);
        if (!is_array($composer)) {
            throw new \RuntimeException("{$composerFile}: not valid JSON");
        }
        $envPath ??= $dir . '/.env';
        $env = is_file($envPath) ? EnvFile::load($envPath) : null;
        $example = is_file($dir . '/.env.example') ? EnvFile::load($dir . '/.env.example') : null;
        $bootstrap = @file_get_contents($dir . '/bootstrap/app.php');
        $public = is_dir($dir . '/public') ? array_values(array_diff(scandir($dir . '/public') ?: [], ['.', '..'])) : [];

        /** @var array<string, mixed> $composer */
        $p = new self($dir, $name, $env, $example, $composer, $bootstrap === false ? null : $bootstrap, $public, self::trackedEnvFiles($dir));
        foreach (['app', 'session', 'cors', 'logging', 'hashing', 'database'] as $c) {
            $t = @file_get_contents("{$dir}/config/{$c}.php");
            if ($t !== false) {
                $p->configs[$c] = $t;
            }
        }

        return $p;
    }

    /** @param array<string, string> $configs */
    public function withConfigs(array $configs): self
    {
        $p = clone $this;
        $p->configs = $configs;

        return $p;
    }

    public function isLaravel(): bool
    {
        return isset($this->packages()['laravel/framework']);
    }

    /**
     * Packages from composer.json with whether they are runtime (require) or dev only.
     *
     * @return array<string, 'require'|'require-dev'>
     */
    public function packages(): array
    {
        $out = [];
        foreach (['require-dev', 'require'] as $section) {
            $list = $this->composer[$section] ?? [];
            if (is_array($list)) {
                foreach (array_keys($list) as $pkg) {
                    $out[strtolower((string) $pkg)] = $section;
                }
            }
        }

        return $out;
    }

    public function configText(string $name): ?string
    {
        return $this->configs[$name] ?? null;
    }

    /**
     * The effective value of a setting that a config file reads with env().
     *
     * Order: the env file, then the default written in the app's published
     * config file, then the framework's own default (for configs the app has
     * not published). A literal value in the published config wins over env.
     */
    public function setting(string $config, string $key, string $envKey, ?string $frameworkDefault): Setting
    {
        $text = $this->configs[$config] ?? null;
        $file = "config/{$config}.php";
        $m = $text === null ? null : self::configLine($text, $key);
        if ($text !== null && $m !== null) {
            $line = substr_count(substr($text, 0, $m[0][1]), "\n") + 1;
            $expr = trim($m[2][0]);
            if (preg_match('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]\s*(?:,\s*(.+?))?\s*\)/', $expr, $e)) {
                $envKey = $e[1];
                if ($this->env?->has($envKey)) {
                    return new Setting(self::normalize((string) $this->env->get($envKey)), $this->env->path, $this->env->line($envKey), true);
                }

                return new Setting(isset($e[2]) ? self::literal($e[2]) : null, $file, $line, false);
            }
            $lit = self::literal(preg_replace('/^\(\w+\)\s*/', '', $expr) ?? $expr);

            return new Setting($lit, $file, $line, true);
        }
        if ($this->env?->has($envKey)) {
            return new Setting(self::normalize((string) $this->env->get($envKey)), $this->env->path, $this->env->line($envKey), true);
        }

        return new Setting($frameworkDefault, '', 0, false);
    }

    /**
     * The first uncommented `'key' => expr` line in a config file.
     *
     * @return array<array{string, int}>|null
     */
    private static function configLine(string $text, string $key): ?array
    {
        $pattern = '/^(.*?)[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*(.+?),?\s*$/m';
        if (!preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }
        foreach ($all as $m) {
            $prefix = ltrim($m[1][0]);
            if (!str_starts_with($prefix, '//') && !str_starts_with($prefix, '*') && !str_starts_with($prefix, '#')) {
                return $m;
            }
        }

        return null;
    }

    /** The raw value of an env variable, without config defaults. */
    public function env(string $name): ?string
    {
        return $this->env?->get($name);
    }

    /** Normalise a value the way Laravel's env() helper does. */
    public static function normalize(string $v): ?string
    {
        return match (strtolower($v)) {
            'true', '(true)' => 'true',
            'false', '(false)' => 'false',
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $v,
        };
    }

    /** A PHP literal from a config file, as a normalised string; null for anything else. */
    public static function literal(string $expr): ?string
    {
        $expr = trim($expr);
        if (preg_match('/^([\'"])(.*)\1$/s', $expr, $m)) {
            return $m[2];
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
            return $expr;
        }

        return match (strtolower($expr)) {
            'true' => 'true',
            'false' => 'false',
            default => null,
        };
    }

    /** @return list<string>|null */
    private static function trackedEnvFiles(string $dir): ?array
    {
        if (!is_dir($dir . '/.git') && !is_file($dir . '/.git')) {
            return null;
        }
        $cmd = 'git -C ' . escapeshellarg($dir) . ' ls-files -- ' . escapeshellarg('.env') . ' ' . escapeshellarg('.env.*') . ' 2>/dev/null';
        $out = shell_exec($cmd);
        if (!is_string($out)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $f): bool => $f !== ''));
    }
}
