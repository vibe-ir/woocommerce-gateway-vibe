/**
 * Vibe Payment Integration JavaScript
 *
 * Handles specific payment method integration for dynamic pricing.
 *
 * @package WooCommerce Vibe Payment Gateway
 * @since 1.1.0
 */

(function ($) {
  "use strict";

  var VibePaymentIntegration = {
    init: function () {
      this.bindEvents();
      this.setupInitialState();
    },

    bindEvents: function () {
      // Enhanced payment method change handling
      $("body").on(
        "change",
        'input[name="payment_method"]',
        this.handlePaymentMethodChange.bind(this)
      );

      // Handle checkout form updates
      $(document).on(
        "checkout_place_order",
        this.handleCheckoutSubmit.bind(this)
      );

      // Handle cart updates
      $("body").on("updated_wc_div", this.handleCartUpdate.bind(this));
    },

    setupInitialState: function () {
      // Set initial payment method if available
      var initialPaymentMethod = $(
        'input[name="payment_method"]:checked'
      ).val();
      if (initialPaymentMethod) {
        this.updateDynamicPricing(initialPaymentMethod);
      }
    },

    handlePaymentMethodChange: function (event) {
      var paymentMethod = $(event.target).val();
      this.updateDynamicPricing(paymentMethod);
    },

    updateDynamicPricing: function (paymentMethod) {
      // Collect product IDs
      var productIds = this.collectProductIds();

      if (productIds.length === 0) {
        return;
      }

      // Show loading state
      this.showLoadingIndicator();

      // Make AJAX request
      $.ajax({
        url: vibe_payment_integration.ajax_url,
        type: "POST",
        data: {
          action: "vibe_update_payment_prices",
          nonce: vibe_payment_integration.nonce,
          payment_method: paymentMethod,
          product_ids: productIds,
        },
        success: this.handleUpdateSuccess.bind(this),
        error: this.handleUpdateError.bind(this),
        complete: this.hideLoadingIndicator.bind(this),
      });
    },

    collectProductIds: function () {
      var productIds = [];

      // From price containers
      $(".vibe-dynamic-price-container").each(function () {
        var productId = $(this).data("product-id");
        if (productId && productIds.indexOf(productId) === -1) {
          productIds.push(productId);
        }
      });

      // From cart/checkout items
      $(".cart_item, .order_item").each(function () {
        var $item = $(this);
        var productId =
          $item.find("[data-product-id]").data("product-id") ||
          $item.data("product-id") ||
          $item.find(".product-name a").attr("href");

        if (productId) {
          // Extract ID from href if needed
          if (
            typeof productId === "string" &&
            productId.indexOf("product") !== -1
          ) {
            var matches = productId.match(/product\/.*?\/(\d+)/);
            if (matches) {
              productId = parseInt(matches[1]);
            }
          }

          productId = parseInt(productId);
          if (productId && productIds.indexOf(productId) === -1) {
            productIds.push(productId);
          }
        }
      });

      return productIds;
    },

    handleUpdateSuccess: function (response) {
      if (response.success && response.data) {
        this.updatePriceDisplays(response.data);
        this.triggerCheckoutUpdate();

        // Log for debugging
        if (vibe_payment_integration.debug) {
          console.log("Dynamic prices updated:", response.data);
        }
      }
    },

    handleUpdateError: function (xhr, status, error) {
      console.error("Failed to update dynamic prices:", error);

      // Show user-friendly error if needed
      this.showNotice(
        "بروزرسانی قیمت‌ها ناموفق بود. لطفا صفحه را رفرش کنید.",
        "error"
      );
    },

    updatePriceDisplays: function (priceData) {
      var self = this;

      $.each(priceData, function (productId, data) {
        self.updateProductPrice(productId, data);
      });
    },

    updateProductPrice: function (productId, data) {
      // Update main price containers
      var containers = $(
        '.vibe-dynamic-price-container[data-product-id="' + productId + '"]'
      );

      if (data.html && containers.length) {
        containers.each(function () {
          $(this).replaceWith(data.html);
        });
      }

      // Update other price references
      this.updateCartItemPrices(productId, data);
      this.updateCheckoutItemPrices(productId, data);
    },

    updateCartItemPrices: function (productId, data) {
      $(".cart_item").each(function () {
        var $item = $(this);
        var itemProductId = $item.find("[data-product-id]").data("product-id");

        if (itemProductId == productId && data.html) {
          // Update price display in cart
          var $priceCell = $item.find(".product-price, .product-subtotal");
          if ($priceCell.length) {
            $priceCell.html(data.html);
          }
        }
      });
    },

    updateCheckoutItemPrices: function (productId, data) {
      $(".order_item").each(function () {
        var $item = $(this);
        var itemProductId = $item.find("[data-product-id]").data("product-id");

        if (itemProductId == productId && data.html) {
          // Update price display in checkout
          var $priceCell = $item.find(".product-total");
          if ($priceCell.length) {
            $priceCell.html(data.html);
          }
        }
      });
    },

    triggerCheckoutUpdate: function () {
      // Trigger WooCommerce checkout update to recalculate totals
      if ($("body.woocommerce-checkout").length) {
        $("body").trigger("update_checkout");
      }
    },

    showLoadingIndicator: function () {
      if (!$(".vibe-payment-loading").length) {
        $(
          '<div class="vibe-payment-loading">بروزرسانی قیمت‌ها...</div>'
        ).appendTo("body");
      }
    },

    hideLoadingIndicator: function () {
      $(".vibe-payment-loading").remove();
    },

    showNotice: function (message, type) {
      type = type || "info";

      var notice = $(
        '<div class="vibe-notice vibe-notice-' +
          type +
          '">' +
          message +
          "</div>"
      );

      // Find appropriate location
      var target = $(
        ".woocommerce-notices-wrapper, .entry-content, .checkout"
      ).first();
      if (target.length) {
        target.prepend(notice);

        // Auto-hide after 5 seconds
        setTimeout(function () {
          notice.fadeOut(function () {
            notice.remove();
          });
        }, 5000);
      }
    },

    handleCheckoutSubmit: function () {
      // Any final processing before checkout submission
      var paymentMethod = $('input[name="payment_method"]:checked').val();

      if (vibe_payment_integration.debug) {
        console.log("Checkout submitted with payment method:", paymentMethod);
      }

      return true; // Allow checkout to proceed
    },

    handleCartUpdate: function () {
      // Refresh pricing when cart is updated
      var currentPaymentMethod = $(
        'input[name="payment_method"]:checked'
      ).val();
      if (currentPaymentMethod) {
        this.updateDynamicPricing(currentPaymentMethod);
      }
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    // Only initialize on cart and checkout pages
    if ($("body.woocommerce-cart, body.woocommerce-checkout").length) {
      VibePaymentIntegration.init();
    }
  });

  // Make globally accessible for debugging
  window.VibePaymentIntegration = VibePaymentIntegration;
})(jQuery);
