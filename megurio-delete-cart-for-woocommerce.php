<?php
/**
 * Plugin Name: Megurio Delete Cart for WooCommerce
 * Description: WooCommerceのカートを無効化し、購入ボタンから直接チェックアウトへ遷移できます。
 * Version:     1.2.5
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author:      Megurio
 * Author URI:  https://megurio.jp
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: megurio-delete-cart-for-woocommerce
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 10.6.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MEGURIO_DELETE_CART_FOR_WOOCOMMERCE_VERSION', '1.2.5' );

add_action( 'before_woocommerce_init', function(): void {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

class Megurio_Delete_Cart_For_WooCommerce {

    public function __construct() {
        // 管理画面：チェックアウトフィールド設定
        add_action( 'admin_menu',                                     [ $this, 'add_settings_page' ] );
        add_action( 'admin_enqueue_scripts',                          [ $this, 'admin_settings_styles' ] );
        add_filter( 'woocommerce_checkout_fields',                    [ $this, 'hide_checkout_fields' ] );

        if ( $this->is_checkout_coupon_hidden() ) {
            add_action( 'woocommerce_before_checkout_form',           [ $this, 'remove_checkout_coupon_form' ], 0 );
        }

        if ( ! $this->is_delete_cart_enabled() ) {
            return;
        }

        // ボタンテキストを変更
        add_filter( 'woocommerce_product_single_add_to_cart_text',    [ $this, 'buy_now_text' ] );
        add_filter( 'woocommerce_product_add_to_cart_text',           [ $this, 'buy_now_text' ] );

        // 商品詳細ページ：カートに追加後、即チェックアウトへリダイレクト
        add_filter( 'woocommerce_add_to_cart_redirect',               [ $this, 'redirect_to_checkout' ] );
        add_filter( 'woocommerce_add_to_cart_validation',             [ $this, 'empty_cart_before_add' ], 1, 6 );

        // 商品一覧ページ：ボタンリンクをチェックアウト URL に変更
        add_filter( 'woocommerce_loop_add_to_cart_link',              [ $this, 'loop_buy_now_link' ], 10, 3 );

        // カートフラグメントの AJAX 読み込みを無効化
        add_action( 'wp_enqueue_scripts',                             [ $this, 'disable_cart_fragments' ] );

        // カートアイコン・メニュー、および AJAX 追加後の「カゴを表示」リンクを非表示
        add_filter( 'woocommerce_widget_cart_is_hidden',              '__return_true' );
        add_action( 'wp_enqueue_scripts',                             [ $this, 'enqueue_public_assets' ] );

        // カートページへの直接アクセスをチェックアウトへリダイレクト
        add_action( 'template_redirect',                              [ $this, 'redirect_cart_page' ] );

        // 「カートに追加しました」通知の生成を抑制
        add_filter( 'wc_add_to_cart_message_html',                    '__return_empty_string' );
        // チェックアウトページ表示前に残留した success 通知を削除
        add_action( 'woocommerce_before_checkout_form',               [ $this, 'clear_success_notices' ], 1 );

        // チェックアウトページ：編集可能なカートセクション
        add_action( 'woocommerce_before_checkout_form',               [ $this, 'checkout_cart_editor' ], 5 );
        add_action( 'wp_enqueue_scripts',                             [ $this, 'checkout_cart_scripts' ] );

        // カート商品削除後はチェックアウトへ直接リダイレクト
        add_filter( 'woocommerce_cart_item_removed_redirect',         [ $this, 'removed_redirect_to_checkout' ] );

        // カート数量更新 AJAX
        add_action( 'wp_ajax_megurio_update_cart_qty',                [ $this, 'ajax_update_cart_qty' ] );
        add_action( 'wp_ajax_nopriv_megurio_update_cart_qty',         [ $this, 'ajax_update_cart_qty' ] );
    }

    /** カート削除機能が有効かどうか */
    private function is_delete_cart_enabled(): bool {
        return 'yes' === get_option( 'megurio_delete_cart_enabled', 'yes' );
    }

    /** チェックアウトページのクーポン入力欄を非表示にするかどうか */
    private function is_checkout_coupon_hidden(): bool {
        return 'yes' === get_option( 'megurio_hide_checkout_coupon', 'yes' );
    }

    /** チェックアウトページ上部のクーポン入力欄を削除 */
    public function remove_checkout_coupon_form(): void {
        remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
    }

    /** ボタンテキストを「今すぐ購入」に統一 */
    public function buy_now_text(): string {
        return $this->get_buy_now_text();
    }

    /** 購入ボタンの表示テキスト */
    private function get_buy_now_text(): string {
        $text = trim( (string) get_option( 'megurio_buy_now_text', __( '今すぐ購入', 'megurio-delete-cart-for-woocommerce' ) ) );

        return $text !== '' ? $text : __( '今すぐ購入', 'megurio-delete-cart-for-woocommerce' );
    }

    /** 商品詳細ページでカートに追加後、チェックアウトへリダイレクト */
    public function redirect_to_checkout(): string {
        return wc_get_checkout_url();
    }

    /**
     * 「今すぐ購入」では以前のカート内容を引き継がず、今回の商品だけでチェックアウトします。
     */
    public function empty_cart_before_add( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = [], array $cart_item_data = [] ): bool {
        if ( ! $passed || ! WC()->cart || WC()->cart->is_empty() ) {
            return $passed;
        }

        WC()->cart->empty_cart();

        return $passed;
    }

    /**
     * 商品一覧ページ：ボタンのリンク先を直接チェックアウト URL に変更。
     */
    public function loop_buy_now_link( string $link, \WC_Product $product, array $args ): string {
        if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return $link;
        }

        if ( $product->is_type( 'simple' ) ) {
            $checkout_url = add_query_arg(
                [
                    'add-to-cart' => $product->get_id(),
                    'quantity'    => 1,
                ],
                wc_get_checkout_url()
            );

            $raw_classes = isset( $args['class'] ) ? $args['class'] : 'button';
            $classes     = trim( implode( ' ', array_filter(
                explode( ' ', $raw_classes ),
                fn( string $c ) => ! in_array( $c, [ 'ajax_add_to_cart', 'add_to_cart_button' ], true )
            ) ) ) ?: 'button';

            return sprintf(
                '<a href="%1$s" class="%2$s" data-product_id="%3$d" data-product_sku="%4$s" rel="nofollow" aria-label="%5$s">%6$s</a>',
                esc_url( $checkout_url ),
                esc_attr( $classes ),
                esc_attr( $product->get_id() ),
                esc_attr( $product->get_sku() ),
                esc_attr( $this->buy_now_text() ),
                esc_html( $this->buy_now_text() )
            );
        }

        // バリアブル商品・グループ商品はテキストのみ変更
        return preg_replace(
            '/(<a\b[^>]*>).*?(<\/a>)/i',
            '$1' . esc_html( $this->buy_now_text() ) . '$2',
            $link
        );
    }

    /** cart-fragments.min.js を除去してカートウィジェットの不要な更新を防止 */
    public function disable_cart_fragments(): void {
        wp_dequeue_script( 'wc-cart-fragments' );
    }

    /** 前台用 CSS を読み込み */
    public function enqueue_public_assets(): void {
        wp_enqueue_style(
            'megurio-delete-cart-public',
            plugin_dir_url( __FILE__ ) . 'assets/css/public.css',
            array(),
            MEGURIO_DELETE_CART_FOR_WOOCOMMERCE_VERSION
        );
    }

    /** チェックアウトフォーム描画前に success 通知（カート追加通知）を全て削除 */
    public function clear_success_notices(): void {
        if ( ! WC()->session ) {
            return;
        }
        $notices = WC()->session->get( 'wc_notices', [] );
        if ( ! empty( $notices['success'] ) ) {
            unset( $notices['success'] );
            WC()->session->set( 'wc_notices', $notices );
        }
    }

    /** カートページへの直接アクセス時にリダイレクト（空カートの場合はショップへ、空でない場合はチェックアウトへ） */
    public function redirect_cart_page(): void {
        if ( is_cart() ) {
            $url = ( WC()->cart && ! WC()->cart->is_empty() )
                ? wc_get_checkout_url()
                : wc_get_page_permalink( 'shop' );
            wp_safe_redirect( $url, 302 );
            exit;
        }
    }

    /** チェックアウトページに編集可能なカートテーブルを表示 */
    public function checkout_cart_editor(): void {
        if ( ! is_checkout() || ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }
        ?>
        <div class="megurio-checkout-cart"
             data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'megurio_update_cart' ) ); ?>">
            <h3><?php esc_html_e( 'ご注文内容', 'megurio-delete-cart-for-woocommerce' ); ?></h3>
            <table class="megurio-cart-table">
                <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                    $product = $cart_item['data'];
                    if ( ! $product || ! $product->exists() ) {
                        continue;
                    }
                    ?>
                    <tr>
                        <td class="megurio-cart-name">
                            <?php echo esc_html( $product->get_name() ); ?>
                            <?php echo wp_kses_post( wc_get_formatted_cart_item_data( $cart_item ) ); ?>
                        </td>
                        <td class="megurio-cart-qty">
                            <button type="button" class="megurio-qty-btn megurio-qty-minus">－</button>
                            <input type="number"
                                   class="megurio-qty-input"
                                   value="<?php echo esc_attr( $cart_item['quantity'] ); ?>"
                                   min="1"
                                   data-key="<?php echo esc_attr( $cart_item_key ); ?>">
                            <button type="button" class="megurio-qty-btn megurio-qty-plus">＋</button>
                        </td>
                        <td class="megurio-cart-subtotal">
                            <?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ); ?>
                        </td>
                        <td class="megurio-cart-remove">
                            <a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>"
                               class="megurio-remove-btn"
                               aria-label="<?php esc_attr_e( '削除', 'megurio-delete-cart-for-woocommerce' ); ?>">✕</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    /** カート商品削除後のリダイレクト先（空になった場合はショップへ、空でない場合はチェックアウトへ） */
    public function removed_redirect_to_checkout(): string {
        if ( WC()->cart && WC()->cart->is_empty() ) {
            return wc_get_page_permalink( 'shop' );
        }
        return wc_get_checkout_url();
    }

    /** カート数量 AJAX 更新ハンドラ */
    public function ajax_update_cart_qty(): void {
        check_ajax_referer( 'megurio_update_cart', 'nonce' );

        $key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $raw_qty = isset( $_POST['qty'] ) ? absint( wp_unslash( $_POST['qty'] ) ) : 1;
        $qty     = max( 1, $raw_qty );

        if ( ! WC()->cart ) {
            wp_send_json_error();
        }

        if ( $key && WC()->cart->get_cart_item( $key ) ) {
            WC()->cart->set_quantity( $key, $qty );
        }
        wp_send_json_success();
    }

    /** チェックアウトページのみ JS を出力（数量操作） */
    public function checkout_cart_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }

        wp_enqueue_script(
            'megurio-delete-cart-checkout',
            plugin_dir_url( __FILE__ ) . 'assets/js/checkout-cart.js',
            array( 'jquery' ),
            MEGURIO_DELETE_CART_FOR_WOOCOMMERCE_VERSION,
            true
        );
    }
    /** WooCommerce 配下にサブメニューを追加 */
    public function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'チェックアウトフィールド設定', 'megurio-delete-cart-for-woocommerce' ),
            __( 'チェックアウト設定', 'megurio-delete-cart-for-woocommerce' ),
            'manage_woocommerce',
            'megurio-checkout-fields',
            [ $this, 'render_settings_page' ]
        );
    }

    /** 管理画面設定ページ用 CSS */
    public function admin_settings_styles( string $hook_suffix ): void {
        if ( 'woocommerce_page_megurio-checkout-fields' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'megurio-delete-cart-admin',
            plugin_dir_url( __FILE__ ) . 'assets/css/admin.css',
            array(),
            MEGURIO_DELETE_CART_FOR_WOOCOMMERCE_VERSION
        );
    }

    /** 設定ページ HTML を出力・保存処理 */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $fields       = $this->get_checkout_field_options();
        $allowed_keys = array_keys( $fields );

        if ( isset( $_POST['megurio_save_fields'] ) ) {
            check_admin_referer( 'megurio_save_checkout_fields' );

            update_option(
                'megurio_delete_cart_enabled',
                isset( $_POST['megurio_delete_cart_enabled'] ) ? 'yes' : 'no'
            );
            update_option(
                'megurio_buy_now_text',
                isset( $_POST['megurio_buy_now_text'] )
                    ? sanitize_text_field( wp_unslash( $_POST['megurio_buy_now_text'] ) )
                    : __( '今すぐ購入', 'megurio-delete-cart-for-woocommerce' )
            );
            update_option(
                'megurio_hide_checkout_coupon',
                isset( $_POST['megurio_hide_checkout_coupon'] ) ? 'yes' : 'no'
            );

            $hidden = isset( $_POST['megurio_hidden_fields'] )
                ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['megurio_hidden_fields'] ) )
                : [];
            $hidden = array_values( array_intersect( $hidden, $allowed_keys ) );

            update_option( 'megurio_hidden_checkout_fields', $hidden );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '設定を保存しました。', 'megurio-delete-cart-for-woocommerce' ) . '</p></div>';
        }

        $hidden = (array) get_option( 'megurio_hidden_checkout_fields', [] );
        $hidden = array_values( array_intersect( array_map( 'sanitize_key', $hidden ), $allowed_keys ) );
        $delete_cart_enabled = $this->is_delete_cart_enabled();
        $buy_now_text = $this->get_buy_now_text();
        $hide_checkout_coupon = $this->is_checkout_coupon_hidden();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'チェックアウトフィールド設定', 'megurio-delete-cart-for-woocommerce' ); ?></h1>
            <h2><?php esc_html_e( 'カート削除機能', 'megurio-delete-cart-for-woocommerce' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'megurio_save_checkout_fields' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'カート削除機能', 'megurio-delete-cart-for-woocommerce' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="megurio_delete_cart_enabled"
                                       value="1"
                                       <?php checked( $delete_cart_enabled ); ?>>
                                <?php esc_html_e( '有効にする', 'megurio-delete-cart-for-woocommerce' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( '有効にすると、カートを経由しない購入フローになります。', 'megurio-delete-cart-for-woocommerce' ); ?>
                            </p>
                            <p>
                                <label for="megurio_buy_now_text">
                                    <?php esc_html_e( '購入ボタンのテキスト', 'megurio-delete-cart-for-woocommerce' ); ?>
                                </label>
                                <br>
                                <input type="text"
                                       id="megurio_buy_now_text"
                                       name="megurio_buy_now_text"
                                       class="regular-text"
                                       value="<?php echo esc_attr( $buy_now_text ); ?>"
                                       placeholder="<?php esc_attr_e( '今すぐ購入', 'megurio-delete-cart-for-woocommerce' ); ?>">
                            </p>
                            <p class="description">
                                <?php esc_html_e( '空欄の場合は「今すぐ購入」を使用します。', 'megurio-delete-cart-for-woocommerce' ); ?>
                            </p>
                            <ul class="megurio-delete-cart-features">
                                <li><?php esc_html_e( '購入ボタンから直接チェックアウトページへ移動します。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( '商品詳細ページでは、カート追加後すぐにチェックアウトへリダイレクトします。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( '商品一覧のシンプル商品は、チェックアウト用の購入リンクに変更します。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( '購入前に既存のカート内容を空にし、今回の商品だけで手続きします。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'カートアイコン、ミニカート、カートウィジェットを非表示にします。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'カートページへアクセスした場合、商品があればチェックアウトへ、空ならショップへ移動します。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'チェックアウトページに「ご注文内容」を表示し、数量変更と削除ができます。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                                <li><?php esc_html_e( '「カートに追加しました」通知を表示せず、チェックアウト上の残留通知も消します。', 'megurio-delete-cart-for-woocommerce' ); ?></li>
                            </ul>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'クーポン設定', 'megurio-delete-cart-for-woocommerce' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'クーポン入力欄', 'megurio-delete-cart-for-woocommerce' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="megurio_hide_checkout_coupon"
                                       value="1"
                                       <?php checked( $hide_checkout_coupon ); ?>>
                                <?php esc_html_e( 'チェックアウトページのクーポン入力欄を非表示にする', 'megurio-delete-cart-for-woocommerce' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'チェックアウトページ上部のクーポンコード入力フォームを表示しません。', 'megurio-delete-cart-for-woocommerce' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'チェックアウトフィールド設定', 'megurio-delete-cart-for-woocommerce' ); ?></h2>
                <p><?php echo wp_kses_post( __( 'チェックアウトページで<strong>非表示</strong>にするフィールドにチェックを入れてください。', 'megurio-delete-cart-for-woocommerce' ) ); ?></p>
                <?php
                $field_groups = [
                    'shipping_required' => [
                        'title'       => __( '配送に必要なフィールド', 'megurio-delete-cart-for-woocommerce' ),
                        'description' => __( '配送が必要な商品がカートに含まれている場合、このグループの設定は無効になります（バーチャル商品のみ有効）。', 'megurio-delete-cart-for-woocommerce' ),
                    ],
                    'optional' => [
                        'title'       => __( '完全に非表示にできるフィールド', 'megurio-delete-cart-for-woocommerce' ),
                        'description' => __( 'チェックを入れると、商品タイプに関係なくチェックアウトページから非表示になります。', 'megurio-delete-cart-for-woocommerce' ),
                    ],
                ];
                ?>
                <?php foreach ( $field_groups as $group_key => $group ) : ?>
                    <h3><?php echo esc_html( $group['title'] ); ?></h3>
                    <p class="description <?php echo 'shipping_required' === $group_key ? 'megurio-checkout-fields-warning' : ''; ?>">
                        <?php echo esc_html( $group['description'] ); ?>
                    </p>
                    <table class="form-table megurio-checkout-fields-table" role="presentation">
                        <?php foreach ( $fields as $key => $field ) : ?>
                            <?php
                            if ( ( 'shipping_required' === $group_key ) !== (bool) $field['shipping_required'] ) {
                                continue;
                            }
                            ?>
                            <tr>
                                <th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                               name="megurio_hidden_fields[]"
                                               value="<?php echo esc_attr( $key ); ?>"
                                               <?php checked( in_array( $key, $hidden, true ) ); ?>>
                                        <?php esc_html_e( '非表示にする', 'megurio-delete-cart-for-woocommerce' ); ?>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endforeach; ?>
                <p class="submit">
                    <button type="submit" name="megurio_save_fields" class="button button-primary">
                        <?php esc_html_e( '変更を保存', 'megurio-delete-cart-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /** 設定に基づいてチェックアウトフィールドを除去（配送必須フィールドは配送商品がある場合スキップ） */
    public function hide_checkout_fields( array $fields ): array {
        $field_options = $this->get_checkout_field_options();
        $hidden        = (array) get_option( 'megurio_hidden_checkout_fields', [] );
        $hidden        = array_values( array_intersect( array_map( 'sanitize_key', $hidden ), array_keys( $field_options ) ) );

        if ( empty( $hidden ) ) {
            return $fields;
        }

        $needs_shipping = WC()->cart && WC()->cart->needs_shipping();

        foreach ( $hidden as $key ) {
            if ( $needs_shipping && ! empty( $field_options[ $key ]['shipping_required'] ) ) {
                continue;
            }
            if ( $key === 'order_comments' ) {
                unset( $fields['order'][ $key ] );
            } else {
                unset( $fields['billing'][ $key ] );
            }
        }
        return $fields;
    }

    /** チェックアウトフィールド設定の選択肢 */
    private function get_checkout_field_options(): array {
        return [
            'billing_last_name'  => [ 'label' => __( '姓', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_first_name' => [ 'label' => __( '名', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_company'    => [ 'label' => __( '会社名', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => false ],
            'billing_country'    => [ 'label' => __( '国または地域', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_postcode'   => [ 'label' => __( '郵便番号', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_state'      => [ 'label' => __( '都道府県', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_city'       => [ 'label' => __( '市区町村', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_address_1'  => [ 'label' => __( '番地', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_address_2'  => [ 'label' => __( 'アパート名・棟名・部屋番号など', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => false ],
            'billing_phone'      => [ 'label' => __( '電話', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'billing_email'      => [ 'label' => __( 'メールアドレス', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => true ],
            'order_comments'     => [ 'label' => __( '注文メモ／追加情報', 'megurio-delete-cart-for-woocommerce' ), 'shipping_required' => false ],
        ];
    }
}

function megurio_delete_cart_for_woocommerce_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', 'megurio_delete_cart_for_woocommerce_missing_woocommerce_notice' );
        }
        return;
    }

    new Megurio_Delete_Cart_For_WooCommerce();
}
add_action( 'plugins_loaded', 'megurio_delete_cart_for_woocommerce_init' );

function megurio_delete_cart_for_woocommerce_missing_woocommerce_notice(): void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    echo '<div class="notice notice-error"><p>' . esc_html__( 'Megurio Delete Cart for WooCommerce requires WooCommerce to be installed and active.', 'megurio-delete-cart-for-woocommerce' ) . '</p></div>';
}
