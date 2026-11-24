# Assinatura Eletrônica Educacional

Plugin WordPress para a escola emitir contratos de prestação de serviços educacionais, acompanhar o aceite pelo responsável e entregar uma cópia verificável. A versão distribuída já inclui o gerador de PDF e o leitor de páginas.

[Baixar o ZIP instalável da versão 1.1.2](https://raw.githubusercontent.com/paulommaeda/assinatura-eletronica/main/dist/assinatura-eletronica-1.1.2.zip)

## Instalação

1. No WordPress, abra **Plugins → Adicionar plugin → Enviar plugin**, selecione o ZIP acima e ative **Assinatura Eletrônica Educacional**.
2. Em **Contratos educacionais → Configurações**, informe o nome da instituição e o prazo dos convites. Defina a guarda documental com a equipe responsável.
3. Cadastre em **Modelos** o texto aprovado pela escola e as declarações específicas que o responsável deve marcar. Cada revisão cria uma nova versão. O plugin acrescenta a declaração geral de leitura.
4. Em **Emitir contrato**, escolha o modelo e preencha o RA, o nome do aluno e os dados do responsável pelo contrato, incluindo CPF e data de nascimento. A escola deve conferir a legitimidade do responsável e o endereço de e-mail no cadastro institucional.
5. Para instalar novas versões pelo painel, abra **Painel → Atualizações**. A opção **Ativar atualizações automáticas** aparece em **Plugins instalados** quando disponível no site. O pacote é conferido por SHA-256 antes da instalação.

Requer WordPress 6.3+, PHP 8.1+ com DOM, MBString e GD, MySQL/MariaDB com InnoDB e HTTPS. O servidor precisa de diretório temporário gravável fora da raiz pública e conexão HTTPS de saída para consultar as atualizações no GitHub. A entrega utiliza o serviço de e-mail configurado para o WordPress. Teste o ciclo com um responsável de teste antes de emitir contratos reais. Em multisite, ative em cada site desejado.

Administradores e usuários com o papel **Gestor de contratos** podem operar a emissão e acessar evidências. Apenas administradores com a permissão de gerenciar opções configuram a guarda ou autorizam o descarte definitivo.

## Modelos e emissão

O editor aceita texto, parágrafos, títulos, negrito, itálico, listas e tabelas HTML. O contrato é emitido em A4. Os campos nativos são {{instituicao}}, {{aluno}}, {{ra}}, {{responsavel}}, {{cpf_mascarado}}, {{email}}, {{telefone}}, {{data_emissao}} e {{protocolo}}. Use nomes em minúsculas e sublinhados nas variáveis próprias, como {{curso}} e {{ano_letivo}}. Campos usados no modelo ou nas declarações precisam ter valor. Cada revisão preserva os contratos anteriores.

A emissão guarda o HTML, as declarações e o PDF daquela versão. O CPF passa pela validação dos dígitos e é persistido como HMAC individual por contrato e dois últimos dígitos. A data de nascimento do responsável é obrigatória e fica registrada como HMAC por contrato para a confirmação de identidade. O telefone é opcional. A instituição escolhe quais dados adicionais incluir no texto contratual. Dados incluídos no texto fazem parte das cópias congeladas.

Em **Envio em lote**, selecione um modelo e envie até 25 registros por arquivo CSV UTF-8, com separador ponto e vírgula. Informe a data de nascimento do responsável na coluna `guardian_birth_date` em DD/MM/AAAA ou AAAA-MM-DD. A coluna external_ref é obrigatória no lote. Use uma referência própria para cada matrícula e instrumento, por exemplo 2027-00123-CONTRATO. Uma reimportação idêntica recupera o contrato existente. A mesma referência com conteúdo diferente gera conflito.

~~~csv
external_ref;student_ref;student_name;guardian_name;guardian_cpf;guardian_birth_date;guardian_email;guardian_phone;curso;ano_letivo
~~~

O painel apresenta os totais de contratos emitidos, registros já processados e erros, com o resultado de cada linha. As mensagens do lote entram em fila e o WP-Cron processa até 12 entregas a cada cinco minutos. Configure o cron do WordPress da hospedagem se o tráfego for baixo. Falhas temporárias de transporte voltam à fila com intervalo crescente. O registro de envio indica aceitação pelo serviço de e-mail. Consulte também os registros do SMTP para acompanhar entrega ou devolução.

## Portal do responsável

O link individual chega por e-mail. O responsável confirma o próprio CPF e a própria data de nascimento e acessa o contrato em uma sessão de uma hora. O segredo do convite fica no fragmento da URL. O portal é servido pelo próprio plugin, com recursos locais. Configure o cache/CDN para respeitar no-store e excluir o parâmetro edu_sign e as rotas /edu-sign/v1/.

O portal exibe o PDF inteiro em rolagem contínua e orienta o responsável a chegar ao final do contrato. Ao confirmar as declarações e registrar a ciência, o portal solicita o código por e-mail. Se o serviço de e-mail recusar o envio, a página informa a falha e oferece nova tentativa. O servidor vincula a versão, as ciências e o tempo decorrido à sessão. O avanço informado pelo navegador é um indício de navegação. A manifestação de ciência é declarada pela própria pessoa. Nos contratos recebidos como PDF do ERP, se o leitor de páginas estiver indisponível, o responsável pode baixar o original e confirmar a leitura do arquivo. O certificado identifica essa modalidade.

O código por e-mail dura dez minutos. Cada código admite cinco tentativas. Há intervalo de um minuto entre pedidos e limite de cinco emissões por hora por contrato. O reenvio revoga o código anterior. Para assinar, o responsável informa o código e aceita expressamente a declaração de assinatura. O código é vinculado ao contrato e à sessão. A conclusão tem proteção contra duplo clique e concorrência.

O PDF final incorpora as páginas originais e anexa o certificado de auditoria. O e-mail de confirmação inclui o protocolo, o SHA-256 final e o link de download com confirmação do CPF e da data de nascimento do responsável, válido por 30 dias. O painel permite renovar o comprovante de um contrato assinado. Reenviar um convite ou comprovante renova a credencial e encerra as sessões anteriores.

### Contratos emitidos antes da versão 1.0.2

O gestor pode abrir **Contratos educacionais → Contratos**, selecionar o contrato e usar **Cadastrar data de nascimento** com o dado conferido pela escola. Até esse cadastro, o acesso do responsável pelo portal fica bloqueado, inclusive com uma sessão antiga. O mesmo link volta a funcionar dentro do prazo de validade. Os PDFs e os registros de assinaturas anteriores mantêm seu conteúdo original.

## Conferência, privacidade e guarda

Em **Contratos → detalhe do contrato**, baixe o PDF e use **Conferir e baixar evidências**. A conferência recalcula os hashes dos arquivos, verifica o documento emitido, os registros de ciência, a sequência de eventos e os selos HMAC-SHA-256 antes de exportar o JSON. O dossiê inclui a identificação do responsável, o texto de cada ciência, o horário, o tempo desde a abertura e a sessão que confirmou a assinatura. O painel e o ERP entregam o mesmo conjunto de evidências. Os horários dos eventos são registrados em UTC.

Por padrão, a trilha usa identificadores pseudonimizados para o IP e o navegador. Em **Configurações**, a instituição pode incluir os dados de conexão legíveis nos novos eventos de responsáveis e operadores. O endereço registrado é o informado ao PHP pela hospedagem e pode ser o de um proxy. Os PDFs ficam em uma tabela binária privada e o download exige autorização.

Para conferir uma cópia fora do WordPress, compare o SHA-256 do arquivo com o valor recebido no comprovante. Use **Get-FileHash arquivo.pdf -Algorithm SHA256** no PowerShell ou **sha256sum arquivo.pdf** no Linux. O certificado identifica o hash anterior à assinatura e o selo de auditoria anterior ao fechamento. O hash do PDF final é registrado após gerar o arquivo e enviado por um canal separado.

Configure uma chave estável e aleatória antes da primeira emissão. Em wp-config.php, defina a constante EDU_SIGN_KEY com um segredo de pelo menos 32 bytes de entropia, guardado fora do banco e do repositório. Gere o segredo no servidor com **openssl rand -hex 32**. Sem essa constante, o plugin usa a chave de autenticação de wp_salt('auth'). A continuidade dos convites, da conferência do CPF e da data de nascimento e dos selos depende da mesma chave. Inclua a chave e o banco com os documentos nos backups protegidos, com restauração testada. O relógio UTC e os selos são do servidor da instituição.

O prazo de guarda começa na assinatura ou no vencimento do contrato encerrado sem assinatura. O padrão zero deixa a revisão sob controle da escola. Com prazo positivo, o cron arquiva os elegíveis e encerra o acesso público. O descarte definitivo exige contrato arquivado, prazo vencido, administrador e confirmação do protocolo. A escola deve conferir eventual obrigação de conservação antes de autorizar o descarte e aplicar sua política também aos backups. Os registros técnicos transitórios de OTP, sessão e limitação são limpos periodicamente depois da expiração. Desativar o plugin preserva o acervo.

A implementação registra assinatura eletrônica com OTP por e-mail e selagem do servidor. O enquadramento jurídico do método, as declarações, o tratamento dos dados de alunos e os prazos de guarda devem ser avaliados pela assessoria da instituição. A classificação como assinatura avançada depende das condições concretas de identificação e controle. Certificado ICP-Brasil e carimbo do tempo externo RFC 3161 exigem serviços próprios. [MP 2.200-2, art. 10](https://www2.camara.leg.br/legin/fed/medpro/2001/medidaprovisoria-2200-2-24-agosto-2001-391394-publicacaooriginal-1-pe.html) · [LGPD](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm)

## Integração com ERP

A partir da versão 1.1.0, envie o PDF gerado pelo ERP Educacional, acompanhe a assinatura por protocolo ou referência externa e receba o resultado por webhook. O pacote inclui o [guia de integração com exemplos de emissão, consulta e validação do retorno](INTEGRACAO-ERP.md).

Use a base /wp-json/edu-sign/v1 e uma conta dedicada com o papel **Gestor de contratos**. Autentique via HTTPS com uma senha de aplicativo do WordPress. Para o painel autenticado por cookie, a REST API do WordPress exige o nonce wp_rest em X-WP-Nonce. A permissão verificada é manage_edu_contracts.

| Método | Rota | Resultado |
| --- | --- | --- |
| GET | /templates | IDs e versões dos modelos vigentes |
| POST | /contracts | Emite um contrato. Retorna 201 para novo, 200 para repetido e 409 para referência conflitante |
| GET | /contracts?external_ref={referência} | Recupera um contrato pela chave do ERP |
| GET | /contracts/{uuid} | Situação, vencimento, data de assinatura e hash final |
| GET | /contracts/{uuid}/document/issued | PDF integral da emissão |
| GET | /contracts/{uuid}/document/signed | PDF assinado |
| GET | /contracts/{uuid}/evidence | Dossiê JSON com identificação, verificação de integridade, ciências por sessão, sessão signatária e eventos |
| POST | /contracts/{uuid}/send | Envia um rascunho |
| POST | /contracts/{uuid}/resend | Renova o convite ou comprovante |
| POST | /contracts/{uuid}/cancel | Cancela um contrato aberto |
| POST | /contracts/{uuid}/archive | Arquiva um contrato encerrado |

Envie application/json. Substitua os campos de exemplo pelos dados conferidos da instituição, incluindo o CPF e a data de nascimento do responsável pelo contrato. O campo `guardian_birth_date` é obrigatório na emissão e aceita AAAA-MM-DD ou DD/MM/AAAA. A referência externa é opcional na emissão individual e deve ser usada em integrações para reconhecer novas tentativas do mesmo pedido.

~~~json
{
  "template_id": 1,
  "external_ref": "2027-00123-CONTRATO",
  "student_ref": "00123",
  "student_name": "Nome do aluno",
  "guardian_name": "Nome do responsável",
  "guardian_cpf": "<CPF válido>",
  "guardian_birth_date": "1980-04-23",
  "guardian_email": "responsavel@example.org",
  "variables": {"curso": "Ensino Fundamental", "ano_letivo": "2027"},
  "mode": "send"
}
~~~

O hook **edu_contract_signed** recebe o ID interno e o resumo com uuid, external_ref, student_ref, status, signed_at e pdf_sha256. Ele roda depois da confirmação da transação de assinatura. A entrega é repetida se a integração lançar uma exceção ou o processo falhar. O consumidor deve tratar uuid como chave idempotente e sinalizar falhas para permitir nova tentativa.

~~~php
add_action('edu_contract_signed', function ($contract_id, $data) {
    // Enfileire a atualização no ERP usando $data['uuid'] e $data['external_ref'].
}, 10, 2);
~~~

## Publicação de novas versões

O ZIP de instalação fica no diretório dist. Para uma nova versão, ajuste o cabeçalho e a constante VERSION, revise tools/release.json e o link inicial deste arquivo. Em ambiente de desenvolvimento com Composer, PHP e Python 3, execute os comandos abaixo.

~~~sh
composer install --no-dev --prefer-dist
python3 tools/package.py
~~~

O empacotador inclui as dependências de produção, obtém a distribuição fixa do PDF.js quando necessário e verifica seu SHA-256. Ele gera o ZIP com a mesma pasta do plugin e grava o SHA-256 em update.json. Publique os fontes, o arquivo de versão em dist e update.json juntos na branch main. O WordPress consulta esse manifesto e preserva as configurações na atualização. A documentação oficial descreve o filtro de atualização usado pelo plugin. [WordPress Developer Resources](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/)

O código próprio está sob GPL-2.0-or-later. As bibliotecas de PDF, fontes e leitor mantêm as respectivas licenças incluídas no ZIP.
