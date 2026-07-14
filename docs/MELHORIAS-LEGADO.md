# Modernização do código legado

Este documento descreve as alterações aplicadas depois da análise do commit-base `851af55`. Ele prevalece sobre pontos divergentes em `DOCUMENTACAO-HUMANA.md` e `DOCUMENTACAO-IA.md`.

## Objetivos

- preservar as rotas públicas existentes;
- reduzir duplicação e responsabilidades misturadas;
- impedir vazamento de credenciais fiscais;
- validar entradas antes de acessar Models/SDKs;
- corrigir defeitos de numeração e totalização;
- tornar falhas previsíveis e respostas JSON consistentes;
- criar verificações locais sem emitir documentos fiscais.

## Nova infraestrutura

| Componente | Responsabilidade |
|---|---|
| `app/Http/JsonRequest.php` | leitura de JSON com detecção de body inválido |
| `app/Http/JsonResponse.php` | respostas JSON UTF-8 consistentes |
| `app/Http/HttpException.php` | erro HTTP de domínio com status/contexto |
| `app/Http/ApiTokenMiddleware.php` | autenticação opcional por token |
| `app/Controllers/LookupController.php` | comportamento comum dos catálogos |
| `app/Models/Concerns/FindsByFilters.php` | filtros SQL com colunas permitidas e valores parametrizados |
| `app/Services/CompanyLogoStorage.php` | validação, gravação e remoção segura de logos |
| `scripts/lint.php` | lint de todos os PHPs |
| `tests/run.php` | regressões de lógica pura, sem banco/SEFAZ/NFS-e |

O runtime mínimo passou a ser PHP 8.1. O `composer.lock` deixou de ser ignorado e deve ser versionado para builds reproduzíveis.

## Autenticação opcional

Adicione ao `.env`:

```dotenv
API_TOKEN="um-token-longo-e-aleatorio"
```

Quando `API_TOKEN` estiver vazio ou ausente, o comportamento legado é mantido. Quando configurado, todas as rotas exigem um dos headers:

```http
Authorization: Bearer um-token-longo-e-aleatorio
```

ou:

```http
X-Auth-Token: um-token-longo-e-aleatorio
```

Os dados do responsável técnico e o CNPJ padrão autorizado para XML também podem ser sobrescritos por `RESP_TEC_CNPJ`, `RESP_TEC_CONTATO`, `RESP_TEC_EMAIL`, `RESP_TEC_TELEFONE`, `RESP_TEC_ID_CSRT` e `AUTXML_CNPJ_PADRAO`.

## Mudanças nos contratos HTTP

- Respostas informativas e 404 agora são objetos JSON.
- JSON malformado retorna `400` antes do controller.
- Filtro ou campo não permitido retorna `422`.
- Criação de empresa retorna `201`.
- Estado/município inexistente retorna `404`, não `401` em texto.
- `/fiscal/emissoes` aceita tanto o body legado cru quanto `{ "filter": ..., "limit": ... }`.
- A listagem/criação/atualização de empresa não devolve `senha`, arquivo PFX, CSC ou CSCId.
- Empresa aceita logo em Base64 e devolve somente `logo_url`; `null` remove a logo na atualização.
- Erros de NFC-e não devolvem mais CSC.

## Banco e Models

- PDO usa `utf8mb4`, exceptions reais e prepared statements nativos.
- Falha de conexão não retorna mais uma string fingindo ser uma conexão.
- Nomes de colunas de filtros são validados por allowlist.
- `UnidadesModel` não concatena mais valores na query e passou a respeitar `limit`.
- Models deixaram de imprimir erro PDO dentro da resposta HTTP.
- Hydrators verificam registro inexistente antes de acessar índices.
- `EmissoesEventosModel($chave)` agora consulta pela chave, em vez de um `id` não definido.
- Empresa aceita CNAE, inscrição municipal e atividade na criação.
- Updates de empresa rejeitam campos desconhecidos.

## Empresa e certificado

- Upload usa base64 estrito, escrita com lock e permissões menos abertas.
- PFX inválido é removido; ao substituir um certificado com sucesso, o antigo é removido.
- Subject CN é interpretado sem depender cegamente de exatamente um `:`.
- Validade inicial e final são verificadas.
- O mesmo conversor compatível com certificados legacy passou a ser usado no teste e na emissão.
- O endpoint de debug que expunha trechos de chaves foi removido.
- Caminhos de certificado/documentos são absolutos internamente e nomes de arquivo usam `basename`.

## Logo da empresa

- `POST /company/create` aceita `logo` opcional em Base64 puro ou Data URL.
- `PUT /company/update/{id}` preserva a logo quando o campo é omitido, substitui quando recebe uma imagem e remove quando recebe `null`.
- Conteúdo é identificado pelos bytes reais; somente PNG, JPEG e WebP são aceitos.
- O limite é 2 MB e 4096 pixels por eixo.
- Arquivos usam nome aleatório em `app/storage/logos`; respostas expõem apenas `logo_url`.
- Escritas que falham no certificado ou banco removem a logo nova; substituição bem-sucedida remove a antiga.
- A coluna `empresa.logo` precisa ser criada conforme `docs/MIGRACAO-LOGO-EMPRESA.md`.

## NF-e

- Ambiente é convertido para inteiro antes de selecionar série/número de produção.
- Payload, cliente e produtos recebem validação mínima.
- Total bruto usa `produto.total`; desconto, frete e outras despesas entram em `ICMSTot`/`vNF`.
- CRT 2 e CRT 3 acumulam ICMS nos totais.
- CRT 3 acumula IPI, PIS e COFINS.
- O total IBS não inclui mais valor do produto nem CBS.
- `validaCST()` agora trabalha com códigos de dois dígitos.
- Transportadora é gerada quando a modalidade é diferente de `9`.
- Consulta de recibo tem limite de cinco ciclos, evitando recursão indefinida.
- Cancelamento e CC-e validam campos, tipo da emissão e inexistência local.
- URLs públicas são montadas sem depender de barras em `URL_BASE`.

### Combustível

Os valores fixos fictícios foram removidos. Produto com `codigo_anp` agora precisa informar `icms_combustivel` explicitamente:

```json
{
  "codigo_anp": "210203001",
  "descricao_anp": "GASOLINA C COMUM",
  "icms_combustivel": {
    "orig": "0",
    "CST": "61",
    "qBCMonoRet": "10.0000",
    "adRemICMSRet": "1.2200",
    "vICMSMonoRet": "12.20"
  }
}
```

Os campos aceitos são os campos ICMS/monofásicos encaminhados ao NFePHP. A composição correta depende de CST, produto, UF e legislação; valide o XML em homologação.

## NFC-e

- Produção/homologação usa comparação inteira consistente.
- Certificado legacy usa o mesmo fluxo da NF-e.
- Empresas CRT 1/4 usam ICMSSN; CRT 2/3 usam ICMS CST.
- PIS e COFINS são enviados por suas tags próprias.
- Frete, desconto e acréscimo entram em `vNF`.
- Resposta do recibo é mantida na propriedade usada para protocolar XML.
- Polling também possui limite.
- Cancelamento valida justificativa, confere `tipo=NFCE` e persiste o XML em `emissoes_eventos`.

## NFS-e

- Série/número continuam vindo de `serie_nfse*`/`numero_nfse*`.
- O incremento agora grava `numero_nfse` ou `numero_nfse_homologacao`; não altera mais NF-e.
- Overrides `servico.serie`/`servico.numero` passam a ser a base do próximo número.
- Retorno `201` é aceito como inteiro ou string numérica.
- Valor do serviço, série, número e arquivo PFX são validados.
- CRT 4 é mapeado para `opSimpNac=2` (MEI).
- Erros internos não expõem detalhes do SDK ao consumidor.

## Dependências

Uma auditoria encontrou cinco advisories médios nas dependências HTTP transitivas. Foram atualizados:

| Pacote | Antes | Depois |
|---|---:|---:|
| `guzzlehttp/guzzle` | 7.10.0 | 7.14.1 |
| `guzzlehttp/psr7` | 2.9.0 | 2.12.5 |
| `guzzlehttp/promises` | 2.3.0 | 2.5.1 |
| `symfony/deprecation-contracts` | 3.6.0 | 3.7.1 |

`composer audit` passou sem advisories depois da atualização.

## Verificação

```bash
composer run check
composer audit
```

Os testes cobrem normalização, CST/CSOSN, direção do CFOP, URL pública, ID DPS, rateio de frete, remoção de segredos e validação/armazenamento/apresentação da logo da empresa.

Não foram executadas chamadas reais à SEFAZ ou NFS-e. O banco configurado continuou inacessível no ambiente de análise; integrações SQL e XML fiscal devem ser verificadas em homologação.

## Pendências que exigem decisão de arquitetura

1. Tornar `API_TOKEN` obrigatório em produção e definir rotação/escopo de credenciais.
2. Reservar numeração fiscal com transação/lock ou serviço próprio. A correção atual não elimina corrida entre requisições simultâneas.
3. Fixar versões estáveis dos SDKs fiscais que ainda usam `dev-master`.
4. Criar migrations do schema e constraints de unicidade por empresa/modelo/ambiente/série/número.
5. Criar fixtures XML por CRT/CST/CSOSN e homologar por UF/município.
6. Implementar contingência NF-e/NFC-e completa, reconciliação e idempotência.
