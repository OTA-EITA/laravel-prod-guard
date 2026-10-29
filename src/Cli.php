<?php

declare(strict_types=1);

namespace LaravelProdGuard;

final class Cli
{
    public const VERSION = '0.1.0';

    private const USAGE = <<<'TXT'
        usage: laravel-prod-guard [options] [path ...]

        Checks a Laravel application's production settings by reading its files:
        .env, .env.example, composer.json, config/*.php, bootstrap/app.php and
        public/. Nothing is executed. Paths default to the current directory;
        directories are searched for Laravel applications.

        options:
          --env <file>           the env file to check instead of <app>/.env (for example .env.production)
          --format <format>      text, json, markdown or sarif (default: text)
          --fail-on <severity>   exit 1 on findings at least this severe: high, medium, low or none (default: high)
          --list-checks          print the checks and exit
          --version              print the version and exit
          -h, --help             show this help

        TXT;

    /**
     * @param list<string> $argv arguments without the program name
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function run(array $argv, callable $out, callable $err, string $cwd): int
    {
        $format = 'text';
        $failOn = 'high';
        $envFile = null;
        $paths = [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            $value = static function (string $name) use ($argv, &$i): string {
                $v = $argv[$i + 1] ?? null;
                if ($v === null) {
                    throw new \InvalidArgumentException("{$name} needs a value");
                }
                $i++;

                return $v;
            };
            try {
                switch ($arg) {
                    case '-h':
                    case '--help':
                        $out(self::USAGE);

                        return 0;
                    case '--version':
                        $out('laravel-prod-guard ' . self::VERSION . "\n");

                        return 0;
                    case '--list-checks':
                        foreach (Checks::ALL as $id => [$severity, $title, $description]) {
                            $out(sprintf("%s  %-6s  %s: %s\n", $id, $severity->value, $title, $description));
                        }

                        return 0;
                    case '--env':
                        $envFile = $value($arg);
                        break;
                    case '--format':
                        $format = $value($arg);
                        if (!in_array($format, Report::FORMATS, true)) {
                            throw new \InvalidArgumentException("unknown format \"{$format}\" (want " . implode(', ', Report::FORMATS) . ')');
                        }
                        break;
                    case '--fail-on':
                        $failOn = $value($arg);
                        if ($failOn !== 'none' && Severity::tryFrom($failOn) === null) {
                            throw new \InvalidArgumentException("--fail-on must be high, medium, low or none, got \"{$failOn}\"");
                        }
                        break;
                    default:
                        if (str_starts_with($arg, '-')) {
                            throw new \InvalidArgumentException("unknown option {$arg}");
                        }
                        $paths[] = $arg;
                }
            } catch (\InvalidArgumentException $e) {
                $err("error: {$e->getMessage()}\n\n" . self::USAGE);

                return 2;
            }
        }

        $cwd = realpath($cwd) ?: $cwd;
        $abs = static fn (string $p): string => realpath(str_starts_with($p, '/') ? $p : "{$cwd}/{$p}") ?: (str_starts_with($p, '/') ? $p : "{$cwd}/{$p}");
        $checks = new Checks();
        $findings = [];
        $apps = 0;
        try {
            $dirs = [];
            foreach ($paths === [] ? ['.'] : $paths as $path) {
                $dirs = [...$dirs, ...self::apps($abs($path))];
            }
            if ($envFile !== null && count($dirs) !== 1) {
                throw new \RuntimeException('--env needs exactly one application');
            }
            foreach ($dirs as $dir) {
                $name = str_starts_with($dir, rtrim($cwd, '/') . '/') ? substr($dir, strlen(rtrim($cwd, '/')) + 1) : ($dir === $cwd ? '' : $dir);
                $envPath = $envFile === null ? null : $abs($envFile);
                if ($envPath !== null && !is_file($envPath)) {
                    throw new \RuntimeException("{$envFile}: no such file");
                }
                $project = Project::load($dir, $name, $envPath);
                if ($project->env === null) {
                    $err(sprintf("note: %s has no .env; checking config defaults only. Pass --env for the production values.\n", $name === '' ? '.' : $name));
                }
                $findings = [...$findings, ...$checks->run($project)];
                $apps++;
            }
        } catch (\RuntimeException $e) {
            $err("error: {$e->getMessage()}\n");

            return 2;
        }
        if ($apps === 0) {
            $err("error: no Laravel application found (composer.json requiring laravel/framework)\n");

            return 2;
        }

        $out(Report::render($findings, $format, $apps, self::VERSION));
        if ($failOn === 'none') {
            return 0;
        }
        $threshold = Severity::from($failOn)->rank();
        foreach ($findings as $f) {
            if ($f->severity->rank() >= $threshold) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Directories holding a composer.json that requires laravel/framework,
     * skipping vendor, VCS and test fixture folders.
     *
     * @return list<string>
     */
    public static function apps(string $path): array
    {
        if (is_file($path)) {
            $path = dirname($path);
        }
        if (!is_dir($path)) {
            throw new \RuntimeException("{$path}: no such file or directory");
        }
        $path = rtrim($path, '/');
        $out = [];
        $composer = @file_get_contents($path . '/composer.json');
        if (is_string($composer)) {
            $json = json_decode($composer, true);
            if (is_array($json) && is_array($json['require'] ?? null) && isset($json['require']['laravel/framework'])) {
                // An application's subdirectories are its own code, not other apps.
                return [$path];
            }
        }
        foreach (scandir($path) ?: [] as $e) {
            // Test fixtures are meant to be bad; scan them by naming the path explicitly.
            if (in_array($e, ['.', '..', 'vendor', 'node_modules', '.git', 'fixtures', 'testdata'], true) || !is_dir("{$path}/{$e}")) {
                continue;
            }
            $out = [...$out, ...self::apps("{$path}/{$e}")];
        }

        return $out;
    }
}
