<?php

namespace App\Http;

class HttpException extends \RuntimeException
{
  private int $status;
  private array $context;

  public function __construct(string $message, int $status = 400, array $context = [])
  {
    parent::__construct($message);
    $this->status = $status;
    $this->context = $context;
  }

  public function status(): int
  {
    return $this->status;
  }

  public function context(): array
  {
    return $this->context;
  }
}
