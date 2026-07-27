<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\EmailEditor;

use Automattic\WooCommerce\EmailEditor\Email_Editor_Container;
use Automattic\WooCommerce\EmailEditor\Engine\Personalizer;
use Automattic\WooCommerce\EmailEditor\Engine\Renderer\Renderer as EmailRenderer;
use Automattic\WooCommerce\Internal\EmailEditor\EmailTemplates\WooEmailTemplate;
use Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmails;
use Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsGenerator;
use Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsManager;

/**
 * Class responsible for rendering block-based emails.
 */
class BlockEmailRenderer {
	const WOO_EMAIL_CONTENT_PLACEHOLDER = '##WOO_CONTENT##';

	/**
	 * Service for rendering block emails
	 *
	 * @var EmailRenderer
	 */
	private $renderer;

	/**
	 * Service for personalization of emails
	 * It replaces personalization tags with actual values
	 *
	 * @var Personalizer
	 */
	private $personalizer;

	/**
	 * Service for extracting WooCommerce content from WC_Email object.
	 *
	 * @var WooContentProcessor
	 */
	private $woo_content_processor;

	/**
	 * WooCommerce Email Template Manager instance.
	 *
	 * @var WCTransactionalEmailPostsManager
	 */
	private $template_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$editor_container       = Email_Editor_Container::container();
		$this->renderer         = $editor_container->get( EmailRenderer::class );
		$this->personalizer     = $editor_container->get( Personalizer::class );
		$this->template_manager = WCTransactionalEmailPostsManager::get_instance();
	}

	/**
	 * Initialize the renderer.
	 *
	 * @param WooContentProcessor $woo_content_processor Service for extracting WooCommerce content from WC_Email object.
	 * @internal
	 */
	final public function init( WooContentProcessor $woo_content_processor ): void {
		$this->woo_content_processor = $woo_content_processor;
		add_action( 'woocommerce_email_blocks_renderer_initialized', array( $this, 'register_block_renderers' ) );
	}

	/**
	 * Maybe render block-based email content.
	 *
	 * @param \WC_Email $wc_email WooCommerce email.
	 * @return string|null Modified email content
	 */
	public function maybe_render_block_email( \WC_Email $wc_email ): ?string {
		$email_post = $this->get_email_post_by_wc_email( $wc_email );

		// Without a published post, the file template is the source of truth
		// (WP Site Editor pattern): render from a synthetic post built from it.
		// Only emails registered for the block editor get the file fallback —
		// others (e.g. third-party emails that did not opt in via the
		// `woocommerce_transactional_emails_for_block_editor` filter) keep
		// rendering through the classic pipeline, matching the pre-lazy-creation
		// boundary where only registered emails ever had a generated post.
		if ( ! $email_post ) {
			if ( ! in_array( $wc_email->id, WCTransactionalEmails::get_transactional_emails(), true ) ) {
				return null;
			}

			$email_post = $this->build_post_from_file_template( $wc_email );
			if ( ! $email_post ) {
				return null;
			}
		}

		$woo_content = $this->woo_content_processor->get_woo_content( $wc_email );
		return $this->render_block_email( $email_post, $woo_content, $wc_email );
	}

	/**
	 * Maybe render block-based email content.
	 *
	 * @param \WP_Post  $email_post Email post.
	 * @param string    $woo_content WooCommerce email content.
	 * @param \WC_Email $wc_email WooCommerce email.
	 * @return string Modified email content
	 */
	private function render_block_email( \WP_Post $email_post, string $woo_content, \WC_Email $wc_email ): ?string {
		try {
			// Set email context before rendering so blocks can access it.
			$filter_callback = function ( $context = array() ) use ( $wc_email ) {
				return array_merge( $context, $this->build_email_context( $wc_email ) );
			};
			add_filter( 'woocommerce_email_editor_rendering_email_context', $filter_callback, 10, 1 );

			// We will get subject from $email_post after we add it to the editor.
			$subject   = $wc_email->get_subject();
			$preheader = $wc_email->get_preheader();
			// A synthetic post has no `_wp_page_template` meta, so the template slug must be passed explicitly.
			$template_slug       = 0 === $email_post->ID ? ( new WooEmailTemplate() )->get_slug() : '';
			$rendered_email_data = $this->renderer->render( $email_post, $subject, $preheader, 'en', '', $template_slug );
			$personalized_email  = $this->personalizer->personalize_content( $rendered_email_data['html'] );
			$rendered_email      = str_replace( self::WOO_EMAIL_CONTENT_PLACEHOLDER, $woo_content, $personalized_email );

			// Remove the filter after rendering to prevent context leakage.
			remove_filter( 'woocommerce_email_editor_rendering_email_context', $filter_callback );

			add_filter( 'woocommerce_email_styles', array( $this->woo_content_processor, 'prepare_css' ), 10, 2 );
			return $rendered_email;
		} catch ( \Exception $e ) {
			wc_caught_exception( $e, __METHOD__, array( $email_post, $woo_content, $wc_email ) );
			// Remove the filter in case of exception.
			if ( isset( $filter_callback ) ) {
				remove_filter( 'woocommerce_email_editor_rendering_email_context', $filter_callback );
			}
			return null;
		}
	}

	/**
	 * Get the email post for a given WC_Email.
	 *
	 * Only published posts are used for rendering: unpublished states
	 * (auto-draft, draft, trash) are editing scratchpads or removed content
	 * and must not affect outgoing emails.
	 *
	 * @param \WC_Email $email WooCommerce email.
	 * @return \WP_Post|null
	 */
	private function get_email_post_by_wc_email( \WC_Email $email ): ?\WP_Post {
		$email_post = $this->template_manager->get_email_post( $email->id );

		if ( $email_post && 'publish' !== $email_post->post_status ) {
			return null;
		}

		return $email_post;
	}

	/**
	 * Build a synthetic post from the file-based template for an email type.
	 *
	 * Used when no published post exists in the database, allowing rendering
	 * directly from the file template without a DB record. The content is the
	 * canonical post content (including the `woocommerce_email_content_post_data`
	 * filter), so what is sent matches both the editor scratchpad content and
	 * the hash the cleanup migration compared against before deleting a post.
	 *
	 * @param \WC_Email $wc_email WooCommerce email.
	 * @return \WP_Post|null Synthetic post, or null when no template content is available.
	 */
	private function build_post_from_file_template( \WC_Email $wc_email ): ?\WP_Post {
		$template_html = WCTransactionalEmailPostsGenerator::compute_canonical_post_content( $wc_email );

		if ( '' === $template_html ) {
			return null;
		}

		return new \WP_Post(
			(object) array(
				'ID'           => 0,
				'post_type'    => Integration::EMAIL_POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => $template_html,
				'post_title'   => $wc_email->get_title(),
				'post_name'    => $wc_email->id,
			)
		);
	}

	/**
	 * Build email context from WC_Email object.
	 *
	 * Extracts relevant context data from the WC_Email object that can be used
	 * by blocks during rendering, such as user ID, email address, order information, etc.
	 *
	 * Blocks that need cart product information can derive it from the user_id or email
	 * using CartCheckoutUtils::get_cart_product_ids_for_user().
	 *
	 * @param \WC_Email $wc_email WooCommerce email object.
	 * @return array Email context data.
	 */
	private function build_email_context( \WC_Email $wc_email ): array {
		$recipient_raw = $wc_email->get_recipient();
		$emails        = array_values( array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $recipient_raw ) ) ) ) );
		$context       = array(
			'recipient_email' => $emails[0] ?? null,
		);

		// Extract order-related context if the email object is an order.
		if ( isset( $wc_email->object ) && $wc_email->object instanceof \WC_Order ) {
			$order              = $wc_email->object;
			$context['user_id'] = $order->get_customer_id();
		}

		return $context;
	}
}
