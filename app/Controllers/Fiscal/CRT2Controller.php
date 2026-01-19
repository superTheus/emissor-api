<?php

namespace App\Controllers\Fiscal;

use stdClass;

/**
 * Controller para emissão de NFe para empresas do Simples Nacional com Excesso de Sublimite (CRT 2)
 *
 * Para empresas do Simples que ultrapassaram o limite de faturamento estadual,
 * recolhendo ICMS/ISS fora do regime, mas ainda dentro do Simples Nacional.
 *
 * Características:
 * - ICMS é recolhido por fora do DAS (como regime normal)
 * - Demais tributos federais continuam no Simples Nacional
 * - Usa CST ao invés de CSOSN para o ICMS
 */
class CRT2Controller extends BaseFiscalController
{
  /**
   * Processa os impostos para Simples Nacional com Excesso de Sublimite
   * ICMS como regime normal, demais tributos simplificados
   */
  protected function processarImpostosProduto($produto, $index)
  {
    $item = $index + 1;

    // Verificar se é combustível
    if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
      $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
      $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
      $this->baseTotalIcms += 1000.00;
    } else {
      // ICMS como regime normal (usa CST ao invés de CSOSN)
      if (isset($produto['icms'])) {
        $this->nfe->tagICMS($this->generateICMSData($produto['icms'], $item));
        $this->baseTotalIcms += $this->baseCalculo;
      } else {
        // ICMS padrão para operações sem tributação específica
        $this->nfe->tagICMS($this->generateICMSDefault($produto, $item));
      }
    }

    // PIS e COFINS simplificados (ainda no Simples para tributos federais)
    $this->nfe->tagPIS($this->generatePisDataSimple($produto, $item));
    $this->nfe->tagCOFINS($this->generateConfinsDataSimple($produto, $item));
  }

  /**
   * Gera dados do ICMS para Regime Normal (usado no excesso de sublimite)
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
