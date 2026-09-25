<?php
/**
 * Plugin Name: Jos Dealerinfo Prijzen
 * Description: Haalt Peugeot-, SYM- en Yadea-voertuigen met kleuren, foto's en prijzen op uit dealerinfo.net en zet ze als product op de site (prijs + rijklaar en kenteken).
 * Version: 1.0.0
 * Author: Jos Kerkhof Tweewielers
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-jdp-scraper.php';
require_once __DIR__ . '/includes/class-jdp-sync.php';

class JDP_Plugin {

	const SETTINGS  = 'jdp_settings';
	const CRON      = 'jdp_daily_sync';
	const CRON_STEP = 'jdp_sync_step';
	const PAGE      = 'jdp-dealerinfo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_jdp_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_jdp_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_jdp_step', array( __CLASS__, 'handle_step' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_start' ) );
		add_action( self::CRON_STEP, array( __CLASS__, 'cron_step' ) );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:00' ), 'daily', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( self::CRON_STEP );
	}

	public static function settings() {
		return wp_parse_args(
			get_option( self::SETTINGS, array() ),
			array(
				'login'    => '',
				'password' => '',
				'rijklaar' => 250,
				'status'   => 'publish',
			)
		);
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			'Dealerinfo sync',
			'Dealerinfo sync',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => 'product', 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	public static function handle_save() {
		self::check( 'jdp_save' );
		$old = self::settings();
		$new = array(
			'login'    => sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) ),
			'password' => '' !== ( $_POST['password'] ?? '' ) ? wp_unslash( $_POST['password'] ) : $old['password'],
			'rijklaar' => (float) str_replace( ',', '.', wp_unslash( $_POST['rijklaar'] ?? '250' ) ),
			'status'   => 'draft' === ( $_POST['status'] ?? '' ) ? 'draft' : 'publish',
		);
		update_option( self::SETTINGS, $new, false );
		wp_safe_redirect( self::page_url( array( 'msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_sync() {
		self::check( 'jdp_sync' );
		try {
			JDP_Sync::start();
		} catch ( Exception $e ) {
			wp_safe_redirect( self::page_url( array( 'err' => rawurlencode( $e->getMessage() ) ) ) );
			exit;
		}
		wp_safe_redirect( self::page_url( array( 'msg' => 'running' ) ) );
		exit;
	}

	public static function handle_step() {
		self::check( 'jdp_step' );
		@set_time_limit( 120 );
		$done = JDP_Sync::step();
		wp_safe_redirect( self::page_url( array( 'msg' => $done ? 'done' : 'running' ) ) );
		exit;
	}

	public static function cron_start() {
		try {
			JDP_Sync::start();
			self::cron_step();
		} catch ( Exception $e ) {
			update_option( JDP_Sync::LOG_OPTION, array( 'finished' => time(), 'log' => array( array( 'model' => '-', 'actie' => 'fout', 'details' => $e->getMessage() ) ) ), false );
		}
	}

	public static function cron_step() {
		if ( ! JDP_Sync::step() ) {
			wp_schedule_single_event( time() + 10, self::CRON_STEP );
		}
	}

	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen toegang.' );
		}
		check_admin_referer( $action );
	}

	public static function render_page() {
		$s       = self::settings();
		$running = JDP_Sync::is_running();
		$state   = JDP_Sync::state();
		$last    = get_option( JDP_Sync::LOG_OPTION );
		$msg     = sanitize_key( $_GET['msg'] ?? '' );
		$err     = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
		$next    = wp_next_scheduled( self::CRON );
		?>
		<div class="wrap">
			<h1>Dealerinfo sync</h1>
			<p>Haalt Peugeot, SYM en Yadea met alle kleuren, foto's en klantprijzen op uit dealerinfo.net. Op de site komt de dealerinfo-prijs plus rijklaar en kenteken. Dealerinfo is leidend: kleuren en modellen die daar verdwijnen, verdwijnen ook hier.</p>

			<?php if ( $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php elseif ( 'saved' === $msg ) : ?>
				<div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>
			<?php elseif ( 'done' === $msg ) : ?>
				<div class="notice notice-success"><p>Synchronisatie klaar.</p></div>
			<?php endif; ?>

			<?php if ( $running ) : ?>
				<div class="notice notice-info">
					<p><strong>Bezig met synchroniseren&hellip;</strong> <?php echo (int) count( $state['done'] ); ?> van <?php echo (int) ( count( $state['done'] ) + count( $state['groups'] ) ); ?> modellen verwerkt (foto's downloaden kost even tijd). Deze pagina gaat vanzelf verder.</p>
				</div>
				<form id="jdp-step" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jdp_step">
					<?php wp_nonce_field( 'jdp_step' ); ?>
					<?php submit_button( 'Verder', 'secondary', 'submit', false ); ?>
				</form>
				<script>setTimeout(function(){document.getElementById('jdp-step').submit();},1000);</script>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jdp_sync">
					<?php wp_nonce_field( 'jdp_sync' ); ?>
					<?php submit_button( 'Nu synchroniseren', 'primary', 'submit', false ); ?>
					<?php if ( $next ) : ?>
						<span style="margin-left:12px;color:#666;">Automatisch: elke nacht (volgende: <?php echo esc_html( wp_date( 'd-m-Y H:i', $next ) ); ?>)</span>
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<?php if ( $last && ! $running ) : ?>
				<h2>Laatste synchronisatie: <?php echo esc_html( wp_date( 'd-m-Y H:i', $last['finished'] ) ); ?></h2>
				<table class="widefat striped" style="max-width:1000px;">
					<thead><tr><th>Model</th><th>Actie</th><th>Details</th></tr></thead>
					<tbody>
					<?php foreach ( $last['log'] as $row ) : ?>
						<tr>
							<td>
								<?php if ( ! empty( $row['post_id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['model'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $row['model'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['actie'] ); ?></td>
							<td><?php echo esc_html( $row['details'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Instellingen</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="jdp_save">
				<?php wp_nonce_field( 'jdp_save' ); ?>
				<table class="form-table">
					<tr><th><label for="jdp-login">Dealerinfo login</label></th>
						<td><input id="jdp-login" name="login" class="regular-text" value="<?php echo esc_attr( $s['login'] ); ?>"></td></tr>
					<tr><th><label for="jdp-password">Dealerinfo wachtwoord</label></th>
						<td><input id="jdp-password" name="password" type="password" class="regular-text" placeholder="<?php echo $s['password'] ? '(opgeslagen, leeg laten om te behouden)' : ''; ?>" autocomplete="new-password"></td></tr>
					<tr><th><label for="jdp-rijklaar">Rijklaar + kenteken (&euro;)</label></th>
						<td><input id="jdp-rijklaar" name="rijklaar" type="number" step="0.01" value="<?php echo esc_attr( $s['rijklaar'] ); ?>"> <p class="description">Wordt bij elke dealerinfo-prijs opgeteld.</p></td></tr>
					<tr><th>Nieuwe modellen</th>
						<td><select name="status">
							<option value="publish" <?php selected( $s['status'], 'publish' ); ?>>Direct online</option>
							<option value="draft" <?php selected( $s['status'], 'draft' ); ?>>Als concept (eerst zelf nakijken)</option>
						</select></td></tr>
				</table>
				<?php submit_button( 'Opslaan' ); ?>
			</form>
		</div>
		<?php
	}
}

JDP_Plugin::init();
register_activation_hook( __FILE__, array( 'JDP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JDP_Plugin', 'deactivate' ) );
