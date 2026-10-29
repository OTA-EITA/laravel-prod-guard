<?php

declare(strict_types=1);

namespace LaravelProdGuard;

/**
 * A .env file read the way phpdotenv (Laravel's loader) reads it, closely
 * enough for configuration checks: `export` prefixes, single quotes taken
 * literally, double quotes with escapes, ${VAR} expansion outside single
 * quotes, and `#` comments after unquoted values.
 */
final class EnvFile
{
    /** @var array<string, array{value: string, line: int}> */
    private array $vars = [];

    public function __construct(public readonly string $path)
    {
    }

    public static function load(string $path): self
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("{$path}: cannot read");
        }

        return self::parse($text, $path);
    }

    public static function parse(string $text, string $path = '.env'): self
    {
        $env = new self($path);
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        for ($i = 0; $i < count($lines); $i++) {
            $line = ltrim($lines[$i]);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/s', $line, $m)) {
                continue;
            }
            $start = $i + 1;
            $raw = $m[2];
            // A quoted value may continue over several lines.
            $quote = $raw[0] ?? '';
            if (($quote === '"' || $quote === "'") && !self::closed($raw, $quote)) {
                while ($i + 1 < count($lines)) {
                    $raw .= "\n" . $lines[++$i];
                    if (self::closed($raw, $quote)) {
                        break;
                    }
                }
            }
            $env->vars[$m[1]] = ['value' => $env->value($raw), 'line' => $start];
        }

        return $env;
    }

    public function has(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    public function get(string $name): ?string
    {
        return $this->vars[$name]['value'] ?? null;
    }

    public function line(string $name): int
    {
        return $this->vars[$name]['line'] ?? 0;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->vars);
    }

    private static function closed(string $raw, string $quote): bool
    {
        for ($j = 1; $j < strlen($raw); $j++) {
            if ($quote === '"' && $raw[$j] === '\\') {
                $j++;
                continue;
            }
            if ($raw[$j] === $quote) {
                return true;
            }
        }

        return false;
    }

    private function value(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if ($raw[0] === "'") {
            $end = strpos($raw, "'", 1);

            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }
        if ($raw[0] === '"') {
            $out = '';
            for ($j = 1; $j < strlen($raw); $j++) {
                $c = $raw[$j];
                if ($c === '\\' && $j + 1 < strlen($raw)) {
                    $n = $raw[++$j];
                    $out .= match ($n) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $n,
                    };
                    continue;
                }
                if ($c === '"') {
                    break;
                }
                $out .= $c;
            }

            return $this->expand($out);
        }
        $value = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;

        return $this->expand(trim($value));
    }

    private function expand(string $value): string
    {
        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_.]*)\}/',
            // Only variables defined earlier in the file; the shell environment is not read.
            fn (array $m): string => $this->vars[$m[1]]['value'] ?? '',
            $value,
        ) ?? $value;
    }
}
