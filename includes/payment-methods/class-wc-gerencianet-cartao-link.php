<?php

use GN_Includes\Gerencianet_I18n;

function init_gerencianet_cartao_link() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	};

	/**
	 * Cartão de crédito pela tela de pagamento hospedada da Efí.
	 *
	 * O gateway de checkout transparente (WC_Gerencianet_Cartao) renderiza o
	 * formulário do cartão na própria loja. Aqui a loja só cria a cobrança e
	 * manda o cliente para o link devolvido pela Efí, então nenhum dado de
	 * cartão chega ao servidor da loja e o escopo de PCI DSS fica no mínimo.
	 */
	class WC_Gerencianet_Cartao_Link extends WC_Payment_Gateway {

		public $id;
		public $has_fields;
		public $method_title;
		public $method_description;
		public $supports;
		public $gerencianetSDK;
		public $gn_client_id_production;
		public $gn_client_secret_production;
		public $gn_client_id_homologation;
		public $gn_client_secret_homologation;
		public $gn_sandbox;
		public $gn_order_status_after_payment;

		public function __construct() {

			$this->id                 = GERENCIANET_CARTAO_LINK_ID;
			$this->has_fields         = false;
			$this->method_title       = __( 'Efí - Cartão de Crédito (link de pagamento)', Gerencianet_I18n::getTextDomain() );
			$this->method_description = __( 'O cliente é levado para uma tela de pagamento hospedada pela Efí. Os dados do cartão não passam pela sua loja.', Gerencianet_I18n::getTextDomain() );

			$this->supports = array(
				'products',
			);

			$this->init_form_fields();

			$this->gerencianetSDK = new Gerencianet_Integration();

			$this->init_settings();

			$title       = trim( (string) $this->get_option( 'gn_card_link_title' ) );
			$description = trim( (string) $this->get_option( 'gn_card_link_description' ) );

			$this->title                         = '' !== $title ? $title : __( 'Cartão de Crédito', Gerencianet_I18n::getTextDomain() );
			$this->description                   = '' !== $description ? $description : __( 'Você será direcionado para o ambiente seguro da Efí para informar os dados do cartão.', Gerencianet_I18n::getTextDomain() );
			$this->enabled                       = sanitize_text_field( $this->get_option( 'gn_card_link' ) );
			$this->gn_client_id_production       = sanitize_text_field( $this->get_option( 'gn_client_id_production' ) );
			$this->gn_client_secret_production   = sanitize_text_field( $this->get_option( 'gn_client_secret_production' ) );
			$this->gn_client_id_homologation     = sanitize_text_field( $this->get_option( 'gn_client_id_homologation' ) );
			$this->gn_client_secret_homologation = sanitize_text_field( $this->get_option( 'gn_client_secret_homologation' ) );
			$this->gn_sandbox                    = sanitize_text_field( $this->get_option( 'gn_sandbox' ) );
			$this->gn_order_status_after_payment = sanitize_text_field( $this->get_option( 'gn_order_status_after_payment' ) );

			add_action( 'woocommerce_update_options_payment_gateways_' . GERENCIANET_CARTAO_LINK_ID, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_' . strtolower( GERENCIANET_CARTAO_LINK_ID ), array( $this, 'webhook' ) );
		}

		public function init_form_fields() {

			$this->form_fields = array(
				'gn_api_section'                => array(
					'title'       => __( 'Credenciais Efí', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => __( "<a href='https://gerencianet.com.br/artigo/como-obter-chaves-client-id-e-client-secret-na-api/#versao-7' target='_blank'>Clique aqui para obter seu Client_Id e Client_Secret! </a>", Gerencianet_I18n::getTextDomain() ),
				),
				'gn_client_id_production'       => array(
					'title'       => __( 'Client_Id Produção', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_Id. Isso é necessário para receber o pagamento.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_client_secret_production'   => array(
					'title'       => __( 'Client_Secret Produção', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_Secret. Isso é necessário para receber o pagamento.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_client_id_homologation'     => array(
					'title'       => __( 'Client_Id Homologação', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_Id de Homologação. Isso é necessário para testar os pagamentos.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_client_secret_homologation' => array(
					'title'       => __( 'Client_Secret Homologação', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Por favor, insira seu Client_Secret de Homologação. Isso é necessário para testar os pagamentos.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_sandbox_section'            => array(
					'title'       => __( 'Ambiente Sandbox', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => 'Habilite para usar o ambiente de testes da Efí. Nenhuma cobrança emitida nesse modo poderá ser paga.',
				),
				'gn_sandbox'                    => array(
					'title'   => __( 'Sandbox', Gerencianet_I18n::getTextDomain() ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar o ambiente sandbox', Gerencianet_I18n::getTextDomain() ),
					'default' => 'no',
				),
				'gn_card_link_section'          => array(
					'title'       => __( 'Configurações de recebimento', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => __( 'O pagamento por link exige a mesma liberação de cartão não presente usada no checkout transparente. Se a sua conta ainda não passou pela análise da Efí, a criação do link será recusada.', Gerencianet_I18n::getTextDomain() ),
				),
				'gn_card_link'                  => array(
					'title'   => __( 'Cartão de Crédito por link', Gerencianet_I18n::getTextDomain() ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar Cartão de Crédito por link de pagamento', Gerencianet_I18n::getTextDomain() ),
					'default' => 'no',
				),
				'gn_card_link_title'            => array(
					'title'       => __( 'Título no checkout', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Nome do meio de pagamento exibido para o cliente.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => __( 'Cartão de Crédito', Gerencianet_I18n::getTextDomain() ),
				),
				'gn_card_link_description'      => array(
					'title'       => __( 'Descrição no checkout', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'textarea',
					'description' => __( 'Texto exibido abaixo do título. Deixe claro que o cliente será direcionado para a tela da Efí.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => __( 'Você será direcionado para o ambiente seguro da Efí para informar os dados do cartão.', Gerencianet_I18n::getTextDomain() ),
				),
				'gn_card_link_auto_redirect'    => array(
					'title'       => __( 'Redirecionamento', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'checkbox',
					'label'       => __( 'Enviar o cliente direto para a tela da Efí', Gerencianet_I18n::getTextDomain() ),
					'description' => __( 'Desmarque para levar o cliente à página de pedido recebido da loja, onde ele clica em um botão para abrir a tela de pagamento.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => 'yes',
				),
				'gn_card_link_number_days'      => array(
					'title'       => __( 'Validade do link', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'number',
					'description' => __( 'Dias até o link de pagamento expirar. Se ficar vazio ou zerado, o plugin usa 3 dias.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'placeholder' => '3',
					'default'     => '3',
				),
				'gn_card_link_message'          => array(
					'title'       => __( 'Mensagem ao cliente', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Mensagem opcional exibida na tela de pagamento da Efí. Limite de 80 caracteres.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => '',
				),
				'gn_order_status_after_payment' => array(
					'title'       => __( 'Status do pedido após pagamento', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'select',
					'description' => __( 'Selecione o status do pedido após a confirmação do pagamento.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => true,
					'options'     => wc_get_order_statuses(),
					'default'     => 'wc-processing',
				),
				'download_button'               => array(
					'title'             => __( 'Baixar Logs', Gerencianet_I18n::getTextDomain() ),
					'type'              => 'button',
					'description'       => __( 'Clique para baixar os logs de emissão de cobranças via link de pagamento.', Gerencianet_I18n::getTextDomain() ),
					'default'           => __( 'Baixar Logs', Gerencianet_I18n::getTextDomain() ),
					'custom_attributes' => array(
						'onclick' => 'location.href="' . admin_url( 'admin-post.php?action=gn_download_logs&log=wc_gerencianet_cartao_link' ) . '";',
					),
				),
			);
		}

		public function process_admin_options() {
			parent::process_admin_options();
		}

		public function payment_fields() {

			if ( $this->description ) {
				echo wpautop( wp_kses_post( $this->description ) );
			}

			if ( 'yes' === $this->get_option( 'gn_sandbox' ) ) {
				$sandboxWarn = '<div class="warning-payment" id="wc-gerencianet-messages-sandbox">
                                    <div class="woocommerce-error">' . __( 'O modo Sandbox está ativo. As cobranças emitidas não serão válidas.', Gerencianet_I18n::getTextDomain() ) . '</div>
                                </div>';
				echo wpautop( wp_kses_post( $sandboxWarn ) );
			}
		}

		/**
		 * A API Cobranças trabalha com centavos em número inteiro, diferente da
		 * API Pix, que usa string com duas casas decimais.
		 */
		private function to_cents( $value ) {
			return intval( number_format( (float) $value, 2, '', '' ) );
		}

		private function clip( $text, $length ) {
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );

			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $text, 0, $length );
			}

			return substr( $text, 0, $length );
		}

		/**
		 * Monta os itens da cobrança a partir do que o WooCommerce já calculou.
		 *
		 * O total do pedido é a fonte de verdade: ele contempla preço dinâmico
		 * (doações e preços personalizados), cupons, taxas, frete e impostos.
		 * A itemização existe só para o cliente reconhecer a compra na tela da
		 * Efí, então, se a soma dos itens não fechar exatamente com o total do
		 * pedido, ela é descartada em favor de um item único com o total — é
		 * melhor perder o detalhamento do que cobrar um centavo de diferença.
		 */
		private function build_items( $order ) {
			$total = $this->to_cents( $order->get_total() );

			if ( $total <= 0 ) {
				throw new Exception(
					__( 'Não foi possível criar o link de pagamento porque o total do pedido é zero.', Gerencianet_I18n::getTextDomain() ),
					1
				);
			}

			$items     = array();
			$shippings = array();
			$sum       = 0;

			foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
				$value = $this->to_cents( $item->get_total() );

				if ( $value <= 0 ) {
					continue;
				}

				$name     = $item->get_name();
				$quantity = 'line_item' === $item->get_type() ? (int) $item->get_quantity() : 1;

				if ( $quantity > 1 ) {
					$name = $quantity . 'x ' . $name;
				}

				$items[] = array(
					'name'   => $this->clip( $name, 255 ),
					'value'  => $value,
					'amount' => 1,
				);

				$sum += $value;
			}

			$tax = $this->to_cents( $order->get_total_tax() );

			if ( $tax > 0 ) {
				$items[] = array(
					'name'   => __( 'Taxas', Gerencianet_I18n::getTextDomain() ),
					'value'  => $tax,
					'amount' => 1,
				);

				$sum += $tax;
			}

			$shippingTotal = $this->to_cents( $order->get_shipping_total() );

			if ( $shippingTotal > 0 ) {
				$shippings[] = array(
					'name'  => __( 'Frete', Gerencianet_I18n::getTextDomain() ),
					'value' => $shippingTotal,
				);

				$sum += $shippingTotal;
			}

			if ( empty( $items ) || $sum !== $total ) {
				return array(
					array(
						array(
							'name'   => $this->clip( sprintf( __( 'Pedido #%s', Gerencianet_I18n::getTextDomain() ), $order->get_order_number() ), 255 ),
							'value'  => $total,
							'amount' => 1,
						),
					),
					array(),
				);
			}

			return array( $items, $shippings );
		}

		private function get_expiration_date() {
			$days = intval( $this->get_option( 'gn_card_link_number_days' ) );

			if ( $days <= 0 ) {
				$days = 3;
			}

			return date( 'Y-m-d', strtotime( '+' . $days . ' days', current_time( 'timestamp' ) ) );
		}

		/**
		 * O e-mail vai para a Efí porque ela usa o endereço para avisar o
		 * pagador, mas no log basta o suficiente para reconhecer o cliente.
		 */
		private function mask_email( $email ) {
			if ( ! is_string( $email ) || false === strpos( $email, '@' ) ) {
				return '';
			}

			list( $user, $domain ) = explode( '@', $email, 2 );

			return substr( $user, 0, 2 ) . str_repeat( '*', max( 1, strlen( $user ) - 2 ) ) . '@' . $domain;
		}

		private function log_debug( $step, $order_id, $data = array() ) {
			gn_log(
				array_merge(
					array(
						'etapa'  => $step,
						'pedido' => '#' . $order_id,
					),
					$data
				),
				GERENCIANET_CARTAO_LINK_ID
			);
		}

		public function process_payment( $order_id ) {

			global $woocommerce;

			$order = wc_get_order( $order_id );

			try {
				list( $items, $shippings ) = $this->build_items( $order );

				$notification_url = strtolower( $woocommerce->api_request_url( GERENCIANET_CARTAO_LINK_ID ) );
				$expirationDate   = $this->get_expiration_date();
				$message          = $this->clip( $this->get_option( 'gn_card_link_message' ), 80 );
				$email            = sanitize_email( $order->get_billing_email() );

				$this->log_debug(
					'POST /v1/charge/one-step/link :: requisicao',
					$order_id,
					array(
						'total_do_pedido' => $order->get_total(),
						'items'           => $items,
						'shippings'       => $shippings,
						'expire_at'       => $expirationDate,
						'email'           => $this->mask_email( $email ),
					)
				);

				$response = $this->gerencianetSDK->one_step_card_link(
					$order_id,
					$items,
					$shippings,
					$notification_url,
					$email,
					$expirationDate,
					$message
				);

				$charge = json_decode( $response, true );

				$this->log_debug(
					'POST /v1/charge/one-step/link :: resposta',
					$order_id,
					array(
						'body' => is_array( $charge ) ? $charge : array( 'bruto' => $response ),
					)
				);

				$paymentUrl = isset( $charge['data']['payment_url'] ) ? esc_url_raw( $charge['data']['payment_url'] ) : '';

				// Sem a URL não há como o cliente pagar, e deixar o pedido
				// seguir daria a impressão de que o checkout deu certo.
				if ( '' === $paymentUrl ) {
					throw new Exception(
						__( 'Não foi possível gerar o link de pagamento do cartão. Tente novamente ou entre em contato com o proprietário da loja.', Gerencianet_I18n::getTextDomain() ),
						1
					);
				}

				if ( isset( $charge['data']['charge_id'] ) ) {
					Gerencianet_Hpos::update_meta( $order_id, '_gn_charge_id', $charge['data']['charge_id'] );
				}

				if ( isset( $charge['data']['status'] ) ) {
					Gerencianet_Hpos::update_meta( $order_id, '_gn_card_link_status', $charge['data']['status'] );
				}

				Gerencianet_Hpos::update_meta( $order_id, '_gn_card_link_url', $paymentUrl );

				$order->add_order_note(
					sprintf(
						__( 'Link de pagamento da Efí gerado (validade %1$s): %2$s', Gerencianet_I18n::getTextDomain() ),
						$expirationDate,
						$paymentUrl
					)
				);

				$order->update_status( 'pending' );
				wc_reduce_stock_levels( $order_id );
				$woocommerce->cart->empty_cart();

				$redirect = 'yes' === $this->get_option( 'gn_card_link_auto_redirect' )
					? $paymentUrl
					: $this->get_return_url( $order );

				return array(
					'result'   => 'success',
					'redirect' => $redirect,
				);

			} catch ( Exception $e ) {
				$this->log_debug(
					'checkout interrompido',
					$order_id,
					array(
						'mensagem' => $e->getMessage(),
					)
				);

				wc_add_notice( $e->getMessage(), 'error' );
				return;
			}
		}

		public function webhook() {
			header( 'HTTP/1.0 200 OK' );

			$post_notification = isset( $_POST['notification'] ) ? sanitize_text_field( $_POST['notification'] ) : '';

			if ( '' === $post_notification ) {
				wp_die( __( 'Request Failure', Gerencianet_I18n::getTextDomain() ) );
			}

			$notification = json_decode( $this->gerencianetSDK->getNotification( GERENCIANET_CARTAO_LINK_ID, $post_notification ) );

			if ( ! isset( $notification->code ) || 200 != $notification->code || empty( $notification->data ) ) {
				gn_log( 'Notification Request : FAIL ', GERENCIANET_CARTAO_LINK_ID );
				gn_log( $notification, GERENCIANET_CARTAO_LINK_ID );
				exit();
			}

			// A Efí devolve todo o histórico da cobrança; o estado atual é a
			// última entrada.
			$notification_data = end( $notification->data );

			$order_id     = isset( $notification_data->custom_id ) ? sanitize_text_field( $notification_data->custom_id ) : '';
			$chargeStatus = isset( $notification_data->status->current ) ? sanitize_text_field( $notification_data->status->current ) : '';

			$order = '' !== $order_id ? wc_get_order( $order_id ) : false;

			if ( ! $order ) {
				gn_log(
					array(
						'etapa'     => 'notificacao sem pedido correspondente',
						'custom_id' => $order_id,
						'status'    => $chargeStatus,
					),
					GERENCIANET_CARTAO_LINK_ID
				);
				exit();
			}

			Gerencianet_Hpos::update_meta( $order->get_id(), '_gn_card_link_status', $chargeStatus );

			$this->log_debug(
				'notificacao recebida',
				$order->get_id(),
				array(
					'status' => $chargeStatus,
				)
			);

			$this->apply_charge_status( $order, $chargeStatus );

			exit();
		}

		private function apply_charge_status( $order, $chargeStatus ) {
			switch ( $chargeStatus ) {
				case 'paid':
					// A Efí notifica "paid" e depois "settled"; sem essa guarda
					// o pedido seria concluído duas vezes.
					if ( ! $order->is_paid() ) {
						$order->update_status( $this->gn_order_status_after_payment );
						$order->payment_complete();
					}
					break;
				case 'unpaid':
					$order->update_status( 'failed' );
					break;
				case 'refunded':
					$order->update_status( 'refunded' );
					break;
				case 'contested':
					$order->update_status( 'failed' );
					break;
				case 'canceled':
					$order->update_status( 'cancelled' );
					break;
				default:
					// link, new, waiting e settled não mudam o pedido.
					break;
			}
		}
	}
}
