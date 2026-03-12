<?php
/**
 * Plugin Name:       ASRG CSMS Tool Evaluation
 * Plugin URI:        https://github.com/asrg-community/asrg-csms-evaluation
 * Description:       Embeds the ASRG CSMS Tool Evaluation comparison table via the [asrg_csms_evaluation] shortcode.
 *                    Also provides [asrg_vendor_portal] for authenticated vendor profile management.
 * Version:           2.1.0
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

define( 'ASRG_CSMS_DB_VERSION', '2.1' );

// ── TABLE NAME HELPERS ─────────────────────────────────────────────────────────
function asrg_csms_votes_table()    { global $wpdb; return $wpdb->prefix . 'asrg_csms_votes'; }
function asrg_csms_claims_table()   { global $wpdb; return $wpdb->prefix . 'asrg_csms_vendor_claims'; }
function asrg_csms_profiles_table() { global $wpdb; return $wpdb->prefix . 'asrg_csms_vendor_profiles'; }

// ── KNOWN VENDOR LIST (single source of truth) ────────────────────────────────
function asrg_csms_vendor_list() {
	return [
		'c2a'       => 'C2A EvSec',
		'cybellum'  => 'Cybellum',
		'manifest'  => 'Manifest Cyber',
		'vxlabs'    => 'VxLabs',
		'itemis'    => 'Itemis',
		'vicone'    => 'VicOne',
		'vultara'   => 'Vultara',
		'autocrypt' => 'AutoCrypt',
	];
}

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
		id          BIGINT(20) UNSIGNED               NOT NULL AUTO_INCREMENT,
		vendor_key  VARCHAR(40)                        NOT NULL,
		feature_id  VARCHAR(120)                       NOT NULL,
		score       ENUM('F','P','N','U')              NOT NULL,
		note        TEXT                               DEFAULT NULL,
		user_id     BIGINT(20) UNSIGNED                NOT NULL,
		status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
		created_at  DATETIME                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME                           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY unique_claim (vendor_key, feature_id),
		KEY user_id (user_id),
		KEY status  (status)
	) $charset;";

	$profiles_sql = "CREATE TABLE IF NOT EXISTS " . asrg_csms_profiles_table() . " (
		id             BIGINT(20) UNSIGNED               NOT NULL AUTO_INCREMENT,
		vendor_key     VARCHAR(40)                        NOT NULL,
		profile_json   LONGTEXT                           NOT NULL,
		user_id        BIGINT(20) UNSIGNED                NOT NULL,
		status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
		reviewer_note  TEXT                               DEFAULT NULL,
		submitted_at   DATETIME                           NOT NULL DEFAULT CURRENT_TIMESTAMP,
		reviewed_at    DATETIME                           DEFAULT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY vendor_key (vendor_key),
		KEY status  (status),
		KEY user_id (user_id)
	) $charset;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $votes_sql );
	dbDelta( $claims_sql );
	dbDelta( $profiles_sql );
	update_option( 'asrg_csms_db_version', ASRG_CSMS_DB_VERSION );
}

register_activation_hook( __FILE__, 'asrg_csms_create_tables' );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'asrg_csms_db_version' ) !== ASRG_CSMS_DB_VERSION ) {
		asrg_csms_create_tables();
	}
} );

// ── ADMIN UI: VENDOR KEY ASSIGNMENT ──────────────────────────────────────────
add_action( 'show_user_profile',       'asrg_csms_render_vendor_key_field' );
add_action( 'edit_user_profile',       'asrg_csms_render_vendor_key_field' );
add_action( 'personal_options_update', 'asrg_csms_save_vendor_key_field' );
add_action( 'edit_user_profile_update','asrg_csms_save_vendor_key_field' );

function asrg_csms_render_vendor_key_field( $user ) {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$current      = get_user_meta( $user->ID, 'asrg_vendor_key', true );
	$vendors      = asrg_csms_vendor_list();
	$has_role     = in_array( 'company', (array) $user->roles );
	$role_warning = ! $has_role
		? '<span style="color:#b45309;font-weight:500">⚠ User does not have the <code>company</code> role — vendor features will be inactive.</span>'
		: '<span style="color:#16a34a;font-weight:500">✓ User has the <code>company</code> role.</span>';
	?>
	<h2 style="border-top:1px solid #eee;padding-top:20px;margin-top:20px">ASRG CSMS — Vendor Company</h2>
	<table class="form-table" role="presentation">
	<tr>
		<th><label for="asrg_vendor_key">Linked Vendor</label></th>
		<td>
			<?php wp_nonce_field( 'asrg_csms_vendor_key_' . $user->ID, 'asrg_csms_vendor_nonce' ); ?>
			<select name="asrg_vendor_key" id="asrg_vendor_key" style="min-width:220px">
				<option value="">— None (not a vendor) —</option>
				<?php foreach ( $vendors as $key => $name ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
						<?php echo esc_html( $name ); ?> (<?php echo esc_html( $key ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description" style="margin-top:6px"><?php echo $role_warning; ?></p>
			<p class="description">Select the vendor company this user can submit profile updates and self-assessments for. Save the user profile after changing.</p>
		</td>
	</tr>
	</table>
	<?php
}

function asrg_csms_save_vendor_key_field( $user_id ) {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( ! isset( $_POST['asrg_csms_vendor_nonce'] )
		|| ! wp_verify_nonce( $_POST['asrg_csms_vendor_nonce'], 'asrg_csms_vendor_key_' . $user_id ) ) {
		return;
	}
	if ( ! isset( $_POST['asrg_vendor_key'] ) ) return;

	$vk      = sanitize_text_field( $_POST['asrg_vendor_key'] );
	$allowed = array_keys( asrg_csms_vendor_list() );

	if ( empty( $vk ) ) {
		delete_user_meta( $user_id, 'asrg_vendor_key' );
	} elseif ( in_array( $vk, $allowed ) ) {
		update_user_meta( $user_id, 'asrg_vendor_key', $vk );
	}
}

// ── PERMISSION HELPERS ────────────────────────────────────────────────────────
function asrg_csms_is_vendor_user() {
	if ( ! is_user_logged_in() ) return false;
	$user = wp_get_current_user();
	$vk   = get_user_meta( $user->ID, 'asrg_vendor_key', true );
	return in_array( 'company', (array) $user->roles ) && ! empty( $vk );
}

function asrg_csms_get_vendor_key( $user_id = null ) {
	$uid = $user_id ?: get_current_user_id();
	return get_user_meta( $uid, 'asrg_vendor_key', true );
}

// ── REST API REGISTRATION ─────────────────────────────────────────────────────
add_action( 'rest_api_init', 'asrg_csms_register_routes' );

function asrg_csms_register_routes() {
	$ns = 'asrg-csms/v1';

	// ── votes ──────────────────────────────────────────────────────────────────
	register_rest_route( $ns, '/votes/summary', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_vote_summary',
		'permission_callback' => '__return_true',
	] );
	register_rest_route( $ns, '/votes/mine', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_my_votes',
		'permission_callback' => 'is_user_logged_in',
	] );
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
	register_rest_route( $ns, '/votes/(?P<id>\d+)', [
		'methods'             => 'DELETE',
		'callback'            => 'asrg_csms_rest_delete_vote',
		'permission_callback' => 'is_user_logged_in',
		'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer' ] ],
	] );

	// ── claims ─────────────────────────────────────────────────────────────────
	register_rest_route( $ns, '/claims/approved', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_approved_claims',
		'permission_callback' => '__return_true',
	] );
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

	// ── vendor profiles ────────────────────────────────────────────────────────
	register_rest_route( $ns, '/vendor-profiles/approved', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_approved_profiles',
		'permission_callback' => '__return_true',
	] );
	register_rest_route( $ns, '/vendor-profile', [
		'methods'             => 'GET',
		'callback'            => 'asrg_csms_rest_get_my_profile',
		'permission_callback' => 'asrg_csms_is_vendor_user',
	] );
	register_rest_route( $ns, '/vendor-profile', [
		'methods'             => 'PUT',
		'callback'            => 'asrg_csms_rest_put_vendor_profile',
		'permission_callback' => 'asrg_csms_is_vendor_user',
		'args'                => [
			'profile' => [ 'required' => true, 'type' => 'object' ],
		],
	] );
}

// ── REST CALLBACKS — VOTES ────────────────────────────────────────────────────
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
		if ( ! isset( $out[$key] ) ) $out[$key] = [ 'up' => 0, 'down' => 0 ];
		$out[$key][ $row['vote'] ] = (int) $row['cnt'];
	}
	return rest_ensure_response( $out );
}

function asrg_csms_rest_my_votes() {
	global $wpdb;
	$uid  = get_current_user_id();
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, feature_id, vendor_key, vote, note FROM " . asrg_csms_votes_table() . " WHERE user_id=%d",
			$uid
		),
		ARRAY_A
	);
	$out = [];
	foreach ( $rows as $row ) {
		$key = $row['vendor_key'] . ':' . $row['feature_id'];
		$out[$key] = [ 'id' => (int) $row['id'], 'vote' => $row['vote'], 'note' => $row['note'] ];
	}
	return rest_ensure_response( $out );
}

function asrg_csms_rest_post_vote( WP_REST_Request $req ) {
	global $wpdb;
	$uid        = get_current_user_id();
	$feature_id = $req->get_param('feature_id');
	$vendor_key = $req->get_param('vendor_key');
	$vote       = $req->get_param('vote');
	$note       = $req->get_param('note') ?: null;

	if ( ! preg_match('/^[a-z0-9_]+$/', $feature_id) )
		return new WP_Error('invalid_feature_id', 'Invalid feature_id.', ['status'=>400]);
	if ( ! preg_match('/^[a-z0-9_]+$/', $vendor_key) )
		return new WP_Error('invalid_vendor_key', 'Invalid vendor_key.', ['status'=>400]);

	$table    = asrg_csms_votes_table();
	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, vote FROM $table WHERE feature_id=%s AND vendor_key=%s AND user_id=%d",
		$feature_id, $vendor_key, $uid
	) );

	if ( $existing ) {
		if ( $existing->vote === $vote && $note === null ) {
			$wpdb->delete( $table, ['id' => $existing->id] );
			return rest_ensure_response(['id'=>null,'vote'=>null,'retracted'=>true]);
		}
		$wpdb->update( $table,
			['vote'=>$vote,'note'=>$note,'updated_at'=>current_time('mysql')],
			['id'=>$existing->id]
		);
		return rest_ensure_response(['id'=>(int)$existing->id,'vote'=>$vote]);
	}

	$wpdb->insert( $table, [
		'feature_id' => $feature_id,
		'vendor_key' => $vendor_key,
		'user_id'    => $uid,
		'vote'       => $vote,
		'note'       => $note,
		'created_at' => current_time('mysql'),
		'updated_at' => current_time('mysql'),
	] );
	return rest_ensure_response(['id'=>(int)$wpdb->insert_id,'vote'=>$vote]);
}

function asrg_csms_rest_delete_vote( WP_REST_Request $req ) {
	global $wpdb;
	$uid     = get_current_user_id();
	$vote_id = (int) $req->get_param('id');
	$table   = asrg_csms_votes_table();
	$row     = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM $table WHERE id=%d AND user_id=%d", $vote_id, $uid
	) );
	if ( ! $row ) return new WP_Error('not_found','Vote not found or not yours.',['status'=>404]);
	$wpdb->delete( $table, ['id'=>$vote_id] );
	return rest_ensure_response(['deleted'=>true]);
}

// ── REST CALLBACKS — CLAIMS ───────────────────────────────────────────────────
function asrg_csms_rest_approved_claims() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT vendor_key, feature_id, score, note FROM " . asrg_csms_claims_table() . " WHERE status='approved'",
		ARRAY_A
	);
	$out = [];
	foreach ( $rows as $row ) {
		if ( ! isset($out[$row['vendor_key']]) ) $out[$row['vendor_key']] = [];
		$out[$row['vendor_key']][$row['feature_id']] = ['score'=>$row['score'],'note'=>$row['note']];
	}
	return rest_ensure_response($out);
}

function asrg_csms_rest_post_claim( WP_REST_Request $req ) {
	global $wpdb;
	$user       = wp_get_current_user();
	$vendor_key = asrg_csms_get_vendor_key($user->ID);
	$feature_id = $req->get_param('feature_id');
	$score      = $req->get_param('score');
	$note       = $req->get_param('note') ?: null;

	if ( ! preg_match('/^[a-z0-9_]+$/', $feature_id) )
		return new WP_Error('invalid_feature_id','Invalid feature_id.',['status'=>400]);

	$table    = asrg_csms_claims_table();
	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM $table WHERE vendor_key=%s AND feature_id=%s", $vendor_key, $feature_id
	) );

	if ( $existing ) {
		$wpdb->update( $table,
			['score'=>$score,'note'=>$note,'status'=>'pending','user_id'=>$user->ID,'updated_at'=>current_time('mysql')],
			['id'=>$existing->id]
		);
		return rest_ensure_response(['id'=>(int)$existing->id,'status'=>'pending']);
	}

	$wpdb->insert( $table, [
		'vendor_key' => $vendor_key,
		'feature_id' => $feature_id,
		'score'      => $score,
		'note'       => $note,
		'user_id'    => $user->ID,
		'status'     => 'pending',
		'created_at' => current_time('mysql'),
		'updated_at' => current_time('mysql'),
	] );
	return rest_ensure_response(['id'=>(int)$wpdb->insert_id,'status'=>'pending']);
}

// ── REST CALLBACKS — VENDOR PROFILES ─────────────────────────────────────────
function asrg_csms_rest_approved_profiles() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT vendor_key, profile_json FROM " . asrg_csms_profiles_table() . " WHERE status='approved'",
		ARRAY_A
	);
	$out = [];
	foreach ( $rows as $row ) {
		$decoded = json_decode($row['profile_json'], true);
		if ( is_array($decoded) ) $out[$row['vendor_key']] = $decoded;
	}
	return rest_ensure_response($out);
}

function asrg_csms_rest_get_my_profile() {
	global $wpdb;
	$vendor_key = asrg_csms_get_vendor_key();
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT profile_json, status, reviewer_note, submitted_at FROM " . asrg_csms_profiles_table() . " WHERE vendor_key=%s",
		$vendor_key
	), ARRAY_A );

	if ( ! $row ) return rest_ensure_response( null );

	return rest_ensure_response([
		'profile'       => json_decode($row['profile_json'], true),
		'status'        => $row['status'],
		'reviewer_note' => $row['reviewer_note'],
		'submitted_at'  => $row['submitted_at'],
	]);
}

function asrg_csms_rest_put_vendor_profile( WP_REST_Request $req ) {
	global $wpdb;
	$user       = wp_get_current_user();
	$vendor_key = asrg_csms_get_vendor_key($user->ID);

	// Whitelist allowed profile fields
	$allowed_fields = [
		'desc','product','productUrl','website','hq',
		'founded','funding','employees','investors','crunchbase',
	];
	$raw     = $req->get_param('profile');
	$profile = [];
	foreach ( $allowed_fields as $f ) {
		if ( isset($raw[$f]) ) {
			$profile[$f] = sanitize_textarea_field( (string) $raw[$f] );
		}
	}

	// Sanitize URLs
	foreach (['productUrl','website','crunchbase'] as $url_field) {
		if ( isset($profile[$url_field]) ) {
			$profile[$url_field] = esc_url_raw($profile[$url_field]);
		}
	}

	$json  = wp_json_encode($profile);
	$table = asrg_csms_profiles_table();

	$existing = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $table WHERE vendor_key=%s", $vendor_key
	) );

	if ( $existing ) {
		$wpdb->update( $table,
			[ 'profile_json'=>$json, 'status'=>'pending', 'user_id'=>$user->ID,
			  'reviewer_note'=>null, 'submitted_at'=>current_time('mysql'), 'reviewed_at'=>null ],
			[ 'vendor_key'=>$vendor_key ]
		);
	} else {
		$wpdb->insert( $table, [
			'vendor_key'    => $vendor_key,
			'profile_json'  => $json,
			'user_id'       => $user->ID,
			'status'        => 'pending',
			'submitted_at'  => current_time('mysql'),
		] );
	}

	return rest_ensure_response(['status'=>'pending','vendor_key'=>$vendor_key]);
}

// ── HELPER: LOAD COMMUNITY DATA FOR SHORTCODES ────────────────────────────────
function asrg_csms_load_community_data() {
	global $wpdb;
	$user            = wp_get_current_user();
	$logged_in       = (bool)( $user && $user->ID );
	$vendor_key_meta = $logged_in ? asrg_csms_get_vendor_key($user->ID) : '';
	$is_vendor       = $logged_in
		&& in_array('company', (array)$user->roles)
		&& ! empty($vendor_key_meta);

	$auth = [
		'loggedIn'    => $logged_in,
		'userId'      => $logged_in ? $user->ID : null,
		'displayName' => $logged_in ? $user->display_name : null,
		'vendorKey'   => $is_vendor ? $vendor_key_meta : null,
		'isVendor'    => $is_vendor,
		'loginUrl'    => 'https://garage.asrg.io',
	];

	$rest = [
		'base'  => rest_url('asrg-csms/v1'),
		'nonce' => $logged_in ? wp_create_nonce('wp_rest') : null,
	];

	// Vote summary
	$vote_rows = $wpdb->get_results(
		"SELECT feature_id, vendor_key, vote, COUNT(*) AS cnt
		 FROM " . asrg_csms_votes_table() . "
		 GROUP BY feature_id, vendor_key, vote",
		ARRAY_A
	);
	$vote_summary = [];
	foreach ($vote_rows as $r) {
		$k = $r['vendor_key'].':'.$r['feature_id'];
		if (!isset($vote_summary[$k])) $vote_summary[$k] = ['up'=>0,'down'=>0];
		$vote_summary[$k][$r['vote']] = (int)$r['cnt'];
	}

	// My votes
	$my_votes = [];
	if ($logged_in) {
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT id,feature_id,vendor_key,vote,note FROM " . asrg_csms_votes_table() . " WHERE user_id=%d", $user->ID),
			ARRAY_A
		);
		foreach ($rows as $r) {
			$my_votes[$r['vendor_key'].':'.$r['feature_id']] = ['id'=>(int)$r['id'],'vote'=>$r['vote'],'note'=>$r['note']];
		}
	}

	// Approved claims
	$claim_rows = $wpdb->get_results(
		"SELECT vendor_key,feature_id,score,note FROM " . asrg_csms_claims_table() . " WHERE status='approved'",
		ARRAY_A
	);
	$vendor_claims = [];
	foreach ($claim_rows as $r) {
		if (!isset($vendor_claims[$r['vendor_key']])) $vendor_claims[$r['vendor_key']] = [];
		$vendor_claims[$r['vendor_key']][$r['feature_id']] = ['score'=>$r['score'],'note'=>$r['note']];
	}

	// My claims (vendor only)
	$my_claims = [];
	if ($is_vendor) {
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT id,feature_id,score,note,status FROM " . asrg_csms_claims_table() . " WHERE vendor_key=%s", $vendor_key_meta),
			ARRAY_A
		);
		foreach ($rows as $r) {
			$my_claims[$r['feature_id']] = ['id'=>(int)$r['id'],'score'=>$r['score'],'note'=>$r['note'],'status'=>$r['status']];
		}
	}

	// Approved vendor profiles
	$profile_rows = $wpdb->get_results(
		"SELECT vendor_key,profile_json FROM " . asrg_csms_profiles_table() . " WHERE status='approved'",
		ARRAY_A
	);
	$vendor_profiles = [];
	foreach ($profile_rows as $r) {
		$d = json_decode($r['profile_json'], true);
		if (is_array($d)) $vendor_profiles[$r['vendor_key']] = $d;
	}

	// My profile (vendor only)
	$my_profile = null;
	if ($is_vendor) {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT profile_json,status,reviewer_note,submitted_at FROM " . asrg_csms_profiles_table() . " WHERE vendor_key=%s",
			$vendor_key_meta
		), ARRAY_A);
		if ($row) {
			$my_profile = [
				'profile'       => json_decode($row['profile_json'], true),
				'status'        => $row['status'],
				'reviewer_note' => $row['reviewer_note'],
				'submitted_at'  => $row['submitted_at'],
			];
		}
	}

	return compact('auth','rest','vote_summary','my_votes','vendor_claims','my_claims','vendor_profiles','my_profile');
}

// ── SHORTCODE: EVALUATION TABLE ──────────────────────────────────────────────
function asrg_csms_evaluation_shortcode( $atts ) {
	$atts     = shortcode_atts([], $atts, 'asrg_csms_evaluation');
	$html_file = plugin_dir_path(__FILE__) . 'asrg-csms-evaluation.html';

	if (!file_exists($html_file))
		return '<p style="color:red;">ASRG CSMS Evaluation: <code>asrg-csms-evaluation.html</code> not found.</p>';

	$raw = file_get_contents($html_file);
	$body   = preg_match('/<body[^>]*>(.*)<\/body>/is', $raw, $m) ? $m[1] : $raw;
	$styles = '';
	if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $raw, $sm))
		foreach ($sm[1] as $css) $styles .= '<style>'.$css.'</style>'."\n";
	$fonts = '';
	if (preg_match_all('/<link[^>]+fonts\.googleapis\.com[^>]+>/i', $raw, $fm))
		$fonts = implode("\n", $fm[0])."\n";

	// Logos
	$logos_js   = '<script>window.ASRG_LOGOS={};</script>'."\n";
	$logos_file = plugin_dir_path(__FILE__).'logos.json';
	if (file_exists($logos_file)) {
		$ld = json_decode(file_get_contents($logos_file), true);
		if (is_array($ld)) {
			unset($ld['_comment']);
			$ld = array_filter($ld, fn($v)=>!is_null($v));
			$logos_js = '<script>window.ASRG_LOGOS='.wp_json_encode($ld).';</script>'."\n";
		}
	}

	$d = asrg_csms_load_community_data();

	$js = '<script>'
		.'window.ASRG_AUTH='          .wp_json_encode($d['auth'])          .';'
		.'window.ASRG_REST='          .wp_json_encode($d['rest'])          .';'
		.'window.ASRG_VOTE_SUMMARY='  .wp_json_encode($d['vote_summary'])  .';'
		.'window.ASRG_MY_VOTES='      .wp_json_encode($d['my_votes'])      .';'
		.'window.ASRG_VENDOR_CLAIMS=' .wp_json_encode($d['vendor_claims']) .';'
		.'window.ASRG_MY_CLAIMS='     .wp_json_encode($d['my_claims'])     .';'
		.'window.ASRG_VENDOR_PROFILES='.wp_json_encode($d['vendor_profiles']).';'
		.'</script>'."\n";

	return $fonts.$styles.$logos_js.$js.$body;
}
add_shortcode('asrg_csms_evaluation', 'asrg_csms_evaluation_shortcode');

// ── SHORTCODE: VENDOR PORTAL ─────────────────────────────────────────────────
function asrg_vendor_portal_shortcode( $atts ) {
	$atts     = shortcode_atts([], $atts, 'asrg_vendor_portal');
	$html_file = plugin_dir_path(__FILE__).'asrg-vendor-portal.html';

	if (!file_exists($html_file))
		return '<p style="color:red;">ASRG Vendor Portal: <code>asrg-vendor-portal.html</code> not found.</p>';

	$raw  = file_get_contents($html_file);
	$body = preg_match('/<body[^>]*>(.*)<\/body>/is', $raw, $m) ? $m[1] : $raw;

	$styles = '';
	if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $raw, $sm))
		foreach ($sm[1] as $css) $styles .= '<style>'.$css.'</style>'."\n";

	$fonts = '';
	if (preg_match_all('/<link[^>]+fonts\.googleapis\.com[^>]+>/i', $raw, $fm))
		$fonts = implode("\n", $fm[0])."\n";

	$d = asrg_csms_load_community_data();

	$js = '<script>'
		.'window.ASRG_AUTH='          .wp_json_encode($d['auth'])          .';'
		.'window.ASRG_REST='          .wp_json_encode($d['rest'])          .';'
		.'window.ASRG_VENDOR_CLAIMS=' .wp_json_encode($d['vendor_claims']) .';'
		.'window.ASRG_MY_CLAIMS='     .wp_json_encode($d['my_claims'])     .';'
		.'window.ASRG_VENDOR_PROFILES='.wp_json_encode($d['vendor_profiles']).';'
		.'window.ASRG_MY_PROFILE='    .wp_json_encode($d['my_profile'])    .';'
		.'</script>'."\n";

	return $fonts.$styles.$js.$body;
}
add_shortcode('asrg_vendor_portal', 'asrg_vendor_portal_shortcode');

// ── CONTENT FILTER: prevent wpautop corruption ────────────────────────────────
function asrg_csms_remove_wpautop($content) {
	if ( has_shortcode($content,'asrg_csms_evaluation') || has_shortcode($content,'asrg_vendor_portal') ) {
		remove_filter('the_content','wpautop');
		remove_filter('the_content','wptexturize');
	}
	return $content;
}
add_filter('the_content','asrg_csms_remove_wpautop', 1);

function asrg_csms_body_class($classes) {
	global $post;
	if (isset($post) && (
		has_shortcode($post->post_content,'asrg_csms_evaluation') ||
		has_shortcode($post->post_content,'asrg_vendor_portal')
	)) {
		$classes[] = 'asrg-csms-full-width';
	}
	return $classes;
}
add_filter('body_class','asrg_csms_body_class');
