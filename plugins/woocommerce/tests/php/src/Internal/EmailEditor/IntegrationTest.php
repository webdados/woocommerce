<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\EmailEditor;

use Automattic\WooCommerce\Internal\EmailEditor\Integration;
use Automattic\WooCommerce\Internal\EmailEditor\Package;
use Automattic\WooCommerce\Internal\EmailEditor\WCTransactionalEmails\WCTransactionalEmailPostsManager;
use WC_Unit_Test_Case;

/**
 * Tests for the Integration class.
 */
class IntegrationTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var Integration
	 */
	private $sut;

	/**
	 * Transactional email post manager singleton.
	 *
	 * @var WCTransactionalEmailPostsManager
	 */
	private WCTransactionalEmailPostsManager $posts_manager;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_option( 'woocommerce_feature_block_email_editor_enabled', 'yes' );
		wc_get_container()->get( Package::class )->init();

		$this->sut           = wc_get_container()->get( Integration::class );
		$this->posts_manager = WCTransactionalEmailPostsManager::get_instance();
		$this->posts_manager->clear_caches();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->posts_manager->clear_caches();
		update_option( 'woocommerce_feature_block_email_editor_enabled', 'no' );

		parent::tearDown();
	}

	/**
	 * @testdox Should write the email type mapping when an auto-draft transitions to publish.
	 */
	public function test_auto_draft_to_publish_writes_mapping(): void {
		$post = $this->create_woo_email_post( 'customer_processing_order' );

		$this->sut->save_email_mapping_on_publish( 'publish', 'auto-draft', $post );

		$this->assertSame(
			$post->ID,
			(int) get_option( 'woocommerce_email_templates_customer_processing_order_post_id' ),
			'Publishing an auto-draft must write the option mapping'
		);
	}

	/**
	 * @testdox Should write the email type mapping when a draft transitions to publish.
	 */
	public function test_draft_to_publish_writes_mapping(): void {
		$post = $this->create_woo_email_post( 'customer_completed_order', 'draft' );

		$this->sut->save_email_mapping_on_publish( 'publish', 'draft', $post );

		$this->assertSame(
			$post->ID,
			(int) get_option( 'woocommerce_email_templates_customer_completed_order_post_id' ),
			'Publishing a draft must write the option mapping'
		);
	}

	/**
	 * @testdox Should not touch an existing mapping on a publish-to-publish transition.
	 */
	public function test_publish_to_publish_leaves_existing_mapping_untouched(): void {
		$other_post_id = $this->factory()->post->create();
		$this->posts_manager->save_email_template_post_id( 'customer_new_account', $other_post_id );

		$post = $this->create_woo_email_post( 'customer_new_account', 'publish' );

		$this->sut->save_email_mapping_on_publish( 'publish', 'publish', $post );

		$this->assertSame(
			$other_post_id,
			(int) get_option( 'woocommerce_email_templates_customer_new_account_post_id' ),
			'A publish-to-publish transition (post update) must not rewrite the mapping'
		);
	}

	/**
	 * @testdox Should ignore posts of other post types.
	 */
	public function test_non_woo_email_post_is_ignored(): void {
		$post = $this->factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		update_post_meta( $post->ID, WCTransactionalEmailPostsManager::EMAIL_TYPE_META_KEY, 'customer_note' );

		$this->sut->save_email_mapping_on_publish( 'publish', 'draft', $post );

		$this->assertFalse(
			get_option( 'woocommerce_email_templates_customer_note_post_id' ),
			'Posts of other post types must not produce a mapping'
		);
	}

	/**
	 * @testdox Should not write a mapping when the email type meta is not a registered WC_Email id.
	 */
	public function test_unregistered_email_type_meta_does_not_write_mapping(): void {
		$post = $this->create_woo_email_post( 'not_a_registered_email_type' );

		$this->sut->save_email_mapping_on_publish( 'publish', 'auto-draft', $post );

		$this->assertFalse(
			get_option( 'woocommerce_email_templates_not_a_registered_email_type_post_id' ),
			'An unregistered email type in the meta must not produce a mapping'
		);
	}

	/**
	 * @testdox Should ignore woo_email posts without the email type meta.
	 */
	public function test_post_without_email_type_meta_is_ignored(): void {
		$post = $this->factory()->post->create_and_get(
			array(
				'post_type'   => Integration::EMAIL_POST_TYPE,
				'post_status' => 'auto-draft',
			)
		);

		$this->sut->save_email_mapping_on_publish( 'publish', 'auto-draft', $post );

		$this->assertNull(
			$this->posts_manager->get_email_type_from_post_id( $post->ID, true ),
			'A woo_email post without the email type meta must not produce a mapping'
		);
	}

	/**
	 * @testdox Should write the mapping through the transition_post_status hook when a post is published.
	 */
	public function test_mapping_written_via_transition_hook_on_publish(): void {
		$this->sut->initialize();

		$post = $this->create_woo_email_post( 'new_order' );

		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			$post->ID,
			(int) get_option( 'woocommerce_email_templates_new_order_post_id' ),
			'Publishing via wp_update_post must write the mapping through the transition_post_status hook'
		);
	}

	/**
	 * Create a `woo_email` post carrying the email type meta.
	 *
	 * @param string $email_type  Email type to stamp into the meta.
	 * @param string $post_status Post status. Defaults to `auto-draft`.
	 * @return \WP_Post
	 */
	private function create_woo_email_post( string $email_type, string $post_status = 'auto-draft' ): \WP_Post {
		$post = $this->factory()->post->create_and_get(
			array(
				'post_title'  => 'Email post for ' . $email_type,
				'post_type'   => Integration::EMAIL_POST_TYPE,
				'post_status' => $post_status,
			)
		);
		update_post_meta( $post->ID, WCTransactionalEmailPostsManager::EMAIL_TYPE_META_KEY, $email_type );

		return $post;
	}
}
