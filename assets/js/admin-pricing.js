/**
 * Admin Dynamic Pricing JavaScript
 * WooCommerce Vibe Payment Gateway
 */

(function ($) {
  "use strict";

  // Initialize when document is ready
  $(document).ready(function () {
    VibePricingAdmin.init();
  });

  // Main admin object
  var VibePricingAdmin = {
    // Initialize admin functionality
    init: function () {
      this.initRuleForm();
      this.initBulkActions();
      this.initAjaxHandlers();
      this.initConfirmActions();
    },

    // Initialize rule form functionality
    initRuleForm: function () {
      // Show/hide product targeting options based on selection
      $('input[name="target_type"]')
        .change(function () {
          $(".target-option").hide();
          if ($(this).val() !== "all") {
            $('.target-option[data-target="' + $(this).val() + '"]').show();
          }
        })
        .trigger("change");

      // Show/hide adjustment value input based on type
      $("#adjustment_type")
        .change(function () {
          var type = $(this).val();
          console.log("Adjustment type changed to:", type); // Debug log
          if (type === "original") {
            console.log("Hiding input and unit"); // Debug log
            $("#adjustment_value, #adjustment_unit").hide();
          } else {
            console.log("Showing input and unit"); // Debug log
            $("#adjustment_value, #adjustment_unit").show();
            // Update unit display - get currency from PHP rendered content
            if (type === "percentage") {
              $("#adjustment_unit").text("%");
            } else {
              // Get currency symbol from the server-rendered content
              var currencySymbol = $("#adjustment_unit").data("currency") || "";
              $("#adjustment_unit").text(currencySymbol);
            }
          }
        })
        .trigger("change");

      // Auto-calculate priority based on existing rules
      $("#rule_priority").on("focus", function () {
        if ($(this).val() === "0" || $(this).val() === "") {
          var maxPriority = 0;
          $(".priority-value").each(function () {
            var priority = parseInt($(this).text());
            if (priority > maxPriority) {
              maxPriority = priority;
            }
          });
          $(this).val(maxPriority + 1);
        }
      });

      // Rule name validation
      $("#rule_name").on("blur", function () {
        var name = $(this).val().trim();
        if (name.length < 3) {
          $(this).addClass("error");
          $(this).after(
            '<span class="error-message">نام قانون باید حداقل 3 کاراکتر باشد.</span>'
          );
        } else {
          $(this).removeClass("error");
          $(this).siblings(".error-message").remove();
        }
      });

      // Price adjustment validation
      $("#adjustment_value").on("input", function () {
        var value = parseFloat($(this).val());
        var type = $("#adjustment_type").val();

        if (isNaN(value)) {
          $(this).addClass("error");
          return;
        }

        if (type === "percentage" && (value < -100 || value > 1000)) {
          $(this).addClass("error");
          $(this).siblings(".error-message").remove();
          $(this).after(
            '<span class="error-message">درصد باید بین -100% و 1000% باشد.</span>'
          );
        } else {
          $(this).removeClass("error");
          $(this).siblings(".error-message").remove();
        }
      });

      // Product selection helpers
      this.initProductSelectionHelpers();

      // Complex logic helper
      this.initComplexLogicHelper();
    },

    // Initialize product selection helpers
    initProductSelectionHelpers: function () {
      // Add select all/none buttons for multi-selects
      $(".target-option select[multiple]").each(function () {
        var $select = $(this);
        var $container = $select.parent();

        if ($container.find(".select-helpers").length === 0) {
          var $helpers = $(
            '<div class="select-helpers" style="margin-top: 5px;"></div>'
          );
          $helpers.append(
            '<button type="button" class="button select-all">انتخاب همه</button> '
          );
          $helpers.append(
            '<button type="button" class="button select-none">انتخاب هیچکدام</button>'
          );
          $container.append($helpers);

          $helpers.find(".select-all").click(function () {
            $select.find("option").prop("selected", true);
          });

          $helpers.find(".select-none").click(function () {
            $select.find("option").prop("selected", false);
          });
        }
      });

      // Price range validation
      $('input[name="min_price"], input[name="max_price"]').on(
        "input",
        function () {
          var minPrice = parseFloat($('input[name="min_price"]').val()) || 0;
          var maxPrice =
            parseFloat($('input[name="max_price"]').val()) || Infinity;

          if (minPrice > maxPrice && maxPrice !== Infinity) {
            $(this).addClass("error");
            $(this).siblings(".error-message").remove();
            $(this).after(
              '<span class="error-message">قیمت حداقل نمی تواند بیشتر از قیمت حداکثر باشد.</span>'
            );
          } else {
            $('input[name="min_price"], input[name="max_price"]').removeClass(
              "error"
            );
            $('input[name="min_price"], input[name="max_price"]')
              .siblings(".error-message")
              .remove();
          }
        }
      );
    },

    // Initialize complex logic helper
    initComplexLogicHelper: function () {
      // Add syntax help for complex logic
      var $complexTextarea = $('textarea[name="complex_logic"]');
      if ($complexTextarea.length > 0) {
        var $helpButton = $(
          '<button type="button" class="button" style="margin-top: 5px;">نمایش راهنمای سینتکس</button>'
        );
        var $helpDiv = $(
          '<div class="complex-logic-help" style="display: none; margin-top: 10px; padding: 10px; background: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 3px;"></div>'
        );

        $helpDiv.html(`
                    <h4>Complex Logic Syntax:</h4>
                    <ul>
                        <li><strong>category:slug</strong> - Product in category with slug</li>
                        <li><strong>tag:slug</strong> - Product has tag with slug</li>
                        <li><strong>price > amount</strong> - Product price greater than amount</li>
                        <li><strong>price < amount</strong> - Product price less than amount</li>
                        <li><strong>price = amount</strong> - Product price equals amount</li>
                    </ul>
                    <h4>Examples:</h4>
                    <ul>
                        <li><code>(category:electronics AND tag:sale) OR price > 100</code></li>
                        <li><code>category:clothing AND (price < 50 OR tag:clearance)</code></li>
                        <li><code>(category:books OR category:magazines) AND price > 20</code></li>
                    </ul>
                `);

        $complexTextarea.after($helpButton).after($helpDiv);

        $helpButton.click(function () {
          $helpDiv.toggle();
          $(this).text(
            $helpDiv.is(":visible")
              ? "پنهان کردن راهنمای سینتکس"
              : "نمایش راهنمای سینتکس"
          );
        });
      }
    },

    // Initialize bulk actions
    initBulkActions: function () {
      $("#bulk-action-selector-top").on("change", function () {
        var action = $(this).val();
        if (action !== "-1") {
          $("#doaction").removeClass("button").addClass("button-primary");
        } else {
          $("#doaction").removeClass("button-primary").addClass("button");
        }
      });

      // Handle bulk action submission
      $("#doaction").on("click", function (e) {
        var action = $("#bulk-action-selector-top").val();
        var selectedRules = [];

        $('input[name="rule_ids[]"]:checked').each(function () {
          selectedRules.push($(this).val());
        });

        if (action === "-1") {
          e.preventDefault();
          alert("لطفا یک عملیات انتخاب کنید.");
          return false;
        }

        if (selectedRules.length === 0) {
          e.preventDefault();
          alert("لطفا حداقل یک قانون انتخاب کنید.");
          return false;
        }

        if (action === "delete") {
          var confirmMessage =
            "Are you sure you want to delete " +
            selectedRules.length +
            " rule(s)?";
          if (!confirm(confirmMessage)) {
            e.preventDefault();
            return false;
          }
        }
      });

      // Select all checkbox
      $("#cb-select-all-1").on("change", function () {
        $('input[name="rule_ids[]"]').prop("checked", $(this).prop("checked"));
      });
    },

    // Initialize AJAX handlers
    initAjaxHandlers: function () {
      // Clear cache button
      $(".clear-cache-btn").on("click", function (e) {
        e.preventDefault();

        var $button = $(this);
        var originalText = $button.text();

        $button.text("Clearing...").prop("disabled", true);

        $.post(ajaxurl, {
          action: "vibe_clear_pricing_cache",
          _wpnonce: $(this).data("nonce"),
        })
          .done(function (response) {
            if (response.success) {
              $button
                .text("Cleared!")
                .removeClass("button")
                .addClass("button-primary");
              setTimeout(function () {
                $button
                  .text(originalText)
                  .removeClass("button-primary")
                  .addClass("button")
                  .prop("disabled", false);
              }, 2000);
            } else {
              alert("خطا در پاک کردن کش. لطفا دوباره تلاش کنید.");
              $button.text(originalText).prop("disabled", false);
            }
          })
          .fail(function () {
            alert("خطا در پاک کردن کش. لطفا دوباره تلاش کنید.");
            $button.text(originalText).prop("disabled", false);
          });
      });

      // Load performance stats
      this.loadPerformanceStats();
    },

    // Initialize confirmation actions
    initConfirmActions: function () {
      // Delete rule confirmation
      $(".delete-rule").on("click", function (e) {
        var ruleName = $(this).data("rule-name");
        var confirmMessage =
          'آیا مطمئن هستید که می خواهید قانون "' + ruleName + '" را حذف کنید؟';

        if (!confirm(confirmMessage)) {
          e.preventDefault();
          return false;
        }
      });

      // Emergency disable confirmation
      $('input[name="emergency_disable"]').on("change", function () {
        if ($(this).prop("checked")) {
          var confirmed = confirm(
            "این باعث می شود که تمام ویژگی های قیمت گذاری اقساطی غیرفعال شود. آیا مطمئن هستید؟"
          );
          if (!confirmed) {
            $(this).prop("checked", false);
          }
        }
      });
    },

    // Load performance statistics
    loadPerformanceStats: function () {
      if ($("#vibe-performance-stats").length === 0) {
        return;
      }

      $.post(ajaxurl, {
        action: "vibe_get_pricing_stats",
      }).done(function (response) {
        if (response.success && response.data) {
          VibePricingAdmin.updatePerformanceStats(response.data);
        }
      });
    },

    // Update performance statistics display
    updatePerformanceStats: function (stats) {
      var $container = $("#vibe-performance-stats");

      if (stats.object_cache_enabled !== undefined) {
        $container
          .find(".object-cache-status")
          .text(stats.object_cache_enabled ? "بله" : "خیر");
      }

      if (stats.database_cache_entries !== undefined) {
        $container.find(".db-cache-entries").text(stats.database_cache_entries);
      }

      if (stats.database_cache_size !== undefined) {
        $container
          .find(".db-cache-size")
          .text(this.formatBytes(stats.database_cache_size));
      }

      if (stats.transient_entries !== undefined) {
        $container.find(".transient-entries").text(stats.transient_entries);
      }
    },

    // Format bytes to human readable
    formatBytes: function (bytes) {
      if (bytes === 0) return "0 بایت";

      var k = 1024;
      var sizes = ["بایت", "کیلوبایت", "مگابایت", "گیگابایت"];
      var i = Math.floor(Math.log(bytes) / Math.log(k));

      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
    },
  };

  // Make available globally
  window.VibePricingAdmin = VibePricingAdmin;
})(jQuery);
