<?php
/**
 * Plugin Name: Megurio Delete Cart for WooCommerce
 * Plugin URI:  https://megurio.jp
 * Description: WooCommerceのカート機能を無効化し、「カートに追加」ボタンを「今すぐ購入」に変更して、クリック時に直接チェックアウト画面へ遷移します。
 * Version:     1.0.0
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
     * クリック処理は wp_footer で出力する JS（キャプチャフェーズ）が担当する。
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

            // ajax_add_to_cart / add_to_cart_button クラスを除去
            $raw_classes = isset( $args['class'] ) ? $args['class'] : 'button';
            $classes     = trim( implode( ' ', array_filter(
                explode( ' ', $raw_classes ),
                fn( string $c ) => ! in_array( $c, [ 'ajax_add_to_cart', 'add_to_cart_button' ], true )
            ) ) ) ?: 'button';

            // <a> ではなく <span> を使用してネストアンカーを回避
            // インライン onclick で親 <a> への伝播を確実に遮断して遷移
            return sprintf(
                '<span class="%s" tabindex="0" role="button" onclick="event.stopPropagation();window.location.href=\'%s\';">%s</span>',
                esc_attr( $classes ),
                esc_js( $checkout_url ),
                esc_html( $this->buy_now_text() )
            );
        }

        // バリアブル商品・グループ商品はバリエーション選択が必要なため商品ページへ。テキストのみ変更
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

    /** テーマでよく使われるカートアイコンと、AJAX 後に挿入される「カゴを表示」リンクを CSS で非表示 */
    public function hide_cart_icon_css(): void {
        echo '<style>
            .cart-contents,
            .header-cart,
            .site-header-cart,
            .woo-cart,
            a.cart-customlocation,
            .widget_shopping_cart,
            li.cart-menu-item,
            a.added_to_cart {
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

    /** カートページへの直接アクセス時にチェックアウトへリダイレクト */
    public function redirect_cart_page(): void {
        if ( is_cart() ) {
            wp_safe_redirect( wc_get_checkout_url(), 302 );
            exit;
        }
    }
}

new Megurio_Delete_Cart_For_WooCommerce();
