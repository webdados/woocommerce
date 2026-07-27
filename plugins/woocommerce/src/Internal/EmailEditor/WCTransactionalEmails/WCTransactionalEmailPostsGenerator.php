<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails;

use Automattic\WooCommerce\Internal\EmailEditor\Integration;
use Automattic\WooCommerce\Internal\EmailEditor\EmailTemplates\WooEmailTemplate;
use Automattic\WooCommerce\Utilities\StringUtil;

/**
 * Class WCTransactionalEmailPostsGenerator
 *
 * Handles the generation of WooCommerce transactional email templates.
 * This class is responsible for initializing and managing default email templates,
 * as well as generating new templates when required.
 *
 * @package Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails
 */
class WCTransactionalEmailPostsGenerator {
	/**
	 * Resolve the block template name for the given email.
	 *
	 * Returns `$email->template_block` when set, otherwise derives it from
	 * `$email->template_plain` by replacing the `plain` segment with `block`
	 * (e.g. `emails/plain/customer-invoice.php` becomes `emails/block/customer-invoice.php`).
	 *
	 * @param \WC_Email $email The email object.
	 * @return string The block template name, or an empty string if none can be resolved.
	 *
	 * @since 10.8.0
	 */
	public static function resolve_block_template_name( $email ): string {
		if ( ! empty( $email->template_block ) ) {
			return (string) $email->template_block;
		}

		$template_plain = (string) $email->template_plain;
		if ( '' === $template_plain ) {
			return '';
		}

		return str_replace( 'plain', 'block', $template_plain );
	}

	/**
	 * Resolve the absolute path of the block template for the given email.
	 *
	 * Uses {@see self::resolve_block_template_name()} for name resolution and then
	 * delegates to `wc_locate_template()` so theme overrides are honored.
	 *
	 * @param \WC_Email $email The email object.
	 * @return string The absolute template path, or an empty string if none can be resolved.
	 *
	 * @since 10.8.0
	 */
	public static function resolve_block_template_path( $email ): string {
		$template_name = self::resolve_block_template_name( $email );
		if ( '' === $template_name ) {
			return '';
		}

		return (string) wc_locate_template(
			$template_name,
			'',
			(string) $email->template_base
		);
	}

	/**
	 * Get the email template for the given email.
	 *
	 * Looks for the initial email block content in plugins/woocommerce/templates/emails/block.
	 *
	 * @param \WC_Email $email The email object.
	 * @return string The email template.
	 */
	public function get_email_template( $email ) {
		return self::render_block_template_html( $email );
	}

	/**
	 * Render the block template HTML for a given email.
	 *
	 * Resolves the block template (honouring theme overrides), falls back to the
	 * default block content on failure, and applies the
	 * `woocommerce_email_block_template_html` filter. Stateless so both the
	 * generator (via {@see self::get_email_template()}) and the divergence
	 * detector observe an identical rendering pipeline.
	 *
	 * @param \WC_Email $email The email object.
	 * @return string The rendered template HTML.
	 *
	 * @since 10.8.0
	 */
	public static function render_block_template_html( $email ): string {
		$template_name = self::resolve_block_template_name( $email );

		try {
			$template_html = wc_get_template_html(
				$template_name,
				array(),
				'',
				(string) $email->template_base
			);
		} catch ( \Exception $e ) {
			// wc_get_template_html() uses ob_start(), so we need to clean the output buffer if an exception is thrown.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$template_html = '';
		}

		// wc_get_template_html does not throw an error when the template is not found.
		// We need to check if the template is not found by checking the template_html content.
		$has_template_error =
			StringUtil::contains( $template_html, 'No such file or directory', false ) ||
			StringUtil::contains( $template_html, 'Failed to open stream', false ) ||
			StringUtil::contains( $template_html, 'Warning: include', false );

		if ( is_wp_error( $template_html ) || empty( $template_html ) || $has_template_error ) {
			$default_template_name = 'emails/block/default-block-content.php';
			$template_html         = wc_get_template_html(
				$default_template_name,
				array()
			);
		}

		/**
		 * Filter the email template HTML.
		 *
		 * @param string    $template_html The email template HTML.
		 * @param \WC_Email $email The email object.
		 * @since 10.7.0
		 */
		$filtered_template_html = apply_filters( 'woocommerce_email_block_template_html', $template_html, $email );

		return is_string( $filtered_template_html ) ? $filtered_template_html : $template_html;
	}

	/**
	 * Build the `wp_insert_post()` payload for a given email and apply the
	 * `woocommerce_email_content_post_data` filter.
	 *
	 * Extracted so the generator and the divergence detector observe the exact
	 * same pre-insert post payload, guaranteeing by construction that the hash
	 * stamped in {@see self::create_auto_draft()} and the hash recomputed
	 * in `WCEmailTemplateDivergenceDetector` hash identical input.
	 *
	 * Note: a `post_status` returned by the filter is not honored on the
	 * creation path — {@see self::create_auto_draft()} forces `auto-draft`
	 * because the status is system-owned (only published posts are rendered).
	 *
	 * @param string    $email_type The email type identifier (e.g. `customer_processing_order`).
	 * @param \WC_Email $email      The transactional email instance.
	 * @return array The post data array after the `woocommerce_email_content_post_data` filter runs.
	 *
	 * @since 10.8.0
	 */
	public static function build_filtered_post_data( string $email_type, $email ): array {
		$post_data = array(
			'post_type'    => Integration::EMAIL_POST_TYPE,
			'post_status'  => 'publish',
			'post_name'    => $email_type,
			'post_title'   => $email->get_title(),
			'post_excerpt' => $email->get_description(),
			'post_content' => self::render_block_template_html( $email ),
			'meta_input'   => array(
				'_wp_page_template' => ( new WooEmailTemplate() )->get_slug(),
			),
		);

		/**
		 * Filter the email content post data before creating the post.
		 *
		 * Allows third-party integrators to modify the post data (title, content, meta, etc.)
		 * before the email content post is created.
		 *
		 * @since 10.5.0
		 * @param array     $post_data  The post data array to be used for wp_insert_post().
		 * @param string    $email_type The email type identifier (e.g., 'customer_processing_order').
		 * @param \WC_Email $email      The WooCommerce email object.
		 */
		$filtered_post_data = apply_filters( 'woocommerce_email_content_post_data', $post_data, $email_type, $email );

		return is_array( $filtered_post_data ) ? $filtered_post_data : $post_data;
	}

	/**
	 * Compute the canonical `post_content` for a given email.
	 *
	 * Returns the `post_content` value that the generator would persist for this
	 * email after the `woocommerce_email_content_post_data` filter runs, i.e.
	 * the exact string whose sha1 is stamped into `_wc_email_template_source_hash`.
	 *
	 * Callers can hash the return value to obtain `currentCoreHash` for
	 * divergence detection.
	 *
	 * @param \WC_Email $email The transactional email instance.
	 * @return string The canonical post content.
	 *
	 * @since 10.8.0
	 */
	public static function compute_canonical_post_content( $email ): string {
		$post_data = self::build_filtered_post_data( (string) $email->id, $email );
		return (string) ( $post_data['post_content'] ?? '' );
	}

	/**
	 * Create an auto-draft email post for the given email.
	 *
	 * The auto-draft is the editing scratchpad created when a user opens the
	 * email editor for an email type that has no saved post yet. It stays
	 * invisible to rendering (only published posts are used) and links to its
	 * email type solely via the `_wc_email_type` meta — the option mapping is
	 * written when the post is published, see
	 * `Integration::save_email_mapping_on_publish()`.
	 *
	 * @param \WC_Email $email The transactional email instance.
	 * @return int The post ID of the created auto-draft.
	 * @throws \Exception When post creation fails.
	 *
	 * @since 11.1.0
	 */
	public function create_auto_draft( $email ): int {
		$email_type = (string) $email->id;
		$post_data  = self::build_filtered_post_data( $email_type, $email );

		// The status is system-owned: it must stay `auto-draft` so the post is
		// ignored by rendering until published, regardless of what the
		// `woocommerce_email_content_post_data` filter returns.
		$post_data['post_status'] = 'auto-draft';

		if ( ! isset( $post_data['meta_input'] ) || ! is_array( $post_data['meta_input'] ) ) {
			$post_data['meta_input'] = array();
		}
		$post_data['meta_input'][ WCTransactionalEmailPostsManager::EMAIL_TYPE_META_KEY ] = $email_type;

		// Sync meta stamp for emails participating in template update propagation.
		// VERSION + LAST_SYNCED_AT are filter-independent and can ride on `meta_input`
		// during the insert. SOURCE_HASH must reflect the post_content WordPress
		// actually persisted (post-`content_save_pre` filter chain), so we stamp it
		// after the insert returns and re-fetch the post to hash its saved content.
		$sync_config = WCEmailTemplateSyncRegistry::get_email_sync_config( $email_type );
		if ( null !== $sync_config ) {
			$post_data['meta_input'][ WCEmailTemplateDivergenceDetector::VERSION_META_KEY ]          = (string) $sync_config['version'];
			$post_data['meta_input'][ WCEmailTemplateDivergenceDetector::LAST_SYNCED_AT_META_KEY ]   = gmdate( 'Y-m-d H:i:s' );
			$post_data['meta_input'][ WCEmailTemplateDivergenceDetector::LAST_CORE_RENDER_META_KEY ] = (string) ( $post_data['post_content'] ?? '' );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			throw new \Exception( esc_html( $post_id->get_error_message() ) );
		}

		if ( null !== $sync_config ) {
			$saved_post = get_post( $post_id );
			$saved_body = $saved_post instanceof \WP_Post ? (string) $saved_post->post_content : (string) ( $post_data['post_content'] ?? '' );
			update_post_meta(
				(int) $post_id,
				WCEmailTemplateDivergenceDetector::SOURCE_HASH_META_KEY,
				sha1( $saved_body )
			);
			// Freshly created posts match canonical core by construction.
			update_post_meta(
				(int) $post_id,
				WCEmailTemplateDivergenceDetector::STATUS_META_KEY,
				WCEmailTemplateDivergenceDetector::STATUS_IN_SYNC
			);
		}

		return (int) $post_id;
	}
}
