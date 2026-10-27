<?php
/**
 * Seeds the EventCatalog with every code this plugin can emit.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDefinition;
use HelloLog\Events\EventType;
use HelloLog\Events\ObjectType;
use HelloLog\Events\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Single registry of (code, severity, object, event_type, message-template)
 * tuples shared by every sensor. Centralising the catalog here means the
 * payload shape stays consistent even as sensors come and go.
 */
final class CatalogSeeder {

	public static function seed( EventCatalog $catalog ): void {
		self::auth( $catalog );
		self::users( $catalog );
		self::content( $catalog );
		self::media( $catalog );
		self::comments( $catalog );
		self::taxonomies( $catalog );
		self::plugins_themes( $catalog );
		self::settings_system( $catalog );
		self::files_db( $catalog );
		self::menus_widgets( $catalog );
		self::multisite( $catalog );
		self::request( $catalog );
		self::two_factor( $catalog );
		self::app_passwords( $catalog );
		self::woocommerce( $catalog );
		self::forms( $catalog );
		self::seo_acf( $catalog );
		self::misc_integrations( $catalog );
		self::lw_specific( $catalog );
	}

	private static function auth( EventCatalog $c ): void {
		$c->register( new EventDefinition( 1000, ObjectType::USER, EventType::LOGIN, Severity::INFO, 'User {username} logged in.' ) );
		$c->register( new EventDefinition( 1001, ObjectType::USER, EventType::LOGOUT, Severity::INFO, 'User {username} logged out.' ) );
		$c->register( new EventDefinition( 1002, ObjectType::USER, EventType::FAILED_LOGIN, Severity::MEDIUM, 'Failed login attempt for {username}.' ) );
		$c->register( new EventDefinition( 1003, ObjectType::USER, EventType::PASSWORD_RESET, Severity::MEDIUM, 'Password reset requested for {username}.' ) );
		$c->register( new EventDefinition( 1004, ObjectType::USER, EventType::PASSWORD_RESET, Severity::MEDIUM, 'Password reset completed for {username}.' ) );
	}

	private static function users( EventCatalog $c ): void {
		$c->register( new EventDefinition( 4000, ObjectType::USER, EventType::CREATED, Severity::LOW, 'New user {username} registered with role {role}.' ) );
		$c->register( new EventDefinition( 4001, ObjectType::USER, EventType::PROFILE_UPDATED, Severity::INFO, 'Profile updated for {username}.' ) );
		$c->register( new EventDefinition( 4002, ObjectType::USER, EventType::ROLE_CHANGED, Severity::HIGH, 'Role for {username} changed from {old_role} to {new_role}.' ) );
		$c->register( new EventDefinition( 4003, ObjectType::USER, EventType::DELETED, Severity::HIGH, 'User {username} deleted.' ) );
		$c->register( new EventDefinition( 4004, ObjectType::USER, EventType::UPDATED, Severity::HIGH, 'Password changed for {username}.' ) );
		$c->register( new EventDefinition( 4005, ObjectType::USER, EventType::UPDATED, Severity::HIGH, 'E-mail address changed for {username}.' ) );
	}

	private static function content( EventCatalog $c ): void {
		$c->register( new EventDefinition( 2000, ObjectType::POST, EventType::CREATED, Severity::INFO, '{post_type} "{title}" created.' ) );
		$c->register( new EventDefinition( 2001, ObjectType::POST, EventType::PUBLISHED, Severity::LOW, '{post_type} "{title}" published.' ) );
		$c->register( new EventDefinition( 2002, ObjectType::POST, EventType::UNPUBLISHED, Severity::LOW, '{post_type} "{title}" unpublished.' ) );
		$c->register( new EventDefinition( 2003, ObjectType::POST, EventType::TRASHED, Severity::LOW, '{post_type} "{title}" moved to trash.' ) );
		$c->register( new EventDefinition( 2004, ObjectType::POST, EventType::RESTORED, Severity::INFO, '{post_type} "{title}" restored from trash.' ) );
		$c->register( new EventDefinition( 2005, ObjectType::POST, EventType::DELETED, Severity::MEDIUM, '{post_type} "{title}" permanently deleted.' ) );
		$c->register( new EventDefinition( 2006, ObjectType::POST, EventType::UPDATED, Severity::INFO, '{post_type} "{title}" updated.' ) );
		$c->register( new EventDefinition( 2007, ObjectType::POST, EventType::UPDATED, Severity::INFO, '{post_type} "{title}" scheduled for {date}.' ) );
		$c->register( new EventDefinition( 2008, ObjectType::POST, EventType::SUBMITTED, Severity::INFO, '{post_type} "{title}" submitted for review.' ) );
		$c->register( new EventDefinition( 2009, ObjectType::POST, EventType::UPDATED, Severity::LOW, '{post_type} "{title}" made private.' ) );
		$c->register( new EventDefinition( 2013, ObjectType::POST, EventType::UPDATED, Severity::MEDIUM, '{post_type} "{title}" reverted to auto-draft.' ) );
	}

	private static function media( EventCatalog $c ): void {
		$c->register( new EventDefinition( 2010, ObjectType::MEDIA, EventType::CREATED, Severity::INFO, 'Media "{title}" uploaded.' ) );
		$c->register( new EventDefinition( 2011, ObjectType::MEDIA, EventType::UPDATED, Severity::INFO, 'Media "{title}" edited.' ) );
		$c->register( new EventDefinition( 2012, ObjectType::MEDIA, EventType::DELETED, Severity::LOW, 'Media "{title}" deleted.' ) );
	}

	private static function comments( EventCatalog $c ): void {
		$c->register( new EventDefinition( 2200, ObjectType::COMMENT, EventType::CREATED, Severity::INFO, 'New comment on {post_title} by {author}.' ) );
		$c->register( new EventDefinition( 2201, ObjectType::COMMENT, EventType::APPROVED, Severity::INFO, 'Comment on {post_title} approved.' ) );
		$c->register( new EventDefinition( 2202, ObjectType::COMMENT, EventType::SPAM, Severity::LOW, 'Comment on {post_title} marked as spam.' ) );
		$c->register( new EventDefinition( 2203, ObjectType::COMMENT, EventType::TRASHED, Severity::LOW, 'Comment on {post_title} trashed.' ) );
		$c->register( new EventDefinition( 2204, ObjectType::COMMENT, EventType::DELETED, Severity::LOW, 'Comment on {post_title} permanently deleted.' ) );
		$c->register( new EventDefinition( 2205, ObjectType::COMMENT, EventType::UPDATED, Severity::INFO, 'Comment on {post_title} edited.' ) );
	}

	private static function taxonomies( EventCatalog $c ): void {
		$c->register( new EventDefinition( 2120, ObjectType::TERM, EventType::CREATED, Severity::INFO, 'Term "{name}" created in {taxonomy}.' ) );
		$c->register( new EventDefinition( 2121, ObjectType::TERM, EventType::UPDATED, Severity::INFO, 'Term "{name}" updated in {taxonomy}.' ) );
		$c->register( new EventDefinition( 2122, ObjectType::TERM, EventType::DELETED, Severity::LOW, 'Term "{name}" deleted from {taxonomy}.' ) );
	}

	private static function plugins_themes( EventCatalog $c ): void {
		$c->register( new EventDefinition( 5000, ObjectType::PLUGIN, EventType::INSTALLED, Severity::MEDIUM, 'Plugin {name} installed.' ) );
		$c->register( new EventDefinition( 5001, ObjectType::PLUGIN, EventType::ACTIVATED, Severity::MEDIUM, 'Plugin {name} activated.' ) );
		$c->register( new EventDefinition( 5002, ObjectType::PLUGIN, EventType::DEACTIVATED, Severity::MEDIUM, 'Plugin {name} deactivated.' ) );
		$c->register( new EventDefinition( 5003, ObjectType::PLUGIN, EventType::UPDATED, Severity::MEDIUM, 'Plugin {name} updated to {version}.' ) );
		$c->register( new EventDefinition( 5004, ObjectType::PLUGIN, EventType::DELETED, Severity::HIGH, 'Plugin {name} deleted.' ) );
		$c->register( new EventDefinition( 5005, ObjectType::PLUGIN, EventType::UPDATED, Severity::MEDIUM, 'Automatic updates {state} for plugin {name}.' ) );

		$c->register( new EventDefinition( 5100, ObjectType::THEME, EventType::ACTIVATED, Severity::MEDIUM, 'Theme switched to {name}.' ) );
		$c->register( new EventDefinition( 5101, ObjectType::THEME, EventType::INSTALLED, Severity::MEDIUM, 'Theme {name} installed.' ) );
		$c->register( new EventDefinition( 5102, ObjectType::THEME, EventType::UPDATED, Severity::MEDIUM, 'Theme {name} updated to {version}.' ) );
		$c->register( new EventDefinition( 5103, ObjectType::THEME, EventType::DELETED, Severity::HIGH, 'Theme {name} deleted.' ) );
		$c->register( new EventDefinition( 5104, ObjectType::THEME, EventType::UPDATED, Severity::INFO, 'Theme customizer settings updated.' ) );
		$c->register( new EventDefinition( 5105, ObjectType::THEME, EventType::UPDATED, Severity::MEDIUM, 'Automatic updates {state} for theme {name}.' ) );
	}

	private static function settings_system( EventCatalog $c ): void {
		$c->register( new EventDefinition( 6000, ObjectType::SETTING, EventType::UPDATED, Severity::MEDIUM, 'Site option {option} changed.' ) );
		$c->register( new EventDefinition( 6001, ObjectType::SETTING, EventType::UPDATED, Severity::HIGH, 'Site URL changed from {old} to {new}.' ) );
		$c->register( new EventDefinition( 6002, ObjectType::SETTING, EventType::UPDATED, Severity::HIGH, 'Admin e-mail changed from {old} to {new}.' ) );
		$c->register( new EventDefinition( 6003, ObjectType::SETTING, EventType::UPDATED, Severity::MEDIUM, 'Permalink structure changed.' ) );
		$c->register( new EventDefinition( 6004, ObjectType::SETTING, EventType::UPDATED, Severity::HIGH, 'Search-engine visibility toggled to {state}.' ) );
		$c->register( new EventDefinition( 6005, ObjectType::SETTING, EventType::UPDATED, Severity::CRITICAL, 'User role capabilities changed.' ) );

		$c->register( new EventDefinition( 6100, ObjectType::SYSTEM, EventType::UPDATED, Severity::HIGH, 'WordPress core updated to {version}.' ) );
	}

	private static function files_db( EventCatalog $c ): void {
		$c->register( new EventDefinition( 6300, ObjectType::FILE, EventType::UPDATED_FILE, Severity::HIGH, '{kind} editor saved {file}.' ) );

		$c->register( new EventDefinition( 7100, ObjectType::DATABASE, EventType::CREATED, Severity::HIGH, 'Custom database table {name} created.' ) );
		$c->register( new EventDefinition( 7101, ObjectType::DATABASE, EventType::DELETED, Severity::HIGH, 'Custom database table {name} dropped.' ) );
	}

	private static function menus_widgets( EventCatalog $c ): void {
		$c->register( new EventDefinition( 2400, ObjectType::MENU, EventType::CREATED, Severity::INFO, 'Menu "{name}" created.' ) );
		$c->register( new EventDefinition( 2401, ObjectType::MENU, EventType::UPDATED, Severity::INFO, 'Menu "{name}" updated.' ) );
		$c->register( new EventDefinition( 2402, ObjectType::MENU, EventType::DELETED, Severity::LOW, 'Menu "{name}" deleted.' ) );

		$c->register( new EventDefinition( 2300, ObjectType::WIDGET, EventType::UPDATED, Severity::INFO, 'Widget {id_base} in sidebar {sidebar} updated.' ) );
		$c->register( new EventDefinition( 2301, ObjectType::WIDGET, EventType::DELETED, Severity::INFO, 'Widget {id_base} removed from {sidebar}.' ) );
	}

	private static function multisite( EventCatalog $c ): void {
		$c->register( new EventDefinition( 4100, ObjectType::SITE, EventType::CREATED, Severity::HIGH, 'Site {domain} created on the network.' ) );
		$c->register( new EventDefinition( 4101, ObjectType::SITE, EventType::DELETED, Severity::HIGH, 'Site {domain} deleted from the network.' ) );
		$c->register( new EventDefinition( 4102, ObjectType::SITE, EventType::UPDATED, Severity::HIGH, 'Site {domain} archived/unarchived: {state}.' ) );
		$c->register( new EventDefinition( 4150, ObjectType::NETWORK, EventType::UPDATED, Severity::HIGH, 'Super admin {username} {state}.' ) );
	}

	private static function request( EventCatalog $c ): void {
		$c->register( new EventDefinition( 6400, ObjectType::REQUEST, EventType::NOT_FOUND, Severity::LOW, '404 for {path}.' ) );
		$c->register( new EventDefinition( 6401, ObjectType::REQUEST, EventType::VIEWED, Severity::INFO, 'REST request {path} ({status}).' ) );
		$c->register( new EventDefinition( 6402, ObjectType::REQUEST, EventType::VIEWED, Severity::LOW, 'XML-RPC call: {method}.' ) );
	}

	private static function two_factor( EventCatalog $c ): void {
		$c->register( new EventDefinition( 4400, ObjectType::USER, EventType::TWO_FACTOR_ENABLED, Severity::MEDIUM, '2FA enabled for {username}.' ) );
		$c->register( new EventDefinition( 4401, ObjectType::USER, EventType::TWO_FACTOR_DISABLED, Severity::HIGH, '2FA disabled for {username}.' ) );
	}

	private static function app_passwords( EventCatalog $c ): void {
		$c->register( new EventDefinition( 4500, ObjectType::USER, EventType::CREATED, Severity::MEDIUM, 'Application password "{name}" created for {username}.' ) );
		$c->register( new EventDefinition( 4501, ObjectType::USER, EventType::DELETED, Severity::MEDIUM, 'Application password revoked for {username}.' ) );
		$c->register( new EventDefinition( 4502, ObjectType::USER, EventType::LOGIN, Severity::INFO, 'Application-password login for {username}.' ) );
	}

	private static function woocommerce( EventCatalog $c ): void {
		// Products
		$c->register( new EventDefinition( 9000, ObjectType::PRODUCT, EventType::CREATED, Severity::INFO, 'WC product "{name}" created.' ) );
		$c->register( new EventDefinition( 9001, ObjectType::PRODUCT, EventType::UPDATED, Severity::INFO, 'WC product "{name}" updated. Fields: {fields}.' ) );
		$c->register( new EventDefinition( 9002, ObjectType::PRODUCT, EventType::DELETED, Severity::MEDIUM, 'WC product "{name}" deleted.' ) );
		$c->register( new EventDefinition( 9003, ObjectType::PRODUCT, EventType::UPDATED, Severity::INFO, 'WC product "{name}" price changed from {old} to {new}.' ) );
		$c->register( new EventDefinition( 9004, ObjectType::PRODUCT, EventType::UPDATED, Severity::INFO, 'WC product "{name}" stock changed from {old} to {new}.' ) );

		// Orders
		$c->register( new EventDefinition( 9100, ObjectType::ORDER, EventType::CREATED, Severity::LOW, 'WC order #{id} created.' ) );
		$c->register( new EventDefinition( 9101, ObjectType::ORDER, EventType::UPDATED, Severity::LOW, 'WC order #{id} status: {old} → {new}.' ) );
		$c->register( new EventDefinition( 9102, ObjectType::ORDER, EventType::DELETED, Severity::MEDIUM, 'WC order #{id} deleted.' ) );
		$c->register( new EventDefinition( 9103, ObjectType::ORDER, EventType::UPDATED, Severity::INFO, 'Note added to WC order #{id}: {note}' ) );
		$c->register( new EventDefinition( 9104, ObjectType::ORDER, EventType::UPDATED, Severity::MEDIUM, 'Refund issued on WC order #{id}: {amount}.' ) );

		// Coupons
		$c->register( new EventDefinition( 9200, ObjectType::COUPON, EventType::CREATED, Severity::INFO, 'WC coupon "{code}" created.' ) );
		$c->register( new EventDefinition( 9201, ObjectType::COUPON, EventType::UPDATED, Severity::INFO, 'WC coupon "{code}" updated.' ) );
		$c->register( new EventDefinition( 9202, ObjectType::COUPON, EventType::DELETED, Severity::INFO, 'WC coupon "{code}" deleted.' ) );

		// Customers
		$c->register( new EventDefinition( 9300, ObjectType::CUSTOMER, EventType::CREATED, Severity::LOW, 'WC customer {username} registered.' ) );
		$c->register( new EventDefinition( 9301, ObjectType::CUSTOMER, EventType::UPDATED, Severity::INFO, 'WC customer {username} details updated.' ) );

		// Settings
		$c->register( new EventDefinition( 9400, ObjectType::SETTING, EventType::UPDATED, Severity::MEDIUM, 'WC setting {option} changed.' ) );
	}

	private static function forms( EventCatalog $c ): void {
		// Gravity Forms 5700–5799
		$c->register( new EventDefinition( 5700, ObjectType::FORM, EventType::CREATED, Severity::INFO, 'Gravity form "{title}" created.' ) );
		$c->register( new EventDefinition( 5701, ObjectType::FORM, EventType::UPDATED, Severity::INFO, 'Gravity form "{title}" updated.' ) );
		$c->register( new EventDefinition( 5702, ObjectType::FORM, EventType::DELETED, Severity::LOW, 'Gravity form "{title}" deleted.' ) );
		$c->register( new EventDefinition( 5710, ObjectType::ENTRY, EventType::SUBMITTED, Severity::INFO, 'Entry submitted to Gravity form "{title}".' ) );

		// WPForms 5800–5899
		$c->register( new EventDefinition( 5800, ObjectType::FORM, EventType::CREATED, Severity::INFO, 'WPForms form "{title}" created.' ) );
		$c->register( new EventDefinition( 5801, ObjectType::FORM, EventType::UPDATED, Severity::INFO, 'WPForms form "{title}" updated.' ) );
		$c->register( new EventDefinition( 5810, ObjectType::ENTRY, EventType::SUBMITTED, Severity::INFO, 'Entry submitted to WPForms form "{title}".' ) );

		// Contact Form 7 5850-5859
		$c->register( new EventDefinition( 5850, ObjectType::FORM, EventType::UPDATED, Severity::INFO, 'Contact Form 7 form "{title}" saved.' ) );
		$c->register( new EventDefinition( 5851, ObjectType::ENTRY, EventType::SUBMITTED, Severity::INFO, 'Mail sent from Contact Form 7 "{title}".' ) );

		// Fluent Forms 5860-5869
		$c->register( new EventDefinition( 5860, ObjectType::FORM, EventType::CREATED, Severity::INFO, 'Fluent form "{title}" created.' ) );
		$c->register( new EventDefinition( 5861, ObjectType::FORM, EventType::UPDATED, Severity::INFO, 'Fluent form "{title}" updated.' ) );
		$c->register( new EventDefinition( 5870, ObjectType::ENTRY, EventType::SUBMITTED, Severity::INFO, 'Entry submitted to Fluent form "{title}".' ) );
	}

	private static function seo_acf( EventCatalog $c ): void {
		// Yoast SEO 8800-8899
		$c->register( new EventDefinition( 8800, ObjectType::SETTING, EventType::UPDATED, Severity::MEDIUM, 'Yoast SEO option {option} changed.' ) );
		$c->register( new EventDefinition( 8801, ObjectType::REDIRECT, EventType::CREATED, Severity::INFO, 'Yoast SEO redirect added: {source} → {target}.' ) );

		// RankMath 8900-8999
		$c->register( new EventDefinition( 8900, ObjectType::SETTING, EventType::UPDATED, Severity::MEDIUM, 'RankMath option {option} changed.' ) );
		$c->register( new EventDefinition( 8901, ObjectType::REDIRECT, EventType::CREATED, Severity::INFO, 'RankMath redirect created: {source}.' ) );

		// ACF 5400-5499
		$c->register( new EventDefinition( 5400, ObjectType::FIELD, EventType::CREATED, Severity::INFO, 'ACF field group "{title}" created.' ) );
		$c->register( new EventDefinition( 5401, ObjectType::FIELD, EventType::UPDATED, Severity::INFO, 'ACF field group "{title}" updated.' ) );
		$c->register( new EventDefinition( 5402, ObjectType::FIELD, EventType::DELETED, Severity::LOW, 'ACF field group "{title}" deleted.' ) );
	}

	private static function misc_integrations( EventCatalog $c ): void {
		// bbPress 8000-8099
		$c->register( new EventDefinition( 8000, ObjectType::POST, EventType::CREATED, Severity::INFO, 'bbPress forum/topic/reply "{title}" created.' ) );
		$c->register( new EventDefinition( 8001, ObjectType::POST, EventType::DELETED, Severity::LOW, 'bbPress forum/topic/reply "{title}" deleted.' ) );

		// LearnDash 5000-5099 (LD-specific subset)
		$c->register( new EventDefinition( 8200, ObjectType::POST, EventType::CREATED, Severity::INFO, 'LearnDash {sub_type} "{title}" created.' ) );
		$c->register( new EventDefinition( 8201, ObjectType::USER, EventType::UPDATED, Severity::INFO, 'LearnDash enrollment for {username}: {course}.' ) );

		// MemberPress 6500-6599
		$c->register( new EventDefinition( 6500, ObjectType::POST, EventType::CREATED, Severity::INFO, 'MemberPress membership "{title}" created.' ) );
		$c->register( new EventDefinition( 6501, ObjectType::USER, EventType::UPDATED, Severity::INFO, 'MemberPress transaction for {username}: {amount}.' ) );

		// Paid Memberships Pro 5500-5599
		$c->register( new EventDefinition( 5500, ObjectType::USER, EventType::UPDATED, Severity::INFO, 'PMP membership for {username}: {level}.' ) );

		// Easy Digital Downloads 8300-8399
		$c->register( new EventDefinition( 8300, ObjectType::POST, EventType::CREATED, Severity::INFO, 'EDD download "{title}" created.' ) );
		$c->register( new EventDefinition( 8301, ObjectType::ORDER, EventType::CREATED, Severity::INFO, 'EDD payment {id} status: {status}.' ) );

		// TablePress 5300-5399
		$c->register( new EventDefinition( 5300, ObjectType::POST, EventType::CREATED, Severity::INFO, 'TablePress table "{name}" created.' ) );
		$c->register( new EventDefinition( 5301, ObjectType::POST, EventType::UPDATED, Severity::INFO, 'TablePress table "{name}" updated.' ) );
		$c->register( new EventDefinition( 5302, ObjectType::POST, EventType::DELETED, Severity::LOW, 'TablePress table "{name}" deleted.' ) );

		// Redirection plugin 5200-5299
		$c->register( new EventDefinition( 5200, ObjectType::REDIRECT, EventType::CREATED, Severity::INFO, 'Redirection rule added: {source} → {target}.' ) );
		$c->register( new EventDefinition( 5201, ObjectType::REDIRECT, EventType::DELETED, Severity::LOW, 'Redirection rule deleted.' ) );

		// MainWP 7700-7799
		$c->register( new EventDefinition( 7700, ObjectType::SITE, EventType::CREATED, Severity::LOW, 'MainWP child site added: {url}.' ) );
		$c->register( new EventDefinition( 7701, ObjectType::SITE, EventType::DELETED, Severity::LOW, 'MainWP child site removed: {url}.' ) );

		// Termly 8500-8599
		$c->register( new EventDefinition( 8500, ObjectType::SETTING, EventType::UPDATED, Severity::INFO, 'Termly cookie/consent settings changed.' ) );
	}

	private static function lw_specific( EventCatalog $c ): void {
		$c->register( new EventDefinition( 9500, ObjectType::REQUEST, EventType::REJECTED, Severity::HIGH, 'lw-firewall blocked request from {ip}: {reason}.' ) );
		$c->register( new EventDefinition( 9501, ObjectType::SETTING, EventType::UPDATED, Severity::INFO, 'lw-firewall rule changed: {rule}.' ) );

		$c->register( new EventDefinition( 9600, ObjectType::REDIRECT, EventType::UPDATED, Severity::INFO, 'lw-seo redirect rule changed.' ) );
		$c->register( new EventDefinition( 9601, ObjectType::SETTING, EventType::UPDATED, Severity::INFO, 'lw-seo setting {option} changed.' ) );

		$c->register( new EventDefinition( 9700, ObjectType::SETTING, EventType::UPDATED, Severity::INFO, 'lw-disable feature toggled: {feature} = {state}.' ) );
		$c->register( new EventDefinition( 9800, ObjectType::SETTING, EventType::UPDATED, Severity::INFO, 'lw-cookie consent updated.' ) );
		$c->register( new EventDefinition( 9900, ObjectType::SETTING, EventType::UPDATED, Severity::INFO, 'lw-zenadmin UI customization changed.' ) );
	}
}
