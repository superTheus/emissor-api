<?php

namespace App\Controllers\Fiscal;

use stdClass;

/**
 * Controller para emissão de NFe para Microempreendedor Individual (CRT 4)
 *
 * Código exclusivo para MEIs, implementado para melhor fiscalização e padronização,
 * diferenciando-os dos demais no Simples Nacional, especialmente para notas de produtos.
 *
 * Características:
 * - Limite de faturamento de R$ 81.000,00/ano
 * - Sem destaque de impostos
 * - Tributação simplificada
 * - Não pode ter sócios
 * - Apenas 1 funcionário
 */
class CRT4Controller extends BaseFiscalController
{
  /**
   * Processa os impostos para MEI
   * MEI não destaca impostos na nota fiscal
   */
  protected function processarImpostosProduto($produto, $index)
  {
    $item = $index + 1;

    // IBS e CBS (novos tributos da reforma tributária)
    $this->nfe->tagIBS($this->generateIBSData($produto, $item));
    $this->nfe->tagCBS($this->generateCBSData($produto, $item));

    // Verificar se é combustível (caso raro para MEI, mas mantido por consistência)
    if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
      $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
      $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
      $this->baseTotalIcms += 1000.00;
    } else {
      // ICMS do Simples Nacional para MEI
      // MEI usa CSOSN 102 (sem permissão de crédito) na maioria dos casos
      $this->nfe->tagICMSSN($this->generateIcmssnMei($produto, $item));
    }

    // PIS e COFINS zerados para MEI
    $this->nfe->tagPIS($this->generatePisDataMei($produto, $item));
    $this->nfe->tagCOFINS($this->generateConfinsMei($produto, $item));
  }

  /**
   * Gera dados do ICMS para MEI
   * MEI geralmente usa CSOSN 102 (tributada sem permissão de crédito)
   */
  protected function generateIcmssnMei($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->orig = isset($produto['origem']) ? $produto['origem'] : 0;

    // MEI geralmente usa CSOSN 102, mas pode variar
    $csosn = isset($produto['csosn']) ? $produto['csosn'] : '102';
    $std->CSOSN = $csosn;

    switch ($csosn) {
      case '102': // Tributada pelo Simples Nacional sem permissão de crédito (padrão MEI)
      case '103': // Isenção do ICMS
      case '300': // Imune
      case '400': // Não tributada pelo Simples Nacional
        // Nenhum campo adicional necessário
        break;

      case '500': // ICMS cobrado anteriormente por substituição tributária
        $std->vBCSTRet = isset($produto['base_retida']) ? $produto['base_retida'] : 0.00;
        $std->pST = isset($produto['aliquota_st_retida']) ? $produto['aliquota_st_retida'] : 0.00;
        $std->vICMSSTRet = isset($produto['valor_st_retido']) ? $produto['valor_st_retido'] : 0.00;
        break;

      default:
        // Para outros CSOSNs, usar 102 como padrão seguro para MEI
        $std->CSOSN = '102';
        break;
    }

    return $std;
  }

  /**
   * Gera dados de PIS para MEI
   * MEI é isento de PIS
   */
  protected function generatePisDataMei($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '06'; // Operação Tributável - Alíquota Zero
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pPIS = number_format(0, 2, ".", "");
    $std->vPIS = number_format(0, 2, ".", "");

    return $std;
  }

  /**
   * Gera dados de COFINS para MEI
   * MEI é isento de COFINS
   */
  protected function generateConfinsMei($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '06'; // Operação Tributável - Alíquota Zero
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pCOFINS = number_format(0, 2, ".", "");
    $std->vCOFINS = number_format(0, 2, ".", "");

    return $std;
  }

  /**
   * Sobrescreve para garantir que o CRT seja 4 na emissão
   */
  protected function generateDataCompany()
  {
    $std = parent::generateDataCompany();
    $std->CRT = '4'; // Força CRT 4 para MEI

    return $std;
  }

  /**
   * Sobrescreve para adicionar informações específicas de MEI
   */
  protected function generateIcmsInfo($data)
  {
    $std = parent::generateIcmsInfo($data);

    // Adiciona informação de MEI se não houver observação
    if (empty($std->infCpl)) {
      $std->infCpl = 'Documento emitido por ME ou EPP optante pelo Simples Nacional - MEI';
    } else {
      $std->infCpl .= ' | Documento emitido por ME ou EPP optante pelo Simples Nacional - MEI';
    }

    return $std;
  }
}
