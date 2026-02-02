<?php

namespace App\Controllers\CupomFiscal;

use stdClass;

/**
 * Controller para emissão de NFC-e para empresas do Simples Nacional com Excesso de Sublimite (CRT 2)
 *
 * Para empresas do Simples que ultrapassaram o limite de faturamento estadual.
 * ICMS é recolhido por fora do DAS, demais tributos continuam no Simples.
 * Usa CST ao invés de CSOSN para o ICMS.
 */
class CRT2CupomController extends BaseCupomFiscalController
{
  /**
   * Processa os impostos para Simples Nacional com Excesso de Sublimite
   */
  protected function processarImpostosProduto($produto, $index)
  {
    $item = $index + 1;

    // Verificar se é combustível
    if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
      $this->nfe->tagcomb($this->addCombustivelTag($produto, $item));
      $this->nfe->tagICMS($this->addICMSCombTag($produto, $item));
      $this->baseTotalIcms += 1000.00;
    } else {
      // ICMS como regime normal (usa CST ao invés de CSOSN)
      if (isset($produto['icms'])) {
        $this->nfe->tagICMS($this->generateICMSData($produto['icms'], $item));
        $this->baseTotalIcms += $this->baseCalculo;
      } else {
        $this->nfe->tagICMS($this->generateICMSDefault($produto, $item));
      }
    }

    // PIS e COFINS simplificados (ainda no Simples para tributos federais)
    $this->nfe->tagPIS($this->generatePisDataSimple($produto, $item));
    $this->nfe->tagCOFINS($this->generateConfinsDataSimple($produto, $item));
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

    $this->valorIcms += $valorIcms;

    return $std;
  }

  /**
   * Gera ICMS padrão quando não há dados específicos
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
   * Gera dados de PIS simplificados
   */
  protected function generatePisDataSimple($produto, $item)
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
   * Gera dados de COFINS simplificados
   */
  protected function generateConfinsDataSimple($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '06'; // Operação Tributável - Alíquota Zero
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pCOFINS = number_format(0, 2, ".", "");
    $std->vCOFINS = number_format(0, 2, ".", "");

    return $std;
  }
}
