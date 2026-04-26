<?php
/**
 * Plugin Name: Megurio Delete Cart for WooCommerce
 * Plugin URI:  https://megurio.jp
 * Description: WooCommerceのカート機能を無効化し、「カートに追加」ボタンを「今すぐ購入」に変更して、クリック時に直接チェックアウト画面へ遷移します。チェックアウト画面では商品数量の変更・削除が可能です。
 * Version:     1.2.0
 * Author:      Megurio
 * License:     GPL-2.0+
 * Text Domain: megurio-delete-cart
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Megurio_Delete_Cart_For_WooCommerce {

    public function __construct() {
        // ボタンテキストを変更
        add_filter( 'woocommerce_product_single_add_to_cart_text',    [ $this, 'buy_now_text' ] );
        add_filter( 'woocommerce_product_add_to_cart_text',           [ $this, 'buy_now_text' ] );

        // 商品詳細ページ：カートに追加後、即チェックアウトへリダイレクト
        add_filter( 'woocommerce_add_to_cart_redirect',               [ $this, 'redirect_to_checkout' ] );

        // 商品一覧ページ：ボタンリンクをチェックアウト URL に変更
        add_filter( 'woocommerce_loop_add_to_cart_link',              [ $this, 'loop_buy_now_link' ], 10, 3 );

        // カートフラグメントの AJAX 読み込みを無効化
        add_action( 'wp_enqueue_scripts',                             [ $this, 'disable_cart_fragments' ] );

        // カートアイコン・メニュー、および AJAX 追加後の「カゴを表示」リンクを非表示
        add_filter( 'woocommerce_widget_cart_is_hidden',              '__return_true' );
        add_action( 'wp_head',                                        [ $this, 'hide_cart_icon_css' ] );

        // カートページへの直接アクセスをチェックアウトへリダイレクト
        add_action( 'template_redirect',                              [ $this, 'redirect_cart_page' ] );

        // 「カートに追加しました」通知の生成を抑制
        add_filter( 'wc_add_to_cart_message_html',                    '__return_empty_string' );
        // チェックアウトページ表示前に残留した success 通知を削除
        add_action( 'woocommerce_before_checkout_form',               [ $this, 'clear_success_notices' ], 1 );

        // チェックアウトページ：編集可能なカートセクション
        add_action( 'woocommerce_before_checkout_form',               [ $this, 'checkout_cart_editor' ], 5 );
        add_action( 'wp_head',                                        [ $this, 'checkout_cart_styles' ] );
        add_action( 'wp_footer',                                      [ $this, 'checkout_cart_scripts' ] );

        // カート商品削除後はチェックアウトへ直接リダイレクト
        add_filter( 'woocommerce_cart_item_removed_redirect',         [ $this, 'removed_redirect_to_checkout' ] );

        // カート数量更新 AJAX
        add_action( 'wp_ajax_megurio_update_cart_qty',                [ $this, 'ajax_update_cart_qty' ] );
        add_action( 'wp_ajax_nopriv_megurio_update_cart_qty',         [ $this, 'ajax_update_cart_qty' ] );

        // 管理画面：チェックアウトフィールド設定
        add_action( 'admin_menu',                                     [ $this, 'add_settings_page' ] );
        add_filter( 'woocommerce_checkout_fields',                    [ $this, 'hide_checkout_fields' ] );
    }

    /** ボタンテキストを「今すぐ購入」に統一 */
    public function buy_now_text(): string {
        return __( '今すぐ購入', 'megurio-delete-cart' );
    }

    /** 商品詳細ページでカートに追加後、チェックアウトへリダイレクト */
    public function redirect_to_checkout(): string {
        return wc_get_checkout_url();
    }

    /**
     * 商品一覧ページ：ボタンを <span> に変更して親 <a> とのネストアンカー問題を回避。
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
                '<span class="%s" tabindex="0" role="button" onclick="event.stopPropagation();window.location.href=\'%s\';">%s</span>',
                esc_attr( $classes ),
                esc_js( $checkout_url ),
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

    /** 主要テーマのカートアイコン・ウィジェットを CSS で非表示 */
    public function hide_cart_icon_css(): void {
        echo '<style>
            /* WooCommerce core */
            a.added_to_cart,
            .widget_shopping_cart,
            li.cart-menu-item,
            li.woocommerce-cart-menu-item,

            /* Storefront */
            .cart-contents,
            .site-header-cart,

            /* Astra */
            .ast-site-header-cart,
            .ast-header-cart-btn-wrap,
            .ast-cart-menu-wrap,

            /* OceanWP */
            .woo-cart,
            .ocean-cart-link,
            #ocean-cart,

            /* GeneratePress */
            .nav-cart,
            .nav-cart-link,

            /* Kadence */
            .header-cart-wrap,
            .cart-link,

            /* Neve */
            .cart-icon-wrapper,
            .nv-nav-cart,

            /* Flatsome / general */
            .header-cart,
            .wcmenucart,
            a.cart-customlocation,

            /* Elementor */
            .elementor-cart,
            .elementor-cart-button,

            /* Woodmart */
            .woodmart-cart-btn,
            .wd-cart-btn,

            /* Porto / Minimog */
            .mini-cart,
            .header-mini-cart,

            /* Avada */
            .avada-shopping-cart-menu,

            /* Divi */
            .et-cart-info,

            /* SWELL (Japanese) */
            .c-headNav__cart,
            .p-globalNav__cart,
            .swell-block-cart,

            /* AFFINGER (Japanese) */
            .header_cart_btn,
            .affinger-cart,

            /* JIN / JIN:R (Japanese) */
            .jin-header-cart,
            .jin-cart-btn,

            /* Lightning / VK (Japanese) */
            .vk_header__cart,
            .lightning-cart,

            /* The Thor (Japanese) */
            .the-thor-cart,

            /* Cocoon (Japanese) */
            .cocoon-cart,

            /* General catch-all */
            .cart-icon,
            .cart-btn,
            [class*="cart-icon"],
            [class*="header-cart"],
            [class*="nav-cart"] {
                display: none !important;
            }
        </style>' . "\n";
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
        if ( ! is_checkout() || WC()->cart->is_empty() ) {
            return;
        }
        ?>
        <div class="megurio-checkout-cart">
            <h3><?php esc_html_e( 'ご注文内容', 'megurio-delete-cart' ); ?></h3>
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
                            <?php echo wc_get_formatted_cart_item_data( $cart_item ); ?>
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
                            <?php echo WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ); ?>
                        </td>
                        <td class="megurio-cart-remove">
                            <a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>"
                               class="megurio-remove-btn"
                               aria-label="<?php esc_attr_e( '削除', 'megurio-delete-cart' ); ?>">✕</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    /** チェックアウトページのカートテーブル用 CSS（wp_head に出力） */
    public function checkout_cart_styles(): void {
        if ( ! is_checkout() ) {
            return;
        }
        ?>
        <style>
        .megurio-checkout-cart {
            margin-bottom: 2em;
        }
        .megurio-checkout-cart h3 {
            font-size: 1em;
            font-weight: bold;
            margin-bottom: .75em;
            padding-bottom: .5em;
            border-bottom: 2px solid #333;
        }
        .megurio-cart-table {
            width: 100%;
            border-collapse: collapse;
        }
        .megurio-cart-table td {
            padding: 10px 6px;
            border-bottom: 1px solid #e5e5e5;
            vertical-align: middle;
        }
        .megurio-cart-name {
            width: 100%;
        }
        .megurio-cart-qty {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .megurio-qty-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 0;
            border-radius: 3px;
            flex-shrink: 0;
        }
        .megurio-qty-btn:hover {
            background: #e0e0e0;
        }
        .megurio-qty-input {
            width: 50px;
            text-align: center;
            border: 1px solid #ccc;
            height: 30px;
            padding: 0 4px;
            font-size: 14px;
            border-radius: 3px;
            -moz-appearance: textfield;
        }
        .megurio-qty-input::-webkit-inner-spin-button,
        .megurio-qty-input::-webkit-outer-spin-button {
            -webkit-appearance: none;
        }
        .megurio-cart-subtotal {
            text-align: right;
            white-space: nowrap;
            padding-left: 12px;
        }
        .megurio-cart-remove {
            white-space: nowrap;
            padding-left: 8px;
        }
        .megurio-remove-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            color: #999;
            text-decoration: none;
            font-size: 14px;
            border-radius: 50%;
            border: 1px solid transparent;
        }
        .megurio-remove-btn:hover {
            color: #cc0000;
            background: #fff0f0;
            border-color: #fcc;
        }
        </style>
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
        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
        $qty = max( 1, (int) ( $_POST['qty'] ?? 1 ) );
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
        ?>
        <script>
        (function ($) {
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'megurio_update_cart' ) ); ?>;
            var timer;

            $(document).on('click', '.megurio-qty-minus', function () {
                var $input = $(this).next('.megurio-qty-input');
                var val = Math.max(1, parseInt($input.val(), 10) - 1);
                $input.val(val);
                schedule($input.data('key'), val);
            });

            $(document).on('click', '.megurio-qty-plus', function () {
                var $input = $(this).prev('.megurio-qty-input');
                var val = parseInt($input.val(), 10) + 1;
                $input.val(val);
                schedule($input.data('key'), val);
            });

            $(document).on('change', '.megurio-qty-input', function () {
                var val = parseInt($(this).val(), 10);
                if (isNaN(val) || val < 1) return;
                schedule($(this).data('key'), val);
            });

            function schedule(key, qty) {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    $.post(ajaxUrl, {
                        action: 'megurio_update_cart_qty',
                        nonce:  nonce,
                        key:    key,
                        qty:    qty
                    }, function (res) {
                        if (res.success) location.reload();
                    });
                }, 500);
            }
        }(jQuery));
        </script>
        <?php
    }
    /** WooCommerce 配下にサブメニューを追加 */
    public function add_settings_page(): void {
        add_submenu_page(
            'woocommerce',
            'チェックアウトフィールド設定',
            'チェックアウト設定',
            'manage_woocommerce',
            'megurio-checkout-fields',
            [ $this, 'render_settings_page' ]
        );
    }

    /** 設定ページ HTML を出力・保存処理 */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_POST['megurio_save_fields'] ) ) {
            check_admin_referer( 'megurio_save_checkout_fields' );
            $hidden = isset( $_POST['megurio_hidden_fields'] )
                ? array_map( 'sanitize_text_field', (array) $_POST['megurio_hidden_fields'] )
                : [];
            update_option( 'megurio_hidden_checkout_fields', $hidden );
            echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
        }

        $hidden = (array) get_option( 'megurio_hidden_checkout_fields', [] );

        // shipping_required=true のフィールドは配送商品がカートにある場合は非表示設定が無効になる
        $fields = [
            'billing_last_name'  => [ 'label' => '姓',                          'shipping_required' => true ],
            'billing_first_name' => [ 'label' => '名',                          'shipping_required' => true ],
            'billing_company'    => [ 'label' => '会社名',                       'shipping_required' => false ],
            'billing_country'    => [ 'label' => '国または地域',                  'shipping_required' => true ],
            'billing_postcode'   => [ 'label' => '郵便番号',                      'shipping_required' => true ],
            'billing_state'      => [ 'label' => '都道府県',                      'shipping_required' => true ],
            'billing_city'       => [ 'label' => '市区町村',                      'shipping_required' => true ],
            'billing_address_1'  => [ 'label' => '番地',                         'shipping_required' => true ],
            'billing_address_2'  => [ 'label' => 'アパート名・棟名・部屋番号など', 'shipping_required' => false ],
            'billing_phone'      => [ 'label' => '電話',                         'shipping_required' => true ],
            'billing_email'      => [ 'label' => 'メールアドレス',                 'shipping_required' => true ],
            'order_comments'     => [ 'label' => '注文メモ／追加情報',             'shipping_required' => false ],
        ];
        ?>
        <div class="wrap">
            <h1>チェックアウトフィールド設定</h1>
            <p>チェックアウトページで<strong>非表示</strong>にするフィールドにチェックを入れてください。</p>
            <form method="post">
                <?php wp_nonce_field( 'megurio_save_checkout_fields' ); ?>
                <table class="form-table" role="presentation">
                    <?php foreach ( $fields as $key => $field ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="megurio_hidden_fields[]"
                                           value="<?php echo esc_attr( $key ); ?>"
                                           <?php checked( in_array( $key, $hidden, true ) ); ?>>
                                    非表示にする
                                </label>
                                <?php if ( $field['shipping_required'] ) : ?>
                                    <p class="description" style="color:#b32d2e;">
                                        ⚠️ 配送が必要な商品がカートに含まれている場合、この設定は無効になります（バーチャル商品のみ有効）。
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <p class="submit">
                    <button type="submit" name="megurio_save_fields" class="button button-primary">
                        変更を保存
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /** 設定に基づいてチェックアウトフィールドを除去（配送必須フィールドは配送商品がある場合スキップ） */
    public function hide_checkout_fields( array $fields ): array {
        $hidden = (array) get_option( 'megurio_hidden_checkout_fields', [] );
        if ( empty( $hidden ) ) {
            return $fields;
        }

        $needs_shipping = WC()->cart && WC()->cart->needs_shipping();

        $shipping_required = [
            'billing_last_name', 'billing_first_name', 'billing_country',
            'billing_postcode', 'billing_state', 'billing_city',
            'billing_address_1', 'billing_phone', 'billing_email',
        ];

        foreach ( $hidden as $key ) {
            if ( $needs_shipping && in_array( $key, $shipping_required, true ) ) {
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
}

new Megurio_Delete_Cart_For_WooCommerce();
