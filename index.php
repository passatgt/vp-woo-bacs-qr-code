<?php
/*
Plugin Name: Hungarian Bank Transfer QR Code for WooCommerce
Plugin URI: http://visztpeter.me
Description: Azonnali fizetéses QR kód megjelenítése az átutalásos fizetési módnál
Author: Viszt Péter
Version: 1.0
WC requires at least: 4.0.0
WC tested up to: 4.1.0
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Generate stuff on plugin activation
register_activation_hook( __FILE__, array( 'VP_Bacs_Qr_Code', 'activate' ) );

//Load QR generator class
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
use chillerlan\QRCode\{QRCode, QROptions};

class VP_Bacs_Qr_Code {

	public static $plugin_prefix;
	public $plugin_url;
	public static $plugin_path;
	public static $plugin_basename;
	public static $version;
	public $template_base = null;

	public $settings = null;

	protected static $_instance = null;

	//Ensures only one instance of WC_OnlinePolo is loaded or can be loaded
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	//Just for a little extra security
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cloning this object is forbidden.', 'woo-pont-shipping' ) );
	}

	//Just for a little extra security
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'woo-pont-shipping' ) );
	}

	//Construct
	public function __construct() {

		//Default variables
		self::$plugin_prefix = 'bacs_qr_code';
		self::$plugin_basename = plugin_basename(__FILE__);
		self::$plugin_path = trailingslashit(dirname(__FILE__));
		self::$version = '1.0';
		$this->plugin_url = plugin_dir_url(self::$plugin_basename);

		//Settings page
		add_action( 'woocommerce_settings_tabs_checkout', array( $this, 'add_redirect_setting' ) );
		add_action( 'woocommerce_update_options_payment_gateways_bacs', array( $this, 'save_qr_details' ) );

		//Embed in thank you page and emails
		add_action( 'woocommerce_thankyou_bacs', array( $this, 'thankyou_page' ), 20 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 11, 3 );

	}

	//Create folder for files
	public static function activate() {
		$upload_dir = wp_upload_dir();

		$files = array(
			array(
				'base' => $upload_dir['basedir'] . '/'.self::$plugin_prefix,
				'file' => 'index.html',
				'content' => ''
			)
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				if ( $file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'w' ) ) {
					fwrite( $file_handle, $file['content'] );
					fclose( $file_handle );
				}
			}
		}
	}

	//Add settings fields
	public function add_redirect_setting() {
		if(isset($_GET['section']) && $_GET['section'] == 'bacs') {
			$settings = array(
				array(
					'type' => 'title',
					'id' => 'vp_bacs_qr_code',
					'title' => __( 'QR kód beállítások', 'wc-vp-sign-documents' ),
				),
				array(
					'title' => __( 'Közlemény', 'wc-vp-sign-documents' ),
					'type' => 'text',
					'desc' => __( 'A generált QR kóddal indított utalás közleménye. Az {order_number} helyettesítő kóddal lehet a rendelés számát beírni. Maximum 70 karakter.', 'wc-vp-sign-documents' ),
					'id' => 'vp_bacs_qr_code_comment',
					'value' => get_option('vp_bacs_qr_code_comment'),
					'placeholder' => '{order_number}'
				),
				array(
					'title' => __( 'Szöveg a QR kód előtt', 'wc-vp-sign-documents' ),
					'type' => 'text',
					'desc' => __( 'Ez a szöveg jelenik meg a QR kód előtt a pénztár oldalon és a fizetésre vár emailben.', 'wc-vp-sign-documents' ),
					'id' => 'vp_bacs_qr_code_before',
					'value' => get_option('vp_bacs_qr_code_before'),
					'placeholder' => ''
				),
				array(
					'title' => __( 'Szöveg a QR kód után', 'wc-vp-sign-documents' ),
					'type' => 'text',
					'desc' => __( 'Ez a szöveg jelenik meg a QR kód előtt a pénztár oldalon és a fizetésre vár emailben.', 'wc-vp-sign-documents' ),
					'id' => 'vp_bacs_qr_code_after',
					'value' => get_option('vp_bacs_qr_code_after'),
					'placeholder' => ''
				),
				array(
					'type' => 'sectionend',
					'id' => 'vp_bacs_qr_code'
				),
			);
			?>
				<?php WC_Admin_Settings::output_fields($settings); ?>
			<?php
		}
	}

	//Save settings
	public function save_qr_details() {
		if ( isset( $_POST['vp_bacs_qr_code_comment'] ) && isset( $_POST['vp_bacs_qr_code_before'] ) && isset( $_POST['vp_bacs_qr_code_after'] ) ) {
			$qr_code_comment = wc_clean( wp_unslash( $_POST['vp_bacs_qr_code_comment'] ) );
			$qr_code_before = wc_clean( wp_unslash( $_POST['vp_bacs_qr_code_before'] ) );
			$qr_code_after = wc_clean( wp_unslash( $_POST['vp_bacs_qr_code_after'] ) );
			update_option( 'vp_bacs_qr_code_comment', $qr_code_comment );
			update_option( 'vp_bacs_qr_code_before', $qr_code_before );
			update_option( 'vp_bacs_qr_code_after', $qr_code_after );
		}
	}

	//Add QR code to thank you page
	public function thankyou_page( $order_id ) {
		$this->display_qr_code( $order_id );
	}

	//Add content to the WC emails.
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( ! $sent_to_admin && 'bacs' === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
			$this->display_qr_code( $order->get_id() );
		}
	}

	public function display_qr_code($order_id) {
		$order = wc_get_order($order_id);

		if($order && 'bacs' === $order->get_payment_method()) {

			//Get bank account details
			$bacs_accounts_info = get_option( 'woocommerce_bacs_accounts');
			$bank_account = $bacs_accounts_info[0];

			//Set expiration date
			$expiration_date = strtotime("+1 week");
			$expiration_date = date('YmdHisO', $expiration_date);

			//Create comment
			$comment = get_option( 'vp_bacs_qr_code_comment');
			if(!$comment) $comment = '{order_number}';
			$comment = str_replace( '{order_number}', $order->get_order_number(), $comment );

			//See if we have an invoice
			$proform_invoice = '';
			if($order->get_meta('_wc_szamlazz_proform')) $proform_invoice = $order->get_meta('_wc_szamlazz_proform');
			if($order->get_meta('_wc_billingo_plus_proform_name')) $proform_invoice = $order->get_meta('_wc_billingo_plus_proform_name');

			$qr_code_content = array(
				'HCT', //Azonosító kód
				'001', //Verziószám
				'1', //Karakterkészlet
				$bank_account['bic'], //Fizető fél vagy kedvezményezett BIC/BEI
				$bank_account['account_name'], //Fizető fél vagy kedvezményezett neve
				$bank_account['iban'], //Fizető fél vagy kedvezményezett IBAN
				'HUF'.$order->get_total(), //Összeg
				$expiration_date, //Érvényességi idő
				'', //Fizetési helyzet azonosító
				$comment, //Közlemény (nem strukturált)
				'', //Kereskedelmi egység, bolt azonosító
				'', //Kereskedői eszköz (POS, pénztárgép) azonosító
				$proform_invoice, //Számla vagy nyugta azonosító
				'', //Ügyfélazonosító
				'', //Kedvezményezett belső tranzakcióazonosítója
				'', //Törzsvásárlói vagy kedvezményezett azonosítója
				'', //NAV ellenőrző kód
				'', //Elválasztójelek helyigénye
				''
			);
			?>
			<div class="vp-bacs-qr-code">
				<?php if(get_option('vp_bacs_qr_code_before')): ?>
					<p><?php echo esc_html(get_option('vp_bacs_qr_code_before')); ?></p>
				<?php endif; ?>
				<p><?php echo '<img src="'.(new QRCode)->render(implode("\r\n",$qr_code_content)).'" alt="" />'; ?></p>
				<?php if(get_option('vp_bacs_qr_code_after')): ?>
					<p><?php echo esc_html(get_option('vp_bacs_qr_code_after')); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}
	}

}

//WC Detection
if ( ! function_exists( 'is_woocommerce_active' ) ) {
	function is_woocommerce_active() {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
		}

		return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins ) ;
	}
}

//Initialize, if woocommerce is active
if ( is_woocommerce_active() ) {
	function VP_Bacs_Qr_Code() {
		return VP_Bacs_Qr_Code::instance();
	}

	VP_Bacs_Qr_Code();
}
