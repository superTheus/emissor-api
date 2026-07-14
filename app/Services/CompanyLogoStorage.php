<?php

namespace App\Services;

final class CompanyLogoStorage
{
  public const MAX_BYTES = 2 * 1024 * 1024;
  public const MAX_DIMENSION = 4096;

  private const EXTENSIONS_BY_MIME = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
  ];

  private string $directory;

  public function __construct(?string $directory = null)
  {
    $this->directory = $directory
      ?? dirname(__DIR__, 2) . '/app/storage/logos';
  }

  public function store(string $encodedImage): string
  {
    [$payload, $declaredMime] = $this->extractPayload($encodedImage);
    $payload = preg_replace('/\s+/', '', $payload) ?? '';

    if ($payload === '' || strlen($payload) > (int) ceil(self::MAX_BYTES / 3) * 4 + 4) {
      throw new \InvalidArgumentException('A logo deve ter no máximo 2 MB.');
    }

    $binary = base64_decode($payload, true);
    if ($binary === false || $binary === '') {
      throw new \InvalidArgumentException('Logo precisa ser uma string Base64 válida.');
    }
    if (strlen($binary) > self::MAX_BYTES) {
      throw new \InvalidArgumentException('A logo deve ter no máximo 2 MB.');
    }

    $imageInfo = @getimagesizefromstring($binary);
    $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
    if (!is_string($mime) || !isset(self::EXTENSIONS_BY_MIME[$mime])) {
      throw new \InvalidArgumentException('Formato de logo não permitido. Use PNG, JPEG ou WebP.');
    }
    if ($declaredMime !== null && $declaredMime !== $mime) {
      throw new \InvalidArgumentException('O tipo informado na Data URL não corresponde à imagem.');
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if (
      $width < 1 || $height < 1
      || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
    ) {
      throw new \InvalidArgumentException('A logo deve ter dimensões entre 1 e 4096 pixels.');
    }

    $this->ensureDirectory();
    $fileName = 'logo_' . bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS_BY_MIME[$mime];
    if (file_put_contents($this->directory . '/' . $fileName, $binary, LOCK_EX) === false) {
      throw new \RuntimeException('Não foi possível salvar a logo da empresa.');
    }

    return $fileName;
  }

  public function remove(?string $fileName): void
  {
    if ($fileName === null || $fileName === '') {
      return;
    }

    $filePath = $this->directory . '/' . basename($fileName);
    if (is_file($filePath) && !unlink($filePath)) {
      error_log("Não foi possível remover a logo {$filePath}");
    }
  }

  private function extractPayload(string $encodedImage): array
  {
    $encodedImage = trim($encodedImage);
    if (!str_starts_with(strtolower($encodedImage), 'data:')) {
      return [$encodedImage, null];
    }

    if (!preg_match('/^data:(image\/(?:png|jpeg|webp));base64,(.*)$/is', $encodedImage, $matches)) {
      throw new \InvalidArgumentException(
        'Data URL da logo inválida. Use PNG, JPEG ou WebP codificado em Base64.'
      );
    }

    return [$matches[2], strtolower($matches[1])];
  }

  private function ensureDirectory(): void
  {
    if (!is_dir($this->directory)) {
      if (!mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
        throw new \RuntimeException('Não foi possível criar a pasta de logos.');
      }
    }
    if (!is_writable($this->directory)) {
      throw new \RuntimeException('A pasta de logos não possui permissão de escrita.');
    }
  }
}
