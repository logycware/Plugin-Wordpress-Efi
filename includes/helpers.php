<?php
/**
 * Plugin helpers.
 *
 * @package  Gerencianet_Oficial
 */

add_action( 'wp_ajax_gn_check_order_status', 'gn_check_order_status' );
add_action( 'wp_ajax_nopriv_gn_check_order_status', 'gn_check_order_status' );

/**
 * Retorna o status atual do pedido.
 */
function gn_check_order_status() {
    // Verifica se veio um order_id via POST
    if ( ! isset( $_POST['order_id'] ) ) {
        wp_send_json_error( [ 'message' => 'Parâmetros inválidos.' ], 400 );
    }

    $order_id = absint( $_POST['order_id'] );
    $order    = new WC_Order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( [ 'message' => 'Pedido não encontrado.' ], 404 );
    }

    // Pega status atual do pedido (sem "wc-")
    $current_status = $order->get_status();

    // Se quiser retornar também o status completo e qualquer info adicional
    // Ex.: $order_data = $order->get_data();
    // mas aqui vamos só mandar o status
    wp_send_json_success( [ 'current_status' => $current_status ] );
}

if ( ! function_exists( 'gn_efi_get_store_timezone' ) ) {
	function gn_efi_get_store_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'America/Sao_Paulo' );
	}
}

if ( ! function_exists( 'gn_efi_format_webhook_date' ) ) {
	function gn_efi_format_webhook_date( $date_string ) {
		try {
			$date = new DateTime( $date_string );
			$date->setTimezone( gn_efi_get_store_timezone() );
			return $date->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return current_time( 'Y-m-d' );
		}
	}
}

if ( ! function_exists( 'gn_efi_get_pix_rejected_charges' ) ) {
	function gn_efi_get_pix_rejected_charges( $order_id ) {
		$charges = Gerencianet_Hpos::get_meta( $order_id, '_gn_pix_rejected_cobr_charges', true );

		return is_array( $charges ) ? $charges : array();
	}
}

if ( ! function_exists( 'gn_efi_save_pix_rejected_charges' ) ) {
	function gn_efi_save_pix_rejected_charges( $order_id, $charges ) {
		Gerencianet_Hpos::update_meta( $order_id, '_gn_pix_rejected_cobr_charges', $charges );
	}
}

if ( ! function_exists( 'gn_efi_extract_cobr_rejection_date' ) ) {
	function gn_efi_extract_cobr_rejection_date( $cobr ) {
		if ( isset( $cobr['atualizacao'] ) && is_array( $cobr['atualizacao'] ) ) {
			foreach ( array_reverse( $cobr['atualizacao'] ) as $event ) {
				if ( isset( $event['status'], $event['data'] ) && 'REJEITADA' === $event['status'] ) {
					return gn_efi_format_webhook_date( $event['data'] );
				}
			}
		}

		if ( isset( $cobr['tentativas'] ) && is_array( $cobr['tentativas'] ) ) {
			foreach ( array_reverse( $cobr['tentativas'] ) as $attempt ) {
				if ( isset( $attempt['atualizacao'] ) && is_array( $attempt['atualizacao'] ) ) {
					foreach ( array_reverse( $attempt['atualizacao'] ) as $event ) {
						if ( isset( $event['status'], $event['data'] ) && 'REJEITADA' === $event['status'] ) {
							return gn_efi_format_webhook_date( $event['data'] );
						}
					}
				}
			}
		}

		return current_time( 'Y-m-d' );
	}
}

if ( ! function_exists( 'gn_efi_extract_cobr_liquidation_date' ) ) {
	function gn_efi_extract_cobr_liquidation_date( $cobr ) {
		if ( isset( $cobr['tentativas'] ) && is_array( $cobr['tentativas'] ) ) {
			foreach ( $cobr['tentativas'] as $attempt ) {
				if ( ! empty( $attempt['dataLiquidacao'] ) ) {
					return sanitize_text_field( $attempt['dataLiquidacao'] );
				}
			}
		}

		return gn_efi_extract_cobr_rejection_date( $cobr );
	}
}

if ( ! function_exists( 'gn_efi_extract_cobr_rejection_reason' ) ) {
	function gn_efi_extract_cobr_rejection_reason( $cobr ) {
		if ( isset( $cobr['encerramento']['rejeicao'] ) && is_array( $cobr['encerramento']['rejeicao'] ) ) {
			return $cobr['encerramento']['rejeicao'];
		}

		if ( isset( $cobr['tentativas'] ) && is_array( $cobr['tentativas'] ) ) {
			foreach ( array_reverse( $cobr['tentativas'] ) as $attempt ) {
				if ( isset( $attempt['rejeicao'] ) && is_array( $attempt['rejeicao'] ) ) {
					return $attempt['rejeicao'];
				}
			}
		}

		return array();
	}
}

if ( ! function_exists( 'gn_efi_register_pix_rejected_charge' ) ) {
	function gn_efi_register_pix_rejected_charge( $order_id, $cobr ) {
		if ( empty( $cobr['txid'] ) ) {
			return;
		}

		$txid     = sanitize_text_field( $cobr['txid'] );
		$charges  = gn_efi_get_pix_rejected_charges( $order_id );
		$existing = isset( $charges[ $txid ] ) && is_array( $charges[ $txid ] ) ? $charges[ $txid ] : array();
		$reason   = gn_efi_extract_cobr_rejection_reason( $cobr );
		$webhook_attempts_count = isset( $cobr['tentativas'] ) && is_array( $cobr['tentativas'] ) ? count( $cobr['tentativas'] ) : 0;
		$retry_count = max(
			isset( $existing['retry_count'] ) ? intval( $existing['retry_count'] ) : 0,
			max( 0, $webhook_attempts_count - 1 )
		);

		$charges[ $txid ] = array_merge(
			$existing,
			array(
				'txid'                   => $txid,
				'idRec'                  => isset( $cobr['idRec'] ) ? sanitize_text_field( $cobr['idRec'] ) : '',
				'status'                 => 'REJEITADA',
				'first_rejected_at'      => ! empty( $existing['first_rejected_at'] ) ? $existing['first_rejected_at'] : gn_efi_extract_cobr_rejection_date( $cobr ),
				'last_rejected_at'       => gn_efi_extract_cobr_rejection_date( $cobr ),
				'liquidation_date'       => gn_efi_extract_cobr_liquidation_date( $cobr ),
				'rejection_code'         => isset( $reason['codigo'] ) ? sanitize_text_field( $reason['codigo'] ) : '',
				'rejection_description'  => isset( $reason['descricao'] ) ? sanitize_text_field( $reason['descricao'] ) : '',
				'webhook_attempts_count' => $webhook_attempts_count,
				'retry_count'            => $retry_count,
				'pending_retry'          => false,
				'updated_at'             => current_time( 'mysql' ),
			)
		);

		gn_efi_save_pix_rejected_charges( $order_id, $charges );
	}
}

if ( ! function_exists( 'gn_efi_update_pix_rejected_charge_status' ) ) {
	function gn_efi_update_pix_rejected_charge_status( $order_id, $txid, $status ) {
		if ( empty( $txid ) ) {
			return;
		}

		$charges = gn_efi_get_pix_rejected_charges( $order_id );
		$txid    = sanitize_text_field( $txid );

		if ( ! isset( $charges[ $txid ] ) || ! is_array( $charges[ $txid ] ) ) {
			return;
		}

		$charges[ $txid ]['status']        = sanitize_text_field( $status );
		$charges[ $txid ]['pending_retry'] = false;
		$charges[ $txid ]['updated_at']    = current_time( 'mysql' );

		gn_efi_save_pix_rejected_charges( $order_id, $charges );
	}
}

if ( ! function_exists( 'gn_efi_mark_pix_retry_requested' ) ) {
	function gn_efi_mark_pix_retry_requested( $order_id, $txid, $retry_date, $response ) {
		$charges = gn_efi_get_pix_rejected_charges( $order_id );
		$txid    = sanitize_text_field( $txid );

		if ( ! isset( $charges[ $txid ] ) || ! is_array( $charges[ $txid ] ) ) {
			return;
		}

		$retry_count = isset( $charges[ $txid ]['retry_count'] ) ? intval( $charges[ $txid ]['retry_count'] ) : 0;
		$retries     = isset( $charges[ $txid ]['retries'] ) && is_array( $charges[ $txid ]['retries'] ) ? $charges[ $txid ]['retries'] : array();

		$retries[] = array(
			'date'         => sanitize_text_field( $retry_date ),
			'requested_at' => current_time( 'mysql' ),
			'response'     => $response,
		);

		$charges[ $txid ]['status']          = 'RETENTATIVA_SOLICITADA';
		$charges[ $txid ]['pending_retry']   = true;
		$charges[ $txid ]['retry_count']     = $retry_count + 1;
		$charges[ $txid ]['last_retry_date'] = sanitize_text_field( $retry_date );
		$charges[ $txid ]['retries']         = $retries;
		$charges[ $txid ]['updated_at']      = current_time( 'mysql' );

		gn_efi_save_pix_rejected_charges( $order_id, $charges );
	}
}

if ( ! function_exists( 'gn_efi_pix_retry_max_date' ) ) {
	function gn_efi_pix_retry_max_date( $charge ) {
		if ( empty( $charge['first_rejected_at'] ) ) {
			return '';
		}

		$timezone = gn_efi_get_store_timezone();
		$date     = DateTime::createFromFormat( '!Y-m-d', $charge['first_rejected_at'], $timezone );

		if ( ! $date ) {
			return '';
		}

		$date->modify( '+7 days' );
		return $date->format( 'Y-m-d' );
	}
}

if ( ! function_exists( 'gn_efi_pix_retry_min_date' ) ) {
	function gn_efi_pix_retry_min_date() {
		$date = new DateTime( 'today', gn_efi_get_store_timezone() );
		$date->modify( '+1 day' );

		return $date->format( 'Y-m-d' );
	}
}

if ( ! function_exists( 'gn_efi_can_retry_pix_charge' ) ) {
	function gn_efi_can_retry_pix_charge( $charge ) {
		if ( empty( $charge ) || ! is_array( $charge ) ) {
			return false;
		}

		if ( ! isset( $charge['status'] ) || 'REJEITADA' !== $charge['status'] ) {
			return false;
		}

		if ( ! empty( $charge['pending_retry'] ) ) {
			return false;
		}

		if ( isset( $charge['retry_count'] ) && intval( $charge['retry_count'] ) >= 3 ) {
			return false;
		}

		$max_date = gn_efi_pix_retry_max_date( $charge );
		if ( empty( $max_date ) ) {
			return false;
		}

		$today = new DateTime( 'today', gn_efi_get_store_timezone() );
		$max   = DateTime::createFromFormat( '!Y-m-d', $max_date, gn_efi_get_store_timezone() );

		return $max && $today < $max;
	}
}

if ( ! function_exists( 'gn_efi_validate_pix_retry_date' ) ) {
	function gn_efi_validate_pix_retry_date( $charge, $retry_date ) {
		if ( ! gn_efi_can_retry_pix_charge( $charge ) ) {
			return 'Essa cobrança não permite nova retentativa.';
		}

		$timezone = gn_efi_get_store_timezone();
		$date     = DateTime::createFromFormat( '!Y-m-d', $retry_date, $timezone );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $retry_date ) {
			return 'Informe uma data válida para a retentativa.';
		}

		$today    = new DateTime( 'today', $timezone );
		$max_date = gn_efi_pix_retry_max_date( $charge );
		$max      = DateTime::createFromFormat( '!Y-m-d', $max_date, $timezone );

		if ( $date <= $today ) {
			return 'A data da retentativa deve ser posterior à data atual.';
		}

		if ( ! $max || $date > $max ) {
			return 'A data da retentativa deve estar em até 7 dias após a primeira rejeição.';
		}

		return true;
	}
}


/**
 * Register autoloader
 *
 * All classes must follow WP class naming convention and be in the same folder as this file
 */
spl_autoload_register(
	function( $required_file ) {

		// Transform file name from class based to file based
		$fixed_name = strtolower( str_ireplace( '_', '-', $required_file ) );
		$file_path  = explode( '\\', $fixed_name );
		$last_index = count( $file_path ) - 1;
		$file_name  = "class-{$file_path[$last_index]}.php";

		// Get fully qualified path
		$fully_qualified_path = trailingslashit( dirname( __FILE__ ), 2 );
		for ( $key = 1; $key < $last_index; $key++ ) {
			$fully_qualified_path .= trailingslashit( $file_path[ $key ] );
		}
		$fully_qualified_path .= $file_name;

		// Include the file
		if ( stream_resolve_include_path( $fully_qualified_path ) ) {
			include_once $fully_qualified_path;
		}

	}
);

/**
 * Check if the plugin template part loader is already loaded
 */
if ( ! function_exists( 'otkp_get_template_part' ) ) {

	/**
	 * Retrieves a template part
	 *
	 * Hacked from the WP function that allows a template part to be loaded
	 *
	 * @param string $slug
	 * @param string $name Optional. Default null
	 * @param bool   $load   Optional. Default true
	 *
	 * @uses  otkp_locate_template()
	 * @uses  load_template()
	 */
	function otkp_get_template_part( $slug, $name = null, $load = true ) {

		// Check that slug is a string
		if ( ! is_string( $slug ) ) {
			return '';
		}

		// Execute code for this part
		do_action( 'get_template_part_' . $slug, $slug, $name );

		// Setup possible parts
		$templates = array();
		if ( isset( $name ) ) {
			$templates[] = $slug . '-' . $name . '.php';
		}
		$templates[] = $slug . '.php';

		// Allow template parts to be filtered
		$templates = apply_filters( 'otkp_get_template_part', $templates, $slug, $name );

		// Return the part that is found
		return otkp_locate_template( $templates, $load, false );
	}

	/**
	 * Retrieve the name of the highest priority template file that exists.
	 *
	 * Searches in the STYLESHEETPATH before TEMPLATEPATH so that themes which
	 * inherit from a parent theme can just overload one file. If the template is
	 * not found in either of those, it looks in the plugin folder last.
	 *
	 * Hacked from the WP function that allows a template part to be located
	 *
	 * @param string|array $template_names Template file(s) to search for, in order.
	 * @param bool         $load If true the template file will be loaded if it is found.
	 * @param bool         $require_once Whether to require_once or require. Default true.
	 *                                    Has no effect if $load is false.
	 * @return string The template filename if one is located.
	 */
	function otkp_locate_template( $template_names, $load = false, $require_once = true ) {
		// No file found yet
		$located = '';

		// Try to find a template file
		foreach ( (array) $template_names as $template_name ) {

			// Continue if template is empty
			if ( empty( $template_name ) ) {
				continue;
			}

			// Trim off any slashes from the template name
			$template_name = ltrim( $template_name, '/' );

			// Current plugin dir name
			$dir_name = basename( dirname( __FILE__, 2 ) );

			if ( file_exists( trailingslashit( get_stylesheet_directory() ) . $dir_name . '/' . $template_name ) ) {  // Check child theme first
				$located = trailingslashit( get_stylesheet_directory() ) . $dir_name . '/' . $template_name;
				break;
			} elseif ( file_exists( trailingslashit( get_template_directory() ) . $dir_name . '/' . $template_name ) ) {  // Check parent theme next
				$located = trailingslashit( get_template_directory() ) . $dir_name . '/' . $template_name;
				break;
			} elseif ( file_exists( trailingslashit( otkp_get_templates_dir() ) . $template_name ) ) {  // Check plugin templates folder last
				$located = trailingslashit( otkp_get_templates_dir() ) . $template_name;
				break;
			}
		}

		if ( ( true === $load ) && ! empty( $located ) ) {
			load_template( $located, $require_once );
		}

		return $located;
	}

	/**
	 * Retrieve the path to the plugin template directory
	 *
	 * @return string
	 */
	function otkp_get_templates_dir() {
		return trailingslashit( dirname( __FILE__, 2 ) ) . 'templates/';
	}
}
