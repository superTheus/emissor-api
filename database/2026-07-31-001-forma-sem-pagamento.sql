-- Garante o meio de pagamento fiscal exigido para documentos sem efeito financeiro.
-- Migration somente de dados e idempotente.

SET NAMES utf8mb4;

INSERT INTO formas_pagtosefaz (codigo, descricao, cod_meio, meio)
SELECT 90, 'SEM PAGAMENTO', NULL, NULL
 WHERE NOT EXISTS (
       SELECT 1
         FROM formas_pagtosefaz
        WHERE codigo = 90
 );
