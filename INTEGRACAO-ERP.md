# Integração do ERP Educacional com a assinatura eletrônica

Disponível na versão 1.1.0. O ERP envia o mesmo PDF que a escola imprime. O plugin guarda os bytes originais, apresenta as páginas ao responsável, registra a assinatura e devolve o resultado ao ERP.

## Preparar os dois lados

No WordPress de assinaturas, crie um usuário dedicado com o papel **Gestor de contratos**. No perfil desse usuário, gere uma **Senha de aplicativo** para o ERP. As chamadas do servidor do ERP usam HTTP Basic pelo HTTPS com o login WordPress e essa senha. A base é `https://assinaturas.example.org/wp-json/edu-sign/v1`. As rotas exigem a permissão `manage_edu_contracts`. [Autenticação da REST API do WordPress](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)

Para receber avisos, o ERP precisa expor uma rota HTTPS com POST. Em **Contratos educacionais → Configurações → Retorno ao ERP Educacional**, cadastre a URL dessa rota e um segredo compartilhado. Gere um valor aleatório, por exemplo com `openssl rand -hex 32`, e registre o mesmo texto no servidor do ERP. Essa chave autentica os avisos. A senha de aplicativo autentica as chamadas ao plugin. Com o destino em branco, o acompanhamento funciona por consulta. Ative o retorno antes de encerrar os contratos que devem gerar avisos.

## Enviar o contrato que o ERP já gerou

1. No ERP, obtenha o documento da coligada e do contrato corretos. A rota de impressão `GET /wp-json/erp-educacional/v1/contratos/{id}/pdf` fornece `nome`, `mime` e `conteudo`. Use `conteudo` diretamente em `document.pdf_base64`. [Impressão de contratos no ERP](https://github.com/paulommaeda/erp-educacional/blob/main/docs/CONTRATOS-HTML-IMPRESSAO.md)
2. Crie uma referência estável para **coligada, contrato e revisão**. Guarde a referência, o SHA-256 do original e os dados do envio para reconhecer novas tentativas. Exemplo de referência: `coligada-2/contrato-5842/rev-3`. RA e período podem compor o título apresentado à família.
3. Faça `POST /contracts` com `Content-Type: application/json`. O exemplo usa marcadores para o CPF conferido do responsável e o PDF em Base64.

~~~json
{
  "external_ref": "coligada-2/contrato-5842/rev-3",
  "student_ref": "00123",
  "student_name": "Aluno de exemplo",
  "guardian_name": "Responsável de exemplo",
  "guardian_cpf": "<CPF do responsável financeiro>",
  "guardian_birth_date": "1980-04-23",
  "guardian_email": "responsavel@example.org",
  "mode": "send",
  "document": {
    "title": "Contrato 00123 – 2027",
    "institution": "Colégio de exemplo",
    "pdf_base64": "<conteudo devolvido pelo ERP>"
  }
}
~~~

| Campo | Como preencher |
| --- | --- |
| `external_ref` | Obrigatório para PDF do ERP. Até 160 caracteres, único no site de assinaturas. Use uma nova referência para uma nova revisão. |
| `student_ref`, `student_name` | RA como texto, preservando os zeros iniciais, e nome do aluno. |
| `guardian_name`, `guardian_email`, `guardian_cpf`, `guardian_birth_date` | Dados do responsável que assinará o contrato. A identificação pede CPF e data de nascimento. O código de assinatura chega ao e-mail informado. A data aceita `AAAA-MM-DD` ou `DD/MM/AAAA`. |
| `guardian_phone` | Telefone opcional. |
| `document.title`, `document.pdf_base64` | Título e PDF original em Base64 simples, sem prefixo de URL de dados. O PDF deve abrir sem senha, ser importável e ter até 8 MiB antes da codificação. |
| `document.institution` | Nome da coligada usado no convite, portal e certificado. Ao omitir, vale a instituição configurada no plugin. |
| `document.sha256` | Opcional. Envie os 64 caracteres hexadecimais do SHA-256 dos bytes do PDF. O plugin calcula o hash em toda emissão e rejeita divergências. |
| `mode` | `send` envia o convite por e-mail e é o padrão. `draft` guarda o PDF para envio posterior com `POST /contracts/{uuid}/send`. |

A emissão por PDF dispensa o cadastro de modelo. Para emitir pelo texto de um modelo local, a mesma rota aceita `template_id` e `variables`. Escolha um formato por requisição.

**Resposta de uma nova emissão com envio** (campos principais):

~~~json
{
  "uuid": "b4486214-a4c5-4704-850b-029c1ce3926c",
  "external_ref": "coligada-2/contrato-5842/rev-3",
  "status": "sent",
  "is_signed": false,
  "source": "erp_pdf",
  "issued_sha256": "48d2efa4df45f21241769b82e7a88875756bf3da768e37812bcc17f00be73f40",
  "signed_at": null,
  "pdf_sha256": null,
  "created": true
}
~~~

**HTTP 201** cria o contrato e **HTTP 200** recupera o existente em uma repetição idêntica (`created: false`). Guarde `uuid` e `issued_sha256` com o contrato do ERP. A mesma referência com outro PDF ou dados de emissão retorna **409**. Em caso de timeout, consulte a referência antes de repetir. Se precisar repetir a emissão, reutilize o mesmo conteúdo e os mesmos bytes. A geração de outro PDF pode alterar seus metadados e seu hash. O reenvio intencional do convite usa `/resend`.

## Conferência pelo responsável

A versão 1.1.1 exibe o PDF original no portal e entrega os módulos do leitor com o tipo JavaScript. Se o leitor de páginas estiver indisponível, o portal oferece uma prévia quando o navegador permite e mantém o botão **Baixar PDF para leitura**. Após baixar e ler, o responsável marca as declarações e clica em **Registrar ciência e continuar**. O assinador vincula essa confirmação à sessão e ao hash do PDF entregue. A auditoria registra a modalidade de download, sem atribuir um percentual de rolagem ao arquivo externo. A partir da versão 1.1.2, as páginas do PDF aparecem em rolagem contínua. Após registrar a ciência, o responsável recebe o código por e-mail para confirmar a assinatura. A tela indica quando o serviço de envio recusa o pedido e permite tentar novamente.

## Consultar e baixar

Todas as rotas abaixo são relativas a `/wp-json/edu-sign/v1` no site de assinaturas.

| Método e rota | Resultado |
| --- | --- |
| `GET /contracts/{uuid}` | Situação atual, `is_signed`, vencimento, data da assinatura, SHA-256 original e final. |
| `GET /contracts?external_ref={referência}` | Mesmo resumo pela chave do ERP. Codifique a referência como parâmetro de URL. Uma referência desconhecida retorna 404. |
| `GET /contracts/{uuid}/document/issued` | Bytes do PDF original em `application/pdf`. |
| `GET /contracts/{uuid}/document/signed` | PDF final com as páginas do original e o certificado. Retorna 404 enquanto o arquivo estiver indisponível. |
| `GET /contracts/{uuid}/evidence` | Dossiê JSON com conferência da integridade e registros de ciência e assinatura. |
| `POST /contracts/{uuid}/send`, `/resend`, `/cancel` | Enviar rascunho, renovar convite ou comprovante, ou cancelar contrato aberto. A resposta traz o resumo. |

| `status` | `is_signed` | Significado para o ERP |
| --- | --- | --- |
| `draft` | `false` | Rascunho aguardando envio. |
| `sent` | `false` | Convite solicitado. O status não comprova a entrega na caixa de entrada. |
| `viewed` | `false` | O responsável abriu o contrato. A assinatura está pendente. |
| `signed` | `true` | Assinatura concluída. `signed_at` está em UTC e `pdf_sha256` identifica o arquivo final. |
| `cancelled` | `false` | Contrato encerrado por cancelamento. |
| `expired` | `false` | O prazo terminou sem assinatura. |

O campo `archived_at` indica arquivamento e preserva o resultado da assinatura. Falha de rede, 401, 403 ou 404 na consulta requer reconciliação. Mantenha o último estado conhecido no ERP até obter uma resposta válida.

## Receber a confirmação no ERP

Para PDFs importados, o plugin coloca um aviso na fila na mesma transação que grava o encerramento. O WP-Cron processa a fila a cada cinco minutos quando executado. O vencimento também é identificado pela manutenção horária. A consulta de status já considera o relógio mesmo antes da manutenção. O painel da assinatura mostra as tentativas em **Contratos → detalhe do contrato → Entregas e integração**. [Funcionamento do WP-Cron](https://developer.wordpress.org/plugins/cron/)

O ERP receberá `POST` na URL configurada. Os eventos são `contract.signed`, `contract.cancelled` e `contract.expired`. Os estados pendentes estão disponíveis na emissão e nas consultas. Exemplo de aviso de assinatura:

~~~json
{
  "event_id": "b4486214-a4c5-4704-850b-029c1ce3926c:signed",
  "event": "contract.signed",
  "uuid": "b4486214-a4c5-4704-850b-029c1ce3926c",
  "external_ref": "coligada-2/contrato-5842/rev-3",
  "status": "signed",
  "is_signed": true,
  "signed_at": "2026-11-20 17:25:00",
  "issued_sha256": "48d2efa4df45f21241769b82e7a88875756bf3da768e37812bcc17f00be73f40",
  "pdf_sha256": "f7ed3195a61ee6560320dab3ad3e674081aa97dae09180a421a99a9b7e2da7b1"
}
~~~

Para cancelamento e vencimento, `is_signed` é `false`, `signed_at` e `pdf_sha256` são `null`. O aviso leva a referência e os hashes. O ERP baixa o PDF assinado pela rota autenticada quando precisar dele.

**Verificação do aviso em PHP.** Este trecho é a verificação a incorporar à futura rota do ERP. `$request` é o `WP_REST_Request` recebido e `$secret` é o segredo configurado nos dois sites.

~~~php
$body = $request->get_body();
$timestamp = (string) $request->get_header('X-Edu-Sign-Timestamp');
$received = (string) $request->get_header('X-Edu-Sign-Signature');

if ($secret === '' || !preg_match('/^[0-9]{10}$/D', $timestamp)
    || abs(time() - (int) $timestamp) > 300) {
    return new WP_Error('erp_webhook_time', 'Aviso não autorizado.', ['status' => 401]);
}
$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
if (!hash_equals($expected, $received)) {
    return new WP_Error('erp_webhook_signature', 'Aviso não autorizado.', ['status' => 401]);
}
$event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
~~~

Use o corpo HTTP original para calcular a assinatura. Para aplicar o resultado:

1. Localize a revisão pela `external_ref` e confira `uuid`, `issued_sha256` e o evento esperado. Consulte o protocolo no plugin se houver divergência.
2. Em uma transação, grave a situação do contrato e registre `event_id` com restrição de unicidade. Para `signed`, guarde a data UTC e o hash final. Confira este hash ao baixar o PDF assinado.
3. Responda **HTTP 204** após persistir ou reconhecer um evento já processado. O plugin considera qualquer 2xx como entrega concluída. Em falha de rede, timeout ou resposta fora de 2xx, ele repete com intervalo crescente e o mesmo `event_id`. A indisponibilidade do ERP não desfaz uma assinatura.
4. Consulte os contratos ainda pendentes para recuperar avisos anteriores à configuração do webhook ou reconciliar uma interrupção. Em uma mesma instalação WordPress, continua disponível o hook `edu_contract_signed` documentado no README.

## Respostas que o emissor deve tratar

| HTTP | Tratamento |
| --- | --- |
| 400, 413, 422 | Corrija JSON, limite do arquivo, CPF, data, e-mail ou PDF conforme a mensagem. |
| 401, 403 | Confira HTTPS, login, senha de aplicativo e permissão do usuário. |
| 404 | Confira o protocolo ou a referência. Para um documento final, consulte a situação antes de tentar baixá-lo. |
| 409 | Verifique se a referência já identifica outra revisão ou se a operação é permitida no estado atual. |
| 5xx ou timeout | Consulte a referência original e repita de forma idempotente quando necessário. |
