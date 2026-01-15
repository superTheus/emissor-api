<?php

namespace App\Controllers\Fiscal;

use stdClass;

/**
 * Controller para emissão de NFe para empresas do Regime Normal (CRT 3)
 *
 * Usado por empresas no Lucro Presumido ou Lucro Real, geralmente de médio e grande porte.
 *
 * Características:
 * - ICMS com CST (Código de Situação Tributária)
 * - IPI quando aplicável
 * - PIS e COFINS com alíquotas completas
 * - Maior complexidade tributária
 */
class CRT3Controller extends BaseFiscalController
{
  /**
   * Processa os impostos para Regime Normal (Lucro Presumido/Real)
   */
  protected function processarImpostosProduto($produto, $index)
  {
    $item = $index + 1;

    // IBS e CBS (novos tributos da reforma tributária)
    $this->nfe->tagIBS($this->generateIBSData($produto, $item));
    $this->nfe->tagCBS($this->generateCBSData($produto, $item));

    // Verificar se é combustível
    if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
      $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
      $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
      $this->baseTotalIcms += 1000.00;
    } else {
      // ICMS do Regime Normal
      if (isset($produto['icms'])) {
        $this->nfe->tagICMS($this->generateICMSData($produto['icms'], $item));
        $this->baseTotalIcms += $this->baseCalculo;
      } else {
        // ICMS padrão isento
        $this->nfe->tagICMS($this->generateICMSDefault($produto, $item));
      }
    }

    // IPI quando aplicável
    if (isset($produto['ipi'])) {
      $this->nfe->tagIPI($this->generateIPIData($produto['ipi'], $item));
    }

    // PIS completo
    if (isset($produto['pis'])) {
      $this->nfe->tagPIS($this->generatePisData($produto['pis'], $item));
    } else {
      $this->nfe->tagPIS($this->generatePisDataDefault($produto, $item));
    }

    // COFINS completo
    if (isset($produto['cofins'])) {
      $this->nfe->tagCOFINS($this->generateConfinsData($produto['cofins'], $item));
    } else {
      $this->nfe->tagCOFINS($this->generateConfinsDataDefault($produto, $item));
    }
  }

  /**
   * Gera dados do ICMS para Regime Normal
   */
  protected function generateICMSData($icms, $item)
  {
    $std = new stdClass();

    $percentual_icms = floatval($icms['aliquota_icms']);
    $valorIcms = $this->baseCalculo * ($percentual_icms / 100);

    $std->item = $item;
    $std->orig = $this->origem;
    $std->CST = $icms['cst'];
    $std->modBC = $icms['mod_bc'] ?? 0;
    $std->vBC = number_format($this->baseCalculo, 2, ".", "");
    $std->pICMS = number_format($percentual_icms, 4, ".", "");
    $std->vICMS = number_format($valorIcms, 2, ".", "");

    // Campos adicionais para CSTs específicos
    if (isset($icms['reducao']) && $icms['reducao'] > 0) {
      $std->pRedBC = number_format($icms['reducao'], 2, ".", "");
    }

    // ICMS ST se aplicável
    if (isset($icms['st']) && $icms['st'] === true) {
      $std->modBCST = $icms['mod_bc_st'] ?? 4;
      $std->pMVAST = isset($icms['mva']) ? $icms['mva'] : 0.00;
      $std->pRedBCST = isset($icms['reducao_st']) ? $icms['reducao_st'] : 0.00;
      $std->vBCST = isset($icms['base_st']) ? $icms['base_st'] : 0.00;
      $std->pICMSST = isset($icms['aliquota_st']) ? $icms['aliquota_st'] : 0.00;
      $std->vICMSST = isset($icms['valor_st']) ? $icms['valor_st'] : 0.00;
    }

    // FCP se aplicável
    if (isset($icms['fcp']) && $icms['fcp'] > 0) {
      $std->vBCFCP = number_format($this->baseCalculo, 2, ".", "");
      $std->pFCP = number_format($icms['fcp'], 2, ".", "");
      $std->vFCP = number_format($this->baseCalculo * ($icms['fcp'] / 100), 2, ".", "");
    }

    $this->valorIcms += $valorIcms;

    return $std;
  }

  /**
   * Gera ICMS padrão quando não há dados específicos de tributação
   */
  protected function generateICMSDefault($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->orig = $produto['origem'] ?? 0;
    $std->CST = '40'; // Isenta
    $std->vICMSDeson = 0.00;
    $std->motDesICMS = 9; // Outros

    return $std;
  }

  /**
   * Gera dados do IPI
   */
  protected function generateIPIData($ipi, $item)
  {
    $percentual_ipi = floatval($ipi['aliquota_ipi']);
    $valorIpi = $this->baseCalculo * ($percentual_ipi / 100);

    $std = new stdClass();
    $std->item = $item;
    $std->CST = $ipi['cst'];
    $std->cEnq = $ipi['enquadramento_legal_ipi'] ?? '999';
    $std->vBC = number_format($this->baseCalculo, 2, ".", "");
    $std->pIPI = number_format($percentual_ipi, 4, ".", "");
    $std->vIPI = number_format($valorIpi, 2, ".", "");

    return $std;
  }

  /**
   * Gera dados do PIS
   */
  protected function generatePisData($pis, $item)
  {
    $percentual_pis = floatval($pis['aliquota_pis']);
    $valorPis = $this->baseCalculo * ($percentual_pis / 100);

    $std = new stdClass();
    $std->item = $item;
    $std->CST = $pis['cst'];
    $std->vBC = number_format($this->baseCalculo, 2, ".", "");
    $std->pPIS = number_format($percentual_pis, 4, ".", "");
    $std->vPIS = number_format($valorPis, 2, ".", "");

    return $std;
  }

  /**
   * Gera dados do PIS padrão
   */
  protected function generatePisDataDefault($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '07'; // Operação Isenta da Contribuição
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pPIS = number_format(0, 2, ".", "");
    $std->vPIS = number_format(0, 2, ".", "");

    return $std;
  }

  /**
   * Gera dados do COFINS
   */
  protected function generateConfinsData($cofins, $item)
  {
    $percentual_cofins = floatval($cofins['aliquota_cofins']);
    $valorCofins = $this->baseCalculo * ($percentual_cofins / 100);

    $std = new stdClass();
    $std->item = $item;
    $std->CST = $cofins['cst'];
    $std->vBC = number_format($this->baseCalculo, 2, ".", "");
    $std->pCOFINS = number_format($percentual_cofins, 4, ".", "");
    $std->vCOFINS = number_format($valorCofins, 2, ".", "");

    return $std;
  }

  /**
   * Gera dados do COFINS padrão
   */
  protected function generateConfinsDataDefault($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '07'; // Operação Isenta da Contribuição
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pCOFINS = number_format(0, 2, ".", "");
    $std->vCOFINS = number_format(0, 2, ".", "");

    return $std;
  }
}
