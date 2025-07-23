/**
 * Vibe Dynamic Pricing Frontend JavaScript
 *
 * Handles frontend dynamic pricing functionality including payment method
 * changes and price updates.
 *
 * @package WooCommerce Vibe Payment Gateway
 * @since 1.1.0
 */

(function ($) {
  "use strict";

  // Dynamic pricing frontend functionality
  const VibeDynamicPricing = {
    // State management
    currentPaymentMethod: "",
    isUpdating: false,
    updateTimeout: null,
    currentRequest: null,

    init: function () {
      this.bindEvents();
      this.currentPaymentMethod = this.getCurrentPaymentMethod();
      console.log("Vibe Dynamic Pricing initialized");
    },

    bindEvents: function () {
      // Listen for payment method changes
      $(document.body).on(
        "change",
        'input[name="payment_method"]',
        this.handlePaymentMethodChange.bind(this)
      );

      // Listen for checkout updates
      $(document.body).on(
        "updated_checkout",
        this.handleCheckoutUpdate.bind(this)
      );

      // Listen for WooCommerce checkout update triggers
      $(document.body).on(
        "update_checkout",
        this.handleCheckoutUpdateTrigger.bind(this)
      );
    },

    getCurrentPaymentMethod: function () {
      return $('input[name="payment_method"]:checked').val() || "";
    },

    handlePaymentMethodChange: function (event) {
      const selectedMethod = $(event.target).val();

      console.log("Payment method changed to:", selectedMethod);

      // Only proceed if payment method actually changed
      if (this.currentPaymentMethod === selectedMethod) {
        console.log("Payment method unchanged, skipping update");
        return;
      }

      // Cancel any pending update
      if (this.updateTimeout) {
        clearTimeout(this.updateTimeout);
        console.log("Cancelled pending update");
      }

      // Cancel any in-flight request
      if (this.currentRequest && this.currentRequest.readyState !== 4) {
        this.currentRequest.abort();
        console.log("Cancelled in-flight request");
      }

      this.currentPaymentMethod = selectedMethod;

      // Show immediate visual feedback
      this.showLoadingState();
      this.showUpdateNotice(
        "بروزرسانی قیمت برای " +
          this.getPaymentMethodName(selectedMethod) +
          "..."
      );

      // Debounce the actual update to prevent rapid requests
      this.updateTimeout = setTimeout(() => {
        this.updatePricing(selectedMethod);
      }, 300); // 300ms debounce
    },

    getPaymentMethodName: function (method) {
      const methodNames = {
        vibe: "وایب",
        cod: "پرداخت در محل",
        bacs: "تراکنش بانکی",
        cheque: "چک",
        paypal: "پی‌پال",
        wc_zibal: "زیبال",
        wc_zarinpal: "زرین‌پال",
        wc_idpay: "ایدی‌پی",
        wc_mollie: "مولی",
        wc_stripe: "استریپ",
        wc_paypal: "پی‌پال",
        wc_bank_transfer: "تراکنش بانکی",
      };
      return methodNames[method?.toLowerCase()] || method;
    },

    handleCheckoutUpdate: function () {
      // Re-bind events after checkout update (DOM might have changed)
      this.bindEvents();
      this.currentPaymentMethod = this.getCurrentPaymentMethod();
      this.hideLoadingState();
      console.log(
        "Checkout updated, current payment method:",
        this.currentPaymentMethod
      );
    },

    handleCheckoutUpdateTrigger: function () {
      // This handles when checkout update is triggered programmatically
      if (!this.isUpdating) {
        this.showLoadingState();
      }
    },

    updatePricing: function (paymentMethod) {
      // Don't proceed if we don't have the necessary data
      if (typeof vibe_dynamic_pricing === "undefined") {
        console.warn("Vibe dynamic pricing data not available");
        this.hideLoadingState();
        this.showUpdateNotice("اطلاعات قیمت یافت نشد", "error");
        return;
      }

      // Prevent multiple simultaneous updates
      if (this.isUpdating) {
        console.log("Update already in progress, skipping");
        return;
      }

      this.isUpdating = true;
      console.log("Starting pricing update for:", paymentMethod);

      // Send AJAX request to update pricing
      this.currentRequest = $.ajax({
        url: vibe_dynamic_pricing.ajax_url,
        type: "POST",
        data: {
          action: "vibe_update_payment_pricing",
          payment_method: paymentMethod,
          nonce: vibe_dynamic_pricing.nonce,
        },
        timeout: 10000, // 10 second timeout
        success: function (response) {
          console.log("Pricing update response:", response);

          if (response.success && response.data) {
            const data = response.data;
            const message = data.pricing_applied
              ? "بروزرسانی قیمت‌ها انجام شد"
              : "قیمت‌های استاندارد اعمال شدند";

            VibeDynamicPricing.showUpdateNotice(message, "success");

            // Force immediate checkout update
            setTimeout(function () {
              $(document.body).trigger("update_checkout");
            }, 100);
          } else {
            console.error("Pricing update failed:", response.data || response);
            const errorMsg =
              response.data && response.data.message
                ? response.data.message
                : "بروزرسانی قیمت‌ها ناموفق بود. لطفا دوباره تلاش کنید.";
            VibeDynamicPricing.showUpdateNotice(errorMsg, "error");
          }
        },
        error: function (xhr, status, error) {
          if (status !== "abort") {
            // Don't show error for cancelled requests
            console.error(
              "AJAX error updating pricing for payment method:",
              paymentMethod,
              status,
              error
            );
            VibeDynamicPricing.showUpdateNotice(
              "خطا در اتصال. لطفا دوباره تلاش کنید.",
              "error"
            );
          }
        },
        complete: function (xhr, status) {
          if (status !== "abort") {
            // Hide loading state after a short delay to ensure checkout update completes
            setTimeout(function () {
              VibeDynamicPricing.hideLoadingState();
              VibeDynamicPricing.isUpdating = false;
            }, 500);
          } else {
            VibeDynamicPricing.isUpdating = false;
          }
        },
      });
    },

    showLoadingState: function () {
      console.log("Showing loading state");

      // Add loading indicator to checkout
      $(".woocommerce-checkout-review-order-table").addClass("processing");

      // Add loading to payment methods
      $(".wc_payment_methods").addClass("processing");

      // Disable payment method inputs temporarily
      $('input[name="payment_method"]').prop("disabled", true);
    },

    hideLoadingState: function () {
      console.log("Hiding loading state");

      // Remove loading indicators
      $(".woocommerce-checkout-review-order-table").removeClass("processing");
      $(".wc_payment_methods").removeClass("processing");

      // Re-enable payment method inputs
      $('input[name="payment_method"]').prop("disabled", false);
    },

    showUpdateNotice: function (message, type) {
      type = type || "info";

      // Remove any existing notices
      $(".vibe-price-update-notice").remove();

      // Create new notice
      const noticeClass = "vibe-price-update-notice vibe-notice-" + type;
      const notice = $(
        '<div class="' + noticeClass + '">' + message + "</div>"
      );

      // Find appropriate location to show notice
      let target = $(".woocommerce-checkout-payment").first();
      if (!target.length) {
        target = $(".wc_payment_methods").first();
      }
      if (!target.length) {
        target = $(".woocommerce-checkout-review-order").first();
      }

      if (target.length) {
        target.before(notice);

        // Auto-hide success/info notices after 3 seconds
        if (type === "success" || type === "info") {
          setTimeout(function () {
            notice.fadeOut(300, function () {
              $(this).remove();
            });
          }, 3000);
        }
      }
    },

    hideUpdateNotice: function () {
      $(".vibe-price-update-notice").fadeOut(300, function () {
        $(this).remove();
      });
    },
  };

  // Initialize when DOM is ready
  $(document).ready(function () {
    // Only initialize on checkout page
    if (
      $("body.woocommerce-checkout").length ||
      $(".woocommerce-checkout").length
    ) {
      VibeDynamicPricing.init();
    }
  });

  // Also initialize after AJAX page loads (for themes that use AJAX)
  $(document).ajaxComplete(function (event, xhr, settings) {
    if (settings.url && settings.url.indexOf("update_order_review") !== -1) {
      setTimeout(function () {
        if (typeof VibeDynamicPricing !== "undefined") {
          VibeDynamicPricing.bindEvents();
        }
      }, 100);
    }
  });
})(jQuery);
