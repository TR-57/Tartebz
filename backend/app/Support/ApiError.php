<?php

namespace App\Support;

/**
 * An error the API returns as {ok:false, error:{code, message, fields?}}.
 * `message` is a plain sentence for the person; `fields` maps "field.path" => what is wrong with it.
 */
class ApiError extends \RuntimeException
{
    public function __construct(
        public string $errCode,
        string $message,
        public int $status = 400,
        public array $fields = [],
    ) {
        parent::__construct($message);
    }

    public static function validation(array $fields, string $message = 'Check the fields marked in red.'): self
    {
        return new self('validation', $message, 422, $fields);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function forbidden(string $message): self
    {
        return new self('forbidden', $message, 403);
    }

    public function toArray(): array
    {
        $e = ['code' => $this->errCode, 'message' => $this->getMessage()];
        if ($this->fields) {
            $e['fields'] = $this->fields;
        }

        return ['ok' => false, 'error' => $e];
    }
}
