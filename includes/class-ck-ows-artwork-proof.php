<?php
/**
 * Artwork proof workflow module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Artwork_Proof {
	public const META_PROOF_ID                = '_ck_ows_artwork_proof_id';
	public const META_PROOF_URL               = '_ck_ows_artwork_proof_url';
	public const META_PROOF_REVISIONS         = '_ck_ows_artwork_proof_revisions';
	public const META_APPROVAL_STATE          = '_ck_ows_artwork_approval_state';
	public const META_APPROVED_AT             = '_ck_ows_artwork_approved_at';
	public const META_APPROVED_BY             = '_ck_ows_artwork_approved_by';
	public const META_CHANGES_REQUESTED_AT    = '_ck_ows_artwork_changes_requested_at';
	public const META_CHANGES_REQUESTED_BY    = '_ck_ows_artwork_changes_requested_by';
	public const META_CHANGES_REQUEST_MESSAGE = '_ck_ows_artwork_changes_request_message';
	public const META_OVERRIDE_AT             = '_ck_ows_artwork_override_at';
	public const META_OVERRIDE_BY             = '_ck_ows_artwork_override_by';
	public const META_OVERRIDE_REASON         = '_ck_ows_artwork_override_reason';
	private const DELETE_ACTION_NONCE         = 'ck_ows_artwork_delete';
	private const DELETE_ACTION_NONCE_FIELD   = 'ck_ows_artwork_delete_nonce';
	private const UPLOAD_ACTION_NONCE         = 'ck_ows_artwork_upload';
	private const UPLOAD_ACTION_NONCE_FIELD   = 'ck_ows_artwork_upload_nonce';
	private const CUSTOMER_ACTION_NONCE       = 'ck_ows_artwork_customer';
	private const CUSTOMER_ACTION_NONCE_FIELD = 'ck_ows_artwork_customer_nonce';

	public const STATE_PENDING  = 'pending';
	public const STATE_APPROVED = 'approved';
	public const STATE_CHANGES  = 'changes_requested';

	private static ?CK_OWS_Artwork_Proof $instance = null;

	public static function instance(): CK_OWS_Artwork_Proof {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_action( 'post_edit_form_tag', array( $this, 'add_multipart_encoding' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_meta' ), 20, 2 );
		add_action( 'admin_post_ck_ows_artwork_upload', array( $this, 'handle_staff_upload' ) );
		add_action( 'admin_post_ck_ows_artwork_delete', array( $this, 'handle_staff_delete_revision' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_artwork_state_order_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_artwork_state_order_column_legacy' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_artwork_state_order_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_artwork_state_order_column_hpos' ), 20, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_panel' ), 30 );
		add_action( 'admin_post_ck_ows_artwork_action', array( $this, 'handle_customer_action' ) );

		add_action( 'admin_post_ck_ows_artwork_override', array( $this, 'handle_staff_override' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'enforce_production_gate' ), 20, 4 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function enqueue_admin_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}

		$valid_screens = array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$valid_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! in_array( $screen->id, $valid_screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'ck-ows-admin-ui',
			CK_OWS_URL . 'assets/css/admin-ui.css',
			array(),
			CK_OWS_VERSION
		);
	}

	public function add_artwork_state_order_column( array $columns ): array {
		$updated_columns = array();

		foreach ( $columns as $key => $label ) {
			$updated_columns[ $key ] = $label;

			if ( 'order_status' === $key || 'wc_actions' === $key ) {
				$updated_columns['ck_ows_artwork_state'] = esc_html__( 'Artwork', 'ck-order-workflow-suite' );
			}
		}

		if ( ! isset( $updated_columns['ck_ows_artwork_state'] ) ) {
			$updated_columns['ck_ows_artwork_state'] = esc_html__( 'Artwork', 'ck-order-workflow-suite' );
		}

		return $updated_columns;
	}

	public function render_artwork_state_order_column_legacy( string $column, int $post_id ): void {
		if ( 'ck_ows_artwork_state' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		$this->render_order_list_artwork_badge( $order );
	}

	public function render_artwork_state_order_column_hpos( string $column, $order_or_id ): void {
		if ( 'ck_ows_artwork_state' !== $column ) {
			return;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( absint( $order_or_id ) );
		$this->render_order_list_artwork_badge( $order );
	}

	private function render_order_list_artwork_badge( ?WC_Order $order ): void {
		if ( ! $order || ! self::order_has_artwork_proof( $order ) ) {
			echo '<span class="ck-ows-artwork-admin__state is-not-set">' . esc_html__( 'None', 'ck-order-workflow-suite' ) . '</span>';
			return;
		}

		$state = (string) $order->get_meta( self::META_APPROVAL_STATE, true );
		echo '<span class="ck-ows-artwork-admin__state ' . esc_attr( $this->get_state_badge_class( $state ) ) . '">' . esc_html( $this->format_state_label( $state ) ) . '</span>';
	}

	public function add_multipart_encoding(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}

		$valid_screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$valid_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( in_array( $screen->id, $valid_screens, true ) ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function order_has_artwork_proof( WC_Order $order ): bool {
		$revisions = self::get_proof_revisions( $order );
		if ( ! empty( $revisions ) ) {
			return true;
		}

		$proof_id  = absint( $order->get_meta( self::META_PROOF_ID, true ) );
		$proof_url = (string) $order->get_meta( self::META_PROOF_URL, true );

		if ( $proof_id > 0 && ! wp_get_attachment_url( $proof_id ) ) {
			$proof_id = 0;
		}

		return $proof_id > 0 || '' !== $proof_url;
	}

	public static function order_is_artwork_approved( WC_Order $order ): bool {
		$state = (string) $order->get_meta( self::META_APPROVAL_STATE, true );

		return self::STATE_APPROVED === $state;
	}

	public static function order_has_staff_override( WC_Order $order ): bool {
		$reason = (string) $order->get_meta( self::META_OVERRIDE_REASON, true );

		return '' !== trim( $reason );
	}

	public function add_order_metabox( string $screen ): void {
		$valid_screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$valid_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! in_array( $screen, $valid_screens, true ) ) {
			return;
		}

		add_meta_box(
			'ck-ows-artwork-proof',
			esc_html__( 'Artwork Proof Approval', 'ck-order-workflow-suite' ),
			array( $this, 'render_order_metabox' ),
			$screen,
			'side',
			'default'
		);
	}

	public function render_order_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not available.', 'ck-order-workflow-suite' ) . '</p>';
			return;
		}

		$proof_id   = absint( $order->get_meta( self::META_PROOF_ID, true ) );
		$proof_url  = (string) $order->get_meta( self::META_PROOF_URL, true );
		$proof_link = $proof_id > 0 ? wp_get_attachment_url( $proof_id ) : $proof_url;
		$state      = (string) $order->get_meta( self::META_APPROVAL_STATE, true );

		wp_nonce_field( 'ck_ows_artwork_meta_' . $order->get_id(), 'ck_ows_artwork_meta_nonce' );

		echo '<div class="ck-ows-artwork-admin">';
		echo '<div class="ck-ows-artwork-admin__section">';
		echo '<h4 class="ck-ows-artwork-admin__title">' . esc_html__( 'Upload Proof PDF', 'ck-order-workflow-suite' ) . '</h4>';
		echo '<p class="ck-ows-artwork-admin__hint">' . esc_html__( 'Upload a PDF proof for customer approval.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		wp_nonce_field( self::UPLOAD_ACTION_NONCE . '_' . $order->get_id(), self::UPLOAD_ACTION_NONCE_FIELD );
		echo '<div class="ck-ows-artwork-admin__upload-row">';
		echo '<input type="file" id="ck_ows_artwork_pdf" name="ck_ows_artwork_pdf" accept="application/pdf">';
		echo '<button type="submit" name="action" value="ck_ows_artwork_upload" formmethod="post" formenctype="multipart/form-data" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="button button-primary">' . esc_html__( 'Upload PDF', 'ck-order-workflow-suite' ) . '</button>';
		echo '</div>';
		echo '</div>';

		$revisions = self::get_proof_revisions( $order );
		if ( $proof_link ) {
			$latest_label = 'v1';
			if ( ! empty( $revisions ) ) {
				$latest_label = $this->get_revision_label( count( $revisions ) - 1 );
			}

			echo '<div class="ck-ows-artwork-admin__section ck-ows-artwork-admin__proof-head">';
			echo '<p><strong>' . esc_html( sprintf( __( 'Current proof (%s)', 'ck-order-workflow-suite' ), $latest_label ) ) . '</strong></p>';
			echo '<p><a class="button button-secondary" href="' . esc_url( $proof_link ) . '" target="_blank" rel="noopener">' . esc_html__( 'View PDF', 'ck-order-workflow-suite' ) . '</a></p>';
			echo '</div>';
		}

		if ( ! empty( $revisions ) ) {
			echo '<div class="ck-ows-artwork-admin__section">';
			echo '<p><strong>' . esc_html__( 'Proof versions', 'ck-order-workflow-suite' ) . '</strong></p>';
			echo '<ul class="ck-ows-artwork-admin__versions">';
			for ( $index = count( $revisions ) - 1; $index >= 0; $index-- ) {
				$revision     = $revisions[ $index ];
				$url          = isset( $revision['url'] ) ? (string) $revision['url'] : '';
				$uploaded_at  = isset( $revision['uploaded_at'] ) ? absint( $revision['uploaded_at'] ) : 0;
				$version      = $this->get_revision_label( $index );
				$version_text = $uploaded_at > 0 ? sprintf( '%s (%s)', $version, wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $uploaded_at ) ) : $version;
				$delete_url   = wp_nonce_url(
					add_query_arg(
						array(
							'action'   => 'ck_ows_artwork_delete',
							'order_id' => $order->get_id(),
							'rev'      => $index,
						),
						admin_url( 'admin-post.php' )
					),
					self::DELETE_ACTION_NONCE,
					self::DELETE_ACTION_NONCE_FIELD
				);

				echo '<li class="ck-ows-artwork-admin__version">';
				if ( '' !== $url ) {
					echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $version_text ) . '</a>';
				} else {
					echo esc_html( $version_text );
				}
				echo '<a href="' . esc_url( $delete_url ) . '" class="button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this artwork proof version?', 'ck-order-workflow-suite' ) ) . '\');">' . esc_html__( 'Delete', 'ck-order-workflow-suite' ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		echo '<div class="ck-ows-artwork-admin__section">';
		echo '<p><strong>' . esc_html__( 'Approval state:', 'ck-order-workflow-suite' ) . '</strong> <span class="ck-ows-artwork-admin__state ' . esc_attr( $this->get_state_badge_class( $state ) ) . '">' . esc_html( $this->format_state_label( $state ) ) . '</span></p>';
		echo '</div>';

		$override_url = add_query_arg(
			array(
				'order_id' => $order->get_id(),
			),
			admin_url( 'admin-post.php' )
		);

		echo '<hr class="ck-ows-artwork-admin__divider">';
		echo '<div class="ck-ows-artwork-admin__section">';
		echo '<p><strong>' . esc_html__( 'Staff Override', 'ck-order-workflow-suite' ) . '</strong></p>';
		echo '<p class="ck-ows-artwork-admin__hint">' . esc_html__( 'A reason is required to move directly to production.', 'ck-order-workflow-suite' ) . '</p>';
		wp_nonce_field( 'ck_ows_artwork_override_' . $order->get_id(), 'ck_ows_artwork_override_nonce' );
		echo '<div class="ck-ows-artwork-admin__override-row">';
		echo '<input type="text" name="ck_ows_override_reason" placeholder="' . esc_attr__( 'Mandatory override reason', 'ck-order-workflow-suite' ) . '">';
		echo '<button type="submit" name="action" value="ck_ows_artwork_override" formmethod="post" formaction="' . esc_url( $override_url ) . '" class="button button-secondary">' . esc_html__( 'Override to Production', 'ck-order-workflow-suite' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	public function handle_staff_upload(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::UPLOAD_ACTION_NONCE . '_' . $order_id, self::UPLOAD_ACTION_NONCE_FIELD );

		if ( empty( $_FILES['ck_ows_artwork_pdf']['name'] ) ) {
			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
			$redirect = add_query_arg( 'ck_ows_artwork_upload_error', rawurlencode( (string) __( 'Please choose a PDF file to upload.', 'ck-order-workflow-suite' ) ), $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		$upload_result = $this->upload_artwork_file_for_order( $order );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );

		if ( is_wp_error( $upload_result ) ) {
			$redirect = add_query_arg( 'ck_ows_artwork_upload_error', rawurlencode( $upload_result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'ck_ows_artwork_upload_success', 1, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_staff_delete_revision(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$rev      = isset( $_GET['rev'] ) ? absint( wp_unslash( $_GET['rev'] ) ) : -1;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::DELETE_ACTION_NONCE, self::DELETE_ACTION_NONCE_FIELD );

		$revisions = self::get_proof_revisions( $order );
		if ( ! isset( $revisions[ $rev ] ) ) {
			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
			$redirect = add_query_arg( 'ck_ows_artwork_delete_error', 1, $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		$deleted_version = $this->get_revision_label( $rev );
		unset( $revisions[ $rev ] );
		$revisions = array_values( $revisions );

		if ( empty( $revisions ) ) {
			$order->delete_meta_data( self::META_PROOF_ID );
			$order->delete_meta_data( self::META_PROOF_URL );
			$order->delete_meta_data( self::META_PROOF_REVISIONS );
			$order->delete_meta_data( self::META_APPROVAL_STATE );
		} else {
			$latest = $revisions[ count( $revisions ) - 1 ];
			$order->update_meta_data( self::META_PROOF_REVISIONS, $revisions );
			$order->update_meta_data( self::META_PROOF_ID, isset( $latest['attachment_id'] ) ? absint( $latest['attachment_id'] ) : 0 );
			$order->update_meta_data( self::META_PROOF_URL, isset( $latest['url'] ) ? esc_url_raw( (string) $latest['url'] ) : '' );
			$order->update_meta_data( self::META_APPROVAL_STATE, self::STATE_PENDING );
		}

		$order->save();
		$order->add_order_note( sprintf( __( 'Artwork proof %s deleted by staff.', 'ck-order-workflow-suite' ), $deleted_version ) );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		$redirect = add_query_arg( 'ck_ows_artwork_delete_success', 1, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function save_order_meta( int $order_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}

		if ( ! $post || ! isset( $post->post_type ) || 'shop_order' !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST['ck_ows_artwork_meta_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['ck_ows_artwork_meta_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'ck_ows_artwork_meta_' . $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( empty( $_FILES['ck_ows_artwork_pdf']['name'] ) ) {
			return;
		}

		$this->upload_artwork_file_for_order( $order );
	}

	private function upload_artwork_file_for_order( WC_Order $order ) {
		$order_id = $order->get_id();

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$uploaded = wp_handle_upload(
			$_FILES['ck_ows_artwork_pdf'],
			array(
				'test_form' => false,
				'mimes'     => array( 'pdf' => 'application/pdf' ),
			)
		);

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'ck_ows_artwork_upload_error', (string) $uploaded['error'] );
		}

		$file_path = (string) $uploaded['file'];
		$file_type = wp_check_filetype( wp_basename( $file_path ), null );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $file_type['type'],
				'post_title'     => sanitize_file_name( wp_basename( $file_path ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path,
			$order_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file_path ) );

		$order->update_meta_data( self::META_PROOF_ID, $attachment_id );
		$order->update_meta_data( self::META_PROOF_URL, esc_url_raw( (string) $uploaded['url'] ) );
		$this->append_proof_revision(
			$order,
			array(
				'attachment_id' => $attachment_id,
				'url'           => esc_url_raw( (string) $uploaded['url'] ),
				'uploaded_at'   => time(),
				'uploaded_by'   => get_current_user_id(),
			)
		);
		$order->update_meta_data( self::META_APPROVAL_STATE, self::STATE_PENDING );
		$order->save();

		$order->add_order_note( __( 'Artwork proof PDF uploaded and approval requested.', 'ck-order-workflow-suite' ) );

		if ( 'awaiting-artwork' !== $order->get_status() ) {
			$order->update_status( 'awaiting-artwork', __( 'Order moved to Awaiting Artwork Approval after proof upload.', 'ck-order-workflow-suite' ), true );
		}

		return true;
	}

	public function render_customer_panel( WC_Order $order ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		if ( ! self::order_has_artwork_proof( $order ) ) {
			return;
		}

		$proof_url  = $this->get_proof_url( $order );
		$revisions  = self::get_proof_revisions( $order );
		$state      = (string) $order->get_meta( self::META_APPROVAL_STATE, true );
		$fallback_notice = $this->get_customer_fallback_notice();

		echo '<section class="ck-ows-artwork-proof">';

		if ( null !== $fallback_notice ) {
			echo '<div class="woocommerce-notices-wrapper">';
			echo '<ul class="woocommerce-' . esc_attr( $fallback_notice['type'] ) . '" role="alert">';
			echo '<li>' . esc_html( $fallback_notice['message'] ) . '</li>';
			echo '</ul>';
			echo '</div>';
		}

		echo '<div class="ck-ows-artwork-proof__head">';
		echo '<h2 class="ck-ows-artwork-proof__title">' . esc_html__( 'Artwork Proof Approval', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<div class="ck-ows-artwork-proof__state"><strong>' . esc_html__( 'Current state:', 'ck-order-workflow-suite' ) . '</strong> <span class="ck-ows-artwork-proof__state-value">' . esc_html( $this->format_state_label( $state ) ) . '</span></div>';
		echo '</div>';
		echo '<p class="ck-ows-artwork-proof__intro">' . esc_html__( 'Please review your artwork proof before production begins.', 'ck-order-workflow-suite' ) . '</p>';

		if ( count( $revisions ) > 1 ) {
			echo '<p><strong>' . esc_html__( 'Previous versions', 'ck-order-workflow-suite' ) . '</strong></p>';
			echo '<ul class="ck-ows-artwork-proof__versions">';
			for ( $index = count( $revisions ) - 2; $index >= 0; $index-- ) {
				$revision = $revisions[ $index ];
				$url      = isset( $revision['url'] ) ? (string) $revision['url'] : '';
				if ( '' === $url ) {
					continue;
				}

				$uploaded_at = isset( $revision['uploaded_at'] ) ? absint( $revision['uploaded_at'] ) : 0;
				$label       = $uploaded_at > 0 ? sprintf( __( '%1$s from %2$s', 'ck-order-workflow-suite' ), $this->get_revision_label( $index ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $uploaded_at ) ) : $this->get_revision_label( $index );

				echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></li>';
			}
			echo '</ul>';
		}

		if ( self::STATE_APPROVED === $state ) {
			echo '<p>' . esc_html__( 'Thank you. Your artwork has been approved.', 'ck-order-workflow-suite' ) . '</p>';
			echo '</section>';
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		$changes_open_attr = self::STATE_CHANGES === $state ? ' open' : '';

		echo '<form method="post" action="' . esc_url( $action_url ) . '" class="ck-ows-artwork-proof__form">';
		echo '<input type="hidden" name="action" value="ck_ows_artwork_action">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		wp_nonce_field( self::CUSTOMER_ACTION_NONCE . '_' . $order->get_id(), self::CUSTOMER_ACTION_NONCE_FIELD );

		echo '<div class="ck-ows-artwork-proof__actions">';
		echo '<div class="ck-ows-artwork-proof__approve">';

		if ( $proof_url ) {
			echo '<p class="ck-ows-artwork-proof__proof-link"><a class="button ck-ows-artwork-proof__button ck-ows-artwork-proof__button--ghost" href="' . esc_url( $proof_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View proof PDF', 'ck-order-workflow-suite' ) . '</a></p>';
		}

		echo '<p><button type="submit" name="artwork_action" value="approve" class="button ck-ows-artwork-proof__button ck-ows-artwork-proof__button--approve">' . esc_html__( 'Approve artwork', 'ck-order-workflow-suite' ) . '</button></p>';
		echo '</div>';

		echo '<details class="ck-ows-artwork-proof__changes"' . $changes_open_attr . '>';
		echo '<summary>' . esc_html__( 'Need edits? Request changes', 'ck-order-workflow-suite' ) . '</summary>';
		echo '<p><label class="screen-reader-text" for="ck_ows_changes_note">' . esc_html__( 'Request changes', 'ck-order-workflow-suite' ) . '</label>';
		echo '<textarea name="changes_note" id="ck_ows_changes_note" rows="4" placeholder="' . esc_attr__( 'Tell us what needs to change.', 'ck-order-workflow-suite' ) . '"></textarea></p>';
		echo '<p><button type="submit" name="artwork_action" value="request_changes" class="button button-secondary ck-ows-artwork-proof__button ck-ows-artwork-proof__button--changes">' . esc_html__( 'Submit change request', 'ck-order-workflow-suite' ) . '</button></p>';
		echo '</details>';
		echo '</div>';
		echo '</form>';
		echo '</section>';
	}

	public function handle_customer_action(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in.', 'ck-order-workflow-suite' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::CUSTOMER_ACTION_NONCE . '_' . $order_id, self::CUSTOMER_ACTION_NONCE_FIELD );

		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			wp_die( esc_html__( 'You cannot update this order.', 'ck-order-workflow-suite' ) );
		}

		$action = isset( $_POST['artwork_action'] ) ? sanitize_key( wp_unslash( $_POST['artwork_action'] ) ) : '';

		if ( 'approve' === $action ) {
			$order->update_meta_data( self::META_APPROVAL_STATE, self::STATE_APPROVED );
			$order->update_meta_data( self::META_APPROVED_AT, time() );
			$order->update_meta_data( self::META_APPROVED_BY, get_current_user_id() );
			$order->save();
			$order->add_order_note( __( 'Customer approved artwork proof.', 'ck-order-workflow-suite' ) );

			if ( 'awaiting-artwork' === $order->get_status() ) {
				$order->update_status( 'in-production', __( 'Artwork approved by customer. Order moved to In Production.', 'ck-order-workflow-suite' ), true );
			}

			$this->redirect_customer_with_notice( $order, __( 'Thanks, your artwork has been approved.', 'ck-order-workflow-suite' ), 'success' );
		}

		if ( 'request_changes' === $action ) {
			$message = isset( $_POST['changes_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['changes_note'] ) ) : '';

			if ( '' === trim( $message ) ) {
				$this->redirect_customer_with_notice( $order, __( 'Please include details for your change request.', 'ck-order-workflow-suite' ), 'error' );
			}

			$order->update_meta_data( self::META_APPROVAL_STATE, self::STATE_CHANGES );
			$order->update_meta_data( self::META_CHANGES_REQUESTED_AT, time() );
			$order->update_meta_data( self::META_CHANGES_REQUESTED_BY, get_current_user_id() );
			$order->update_meta_data( self::META_CHANGES_REQUEST_MESSAGE, $message );
			$order->save();
			$order->add_order_note( sprintf( __( 'Customer requested artwork changes: %s', 'ck-order-workflow-suite' ), $message ) );

			if ( 'awaiting-artwork' !== $order->get_status() ) {
				$order->update_status( 'awaiting-artwork', __( 'Order moved back to Awaiting Artwork Approval after customer change request.', 'ck-order-workflow-suite' ), true );
			}

			$this->redirect_customer_with_notice( $order, __( 'Thanks, we have sent your change request to our team.', 'ck-order-workflow-suite' ), 'success' );
		}

		$this->redirect_customer_with_notice( $order, __( 'Invalid artwork action.', 'ck-order-workflow-suite' ), 'error' );
	}

	private function redirect_customer_with_notice( WC_Order $order, string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		$notice_url = add_query_arg(
			array(
				'ck_ows_artwork_notice'      => $message,
				'ck_ows_artwork_notice_type' => $type,
			),
			$order->get_view_order_url()
		);

		wp_safe_redirect( $notice_url );
		exit;
	}

	private function get_customer_fallback_notice(): ?array {
		if ( ! isset( $_GET['ck_ows_artwork_notice'] ) || ! isset( $_GET['ck_ows_artwork_notice_type'] ) ) {
			return null;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['ck_ows_artwork_notice'] ) );
		$type    = sanitize_key( wp_unslash( $_GET['ck_ows_artwork_notice_type'] ) );

		if ( '' === $message ) {
			return null;
		}

		if ( ! in_array( $type, array( 'error', 'success', 'notice' ), true ) ) {
			$type = 'notice';
		}

		return array(
			'message' => $message,
			'type'    => $type,
		);
	}

	public function handle_staff_override(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		$override_nonce = isset( $_POST['ck_ows_artwork_override_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ck_ows_artwork_override_nonce'] ) ) : '';
		if ( '' === $override_nonce || ! wp_verify_nonce( $override_nonce, 'ck_ows_artwork_override_' . $order_id ) ) {
			$redirect = wp_get_referer() ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			$redirect = add_query_arg( 'ck_ows_override_nonce_failed', 1, $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		$reason = isset( $_POST['ck_ows_override_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['ck_ows_override_reason'] ) ) : '';

		if ( '' === trim( $reason ) ) {
			$redirect = wp_get_referer() ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			$redirect = add_query_arg( 'ck_ows_override_reason_required', 1, $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		$order->update_meta_data( self::META_OVERRIDE_REASON, $reason );
		$order->update_meta_data( self::META_OVERRIDE_BY, get_current_user_id() );
		$order->update_meta_data( self::META_OVERRIDE_AT, time() );
		$order->save();

		$order->add_order_note( sprintf( __( 'Staff override approved artwork and moved to production. Reason: %s', 'ck-order-workflow-suite' ), $reason ) );
		$order->update_status( 'in-production', __( 'Staff override moved order to In Production.', 'ck-order-workflow-suite' ), true );

		$redirect = wp_get_referer() ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		$redirect = add_query_arg( 'ck_ows_override_success', 1, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function enforce_production_gate( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		unset( $order_id );

		if ( 'in-production' !== $to_status || 'in-production' === $from_status ) {
			return;
		}

		if ( self::order_can_move_to_production( $order ) ) {
			return;
		}

		$order->update_status( $from_status, __( 'Transition to In Production blocked: artwork approval required.', 'ck-order-workflow-suite' ), true );
		$order->add_order_note( __( 'Order attempted to move to In Production without required artwork approval.', 'ck-order-workflow-suite' ) );
	}

	public static function order_can_move_to_production( WC_Order $order ): bool {
		if ( ! self::order_has_artwork_proof( $order ) ) {
			return true;
		}

		return self::order_is_artwork_approved( $order ) || self::order_has_staff_override( $order );
	}

	public function admin_notices(): void {
		if ( isset( $_GET['ck_ows_override_reason_required'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Override reason is mandatory to move an artwork approval order to production.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_override_nonce_failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed for artwork override. Please refresh and try again.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_override_success'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Artwork override applied and order moved to In Production.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_artwork_upload_error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['ck_ows_artwork_upload_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Artwork upload failed: %s', 'ck-order-workflow-suite' ), $error ) ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_artwork_upload_success'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Artwork proof uploaded successfully.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_artwork_delete_success'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Artwork proof version deleted successfully.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_artwork_delete_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not delete artwork proof version.', 'ck-order-workflow-suite' ) . '</p></div>';
		}
	}

	private function get_revision_label( int $index ): string {
		return 'v' . (string) ( $index + 1 );
	}

	private function get_proof_url( WC_Order $order ): string {
		$revisions = self::get_proof_revisions( $order );
		if ( ! empty( $revisions ) ) {
			$latest = $revisions[ count( $revisions ) - 1 ];
			if ( isset( $latest['url'] ) && '' !== (string) $latest['url'] ) {
				return (string) $latest['url'];
			}
		}

		$proof_id = absint( $order->get_meta( self::META_PROOF_ID, true ) );

		if ( $proof_id > 0 ) {
			$url = wp_get_attachment_url( $proof_id );
			if ( $url ) {
				return (string) $url;
			}
		}

		return (string) $order->get_meta( self::META_PROOF_URL, true );
	}

	private static function get_proof_revisions( WC_Order $order ): array {
		$stored = $order->get_meta( self::META_PROOF_REVISIONS, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$revisions = array();
		foreach ( $stored as $revision ) {
			if ( ! is_array( $revision ) ) {
				continue;
			}

			$url = isset( $revision['url'] ) ? esc_url_raw( (string) $revision['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}

			$revisions[] = array(
				'attachment_id' => isset( $revision['attachment_id'] ) ? absint( $revision['attachment_id'] ) : 0,
				'url'           => $url,
				'uploaded_at'   => isset( $revision['uploaded_at'] ) ? absint( $revision['uploaded_at'] ) : 0,
				'uploaded_by'   => isset( $revision['uploaded_by'] ) ? absint( $revision['uploaded_by'] ) : 0,
			);
		}

		return $revisions;
	}

	private function append_proof_revision( WC_Order $order, array $revision ): void {
		$revisions   = self::get_proof_revisions( $order );
		$revisions[] = array(
			'attachment_id' => isset( $revision['attachment_id'] ) ? absint( $revision['attachment_id'] ) : 0,
			'url'           => isset( $revision['url'] ) ? esc_url_raw( (string) $revision['url'] ) : '',
			'uploaded_at'   => isset( $revision['uploaded_at'] ) ? absint( $revision['uploaded_at'] ) : time(),
			'uploaded_by'   => isset( $revision['uploaded_by'] ) ? absint( $revision['uploaded_by'] ) : 0,
		);

		$order->update_meta_data( self::META_PROOF_REVISIONS, $revisions );
	}

	private function format_state_label( string $state ): string {
		$map = array(
			self::STATE_PENDING  => __( 'Pending customer approval', 'ck-order-workflow-suite' ),
			self::STATE_APPROVED => __( 'Approved by customer', 'ck-order-workflow-suite' ),
			self::STATE_CHANGES  => __( 'Changes requested', 'ck-order-workflow-suite' ),
		);

		if ( isset( $map[ $state ] ) ) {
			return $map[ $state ];
		}

		return __( 'Not set', 'ck-order-workflow-suite' );
	}

	private function get_state_badge_class( string $state ): string {
		if ( self::STATE_APPROVED === $state ) {
			return 'is-approved';
		}

		if ( self::STATE_CHANGES === $state ) {
			return 'is-changes';
		}

		if ( self::STATE_PENDING === $state ) {
			return 'is-pending';
		}

		return 'is-not-set';
	}
}
