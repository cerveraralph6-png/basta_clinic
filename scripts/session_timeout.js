 let inactivityTimeout;
        const INACTIVITY_LIMIT = 120000; // 2 minutes

        function resetInactivityTimer() {
            clearTimeout(inactivityTimeout);
            inactivityTimeout = setTimeout(triggerAutoLogout, INACTIVITY_LIMIT);
        }

        function triggerAutoLogout() {
            
            alert("You have been logged out due to 2 minute of inactivity.");
            window.location.href = "../logout.php?timeout=1";
        }

        
        window.onload = resetInactivityTimer;
        document.onmousemove = resetInactivityTimer;
        document.onkeypress = resetInactivityTimer;
        document.onclick = resetInactivityTimer;
        document.onscroll = resetInactivityTimer;
        document.ontouchstart = resetInactivityTimer; 