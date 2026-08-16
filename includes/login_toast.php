<?php
if (!empty($_SESSION['login_success'])) {
    unset($_SESSION['login_success']);

    // A calling page can set $login_toast_title before including this file
    // to override the default message (e.g. "Welcome!" for new users).
    if (!empty($login_toast_title)) {
        $toast_title = $login_toast_title;
    } else {
        $toast_name = $_SESSION['full_name'] ?? null;
        $toast_title = $toast_name
            ? 'Welcome back, ' . htmlspecialchars($toast_name, ENT_QUOTES) . '!'
            : 'Signed in successfully';
    }
?>
    <script>
        Swal.mixin({
            toast: true,
            position: "top-end",
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.onmouseenter = Swal.stopTimer;
                toast.onmouseleave = Swal.resumeTimer;
            }
        }).fire({
            icon: "success",
            title: "<?= $toast_title ?>"
        });
    </script>
<?php
}
?>