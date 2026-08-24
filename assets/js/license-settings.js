jQuery(document).ready(function($) {
    var licenseMessage = $("#whop-wc-license-message");

    function showMessage(message, type) {
        licenseMessage.removeClass("notice notice-success notice-error").addClass("notice notice-" + type).html("<p>" + message + "</p>").show();
    }

    function updateLicenseInfo(data) {
        $("#whop_wc_license_key").val(data.license_key || "");
        $(".license-status-").removeClass("license-status-active license-status-inactive license-status-invalid");
        $(".license-status-").addClass("license-status-" + (data.status ? data.status.toLowerCase() : "inactive"));
        $(".license-status-").find("strong").text(data.status ? data.status.charAt(0).toUpperCase() + data.status.slice(1) : "Inactive");
        $("td:contains(" + whopWcLicense.licenseTypeLabel + ")").next("td").text(data.license_type || "N/A");
        $("td:contains(" + whopWcLicense.supportExpirationLabel + ")").next("td").text(data.support_expiration || "N/A");
        $("td:contains(" + whopWcLicense.lastCheckedLabel + ")").next("td").text(data.last_check || "Never");
    }

    $("#whop-wc-activate-license").on("click", function() {
        var licenseKey = $("#whop_wc_license_key").val();
        $.ajax({
            url: whopWcLicense.ajax_url,
            type: "POST",
            data: {
                action: "whop_activate_license",
                nonce: whopWcLicense.nonce,
                license_key: licenseKey
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, "success");
                    // Optionally update UI with new license info
                    // updateLicenseInfo(response.data.data);
                } else {
                    showMessage(response.data.message, "error");
                }
            },
            error: function() {
                showMessage("An unknown error occurred.", "error");
            }
        });
    });

    $("#whop-wc-deactivate-license").on("click", function() {
        $.ajax({
            url: whopWcLicense.ajax_url,
            type: "POST",
            data: {
                action: "whop_deactivate_license",
                nonce: whopWcLicense.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, "success");
                    // Optionally update UI with new license info
                    // updateLicenseInfo(response.data.data);
                } else {
                    showMessage(response.data.message, "error");
                }
            },
            error: function() {
                showMessage("An unknown error occurred.", "error");
            }
        });
    });

    $("#whop-wc-check-license").on("click", function() {
        $.ajax({
            url: whopWcLicense.ajax_url,
            type: "POST",
            data: {
                action: "whop_check_license",
                nonce: whopWcLicense.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, "success");
                    updateLicenseInfo(response.data.data);
                } else {
                    showMessage(response.data.message, "error");
                }
            },
            error: function() {
                showMessage("An unknown error occurred.", "error");
            }
        });
    });

    $("#whop-wc-check-updates").on("click", function() {
        $.ajax({
            url: whopWcLicense.ajax_url,
            type: "POST",
            data: {
                action: "whop_check_updates",
                nonce: whopWcLicense.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, "success");
                    // Optionally update UI with new update info
                    // updateLicenseInfo(response.data.data);
                } else {
                    showMessage(response.data.message, "error");
                }
            },
            error: function() {
                showMessage("An unknown error occurred.", "error");
            }
        });
    });
});
