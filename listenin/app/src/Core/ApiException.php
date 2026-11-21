<?php
declare(strict_types=1);

namespace ListenIn\Core;

/** Errore applicativo esposto al client con codice HTTP e messaggio sicuro. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = []
    ) {
        parent::__construct($message, $status);
    }

    public static function validation(array $fieldErrors, string $message = 'Alcuni campi non sono validi.'): self
    {
        return new self(422, 'validation_error', $message, ['fields' => $fieldErrors]);
    }

    public static function notFound(string $message = 'Risorsa non trovata.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'Operazione non consentita.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function unauthorized(string $message = 'Accedi per continuare.'): self
    {
        return new self(401, 'unauthorized', $message);
    }
}
