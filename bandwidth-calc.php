<?php
/**
 * Plugin Name:       Check Bandwidth
 * Description:       Estimate monthly bandwidth from page size, visits and pageviews. Stores results in a custom table and exposes a form shortcode and a searchable list shortcode.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Leroy Bruster
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       check-bandwidth
 *
 * Shortcodes:
 *   [cb_bandwidth]       Submission form (public).
 *   [cb_bandwidth_list]  Searchable list. Admin-only unless public="yes".
 *   [my_list]            Deprecated alias of [cb_bandwidth_list].
 *
 * @package CheckBandwidth
 */

defined( 'ABSPATH' ) || exit;

final class CB_Bandwidth {

	const DB_VERSION = '2';
	const OPTION     = 'cb_bandwidth_db_version';
	const ACTION     = 'cb_bandwidth_submit';
	const NONCE      = 'cb_bandwidth_nonce';

	// Input limits keep the result inside BIGINT and reject nonsense values.
	const MAX_PAGE_KB   = 1000000;     // ~1 GB page.
	const MAX_VISITS    = 1000000000;  // 1 billion visits / month.
	const MAX_PAGEVIEWS = 1000;
	const MAX_URL_LEN   = 100;
	const LIST_LIMIT    = 50;

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
	}

	/* ------------------------------------------------------------------
	 * Database
	 * ---------------------------------------------------------------- */

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'chk_bandw';
	}

	/** Runs on activation and whenever the schema version changes (not on every request). */
	public static function install() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			chkb_wppagesize int NOT NULL,
			chkb_monavg_visits int NOT NULL,
			chkb_res_monavg bigint(20) unsigned NOT NULL,
			chkb_avg_pageviews int NOT NULL,
			chkb_avg_desc varchar(100) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPTION, self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------------
	 * Shortcodes
	 * ---------------------------------------------------------------- */

	public static function register_shortcodes() {
		add_shortcode( 'cb_bandwidth', array( __CLASS__, 'render_form' ) );
		add_shortcode( 'cb_bandwidth_list', array( __CLASS__, 'render_list' ) );
		add_shortcode( 'my_list', array( __CLASS__, 'render_list' ) ); // Backwards compatibility.
	}

	public static function render_form() {
		ob_start();

		$notice = isset( $_GET['cb_status'] ) ? sanitize_key( wp_unslash( $_GET['cb_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'ok' === $notice ) {
			$result_id = isset( $_GET['cb_id'] ) ? absint( $_GET['cb_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$row       = $result_id ? self::get_row( $result_id ) : null;
			if ( $row ) {
				printf(
					'<p class="cb-notice cb-notice--ok" role="status">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: entry ID, 2: bandwidth in GB. */
							__( 'Saved as entry #%1$d. Estimated bandwidth: %2$s GB per month.', 'check-bandwidth' ),
							$row->id,
							self::format_gb( $row->chkb_res_monavg )
						)
					)
				);
			}
		} elseif ( 'invalid' === $notice ) {
			printf(
				'<p class="cb-notice cb-notice--error" role="alert">%s</p>',
				esc_html__( 'Please check your values and try again.', 'check-bandwidth' )
			);
		}
		?>
		<form class="cb-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="cb_redirect" value="<?php echo esc_url( get_permalink() ); ?>">
			<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>

			<p style="display:none" aria-hidden="true">
				<label>Leave this empty <input type="text" name="cb_hp" tabindex="-1" autocomplete="off"></label>
			</p>

			<p>
				<label for="cb_wppagesize"><?php esc_html_e( 'Average page size (KB)', 'check-bandwidth' ); ?></label>
				<input type="number" id="cb_wppagesize" name="cb_wppagesize" min="1" max="<?php echo esc_attr( self::MAX_PAGE_KB ); ?>" required>
			</p>
			<p>
				<label for="cb_monavg_visits"><?php esc_html_e( 'Average monthly visits', 'check-bandwidth' ); ?></label>
				<input type="number" id="cb_monavg_visits" name="cb_monavg_visits" min="1" max="<?php echo esc_attr( self::MAX_VISITS ); ?>" required>
			</p>
			<p>
				<label for="cb_avg_pageviews"><?php esc_html_e( 'Average pageviews per visit', 'check-bandwidth' ); ?></label>
				<input type="number" id="cb_avg_pageviews" name="cb_avg_pageviews" min="1" max="<?php echo esc_attr( self::MAX_PAGEVIEWS ); ?>" required>
			</p>
			<p>
				<label for="cb_avg_desc"><?php esc_html_e( 'Website URL', 'check-bandwidth' ); ?></label>
				<input type="url" id="cb_avg_desc" name="cb_avg_desc" maxlength="<?php echo esc_attr( self::MAX_URL_LEN ); ?>" required>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Calculate', 'check-bandwidth' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * [cb_bandwidth_list public="no"] — search by entry ID (number) or website (text).
	 * Search uses GET: it is read-only and the result can be bookmarked.
	 */
	public static function render_list( $atts ) {
		$atts = shortcode_atts( array( 'public' => 'no' ), $atts, 'cb_bandwidth_list' );

		if ( 'yes' !== $atts['public'] && ! current_user_can( 'manage_options' ) ) {
			return '<p class="cb-notice">' . esc_html__( 'You do not have permission to view this list.', 'check-bandwidth' ) . '</p>';
		}

		$q    = isset( $_GET['cb_q'] ) ? sanitize_text_field( wp_unslash( $_GET['cb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$rows = self::search( $q );

		ob_start();
		?>
		<form class="cb-search" method="get" action="<?php echo esc_url( get_permalink() ); ?>">
			<label for="cb_q"><?php esc_html_e( 'Search by ID or website', 'check-bandwidth' ); ?></label>
			<input type="search" id="cb_q" name="cb_q" value="<?php echo esc_attr( $q ); ?>">
			<button type="submit"><?php esc_html_e( 'Search', 'check-bandwidth' ); ?></button>
		</form>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No entries found.', 'check-bandwidth' ); ?></p>
		<?php else : ?>
			<table class="cb-list">
				<thead>
					<tr>
						<th scope="col">ID</th>
						<th scope="col"><?php esc_html_e( 'Website', 'check-bandwidth' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Page size (KB)', 'check-bandwidth' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Visits / month', 'check-bandwidth' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pageviews', 'check-bandwidth' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Bandwidth (GB / month)', 'check-bandwidth' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->id ); ?></td>
						<td><?php echo esc_html( $row->chkb_avg_desc ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->chkb_wppagesize ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->chkb_monavg_visits ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row->chkb_avg_pageviews ) ); ?></td>
						<td><?php echo esc_html( self::format_gb( $row->chkb_res_monavg ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Queries (always prepared)
	 * ---------------------------------------------------------------- */

	private static function get_row( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
	}

	private static function search( $q ) {
		global $wpdb;
		$table = self::table();

		if ( '' === $q ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", self::LIST_LIMIT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ctype_digit( $q ) ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $q ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE chkb_avg_desc LIKE %s ORDER BY id DESC LIMIT %d", $like, self::LIST_LIMIT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Form handling: nonce -> validate -> insert -> redirect (PRG)
	 * ---------------------------------------------------------------- */

	public static function handle_submit() {
		$redirect = isset( $_POST['cb_redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['cb_redirect'] ) ), home_url( '/' ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked just below.

		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'check-bandwidth' ), '', array( 'response' => 403 ) );
		}

		// Honeypot: bots fill hidden fields. Pretend success, store nothing.
		if ( ! empty( $_POST['cb_hp'] ) ) {
			wp_safe_redirect( add_query_arg( 'cb_status', 'invalid', $redirect ) );
			exit;
		}

		$page_kb = isset( $_POST['cb_wppagesize'] ) ? absint( wp_unslash( $_POST['cb_wppagesize'] ) ) : 0;
		$visits  = isset( $_POST['cb_monavg_visits'] ) ? absint( wp_unslash( $_POST['cb_monavg_visits'] ) ) : 0;
		$views   = isset( $_POST['cb_avg_pageviews'] ) ? absint( wp_unslash( $_POST['cb_avg_pageviews'] ) ) : 0;
		$url     = isset( $_POST['cb_avg_desc'] ) ? esc_url_raw( wp_unslash( $_POST['cb_avg_desc'] ) ) : '';

		$valid = $page_kb >= 1 && $page_kb <= self::MAX_PAGE_KB
			&& $visits >= 1 && $visits <= self::MAX_VISITS
			&& $views >= 1 && $views <= self::MAX_PAGEVIEWS
			&& '' !== $url && strlen( $url ) <= self::MAX_URL_LEN;

		if ( ! $valid ) {
			wp_safe_redirect( add_query_arg( 'cb_status', 'invalid', $redirect ) );
			exit;
		}

		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'chkb_wppagesize'    => $page_kb,
				'chkb_monavg_visits' => $visits,
				'chkb_res_monavg'    => $page_kb * $visits * $views, // KB per month.
				'chkb_avg_pageviews' => $views,
				'chkb_avg_desc'      => $url,
			),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'cb_status' => 'ok',
					'cb_id'     => (int) $wpdb->insert_id,
				),
				$redirect
			)
		);
		exit;
	}

	/** KB -> GB, 2 decimals. */
	private static function format_gb( $kb ) {
		return number_format_i18n( $kb / 1024 / 1024, 2 );
	}
}

CB_Bandwidth::init();
