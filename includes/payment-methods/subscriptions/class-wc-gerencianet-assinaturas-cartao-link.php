<?php

use GN_Includes\Gerencianet_I18n;

function init_gerencianet_assinaturas_cartao_link() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	};

	/**
	 * Assinatura de cartao pela tela de pagamento hospedada da Efi.
	 *
	 * Diferente do checkout transparente de assinaturas, aqui a loja cria o
	 * plano e devolve um link: o cliente cadastra o cartao no dominio da Efi e
	 * a recorrencia passa a ser debitada automaticamente, sem payment_token nem
	 * Identificador de Conta na loja.
	 */
	class WC_Gerencianet_Assinaturas_Cartao_Link extends WC_Payment_Gateway {

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

			$this->id                 = GERENCIANET_ASSINATURAS_CARTAO_LINK_ID;
			$this->has_fields         = false;
			$this->method_title       = __( 'Efí - Assinaturas via Cartão (link de pagamento)', Gerencianet_I18n::getTextDomain() );
			$this->method_description = __( 'O cliente autoriza a assinatura e cadastra o cartão em uma tela hospedada pela Efí. Nenhum dado de cartão passa pela sua loja.', Gerencianet_I18n::getTextDomain() );

			$this->supports = array(
				'products',
			);

			$this->init_form_fields();

			$this->gerencianetSDK = new Gerencianet_Integration();

			$this->init_settings();

			$title       = trim( (string) $this->get_option( 'gn_subs_card_link_title' ) );
			$description = trim( (string) $this->get_option( 'gn_subs_card_link_description' ) );

			$this->title                         = '' !== $title ? $title : __( 'Assinatura - Cartão de Crédito', Gerencianet_I18n::getTextDomain() );
			$this->description                   = '' !== $description ? $description : __( 'Você será direcionado para o ambiente seguro da Efí para autorizar a assinatura e informar os dados do cartão.', Gerencianet_I18n::getTextDomain() );
			$this->enabled                       = sanitize_text_field( $this->get_option( 'gn_subs_card_link' ) );
			$this->gn_client_id_production       = sanitize_text_field( $this->get_option( 'gn_client_id_production' ) );
			$this->gn_client_secret_production   = sanitize_text_field( $this->get_option( 'gn_client_secret_production' ) );
			$this->gn_client_id_homologation     = sanitize_text_field( $this->get_option( 'gn_client_id_homologation' ) );
			$this->gn_client_secret_homologation = sanitize_text_field( $this->get_option( 'gn_client_secret_homologation' ) );
			$this->gn_sandbox                    = sanitize_text_field( $this->get_option( 'gn_sandbox' ) );
			$this->gn_order_status_after_payment = sanitize_text_field( $this->get_option( 'gn_order_status_after_payment' ) );

			add_action( 'woocommerce_update_options_payment_gateways_' . GERENCIANET_ASSINATURAS_CARTAO_LINK_ID, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_' . strtolower( GERENCIANET_ASSINATURAS_CARTAO_LINK_ID ), array( $this, 'webhook' ) );
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
				'gn_subs_card_link_section'     => array(
					'title'       => __( 'Configurações de recebimento', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'title',
					'description' => __( 'Este gateway exige a mesma liberação de cartão não presente usada nos demais meios de cartão da Efí.', Gerencianet_I18n::getTextDomain() ),
				),
				'gn_subs_card_link'             => array(
					'title'   => __( 'Assinatura por link', Gerencianet_I18n::getTextDomain() ),
					'type'    => 'checkbox',
					'label'   => __( 'Habilitar Assinaturas via Cartão por link de pagamento', Gerencianet_I18n::getTextDomain() ),
					'default' => 'no',
				),
				'gn_subs_card_link_title'       => array(
					'title'       => __( 'Título no checkout', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Nome do meio de pagamento exibido para o cliente.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => __( 'Assinatura - Cartão de Crédito', Gerencianet_I18n::getTextDomain() ),
				),
				'gn_subs_card_link_description' => array(
					'title'       => __( 'Descrição no checkout', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'textarea',
					'description' => __( 'Texto exibido abaixo do título. Deixe claro que o cliente será direcionado para a tela da Efí.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => __( 'Você será direcionado para o ambiente seguro da Efí para autorizar a assinatura e informar os dados do cartão.', Gerencianet_I18n::getTextDomain() ),
				),
				'gn_subs_card_link_auto_redirect' => array(
					'title'       => __( 'Redirecionamento', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'checkbox',
					'label'       => __( 'Enviar o cliente direto para a tela da Efí', Gerencianet_I18n::getTextDomain() ),
					'description' => __( 'Desmarque para levar o cliente à página de pedido recebido da loja, onde encontra um botão para abrir a autorização da assinatura.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'default'     => 'yes',
				),
				'gn_subs_card_link_number_days' => array(
					'title'       => __( 'Validade do link', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'number',
					'description' => __( 'Dias até o link expirar. Se ficar vazio ou zerado, o plugin usa 3 dias.', Gerencianet_I18n::getTextDomain() ),
					'desc_tip'    => false,
					'placeholder' => '3',
					'default'     => '3',
				),
				'gn_subs_card_link_message'     => array(
					'title'       => __( 'Mensagem ao cliente', Gerencianet_I18n::getTextDomain() ),
					'type'        => 'text',
					'description' => __( 'Mensagem opcional exibida na tela de pagamento da Efí. A Efí exige de 3 a 80 caracteres, então mensagens menores são ignoradas.', Gerencianet_I18n::getTextDomain() ),
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
					'description'       => __( 'Clique para baixar os logs de emissão de Assinaturas via Cartão por link.', Gerencianet_I18n::getTextDomain() ),
					'default'           => __( 'Baixar Logs', Gerencianet_I18n::getTextDomain() ),
					'custom_attributes' => array(
						'onclick' => 'location.href="' . admin_url( 'admin-post.php?action=gn_download_logs&log=wc_gerencianet_assinaturas_cartao_link' ) . '";',
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

		private function get_customer_message() {
			$message = $this->clip( $this->get_option( 'gn_subs_card_link_message' ), 80 );
			$length  = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );

			return $length >= 3 ? $message : '';
		}

		private function get_expiration_date() {
			$days = intval( $this->get_option( 'gn_subs_card_link_number_days' ) );

			if ( $days <= 0 ) {
				$days = 3;
			}

			return date( 'Y-m-d', strtotime( '+' . $days . ' days', current_time( 'timestamp' ) ) );
		}

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
				GERENCIANET_ASSINATURAS_CARTAO_LINK_ID
			);
		}

		/**
		 * Le intervalo, repeticoes e nome do plano a partir do produto recorrente
		 * do pedido. O checkout de assinatura so exibe este gateway quando o
		 * carrinho contem um produto com recorrencia habilitada.
		 */
		private function get_plan_meta_from_order( $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();

				if ( ! $product ) {
					continue;
				}

				if ( 'yes' !== $product->get_meta( '_habilitar_recorrencia' ) ) {
					continue;
				}

				return array(
					'name'     => $product->get_name(),
					'interval' => intval( $product->get_meta( '_gerencianet_interval' ) ),
					'repeats'  => intval( $product->get_meta( '_gerencianet_repeats' ) ),
				);
			}

			throw new Exception(
				__( 'Não foi possível identificar o plano de assinatura deste pedido.', Gerencianet_I18n::getTextDomain() ),
				1
			);
		}

		private function build_items( $order ) {
			$total = $this->to_cents( $order->get_total() );

			if ( $total <= 0 ) {
				throw new Exception(
					__( 'Não foi possível criar a assinatura porque o total do pedido é zero.', Gerencianet_I18n::getTextDomain() ),
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
							'name'   => $this->clip( sprintf( __( 'Assinatura - Pedido #%s', Gerencianet_I18n::getTextDomain() ), $order->get_order_number() ), 255 ),
							'value'  => $total,
							'amount' => 1,
						),
					),
					array(),
				);
			}

			return array( $items, $shippings );
		}

		public function process_payment( $order_id ) {

			global $woocommerce;

			$order = wc_get_order( $order_id );

			try {
				$planMeta         = $this->get_plan_meta_from_order( $order );
				list( $items, $shippings ) = $this->build_items( $order );

				$notification_url = strtolower( $woocommerce->api_request_url( GERENCIANET_ASSINATURAS_CARTAO_LINK_ID ) );
				$expirationDate   = $this->get_expiration_date();
				$message          = $this->get_customer_message();
				$email            = sanitize_email( $order->get_billing_email() );

				$this->log_debug(
					'POST /v1/plan :: requisicao',
					$order_id,
					array(
						'plano'      => $planMeta['name'],
						'interval'   => $planMeta['interval'],
						'repeats'    => $planMeta['repeats'],
						'total'      => $order->get_total(),
					)
				);

				$response = $this->gerencianetSDK->create_plan(
					GERENCIANET_ASSINATURAS_CARTAO_LINK_ID,
					$planMeta['name'],
					$planMeta['interval'],
					$planMeta['repeats']
				);
				$plan     = json_decode( $response, true );

				if ( empty( $plan['data']['plan_id'] ) ) {
					throw new Exception(
						__( 'Não foi possível criar o plano de assinatura na Efí. Tente novamente ou entre em contato com o proprietário da loja.', Gerencianet_I18n::getTextDomain() ),
						1
					);
				}

				$plan_id = $plan['data']['plan_id'];

				$this->log_debug(
					'POST /v1/plan/:id/subscription/one-step/link :: requisicao',
					$order_id,
					array(
						'plan_id'     => $plan_id,
						'items'       => $items,
						'shippings'   => $shippings,
						'expire_at'   => $expirationDate,
						'message'     => '' === $message ? '(omitida)' : $message,
						'email'       => $this->mask_email( $email ),
					)
				);

				$response = $this->gerencianetSDK->create_subscription_card_link(
					$plan_id,
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
					'POST /v1/plan/:id/subscription/one-step/link :: resposta',
					$order_id,
					array(
						'body' => is_array( $charge ) ? $charge : array( 'bruto' => $response ),
					)
				);

				$paymentUrl = isset( $charge['data']['payment_url'] ) ? esc_url_raw( $charge['data']['payment_url'] ) : '';

				if ( '' === $paymentUrl ) {
					throw new Exception(
						__( 'Não foi possível gerar o link de autorização da assinatura. Tente novamente ou entre em contato com o proprietário da loja.', Gerencianet_I18n::getTextDomain() ),
						1
					);
				}

				$subscriptionId = isset( $charge['data']['subscription_id'] ) ? $charge['data']['subscription_id'] : '';
				$initialStatus    = isset( $charge['data']['status'] ) ? $charge['data']['status'] : 'new';
				$dataInicio       = isset( $charge['data']['first_execution'] ) ? $charge['data']['first_execution'] : date( 'Y-m-d' );

				Gerencianet_Hpos::update_meta( $order_id, '_gn_subs_card_link_url', $paymentUrl );
				Gerencianet_Hpos::update_meta( $order_id, '_gn_subs_card_link_status', $initialStatus );

				if ( isset( $charge['data']['charge']['id'] ) ) {
					Gerencianet_Hpos::update_meta( $order_id, '_gn_charge_id', $charge['data']['charge']['id'] );
				}

				if ( isset( $charge['data']['charge']['status'] ) ) {
					Gerencianet_Hpos::update_meta( $order_id, '_gn_subs_card_link_charge_status', $charge['data']['charge']['status'] );
				}

				Gerencianet_Hpos::update_meta( $order_id, '_is_subscription', 'yes' );

				$nome_cliente = $order->get_formatted_billing_full_name();
				$sub_id       = $this->criar_assinatura(
					$nome_cliente,
					$initialStatus,
					$subscriptionId,
					$planMeta['name'],
					__( 'Não possui', Gerencianet_I18n::getTextDomain() ),
					$dataInicio,
					'a fazer',
					$order_id
				);

				Gerencianet_Hpos::update_meta( $order_id, '_subscription_id', $sub_id );
				Gerencianet_Hpos::update_meta( $sub_id, '_gn_order_id', $order_id );

				$order->add_order_note(
					sprintf(
						__( 'Link de autorização da assinatura Efí gerado (validade %1$s): %2$s', Gerencianet_I18n::getTextDomain() ),
						$expirationDate,
						$paymentUrl
					)
				);

				$order->update_status( 'pending-payment' );
				wc_reduce_stock_levels( $order_id );
				$woocommerce->cart->empty_cart();

				$redirect = 'yes' === $this->get_option( 'gn_subs_card_link_auto_redirect' )
					? $paymentUrl
					: $this->get_return_url( $order );

				return array(
					'result'   => 'success',
					'redirect' => $redirect,
				);

			} catch ( Exception $e ) {
				$this->log_debug(
					'checkout da assinatura interrompido',
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

			$notification_response = $this->gerencianetSDK->getNotification( GERENCIANET_ASSINATURAS_CARTAO_LINK_ID, $post_notification );
			$notification          = json_decode( $notification_response );

			if ( ! isset( $notification->code ) || 200 != $notification->code || empty( $notification->data ) ) {
				gn_log( 'Notification Request : FAIL ', GERENCIANET_ASSINATURAS_CARTAO_LINK_ID );
				gn_log( $notification, GERENCIANET_ASSINATURAS_CARTAO_LINK_ID );
				exit();
			}

			$notification_data = end( $notification->data );
			$order_id          = isset( $notification_data->custom_id ) ? sanitize_text_field( $notification_data->custom_id ) : '';
			$chargeStatus      = isset( $notification_data->status->current ) ? sanitize_text_field( $notification_data->status->current ) : '';

			Gerencianet_Hpos::update_meta( $order_id, '_notification_subscription', $notification_response );

			$order = '' !== $order_id ? wc_get_order( $order_id ) : false;

			if ( ! $order ) {
				gn_log(
					array(
						'etapa'     => 'notificacao sem pedido correspondente',
						'custom_id' => $order_id,
						'status'    => $chargeStatus,
					),
					GERENCIANET_ASSINATURAS_CARTAO_LINK_ID
				);
				exit();
			}

			Gerencianet_Hpos::update_meta( $order->get_id(), '_gn_subs_card_link_charge_status', $chargeStatus );

			$this->log_debug(
				'notificacao recebida',
				$order->get_id(),
				array(
					'status' => $chargeStatus,
				)
			);

			switch ( $chargeStatus ) {
				case 'paid':
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
					break;
			}

			exit();
		}

		public function criar_assinatura( $nome_cliente, $initial_status, $id_assinatura, $plano, $periodo_de_teste, $data_inicio, $data_fim, $order_id ) {
			if ( ! post_type_exists( 'efi_assinaturas' ) ) {
				return;
			}

			$subscription_id = wp_insert_post(
				array(
					'post_type'   => 'efi_assinaturas',
					'post_title'  => $nome_cliente,
					'post_content'=> '',
					'post_status' => 'publish',
					'post_author' => 1,
				)
			);

			Gerencianet_Hpos::update_meta( $subscription_id, '_status', $this->verifica_status( $initial_status ) );
			Gerencianet_Hpos::update_meta( $subscription_id, '_id_da_assinatura', $id_assinatura );
			Gerencianet_Hpos::update_meta( $subscription_id, '_plano', $plano );
			Gerencianet_Hpos::update_meta( $subscription_id, '_periodo_de_teste', $periodo_de_teste );
			Gerencianet_Hpos::update_meta( $subscription_id, '_data_de_inicio', $data_inicio );
			Gerencianet_Hpos::update_meta( $subscription_id, '_data_fim', $data_fim );
			Gerencianet_Hpos::update_meta( $subscription_id, '_pedido_associado', $order_id );
			Gerencianet_Hpos::update_meta( $subscription_id, '_subs_payment_method', GERENCIANET_ASSINATURAS_CARTAO_LINK_ID );

			return $subscription_id;
		}

		public function verifica_status( $status ) {
			$newStatus = array(
				'new'      => '<mark class="order-status status-pending tips"><span>Aguardando Pagamento</span></mark>',
				'active'   => '<mark class="order-status status-processing tips"><span>Ativa</span></mark>',
				'canceled' => '<mark class="order-status status-failed tips"><span>Cancelada</span></mark>',
				'expired'  => '<mark class="order-status status-completed tips"><span>Expirada</span></mark>',
			);

			return isset( $newStatus[ $status ] ) ? $newStatus[ $status ] : $newStatus['new'];
		}

		public function validate_gn_client_id_production_field( $key, $value ) {
			if ( ! preg_match( '/^Client_Id_[a-zA-Z0-9]{40}$/', $value ) ) {
				WC_Admin_Settings::add_error( 'Insira o Client_Id de Produção.' );
				$this->update_option( 'gn_subs_card_link', 'no' );
				$value = '';
			}

			return $value;
		}

		public function validate_gn_client_secret_production_field( $key, $value ) {
			if ( ! preg_match( '/^Client_Secret_[a-zA-Z0-9]{40}$/', $value ) ) {
				WC_Admin_Settings::add_error( 'Insira o Client_Secret de Produção.' );
				$this->update_option( 'gn_subs_card_link', 'no' );
				$value = '';
			}

			return $value;
		}

		public function validate_gn_client_id_homologation_field( $key, $value ) {
			if ( ! preg_match( '/^Client_Id_[a-zA-Z0-9]{40}$/', $value ) ) {
				WC_Admin_Settings::add_error( 'Insira o Client_Id de Homologação.' );
				$this->update_option( 'gn_subs_card_link', 'no' );
				$value = '';
			}

			return $value;
		}

		public function validate_gn_client_secret_homologation_field( $key, $value ) {
			if ( ! preg_match( '/^Client_Secret_[a-zA-Z0-9]{40}$/', $value ) ) {
				WC_Admin_Settings::add_error( 'Insira o Client_Secret de Homologação.' );
				$this->update_option( 'gn_subs_card_link', 'no' );
				$value = '';
			}

			return $value;
		}
	}
}
