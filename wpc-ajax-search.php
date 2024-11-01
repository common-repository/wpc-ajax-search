<?php
/*
Plugin Name: WPC AJAX Search for WooCommerce
Plugin URI: https://wpclever.net/
Description: An interaction search popup for WooCommerce.
Version: 2.4.1
Author: WPClever
Author URI: https://wpclever.net
Text Domain: wpc-ajax-search
Domain Path: /languages/
Requires Plugins: woocommerce
Requires at least: 4.0
Tested up to: 6.6
WC requires at least: 3.0
WC tested up to: 9.2
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WPCAS_VERSION' ) && define( 'WPCAS_VERSION', '2.4.1' );
! defined( 'WPCAS_LITE' ) && define( 'WPCAS_LITE', __FILE__ );
! defined( 'WPCAS_FILE' ) && define( 'WPCAS_FILE', __FILE__ );
! defined( 'WPCAS_URI' ) && define( 'WPCAS_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WPCAS_DIR' ) && define( 'WPCAS_DIR', plugin_dir_path( __FILE__ ) );
! defined( 'WPCAS_SUPPORT' ) && define( 'WPCAS_SUPPORT', 'https://wpclever.net/support?utm_source=support&utm_medium=wpcas&utm_campaign=wporg' );
! defined( 'WPCAS_REVIEWS' ) && define( 'WPCAS_REVIEWS', 'https://wordpress.org/support/plugin/wpc-ajax-search/reviews/?filter=5' );
! defined( 'WPCAS_CHANGELOG' ) && define( 'WPCAS_CHANGELOG', 'https://wordpress.org/plugins/wpc-ajax-search/#developers' );
! defined( 'WPCAS_DISCUSSION' ) && define( 'WPCAS_DISCUSSION', 'https://wordpress.org/support/plugin/wpc-ajax-search' );
! defined( 'WPC_URI' ) && define( 'WPC_URI', WPCAS_URI );

include 'includes/dashboard/wpc-dashboard.php';
include 'includes/kit/wpc-kit.php';
include 'includes/hpos.php';

if ( ! function_exists( 'wpcas_init' ) ) {
	add_action( 'plugins_loaded', 'wpcas_init', 11 );

	function wpcas_init() {
		// load text-domain
		load_plugin_textdomain( 'wpc-ajax-search', false, basename( __DIR__ ) . '/languages/' );

		if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
			add_action( 'admin_notices', 'wpcas_notice_wc' );

			return null;
		}

		if ( ! class_exists( 'WPCleverWpcas' ) && class_exists( 'WC_Product' ) ) {
			class WPCleverWpcas {
				protected static $instance = null;
				protected static $settings = [];
				protected static $localization = [];
				protected static $rules = [];

				public static function instance() {
					if ( is_null( self::$instance ) ) {
						self::$instance = new self();
					}

					return self::$instance;
				}

				function __construct() {
					self::$settings     = (array) get_option( 'wpcas_settings', [] );
					self::$localization = (array) get_option( 'wpcas_localization', [] );
					self::$rules        = (array) get_option( 'wpcas_rules', [] );

					// frontend
					add_action( 'init', [ $this, 'init' ] );
					add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
					add_filter( 'wp_nav_menu_items', [ $this, 'nav_menu_items' ], 99, 2 );
					add_action( 'wp_footer', [ $this, 'footer' ] );

					// backend
					add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
					add_action( 'admin_init', [ $this, 'register_settings' ] );
					add_action( 'admin_menu', [ $this, 'admin_menu' ] );
					add_filter( 'plugin_action_links', [ $this, 'action_links' ], 10, 2 );
					add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );

					if ( self::get_setting( 'search_sku', 'no' ) === 'yes' ) {
						add_filter( 'pre_get_posts', [ $this, 'search_sku' ], 99 );
					}

					// frontend ajax
					add_action( 'wc_ajax_wpcas_search', [ $this, 'ajax_search' ] );

					// backend ajax
					add_action( 'wp_ajax_wpcas_add_rule', [ $this, 'ajax_add_rule' ] );
					add_action( 'wp_ajax_wpcas_add_condition', [ $this, 'ajax_add_condition' ] );
					add_action( 'wp_ajax_wpcas_add_combined', [ $this, 'ajax_add_combined' ] );
					add_action( 'wp_ajax_wpcas_search_term', [ $this, 'ajax_search_term' ] );

					// actions
					if ( self::get_setting( 'compare', 'yes' ) === 'yes' ) {
						add_action( 'wpcas_product_actions', [ $this, 'item_compare' ], 1 );
					}

					if ( self::get_setting( 'wishlist', 'yes' ) === 'yes' ) {
						add_action( 'wpcas_product_actions', [ $this, 'item_wishlist' ], 2 );
					}

					if ( self::get_setting( 'add_to_cart', 'yes' ) === 'yes' ) {
						add_action( 'wpcas_product_actions', [ $this, 'item_add_to_cart' ], 3 );
					}

					// WPC Smart Messages
					add_filter( 'wpcsm_locations', [ $this, 'wpcsm_locations' ] );
				}

				function init() {
					add_shortcode( 'wpcas_categories', [ $this, 'shortcode_categories' ] );
					add_shortcode( 'wpcas_products', [ $this, 'shortcode_products' ] );
					add_shortcode( 'wpcas_posts', [ $this, 'shortcode_posts' ] );
				}

				function enqueue_scripts() {
					// disable on some pages
					if ( apply_filters( 'wpcas_disable', false ) ) {
						return;
					}

					// feather icons
					wp_enqueue_style( 'wpcas-feather', WPCAS_URI . 'assets/feather/feather.css' );

					// perfect scrollbar
					if ( self::get_setting( 'perfect_scrollbar', 'yes' ) === 'yes' ) {
						wp_enqueue_style( 'perfect-scrollbar', WPCAS_URI . 'assets/libs/perfect-scrollbar/css/perfect-scrollbar.min.css' );
						wp_enqueue_style( 'perfect-scrollbar-wpc', WPCAS_URI . 'assets/libs/perfect-scrollbar/css/custom-theme.css' );
						wp_enqueue_script( 'perfect-scrollbar', WPCAS_URI . 'assets/libs/perfect-scrollbar/js/perfect-scrollbar.jquery.min.js', [ 'jquery' ], WPCAS_VERSION, true );
					}

					// animated placeholder
					if ( self::get_setting( 'animated_placeholder', 'yes' ) === 'yes' ) {
						wp_enqueue_script( 'placeholderTypewriter', WPCAS_URI . 'assets/libs/placeholderTypewriter/placeholderTypewriter.js', [ 'jquery' ], WPCAS_VERSION, true );
					}

					// css
					wp_enqueue_style( 'wpcas-frontend', WPCAS_URI . 'assets/css/frontend.css', [], WPCAS_VERSION );

					if ( ( self::get_setting( 'animated_placeholder', 'yes' ) === 'yes' ) && ! empty( self::get_setting( 'placeholder_text' ) ) ) {
						$animated_placeholder = [
							'delay' => 50,
							'pause' => 3000,
						];

						$placeholder_text             = self::get_setting( 'placeholder_text' );
						$placeholder_arr              = explode( "\n", $placeholder_text );
						$placeholder_arr              = array_map( 'esc_attr', $placeholder_arr );
						$animated_placeholder['text'] = array_map( 'trim', $placeholder_arr );
					} else {
						$animated_placeholder = [];
					}

					// js
					wp_enqueue_script( 'wpcas-frontend', WPCAS_URI . 'assets/js/frontend.js', [ 'jquery' ], WPCAS_VERSION, true );
					wp_localize_script( 'wpcas-frontend', 'wpcas_vars', [
							'wc_ajax_url'          => WC_AJAX::get_endpoint( '%%endpoint%%' ),
							'nonce'                => wp_create_nonce( 'wpcas-security' ),
							'auto_show'            => apply_filters( 'wpcas_auto_show', self::get_setting( 'auto_show', 'yes' ) ),
							'auto_exclude'         => apply_filters( 'wpcas_auto_show_exclude', '' ),
							'manual_show'          => apply_filters( 'wpcas_manual_show', self::get_setting( 'manual_show', '' ) ),
							'position'             => self::get_setting( 'position', '01' ),
							'perfect_scrollbar'    => self::get_setting( 'perfect_scrollbar', 'yes' ),
							'placeholder_clicking' => self::get_setting( 'placeholder_clicking', 'empty' ),
							'animated_placeholder' => apply_filters( 'wpcas_animated_placeholder', json_encode( $animated_placeholder ) ),
						]
					);
				}

				function admin_enqueue_scripts( $hook ) {
					if ( str_contains( $hook, 'wpcas' ) ) {
						wp_enqueue_editor();
						wp_enqueue_style( 'wpcas-backend', WPCAS_URI . 'assets/css/backend.css', [ 'woocommerce_admin_styles' ], WPCAS_VERSION );
						wp_enqueue_script( 'wpcas-backend', WPCAS_URI . 'assets/js/backend.js', [
							'jquery',
							'jquery-ui-sortable',
							'jquery-ui-dialog',
							'wc-enhanced-select',
							'selectWoo',
						] );
						wp_localize_script( 'wpcas-backend', 'wpcas_vars', [
							'wpcas_nonce' => wp_create_nonce( 'wpcas_nonce' )
						] );
					}
				}

				function action_links( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$settings             = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'wpc-ajax-search' ) . '</a>';
						$links['wpc-premium'] = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=premium' ) ) . '">' . esc_html__( 'Premium Version', 'wpc-ajax-search' ) . '</a>';
						array_unshift( $links, $settings );
					}

					return (array) $links;
				}

				function row_meta( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$row_meta = [
							'support' => '<a href="' . esc_url( WPCAS_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'wpc-ajax-search' ) . '</a>',
						];

						return array_merge( $links, $row_meta );
					}

					return (array) $links;
				}

				function register_settings() {
					// settings
					register_setting( 'wpcas_settings', 'wpcas_settings' );
					// localization
					register_setting( 'wpcas_localization', 'wpcas_localization' );
					// rules
					register_setting( 'wpcas_rules', 'wpcas_rules' );
				}

				function admin_menu() {
					add_submenu_page( 'wpclever', esc_html__( 'WPC AJAX Search', 'wpc-ajax-search' ), esc_html__( 'AJAX Search', 'wpc-ajax-search' ), 'manage_options', 'wpclever-wpcas', [
						$this,
						'admin_menu_content'
					] );
				}

				function admin_menu_content() {
					add_thickbox();
					$active_tab = sanitize_key( $_GET['tab'] ?? 'settings' );
					?>
                    <div class="wpclever_settings_page wrap">
                        <h1 class="wpclever_settings_page_title"><?php echo esc_html__( 'WPC AJAX Search', 'wpc-ajax-search' ) . ' ' . esc_html( WPCAS_VERSION ) . ' ' . ( defined( 'WPCAS_PREMIUM' ) ? '<span class="premium" style="display: none">' . esc_html__( 'Premium', 'wpc-ajax-search' ) . '</span>' : '' ); ?></h1>
                        <div class="wpclever_settings_page_desc about-text">
                            <p>
								<?php printf( /* translators: stars */ esc_html__( 'Thank you for using our plugin! If you are satisfied, please reward it a full five-star %s rating.', 'wpc-ajax-search' ), '<span style="color:#ffb900">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' ); ?>
                                <br/>
                                <a href="<?php echo esc_url( WPCAS_REVIEWS ); ?>" target="_blank"><?php esc_html_e( 'Reviews', 'wpc-ajax-search' ); ?></a> |
                                <a href="<?php echo esc_url( WPCAS_CHANGELOG ); ?>" target="_blank"><?php esc_html_e( 'Changelog', 'wpc-ajax-search' ); ?></a> |
                                <a href="<?php echo esc_url( WPCAS_DISCUSSION ); ?>" target="_blank"><?php esc_html_e( 'Discussion', 'wpc-ajax-search' ); ?></a>
                            </p>
                        </div>
						<?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) { ?>
                            <div class="notice notice-success is-dismissible">
                                <p><?php esc_html_e( 'Settings updated.', 'wpc-ajax-search' ); ?></p>
                            </div>
						<?php } ?>
                        <div class="wpclever_settings_page_nav">
                            <h2 class="nav-tab-wrapper">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=settings' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'settings' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Settings', 'wpc-ajax-search' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=smart' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'smart' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Smart Search', 'wpc-ajax-search' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=localization' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'localization' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Localization', 'wpc-ajax-search' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=premium' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'premium' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>" style="color: #c9356e">
									<?php esc_html_e( 'Premium Version', 'wpc-ajax-search' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>" class="nav-tab">
									<?php esc_html_e( 'Essential Kit', 'wpc-ajax-search' ); ?>
                                </a>
                            </h2>
                        </div>
                        <div class="wpclever_settings_page_content">
							<?php if ( $active_tab === 'settings' ) {
								$auto_show             = self::get_setting( 'auto_show', 'yes' );
								$overlay_layer         = self::get_setting( 'overlay_layer', 'yes' );
								$position              = self::get_setting( 'position', '01' );
								$effect                = self::get_setting( 'effect', 'yes' );
								$perfect_scrollbar     = self::get_setting( 'perfect_scrollbar', 'yes' );
								$close                 = self::get_setting( 'close', 'yes' );
								$link                  = self::get_setting( 'link', 'yes' );
								$compare               = self::get_setting( 'compare', 'yes' );
								$wishlist              = self::get_setting( 'wishlist', 'yes' );
								$add_to_cart           = self::get_setting( 'add_to_cart', 'yes' );
								$exclude_hidden        = self::get_setting( 'exclude_hidden', 'no' );
								$exclude_unpurchasable = self::get_setting( 'exclude_unpurchasable', 'no' );
								$animated_placeholder  = self::get_setting( 'animated_placeholder', 'yes' );
								$placeholder_clicking  = self::get_setting( 'placeholder_clicking', 'empty' );
								$search_category       = self::get_setting( 'search_category', 'yes' );
								$search_sku            = self::get_setting( 'search_sku', 'no' );
								$more_results          = self::get_setting( 'more_results', 'yes' );
								$cache_method          = self::get_setting( 'cache_method', 'file' );
								?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th><?php esc_html_e( 'General', 'wpc-ajax-search' ); ?></th>
                                            <td><?php esc_html_e( 'General settings.', 'wpc-ajax-search' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Auto-open', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[auto_show]">
                                                    <option value="yes" <?php selected( $auto_show, 'yes' ); ?>><?php esc_html_e( 'Yes, open popup', 'wpc-ajax-search' ); ?></option>
                                                    <option value="yes_inline" <?php selected( $auto_show, 'yes_inline' ); ?>><?php esc_html_e( 'Yes, open inline', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $auto_show, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Do the AJAX search when clicking on all search inputs.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Manual show up button', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" name="wpcas_settings[manual_show]" class="regular-text" value="<?php echo esc_attr( self::get_setting( 'manual_show', '' ) ); ?>" placeholder="<?php esc_html_e( 'button class or id', 'wpc-ajax-search' ); ?>"/>
                                                <p class="description"><?php printf( /* translators: selector */ esc_html__( 'The class or id of the button, when clicking on this button the search popup will show up. Example %1$s or %2$s', 'wpc-ajax-search' ), '<code>.search-btn</code>', '<code>#search-btn</code>' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Overlay layer', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[overlay_layer]">
                                                    <option value="yes" <?php selected( $overlay_layer, 'yes' ); ?>><?php esc_html_e( 'Show', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $overlay_layer, 'no' ); ?>><?php esc_html_e( 'Hide', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'If you hide the overlay layer, the buyer still can work on your site when the search popup is opening.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Position', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[position]">
                                                    <option value="01" <?php selected( $position, '01' ); ?>><?php esc_html_e( 'Right', 'wpc-ajax-search' ); ?></option>
                                                    <option value="02" <?php selected( $position, '02' ); ?>><?php esc_html_e( 'Left', 'wpc-ajax-search' ); ?></option>
                                                    <option value="03" <?php selected( $position, '03' ); ?>><?php esc_html_e( 'Top', 'wpc-ajax-search' ); ?></option>
                                                    <option value="04" <?php selected( $position, '04' ); ?>><?php esc_html_e( 'Bottom', 'wpc-ajax-search' ); ?></option>
                                                    <option value="05" <?php selected( $position, '05' ); ?>><?php esc_html_e( 'Center', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Effect', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[effect]">
                                                    <option value="yes" <?php selected( $effect, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $effect, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Enable/disable slide effect.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Use perfect-scrollbar', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[perfect_scrollbar]">
                                                    <option value="yes" <?php selected( $perfect_scrollbar, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $perfect_scrollbar, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php printf( /* translators: link */ esc_html__( 'Read more about %s', 'wpc-ajax-search' ), '<a href="https://github.com/mdbootstrap/perfect-scrollbar" target="_blank">perfect-scrollbar</a>' ); ?>.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Close button', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[close]">
                                                    <option value="yes" <?php selected( $close, 'yes' ); ?>><?php esc_html_e( 'Show', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $close, 'no' ); ?>><?php esc_html_e( 'Hide', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Show/hide the close button.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Link to individual product', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[link]">
                                                    <option value="yes" <?php selected( $link, 'yes' ); ?>><?php esc_html_e( 'Yes, open in the same tab', 'wpc-ajax-search' ); ?></option>
                                                    <option value="yes_blank" <?php selected( $link, 'yes_blank' ); ?>><?php esc_html_e( 'Yes, open in the new tab', 'wpc-ajax-search' ); ?></option>
                                                    <option value="yes_popup" <?php selected( $link, 'yes_popup' ); ?>><?php esc_html_e( 'Yes, open quick view popup', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $link, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <p class="description">If you choose "Open quick view popup", please install and activate
                                                    <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-smart-quick-view&TB_iframe=true&width=800&height=550' ) ); ?>" class="thickbox" title="WPC Smart Quick View">WPC Smart Quick View</a> to make it work.
                                                </p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Compare button', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[compare]">
                                                    <option value="yes" <?php selected( $compare, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $compare, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description">Please install and activate <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-smart-compare&TB_iframe=true&width=800&height=550' ) ); ?>" class="thickbox" title="WPC Smart Compare">WPC Smart Compare</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Wishlist button', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[wishlist]">
                                                    <option value="yes" <?php selected( $wishlist, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $wishlist, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description">Please install and activate <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=woo-smart-wishlist&TB_iframe=true&width=800&height=550' ) ); ?>" class="thickbox" title="WPC Smart Wishlist">WPC Smart Wishlist</a> to make it work.</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Add to cart button', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[add_to_cart]">
                                                    <option value="yes" <?php selected( $add_to_cart, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $add_to_cart, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Exclude hidden', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[exclude_hidden]">
                                                    <option value="yes" <?php selected( $exclude_hidden, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $exclude_hidden, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Exclude hidden products from the search result.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Exclude unpurchasable', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[exclude_unpurchasable]">
                                                    <option value="yes" <?php selected( $exclude_unpurchasable, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $exclude_unpurchasable, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Exclude unpurchasable products from the search result.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Smart keywords', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <span class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcas&tab=smart' ) ); ?>" target="_blank"><?php esc_html_e( 'Configure smart keywords with many conditions.', 'wpc-ajax-search' ); ?></a></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Special keywords', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <div style="margin-bottom: 10px;">
                                                    <input type="text" name="wpcas_settings[keyword_on_sale]" value="<?php echo esc_attr( self::get_setting( 'keyword_on_sale', '' ) ); ?>"/>
                                                    <span class="description"><?php esc_html_e( 'Keyword for on-sale products.', 'wpc-ajax-search' ); ?></span>
                                                </div>
                                                <div style="margin-bottom: 10px;">
                                                    <input type="text" name="wpcas_settings[keyword_recent]" value="<?php echo esc_attr( self::get_setting( 'keyword_recent', '' ) ); ?>"/>
                                                    <span class="description"><?php esc_html_e( 'Keyword for recent products.', 'wpc-ajax-search' ); ?></span>
                                                </div>
                                                <div style="margin-bottom: 10px;">
                                                    <input type="text" name="wpcas_settings[keyword_featured]" value="<?php echo esc_attr( self::get_setting( 'keyword_featured', '' ) ); ?>"/>
                                                    <span class="description"><?php esc_html_e( 'Keyword for featured products.', 'wpc-ajax-search' ); ?></span>
                                                </div>
                                                <div>
                                                    <input type="text" name="wpcas_settings[keyword_popular]" value="<?php echo esc_attr( self::get_setting( 'keyword_popular', '' ) ); ?>"/>
                                                    <span class="description"><?php esc_html_e( 'Keyword for popular products.', 'wpc-ajax-search' ); ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Popular keywords', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <textarea name="wpcas_settings[popular_keywords]" rows="5" cols="50" class="large-text"><?php echo self::get_setting( 'popular_keywords' ); ?></textarea>
                                                <span class="description"><?php esc_html_e( 'Add popular keywords, split by a comma. It will be shown on the search popup. You also can use above special keywords.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Animated placeholder', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[animated_placeholder]" class="wpcas_animated_placeholder">
                                                    <option value="yes" <?php selected( $animated_placeholder, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $animated_placeholder, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Enable animated placeholder texts.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="wpcas-show-if-animated-placeholder">
                                            <th scope="row"><?php esc_html_e( 'Animated placeholder texts', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <textarea name="wpcas_settings[placeholder_text]" rows="10" cols="50" class="large-text"><?php echo self::get_setting( 'placeholder_text' ); ?></textarea>
                                                <span class="description"><?php esc_html_e( 'Add animated placeholder texts, each text in one line.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="wpcas-show-if-animated-placeholder">
                                            <th scope="row"><?php esc_html_e( 'Clicking on placeholder texts', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[placeholder_clicking]">
                                                    <option value="empty" <?php selected( $placeholder_clicking, 'empty' ); ?>><?php esc_html_e( 'Start with empty search box', 'wpc-ajax-search' ); ?></option>
                                                    <option value="keep" <?php selected( $placeholder_clicking, 'keep' ); ?>><?php esc_html_e( 'Keep the placeholder texts', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Menu(s)', 'wpc-ajax-search' ); ?></th>
                                            <td>
												<?php
												$nav_args    = [
													'hide_empty' => false,
													'fields'     => 'id=>name',
												];
												$nav_menus   = get_terms( 'nav_menu', $nav_args );
												$saved_menus = (array) self::get_setting( 'menus', [] );

												foreach ( $nav_menus as $nav_id => $nav_name ) {
													echo '<input type="checkbox" name="wpcas_settings[menus][]" value="' . $nav_id . '" ' . ( in_array( $nav_id, $saved_menus ) ? 'checked' : '' ) . '/><label>' . $nav_name . '</label><br/>';
												}
												?>
                                                <span class="description"><?php esc_html_e( 'Choose the menu(s) you want to add the "search menu" at the end.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="heading">
                                            <th colspan="2">
												<?php esc_html_e( 'Search', 'wpc-ajax-search' ); ?>
                                            </th>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Search by category', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[search_category]">
                                                    <option value="yes" <?php selected( $search_category, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $search_category, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Search by SKU', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[search_sku]">
                                                    <option value="yes" <?php selected( $search_sku, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $search_sku, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Search limit', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="number" min="1" max="500" name="wpcas_settings[search_limit]" value="<?php echo esc_attr( self::get_setting( 'search_limit', 10 ) ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'More results', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[more_results]">
                                                    <option value="yes" <?php selected( $more_results, 'yes' ); ?>><?php esc_html_e( 'Yes', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $more_results, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                                <span class="description"><?php esc_html_e( 'Show "more results" and link it to the search page when having more results than the limitation.', 'wpc-ajax-search' ); ?></span>
                                            </td>
                                        </tr>
                                        <tr class="heading">
                                            <th colspan="2">
												<?php esc_html_e( 'Cache', 'wpc-ajax-search' ); ?>
                                            </th>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Cache method', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <select name="wpcas_settings[cache_method]">
                                                    <option value="file" <?php selected( $cache_method, 'file' ); ?>><?php esc_html_e( 'File', 'wpc-ajax-search' ); ?></option>
                                                    <option value="database" <?php selected( $cache_method, 'database' ); ?>><?php esc_html_e( 'Database', 'wpc-ajax-search' ); ?></option>
                                                    <option value="no" <?php selected( $cache_method, 'no' ); ?>><?php esc_html_e( 'No', 'wpc-ajax-search' ); ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Cache time (hrs)', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input name="wpcas_settings[cache_time]" type="number" min="0" max="8760" value="<?php echo esc_attr( self::get_setting( 'cache_time', 24 ) ); ?>"/>
												<?php if ( isset( $_GET['act'] ) && ( $_GET['act'] === 'clear_cache' ) ) {
													global $wpdb;

													// clear database cache
													$wpdb->query( 'DELETE FROM `' . $wpdb->prefix . 'options` WHERE `option_name` LIKE "%transient_wpcas%" OR `option_name` LIKE "%transient_timeout_wpcas%"' );

													// clear file cache
													$upload_dir = wp_upload_dir( null, false );
													wpcas_delete_folder( $upload_dir['basedir'] . '/wpc-ajax-search' );

													esc_html_e( 'Cleared!', 'wpc-ajax-search' );
												} else { ?>
                                                    <a class="button" id="clear_cache" href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-wpcas&act=clear_cache' ) ); ?>"><?php esc_html_e( 'Clear cache', 'wpc-ajax-search' ); ?></a>
												<?php } ?>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'wpcas_settings' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
							<?php } elseif ( $active_tab === 'localization' ) { ?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr class="heading">
                                            <th scope="row"><?php esc_html_e( 'General', 'wpc-ajax-search' ); ?></th>
                                            <td>
												<?php esc_html_e( 'Leave blank to use the default text and its equivalent translation in multiple languages.', 'wpc-ajax-search' ); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Menu label', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[menu]" value="<?php echo esc_attr( self::localization( 'menu' ) ); ?>" placeholder="<?php esc_attr_e( 'Search', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Heading', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[heading]" value="<?php echo esc_attr( self::localization( 'heading' ) ); ?>" placeholder="<?php esc_attr_e( 'Search', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Placeholder', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[placeholder]" value="<?php echo esc_attr( self::localization( 'placeholder' ) ); ?>" placeholder="<?php esc_attr_e( 'Search products…', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Popular keywords:', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[popular_keywords]" value="<?php echo esc_attr( self::localization( 'popular_keywords' ) ); ?>" placeholder="<?php esc_attr_e( 'Popular keywords:', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'No results', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[no_results]" value="<?php echo esc_attr( self::localization( 'no_results' ) ); ?>" placeholder="<?php /* translators: keyword */
												esc_attr_e( 'No results found for "%s".', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'No results in category', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[no_results_category]" value="<?php echo esc_attr( self::localization( 'no_results_category' ) ); ?>" placeholder="<?php /* translators: keyword and category */
												esc_attr_e( 'No results found for "%1$s" in "%2$s".', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'More results', 'wpc-ajax-search' ); ?></th>
                                            <td>
                                                <input type="text" class="regular-text" name="wpcas_localization[more_results]" value="<?php echo esc_attr( self::localization( 'more_results' ) ); ?>" placeholder="<?php /* translators: count */
												esc_attr_e( 'More results (%d)', 'wpc-ajax-search' ); ?>"/>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'wpcas_localization' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
							<?php } else if ( $active_tab === 'smart' ) { ?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr>
                                            <td>
                                                <p style="color: #c9356e">This feature is only available on the Premium Version. Click
                                                    <a href="https://wpclever.net/downloads/wpc-ajax-search?utm_source=pro&utm_medium=wpcas&utm_campaign=wporg" target="_blank">here</a> to buy, just $29.
                                                </p>
                                                <p>
													<?php esc_html_e( 'This plugin will check the conditions from the top down the list to find if the input keyword matches any condition. When one condition is satisfied, the smart search’ checking process will stop and show the defined products as search results. The checking process won’t stop until a satisfied condition is found in the list. If no conditions are met by the input keyword(s), normal search results will be shown instead.', 'wpc-ajax-search' ); ?>
                                                </p>
                                                <div class="wpcas-dialog" id="wpcas_shortcodes_dialog" style="display: none" title="<?php esc_html_e( 'Build-in Shortcodes', 'wpc-ajax-search' ); ?>">
                                                    You can use shortcode(s) within the text, e.g:
                                                    <code>ABC [your_shortcode] XYZ</code>
                                                    <br/><br/>Try below build-in shortcodes:
                                                    <ul>
                                                        <li>
                                                            <code>[wpcas_categories]</code><br/>Display product categories list.
                                                        </li>
                                                        <li>
                                                            <code>[wpcas_products]</code><br/>Display products list, e.g:
                                                            <code>[wpcas_products type="search" s="hat" limit="10"]</code>
                                                        </li>
                                                        <li><code>[wpcas_posts]</code><br/>Display posts list, e.g:
                                                            <code>[wpcas_posts type="recent" limit="5"]</code></li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="wpcas_rules">
													<?php
													if ( is_array( self::$rules ) && ( count( self::$rules ) > 0 ) ) {
														foreach ( self::$rules as $rule_key => $rule ) {
															self::rule( $rule_key, $rule, false );
														}
													} else {
														self::rule( '', [], true );
													}
													?>
                                                </div>
                                                <div class="wpcas_add_rule">
                                                    <div>
                                                        <a href="#" class="wpcas_new_rule button">
															<?php esc_html_e( '+ Add rule', 'wpc-ajax-search' ); ?>
                                                        </a> <a href="#" class="wpcas_expand_all">
															<?php esc_html_e( 'Expand All', 'wpc-ajax-search' ); ?>
                                                        </a> <a href="#" class="wpcas_collapse_all">
															<?php esc_html_e( 'Collapse All', 'wpc-ajax-search' ); ?>
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'wpcas_rules' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
							<?php } else if ( $active_tab === 'premium' ) {
								?>
                                <div class="wpclever_settings_page_content_text">
                                    <p>Get the Premium Version just $29!
                                        <a href="https://wpclever.net/downloads/wpc-ajax-search?utm_source=pro&utm_medium=wpcas&utm_campaign=wporg" target="_blank">https://wpclever.net/downloads/wpc-ajax-search</a>
                                    </p>
                                    <p><strong>Extra features for Premium Version:</strong></p>
                                    <ul style="margin-bottom: 0">
                                        <li>- Enable Smart Search feature.</li>
                                        <li>- Get the lifetime update & premium support.</li>
                                    </ul>
                                </div>
								<?php
							} ?>
                        </div><!-- /.wpclever_settings_page_content -->
                        <div class="wpclever_settings_page_suggestion">
                            <div class="wpclever_settings_page_suggestion_label">
                                <span class="dashicons dashicons-yes-alt"></span> Suggestion
                            </div>
                            <div class="wpclever_settings_page_suggestion_content">
                                <div>
                                    To display custom engaging real-time messages on any wished positions, please install
                                    <a href="https://wordpress.org/plugins/wpc-smart-messages/" target="_blank">WPC Smart Messages</a> plugin. It's free!
                                </div>
                                <div>
                                    Wanna save your precious time working on variations? Try our brand-new free plugin
                                    <a href="https://wordpress.org/plugins/wpc-variation-bulk-editor/" target="_blank">WPC Variation Bulk Editor</a> and
                                    <a href="https://wordpress.org/plugins/wpc-variation-duplicator/" target="_blank">WPC Variation Duplicator</a>.
                                </div>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function ajax_search() {
					if ( ! apply_filters( 'wpcas_disable_security_check', false, 'search' ) ) {
						if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wpcas-security' ) ) {
							die( '<div class="wpcas-error">Permissions check failed.</div>' );
						}
					}

					$keyword      = sanitize_text_field( $_POST['keyword'] );
					$category     = absint( sanitize_text_field( $_POST['category'] ) );
					$on_sale      = self::get_setting( 'keyword_on_sale', '' );
					$recent       = self::get_setting( 'keyword_recent', '' );
					$featured     = self::get_setting( 'keyword_featured', '' );
					$popular      = self::get_setting( 'keyword_popular', '' );
					$cache_method = self::get_setting( 'cache_method', 'file' );
					$cache_time   = absint( self::get_setting( 'cache_time', 24 ) );
					$log_key      = 'wpcas_' . $category . '_' . sanitize_title( $keyword );
					$products     = [];

					do_action( 'wpcas_before_search', $keyword, $category );

					if ( $cache_method === 'file' ) {
						// file cache
						$upload_dir = wp_upload_dir( null, false );
						$cache_dir  = $upload_dir['basedir'] . '/wpc-ajax-search';
						$cache_life = $cache_time * 60 * 60; // in seconds

						if ( ! is_dir( $cache_dir ) ) {
							wp_mkdir_p( $cache_dir );
						}

						$cache_file = $cache_dir . '/' . $log_key . '.log';

						if ( file_exists( $cache_file ) ) {
							// file cache
							$file_time = @filemtime( $cache_file );

							if ( ( time() - $file_time < $cache_life ) ) {
								$return = file_get_contents( $cache_file );
								echo apply_filters( 'wpcas_search_result', $return, $keyword, $category );

								wp_die();
							} else {
								// delete this file & write later
								unlink( $cache_file );
							}
						}
					}

					if ( ( $cache_method === 'database' ) && ( $return = get_transient( $log_key ) ) ) {
						// database cache
						echo apply_filters( 'wpcas_search_result', $return, $keyword, $category );

						wp_die();
					}

					ob_start();

					do_action( 'wpcas_before_search_result', $keyword, $category );

					$more_results = 0;

					if ( ! $products ) {
						$limit = absint( self::get_setting( 'search_limit', 10 ) );

						$args = [
							'is_wpcas'       => true,
							'post_type'      => 'product',
							'post_status'    => 'publish',
							'fields'         => 'ids',
							'posts_per_page' => $limit
						];

						if ( $category ) {
							$args['tax_query'] = [
								[
									'taxonomy'         => 'product_cat',
									'terms'            => $category,
									'field'            => 'ID',
									'include_children' => true,
									'operator'         => 'IN'
								]
							];
						}

						if ( ! empty( $on_sale ) && $keyword === $on_sale ) {
							$args['post__in'] = wc_get_product_ids_on_sale();
						} elseif ( ! empty( $recent ) && $keyword === $recent ) {
							$args['orderby'] = 'date';
						} elseif ( ! empty( $featured ) && $keyword === $featured ) {
							$args['post__in'] = wc_get_featured_product_ids();
						} elseif ( ! empty( $popular ) && $keyword === $popular ) {
							$args['meta_key'] = 'total_sales';
							$args['orderby']  = 'meta_value_num';
						} else {
							$args['s'] = $keyword;
						}

						$query        = new WP_Query( apply_filters( 'wpcas_search_query_args', $args ) );
						$products     = $query->posts;
						$more_results = ( self::get_setting( 'more_results', 'yes' ) === 'yes' ) && ( $query->found_posts > $limit ) ? $query->found_posts : 0;
					}

					if ( $products ) {
						self::show_products( $products, $keyword, $category, $more_results );
					} else {
						do_action( 'wpcas_before_not_found', $keyword, $category );

						if ( ! empty( $category ) && ( $cat = get_term_by( 'id', absint( $category ), 'product_cat' ) ) ) {
							echo '<div class="wpcas-not-found"><span>' . sprintf( self::localization( 'no_results_category', /* translators: keyword and category */ esc_html__( 'No results found for "%1$s" in "%2$s".', 'wpc-ajax-search' ) ), $keyword, esc_html( $cat->name ) ) . '</span></div>';
						} else {
							echo '<div class="wpcas-not-found"><span>' . sprintf( self::localization( 'no_results', /* translators: keyword */ esc_html__( 'No results found for "%s".', 'wpc-ajax-search' ) ), $keyword ) . '</span></div>';
						}

						do_action( 'wpcas_after_not_found', $keyword, $category );
					}

					end:

					do_action( 'wpcas_after_search_result', $keyword, $category );

					$return = ob_get_clean();

					if ( ( $cache_method === 'file' ) && ! file_exists( $cache_file ) ) {
						// file cache
						$open  = @fopen( $cache_file, "a" );
						$write = @fputs( $open, $return );
						@fclose( $open );
					}

					if ( ( $cache_method === 'database' ) && ! get_transient( $log_key ) ) {
						// database cache
						set_transient( $log_key, $return, $cache_time * HOUR_IN_SECONDS );
					}

					do_action( 'wpcas_after_search', $keyword, $category );

					echo apply_filters( 'wpcas_search_result', $return, $keyword, $category );

					wp_die();
				}

				function show_products( $products, $keyword = '', $category = 0, $more_results = 0 ) {
					$link = self::get_setting( 'link', 'yes' );

					echo '<div class="wpcas-products">';

					do_action( 'wpcas_before_products', $keyword, $category );

					foreach ( $products as $_product_id ) {
						$_product = wc_get_product( $_product_id );

						if ( ! $_product ) {
							continue;
						}

						if ( ( self::get_setting( 'exclude_unpurchasable', 'no' ) === 'yes' ) && ( ! $_product->is_in_stock() || ! $_product->is_purchasable() ) ) {
							continue;
						}

						if ( ( self::get_setting( 'exclude_hidden', 'no' ) === 'yes' ) && ( $_product->get_catalog_visibility() !== 'visible' ) && ( $_product->get_catalog_visibility() !== 'search' ) ) {
							continue;
						}

						if ( apply_filters( 'wpcas_exclude_product', false, $_product ) ) {
							continue;
						}

						echo '<div class="wpcas-product">';
						echo '<div class="wpcas-product-inner">';
						do_action( 'wpcas_before_product_inner', $_product );

						echo '<div class="wpcas-product-thumb">';
						do_action( 'wpcas_before_product_thumbnail', $_product );

						if ( $link !== 'no' ) {
							echo sprintf( '<a ' . ( $link === 'yes_popup' ? 'class="woosq-btn" data-id="' . $_product_id . '"' : '' ) . ' href="%s" ' . ( $link === 'yes_blank' ? 'target="_blank"' : '' ) . '>%s</a>', $_product->get_permalink(), $_product->get_image() );
						} else {
							echo $_product->get_image();
						}

						do_action( 'wpcas_after_product_thumbnail', $_product );
						echo '</div><!-- /wpcas-product-thumb -->';

						echo '<div class="wpcas-product-info">';
						do_action( 'wpcas_before_product_info', $_product );

						echo '<div class="wpcas-product-name">';
						do_action( 'wpcas_before_product_name', $_product );

						if ( $link !== 'no' ) {
							echo sprintf( '<a ' . ( $link === 'yes_popup' ? 'class="woosq-btn" data-id="' . $_product_id . '"' : '' ) . ' href="%s" ' . ( $link === 'yes_blank' ? 'target="_blank"' : '' ) . '>%s</a>', $_product->get_permalink(), $_product->get_name() );
						} else {
							echo $_product->get_name();
						}

						do_action( 'wpcas_after_product_name', $_product );
						echo '</div><!-- /wpcas-product-name -->';

						echo '<div class="wpcas-product-price">' . $_product->get_price_html() . '</div>';

						do_action( 'wpcas_after_product_info', $_product );
						echo '</div><!-- /wpcas-product-info -->';

						echo '<div class="wpcas-product-actions">';
						do_action( 'wpcas_product_actions', $_product );
						echo '</div><!-- /wpcas-product-actions -->';

						do_action( 'wpcas_after_product_inner', $_product );
						echo '</div><!-- /wpcas-product-inner -->';
						echo '</div><!-- /wpcas-product -->';
					}

					do_action( 'wpcas_after_products', $keyword, $category );

					if ( $more_results ) {
						$more_results_txt = self::localization( 'more_results', /* translators: count */ esc_html__( 'More results (%d)', 'wpc-ajax-search' ) );

						echo apply_filters( 'wpcas_more_results', '<div class="wpcas-more-results"><div class="wpcas-more-results-inner"><a href="' . esc_url( home_url( '/?post_type=product&s=' . $keyword ) ) . '">' . sprintf( $more_results_txt, $more_results ) . '</a></div></div>', $products, $keyword, $category, $more_results );
					}

					echo '</div>';
				}

				function search_sku( $query ) {
					if ( $query->is_search && isset( $query->query['is_wpcas'] ) ) {
						global $wpdb;

						$sku = $query->query['s'];
						$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value = %s;", $sku ) );

						if ( ! $ids ) {
							return;
						}

						unset( $query->query['s'], $query->query_vars['s'] );
						$query->query['post__in'] = [];

						foreach ( $ids as $id ) {
							$post = get_post( $id );

							if ( $post->post_type === 'product_variation' ) {
								$query->query['post__in'][]      = $post->post_parent;
								$query->query_vars['post__in'][] = $post->post_parent;
							} else {
								$query->query_vars['post__in'][] = $post->ID;
							}
						}
					}
				}

				function get_search_area() {
					$heading          = self::localization( 'heading', esc_html__( 'Search', 'wpc-ajax-search' ) );
					$popular          = apply_filters( 'wpcas_popular_keywords', trim( self::get_setting( 'popular_keywords' ) ) );
					$keywords         = explode( ',', $popular );
					$popular_keywords = [];

					if ( ! empty( $keywords ) ) {
						foreach ( $keywords as $keyword ) {
							if ( ! empty( trim( $keyword ) ) ) {
								$popular_keywords[] = '<a href="#wpcas">' . esc_html( trim( $keyword ) ) . '</a>';
							}
						}
					}

					if ( empty( $heading ) ) {
						$heading = esc_html__( 'Search', 'wpc-ajax-search' );
					}
					?>
                    <div id="wpcas-area" class="wpcas-area wpcas-position-<?php echo esc_attr( self::get_setting( 'position', '01' ) ); ?> wpcas-slide-<?php echo esc_attr( self::get_setting( 'effect', 'yes' ) ); ?>">
                        <div class="wpcas-area-top">
                            <span><?php echo esc_html( $heading ); ?></span>

							<?php if ( self::get_setting( 'close', 'yes' ) === 'yes' ) {
								echo '<div class="wpcas-close">&times;</div>';
							} ?>
                        </div>

                        <div class="wpcas-area-mid wpcas-search">
                            <div class="wpcas-search-input">
                                <div class="wpcas-search-input-inner">
									<?php
									echo '<span class="wpcas-search-input-icon"></span>';
									echo apply_filters( 'wpcas_search_input', '<input name="wpcas-search-input-value" id="wpcas_search_keyword" type="search" placeholder="' . esc_attr( self::localization( 'placeholder', esc_html__( 'Search products…', 'wpc-ajax-search' ) ) ) . '"/>' );

									if ( self::get_setting( 'search_category', 'yes' ) === 'yes' ) {
										$category_args = apply_filters( 'wpcas_search_category_args', [
											'name'             => 'wpcas-search-cats',
											'id'               => 'wpcas_search_cats',
											'hide_empty'       => 0,
											'value_field'      => 'id',
											'show_option_all'  => esc_html__( 'All categories', 'wpc-ajax-search' ),
											'show_option_none' => '',
										] );

										wc_product_dropdown_categories( $category_args );
									}
									?>
                                </div>
                            </div>

							<?php if ( ! empty( $popular_keywords ) ) { ?>
                                <div class="wpcas-popular-keywords">
                                    <div class="wpcas-popular-keywords-inner">
                                        <span class="wpcas-popular-keywords-label"><?php echo self::localization( 'popular_keywords', esc_html__( 'Popular keywords:', 'wpc-ajax-search' ) ); ?></span>
										<?php echo implode( ', ', $popular_keywords ); ?>
                                    </div>
                                </div>
							<?php } ?>

                            <div class="wpcas-search-result">
								<?php do_action( 'wpcas_placeholder_result' ); ?>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function footer() {
					if ( is_admin() ) {
						return;
					}

					self::get_search_area();

					if ( self::get_setting( 'overlay_layer', 'yes' ) === 'yes' ) {
						echo '<div class="wpcas-overlay"></div>';
					}
				}

				function nav_menu_items( $items, $args ) {
					$selected    = false;
					$saved_menus = (array) self::get_setting( 'menus', [] );

					if ( empty( $saved_menus ) || ! property_exists( $args, 'menu' ) ) {
						return $items;
					}

					if ( $args->menu instanceof WP_Term ) {
						// menu object
						if ( in_array( $args->menu->term_id, $saved_menus ) ) {
							$selected = true;
						}
					} elseif ( is_numeric( $args->menu ) ) {
						// menu id
						if ( in_array( $args->menu, $saved_menus ) ) {
							$selected = true;
						}
					} elseif ( is_string( $args->menu ) ) {
						// menu slug or name
						$menu = get_term_by( 'name', $args->menu, 'nav_menu' );

						if ( ! $menu ) {
							$menu = get_term_by( 'slug', $args->menu, 'nav_menu' );
						}

						if ( $menu && in_array( $menu->term_id, $saved_menus ) ) {
							$selected = true;
						}
					}

					if ( $selected ) {
						$items .= apply_filters( 'wpcas_menu_item', '<li class="menu-item wpcas-menu-item menu-item-type-wpcas"><a href="#">' . self::localization( 'menu', esc_html__( 'Search', 'wpc-ajax-search' ) ) . '</a></li>' );
					}

					return $items;
				}

				function item_compare( $product ) {
					if ( $product && class_exists( 'WPCleverWoosc' ) ) {
						echo do_shortcode( '[woosc id="' . $product->get_id() . '"]' );
					}
				}

				function item_wishlist( $product ) {
					if ( $product && class_exists( 'WPCleverWoosw' ) ) {
						echo do_shortcode( '[woosw id="' . $product->get_id() . '"]' );
					}
				}

				function item_add_to_cart( $product ) {
					if ( $product ) {
						echo '<div class="atc-btn">' . do_shortcode( '[add_to_cart style="" show_price="false" id="' . $product->get_id() . '"]' ) . '</div>';
					}
				}


				function rule( $rule_key = '', $rule = [], $active = false ) {
					if ( empty( $rule_key ) ) {
						$rule_key = self::generate_key();
					}

					$name = $rule['name'] ?? '';
					?>
                    <div class="wpcas_rule <?php echo esc_attr( $active ? 'active' : '' ); ?>" data-key="<?php echo esc_attr( $rule_key ); ?>">
                        <div class="wpcas_rule_heading">
                            <span class="wpcas_rule_move"></span>
                            <span class="wpcas_rule_label"><span class="wpcas_rule_name"><?php echo esc_html( $name ); ?></span> <span class="wpcas_rule_returned"></span></span>
                            <a href="#" class="wpcas_rule_remove"><?php esc_html_e( 'remove', 'wpc-ajax-search' ); ?></a>
                        </div>
                        <div class="wpcas_rule_content">
                            <div class="wpcas_tr">
                                <div class="wpcas_th">
									<?php esc_html_e( 'Name', 'wpc-ajax-search' ); ?>
                                </div>
                                <div class="wpcas_td">
                                    <input type="text" class="regular-text wpcas_rule_name_val" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][name]" value="<?php echo esc_attr( $name ); ?>"/>
                                    <span class="description"><?php esc_html_e( 'For management only.', 'wpc-ajax-search' ); ?></span>
                                </div>
                            </div>
							<?php self::conditions( $rule_key, $rule ); ?>
							<?php self::returned( $rule_key, $rule ); ?>
                        </div>
                    </div>
					<?php
				}

				function conditions( $rule_key = '', $rule = [] ) {
					$conditions = (array) ( $rule['conditions'] ?? [] );
					?>
                    <div class="wpcas_tr">
                        <div class="wpcas_th"><?php esc_html_e( 'Keyword conditions', 'wpc-ajax-search' ); ?></div>
                        <div class="wpcas_td wpcas_rule_td"><?php esc_html_e( 'Describe the applicable keywords.', 'wpc-ajax-search' ); ?></div>
                    </div>
                    <div class="wpcas_tr">
                        <div class="wpcas_th"></div>
                        <div class="wpcas_td wpcas_rule_td">
                            <div class="wpcas_conditions">
								<?php
								if ( ! empty( $conditions ) ) {
									foreach ( $conditions as $condition_key => $condition ) {
										self::condition( $condition_key, $condition, $rule_key );
									}
								} else {
									self::condition( '', [ 'compare' => 'include_either' ], $rule_key );
								}
								?>
                            </div>
                            <div class="wpcas_add_condition">
                                <a class="wpcas_new_condition" href="#"><?php esc_attr_e( '+ Add condition', 'wpc-ajax-search' ); ?></a>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function condition( $condition_key = '', $condition = [], $rule_key = '' ) {
					if ( empty( $condition_key ) ) {
						$condition_key = self::generate_key();
					}

					$condition = array_merge( [
						'compare' => '',
						'number'  => '',
						'text'    => [],
						'pattern' => ''
					], $condition );
					?>
                    <div class="wpcas_condition">
						<span class="wpcas_condition_compare">
							<select name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][conditions][<?php echo esc_attr( $condition_key ); ?>][compare]" class="wpcas_condition_compare_selector">
								<option value="characters_more" <?php selected( $condition['compare'], 'characters_more' ); ?> data-val="number"><?php esc_html_e( 'Input characters are more than', 'wpc-ajax-search' ); ?></option>
								<option value="characters_fewer" <?php selected( $condition['compare'], 'characters_fewer' ); ?> data-val="number"><?php esc_html_e( 'Input characters are fewer than', 'wpc-ajax-search' ); ?></option>
								<option value="words_more" <?php selected( $condition['compare'], 'words_more' ); ?> data-val="number"><?php esc_html_e( 'Input words are more than', 'wpc-ajax-search' ); ?></option>
								<option value="words_fewer" <?php selected( $condition['compare'], 'words_fewer' ); ?> data-val="number"><?php esc_html_e( 'Input words are fewer than', 'wpc-ajax-search' ); ?></option>
								<option value="include_either" <?php selected( $condition['compare'], 'include_either' ); ?> data-val="text"><?php esc_html_e( 'Include either keyword', 'wpc-ajax-search' ); ?></option>
								<option value="include_all" <?php selected( $condition['compare'], 'include_all' ); ?> data-val="text"><?php esc_html_e( 'Include all keyword(s)', 'wpc-ajax-search' ); ?></option>
								<option value="exclude" <?php selected( $condition['compare'], 'exclude' ); ?> data-val="text"><?php esc_html_e( 'Exclude keyword(s)', 'wpc-ajax-search' ); ?></option>
								<option value="regex" <?php selected( $condition['compare'], 'regex' ); ?> data-val="regex"><?php esc_html_e( 'Match regular expression (RegEx)', 'wpc-ajax-search' ); ?></option>
							</select>
						</span> <span class="wpcas_condition_number">
							<input type="number" step="1" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][conditions][<?php echo esc_attr( $condition_key ); ?>][number]" value="<?php echo esc_attr( $condition['number'] ); ?>"/>
						</span> <span class="wpcas_condition_text">
							<select name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][conditions][<?php echo esc_attr( $condition_key ); ?>][text][]" class="wpcas_condition_text_val" multiple>
								<?php
								if ( is_array( $condition['text'] ) && ! empty( $condition['text'] ) ) {
									foreach ( $condition['text'] as $t ) {
										echo '<option value="' . esc_attr( $t ) . '" selected>' . esc_html( $t ) . '</option>';
									}
								}
								?>
							</select>
						</span>
                        <span class="wpcas_condition_regex"><input type="text" class="text" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][conditions][<?php echo esc_attr( $condition_key ); ?>][pattern]" value="<?php echo esc_attr( $condition['pattern'] ); ?>"/></span>
                        <span class="wpcas_condition_remove">&times;</span>
                    </div>
					<?php
				}

				function returned( $rule_key = '', $rule = [] ) {
					$returned = $rule['returned'] ?? 'products';
					$message  = $rule['message'] ?? '';
					$get      = $rule['get'] ?? 'all';
					$products = (array) ( $rule['products'] ?? [] );
					$terms    = (array) ( $rule['terms'] ?? [] );
					$combined = (array) ( $rule['combined'] ?? [] );
					$limit    = absint( $rule['limit'] ?? 10 );
					$orderby  = $rule['orderby'] ?? 'default';
					$order    = $rule['order'] ?? 'default';
					?>
                    <div class="wpcas_tr">
                        <div class="wpcas_th"><?php esc_html_e( 'Returned results', 'wpc-ajax-search' ); ?></div>
                        <div class="wpcas_td"><?php esc_html_e( 'Define products or message shown in the search results for above keyword(s).', 'wpc-ajax-search' ); ?></div>
                    </div>
                    <div class="wpcas_tr">
                        <div class="wpcas_th"><?php esc_html_e( 'Output types', 'wpc-ajax-search' ); ?></div>
                        <div class="wpcas_td wpcas_rule_td">
                            <select name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][returned]" class="wpcas_returned_selector">
                                <option value="products" <?php selected( $returned, 'products' ); ?>><?php esc_html_e( 'Products', 'wpc-ajax-search' ); ?></option>
                                <option value="message" <?php selected( $returned, 'message' ); ?>><?php esc_html_e( 'Custom message', 'wpc-ajax-search' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="hide_returned show_if_returned_message">
                        <div class="wpcas_tr">
                            <div class="wpcas_th"><?php esc_html_e( 'Message', 'wpc-ajax-search' ); ?></div>
                            <div class="wpcas_td wpcas_rule_td">
								<?php
								if ( empty( $rule ) ) {
									// new
									echo '<textarea id="wpcas_message_' . $rule_key . '" name="wpcas_rules[' . $rule_key . '][message]" rows="10" class="wpcas_editor"></textarea>';
								} else {
									wp_editor( $message, 'wpcas_message_' . $rule_key, [
										'textarea_name' => 'wpcas_rules[' . $rule_key . '][message]',
										'textarea_rows' => 10
									] );
								}
								?>
                                <p class="description"><?php esc_html_e( 'You can use shortcode(s) in this custom message.', 'wpc-ajax-search' ); ?>
                                    <a href="#" class="wpcas_shortcodes_btn">build-in shortcodes</a></p>
                            </div>
                        </div>
                    </div>
                    <div class="hide_returned show_if_returned_products">
                        <div class="wpcas_tr">
                            <div class="wpcas_th"><?php esc_html_e( 'Source', 'wpc-ajax-search' ); ?></div>
                            <div class="wpcas_td wpcas_rule_td">
                                <select class="wpcas_source_selector" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][get]">
                                    <option value="all" <?php selected( $get, 'all' ); ?>><?php esc_html_e( 'All products', 'wpc-ajax-search' ); ?></option>
                                    <option value="products" <?php selected( $get, 'products' ); ?>><?php esc_html_e( 'Selected products', 'wpc-ajax-search' ); ?></option>
                                    <option value="combined" <?php selected( $get, 'combined' ); ?>><?php esc_html_e( 'Combined', 'wpc-ajax-search' ); ?></option>
									<?php
									$taxonomies = get_object_taxonomies( 'product', 'objects' );

									foreach ( $taxonomies as $taxonomy ) {
										echo '<option value="' . esc_attr( $taxonomy->name ) . '" ' . ( $get === $taxonomy->name ? 'selected' : '' ) . '>' . esc_html( $taxonomy->label ) . '</option>';
									}
									?>
                                </select> <span class="show_get hide_if_products">
										<span><?php esc_html_e( 'Limit', 'wpc-ajax-search' ); ?> <input type="number" min="1" max="50" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][limit]" value="<?php echo esc_attr( $limit ); ?>"/></span>
										<span>
										<?php esc_html_e( 'Order by', 'wpc-ajax-search' ); ?> <select name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][orderby]">
                                                        <option value="default" <?php selected( $orderby, 'default' ); ?>><?php esc_html_e( 'Default', 'wpc-ajax-search' ); ?></option>
                                                        <option value="none" <?php selected( $orderby, 'none' ); ?>><?php esc_html_e( 'None', 'wpc-ajax-search' ); ?></option>
                                                        <option value="ID" <?php selected( $orderby, 'ID' ); ?>><?php esc_html_e( 'ID', 'wpc-ajax-search' ); ?></option>
                                                        <option value="name" <?php selected( $orderby, 'name' ); ?>><?php esc_html_e( 'Name', 'wpc-ajax-search' ); ?></option>
                                                        <option value="type" <?php selected( $orderby, 'type' ); ?>><?php esc_html_e( 'Type', 'wpc-ajax-search' ); ?></option>
                                                        <option value="rand" <?php selected( $orderby, 'rand' ); ?>><?php esc_html_e( 'Rand', 'wpc-ajax-search' ); ?></option>
                                                        <option value="date" <?php selected( $orderby, 'date' ); ?>><?php esc_html_e( 'Date', 'wpc-ajax-search' ); ?></option>
                                                        <option value="price" <?php selected( $orderby, 'price' ); ?>><?php esc_html_e( 'Price', 'wpc-ajax-search' ); ?></option>
                                                        <option value="modified" <?php selected( $orderby, 'modified' ); ?>><?php esc_html_e( 'Modified', 'wpc-ajax-search' ); ?></option>
                                                    </select>
									</span>
										<span><?php esc_html_e( 'Order', 'wpc-ajax-search' ); ?> <select name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][order]">
                                                        <option value="default" <?php selected( $order, 'default' ); ?>><?php esc_html_e( 'Default', 'wpc-ajax-search' ); ?></option>
                                                        <option value="DESC" <?php selected( $order, 'DESC' ); ?>><?php esc_html_e( 'DESC', 'wpc-ajax-search' ); ?></option>
                                                        <option value="ASC" <?php selected( $order, 'ASC' ); ?>><?php esc_html_e( 'ASC', 'wpc-ajax-search' ); ?></option>
                                                        </select></span>
									</span>
                            </div>
                        </div>
                        <div class="wpcas_tr hide_get show_if_products">
                            <div class="wpcas_th"><?php esc_html_e( 'Products', 'wpc-ajax-search' ); ?></div>
                            <div class="wpcas_td wpcas_rule_td">
                                <select class="wc-product-search wpcas-product-search" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][products][]" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'wpc-ajax-search' ); ?>" data-action="woocommerce_json_search_products_and_variations">
									<?php
									if ( ! empty( $products ) ) {
										foreach ( $products as $_product_id ) {
											if ( $_product = wc_get_product( $_product_id ) ) {
												echo '<option value="' . esc_attr( $_product_id ) . '" selected>' . wp_kses_post( $_product->get_formatted_name() ) . '</option>';
											}
										}
									}
									?>
                                </select>
                            </div>
                        </div>
                        <div class="wpcas_tr hide_get show_if_combined">
                            <div class="wpcas_th"><?php esc_html_e( 'Combined', 'wpc-ajax-search' ); ?></div>
                            <div class="wpcas_td wpcas_rule_td">
                                <div class="wpcas_combination">
                                    <p class="description"><?php esc_html_e( '* Configure to find products that match all listed conditions.', 'wpc-ajax-search' ); ?></p>
									<?php
									if ( ! empty( $combined ) ) {
										foreach ( $combined as $cmb_key => $cmb ) {
											self::combined( $cmb_key, $cmb, $rule_key );
										}
									}
									?>
                                </div>
                                <div class="wpcas_add_combined">
                                    <a class="wpcas_new_combined" href="#"><?php esc_attr_e( '+ Add condition', 'wpc-ajax-search' ); ?></a>
                                </div>
                            </div>
                        </div>
                        <div class="wpcas_tr show_get hide_if_all hide_if_products hide_if_combined">
                            <div class="wpcas_th wpcas_get_text"><?php esc_html_e( 'Terms', 'wpc-ajax-search' ); ?></div>
                            <div class="wpcas_td wpcas_rule_td">
                                <select class="wpcas_terms" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][terms][]" multiple="multiple" data-<?php echo esc_attr( $get ); ?>="<?php echo esc_attr( implode( ',', $terms ) ); ?>">
									<?php
									if ( ! empty( $terms ) ) {
										foreach ( $terms as $at ) {
											if ( $term = get_term_by( 'slug', $at, $get ) ) {
												echo '<option value="' . esc_attr( $at ) . '" selected>' . esc_html( $term->name ) . '</option>';
											}
										}
									}
									?>
                                </select>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function combined( $combined_key = '', $combined = [], $rule_key = '' ) {
					if ( empty( $combined_key ) ) {
						$combined_key = self::generate_key();
					}

					$apply          = $combined['apply'] ?? '';
					$compare        = $combined['compare'] ?? 'is';
					$terms          = (array) ( $combined['terms'] ?? [] );
					$number_compare = $combined['number_compare'] ?? 'equal';
					$number_value   = $combined['number_value'] ?? '0';
					$taxonomies     = get_object_taxonomies( 'product', 'objects' );
					?>
                    <div class="wpcas_combined">
                        <span class="wpcas_combined_remove">&times;</span> <span class="wpcas_combined_selector_wrap">
                                    <select class="wpcas_combined_selector" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][combined][<?php echo esc_attr( $combined_key ); ?>][apply]">
	                                    <?php
	                                    echo '<option value="price" ' . selected( $apply, 'price', false ) . '>' . esc_html__( 'Price', 'wpc-ajax-search' ) . '</option>';

	                                    foreach ( $taxonomies as $taxonomy ) {
		                                    echo '<option value="' . esc_attr( $taxonomy->name ) . '" ' . ( $apply === $taxonomy->name ? 'selected' : '' ) . '>' . esc_html( $taxonomy->label ) . '</option>';
	                                    }
	                                    ?>
                                    </select>
                                </span> <span class="wpcas_combined_compare_wrap">
							<select class="wpcas_combined_compare" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][combined][<?php echo esc_attr( $combined_key ); ?>][compare]">
								<option value="is" <?php selected( $compare, 'is' ); ?>><?php esc_html_e( 'including', 'wpc-ajax-search' ); ?></option>
								<option value="is_not" <?php selected( $compare, 'is_not' ); ?>><?php esc_html_e( 'excluding', 'wpc-ajax-search' ); ?></option>
							</select></span> <span class="wpcas_combined_val_wrap">
                                    <select class="wpcas_combined_val wpcas_apply_terms" multiple="multiple" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][combined][<?php echo esc_attr( $combined_key ); ?>][terms][]">
                                        <?php
                                        if ( ! empty( $terms ) ) {
	                                        foreach ( $terms as $ct ) {
		                                        if ( $term = get_term_by( 'slug', $ct, $apply ) ) {
			                                        echo '<option value="' . esc_attr( $ct ) . '" selected>' . esc_html( $term->name ) . '</option>';
		                                        }
	                                        }
                                        }
                                        ?>
                                    </select>
                                </span> <span class="wpcas_combined_number_compare_wrap">
							<select class="wpcas_combined_number_compare" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][combined][<?php echo esc_attr( $combined_key ); ?>][number_compare]">
								<option value="equal" <?php selected( $number_compare, 'equal' ); ?>><?php esc_html_e( 'equal', 'wpc-ajax-search' ); ?></option>
								<option value="not_equal" <?php selected( $number_compare, 'not_equal' ); ?>><?php esc_html_e( 'not equal', 'wpc-ajax-search' ); ?></option>
								<option value="greater" <?php selected( $number_compare, 'greater' ); ?>><?php esc_html_e( 'greater', 'wpc-ajax-search' ); ?></option>
								<option value="less" <?php selected( $number_compare, 'less' ); ?>><?php esc_html_e( 'less than', 'wpc-ajax-search' ); ?></option>
								<option value="greater_equal" <?php selected( $number_compare, 'greater_equal' ); ?>><?php esc_html_e( 'greater or equal', 'wpc-ajax-search' ); ?></option>
								<option value="less_equal" <?php selected( $number_compare, 'less_equal' ); ?>><?php esc_html_e( 'less or equal', 'wpc-ajax-search' ); ?></option>
							</select></span> <span class="wpcas_combined_number_val_wrap">
                                    <input type="number" class="wpcas_combined_number_val" value="<?php echo esc_attr( $number_value ); ?>" name="wpcas_rules[<?php echo esc_attr( $rule_key ); ?>][combined][<?php echo esc_attr( $combined_key ); ?>][number_value]"/>
                                </span>
                    </div>
					<?php
				}

				function ajax_add_rule() {
					if ( ! apply_filters( 'wpcas_disable_security_check', false, 'add_rule' ) ) {
						if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wpcas_nonce' ) ) {
							die( 'Permissions check failed!' );
						}
					}

					self::rule( '', [], true );
					wp_die();
				}

				function ajax_add_condition() {
					if ( ! apply_filters( 'wpcas_disable_security_check', false, 'add_condition' ) ) {
						if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wpcas_nonce' ) ) {
							die( 'Permissions check failed!' );
						}
					}

					$rule_key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : self::generate_key();

					self::condition( '', [], $rule_key );
					wp_die();
				}

				function ajax_add_combined() {
					if ( ! apply_filters( 'wpcas_disable_security_check', false, 'add_combined' ) ) {
						if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wpcas_nonce' ) ) {
							die( 'Permissions check failed!' );
						}
					}

					$rule_key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : self::generate_key();

					self::combined( '', [], $rule_key );
					wp_die();
				}

				function ajax_search_term() {
					$return = [];

					$args = [
						'taxonomy'   => sanitize_text_field( $_REQUEST['taxonomy'] ),
						'orderby'    => 'id',
						'order'      => 'ASC',
						'hide_empty' => false,
						'fields'     => 'all',
						'name__like' => sanitize_text_field( $_REQUEST['q'] ),
					];

					$terms = get_terms( $args );

					if ( count( $terms ) ) {
						foreach ( $terms as $term ) {
							$return[] = [ $term->slug, $term->name ];
						}
					}

					wp_send_json( $return );
				}

				public static function get_settings() {
					return apply_filters( 'wpcas_get_settings', self::$settings );
				}

				public static function get_setting( $name, $default = false ) {
					if ( ! empty( self::$settings ) && isset( self::$settings[ $name ] ) ) {
						$setting = self::$settings[ $name ];
					} else {
						$setting = get_option( 'wpcas_' . $name, $default );
					}

					return apply_filters( 'wpcas_get_setting', $setting, $name, $default );
				}

				public static function localization( $key = '', $default = '' ) {
					$str = '';

					if ( ! empty( $key ) && ! empty( self::$localization[ $key ] ) ) {
						$str = self::$localization[ $key ];
					} elseif ( ! empty( $default ) ) {
						$str = $default;
					}

					return esc_html( apply_filters( 'wpcas_localization_' . $key, $str ) );
				}

				public static function generate_key() {
					$key         = '';
					$key_str     = apply_filters( 'wpcas_key_characters', 'abcdefghijklmnopqrstuvwxyz0123456789' );
					$key_str_len = strlen( $key_str );

					for ( $i = 0; $i < apply_filters( 'wpcas_key_length', 4 ); $i ++ ) {
						$key .= $key_str[ random_int( 0, $key_str_len - 1 ) ];
					}

					if ( is_numeric( $key ) ) {
						$key = self::generate_key();
					}

					return apply_filters( 'wpcas_generate_key', $key );
				}

				function wpcsm_locations( $locations ) {
					$locations['WPC AJAX Search'] = [
						'wpcas_placeholder_result'   => esc_html__( 'Placeholder result', 'wpc-ajax-search' ),
						'wpcas_before_message'       => esc_html__( 'Before returned message', 'wpc-ajax-search' ),
						'wpcas_after_message'        => esc_html__( 'After returned message', 'wpc-ajax-search' ),
						'wpcas_before_products'      => esc_html__( 'Before returned products', 'wpc-ajax-search' ),
						'wpcas_after_products'       => esc_html__( 'After returned products', 'wpc-ajax-search' ),
						'wpcas_before_not_found'     => esc_html__( 'Before not-found message', 'wpc-ajax-search' ),
						'wpcas_after_not_found'      => esc_html__( 'After not-found message', 'wpc-ajax-search' ),
						'wpcas_before_search_result' => esc_html__( 'Before search result', 'wpc-ajax-search' ),
						'wpcas_after_search_result'  => esc_html__( 'After search result', 'wpc-ajax-search' ),
					];

					return $locations;
				}

				// shortcodes
				function shortcode_categories() {
					$output = '<div class="wpcas-shortcode-categories"><ul>' . wp_list_categories( [
							'taxonomy' => 'product_cat',
							'title_li' => '',
							'echo'     => 0
						] ) . '</ul></div>';

					return apply_filters( 'wpcas_shortcode_categories', $output );
				}

				function shortcode_products( $attrs ) {
					$output = '';

					$attrs = shortcode_atts( [
						's'       => '',
						'type'    => 'recent',
						'orderby' => 'default',
						'order'   => 'default',
						'limit'   => 10
					], $attrs );

					$args = [
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'fields'         => 'ids',
						'posts_per_page' => $attrs['limit'],
						'orderby'        => $attrs['orderby'],
						'order'          => $attrs['order'],
					];

					switch ( $attrs['type'] ) {
						case 'on_sale':
							$args['post__in'] = wc_get_product_ids_on_sale();
							break;
						case 'featured':
							$args['post__in'] = wc_get_featured_product_ids();
							break;
						case 'recent':
							$args['orderby'] = 'date';
							break;
						case 'popular':
							$args['meta_key'] = 'total_sales';
							$args['orderby']  = 'meta_value_num';
							break;
						default:
							$args['s'] = $attrs['s'];
					}

					$query = new WP_Query( apply_filters( 'wpcas_shortcode_products_query_args', $args ) );

					if ( $products = $query->posts ) {
						ob_start();
						self::show_products( $products, $attrs['s'] );
						$output = ob_get_clean();
					}

					return apply_filters( 'wpcas_shortcode_products', $output, $attrs );
				}

				function shortcode_posts( $attrs ) {
					$output = '';

					$attrs = shortcode_atts( [
						's'       => '',
						'type'    => 'recent',
						'orderby' => 'default',
						'order'   => 'default',
						'limit'   => 10
					], $attrs );

					$args = [
						'post_type'      => 'post',
						'post_status'    => 'publish',
						'fields'         => 'ids',
						'posts_per_page' => $attrs['limit'],
						'orderby'        => $attrs['orderby'],
						'order'          => $attrs['order'],
					];

					switch ( $attrs['type'] ) {
						case 'recent':
							$args['orderby'] = 'date';
							break;
						default:
							$args['s'] = $attrs['s'];
					}

					$query = new WP_Query( apply_filters( 'wpcas_shortcode_posts_query_args', $args ) );

					if ( $posts = $query->posts ) {
						ob_start();
						self::show_posts( $posts );
						$output = ob_get_clean();
					}

					return apply_filters( 'wpcas_shortcode_posts', $output, $attrs );
				}

				function show_posts( $posts ) {
					echo '<div class="wpcas-posts">';

					do_action( 'wpcas_before_posts' );

					foreach ( $posts as $post_id ) {
						echo '<div class="wpcas-post">';
						echo '<div class="wpcas-post-inner">';
						do_action( 'wpcas_before_post_inner', $post_id );

						echo '<div class="wpcas-post-thumb">';
						do_action( 'wpcas_before_post_thumbnail', $post_id );

						echo '<a href="' . get_permalink( $post_id ) . '">' . get_the_post_thumbnail( $post_id, 'thumbnail' ) . '</a>';

						do_action( 'wpcas_after_post_thumbnail', $post_id );
						echo '</div><!-- /wpcas-post-thumb -->';

						echo '<div class="wpcas-post-info">';
						do_action( 'wpcas_before_post_info', $post_id );

						echo '<div class="wpcas-post-name">';
						do_action( 'wpcas_before_post_name', $post_id );

						echo '<a href="' . get_permalink( $post_id ) . '">' . get_the_title( $post_id ) . '</a>';

						do_action( 'wpcas_after_post_name', $post_id );
						echo '</div><!-- /wpcas-post-name -->';

						echo '<div class="wpcas-post-date">' . get_the_date( 'd/m/Y', $post_id ) . '</div>';

						do_action( 'wpcas_after_post_info', $post_id );
						echo '</div><!-- /wpcas-post-info -->';

						do_action( 'wpcas_after_post_inner', $post_id );
						echo '</div><!-- /wpcas-post-inner -->';
						echo '</div><!-- /wpcas-post -->';
					}

					do_action( 'wpcas_after_posts' );

					echo '</div>';
				}
			}

			return WPCleverWpcas::instance();
		}

		return null;
	}
}

if ( ! function_exists( 'wpcas_delete_folder' ) ) {
	function wpcas_delete_folder( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), [ '.', '..' ] );

		foreach ( $files as $file ) {
			( is_dir( "$dir/$file" ) ) ? wpcas_delete_folder( "$dir/$file" ) : unlink( "$dir/$file" );
		}

		return rmdir( $dir );
	}
}

if ( ! function_exists( 'wpcas_notice_wc' ) ) {
	function wpcas_notice_wc() {
		?>
        <div class="error">
            <p><strong>WPC AJAX Search</strong> requires WooCommerce version 3.0 or greater.</p>
        </div>
		<?php
	}
}
