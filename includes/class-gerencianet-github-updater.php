<?php
/**
 * Atualização automática deste fork a partir dos GitHub Releases.
 *
 * @package    Gerencianet_Oficial
 * @subpackage Gerencianet_Oficial/includes
 */

namespace GN_Includes;

/**
 * Informa ao WordPress a versão publicada no último Release do fork.
 *
 * O header `Update URI` do plugin aponta para github.com, o que faz o WordPress
 * ignorar o wordpress.org para este plugin e aplicar o filtro nativo
 * `update_plugins_github.com` (disponível desde o WordPress 5.8) durante a
 * verificação de atualizações.
 */
class Gerencianet_Github_Updater {

	/**
	 * Repositório do fork, no formato `owner/repo`.
	 */
	const REPOSITORY = 'logycware/Plugin-Wordpress-Efi';

	/**
	 * Mesmo valor declarado no header `Update URI` do plugin.
	 */
	const UPDATE_URI = 'https://github.com/logycware/Plugin-Wordpress-Efi';

	/**
	 * Asset publicado em cada Release com o ZIP instalável do plugin.
	 */
	const ASSET_NAME = 'gerencianet-oficial.zip';

	/**
	 * Transient de rede com os dados do último Release.
	 */
	const CACHE_KEY = 'gn_efi_github_release';

	/**
	 * Cache de uma consulta bem-sucedida à API do GitHub.
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Cache de uma consulta sem resultado utilizável, para não repetir a
	 * chamada à API em cada requisição enquanto o GitHub estiver indisponível.
	 */
	const CACHE_TTL_UNAVAILABLE = 30 * MINUTE_IN_SECONDS;

	/**
	 * Dados do último Release já resolvidos nesta requisição.
	 *
	 * @var array|null
	 */
	private $release = null;

	/**
	 * Registra os hooks do updater.
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );

		// Libera o cache ao concluir uma atualização e quando o WordPress
		// descarta o transient de atualizações (botão "Verificar novamente").
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
		add_action( 'delete_site_transient_update_plugins', array( $this, 'clear_cache' ) );
	}

	/**
	 * Responde ao filtro nativo com os dados do Release mais recente.
	 *
	 * Devolve o valor recebido sem alteração quando não há versão mais nova ou
	 * quando o GitHub não pôde ser consultado, de modo que o WordPress mantenha
	 * a versão instalada sem exibir erros.
	 *
	 * @param array|false $update      Dados de atualização acumulados pelo filtro.
	 * @param array       $plugin_data Headers do plugin verificado.
	 * @param string      $plugin_file Caminho do plugin relativo a wp-content/plugins.
	 * @return array|false
	 */
	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( GERENCIANET_OFICIAL_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_latest_release();

		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $update;
		}

		if ( ! version_compare( $release['version'], GERENCIANET_OFICIAL_VERSION, '>' ) ) {
			return $update;
		}

		return array(
			'id'      => self::UPDATE_URI,
			'slug'    => $this->get_slug( $plugin_file ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * Remove o cache da consulta ao GitHub.
	 */
	public function clear_cache() {
		$this->release = null;
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Retorna os dados do último Release, usando cache em transient.
	 *
	 * @return array Chaves `version`, `package` e `url`, ou array vazio.
	 */
	private function get_latest_release() {
		if ( null !== $this->release ) {
			return $this->release;
		}

		$cached = get_site_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			$this->release = $cached;

			return $this->release;
		}

		$this->release = $this->request_latest_release();

		set_site_transient(
			self::CACHE_KEY,
			$this->release,
			empty( $this->release ) ? self::CACHE_TTL_UNAVAILABLE : self::CACHE_TTL
		);

		return $this->release;
	}

	/**
	 * Consulta o Release mais recente do repositório público do fork.
	 *
	 * Não exige token do GitHub e qualquer falha resulta em array vazio.
	 *
	 * @return array
	 */
	private function request_latest_release() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return array();
		}

		$version = $this->normalize_version( $release['tag_name'] );
		$package = $this->find_package( isset( $release['assets'] ) ? $release['assets'] : array() );

		if ( '' === $version || '' === $package ) {
			return array();
		}

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => empty( $release['html_url'] ) ? self::UPDATE_URI : esc_url_raw( $release['html_url'] ),
		);
	}

	/**
	 * Converte a tag do Release em um número de versão comparável.
	 *
	 * Aceita tags com o prefixo usual `v`, como `v3.2.0.1`.
	 *
	 * @param string $tag_name Tag do Release.
	 * @return string Versão sem prefixo, ou string vazia se a tag não for uma versão.
	 */
	private function normalize_version( $tag_name ) {
		$version = preg_replace( '/^[vV]/', '', trim( (string) $tag_name ) );

		return preg_match( '/^\d+(\.\d+)*(-[0-9A-Za-z.]+)?$/', $version ) ? $version : '';
	}

	/**
	 * Escolhe o ZIP instalável entre os assets do Release.
	 *
	 * O `zipball_url` do GitHub não é usado como alternativa porque o seu
	 * diretório raiz inclui o hash do commit e mudaria a pasta de instalação do
	 * plugin a cada atualização.
	 *
	 * @param array $assets Assets do Release.
	 * @return string URL do pacote, ou string vazia se nenhum asset servir.
	 */
	private function find_package( $assets ) {
		if ( ! is_array( $assets ) ) {
			return '';
		}

		$fallback = '';

		foreach ( $assets as $asset ) {
			if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			$url = $this->sanitize_package_url( $asset['browser_download_url'] );

			if ( '' === $url ) {
				continue;
			}

			if ( self::ASSET_NAME === $asset['name'] ) {
				return $url;
			}

			if ( '' === $fallback && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}

	/**
	 * Aceita apenas pacotes servidos pelo GitHub via HTTPS.
	 *
	 * @param string $url URL informada pela API.
	 * @return string URL saneada, ou string vazia se não for confiável.
	 */
	private function sanitize_package_url( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return '';
		}

		$allowed_hosts = array( 'github.com', 'objects.githubusercontent.com' );

		return in_array( wp_parse_url( $url, PHP_URL_HOST ), $allowed_hosts, true ) ? $url : '';
	}

	/**
	 * Slug do plugin, que precisa corresponder à pasta de instalação para que a
	 * atualização substitua os arquivos no lugar certo.
	 *
	 * @param string $plugin_file Caminho do plugin relativo a wp-content/plugins.
	 * @return string
	 */
	private function get_slug( $plugin_file ) {
		$slug = dirname( $plugin_file );

		return ( '' === $slug || '.' === $slug ) ? basename( $plugin_file, '.php' ) : $slug;
	}
}
