<?php
/**
 * Plugin Name:       ASRG CSMS Tool Evaluation
 * Plugin URI:        https://github.com/asrg-community/asrg-csms-evaluation
 * Description:       Embeds the ASRG CSMS Tool Evaluation comparison table via the [asrg_csms_evaluation] shortcode.
 * Version:           2.0.0
 * Author:            Automotive Security Research Group
 * Author URI:        https://asrg.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       asrg-csms-evaluation
 * Network:           true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access
}

// ── DB TABLE NAMES ─────────────────────────────────────────────────────────────
function asrg_csms_votes_table()  { global $wpdb; return $wpdb->prefix . 'asrg_csms_votes'; }
function asrg_csms_claims_table() { global $wpdb; return $wpdb->prefix . 'asrg_csms_vendor_claims'; }

// ── DB VERSION ─────────────────────────────────────────────────────────────────
define( 'ASRG_CSMS_DB_VERSION', '2.0' );

// ── CREATE TABLES ──────────────────────────────────────────────────────────────
function asrg_csms_create_tables() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();

	$votes_sql = "CREATE TABLE IF NOT EXISTS " . asrg_csms_votes_table() . " (
		id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		feature_id  VARCHAR(120)        NOT NULL,
		vendor_key  VARCHAR(40)         NOT NULL,
		user_id     BIGINT(20) UNSIGNED NOT NULL,
		vote        ENUM('up','down')   NOT NULL,
		note        TEXT                DEFAULT NULL,
		created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY unique_user_vote (feature_id, vendor_key, user_id),
		KEY user_id    (user_id),
		KEY feature_id (feature_id)
	) $charset;";

	$claims_sql = "CREATE TABLE IF NOT EXISTS " . asrg_csms_claims_table() . " (
		id          BIGINT(20) UNSIGNED         NOT NULL AUTO_INCREMENT,
		vendor_key  VARCHAR(40)                 NOT NULL,
		feature_id  VARCHAR(120)                NOT NULL,
		score       ENUM('F','P','N','U')        NOT NULL,
		note        TEXT                         DEFAULT NULL,
		user_id     BIGINT(20) UNSIGNED          NOT NULL,
		status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
		created_at  DATETIME                     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME                     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY unique_claim (vendor_key, feature_id),
		KEY user_id (user_id),
		KEY status  (status)
	) $charset;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $votes_sql );
	dbDelta( $claims_sql );
	update_option( 'asrg_csms_db_version', ASRG_CSMS_DB_VERSION );
}

register_activation_hook( __FILE__, 'asrg_csms_create_tables' );

// Also run on plugins_loaded to handle version upgrades / manual activations
add_action( 'plugins_loaded', function () {
	if ( get_option( 'asrg_csms_db_version' ) !== ASRG_CSMS_DB_VERSION ) {
		asrg_csms_create_tables();
	}
} );

// ── PERMISSION HELPER ──────────────────────────────────────────────────────────
function asrg_csms_is_vendor_user() {
	if ( ! is_user_logged_in() ) return false;
	$user = wp_get_current_user();
	$vk   = get_user_meta( $user->ID, 'asrg_vendor_key', true );
	return in_array( 'company', (array) $user->roles ) && ! empty( $vk );
}

// ── REST API REGISTRATION ─────────────────────────────────────────────────────
add_action( 'rest_api_init', 'asrg_csms_register_routes' );

function asrg_csms_register_routes() {
	$ns = 'asrg-csms/v1';

	// GET /votes/summary — public
	register_rest_route( $ns, '/votes/summary', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_vote_summary',
		'permission_callback' => '__return_true',
	] );

	// GET /votes/mine — auth required
	register_rest_route( $ns, '/votes/mine', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_my_votes',
		'permission_callback' => 'is_user_logged_in',
	] );

	// POST /votes — auth required
	register_rest_route( $ns, '/votes', [
		'methods'             => 'POST',
		'callback'            => 'asrg_csms_rest_post_vote',
		'permission_callback' => 'is_user_logged_in',
		'args'                => [
			'feature_id' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'vendor_key' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'vote'       => [ 'required' => true,  'type' => 'string', 'enum' => [ 'up', 'down' ] ],
			'note'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );

	// DELETE /votes/(?P<id>\d+) — auth, own votes only
	register_rest_route( $ns, '/votes/(?P<id>\d+)', [
		'methods'             => 'DELETE',
		'callback'            => 'asrg_csms_rest_delete_vote',
		'permission_callback' => 'is_user_logged_in',
		'args'                => [
			'id' => [ 'required' => true, 'type' => 'integer' ],
		],
	] );

	// GET /claims/approved — public
	register_rest_route( $ns, '/claims/approved', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_approved_claims',
		'permission_callback' => '__return_true',
	] );

	// POST /claims — company role + asrg_vendor_key meta required
	register_rest_route( $ns, '/claims', [
		'methods'             => 'POST',
		'callback'            => 'asrg_csms_rest_post_claim',
		'permission_callback' => 'asrg_csms_is_vendor_user',
		'args'                => [
			'feature_id' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'score'      => [ 'required' => true,  'type' => 'string', 'enum' => [ 'F', 'P', 'N', 'U' ] ],
			'note'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
		],
	] );
}

// ── REST CALLBACKS ─────────────────────────────────────────────────────────────

function asrg_csms_rest_vote_summary() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT feature_id, vendor_key, vote, COUNT(*) AS cnt
		 FROM " . asrg_csms_votes_table() . "
		 GROUP BY feature_id, vendor_key, vote",
		ARRAY_A
	);
	$out = [];
	foreach ( $rows as $row ) {
		$key = $row['vendor_key'] . ':' . $row['feature_id'];
		if ( ! isset( $out[ $key ] ) ) $out[ $key ] = [ 'up' => 0, 'down' => 0 ];
		$out[ $key ][ $row['vote'] ] = (int) $row['cnt'];
	}
	return rest_ensure_response( $out );
}

function asrg_csms_rest_my_votes() {
	global $wpdb;
	$uid  = get_current_user_id();
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, feature_id, vendor_key, vote, note FROM " . asrg_csms_votes_table() . " WHERE user_id = %d",
			$uid
		),
		ARRAY_A
	);
	$out = [];
	foreach ( $rows as $row ) {
		$key = $row['vendor_key'] . ':' . $row['feature_id'];
		$out[ $key ] = [ 'id' => (int) $row['id'], 'vote' => $row['vote'], 'note' => $row['note'] ];
	}
	return rest_ensure_response( $out );
}

function asrg_csms_rest_post_vote( WP_REST_Request $req ) {
	global $wpdb;
	$uid        = get_current_user_id();
	$feature_id = $req->get_param( 'feature_id' );
	$vendor_key = $req->get_param( 'vendor_key' );
	$vote       = $req->get_param( 'vote' );
	$note       = $req->get_param( 'note' ) ?: null;

	// Validate slugs
	if ( ! preg_match( '/^[a-z0-9_]+$/', $feature_id ) ) {
		return new WP_Error( 'invalid_feature_id', 'Invalid feature_id.', [ 'status' => 400 ] );
	}
	if ( ! preg_match( '/^[a-z0-9_]+$/', $vendor_key ) ) {
		return new WP_Error( 'invalid_vendor_key', 'Invalid vendor_key.', [ 'status' => 400 ] );
	}

	$table    = asrg_csms_votes_table();
	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, vote FROM $table WHERE feature_id=%s AND vendor_key=%s AND user_id=%d",
			$feature_id, $vendor_key, $uid
		)
	);

	if ( $existing ) {
		// Same vote + no note = retract (toggle off)
		if ( $existing->vote === $vote && $note === null ) {
			$wpdb->delete( $table, [ 'id' => $existing->id ] );
			return rest_ensure_response( [ 'id' => null, 'vote' => null, 'retracted' => true ] );
		}
		$wpdb->update(
			$table,
			[ 'vote' => $vote, 'note' => $note, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id'   => $existing->id ]
		);
		return rest_ensure_response( [ 'id' => (int) $existing->id, 'vote' => $vote ] );
	}

	$wpdb->insert( $table, [
		'feature_id' => $feature_id,
		'vendor_key' => $vendor_key,
		'user_id'    => $uid,
		'vote'       => $vote,
		'note'       => $note,
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	] );
	return rest_ensure_response( [ 'id' => (int) $wpdb->insert_id, 'vote' => $vote ] );
}

function asrg_csms_rest_delete_vote( WP_REST_Request $req ) {
	global $wpdb;
	$uid     = get_current_user_id();
	$vote_id = (int) $req->get_param( 'id' );
	$table   = asrg_csms_votes_table();

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM $table WHERE id=%d AND user_id=%d",
		$vote_id, $uid
	) );
	if ( ! $row ) {
		return new WP_Error( 'not_found', 'Vote not found or not yours.', [ 'status' => 404 ] );
	}
	$wpdb->delete( $table, [ 'id' => $vote_id ] );
	return rest_ensure_response( [ 'deleted' => true ] );
}

function asrg_csms_rest_approved_claims() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT vendor_key, feature_id, score, note FROM " . asrg_csms_claims_table() . " WHERE status='approved'",
		ARRAY_A
	);
	$out = [];
	foreach ( $rows as $row ) {
		if ( ! isset( $out[ $row['vendor_key'] ] ) ) $out[ $row['vendor_key'] ] = [];
		$out[ $row['vendor_key'] ][ $row['feature_id'] ] = [ 'score' => $row['score'], 'note' => $row['note'] ];
	}
	return rest_ensure_response( $out );
}

function asrg_csms_rest_post_claim( WP_REST_Request $req ) {
	global $wpdb;
	$user       = wp_get_current_user();
	$vendor_key = get_user_meta( $user->ID, 'asrg_vendor_key', true );
	$feature_id = $req->get_param( 'feature_id' );
	$score      = $req->get_param( 'score' );
	$note       = $req->get_param( 'note' ) ?: null;

	if ( ! preg_match( '/^[a-z0-9_]+$/', $feature_id ) ) {
		return new WP_Error( 'invalid_feature_id', 'Invalid feature_id.', [ 'status' => 400 ] );
	}

	$table    = asrg_csms_claims_table();
	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM $table WHERE vendor_key=%s AND feature_id=%s",
		$vendor_key, $feature_id
	) );

	if ( $existing ) {
		$wpdb->update(
			$table,
			[ 'score' => $score, 'note' => $note, 'status' => 'pending', 'user_id' => $user->ID, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id'    => $existing->id ]
		);
		return rest_ensure_response( [ 'id' => (int) $existing->id, 'status' => 'pending' ] );
	}

	$wpdb->insert( $table, [
		'vendor_key' => $vendor_key,
		'feature_id' => $feature_id,
		'score'      => $score,
		'note'       => $note,
		'user_id'    => $user->ID,
		'status'     => 'pending',
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	] );
	return rest_ensure_response( [ 'id' => (int) $wpdb->insert_id, 'status' => 'pending' ] );
}

// ── SHORTCODE ──────────────────────────────────────────────────────────────────
function asrg_csms_evaluation_shortcode( $atts ) {
	$atts = shortcode_atts( [], $atts, 'asrg_csms_evaluation' );

	$html_file = plugin_dir_path( __FILE__ ) . 'asrg-csms-evaluation.html';

	if ( ! file_exists( $html_file ) ) {
		return '<p style="color:red;">ASRG CSMS Evaluation: <code>asrg-csms-evaluation.html</code> not found in plugin directory.</p>';
	}

	$raw = file_get_contents( $html_file );

	// Strip the outer <html>/<head>/<body> wrapper — we only want the inner content
	if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $raw, $matches ) ) {
		$body = $matches[1];
	} else {
		$body = $raw;
	}

	// Extract <style> blocks from <head> and inline them at the top
	$styles = '';
	if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $raw, $style_matches ) ) {
		foreach ( $style_matches[1] as $css ) {
			$styles .= '<style>' . $css . '</style>' . "\n";
		}
	}

	// Extract Google Fonts link tags
	$fonts = '';
	if ( preg_match_all( '/<link[^>]+fonts\.googleapis\.com[^>]+>/i', $raw, $font_matches ) ) {
		$fonts = implode( "\n", $font_matches[0] ) . "\n";
	}

	// Inject logos.json as a JS variable
	$logos_js = '<script>window.ASRG_LOGOS = {};</script>' . "\n";
	$logos_file = plugin_dir_path( __FILE__ ) . 'logos.json';
	if ( file_exists( $logos_file ) ) {
		$logos_raw  = file_get_contents( $logos_file );
		$logos_data = json_decode( $logos_raw, true );
		if ( is_array( $logos_data ) ) {
			unset( $logos_data['_comment'] );
			$logos_data = array_filter( $logos_data, function ( $v ) { return ! is_null( $v ); } );
			$logos_js   = '<script>window.ASRG_LOGOS = ' . wp_json_encode( $logos_data ) . ';</script>' . "\n";
		}
	}

	// ── AUTH & COMMUNITY DATA ──────────────────────────────────────────────────
	global $wpdb;

	$user            = wp_get_current_user();
	$logged_in       = $user && $user->ID;
	$vendor_key_meta = $logged_in ? get_user_meta( $user->ID, 'asrg_vendor_key', true ) : '';
	$is_vendor       = $logged_in
		&& in_array( 'company', (array) $user->roles )
		&& ! empty( $vendor_key_meta );

	$auth_data = [
		'loggedIn'    => (bool) $logged_in,
		'userId'      => $logged_in ? $user->ID : null,
		'displayName' => $logged_in ? $user->display_name : null,
		'vendorKey'   => $is_vendor ? $vendor_key_meta : null,
		'isVendor'    => $is_vendor,
		'loginUrl'    => 'https://garage.asrg.io',
	];

	$rest_data = [
		'base'  => rest_url( 'asrg-csms/v1' ),
		'nonce' => $logged_in ? wp_create_nonce( 'wp_rest' ) : null,
	];

	// Vote summary (public, all vendors)
	$votes_table = asrg_csms_votes_table();
	$vote_rows   = $wpdb->get_results(
		"SELECT feature_id, vendor_key, vote, COUNT(*) AS cnt
		 FROM $votes_table
		 GROUP BY feature_id, vendor_key, vote",
		ARRAY_A
	);
	$vote_summary = [];
	foreach ( $vote_rows as $row ) {
		$key = $row['vendor_key'] . ':' . $row['feature_id'];
		if ( ! isset( $vote_summary[ $key ] ) ) $vote_summary[ $key ] = [ 'up' => 0, 'down' => 0 ];
		$vote_summary[ $key ][ $row['vote'] ] = (int) $row['cnt'];
	}

	// Current user's own votes
	$my_votes = [];
	if ( $logged_in ) {
		$my_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, feature_id, vendor_key, vote, note FROM $votes_table WHERE user_id = %d",
				$user->ID
			),
			ARRAY_A
		);
		foreach ( $my_rows as $row ) {
			$key = $row['vendor_key'] . ':' . $row['feature_id'];
			$my_votes[ $key ] = [ 'id' => (int) $row['id'], 'vote' => $row['vote'], 'note' => $row['note'] ];
		}
	}

	// Approved vendor claims (visible to all)
	$claims_table = asrg_csms_claims_table();
	$claim_rows   = $wpdb->get_results(
		"SELECT vendor_key, feature_id, score, note FROM $claims_table WHERE status='approved'",
		ARRAY_A
	);
	$vendor_claims = [];
	foreach ( $claim_rows as $row ) {
		if ( ! isset( $vendor_claims[ $row['vendor_key'] ] ) ) $vendor_claims[ $row['vendor_key'] ] = [];
		$vendor_claims[ $row['vendor_key'] ][ $row['feature_id'] ] = [ 'score' => $row['score'], 'note' => $row['note'] ];
	}

	// Vendor's own claims including pending/rejected (only for vendor users)
	$my_claims = [];
	if ( $is_vendor ) {
		$my_claim_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, feature_id, score, note, status FROM $claims_table WHERE vendor_key = %s",
				$vendor_key_meta
			),
			ARRAY_A
		);
		foreach ( $my_claim_rows as $row ) {
			$my_claims[ $row['feature_id'] ] = [
				'id'     => (int) $row['id'],
				'score'  => $row['score'],
				'note'   => $row['note'],
				'status' => $row['status'],
			];
		}
	}

	$community_js = '<script>'
		. 'window.ASRG_AUTH='          . wp_json_encode( $auth_data )    . ';'
		. 'window.ASRG_REST='          . wp_json_encode( $rest_data )    . ';'
		. 'window.ASRG_VOTE_SUMMARY='  . wp_json_encode( $vote_summary ) . ';'
		. 'window.ASRG_MY_VOTES='      . wp_json_encode( $my_votes )     . ';'
		. 'window.ASRG_VENDOR_CLAIMS=' . wp_json_encode( $vendor_claims ). ';'
		. 'window.ASRG_MY_CLAIMS='     . wp_json_encode( $my_claims )    . ';'
		. '</script>' . "\n";

	return $fonts . $styles . $logos_js . $community_js . $body;
}
add_shortcode( 'asrg_csms_evaluation', 'asrg_csms_evaluation_shortcode' );

/**
 * Tell WordPress not to apply wpautop (auto-paragraph) to our shortcode output,
 * which would corrupt the HTML table structure.
 */
function asrg_csms_remove_wpautop( $content ) {
	if ( has_shortcode( $content, 'asrg_csms_evaluation' ) ) {
		remove_filter( 'the_content', 'wpautop' );
		remove_filter( 'the_content', 'wptexturize' );
	}
	return $content;
}
add_filter( 'the_content', 'asrg_csms_remove_wpautop', 1 );

/**
 * Add a "Full Width" body class when the shortcode page is loaded,
 * so themes can optionally suppress sidebars via CSS.
 */
function asrg_csms_body_class( $classes ) {
	global $post;
	if ( isset( $post ) && has_shortcode( $post->post_content, 'asrg_csms_evaluation' ) ) {
		$classes[] = 'asrg-csms-full-width';
	}
	return $classes;
}
add_filter( 'body_class', 'asrg_csms_body_class' );
