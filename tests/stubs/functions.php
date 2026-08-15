<?php
/**
 * Minimal WordPress function stubs for PHPUnit.
 *
 * This file is required (not eval'd) so Patchwork can intercept these
 * functions and Brain\Monkey can mock them per test.
 *
 * @package EssFinance\Tests
 */

// add_action / add_filter are intentionally absent — Brain\Monkey\setUp() loads
// wp-hook-functions.php which provides them with full hook-tracking support.
// Defining them here would silently block that file (it uses function_exists() guards).

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {}
}
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {}
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = [] ) {}
}
if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page, $args = [] ) {}
}
if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = [] ) {}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '', $scheme = 'admin' ) {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = false, $gmt = false ) {
		return date( $format, $timestamp === false ? time() : $timestamp ); // phpcs:ignore
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return true;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return $str;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		return 'http://example.com/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'plugin_action_links_' ) ) {
	// filter — no-op
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}
