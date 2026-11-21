<?php
declare(strict_types=1);

namespace ListenIn\Core;

/**
 * Validazione e sanitizzazione degli input lato server.
 * Accumula gli errori per campo; throwIfErrors() li restituisce come 422.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private readonly array $data) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, int $min = 0, int $max = 255, bool $required = true, bool $multiline = false): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$key] = 'Campo obbligatorio.';
            }
            return null;
        }
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            $this->errors[$key] = 'Valore non valido.';
            return null;
        }
        $v = self::clean((string) $v, $multiline);
        $len = mb_strlen($v);
        if ($len === 0 && $required) {
            $this->errors[$key] = 'Campo obbligatorio.';
            return null;
        }
        if ($len < $min) {
            $this->errors[$key] = "Minimo {$min} caratteri.";
        } elseif ($len > $max) {
            $this->errors[$key] = "Massimo {$max} caratteri.";
        }
        return $len === 0 ? null : $v;
    }

    public function email(string $key, bool $required = true): ?string
    {
        $v = $this->string($key, 3, 190, $required);
        if ($v === null) {
            return null;
        }
        $v = mb_strtolower($v);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$key] = 'Indirizzo email non valido.';
            return null;
        }
        return $v;
    }

    public function password(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        $min = (int) Config::get('security.password_min_length', 10);
        if (!is_string($v) || $v === '') {
            $this->errors[$key] = 'Campo obbligatorio.';
            return null;
        }
        if (strlen($v) > 4096) {
            $this->errors[$key] = 'Password troppo lunga.';
            return null;
        }
        if (mb_strlen($v) < $min) {
            $this->errors[$key] = "La password deve avere almeno {$min} caratteri.";
            return null;
        }
        if (preg_match('/^(.)\1+$/u', $v)) {
            $this->errors[$key] = 'La password è troppo prevedibile.';
            return null;
        }
        return $v;
    }

    public function int(string $key, int $min, int $max, bool $required = true): ?int
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$key] = 'Campo obbligatorio.';
            }
            return null;
        }
        if (is_string($v) && preg_match('/^-?\d{1,18}$/', trim($v))) {
            $v = (int) trim($v);
        }
        if (!is_int($v)) {
            $this->errors[$key] = 'Numero intero non valido.';
            return null;
        }
        if ($v < $min || $v > $max) {
            $this->errors[$key] = "Valore ammesso tra {$min} e {$max}.";
            return null;
        }
        return $v;
    }

    public function float(string $key, float $min, float $max, bool $required = true): ?float
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$key] = 'Campo obbligatorio.';
            }
            return null;
        }
        if (is_string($v) && preg_match('/^-?\d{1,3}(?:[.,]\d{1,8})?$/', trim($v))) {
            $v = (float) str_replace(',', '.', trim($v));
        }
        if (!is_int($v) && !is_float($v)) {
            $this->errors[$key] = 'Numero non valido.';
            return null;
        }
        if ($v < $min || $v > $max) {
            $this->errors[$key] = "Valore ammesso tra {$min} e {$max}.";
            return null;
        }
        return (float) $v;
    }

    public function enum(string $key, array $allowed, bool $required = true): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$key] = 'Campo obbligatorio.';
            }
            return null;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$key] = 'Valore non ammesso.';
            return null;
        }
        return $v;
    }

    public function accepted(string $key, string $message = 'Conferma richiesta.'): bool
    {
        $v = $this->data[$key] ?? false;
        if ($v !== true && $v !== 1 && $v !== '1' && $v !== 'on') {
            $this->errors[$key] = $message;
            return false;
        }
        return true;
    }

    public function addError(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function throwIfErrors(): void
    {
        if ($this->errors) {
            throw ApiException::validation($this->errors);
        }
    }

    /** Normalizza Unicode, rimuove caratteri di controllo e spazi superflui. */
    public static function clean(string $v, bool $multiline = false): string
    {
        if (class_exists(\Normalizer::class)) {
            $v = \Normalizer::normalize($v, \Normalizer::FORM_C) ?: $v;
        }
        $v = $multiline
            ? preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $v))
            : preg_replace('/\p{C}+/u', ' ', $v);
        $v = (string) $v;
        if (!$multiline) {
            $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        }
        return trim($v);
    }
}
