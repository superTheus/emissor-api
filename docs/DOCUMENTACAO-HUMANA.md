# Emissor API — documentação técnica e funcional

> Versão para leitura humana. Análise estática do snapshot original `851af55`, realizada em 13/07/2026. Regras fiscais devem ser validadas por profissional fiscal antes de uso em produção.

> **Atualização de legado:** após esta análise, o projeto foi refatorado. As correções e mudanças de contrato vigentes estão em [MELHORIAS-LEGADO.md](MELHORIAS-LEGADO.md). Quando houver divergência, o documento de melhorias prevalece; este arquivo mantém o mapa detalhado do código-base original.

## 1. Visão geral

Esta é uma API PHP para:

- cadastrar empresas e seus certificados A1;
- consultar tabelas fiscais auxiliares;
- emitir, pré-visualizar, cancelar e gerar carta de correção de NF-e (modelo 55);
- emitir e cancelar NFC-e (modelo 65);
- emitir NFS-e pelo padrão nacional, a partir de uma DPS;
- consultar emissões armazenadas;
- testar certificados digitais.

Não existe autenticação no código atual. O `index.php` libera CORS para qualquer origem (`*`) e todos os endpoints ficam públicos se o servidor web não aplicar proteção adicional.

### Tecnologias

| Componente | Uso | Versão instalada |
|---|---|---|
| PHP | runtime | 8.3.16 no ambiente analisado |
| `bramus/router` | roteamento HTTP | 1.6.1 |
| `vlucas/phpdotenv` | leitura do `.env` | 5.6.3 |
| `nfephp-org/sped-nfe` | XML, assinatura e comunicação NF-e/NFC-e | `dev-master` (`c0756c2`) |
| `nfephp-org/sped-da` | DANFE, DANFCE e DACCE | `dev-master` (`1fdf9dd`) |
| `divulgueregional/api-nfse` | DPS e NFS-e nacional | 1.1.4 |
| MySQL/PDO | persistência | driver esperado pelo código |

## 2. Como a requisição percorre o sistema

```text
HTTP → index.php → Routers::execute()
                     ├─ Controllers de consulta → Models → MySQL
                     ├─ CompanyController → certificado em disco + empresa no MySQL
                     ├─ FiscalController → CRT1/2/3/4 → NFePHP → SEFAZ
                     ├─ CupomFiscalController → NFePHP → SEFAZ
                     └─ NotaServicoController → API NFS-e Nacional

Documentos autorizados → app/storage/fiscal/{xml,pdf}
Certificados A1        → app/storage/certificados
Logos das empresas     → app/storage/logos
Metadados/XML/PDF      → tabelas emissoes e emissoes_eventos
```

`FiscalController` é uma fachada: procura a empresa pelo CNPJ, lê o `crt` e seleciona automaticamente:

| CRT | Controller | Tratamento principal |
|---:|---|---|
| 1 | `CRT1Controller` | Simples Nacional, `ICMSSN`/CSOSN; também gera IBS/CBS |
| 2 | `CRT2Controller` | Simples com excesso de sublimite; ICMS por CST, PIS/COFINS zerados |
| 3 | `CRT3Controller` | Regime normal; ICMS, IPI, PIS e COFINS parametrizáveis |
| 4 | `CRT4Controller` | MEI; CSOSN e PIS/COFINS zerados |

## 3. Instalação e configuração

```bash
composer install
```

Variáveis do `.env`:

```dotenv
DB_SERVER="mysql"
DB_NAME="emissor"
DB_USER="usuario"
DB_PASS="senha"
URL_BASE="https://api.exemplo.com/"
```

- O document root deve apontar para a raiz que contém `index.php`.
- O rewrite do servidor precisa encaminhar as rotas a `index.php`; o `.htaccess` existente atende Apache com `mod_rewrite`.
- `URL_BASE` é concatenada ao caminho do PDF. Deve terminar com `/` se necessário para formar uma URL válida.
- O processo PHP precisa escrever em `app/storage/certificados`, `app/storage/logos` e `app/storage/fiscal`.
- O comando `openssl` precisa estar disponível no `PATH`; `UtilsController::openCertificate()` o executa com `proc_open`.
- O timezone global é `America/Manaus`.

## 4. Convenções HTTP

- Requests de negócio usam JSON em UTF-8: `Content-Type: application/json`.
- Não há validação central de JSON inválido. `json_decode()` pode produzir `null` e o erro aparece mais tarde no controller.
- As consultas usam o formato comum abaixo. `filter` é igualdade exata e `limit` limita a quantidade.

```json
{
  "filter": {
    "coluna": "valor"
  },
  "limit": 10
}
```

- Em geral, sucesso retorna `200`; a criação de empresa retorna `201`.
- Há respostas que são texto simples apesar do header global `application/json` (`Home`, `Empresa`, erros de estado/município e 404 de rota).
- O preflight `OPTIONS` sempre retorna `200` antes do router.

## 5. Catálogo completo das 35 rotas

### Rotas informativas e empresa

| Método | Rota | Implementação | Resultado |
|---|---|---|---|
| GET | `/` | closure | texto `Home` |
| POST | `/` | closure | texto `Home` |
| GET | `/company/` | closure | texto `Empresa` |
| POST | `/company/` | closure | texto `Empresa` |
| POST | `/company/list` | `CompanyController::find` | lista empresas e acrescenta dados do certificado |
| POST | `/company/create` | `CompanyController::create` | valida/salva PFX e insere empresa |
| PUT | `/company/update/{id}` | `CompanyController::update` | atualização da empresa carregada por `id` |

### Fiscal

| Método | Rota | Implementação | Resultado |
|---|---|---|---|
| GET | `/fiscal/` | closure | texto `Fiscal` |
| GET | `/fiscal/nfe/` | closure | texto `Emitir NFe` |
| POST | `/fiscal/nfe/` | `FiscalController::createNfe(false)` | emite NF-e modelo 55 |
| POST | `/fiscal/nfe/preview` | `FiscalController::createNfe(true)` | cria XML/DANFE sem assinar, transmitir, persistir ou consumir número |
| POST | `/fiscal/nfe/cancel` | `FiscalController::cancelNfe` | cancela NF-e, cria PDF cancelado e registra evento |
| POST | `/fiscal/nfe/carta` | `FiscalController::gerarCC` | envia CC-e, gera DACCE e registra evento |
| POST | `/fiscal/nfce/` | `CupomFiscalController::createNfe` | emite NFC-e modelo 65 |
| POST | `/fiscal/nfce/cancel` | `CupomFiscalController::cancelNfce` | cancela NFC-e, sem persistir evento |
| GET | `/fiscal/nfse/` | closure | texto `Emitir NFS-e` |
| POST | `/fiscal/nfse/` | `NotaServicoController::emitir` | monta/assina DPS e envia à NFS-e Nacional |
| POST | `/fiscal/emissoes` | `EmissoesController::find` | consulta `emissoes`; o body inteiro vira filtro |
| GET | `/fiscal/certicate/` | closure | texto `Certificado` |
| POST | `/fiscal/certicate/test` | `EmissoesController::verifyCertificate` | valida um PFX base64 recebido |
| GET | `/fiscal/certicate/test/{cnpj}` | `UtilsController::testCertificate` | testa o certificado já salvo da empresa |

> A palavra `certicate` está grafada assim no código e faz parte da URL pública.

### Tabelas auxiliares

| Método | Rota | Controller/Model | Tabela |
|---|---|---|---|
| POST | `/cest/` | `CestController` / `CestModel` | `cest` |
| POST | `/cfop/` | `CfopController` / `CfopModel` | `cfop` |
| POST | `/formas/` | `FormaPagamentoController` / `FormaPagamentoModel` | `formas_pagtosefaz` |
| POST | `/ibpt/` | `IbptController` / `IbptModel` | `ibpt_nacional` |
| POST | `/ncm/` | `NcmController` / `NcmModel` | `ncm` |
| POST | `/origem/` | `OrigemController` / `OrigemModel` | `origem` |
| POST | `/situacao/` | `SituacaoTributariaController` / `SituacaoTributariaModel` | `situacaotributaria` |
| POST | `/unidades/` | `UnidadesController` / `UnidadesModel` | `unidades` |
| POST | `/estados/` | `EstadosController::find` | `estados` |
| POST | `/estados/{uf}` | `EstadosController::find` | estado por UF; pode retornar objeto ou lista vazia |
| GET | `/estados/{uf}` | `EstadosController::findunique` | primeiro estado ou `401` texto |
| POST | `/municipios/` | `MunicipiosController::find` | `municipio` |
| POST | `/municipios/{uf}` | `MunicipiosController::findByUf` | todos os municípios do estado |
| GET | `/municipios/{uf}/{cidade}` | estado + `MunicipiosController::findunique` | primeiro município com nome exato |

Exemplo de consulta:

```bash
curl -X POST "$BASE_URL/ncm/" \
  -H "Content-Type: application/json" \
  -d '{"filter":{"codigo":"84713012"},"limit":1}'
```

Colunas retornadas pelos catálogos:

| Recurso | Colunas observadas no Model |
|---|---|
| CEST | `cest_id`, `ncm_id`, `descricao` |
| CFOP | `id`, `descricao`, `aplicacao` |
| formas | `codigo`, `descricao`, `cod_meio`, `meio` |
| IBPT | `codigo`, `nacional`, `importado` |
| NCM | `id`, `codigo`, `descricao` |
| origem | `id`, `descricao` |
| situação tributária | `id`, `codigo`, `descricao`, `regime` |
| unidades | `id`, `nome`, `sigla` |
| estados | `id`, `nome`, `uf`, `codigo_ibge` |
| municípios | `id`, `id_estado`, `nome`, `codigo_ibge` |

## 6. Cadastro de empresa, certificado e logo

### Criar empresa

`POST /company/create`

O certificado deve ser o conteúdo PFX/P12 em Base64, com ou sem prefixo `data:...;base64,`. A logo é opcional e também é enviada no JSON como Base64 puro ou Data URL. O fluxo é:

1. valida sintaxe base64;
2. grava o arquivo como `app/storage/certificados/certificado_<id>.pfx`;
3. abre o certificado com a senha;
4. compara o documento após `:` do `subject.CN` com o CNPJ informado;
5. verifica as datas inicial e final de validade;
6. se `logo` estiver presente, valida conteúdo, formato, tamanho e dimensões e grava em `app/storage/logos`;
7. insere a empresa guardando somente os nomes dos arquivos.

A logo aceita PNG, JPEG ou WebP, no máximo 2 MB e dimensões de até 4096x4096 pixels. SVG e outros formatos não são aceitos. Se o certificado ou o banco falhar, uma logo nova já gravada é removida.

Exemplo completo compatível com os campos acessados pelo `INSERT`:

```json
{
  "tpamb": 2,
  "cnpj": "12.345.678/0001-90",
  "razao_social": "EMPRESA DE HOMOLOGACAO LTDA",
  "nome_fantasia": "EMPRESA TESTE",
  "telefone": "92999999999",
  "email": "fiscal@empresa.test",
  "cep": "69000000",
  "logradouro": "Avenida Exemplo",
  "numero": "100",
  "bairro": "Centro",
  "cidade": "Manaus",
  "uf": "AM",
  "inscricao_estadual": "041234567",
  "codigo_municipio": "1302603",
  "codigo_uf": "13",
  "situacao_tributaria": "102",
  "crt": 1,
  "logo": "data:image/png;base64,iVBORw0KGgo...",
  "certificado": "MII...BASE64...",
  "senha": "senha-do-pfx",
  "csc": "CSC_PRODUCAO",
  "csc_id": "000001",
  "csc_homologacao": "CSC_HOMOLOGACAO",
  "csc_id_homologacao": "000001",
  "serie_nfce": 1,
  "numero_nfce": 1,
  "serie_nfce_homologacao": 1,
  "numero_nfce_homologacao": 1,
  "serie_nfe": 1,
  "numero_nfe": 1,
  "serie_nfe_homologacao": 1,
  "numero_nfe_homologacao": 1,
  "serie_nfse": 1,
  "numero_nfse": 1,
  "serie_nfse_homologacao": 1,
  "numero_nfse_homologacao": 1
}
```

O campo `logo` é opcional. A resposta não devolve o Base64 nem o nome interno do arquivo; ela inclui `logo_url`, que usa `URL_BASE` como origem.

### Atualizar empresa

`PUT /company/update/{id}`

O Model carrega a linha pelo `id`, aplica os campos recebidos e executa um `UPDATE` de todas as colunas. Assim, uma atualização parcial funciona, embora a rota use PUT. Se `certificado` for enviado, o mesmo fluxo de upload e validação é executado.

Para a logo:

- omitir `logo` preserva a imagem atual;
- enviar uma nova imagem Base64 substitui a atual e remove o arquivo anterior depois do sucesso no banco;
- enviar `"logo": null` remove a logo atual;
- enviar string vazia ou imagem inválida retorna HTTP `422`.

```json
{
  "inscricao_municipal": "12345678",
  "cnae": "6201501",
  "atividade": "Desenvolvimento de software",
  "numero_nfse_homologacao": 15,
  "logo": "data:image/webp;base64,UklGR..."
}
```

### Listar empresa

```json
{
  "filter": {"cnpj": "12345678000190"},
  "limit": 1
}
```

A resposta inclui os dados públicos da empresa, `dados_certificado` sem chave privada e `logo_url`. Senha do PFX, nome do certificado, CSCs e nome interno da logo são removidos pelo controller.

Exemplo parcial de resposta:

```json
{
  "id": 7,
  "cnpj": "12345678000190",
  "razao_social": "EMPRESA DE HOMOLOGACAO LTDA",
  "logo_url": "https://api.exemplo.com/app/storage/logos/logo_8f2c....png",
  "dados_certificado": {
    "titular": "EMPRESA DE HOMOLOGACAO LTDA:12345678000190",
    "valido_de": "2026-01-01T00:00:00-04:00",
    "valido_ate": "2027-01-01T00:00:00-04:00"
  }
}
```

A alteração de banco obrigatória e exemplos isolados de substituição/remoção estão em `MIGRACAO-LOGO-EMPRESA.md`.

Esta alteração cadastra e disponibiliza a logo pela API. Ela ainda não injeta automaticamente a imagem nos PDFs de DANFE/DANFCE ou no documento de NFS-e.

### Testar certificado recebido

`POST /fiscal/certicate/test`

```json
{
  "certificado": "data:application/x-pkcs12;base64,MII...",
  "senha": "senha-do-pfx"
}
```

Sucesso:

```json
{
  "empresa": "EMPRESA TESTE LTDA",
  "cnpj": "12345678000190"
}
```

## 7. NF-e — emissão de nota de produto

### Pré-requisitos cadastrais

A empresa encontrada pelo CNPJ precisa ter, no mínimo:

- CNPJ, razão social, nome fantasia, IE, endereço, UF, códigos IBGE de UF/município e CRT;
- ambiente (`tpamb`: `1` produção; qualquer outro valor normalmente opera como homologação);
- série/número NF-e do ambiente;
- certificado PFX e senha válidos;
- CSC/CSCid são carregados também para NF-e, embora sejam essenciais principalmente na NFC-e.

### Payload base completo

`POST /fiscal/nfe/`

```json
{
  "cnpj": "12.345.678/0001-90",
  "operacao": "VENDA DE MERCADORIA",
  "cfop": "5102",
  "finalidade": 1,
  "consumidor_final": "N",
  "modoEmissao": 1,
  "total": 100.00,
  "total_frete": 10.00,
  "total_desconto": 5.00,
  "total_acrescimo": 0.00,
  "troco": 0.00,
  "observacao": "Pedido 123",
  "cnpj_consulta": "13.937.073/0001-56",
  "cliente": {
    "nome": "CLIENTE EXEMPLO LTDA",
    "tipo_documento": "CNPJ",
    "documento": "98.765.432/0001-10",
    "inscricao_estadual": "123456789",
    "tipo_icms": 1,
    "endereco": {
      "logradouro": "Rua do Cliente",
      "numero": "200",
      "bairro": "Centro",
      "codigo_municipio": "1302603",
      "municipio": "Manaus",
      "uf": "AM",
      "cep": "69000-000"
    }
  },
  "produtos": [
    {
      "codigo": "P001",
      "ean": "SEM GTIN",
      "descricao": "PRODUTO DE TESTE",
      "ncm": "84713012",
      "cfop": "5102",
      "unidade": "UN",
      "quantidade": 2,
      "valor": 50.00,
      "total": 100.00,
      "desconto": 5.00,
      "frete": 10.00,
      "outras_despesas": 0.00,
      "origem": 0,
      "cst_icms": "102",
      "cst_pis": "06",
      "aliquota_pis": 0.00,
      "cst_cofins": "06",
      "aliquota_cofins": 0.00,
      "cst_ibscbs": "000",
      "cclasstrib_ibscbs": "000001",
      "informacoes_adicionais": "Lote 2026-01"
    }
  ],
  "pagamentos": [
    {"codigo": "01", "valorpago": 105.00}
  ],
  "modalidade_frete": 9,
  "quantidade_volumes": 1,
  "especie_volume": "CAIXA",
  "peso_liquido": 1.20,
  "peso_bruto": 1.40,
  "fiscal": {
    "aliquota_ibs_estadual": 0.10,
    "aliquota_ibs_municipal": 0.00,
    "aliquota_cbs": 0.90
  }
}
```

Campos efetivamente obrigatórios porque são acessados diretamente:

- raiz: `cnpj`, `cfop`, `cliente`, `cliente.endereco`, `produtos`;
- cliente: `nome`, `tipo_documento`, `documento`; endereço: `logradouro`, `numero`, `bairro`, `codigo_municipio`, `municipio`, `uf`, `cep`;
- cada produto: `codigo`, `ean`, `descricao`, `ncm`, `cfop`, `unidade`, `quantidade`, `valor`, `total`, `desconto`, `origem`.

`pagamentos` não é explicitamente obrigatório na NF-e, mas ausência pode gerar XML inválido conforme finalidade/operação. Cada código precisa existir em `formas_pagtosefaz`; dele saem `tPag` e `indPag`.

### Campos por regime tributário

#### CRT 1 — Simples Nacional

Por produto:

- `cst_icms` é tratado como CSOSN; fallback `102`;
- CSOSN `101`: aceita `aliquota_credito`;
- `201`/`202`/`203`: `mva`, `reducao_st`, `base_st`, `aliquota_st`, `valor_st`; `201` também crédito;
- `500`: `base_retida`, `aliquota_st_retida`, `valor_st_retido`;
- `900`: `mod_bc_icms`, `reducao`, `aliquota_icms`, `st` e campos ST;
- PIS: `cst_pis`, `aliquota_pis`; COFINS: `cst_cofins`, `aliquota_cofins`;
- IBS/CBS: `cst_ibscbs`, `cclasstrib_ibscbs`; alíquotas vêm de `fiscal` na raiz.

#### CRT 2 — excesso de sublimite

Se `produto.icms` existir:

```json
"icms": {
  "cst": "00",
  "mod_bc": 0,
  "aliquota_icms": 18.00
}
```

Sem `icms`, usa CST `40` (isenta). PIS e COFINS são gerados com CST `06` e valores zero.

#### CRT 3 — regime normal

```json
{
  "icms": {
    "cst": "00",
    "mod_bc": 0,
    "aliquota_icms": 18.00,
    "reducao": 0,
    "st": false,
    "fcp": 2.00
  },
  "ipi": {
    "cst": "50",
    "enquadramento_legal_ipi": "999",
    "aliquota_ipi": 5.00
  },
  "pis": {"cst": "01", "aliquota_pis": 1.65},
  "cofins": {"cst": "01", "aliquota_cofins": 7.60}
}
```

Sem os blocos, ICMS usa `produto.cst_icms` ou `40`; PIS/COFINS usam CST `07` e zero. O bloco ICMS aceita ainda `mod_bc_st`, `mva`, `reducao_st`, `base_st`, `aliquota_st`, `valor_st`.

#### CRT 4 — MEI

Usa `produto.csosn` com suporte real a `102`, `103`, `300`, `400` e `500`; qualquer outro cai em `102`. PIS/COFINS ficam zerados. Quando `observacao` é informada, a mensagem de MEI é acrescentada; porém a tag de informações adicionais só é criada pelo fluxo base quando a observação original não está vazia.

### Combustível

Se `codigo_anp` estiver preenchido, todos os CRTs desviam para as tags de combustível. São exigidos:

```json
{
  "codigo_anp": "210203001",
  "descricao_anp": "GASOLINA C COMUM",
  "gpl_percentual": 0,
  "gas_percentual_nacional": 0,
  "valor_partida": 0
}
```

O ICMS monofásico/ST de combustível está fixado no código com bases e valores de exemplo (`1000`, `18%`, etc.). Esse trecho não calcula imposto a partir do produto e não deve ser usado em produção sem correção fiscal.

### Frete, transportadora e referência

- Se `produto.frete` não vier, o frete da raiz é rateado por `produto.total / total dos produtos`.
- `nota_referencia` gera `refNFe`.
- `modalidade_frete` alimenta `modFrete`.
- A transportadora só é adicionada quando `modalidade_frete === 9` no código atual, condição provavelmente invertida em relação ao significado fiscal usual.

```json
"transportadora": {
  "cnpj": "11222333000144",
  "razao_social": "TRANSPORTADORA LTDA",
  "cidade": "Manaus",
  "uf": "AM",
  "veiculo": {"placa": "ABC1D23", "uf": "AM"}
}
```

### Fluxo interno de autorização

1. Carrega empresa, ambiente, série, número, certificado e configura `Tools` modelo 55.
2. Testa `sefazStatus`; se não obtiver `107`, troca `tpEmis` para `9` e adiciona aviso de contingência.
3. Monta chave e XML 4.00.
4. Gera ide, emitente, destinatário, itens, tributos, totais, transporte, pagamentos e responsáveis.
5. Assina o XML e envia lote síncrono.
6. Trata `100`, `103`, `104` e `105`; demais códigos retornam `403`.
7. Protocola XML, renderiza DANFE, grava XML/PDF, incrementa contador e insere `emissoes` com `tipo=NFE`.

> Apesar de selecionar contingência, o código ainda chama `sefazEnviaLote`; ele não implementa o ciclo operacional completo de contingência/offline.

### Resposta de sucesso

```json
{
  "chave": "13260712345678000190550010000001231123456780",
  "avisos": [],
  "protocolo": "113260000000001",
  "link": "https://api.exemplo.com/app/storage/fiscal/pdf/pdf_...pdf",
  "xml": "<nfeProc>...</nfeProc>",
  "pdf": "JVBERi0xLjQK..."
}
```

### Pré-visualização

`POST /fiscal/nfe/preview` usa o mesmo body. Retorna a mesma estrutura, mas:

- não assina e não envia à SEFAZ;
- não incrementa número;
- não insere em `emissoes`;
- grava em `app/storage/fiscal/xml/preview` e `pdf/preview`;
- o protocolo tende a ser `null`.

O construtor ainda testa a SEFAZ antes do preview, podendo mudar o modo de emissão e gerar aviso.

### Cancelamento de NF-e

`POST /fiscal/nfe/cancel`

```json
{
  "cnpj": "12.345.678/0001-90",
  "chave": "13260712345678000190550010000001231123456780",
  "justificativa": "Cancelamento solicitado por erro no pedido"
}
```

Valida CNPJ, chave e justificativa mínima de 15 caracteres. A emissão precisa existir localmente para fornecer XML e protocolo. Em `cStat 128` ou `135`, protocola o evento, marca o DANFE como cancelado, grava PDF e insere `emissoes_eventos` com `tipo=CANCELAMENTO`.

### Carta de correção

`POST /fiscal/nfe/carta`

```json
{
  "cnpj": "12.345.678/0001-90",
  "chave": "13260712345678000190550010000001231123456780",
  "carta": "Corrige-se o peso bruto para 1,40 kg, permanecendo inalterados os demais dados."
}
```

Aceita `cStat 128`, `135` ou `136`, incrementa `emissoes.sequencia_cc`, gera DACCE e insere evento `CC`. A rota não valida previamente presença/tamanho de `chave` e `carta`.

## 8. NFC-e — cupom fiscal

`POST /fiscal/nfce/`

O payload é semelhante ao de NF-e, mas a implementação é separada e não usa os controllers CRT. Produtos e pagamentos não podem estar vazios.

```json
{
  "cnpj": "12.345.678/0001-90",
  "cfop": "5102",
  "operacao": "VENDA DE MERCADORIA",
  "troco": 0,
  "cliente": {
    "nome": "CONSUMIDOR FINAL",
    "tipo_documento": "CPF",
    "documento": "12345678909"
  },
  "produtos": [
    {
      "codigo": "P001",
      "ean": "SEM GTIN",
      "descricao": "PRODUTO TESTE",
      "ncm": "84713012",
      "cfop": "5102",
      "unidade": "UN",
      "quantidade": 1,
      "valor": 25.00,
      "total": 25.00,
      "desconto": 0,
      "frete": 0,
      "acrescimo": 0,
      "origem": 0
    }
  ],
  "pagamentos": [
    {"codigo": "01", "valorpago": 25.00}
  ]
}
```

Com consumidor identificado e diferente de `CONSUMIDOR FINAL`, o endereço, se enviado, usa os mesmos campos da NF-e. A situação tributária da empresa é usada como CSOSN; alguns valores de `cliente.tipo_icms` servem como fallback.

Fluxo:

1. carrega série/número e CSC do ambiente;
2. monta XML modelo 65, sempre com destino interno, consumidor final e presença física;
3. usa ICMSSN para itens comuns, independentemente do CRT cadastrado;
4. exige ao menos um pagamento;
5. assina, envia, protocola, gera DANFCE 80 mm, salva e incrementa contador NFC-e.

Pontos específicos da implementação:

- o total `vNF` usa apenas a soma de `produto.total`, sem incorporar frete, desconto ou acréscimo nas totalizações finais;
- PIS/COFINS são construídos dentro do objeto passado a `tagimposto`, não por `tagPIS`/`tagCOFINS` como na NF-e;
- não há teste de status SEFAZ nem ativação de contingência, apesar de existir um método privado não usado;
- o erro pode devolver o CSC no JSON, informação sensível;
- o fluxo pressupõe Simples Nacional/CSOSN, mesmo quando a empresa tem CRT 2 ou 3.

### Cancelamento NFC-e

`POST /fiscal/nfce/cancel`

```json
{
  "cnpj": "12.345.678/0001-90",
  "chave": "13260712345678000190650010000001231123456780",
  "justificativa": "Cancelamento por erro de lançamento"
}
```

Em `128` ou `135`, retorna apenas:

```json
{"status":"success","message":"Cancelamento homologado com sucesso!"}
```

Não protocola/salva XML do evento, não gera PDF cancelado e não insere `emissoes_eventos`. Também não valida presença ou tamanho da justificativa antes de acessar os campos.

## 9. NFS-e Nacional

`POST /fiscal/nfse/`

Somente `cnpj` é validado explicitamente. `cliente`, `servico`, `fiscal`, `produtos` e `total` possuem fallbacks, embora a API nacional possa rejeitar uma DPS incompleta.

```json
{
  "cnpj": "12.345.678/0001-90",
  "total": 350.00,
  "observacao": "Consultoria referente a julho/2026",
  "cliente": {
    "nome": "TOMADOR EXEMPLO LTDA",
    "tipo_documento": "CNPJ",
    "documento": "98.765.432/0001-10",
    "email": "financeiro@tomador.test",
    "telefone": "92999999999",
    "endereco": {
      "codigo_municipio": "1302603",
      "cep": "69000000",
      "logradouro": "Rua Exemplo",
      "numero": "200",
      "bairro": "Centro"
    }
  },
  "produtos": [
    {"descricao": "CONSULTORIA EM TECNOLOGIA"}
  ],
  "servico": {
    "serie": 1,
    "numero": 15,
    "dCompet": "2026-07-13",
    "cLocPrestacao": "1302603",
    "cTribNac": "010701",
    "xDescServ": "CONSULTORIA EM TECNOLOGIA",
    "xInfComp": "Contrato 123",
    "cIntContrib": "VENDA-123",
    "tribISSQN": 1,
    "tpRetISSQN": 1,
    "pTotTribSN": 0.00
  },
  "fiscal": {
    "opSimpNac": 3,
    "regApTribSN": 1,
    "regEspTrib": 0
  }
}
```

### Mapeamento DPS

| Origem | DPS |
|---|---|
| empresa CNPJ/IM/telefone/e-mail | `prestador` |
| empresa CRT | `prestador.regTrib` |
| `cliente.documento` | `tomador.cpf` ou `tomador.cnpj` |
| `cliente.nome` | `tomador.xNome` |
| `cliente.endereco.*` | `tomador.endereco` |
| `total` | `valores.vServ` |
| `produtos[].descricao` | `servico.xDescServ`, concatenados por `; ` |
| `observacao` | `servico.xInfComp` |
| `servico.*` | sobrescritas específicas da DPS |

Regime automático:

| CRT | `opSimpNac` | `regApTribSN` |
|---:|---:|---:|
| 1 | 3 | 1 |
| 2 | 3 | 2 |
| 3 | 1 | omitido |
| 4 | 3 | 1 |

O ID tem o formato `DPS + município + tipo de inscrição + documento(14) + série(5) + número(15)`. `dhEmi` é a hora atual menos um minuto.

O SDK monta XML, assina com o PFX e envia. Somente `retorno.codigo === 201` como inteiro é tratado como sucesso. A emissão é gravada com `tipo=NFSE`, XML retornado, PDF vazio e protocolo `idDps`.

Resposta:

```json
{
  "chave": "DPS13026031...",
  "protocolo": "DPS13026031...",
  "avisos": [],
  "xml": "<CompNfse>...</CompNfse>",
  "retorno": {"codigo": 201, "chaveAcesso": "...", "idDps": "..."}
}
```

Inconsistência crítica do contador: a série e o número são lidos dos campos `serie_nfse*`/`numero_nfse*`, mas `atualizaNumero()` grava o incremento em `numero_nfe*`. Portanto, o contador NFS-e não avança e o contador NF-e é alterado. Corrija antes de emitir em produção.

## 10. Consulta de emissões e persistência

`POST /fiscal/emissoes`

Nesta rota, o body já é o filtro; não use o envelope `filter`:

```json
{
  "empresa": "12.345.678/0001-90",
  "tipo": "NFE"
}
```

O CNPJ do filtro `empresa` é normalizado. Não há `limit` acessível pela rota, porque o controller embrulha todo o body como filtro e não repassa um limite separado.

### Tabelas inferidas dos Models

Não há migrations/DDL no repositório e o banco configurado não estava acessível durante a análise. A estrutura abaixo representa as colunas usadas pelo código, sem afirmar tipos SQL ou constraints.

| Tabela | Chave lógica | Colunas usadas |
|---|---|---|
| `empresa` | `id`; busca por `cnpj` | todos os campos do cadastro, certificado, ambiente, CRT e contadores |
| `emissoes` | `chave` | `chave`, `numero`, `serie`, `empresa`, `xml`, `pdf`, `tipo`, `protocolo`, `sequencia_cc` |
| `emissoes_eventos` | `id`; relação por `chave` | `id`, `chave`, `tipo`, `protocolo`, `xml`, `link` |
| catálogos | chave de cada Model | colunas listadas na seção 5 |

`emissoes.tipo` recebe `NFE`, `NFCE` ou `NFSE`. Eventos recebem `CANCELAMENTO` ou `CC`.

Arquivos:

```text
app/storage/certificados/certificado_<uniqid>.pfx
app/storage/logos/logo_<32 caracteres aleatórios>.<png|jpg|webp>
app/storage/fiscal/xml/xml_<chave>.xml
app/storage/fiscal/pdf/pdf_<chave>.pdf
app/storage/fiscal/xml/preview/xml_<chave>.xml
app/storage/fiscal/pdf/preview/pdf_<chave+uniqid>.pdf
```

## 11. Mapeamento de Controllers e Models

| Arquivo | Responsabilidade real |
|---|---|
| `Routers/Routers.php` | declara as 35 rotas, lê JSON e instancia controllers |
| `CompanyController` | lista, cria e atualiza empresa; orquestra certificado |
| `FiscalController` | escolhe o controller NF-e pelo CRT e delega emissão/eventos |
| `Fiscal/BaseFiscalController` | pipeline comum completo de NF-e, SEFAZ, DANFE e persistência |
| `Fiscal/CRT1Controller` | CSOSN, PIS, COFINS e IBS/CBS do CRT 1 |
| `Fiscal/CRT2Controller` | CST ICMS e contribuições zeradas do CRT 2 |
| `Fiscal/CRT3Controller` | ICMS/IPI/PIS/COFINS do regime normal |
| `Fiscal/CRT4Controller` | tributação simplificada MEI |
| `CupomFiscalController` | pipeline próprio de NFC-e e cancelamento |
| `Fiscal/NotaServicoController` | DPS/NFS-e Nacional |
| `EmissoesController` | consulta emissões e valida PFX base64 |
| `EstadosController` | lista/retorna estado; helper interno `findOnly` |
| `MunicipiosController` | lista, procura por UF/nome e resolve estado |
| oito controllers de catálogo | adaptadores finos para `Model::find` |
| `UtilsController` | normalização, certificado, arquivos, chave auxiliar e validações CST/CSOSN |
| `Connection` | abre PDO MySQL a partir do `.env` |
| `CompanyModel` | CRUD e estado completo de empresa/certificado/contadores |
| `EmissoesModel` | CRUD de documentos emitidos e sequência CC-e |
| `EmissoesEventosModel` | CRUD de cancelamentos/cartas |
| dez Models de catálogo | leitura por ID e busca por igualdade |

## 12. Riscos e inconsistências encontrados

Prioridade crítica:

1. Não há autenticação; CORS é `*`; empresas, XMLs, PDFs, senhas de PFX e CSCs podem ser consultados.
2. A rota de empresa devolve segredos e o JSON de erro da NFC-e pode devolver CSC.
3. O contador NFS-e atualiza colunas de NF-e, causando repetição de DPS e corrupção da numeração NF-e.
4. O ICMS de combustível usa valores fixos de exemplo.
5. Não há transação/lock ao ler e incrementar números. Requisições simultâneas podem emitir o mesmo número.

Prioridade alta:

1. `UnidadesModel::find()` concatena chaves e valores diretamente no SQL; os outros `find()` parametrizam valores, mas ainda interpolam nomes de coluna vindos do cliente.
2. `Connection::openConnection()` retorna uma string em caso de falha, em vez de lançar exceção; Models então tentam chamar `prepare()` nessa string.
3. Os Models capturam e imprimem erros PDO, frequentemente permitindo que o controller continue com `200` e `null` ou misturando texto com JSON.
4. NF-e em CRT 2/3 calcula ICMS em `valorIcms`, mas não o soma em `totalIcms`; PIS/COFINS/IPI do CRT 3 também não alimentam todos os totais globais.
5. Na NFC-e, totais ignoram desconto/frete/acréscimo e o fluxo tributário presume ICMSSN para qualquer CRT.
6. A contingência NF-e é apenas parcialmente sinalizada, sem fluxo offline completo.
7. Upload de certificado ocorre antes da validação; certificado inválido deixa arquivo órfão.

Prioridade média:

1. A validação `validaCST()` preenche para três dígitos, mas compara com códigos de dois dígitos; na prática tende a rejeitar o CST informado e usar fallback.
2. `EmissoesEventosModel::__construct($chave)` define `chave`, mas `getById()` consulta por `id`, que não foi definido.
3. `CompanyModel::getById()` não trata `fetch()` falso antes de acessar índices.
4. `CompanyModel::create()` não inclui CNAE, IM e atividade.
5. A CC-e não valida campos antes do uso; cancelamento NFC-e também não.
6. O sucesso NFS-e exige `codigo` inteiro `201`; string `"201"` cai no erro.
7. Não há testes automatizados, schema versionado, paginação, rate limit, logs estruturados ou contrato OpenAPI.

## 13. Ordem recomendada para tornar o projeto seguro em produção

1. Colocar autenticação/autorização e remover segredos de todas as respostas.
2. Corrigir e proteger numeração fiscal com transação, lock e contador próprio por modelo/ambiente/série.
3. Corrigir NFS-e, combustível, totalizações e regras por CRT com testes fiscais de XML.
4. Criar validação de payload por endpoint e padronizar erros JSON/status HTTP.
5. Restringir colunas aceitas em filtros e parametrizar integralmente as consultas.
6. Versionar migrations e criar índices/constraints, especialmente unicidade de chave e numeração.
7. Fixar versões estáveis dos SDKs `dev-master` e executar testes de homologação por UF/município.

## 14. Escopo e limites desta documentação

Foram lidos integralmente todos os arquivos de `app/Controllers`, `app/Models` e `app/Routers`, além de `index.php`, configuração Composer e documentação NFS-e preexistente. A sintaxe de todos os PHPs foi validada com `php -l`.

Não foi feita emissão real nem chamada à SEFAZ/NFS-e, para não gerar efeitos fiscais. O banco configurado não pôde ser resolvido a partir deste ambiente; por isso tipos, índices e constraints SQL não foram inventados e estão explicitamente marcados como inferidos.
