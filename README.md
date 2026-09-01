<h1 align="center">Plugin Oficial Efí Bank para Wordpress/Woocommerce</h1>

![Banner APIs Efí Pay](https://gnetbr.com/BJgSIUhlYs)

## Descrição 

Este é o Plugin Oficial fornecido pela [Efí Bank](https://sejaefi.com.br/) para WooCommerce. Com ele, o proprietário da loja pode optar por receber pagamentos por Boleto Bancário, Cartão de Crédito, Pix ou Open Finance. Todo processo é realizado por meio do checkout transparente. Com isso, o comprador não precisa sair do site da loja para efetuar o pagamento.

Caso você tenha alguma dúvida ou sugestão, entre em contato conosco pelo site [Clicando AQUI](https://sejaefi.com.br/fale-conosco).

## Requisitos
* Versão do PHP: 7.x ou superior
* Versão do WooCommerce: 5.x ou superior
* Versão do WordPress: 6.x ou superior

## Instalação automática 

1. Acesse o link em sua loja "Plugins" -> "Adicionar novo" -> No campo de busca, pesquise por "Efí Bank".
2. Clique em "Instalar agora".
4. Após a instalação, clique em "Ativar o Plugin".
5. Configure o plugin em "WooCommerce" > "Configurações" > "Pagamentos"  e comece a receber pagamentos!


## Configuração 

1. Ative o plugin.

2. Configure as credenciais de sua Aplicação. Para criar uma nova Aplicação, entre em sua conta Efí Bank, acesse o menu "API" e clique em "Aplicações" -> "Nova aplicação". Libere os escopos desejados e então insira as credenciais Client ID e Client Secret de produção e homologação nos respectivos campos de configuração do plugin.

3. Configure as opções de pagamento que deseja receber: Boleto, Cartão de Crédito, Open Finance, Pix, Assinatura via Boleto e/ou Assinatura via Cartão de Crédito.

4. Caso utilize a opção de Cartão de Crédito:
   * Insira o Identificador de Conta de sua conta Efí Bank. 
   `Para encontrar o Identificador, entre em sua conta, acesse o menu "API" e clique em "Introdução". Na lateral direita haverá um botão chamado "Identificador de conta" basta clicar e o código identificador será exibido`

5. Caso utilize a opção de Pix:
   * Insira sua Chave Pix cadastrada em sua conta Efí.
   * Insira o seu certificado (arquivo .p12).
   * Marque o campo "Validar mTLS" caso deseje utilizar a validação mTLS em seu servidor.

6. Caso utilize a opção de Open Finance:
   * Insira seu CPF/CNPJ (APENAS NÚMEROS)
   * Nome completo
   * Número da conta Efí (Com dígito e SEM TRAÇO)

7. Recomendamos que antes de disponibilizar os pagamentos, o lojista realize testes de cobrança com o sandbox(ambiente de testes) ativado para verificar se o procedimento de pagamento está acontecendo conforme esperado.

## Cartão de crédito por link de pagamento

Além do cartão por checkout transparente, este fork oferece o gateway **"Efí - Cartão de Crédito (link de pagamento)"**, em que o cliente é levado para a tela de pagamento hospedada pela Efí (`POST /v1/charge/one-step/link`).

A diferença prática está em onde o cartão é digitado. No checkout transparente o formulário fica no domínio da loja e o JavaScript da Efí tokeniza os dados no navegador. No link de pagamento a loja não renderiza campo algum de cartão: ela cria a cobrança, recebe uma URL e redireciona o cliente. Como a tela de pagamento é servida pela Efí, um script malicioso na loja não tem como capturar o cartão, e o escopo de PCI DSS do lojista fica no mínimo.

Em troca, o cliente sai do site durante o pagamento, o que costuma custar conversão. Os dois gateways podem ficar ativos ao mesmo tempo, então dá para comparar antes de decidir.

### Configuração

1. Ative "Habilitar Cartão de Crédito por link de pagamento" e informe as credenciais Client ID e Client Secret (produção e homologação). Este gateway não usa Identificador de Conta, porque ele existe apenas para a tokenização no navegador.
2. Ajuste a validade do link (padrão de 3 dias) e, se quiser, uma mensagem exibida na tela da Efí. A Efí só aceita mensagens de 3 a 80 caracteres, então mensagens menores são ignoradas em vez de fazer a cobrança falhar.
3. Escolha se o cliente vai direto para a tela da Efí ou se passa antes pela página de pedido recebido da loja, onde encontra um botão para abrir o pagamento.

O cartão não presente por link exige a mesma liberação de análise (KYC) que a Efí pede para o checkout transparente. Sem essa liberação, a criação do link é recusada.

### Como o valor é calculado

O total do pedido do WooCommerce é a fonte de verdade, o que mantém a compatibilidade com produtos de doação, preços personalizados e plugins que alteram o preço no carrinho. Os itens são detalhados apenas para o cliente reconhecer a compra na tela da Efí; se a soma dos itens não fechar exatamente com o total do pedido (um desconto de loja aplicado como taxa negativa, por exemplo), a itemização é descartada em favor de um item único com o total, porque cobrar o valor certo importa mais que o detalhamento.

Diferente da API Pix, a API Cobranças trabalha com centavos em número inteiro: R$ 11,00 é enviado como `1100`.

### Confirmação do pagamento

A confirmação chega pela `notification_url`, o mesmo mecanismo dos demais gateways da API Cobranças. O pedido nasce como "Pagamento pendente" e só avança quando a Efí notifica o status `paid`. Se o pagamento falhar, o pedido vai para "Malsucedido" e o cliente pode tentar de novo pela área "Meus pedidos", que gera uma cobrança e um link novos — a Efí não permite reaproveitar um link cuja cobrança já teve tentativa de pagamento.

Os registros ficam em `efi-cartao-link.log`, disponível no botão "Baixar Logs" das configurações do gateway.

### Limitações

Este gateway cobre pagamento avulso no cartão. Assinaturas continuam pelos gateways de recorrência já existentes, e o Pix (inclusive o Pix Automático) não é afetado: o link de pagamento pertence à API Cobranças e não oferece Pix Automático.

## Atualização automática deste fork

Este fork se atualiza pelos [Releases deste repositório](https://github.com/logycware/Plugin-Wordpress-Efi/releases), e não pelo wordpress.org. O plugin declara o header `Update URI` apontando para o GitHub, o que faz o WordPress consultar o repositório através do filtro nativo `update_plugins_github.com` e exibir a nova versão no painel padrão de atualizações ("Painel" > "Atualizações" e a tela de Plugins).

A consulta usa a API pública do GitHub, sem necessidade de token, é guardada em cache por 6 horas e, se o GitHub estiver indisponível, o plugin simplesmente mantém a versão instalada.

### Instalação

O plugin precisa estar instalado em `wp-content/plugins/gerencianet-oficial/`, que é o diretório raiz do ZIP publicado em cada Release. Se você já tem a versão do wordpress.org instalada em `wp-content/plugins/woo-gerencianet-official/`, desative-a e remova essa pasta antes de instalar o fork, para não manter duas cópias do plugin.

### Publicando uma nova versão

1. Atualize `Version` e `GERENCIANET_OFICIAL_VERSION` em `gerencianet-oficial.php` (e o `Stable tag` no `readme.txt`), mantendo os três iguais.
2. Crie a tag e o Release usando a versão como nome, com ou sem o prefixo `v` (por exemplo `v3.2.0.3`).
3. O workflow `.github/workflows/release-asset.yml` gera e anexa automaticamente o asset `gerencianet-oficial.zip` ao Release, sempre com `gerencianet-oficial/` como diretório raiz.

O updater usa esse asset como pacote de atualização. O `zipball_url` gerado pelo GitHub não é utilizado porque o seu diretório raiz inclui o hash do commit, o que faria o WordPress instalar o plugin em uma pasta diferente a cada atualização.

## **Documentação Adicional**

A documentação completa com todos os endpoints e detalhes das APIs está disponível em https://dev.efipay.com.br/docs/modulos/WordPress.

Se você ainda não tem uma conta digital Efí Bank, [abra a sua agora](https://sejaefi.com.br)!

## **Comunidade no Discord**

<a href="https://comunidade.sejaefi.com.br/"><img src="https://efipay.github.io/comunidade-discord-efi/assets/img/thumb-repository.png"></a>

Se você tem a necessidade de integrar seu sistema ou aplicação a uma API completa de pagamentos, desejos de trocar experiências e compartilhar seu conhecimento, conecte-se à [comunidade da Efí no Discord](https://comunidade.sejaefi.com.br/).