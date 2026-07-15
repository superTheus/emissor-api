<?php

namespace App\Controllers\Fiscal\Concerns;

use App\Http\JsonResponse;
use NFePHP\NFe\Common\Standardize;

trait HandlesSefazAuthorizationResponse
{
  protected function analisaRetorno($response): void
  {
    if (!is_object($response) || !isset($response->cStat)) {
      throw new \RuntimeException('A resposta da SEFAZ não contém o código de status (cStat).');
    }

    $this->setCurrentData($response);
    $this->setStatus($response->cStat);
    $status = (int) $response->cStat;

    switch ($status) {
      case 100:
        $this->processarEmissao($response);
        return;

      case 103:
        $this->processarLote($response);
        return;

      case 104:
        $this->loteProcessado($response);
        return;

      case 105:
        $this->processarLote($response);
        return;

      default:
        $reason = isset($response->xMotivo)
          ? (string) $response->xMotivo
          : 'Motivo não informado pela SEFAZ.';

        JsonResponse::send([
          'codigo' => $status,
          'cStat' => $status,
          'error' => $reason,
          // O xMotivo descreve a rejeição da SEFAZ; não é um erro de tag do XML.
          'error_tags' => [],
          'etapa' => 'autorização da SEFAZ',
        ], 422);
        return;
    }
  }

  protected function processarLote($response): void
  {
    if (isset($response->infRec->nRec)) {
      $this->receiptNumber = (string) $response->infRec->nRec;
    }

    if (empty($this->receiptNumber)) {
      throw new \RuntimeException(
        'A SEFAZ informou processamento assíncrono sem fornecer o número do recibo.'
      );
    }

    $this->receiptPollAttempts++;
    if ($this->receiptPollAttempts > self::MAX_RECEIPT_POLLS) {
      JsonResponse::send([
        'status' => 'processando',
        'codigo' => 105,
        'cStat' => 105,
        'recibo' => $this->receiptNumber,
        'error' => 'O lote continua em processamento na SEFAZ.',
        'error_tags' => [],
        'etapa' => 'processamento da SEFAZ',
      ], 202);
      return;
    }

    // Evita consultas praticamente simultâneas enquanto o lote ainda está processando.
    usleep(500000);
    $this->response = $this->tools->sefazConsultaRecibo($this->receiptNumber);

    $standardize = new Standardize();
    $this->analisaRetorno($standardize->toStd($this->response));
  }

  protected function loteProcessado($response): void
  {
    if (!isset($response->protNFe)) {
      throw new \RuntimeException(
        'O lote foi processado, mas a SEFAZ não retornou o protocolo do documento fiscal.'
      );
    }

    // Para um único documento, Standardize devolve protNFe como objeto.
    // Iterar esse objeto diretamente percorre attributes/infProt, que não têm cStat.
    $protocols = is_array($response->protNFe)
      ? $response->protNFe
      : [$response->protNFe];

    foreach ($protocols as $protocol) {
      if (!is_object($protocol)) {
        throw new \RuntimeException('A SEFAZ retornou um protocolo em formato inválido.');
      }

      $result = $protocol->infProt ?? $protocol;
      $this->analisaRetorno($result);
    }
  }

  abstract protected function processarEmissao($response);
}
