# Migração: logo da empresa

Esta migração habilita o campo `logo` nas rotas de criação e atualização de empresa.

## Aplicação no banco

Execute uma única vez no mesmo banco configurado pela API, antes de publicar a nova versão:

```sql
ALTER TABLE empresa
  ADD COLUMN logo VARCHAR(255) NULL AFTER atividade;
```

Para desfazer a alteração:

```sql
ALTER TABLE empresa
  DROP COLUMN logo;
```

Antes do rollback, remova ou arquive os arquivos de `app/storage/logos` caso não sejam mais necessários.

## Contrato HTTP

As rotas continuam recebendo `Content-Type: application/json`.

- `POST /company/create`: `logo` é opcional e aceita Base64 puro ou Data URL.
- `PUT /company/update/{id}`: omitir `logo` preserva a atual; enviar uma nova imagem substitui a atual; enviar `null` remove a associação e o arquivo anterior.
- `POST /company/list`, criação e atualização retornam `logo_url`; o nome interno do arquivo não é exposto.
- Formatos aceitos: PNG, JPEG e WebP.
- Tamanho máximo: 2 MB depois de decodificar o Base64.
- Dimensões permitidas: de 1x1 a 4096x4096 pixels.

Exemplo resumido de criação:

```http
POST /company/create
Content-Type: application/json
Authorization: Bearer <API_TOKEN>

{
  "cnpj": "12.345.678/0001-90",
  "razao_social": "Empresa Exemplo Ltda",
  "uf": "AM",
  "codigo_municipio": "1302603",
  "codigo_uf": "13",
  "crt": 1,
  "certificado": "<PFX_EM_BASE64>",
  "senha": "<SENHA_DO_PFX>",
  "logo": "data:image/png;base64,<PNG_EM_BASE64>"
}
```

Exemplo de substituição:

```http
PUT /company/update/7
Content-Type: application/json
Authorization: Bearer <API_TOKEN>

{
  "logo": "data:image/webp;base64,<WEBP_EM_BASE64>"
}
```

Exemplo de remoção:

```json
{
  "logo": null
}
```

Resposta parcial:

```json
{
  "id": 7,
  "cnpj": "12345678000190",
  "razao_social": "Empresa Exemplo Ltda",
  "logo_url": "https://api.exemplo.com/app/storage/logos/logo_8f2c....png"
}
```

`logo_url` depende de `URL_BASE`. O processo PHP precisa de permissão de escrita em `app/storage/logos`, e o servidor HTTP precisa permitir leitura pública dessa pasta se a URL for consumida diretamente por clientes.

O escopo desta alteração é o cadastro, a atualização e a consulta da logo. A renderização de DANFE, DANFCE e documentos de NFS-e continua sem injeção automática dessa imagem.

## Erros esperados

Imagem inválida retorna HTTP `422`, por exemplo:

```json
{
  "error": "Formato de logo não permitido. Use PNG, JPEG ou WebP."
}
```

Falhas internas de disco ou banco retornam HTTP `500` sem expor caminhos do servidor.
