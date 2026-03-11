<?php
/**
 * Plugin Name:       ASRG CSMS Tool Evaluation
 * Plugin URI:        https://github.com/asrg-community/asrg-csms-evaluation
 * Description:       Embeds the ASRG CSMS Tool Evaluation comparison table via the [asrg_csms_evaluation] shortcode.
 * Version:           1.0.0
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

/**
 * Register the shortcode [asrg_csms_evaluation]
 */
function asrg_csms_evaluation_shortcode( $atts ) {
	$atts = shortcode_atts( [], $atts, 'asrg_csms_evaluation' );

	$html_file = plugin_dir_path( __FILE__ ) . 'asrg-csms-evaluation.html';

	if ( ! file_exists( $html_file ) ) {
		return '<p style="color:red;">ASRG CSMS Evaluation: <code>asrg-csms-evaluation.html</code> not found in plugin directory.</p>';
	}

	$raw = file_get_contents( $html_file );

	// Strip the outer <html>/<head>/<body> wrapper — we only want the inner content
	// Extract everything inside <body>...</body>
	if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $raw, $matches ) ) {
		$body = $matches[1];
	} else {
		$body = $raw; // fallback: use the whole file
	}

	// Extract <style> blocks from <head> and inline them at the top
	$styles = '';
	if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $raw, $style_matches ) ) {
		foreach ( $style_matches[1] as $css ) {
			$styles .= '<style>' . $css . '</style>' . "\n";
		}
	}

	// Extract and enqueue Google Fonts link tags
	// (WordPress handles these better when output in the page head, but inlining works fine)
	$fonts = '';
	if ( preg_match_all( '/<link[^>]+fonts\.googleapis\.com[^>]+>/i', $raw, $font_matches ) ) {
		$fonts = implode( "\n", $font_matches[0] ) . "\n";
	}

	// Inject logos.json as a JS variable so the evaluation table can use custom logos.
	// Edit plugin/logos.json to map vendor keys to image URLs.
	$logos_js = '<script>window.ASRG_LOGOS = {};</script>' . "\n";
	$logos_file = plugin_dir_path( __FILE__ ) . 'logos.json';
	if ( file_exists( $logos_file ) ) {
		$logos_raw  = file_get_contents( $logos_file );
		$logos_data = json_decode( $logos_raw, true );
		if ( is_array( $logos_data ) ) {
			// Strip internal comment key before passing to JS
			unset( $logos_data['_comment'] );
			// Remove null values so the UI falls back to Clearbit for those vendors
			$logos_data = array_filter( $logos_data, function ( $v ) { return ! is_null( $v ); } );
			$logos_js   = '<script>window.ASRG_LOGOS = ' . wp_json_encode( $logos_data ) . ';</script>' . "\n";
		}
	}

	return $fonts . $styles . $logos_js . $body;
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
