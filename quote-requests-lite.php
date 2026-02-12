<?php
/**
 * Plugin Name: Quote Requests Lite
 * Description: Sistema de cotizaciones sin precios. Productos se agregan a cotización sin redirección automática. Formulario con código de país para teléfono.
 * Version: 2.7.0
 * Author: Zadkiel
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

final class QRL_Quote_Requests_Lite_Fixed {
  const OPT_KEY = 'qrl_settings';
  const PAGE_OPT = 'qrl_quote_page_id';
  const RATE_OPT = 'qrl_rate_';

  public static function init() {
    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);

    // Admin
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);

    // Forzar que productos sean "purchasable" incluso sin precio
    add_filter('woocommerce_is_purchasable', [__CLASS__, 'force_purchasable_in_quote_context'], 999, 2);
    add_filter('woocommerce_product_is_in_stock', [__CLASS__, 'force_in_stock_in_quote_context'], 999, 2);
    add_filter('woocommerce_variation_is_purchasable', [__CLASS__, 'force_purchasable_in_quote_context'], 999, 2);

    // Suprimir precios en todo el sitio
    add_filter('woocommerce_get_price_html', [__CLASS__, 'hide_prices'], 999, 2);
    add_filter('woocommerce_cart_item_price', '__return_empty_string', 999);
    add_filter('woocommerce_cart_item_subtotal', '__return_empty_string', 999);

    // Front: botones en loop se convierten en "Agregar a cotización"
    add_filter('woocommerce_loop_add_to_cart_link', [__CLASS__, 'loop_button_link'], 999, 3);
    add_filter('woocommerce_product_add_to_cart_text', [__CLASS__, 'loop_button_text'], 999, 2);
    add_filter('woocommerce_product_single_add_to_cart_text', [__CLASS__, 'single_button_text'], 999);

    // Deshabilitar AJAX en add-to-cart para que los links funcionen como navegación normal
    add_action('wp_enqueue_scripts', [__CLASS__, 'disable_ajax_add_to_cart'], 999);

    // Single product: reemplazar botón de add-to-cart
    add_action('wp', [__CLASS__, 'replace_single_add_to_cart']);

    // Suprimir mensajes de WooCommerce y redirigir correctamente después de agregar
    add_filter('wc_add_to_cart_message_html', [__CLASS__, 'custom_add_to_cart_message'], 999, 2);
    add_filter('woocommerce_add_to_cart_redirect', [__CLASS__, 'prevent_redirect'], 999);

    // Block cart/checkout pages (redirigir a página de cotización)
    add_action('template_redirect', [__CLASS__, 'block_cart_and_checkout'], 2);

    // Manejar eliminación de items directamente en la página de cotización
    add_action('wp_loaded', [__CLASS__, 'handle_remove_item'], 10);

    // Prevenir cache en página de cotización para que siempre muestre datos frescos
    add_action('template_redirect', [__CLASS__, 'no_cache_quote_page'], 1);

    // Limpiar notificaciones viejas en página de cotización
    add_action('wp', [__CLASS__, 'clear_notices_on_quote_page'], 20);

    // Quotation page shortcode
    add_shortcode('qrl_quote_checkout', [__CLASS__, 'shortcode_quote_page']);

    // Procesar formulario de cotización
    add_action('init', [__CLASS__, 'handle_quote_form_submit']);

    // Orders: status "Quote requested"
    add_action('init', [__CLASS__, 'register_quote_status']);
    add_filter('wc_order_statuses', [__CLASS__, 'add_quote_status_to_list']);

    // CSS personalizado
    add_action('wp_head', [__CLASS__, 'maybe_inject_css'], 999);

    // Estilos para selector de país
    add_action('wp_head', [__CLASS__, 'add_phone_input_styles']);
  }

  /* =========================
   * Defaults / settings
   * ========================= */
  private static function defaults() {
    return [
      'button_text'            => 'Add to Quotation',
      'button_css_class'       => 'qrl-quote-btn',
      'view_quote_text'        => 'View Quotation',
      'submit_text'            => 'Send Quote Request',
      'continue_text'          => 'Continue browsing products',
      'continue_url'           => home_url('/'),
      'enable_project_details' => 1,
      'project_label'          => 'Additional message or specifications (optional)',
      'project_placeholder'    => 'Add any specifications, questions, or details about your quote request...',
      'internal_emails'        => get_option('admin_email'),
      'from_name'              => get_bloginfo('name'),
      'from_email'             => get_option('admin_email'),
      'subject_customer'       => 'We received your quote request',
      'subject_internal'       => 'New quote request received',
      'customer_template'      => self::default_customer_email_template(),
      'internal_template'      => self::default_internal_email_template(),
      'antispam_enabled'       => 1,
      'rate_limit_max'         => 3,
      'rate_limit_minutes'     => 10,
      'custom_css'             => '',
    ];
  }

  private static function get_settings() {
    $saved = get_option(self::OPT_KEY, []);
    return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
  }

  private static function update_settings($new) {
    update_option(self::OPT_KEY, $new);
  }

  private static function quote_page_id() {
    return (int) get_option(self::PAGE_OPT, 0);
  }

  private static function is_quote_page() {
    $id = self::quote_page_id();
    return ($id && function_exists('is_page') && is_page($id));
  }

  private static function quote_page_url() {
    $id = self::quote_page_id();
    if ($id) {
      $url = get_permalink($id);
      if ($url) return $url;
    }
    return home_url('/quotation/');
  }

  private static function quote_has_items() {
    return (function_exists('WC') && WC()->cart && !WC()->cart->is_empty());
  }

  /* =========================
   * Ocultar precios
   * ========================= */
  public static function hide_prices($price, $product) {
    return '';
  }

  /* =========================
   * Forzar purchasable
   * ========================= */
  public static function force_purchasable_in_quote_context($is_purchasable, $product) {
    return true;
  }

  public static function force_in_stock_in_quote_context($is_in_stock, $product) {
    return true;
  }

  /* =========================
   * FIX: Mensaje de add-to-cart correcto
   * ========================= */
  public static function custom_add_to_cart_message($message, $products) {
    $titles = array();
    foreach ($products as $product_id => $qty) {
      $product = wc_get_product($product_id);
      if ($product) {
        $titles[] = $product->get_name();
      }
    }

    $titles_text = implode(', ', $titles);
    $quote_url = self::quote_page_url();
    $s = self::get_settings();

    return sprintf(
      '%s added to your quotation. <a href="%s" class="button wc-forward">%s</a>',
      esc_html($titles_text),
      esc_url($quote_url),
      esc_html($s['view_quote_text'])
    );
  }

  /* =========================
   * FIX: Redirigir de vuelta a la página de origen después de agregar
   *
   * Antes retornaba '' (string vacío) lo que causaba que WooCommerce
   * no redirigiera, dejando ?add-to-cart=ID en la URL. Si el usuario
   * recargaba la página, el producto se agregaba de nuevo.
   *
   * Ahora retornamos la URL de referencia (de donde vino el usuario)
   * para completar el patrón POST-REDIRECT-GET correctamente.
   * ========================= */
  public static function prevent_redirect($url) {
    $referer = wp_get_referer();
    if ($referer) {
      return remove_query_arg(array('add-to-cart', 'added-to-cart'), $referer);
    }
    return false;
  }

  /* =========================
   * FIX: Deshabilitar AJAX en add-to-cart
   *
   * Antes se dequeaba el script de WC y se reemplazaba con un jQuery.get()
   * seguido de window.location.reload(). Esto creaba una race condition:
   * la recarga podía ocurrir antes de que WC terminara de agregar el producto.
   *
   * Ahora simplemente dequeamos el script de AJAX. Los links de add-to-cart
   * funcionan como links normales: el navegador navega a ?add-to-cart=ID,
   * WC procesa la adición, y nuestro filtro prevent_redirect redirige
   * de vuelta a la página de origen.
   * ========================= */
  public static function disable_ajax_add_to_cart() {
    wp_dequeue_script('wc-add-to-cart');
  }

  /* =========================
   * Loop buttons
   * ========================= */
  public static function loop_button_link($html, $product, $args) {
    $s = self::get_settings();

    $url = esc_url($product->add_to_cart_url());
    $text = $s['button_text'];
    $class = esc_attr($s['button_css_class'] . ' button add_to_cart_button');

    return sprintf(
      '<a href="%s" data-quantity="1" class="%s" data-product_id="%s" data-product_sku="%s" rel="nofollow">%s</a>',
      $url,
      $class,
      esc_attr($product->get_id()),
      esc_attr($product->get_sku()),
      esc_html($text)
    );
  }

  public static function loop_button_text($text, $product) {
    $s = self::get_settings();
    return $s['button_text'];
  }

  public static function single_button_text($text) {
    $s = self::get_settings();
    return $s['button_text'];
  }

  /* =========================
   * Single product: custom buttons
   * ========================= */
  public static function replace_single_add_to_cart() {
    if (!is_product()) return;

    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    add_action('woocommerce_single_product_summary', [__CLASS__, 'custom_single_buttons'], 30);
  }

  public static function custom_single_buttons() {
    global $product;
    if (!$product) return;

    $s = self::get_settings();
    $add_url = esc_url($product->add_to_cart_url());
    $quote_url = self::quote_page_url();
    $has_items = self::quote_has_items();

    ?>
    <div class="qrl-quote-buttons">
      <a href="<?php echo $add_url; ?>"
         class="<?php echo esc_attr($s['button_css_class']); ?> button single_add_to_cart_button"
         data-product_id="<?php echo esc_attr($product->get_id()); ?>"
         data-product_sku="<?php echo esc_attr($product->get_sku()); ?>"
         data-quantity="1">
        <?php echo esc_html($s['button_text']); ?>
      </a>

      <a href="<?php echo esc_url($quote_url); ?>"
         class="qrl-view-quote-btn button <?php echo $has_items ? '' : 'disabled'; ?>"
         <?php echo $has_items ? '' : 'onclick="return false;" style="opacity:0.5;cursor:not-allowed;"'; ?>>
        <?php echo esc_html($s['view_quote_text']); ?>
      </a>
    </div>
    <?php
  }

  /* =========================
   * Block cart and checkout
   * ========================= */
  public static function block_cart_and_checkout() {
    if (!function_exists('is_cart') || !function_exists('is_checkout')) return;

    if (is_cart() || is_checkout()) {
      wp_redirect(self::quote_page_url());
      exit;
    }
  }

  /* =========================
   * FIX: Manejar eliminación de items en la página de cotización
   *
   * Antes se usaba wc_get_cart_remove_url() que genera links a /cart/.
   * El flujo era: click remove -> navegar a /cart/?remove_item=KEY ->
   * WC procesa removal en wp_loaded -> WC redirige -> nuestro
   * block_cart_and_checkout redirige de nuevo a /quotation/.
   *
   * El problema: en esta cadena de redirecciones dobles, la sesión de
   * WooCommerce no siempre guarda los cambios del carrito correctamente.
   * Al refrescar, la sesión vieja se restaura y el item "eliminado" reaparece.
   *
   * Solución: usar nuestros propios links de eliminación que apuntan
   * directamente a /quotation/?qrl_remove_item=KEY&qrl_nonce=NONCE.
   * Procesamos la eliminación aquí, forzamos el guardado de sesión,
   * y redirigimos limpiamente a /quotation/.
   * ========================= */
  public static function handle_remove_item() {
    if (empty($_GET['qrl_remove_item'])) return;
    if (!function_exists('WC') || !WC()->cart) return;

    $cart_item_key = sanitize_text_field(wp_unslash($_GET['qrl_remove_item']));

    // Verificar nonce
    $nonce = $_GET['qrl_nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'qrl_remove_' . $cart_item_key)) {
      wc_add_notice('Invalid security token. Please try again.', 'error');
      wp_safe_redirect(self::quote_page_url());
      exit;
    }

    // Obtener info del producto antes de eliminarlo (para el mensaje)
    $cart_item = WC()->cart->get_cart_item($cart_item_key);
    $product_name = '';
    if ($cart_item && isset($cart_item['data'])) {
      $product_name = $cart_item['data']->get_name();
    }

    // Eliminar el item del carrito
    $removed = WC()->cart->remove_cart_item($cart_item_key);

    if ($removed) {
      // Forzar recalcular totales (esto también actualiza la sesión)
      WC()->cart->calculate_totals();

      // Forzar guardado explícito de la sesión AHORA, no esperar a shutdown
      if (WC()->session) {
        WC()->session->save_data();
      }

      if ($product_name) {
        wc_add_notice(sprintf('"%s" has been removed from your quotation.', esc_html($product_name)), 'success');
      }
    }

    // Redirigir a URL limpia (sin parámetros de eliminación)
    wp_safe_redirect(self::quote_page_url());
    exit;
  }

  /* =========================
   * Prevenir cache en página de cotización
   *
   * Si un plugin de cache (WP Super Cache, W3 Total Cache, LiteSpeed,
   * etc.) cachea la página de cotización, el usuario verá datos viejos
   * del carrito. Esto previene ese problema.
   * ========================= */
  public static function no_cache_quote_page() {
    if (!self::is_quote_page()) return;

    // Headers estándar de no-cache
    nocache_headers();

    // Constante que respetan la mayoría de plugins de cache
    if (!defined('DONOTCACHEPAGE')) {
      define('DONOTCACHEPAGE', true);
    }
  }

  /* =========================
   * Clear notices on quote page
   * ========================= */
  public static function clear_notices_on_quote_page() {
    if (!self::is_quote_page()) return;
    if (function_exists('wc_clear_notices')) {
      wc_clear_notices();
    }
  }

  /* =========================
   * Estilos para input de teléfono y JS del formulario
   * ========================= */
  public static function add_phone_input_styles() {
    if (!self::is_quote_page()) return;
    ?>
    <style>
      .qrl-phone-input-wrapper {
        display: flex;
        gap: 10px;
        align-items: flex-start;
      }
      .qrl-phone-input-wrapper input[type="tel"] {
        flex: 1;
      }
      .qrl-phone-input-wrapper input.qrl-country-code {
        width: 100px;
        flex: 0 0 100px;
      }
      .qrl-quote-form label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
      }
      .qrl-quote-form input[type="text"],
      .qrl-quote-form input[type="email"],
      .qrl-quote-form input[type="tel"],
      .qrl-quote-form textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
      }
      .qrl-quote-form textarea {
        min-height: 100px;
        resize: vertical;
      }
      .qrl-form-row {
        margin-bottom: 15px;
      }
      .qrl-quote-buttons a {
        margin-right: 10px;
      }
      .qrl-empty-quote {
        text-align: center;
        padding: 40px 20px;
        background: #f9f9f9;
        border-radius: 8px;
        margin: 20px 0;
      }
    </style>

    <script>
    jQuery(document).ready(function($) {
      // Prevenir doble envío del formulario
      var formSubmitted = false;
      $('.qrl-quote-form').on('submit', function(e) {
        if (formSubmitted) {
          e.preventDefault();
          return false;
        }
        formSubmitted = true;
        $(this).find('button[type="submit"]').prop('disabled', true).text('Sending...');
      });
    });
    </script>
    <?php
  }

  /* =========================
   * FIX: Quotation page shortcode
   *
   * Antes este método destruía el carrito en cada carga:
   *   WC()->cart = null;
   *   WC()->initialize_cart();
   *   WC()->session->set('cart', null);  <-- ESTO BORRABA TODO
   *
   * Esto causaba que los productos agregados desaparecieran al visitar
   * la página de cotización. Ahora simplemente usamos el carrito tal
   * como está, sin manipular la sesión.
   * ========================= */
  public static function shortcode_quote_page($atts) {
    if (!function_exists('WC') || !WC()->cart) {
      return '<p>WooCommerce is not active.</p>';
    }

    $s = self::get_settings();
    $cart = WC()->cart;

    ob_start();

    if ($cart->is_empty()) {
      ?>
      <div class="qrl-empty-quote">
        <h2>Your quotation is empty</h2>
        <p>Please add items to your quotation to continue.</p>
        <a href="<?php echo esc_url($s['continue_url']); ?>" class="button">
          <?php echo esc_html($s['continue_text']); ?>
        </a>
      </div>
      <?php
    } else {
      ?>
      <div class="qrl-quote-page">
        <h2>Your Quotation</h2>

        <table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents">
          <thead>
            <tr>
              <th class="product-remove">&nbsp;</th>
              <th class="product-name">Product</th>
              <th class="product-sku">SKU</th>
            </tr>
          </thead>
          <tbody>
            <?php
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
              $_product = $cart_item['data'];
              if (!$_product || !$_product->exists()) continue;

              $product_name = $_product->get_name();
              $product_sku = $_product->get_sku() ?: '—';
              $product_permalink = $_product->is_visible() ? $_product->get_permalink() : '';
              // URL de eliminación apunta a la MISMA página de cotización (no a /cart/)
              $remove_url = wp_nonce_url(
                add_query_arg('qrl_remove_item', $cart_item_key, self::quote_page_url()),
                'qrl_remove_' . $cart_item_key,
                'qrl_nonce'
              );
              ?>
              <tr class="woocommerce-cart-form__cart-item cart_item">
                <td class="product-remove">
                  <a href="<?php echo esc_url($remove_url); ?>"
                     class="remove"
                     aria-label="<?php esc_attr_e('Remove this item', 'woocommerce'); ?>"
                     data-product_id="<?php echo esc_attr($_product->get_id()); ?>">&times;</a>
                </td>
                <td class="product-name" data-title="Product">
                  <?php
                  if ($product_permalink) {
                    echo '<a href="' . esc_url($product_permalink) . '">' . esc_html($product_name) . '</a>';
                  } else {
                    echo esc_html($product_name);
                  }
                  ?>
                </td>
                <td class="product-sku" data-title="SKU">
                  <?php echo esc_html($product_sku); ?>
                </td>
              </tr>
              <?php
            }
            ?>
          </tbody>
        </table>

        <h3>Contact Information</h3>
        <form method="post" class="qrl-quote-form">
          <input type="hidden" name="qrl_submit_quote" value="1" />
          <?php wp_nonce_field('qrl_quote_submit', 'qrl_quote_nonce'); ?>

          <div class="qrl-form-row">
            <label for="qrl_name">Name <span class="required">*</span></label>
            <input type="text" id="qrl_name" name="qrl_name" required />
          </div>

          <div class="qrl-form-row">
            <label for="qrl_email">Email <span class="required">*</span></label>
            <input type="email" id="qrl_email" name="qrl_email" required />
          </div>

          <div class="qrl-form-row">
            <label for="qrl_phone">Phone <span class="required">*</span></label>
            <div class="qrl-phone-input-wrapper">
              <input type="text"
                     id="qrl_country_code"
                     name="qrl_country_code"
                     class="qrl-country-code"
                     placeholder="+1"
                     pattern="\+[0-9]{1,4}"
                     title="Country code (e.g., +1, +52, +44)"
                     required />
              <input type="tel"
                     id="qrl_phone"
                     name="qrl_phone"
                     placeholder="Phone number"
                     required />
            </div>
            <small>Enter your country code (e.g., +1 for USA, +52 for Mexico, +44 for UK) and phone number</small>
          </div>

          <?php if ($s['enable_project_details']): ?>
          <div class="qrl-form-row">
            <label for="qrl_project"><?php echo esc_html($s['project_label']); ?></label>
            <textarea id="qrl_project"
                      name="qrl_project"
                      placeholder="<?php echo esc_attr($s['project_placeholder']); ?>"></textarea>
          </div>
          <?php endif; ?>

          <div class="qrl-form-row">
            <button type="submit" class="button alt">
              <?php echo esc_html($s['submit_text']); ?>
            </button>
          </div>
        </form>
      </div>
      <?php
    }

    return ob_get_clean();
  }

  /* =========================
   * FIX: Handle quote form submit
   *
   * Correcciones:
   * 1. La detección de duplicados ahora usa IP+email en vez de
   *    get_current_user_id() que retorna 0 para todos los invitados.
   * 2. Se removió la manipulación excesiva del carrito después de
   *    empty_cart(). empty_cart(true) ya limpia todo correctamente.
   * 3. Se removieron las queries SQL destructivas y wp_cache_flush().
   * ========================= */
  public static function handle_quote_form_submit() {
    if (!isset($_POST['qrl_submit_quote']) || (int)$_POST['qrl_submit_quote'] !== 1) return;
    if (!isset($_POST['qrl_quote_nonce']) || !wp_verify_nonce($_POST['qrl_quote_nonce'], 'qrl_quote_submit')) return;

    $s = self::get_settings();

    // Get form data early for duplicate detection
    $name = sanitize_text_field($_POST['qrl_name'] ?? '');
    $email = sanitize_email($_POST['qrl_email'] ?? '');
    $country_code = sanitize_text_field($_POST['qrl_country_code'] ?? '');
    $phone = sanitize_text_field($_POST['qrl_phone'] ?? '');
    $project = sanitize_textarea_field($_POST['qrl_project'] ?? '');

    // Validate required fields
    if (!$name || !is_email($email) || !$country_code || !$phone) {
      wc_add_notice('Please fill in all required fields.', 'error');
      return;
    }

    // Prevenir envíos duplicados usando IP + email como clave única
    $submission_key = 'qrl_submitted_' . md5(self::get_client_ip() . $email);
    $last_submission = get_transient($submission_key);
    if ($last_submission && (time() - $last_submission) < 10) {
      wc_add_notice('Please wait before submitting again.', 'error');
      return;
    }

    // Anti-spam check
    if ($s['antispam_enabled']) {
      if (self::is_rate_limited()) {
        wc_add_notice('You are submitting too many requests. Please try again later.', 'error');
        return;
      }
    }

    // Validate cart
    if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
      wc_add_notice('Your quotation is empty.', 'error');
      return;
    }

    // Combine country code and phone
    $full_phone = trim($country_code) . ' ' . trim($phone);

    // Create WooCommerce order
    $order = wc_create_order();
    if (!$order) {
      wc_add_notice('Failed to create quote request. Please try again.', 'error');
      return;
    }

    // Add cart items to order
    foreach (WC()->cart->get_cart() as $cart_item) {
      $product = $cart_item['data'];
      $order->add_product($product, $cart_item['quantity']);
    }

    // Set billing info
    $order->set_billing_first_name($name);
    $order->set_billing_email($email);
    $order->set_billing_phone($full_phone);

    // Save custom meta
    $order->update_meta_data('_qrl_customer_name', $name);
    $order->update_meta_data('_qrl_customer_email', $email);
    $order->update_meta_data('_qrl_customer_phone', $full_phone);
    $order->update_meta_data('_qrl_project_details', $project);

    // Set status
    $order->set_status('wc-quote-requested');
    $order->save();

    // Marcar envío para prevenir duplicados
    set_transient($submission_key, time(), 60);

    // Send emails
    self::send_customer_email($order);
    self::send_internal_email($order);

    // Bump rate limit
    if ($s['antispam_enabled']) {
      self::bump_rate_limit();
    }

    // Vaciar carrito - empty_cart(true) limpia carrito, sesión persistente y cookies
    WC()->cart->empty_cart(true);

    // Redirect with success
    wc_add_notice('Your quote request has been sent successfully! We will contact you soon.', 'success');
    wp_redirect(self::quote_page_url());
    exit;
  }

  /* =========================
   * Activation: create page
   * ========================= */
  public static function on_activate() {
    if (!class_exists('WooCommerce')) return;

    $existing_id = self::quote_page_id();
    if (!$existing_id || !get_post($existing_id)) {
      $page = [
        'post_title'   => 'Quotation',
        'post_name'    => 'quotation',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => "[qrl_quote_checkout]\n",
      ];
      $page_id = wp_insert_post($page);
      if (!is_wp_error($page_id) && $page_id) {
        update_option(self::PAGE_OPT, (int)$page_id);
      }
    }

    if (!get_option(self::OPT_KEY, false)) {
      self::update_settings(self::defaults());
    }
  }

  /* =========================
   * Admin menu (WooCommerce)
   * ========================= */
  public static function admin_menu() {
    if (!current_user_can('manage_options')) return;

    add_submenu_page(
      'woocommerce',
      'Quote Requests',
      'Quote Requests',
      'manage_options',
      'qrl-quote-requests',
      [__CLASS__, 'settings_page']
    );
  }

  public static function register_settings() {
    register_setting('qrl_quote_requests', self::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
      'default' => self::defaults(),
    ]);
  }

  public static function sanitize_settings($input) {
    $d = self::defaults();
    $out = [];

    $out['button_text']        = sanitize_text_field($input['button_text'] ?? $d['button_text']);
    $out['button_css_class']   = sanitize_text_field($input['button_css_class'] ?? $d['button_css_class']);
    $out['view_quote_text']    = sanitize_text_field($input['view_quote_text'] ?? $d['view_quote_text']);

    $out['submit_text']        = sanitize_text_field($input['submit_text'] ?? $d['submit_text']);
    $out['continue_text']      = sanitize_text_field($input['continue_text'] ?? $d['continue_text']);
    $out['continue_url']       = esc_url_raw($input['continue_url'] ?? $d['continue_url']);

    $out['enable_project_details'] = !empty($input['enable_project_details']) ? 1 : 0;
    $out['project_label']          = sanitize_text_field($input['project_label'] ?? $d['project_label']);
    $out['project_placeholder']    = sanitize_text_field($input['project_placeholder'] ?? $d['project_placeholder']);

    $out['internal_emails'] = sanitize_textarea_field($input['internal_emails'] ?? $d['internal_emails']);
    $out['from_name']       = sanitize_text_field($input['from_name'] ?? $d['from_name']);
    $out['from_email']      = sanitize_email($input['from_email'] ?? $d['from_email']);

    $out['subject_customer']  = sanitize_text_field($input['subject_customer'] ?? $d['subject_customer']);
    $out['subject_internal']  = sanitize_text_field($input['subject_internal'] ?? $d['subject_internal']);

    $out['customer_template'] = wp_kses_post($input['customer_template'] ?? $d['customer_template']);
    $out['internal_template'] = wp_kses_post($input['internal_template'] ?? $d['internal_template']);

    $out['antispam_enabled']     = !empty($input['antispam_enabled']) ? 1 : 0;
    $out['rate_limit_max']       = absint($input['rate_limit_max'] ?? $d['rate_limit_max']);
    $out['rate_limit_minutes']   = absint($input['rate_limit_minutes'] ?? $d['rate_limit_minutes']);

    $out['custom_css'] = sanitize_textarea_field($input['custom_css'] ?? $d['custom_css']);

    return $out;
  }

  public static function settings_page() {
    if (!current_user_can('manage_options')) return;

    $s = self::get_settings();
    $page_id = self::quote_page_id();
    $page_url = self::quote_page_url();

    ?>
    <div class="wrap">
      <h1>QRL Quote Requests — Settings</h1>
      <form method="post" action="options.php">
        <?php settings_fields('qrl_quote_requests'); ?>
        <table class="form-table">
          <tr>
            <th colspan="2"><h2>General</h2></th>
          </tr>
          <tr>
            <th>Quotation Page</th>
            <td>
              <?php if ($page_id && get_post($page_id)): ?>
                <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>" target="_blank">Edit page</a> |
                <a href="<?php echo esc_url($page_url); ?>" target="_blank">View page</a>
              <?php else: ?>
                <em>No page found. Re-activate plugin to create.</em>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th>Button text (Add to Quotation)</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[button_text]" value="<?php echo esc_attr($s['button_text']); ?>" style="width:300px;"/></td>
          </tr>
          <tr>
            <th>View Quotation button text</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[view_quote_text]" value="<?php echo esc_attr($s['view_quote_text']); ?>" style="width:300px;"/></td>
          </tr>
          <tr>
            <th>Button CSS class</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[button_css_class]" value="<?php echo esc_attr($s['button_css_class']); ?>" style="width:300px;"/></td>
          </tr>

          <tr>
            <th colspan="2"><h2>Form</h2></th>
          </tr>
          <tr>
            <th>Submit button text</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[submit_text]" value="<?php echo esc_attr($s['submit_text']); ?>" style="width:300px;"/></td>
          </tr>
          <tr>
            <th>Continue button text</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[continue_text]" value="<?php echo esc_attr($s['continue_text']); ?>" style="width:300px;"/></td>
          </tr>
          <tr>
            <th>Continue URL</th>
            <td><input type="url" name="<?php echo self::OPT_KEY; ?>[continue_url]" value="<?php echo esc_url($s['continue_url']); ?>" style="width:400px;"/></td>
          </tr>
          <tr>
            <th>Enable project details field?</th>
            <td><label><input type="checkbox" name="<?php echo self::OPT_KEY; ?>[enable_project_details]" value="1" <?php checked($s['enable_project_details'], 1); ?>/> Yes</label></td>
          </tr>
          <tr>
            <th>Project details label</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[project_label]" value="<?php echo esc_attr($s['project_label']); ?>" style="width:400px;"/></td>
          </tr>
          <tr>
            <th>Project details placeholder</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[project_placeholder]" value="<?php echo esc_attr($s['project_placeholder']); ?>" style="width:400px;"/></td>
          </tr>

          <tr>
            <th colspan="2"><h2>Emails</h2></th>
          </tr>
          <tr>
            <th>Internal recipients (comma-separated)</th>
            <td><textarea name="<?php echo self::OPT_KEY; ?>[internal_emails]" rows="2" style="width:400px;"><?php echo esc_textarea($s['internal_emails']); ?></textarea></td>
          </tr>
          <tr>
            <th>From name</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[from_name]" value="<?php echo esc_attr($s['from_name']); ?>" style="width:300px;"/></td>
          </tr>
          <tr>
            <th>From email</th>
            <td><input type="email" name="<?php echo self::OPT_KEY; ?>[from_email]" value="<?php echo esc_attr($s['from_email']); ?>" style="width:300px;"/></td>
          </tr>
          <tr>
            <th>Subject (customer)</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[subject_customer]" value="<?php echo esc_attr($s['subject_customer']); ?>" style="width:400px;"/></td>
          </tr>
          <tr>
            <th>Email template (customer)</th>
            <td>
              <textarea name="<?php echo self::OPT_KEY; ?>[customer_template]" rows="10" style="width:100%;font-family:monospace;"><?php echo esc_textarea($s['customer_template']); ?></textarea>
              <p><small>Available placeholders: {{customer_name}}, {{customer_email}}, {{customer_phone}}, {{quote_id}}, {{quote_date}}, {{items_table}}, {{project_details}}, {{site_name}}</small></p>
            </td>
          </tr>
          <tr>
            <th>Subject (internal)</th>
            <td><input type="text" name="<?php echo self::OPT_KEY; ?>[subject_internal]" value="<?php echo esc_attr($s['subject_internal']); ?>" style="width:400px;"/></td>
          </tr>
          <tr>
            <th>Email template (internal)</th>
            <td>
              <textarea name="<?php echo self::OPT_KEY; ?>[internal_template]" rows="10" style="width:100%;font-family:monospace;"><?php echo esc_textarea($s['internal_template']); ?></textarea>
              <p><small>Available placeholders: {{customer_name}}, {{customer_email}}, {{customer_phone}}, {{quote_id}}, {{quote_date}}, {{items_table}}, {{project_details}}, {{admin_link}}, {{site_name}}</small></p>
            </td>
          </tr>

          <tr>
            <th colspan="2"><h2>Anti-spam / Rate limiting</h2></th>
          </tr>
          <tr>
            <th>Enable anti-spam?</th>
            <td><label><input type="checkbox" name="<?php echo self::OPT_KEY; ?>[antispam_enabled]" value="1" <?php checked($s['antispam_enabled'], 1); ?>/> Yes</label></td>
          </tr>
          <tr>
            <th>Max submissions per IP</th>
            <td><input type="number" name="<?php echo self::OPT_KEY; ?>[rate_limit_max]" value="<?php echo esc_attr($s['rate_limit_max']); ?>" min="1" max="20"/></td>
          </tr>
          <tr>
            <th>Time window (minutes)</th>
            <td><input type="number" name="<?php echo self::OPT_KEY; ?>[rate_limit_minutes]" value="<?php echo esc_attr($s['rate_limit_minutes']); ?>" min="1" max="120"/></td>
          </tr>

          <tr>
            <th colspan="2"><h2>Custom CSS</h2></th>
          </tr>
          <tr>
            <th>CSS code</th>
            <td><textarea name="<?php echo self::OPT_KEY; ?>[custom_css]" rows="10" style="width:100%;font-family:monospace;"><?php echo esc_textarea($s['custom_css']); ?></textarea></td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  /* =========================
   * Anti-spam / Rate limiting
   * ========================= */
  private static function get_client_ip() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
      $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
      $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  private static function is_rate_limited() {
    $s = self::get_settings();
    $ip = self::get_client_ip();
    if (!$ip) $ip = 'unknown';

    $key = self::RATE_OPT . md5($ip);
    $count = (int) get_transient($key);
    $max = (int) $s['rate_limit_max'];

    return ($count >= $max);
  }

  private static function bump_rate_limit() {
    $s = self::get_settings();
    $ip = self::get_client_ip();
    if (!$ip) $ip = 'unknown';

    $key = self::RATE_OPT . md5($ip);
    $count = (int) get_transient($key);
    $count++;
    $minutes = (int) $s['rate_limit_minutes'];
    set_transient($key, $count, $minutes * MINUTE_IN_SECONDS);
  }

  /* =========================
   * Order status
   * ========================= */
  public static function register_quote_status() {
    register_post_status('wc-quote-requested', [
      'label'                     => _x('Quote requested', 'Order status', 'qrl'),
      'public'                    => true,
      'exclude_from_search'       => false,
      'show_in_admin_all_list'    => true,
      'show_in_admin_status_list' => true,
      'label_count'               => _n_noop('Quote requested <span class="count">(%s)</span>', 'Quote requested <span class="count">(%s)</span>', 'qrl'),
    ]);
  }

  public static function add_quote_status_to_list($order_statuses) {
    $new = [];
    foreach ($order_statuses as $k => $label) {
      $new[$k] = $label;
      if ($k === 'wc-pending') {
        $new['wc-quote-requested'] = _x('Quote requested', 'Order status', 'qrl');
      }
    }
    if (!isset($new['wc-quote-requested'])) {
      $new['wc-quote-requested'] = _x('Quote requested', 'Order status', 'qrl');
    }
    return $new;
  }

  /* =========================
   * Emails
   * ========================= */
  private static function build_headers($s) {
    $from_email = is_email($s['from_email']) ? $s['from_email'] : get_option('admin_email');
    $from_name  = $s['from_name'] ?: get_bloginfo('name');
    $headers = [];
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . sprintf('%s <%s>', $from_name, $from_email);
    return $headers;
  }

  private static function items_table_html($order) {
    $rows = '';
    foreach ($order->get_items() as $item) {
      $name = esc_html($item->get_name());
      $product = $item->get_product();
      $sku = '—';
      $link = '';

      if ($product) {
        $sku_val = $product->get_sku();
        if ($sku_val) $sku = esc_html($sku_val);
        $plink = get_permalink($product->get_id());
        if ($plink) $link = esc_url($plink);
      }

      $rows .= '<tr>';
      $rows .= '<td style="padding:8px;border:1px solid #e5e7eb;">' . ($link ? '<a href="'.$link.'">'.$name.'</a>' : $name) . '</td>';
      $rows .= '<td style="padding:8px;border:1px solid #e5e7eb;">' . $sku . '</td>';
      $rows .= '</tr>';
    }

    return '
      <table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb;">Item</th>
            <th style="text-align:left;padding:8px;border:1px solid #e5e7eb;background:#f9fafb;">SKU</th>
          </tr>
        </thead>
        <tbody>'.$rows.'</tbody>
      </table>
    ';
  }

  private static function build_placeholders($order) {
    $name = (string) get_post_meta($order->get_id(), '_qrl_customer_name', true);
    $email = (string) get_post_meta($order->get_id(), '_qrl_customer_email', true);
    $phone = (string) get_post_meta($order->get_id(), '_qrl_customer_phone', true);
    $project = (string) get_post_meta($order->get_id(), '_qrl_project_details', true);

    $items_table = self::items_table_html($order);
    $project_safe = $project !== '' ? nl2br(esc_html($project)) : '<em>—</em>';
    $admin_link = esc_url(get_edit_post_link($order->get_id(), ''));

    return [
      '{{customer_name}}'   => esc_html($name ?: trim($order->get_billing_first_name().' '.$order->get_billing_last_name())),
      '{{customer_email}}'  => esc_html($email ?: $order->get_billing_email()),
      '{{customer_phone}}'  => esc_html($phone ?: $order->get_billing_phone()),
      '{{quote_id}}'        => esc_html((string)$order->get_order_number()),
      '{{quote_date}}'      => esc_html(wc_format_datetime($order->get_date_created())),
      '{{items_table}}'     => $items_table,
      '{{project_details}}' => $project_safe,
      '{{admin_link}}'      => $admin_link ? '<a href="'.$admin_link.'">Open quote in admin</a>' : '',
      '{{site_name}}'       => esc_html(get_bloginfo('name')),
    ];
  }

  private static function replace_placeholders($text, $repl) {
    return strtr((string)$text, $repl);
  }

  private static function send_customer_email($order) {
    $s = self::get_settings();
    $to = (string) get_post_meta($order->get_id(), '_qrl_customer_email', true);
    if (!is_email($to)) $to = $order->get_billing_email();
    if (!is_email($to)) return;

    $repl = self::build_placeholders($order);
    $subject = self::replace_placeholders($s['subject_customer'], $repl);
    $body = self::replace_placeholders($s['customer_template'], $repl);

    wp_mail($to, $subject, $body, self::build_headers($s));
  }

  private static function send_internal_email($order) {
    $s = self::get_settings();
    $to_raw = trim((string)$s['internal_emails']);
    if ($to_raw === '') return;

    $tos = array_filter(array_map('trim', explode(',', $to_raw)), function($e){
      return is_email($e);
    });
    if (empty($tos)) return;

    $repl = self::build_placeholders($order);
    $subject = self::replace_placeholders($s['subject_internal'], $repl);
    $body = self::replace_placeholders($s['internal_template'], $repl);

    wp_mail($tos, $subject, $body, self::build_headers($s));
  }

  private static function default_customer_email_template() {
    return '
<div style="font-family:Arial,sans-serif;color:#111827;line-height:1.5;">
  <h2 style="margin:0 0 12px;">Thanks — we received your quote request</h2>
  <p style="margin:0 0 12px;">Hi {{customer_name}},</p>
  <p style="margin:0 0 16px;">
    We have received your quotation request (#{{quote_id}}) on {{quote_date}}.
    Our sales team will review it and contact you shortly.
  </p>
  <h3 style="margin:18px 0 10px;">Requested items</h3>
  {{items_table}}
  <h3 style="margin:18px 0 10px;">Your message</h3>
  <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
    {{project_details}}
  </div>
  <p style="margin:18px 0 0;color:#6b7280;font-size:12px;">
    {{site_name}}
  </p>
</div>';
  }

  private static function default_internal_email_template() {
    return '
<div style="font-family:Arial,sans-serif;color:#111827;line-height:1.5;">
  <h2 style="margin:0 0 12px;">New quote request received</h2>
  <p style="margin:0 0 8px;"><strong>Quote:</strong> #{{quote_id}} ({{quote_date}})</p>
  <p style="margin:0 0 8px;"><strong>Name:</strong> {{customer_name}}</p>
  <p style="margin:0 0 8px;"><strong>Email:</strong> {{customer_email}}</p>
  <p style="margin:0 0 16px;"><strong>Phone:</strong> {{customer_phone}}</p>
  <h3 style="margin:18px 0 10px;">Items</h3>
  {{items_table}}
  <h3 style="margin:18px 0 10px;">Customer message</h3>
  <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#fafafa;">
    {{project_details}}
  </div>
  <p style="margin:18px 0 0;">{{admin_link}}</p>
  <p style="margin:10px 0 0;color:#6b7280;font-size:12px;">{{site_name}}</p>
</div>';
  }

  /* =========================
   * Custom CSS injection
   * ========================= */
  public static function maybe_inject_css() {
    $s = self::get_settings();
    if (empty($s['custom_css'])) return;
    echo "<style id='qrl-custom-css'>\n" . $s['custom_css'] . "\n</style>";
  }
}

QRL_Quote_Requests_Lite_Fixed::init();
