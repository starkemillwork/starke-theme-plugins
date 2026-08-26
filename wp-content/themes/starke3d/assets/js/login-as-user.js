(function() {
    let timeout = 18000000; // Allowed inactivity time amount in milliseconds (currently set to 5hrs) 
    let timeoutId;

    function resetTimer() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(logout, timeout);
    }

    function logout() {
        // Send AJAX request to handle logout.
        let xhr = new XMLHttpRequest();
        xhr.open('POST', impersonation_vars.ajaxurl, true); // Use the localized variable!
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                // Redirect to admin after successful logout.
                window.location.href = '/wp-admin/'; // Use absolute URL
            } else {
                console.error("Logout failed.");
            }
        };
        xhr.onerror = function() {
            console.error("Logout request failed.");
        }
        xhr.send('action=inactivity_logout&_wpnonce=' + impersonation_vars.nonce);
    }


    // Reset timer on user activity.
    document.addEventListener('mousemove', resetTimer);
    document.addEventListener('keypress', resetTimer);
    document.addEventListener('scroll', resetTimer);
    document.addEventListener('touchstart', resetTimer);

    // Start the timer initially.
    resetTimer();
})();