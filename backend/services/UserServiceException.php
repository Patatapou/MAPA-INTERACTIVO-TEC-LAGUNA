<?php

class UserServiceException extends RuntimeException
{
    /** @var array<int, string> */
    protected array $detalles = [];

    public function __construct(string $mensaje, int $statusCode = 400, array $detalles = [])
    {
        parent::__construct($mensaje, $statusCode);
        $this->detalles = array_values($detalles);
    }

    public function statusCode(): int
    {
        return (int) $this->getCode();
    }

    /** @return array<int, string> */
    public function detalles(): array
    {
        return $this->detalles;
    }
}
